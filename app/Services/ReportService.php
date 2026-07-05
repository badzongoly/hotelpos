<?php

declare(strict_types=1);

/**
 * Reporting service. Centralizes report queries so totals, filters, and voided-record rules stay consistent.
 */

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;

final class ReportService
{
    public function __construct(private Database $db) {}

    public function summary(string $start, string $end): array
    {
        $analytics = $this->analytics(['preset' => 'custom', 'start' => $start, 'end' => $end], 'manager');
        return [
            'revenue' => $analytics['dashboard']['mtd_revenue'],
            'expenses' => $analytics['dashboard']['mtd_expenses'],
            'net_income' => $analytics['dashboard']['mtd_cashflow'],
            'payments_by_method' => $analytics['payments']['by_method'],
            'daily_revenue' => $this->paymentsByDay($start, $end),
        ];
    }

    public function analytics(array $query, string $role): array
    {
        [$start, $end, $preset] = $this->resolveRange($query);
        $filters = $this->filters($query);
        $startAt = $start . ' 00:00:00';
        $endAt = $end . ' 23:59:59';
        $days = max(1, (int)((strtotime($end) - strtotime($start)) / 86400) + 1);
        $monthStart = gmdate('Y-m-01');
        $monthEnd = gmdate('Y-m-d');

        return [
            'filters' => $filters + ['preset' => $preset, 'start' => $start, 'end' => $end, 'days' => $days],
            'options' => $this->filterOptions(),
            'dashboard' => $this->dashboard($monthStart, $monthEnd),
            'monthly_cashflow' => ['rows' => $this->monthlyCashflow()],
            'room_performance' => ['rows' => $this->roomPerformance($startAt, $endAt, $days, $filters)],
            'occupancy' => $this->occupancy($start, $end, $days, $filters),
            'extras' => $this->extrasReport($startAt, $endAt, $filters),
            'expenses' => $this->expensesReport($start, $end, $filters),
            'stock' => $this->stockReport($startAt, $endAt),
            'payments' => $this->paymentsReport($startAt, $endAt, $filters),
            'outstanding_balances' => ['rows' => $this->outstandingBalances($role)],
            'discounts_cancellations' => $this->discountsAndCancellations($startAt, $endAt),
            'guests' => $this->guestInsights($startAt, $endAt),
            'staff' => ['rows' => $this->staffAccountability($startAt, $endAt)],
            'anomalies' => ['issues' => $this->anomalies()],
            'meta' => [
                'currency' => 'GHS',
                'privacy_limited' => !in_array($role, ['administrator', 'manager', 'auditor'], true),
                'unavailable_fields' => ['booking_source', 'product_category', 'reorder_level', 'expense_type', 'discount_amount'],
                'notes' => [
                    'Revenue is cashflow from non-voided payments, not accounting profit.',
                    'Occupancy excludes inactive rooms, non-occupancy rooms, and cancelled bookings.',
                    'Unavailable schema fields are shown as N/A instead of guessed values.',
                ],
            ],
        ];
    }

    private function dashboard(string $monthStart, string $monthEnd): array
    {
        $today = gmdate('Y-m-d');
        $rooms = $this->db->fetch('SELECT COUNT(*) total, SUM(status="occupied") occupied, SUM(status="vacant") vacant, SUM(status="dirty") dirty, SUM(status="maintenance") maintenance FROM rooms WHERE active=1 AND occupancy_counted=1') ?: [];
        $totalRooms = (int)($rooms['total'] ?? 0);
        $occupied = (int)($rooms['occupied'] ?? 0);
        $mtdRevenue = $this->scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE voided_at IS NULL AND DATE(created_at) BETWEEN ? AND ?', [$monthStart, $monthEnd]);
        $mtdExpenses = $this->scalar('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE voided_at IS NULL AND expense_date BETWEEN ? AND ?', [$monthStart, $monthEnd]);
        return [
            'today_revenue' => round($this->scalar('SELECT COALESCE(SUM(amount),0) FROM payments WHERE voided_at IS NULL AND DATE(created_at)=?', [$today]), 2),
            'mtd_revenue' => round($mtdRevenue, 2),
            'mtd_expenses' => round($mtdExpenses, 2),
            'mtd_cashflow' => round($mtdRevenue - $mtdExpenses, 2),
            'current_occupancy_rate' => $totalRooms > 0 ? round(($occupied / $totalRooms) * 100, 2) : 0,
            'occupied_rooms' => $occupied,
            'vacant_rooms' => (int)($rooms['vacant'] ?? 0),
            'dirty_rooms' => (int)($rooms['dirty'] ?? 0),
            'maintenance_rooms' => (int)($rooms['maintenance'] ?? 0),
            'outstanding_balances' => round($this->outstandingTotal(), 2),
            'extras_sales_today' => round($this->scalar('SELECT COALESCE(SUM(qty * unit_price),0) FROM booking_extras WHERE voided_at IS NULL AND DATE(created_at)=?', [$today]), 2),
            'low_stock_count' => (int)$this->scalar('SELECT COUNT(*) FROM extras WHERE active=1 AND stock_tracked=1 AND stock_qty <= 0'),
            'pending_checkouts' => (int)$this->scalar('SELECT COUNT(*) FROM bookings WHERE status="active" AND DATE(checkin_at) < ?', [$today]),
            'cancelled_bookings_month' => (int)$this->scalar('SELECT COUNT(*) FROM bookings WHERE status="cancelled" AND DATE(COALESCE(updated_at, created_at)) BETWEEN ? AND ?', [$monthStart, $monthEnd]),
        ];
    }

    private function monthlyCashflow(): array
    {
        $rows = $this->db->fetchAll('SELECT m.month, COALESCE(p.revenue,0) revenue, COALESCE(e.expenses,0) expenses, COALESCE(room.room_revenue,0) room_revenue, COALESCE(extra.extras_revenue,0) extras_revenue FROM (SELECT DATE_FORMAT(created_at, "%Y-%m") month FROM payments WHERE created_at >= DATE_SUB(UTC_DATE(), INTERVAL 12 MONTH) UNION SELECT DATE_FORMAT(expense_date, "%Y-%m") month FROM expenses WHERE expense_date >= DATE_SUB(UTC_DATE(), INTERVAL 12 MONTH)) m LEFT JOIN (SELECT DATE_FORMAT(created_at, "%Y-%m") month, SUM(amount) revenue FROM payments WHERE voided_at IS NULL GROUP BY DATE_FORMAT(created_at, "%Y-%m")) p ON p.month=m.month LEFT JOIN (SELECT DATE_FORMAT(expense_date, "%Y-%m") month, SUM(amount) expenses FROM expenses WHERE voided_at IS NULL GROUP BY DATE_FORMAT(expense_date, "%Y-%m")) e ON e.month=m.month LEFT JOIN (SELECT DATE_FORMAT(checkin_at, "%Y-%m") month, SUM(GREATEST(1, DATEDIFF(DATE(COALESCE(checkout_at, UTC_TIMESTAMP())), DATE(checkin_at))) * rate_per_night) room_revenue FROM bookings WHERE status <> "cancelled" GROUP BY DATE_FORMAT(checkin_at, "%Y-%m")) room ON room.month=m.month LEFT JOIN (SELECT DATE_FORMAT(created_at, "%Y-%m") month, SUM(qty * unit_price) extras_revenue FROM booking_extras WHERE voided_at IS NULL GROUP BY DATE_FORMAT(created_at, "%Y-%m")) extra ON extra.month=m.month GROUP BY m.month, p.revenue, e.expenses, room.room_revenue, extra.extras_revenue ORDER BY m.month');
        $prev = null;
        foreach ($rows as &$row) {
            $row['revenue'] = round((float)$row['revenue'], 2);
            $row['expenses'] = round((float)$row['expenses'], 2);
            $row['net_cashflow'] = round($row['revenue'] - $row['expenses'], 2);
            $row['room_revenue'] = round((float)$row['room_revenue'], 2);
            $row['extras_revenue'] = round((float)$row['extras_revenue'], 2);
            $row['other_revenue'] = round(max(0, $row['revenue'] - $row['room_revenue'] - $row['extras_revenue']), 2);
            $row['revenue_growth_pct'] = $this->growth($prev['revenue'] ?? null, $row['revenue']);
            $row['expense_growth_pct'] = $this->growth($prev['expenses'] ?? null, $row['expenses']);
            $row['cashflow_growth_pct'] = $this->growth($prev['net_cashflow'] ?? null, $row['net_cashflow']);
            $prev = $row;
        }
        return $rows;
    }
    private function roomPerformance(string $startAt, string $endAt, int $days, array $filters): array
    {
        $where = ['r.active=1', 'r.occupancy_counted=1'];
        $params = [];
        if ($filters['room_id']) { $where[] = 'r.id=?'; $params[] = $filters['room_id']; }
        if ($filters['room_type'] !== '') { $where[] = 'r.type=?'; $params[] = $filters['room_type']; }
        $rows = $this->db->fetchAll('SELECT r.id, r.name, r.type, r.rate, COUNT(DISTINCT b.id) bookings, COALESCE(SUM(CASE WHEN b.status <> "cancelled" THEN GREATEST(1, DATEDIFF(DATE(COALESCE(b.checkout_at, ?)), DATE(b.checkin_at))) ELSE 0 END),0) occupied_nights, COALESCE(SUM(CASE WHEN b.status <> "cancelled" THEN GREATEST(1, DATEDIFF(DATE(COALESCE(b.checkout_at, ?)), DATE(b.checkin_at))) * b.rate_per_night ELSE 0 END),0) room_revenue, COALESCE(SUM(be.extras_total),0) extras_revenue, SUM(b.status="cancelled") cancellations, COALESCE(AVG(CASE WHEN b.status <> "cancelled" THEN GREATEST(1, DATEDIFF(DATE(COALESCE(b.checkout_at, ?)), DATE(b.checkin_at))) END),0) avg_los FROM rooms r LEFT JOIN bookings b ON b.room_id=r.id AND b.checkin_at BETWEEN ? AND ? LEFT JOIN (SELECT booking_id, SUM(qty*unit_price) extras_total FROM booking_extras WHERE voided_at IS NULL GROUP BY booking_id) be ON be.booking_id=b.id WHERE ' . implode(' AND ', $where) . ' GROUP BY r.id, r.name, r.type, r.rate ORDER BY 7 DESC', array_merge([$endAt, $endAt, $endAt, $startAt, $endAt], $params));
        foreach ($rows as &$row) {
            $bookings = max(1, (int)$row['bookings']);
            $totalRevenue = (float)$row['room_revenue'] + (float)$row['extras_revenue'];
            $occupiedNights = (float)$row['occupied_nights'];
            $row['total_revenue'] = round($totalRevenue, 2);
            $row['avg_revenue_per_booking'] = round($totalRevenue / $bookings, 2);
            $row['adr'] = $occupiedNights > 0 ? round((float)$row['room_revenue'] / $occupiedNights, 2) : 0;
            $row['revpar'] = $days > 0 ? round((float)$row['room_revenue'] / $days, 2) : 0;
            $row['discount_amount'] = 'N/A';
            $row['net_room_revenue'] = round((float)$row['room_revenue'], 2);
            $row['room_revenue'] = round((float)$row['room_revenue'], 2);
            $row['extras_revenue'] = round((float)$row['extras_revenue'], 2);
            $row['avg_los'] = round((float)$row['avg_los'], 2);
        }
        return $rows;
    }

    private function occupancy(string $start, string $end, int $days, array $filters): array
    {
        $roomWhere = ['active=1', 'occupancy_counted=1'];
        $roomParams = [];
        if ($filters['room_id']) { $roomWhere[] = 'id=?'; $roomParams[] = $filters['room_id']; }
        if ($filters['room_type'] !== '') { $roomWhere[] = 'type=?'; $roomParams[] = $filters['room_type']; }
        $roomCount = (int)$this->scalar('SELECT COUNT(*) FROM rooms WHERE ' . implode(' AND ', $roomWhere), $roomParams);
        $available = $roomCount * $days;
        $daily = [];
        $cursor = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($end, new DateTimeZone('UTC'));
        while ($cursor <= $last) {
            $day = $cursor->format('Y-m-d');
            $occupied = (int)$this->scalar('SELECT COUNT(DISTINCT b.room_id) FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.status <> "cancelled" AND r.active=1 AND r.occupancy_counted=1 AND DATE(b.checkin_at) <= ? AND DATE(COALESCE(b.checkout_at, ?)) >= ?', [$day, $day, $day]);
            $daily[] = ['date' => $day, 'available_rooms' => $roomCount, 'occupied_rooms' => $occupied, 'occupancy_pct' => $roomCount > 0 ? round(($occupied / $roomCount) * 100, 2) : 0];
            $cursor = $cursor->modify('+1 day');
        }
        $occupiedNights = array_sum(array_column($daily, 'occupied_rooms'));
        return [
            'summary' => ['available_room_nights' => $available, 'occupied_room_nights' => $occupiedNights, 'occupancy_rate' => $available > 0 ? round(($occupiedNights / $available) * 100, 2) : 0, 'average_length_of_stay' => round($this->scalar('SELECT COALESCE(AVG(GREATEST(1, DATEDIFF(DATE(COALESCE(checkout_at, UTC_TIMESTAMP())), DATE(checkin_at)))),0) FROM bookings WHERE status <> "cancelled" AND DATE(checkin_at) BETWEEN ? AND ?', [$start, $end]), 2), 'cancelled_bookings' => (int)$this->scalar('SELECT COUNT(*) FROM bookings WHERE status="cancelled" AND DATE(checkin_at) BETWEEN ? AND ?', [$start, $end])],
            'daily' => $daily,
            'by_weekday' => $this->db->fetchAll('SELECT DAYNAME(checkin_at) label, COUNT(*) checkins FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.status <> "cancelled" AND r.occupancy_counted=1 AND DATE(checkin_at) BETWEEN ? AND ? GROUP BY DAYOFWEEK(checkin_at), DAYNAME(checkin_at) ORDER BY DAYOFWEEK(checkin_at)', [$start, $end]),
            'by_type' => $this->db->fetchAll('SELECT r.type, COUNT(*) bookings FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.status <> "cancelled" AND r.occupancy_counted=1 AND DATE(b.checkin_at) BETWEEN ? AND ? GROUP BY r.type ORDER BY bookings DESC', [$start, $end]),
            'checkins' => $this->db->fetchAll('SELECT DATE(checkin_at) day, COUNT(*) total FROM bookings WHERE DATE(checkin_at) BETWEEN ? AND ? GROUP BY DATE(checkin_at) ORDER BY day', [$start, $end]),
            'checkouts' => $this->db->fetchAll('SELECT DATE(checkout_at) day, COUNT(*) total FROM bookings WHERE checkout_at IS NOT NULL AND DATE(checkout_at) BETWEEN ? AND ? GROUP BY DATE(checkout_at) ORDER BY day', [$start, $end]),
        ];
    }

    private function extrasReport(string $startAt, string $endAt, array $filters): array
    {
        $where = ['be.voided_at IS NULL', 'be.created_at BETWEEN ? AND ?'];
        $params = [$startAt, $endAt];
        if ($filters['staff_id']) { $where[] = 'be.created_by=?'; $params[] = $filters['staff_id']; }
        $whereSql = implode(' AND ', $where);
        $rows = $this->db->fetchAll("SELECT COALESCE(e.name, be.description, 'Extra') product, 'N/A' category, SUM(be.qty) quantity_sold, SUM(be.qty*be.unit_price) revenue, e.stock_qty current_stock, MAX(sm.unit_cost) unit_cost FROM booking_extras be LEFT JOIN extras e ON e.id=be.extra_id LEFT JOIN stock_movements sm ON sm.extra_id=e.id AND sm.unit_cost IS NOT NULL WHERE {$whereSql} GROUP BY COALESCE(e.name, be.description, 'Extra'), e.stock_qty ORDER BY revenue DESC", $params);
        foreach ($rows as &$row) {
            $row['quantity_sold'] = (float)$row['quantity_sold'];
            $row['revenue'] = round((float)$row['revenue'], 2);
            $row['cost'] = $row['unit_cost'] === null ? 'N/A' : round((float)$row['unit_cost'] * $row['quantity_sold'], 2);
            $row['gross_margin'] = $row['unit_cost'] === null ? 'N/A' : round($row['revenue'] - ((float)$row['unit_cost'] * $row['quantity_sold']), 2);
        }
        $total = array_sum(array_map(static fn($row) => (float)$row['revenue'], $rows));
        $bookingCount = max(1, (int)$this->scalar('SELECT COUNT(DISTINCT booking_id) FROM booking_extras WHERE voided_at IS NULL AND created_at BETWEEN ? AND ?', [$startAt, $endAt]));
        return ['summary' => ['total_revenue' => round($total, 2), 'average_spend_per_booking' => round($total / $bookingCount, 2)], 'products' => $rows, 'by_category' => [['category' => 'N/A', 'revenue' => round($total, 2)]], 'by_room' => $this->db->fetchAll('SELECT r.name room, SUM(be.qty*be.unit_price) revenue FROM booking_extras be JOIN bookings b ON b.id=be.booking_id JOIN rooms r ON r.id=b.room_id WHERE be.voided_at IS NULL AND be.created_at BETWEEN ? AND ? GROUP BY r.name ORDER BY revenue DESC', [$startAt, $endAt]), 'by_staff' => $this->db->fetchAll('SELECT COALESCE(u.name,"System") staff, SUM(be.qty*be.unit_price) revenue, SUM(be.qty) qty FROM booking_extras be LEFT JOIN users u ON u.id=be.created_by WHERE be.voided_at IS NULL AND be.created_at BETWEEN ? AND ? GROUP BY COALESCE(u.name,"System") ORDER BY revenue DESC', [$startAt, $endAt])];
    }

    private function expensesReport(string $start, string $end, array $filters): array
    {
        $where = ['e.voided_at IS NULL', 'e.expense_date BETWEEN ? AND ?'];
        $params = [$start, $end];
        if ($filters['expense_category_id']) { $where[] = 'e.category_id=?'; $params[] = $filters['expense_category_id']; }
        if ($filters['payment_method'] !== '') { $where[] = 'e.method=?'; $params[] = $filters['payment_method']; }
        if ($filters['staff_id']) { $where[] = 'e.user_id=?'; $params[] = $filters['staff_id']; }
        $whereSql = implode(' AND ', $where);
        return ['by_category' => $this->db->fetchAll("SELECT c.name category, SUM(e.amount) amount FROM expenses e JOIN expense_categories c ON c.id=e.category_id WHERE {$whereSql} GROUP BY c.name ORDER BY amount DESC", $params), 'by_vendor' => $this->db->fetchAll("SELECT COALESCE(NULLIF(e.vendor,''),'Not recorded') vendor, SUM(e.amount) amount FROM expenses e WHERE {$whereSql} GROUP BY COALESCE(NULLIF(e.vendor,''),'Not recorded') ORDER BY amount DESC", $params), 'by_method' => $this->db->fetchAll("SELECT e.method, SUM(e.amount) amount FROM expenses e WHERE {$whereSql} GROUP BY e.method ORDER BY amount DESC", $params), 'by_user' => $this->db->fetchAll("SELECT COALESCE(u.name,'System') user_name, SUM(e.amount) amount FROM expenses e LEFT JOIN users u ON u.id=e.user_id WHERE {$whereSql} GROUP BY COALESCE(u.name,'System') ORDER BY amount DESC", $params), 'monthly' => $this->db->fetchAll("SELECT DATE_FORMAT(e.expense_date,'%Y-%m') month, SUM(e.amount) amount FROM expenses e WHERE {$whereSql} GROUP BY DATE_FORMAT(e.expense_date,'%Y-%m') ORDER BY month", $params), 'details' => $this->db->fetchAll("SELECT e.expense_date, c.name category, e.vendor, e.description, e.method, COALESCE(u.name,'System') user_name, e.amount, 'N/A' expense_type FROM expenses e JOIN expense_categories c ON c.id=e.category_id LEFT JOIN users u ON u.id=e.user_id WHERE {$whereSql} ORDER BY e.expense_date DESC, e.id DESC LIMIT 250", $params), 'capex_vs_opex' => [['type' => 'N/A', 'amount' => null]]];
    }

    private function stockReport(string $startAt, string $endAt): array
    {
        $products = $this->db->fetchAll('SELECT e.id, e.name product, "N/A" category, e.stock_qty current_stock, "N/A" reorder_level, latest.unit_cost, CASE WHEN latest.unit_cost IS NULL THEN NULL ELSE e.stock_qty * latest.unit_cost END estimated_value FROM extras e LEFT JOIN (SELECT sm1.extra_id, sm1.unit_cost FROM stock_movements sm1 JOIN (SELECT extra_id, MAX(created_at) max_created FROM stock_movements WHERE unit_cost IS NOT NULL GROUP BY extra_id) x ON x.extra_id=sm1.extra_id AND x.max_created=sm1.created_at) latest ON latest.extra_id=e.id WHERE e.active=1 AND e.stock_tracked=1 ORDER BY e.stock_qty ASC, e.name');
        return ['products' => $products, 'alerts' => array_values(array_filter($products, static fn($row) => (float)$row['current_stock'] <= 0)), 'movements' => $this->db->fetchAll('SELECT e.name product, sm.movement_type, SUM(sm.qty) qty FROM stock_movements sm JOIN extras e ON e.id=sm.extra_id WHERE sm.created_at BETWEEN ? AND ? GROUP BY e.name, sm.movement_type ORDER BY e.name', [$startAt, $endAt]), 'fast_moving' => $this->db->fetchAll('SELECT e.name product, SUM(sm.qty) qty FROM stock_movements sm JOIN extras e ON e.id=sm.extra_id WHERE sm.movement_type="out" AND sm.created_at BETWEEN ? AND ? GROUP BY e.name ORDER BY qty DESC LIMIT 10', [$startAt, $endAt]), 'slow_moving' => $this->db->fetchAll('SELECT e.name product, COALESCE(SUM(CASE WHEN sm.movement_type="out" THEN sm.qty ELSE 0 END),0) qty FROM extras e LEFT JOIN stock_movements sm ON sm.extra_id=e.id AND sm.created_at BETWEEN ? AND ? WHERE e.active=1 GROUP BY e.name ORDER BY qty ASC, e.name LIMIT 10', [$startAt, $endAt])];
    }

    private function paymentsReport(string $startAt, string $endAt, array $filters): array
    {
        $where = ['p.voided_at IS NULL', 'p.created_at BETWEEN ? AND ?'];
        $params = [$startAt, $endAt];
        if ($filters['payment_method'] !== '') { $where[] = 'p.method=?'; $params[] = $filters['payment_method']; }
        if ($filters['staff_id']) { $where[] = 'p.created_by=?'; $params[] = $filters['staff_id']; }
        $whereSql = implode(' AND ', $where);
        $byMethod = $this->db->fetchAll("SELECT p.method, COUNT(*) count, SUM(p.amount) amount FROM payments p WHERE {$whereSql} GROUP BY p.method ORDER BY amount DESC", $params);
        $total = max(0.01, array_sum(array_map(static fn($row) => (float)$row['amount'], $byMethod)));
        foreach ($byMethod as &$row) { $row['percentage'] = round(((float)$row['amount'] / $total) * 100, 2); }
        return ['by_method' => $byMethod, 'monthly_by_method' => $this->db->fetchAll("SELECT DATE_FORMAT(p.created_at,'%Y-%m') month, p.method, SUM(p.amount) amount FROM payments p WHERE {$whereSql} GROUP BY DATE_FORMAT(p.created_at,'%Y-%m'), p.method ORDER BY month", $params), 'by_staff' => $this->db->fetchAll("SELECT COALESCE(u.name,'System') user_name, p.method, SUM(p.amount) amount FROM payments p LEFT JOIN users u ON u.id=p.created_by WHERE {$whereSql} GROUP BY COALESCE(u.name,'System'), p.method ORDER BY user_name", $params), 'by_booking_source' => [['booking_source' => 'N/A', 'amount' => null]]];
    }

    private function outstandingBalances(string $role): array
    {
        $canSeeGuests = in_array($role, ['administrator', 'manager', 'auditor'], true);
        $rows = $this->db->fetchAll('SELECT b.id, b.guest_name, b.contact, r.name room, b.checkin_at, b.checkout_at, b.status, COALESCE(u.name,"System") created_by, ((GREATEST(1, DATEDIFF(DATE(COALESCE(b.checkout_at, UTC_TIMESTAMP())), DATE(b.checkin_at))) * b.rate_per_night) + COALESCE(be.extras_total,0)) booking_total, COALESCE(p.paid_total,0) amount_paid, GREATEST(0, ((GREATEST(1, DATEDIFF(DATE(COALESCE(b.checkout_at, UTC_TIMESTAMP())), DATE(b.checkin_at))) * b.rate_per_night) + COALESCE(be.extras_total,0)) - COALESCE(p.paid_total,0)) balance_due, DATEDIFF(UTC_DATE(), DATE(COALESCE(b.checkout_at, b.checkin_at))) days_overdue FROM bookings b JOIN rooms r ON r.id=b.room_id LEFT JOIN users u ON u.id=b.created_by LEFT JOIN (SELECT booking_id, SUM(qty*unit_price) extras_total FROM booking_extras WHERE voided_at IS NULL GROUP BY booking_id) be ON be.booking_id=b.id LEFT JOIN (SELECT booking_id, SUM(amount) paid_total FROM payments WHERE voided_at IS NULL GROUP BY booking_id) p ON p.booking_id=b.id WHERE b.status IN ("active","checked_out") HAVING balance_due > 0 ORDER BY balance_due DESC LIMIT 250');
        foreach ($rows as &$row) {
            if (!$canSeeGuests) { $row['guest_name'] = $this->maskName((string)$row['guest_name']); $row['contact'] = $this->maskContact((string)($row['contact'] ?? '')); }
            $row['booking_total'] = round((float)$row['booking_total'], 2);
            $row['amount_paid'] = round((float)$row['amount_paid'], 2);
            $row['balance_due'] = round((float)$row['balance_due'], 2);
            $row['payment_staff'] = 'See payment ledger';
        }
        return $rows;
    }

    private function discountsAndCancellations(string $startAt, string $endAt): array
    {
        return ['summary' => ['total_discounts' => 'N/A', 'discount_pct_of_revenue' => 'N/A', 'cancelled_bookings' => (int)$this->scalar('SELECT COUNT(*) FROM bookings WHERE status="cancelled" AND COALESCE(updated_at, created_at) BETWEEN ? AND ?', [$startAt, $endAt])], 'discounts_by_staff' => [['user_name' => 'N/A', 'amount' => null]], 'cancellation_reasons' => $this->db->fetchAll('SELECT COALESCE(NULLIF(cancellation_reason,""),"Not recorded") reason, COUNT(*) total FROM bookings WHERE status="cancelled" AND COALESCE(updated_at, created_at) BETWEEN ? AND ? GROUP BY COALESCE(NULLIF(cancellation_reason,""),"Not recorded") ORDER BY total DESC', [$startAt, $endAt]), 'cancellations_by_month' => $this->db->fetchAll('SELECT DATE_FORMAT(COALESCE(updated_at, created_at),"%Y-%m") month, COUNT(*) total FROM bookings WHERE status="cancelled" GROUP BY DATE_FORMAT(COALESCE(updated_at, created_at),"%Y-%m") ORDER BY month'), 'rows' => $this->db->fetchAll('SELECT b.id booking_id, r.name room, b.rate_per_night original_amount, "N/A" discount, b.rate_per_night cancelled_amount, COALESCE(b.cancellation_reason,"Not recorded") reason, COALESCE(u.name,"System") user_name FROM bookings b JOIN rooms r ON r.id=b.room_id LEFT JOIN users u ON u.id=b.created_by WHERE b.status="cancelled" AND COALESCE(b.updated_at, b.created_at) BETWEEN ? AND ? ORDER BY COALESCE(b.updated_at, b.created_at) DESC LIMIT 250', [$startAt, $endAt])];
    }

    private function guestInsights(string $startAt, string $endAt): array
    {
        $repeat = $this->db->fetch('SELECT COUNT(*) repeat_guest_count FROM (SELECT guest_name, COUNT(*) c FROM bookings WHERE checkin_at BETWEEN ? AND ? GROUP BY guest_name HAVING c > 1) x', [$startAt, $endAt]) ?: ['repeat_guest_count' => 0];
        return ['summary' => ['repeat_guest_count' => (int)$repeat['repeat_guest_count']], 'new_vs_returning' => $this->db->fetchAll('SELECT CASE WHEN c > 1 THEN "Returning" ELSE "New" END guest_type, COUNT(*) guests FROM (SELECT guest_name, COUNT(*) c FROM bookings WHERE checkin_at BETWEEN ? AND ? GROUP BY guest_name) x GROUP BY CASE WHEN c > 1 THEN "Returning" ELSE "New" END', [$startAt, $endAt]), 'nationality_mix' => $this->db->fetchAll('SELECT COALESCE(NULLIF(nationality,""),"Not recorded") nationality, COUNT(*) total FROM bookings WHERE checkin_at BETWEEN ? AND ? GROUP BY COALESCE(NULLIF(nationality,""),"Not recorded") ORDER BY total DESC', [$startAt, $endAt]), 'gender_mix' => $this->db->fetchAll('SELECT COALESCE(NULLIF(gender,""),"Not recorded") gender, COUNT(*) total FROM bookings WHERE checkin_at BETWEEN ? AND ? GROUP BY COALESCE(NULLIF(gender,""),"Not recorded") ORDER BY total DESC', [$startAt, $endAt]), 'contact_completeness' => $this->db->fetch('SELECT SUM(contact IS NOT NULL AND contact <> "") complete, COUNT(*) total FROM bookings WHERE checkin_at BETWEEN ? AND ?', [$startAt, $endAt]) ?: ['complete' => 0, 'total' => 0]];
    }

    private function staffAccountability(string $startAt, string $endAt): array
    {
        return $this->db->fetchAll('SELECT u.name user_name, COUNT(DISTINCT b.id) bookings, COUNT(DISTINCT p.id) payments, COALESCE(SUM(p.amount),0) revenue_handled, COUNT(DISTINCT e.id) expenses, COALESCE(SUM(e.amount),0) expenses_recorded, COUNT(DISTINCT sm.id) stock_movements, SUM(b.status="cancelled") cancellations, "N/A" discounts FROM users u LEFT JOIN bookings b ON b.created_by=u.id AND b.created_at BETWEEN ? AND ? LEFT JOIN payments p ON p.created_by=u.id AND p.created_at BETWEEN ? AND ? AND p.voided_at IS NULL LEFT JOIN expenses e ON e.user_id=u.id AND e.created_at BETWEEN ? AND ? AND e.voided_at IS NULL LEFT JOIN stock_movements sm ON sm.created_by=u.id AND sm.created_at BETWEEN ? AND ? GROUP BY u.id, u.name ORDER BY revenue_handled DESC', [$startAt, $endAt, $startAt, $endAt, $startAt, $endAt, $startAt, $endAt]);
    }

    private function anomalies(): array
    {
        $issues = [];
        foreach ($this->db->fetchAll('SELECT b.id FROM bookings b LEFT JOIN payments p ON p.booking_id=b.id AND p.voided_at IS NULL WHERE b.status <> "cancelled" GROUP BY b.id HAVING COALESCE(SUM(p.amount),0)=0 LIMIT 50') as $row) { $issues[] = $this->issue('High', 'Booking with no payment', 'Booking #' . $row['id'] . ' has no non-voided payment.', 'booking', (string)$row['id'], 'Record payment or verify the booking.'); }
        foreach ($this->db->fetchAll('SELECT b.id FROM bookings b JOIN payments p ON p.booking_id=b.id AND p.voided_at IS NULL WHERE b.status="cancelled" GROUP BY b.id LIMIT 50') as $row) { $issues[] = $this->issue('High', 'Cancelled booking with payment', 'Cancelled booking #' . $row['id'] . ' still has payment records.', 'booking', (string)$row['id'], 'Review refund or void workflow.'); }
        foreach ($this->db->fetchAll('SELECT id, name, stock_qty FROM extras WHERE stock_tracked=1 AND stock_qty < 0 LIMIT 50') as $row) { $issues[] = $this->issue('High', 'Negative stock', $row['name'] . ' has stock ' . $row['stock_qty'] . '.', 'extra', (string)$row['id'], 'Review stock movements and correct inventory.'); }
        foreach ($this->db->fetchAll('SELECT id, name FROM extras WHERE active=1 AND stock_tracked=1 AND stock_qty <= 0 LIMIT 50') as $row) { $issues[] = $this->issue('Medium', 'Out of stock product', $row['name'] . ' is out of stock.', 'extra', (string)$row['id'], 'Restock or mark inactive.'); }
        foreach ($this->db->fetchAll('SELECT name FROM extras WHERE active=1 GROUP BY name HAVING COUNT(*) > 1 LIMIT 50') as $row) { $issues[] = $this->issue('Medium', 'Duplicate product name', $row['name'] . ' appears more than once.', 'extra', '', 'Merge or rename duplicate products.'); }
        foreach ($this->db->fetchAll('SELECT name FROM rooms WHERE active=1 GROUP BY name HAVING COUNT(*) > 1 LIMIT 50') as $row) { $issues[] = $this->issue('Medium', 'Duplicate active room number', $row['name'] . ' appears more than once.', 'room', '', 'Rename or deactivate duplicates.'); }
        foreach ($this->db->fetchAll('SELECT id FROM bookings WHERE checkout_at IS NOT NULL AND checkout_at < checkin_at LIMIT 50') as $row) { $issues[] = $this->issue('High', 'Checkout before check-in', 'Booking #' . $row['id'] . ' has invalid dates.', 'booking', (string)$row['id'], 'Correct booking dates.'); }
        foreach ($this->db->fetchAll('SELECT id FROM bookings WHERE status="checked_out" AND checkout_at IS NULL LIMIT 50') as $row) { $issues[] = $this->issue('Medium', 'Missing checkout date', 'Checked-out booking #' . $row['id'] . ' has no checkout date.', 'booking', (string)$row['id'], 'Update checkout date.'); }
        foreach ($this->db->fetchAll('SELECT id FROM expenses WHERE vendor IS NULL OR vendor="" LIMIT 50') as $row) { $issues[] = $this->issue('Low', 'Expense without vendor', 'Expense #' . $row['id'] . ' has no vendor.', 'expense', (string)$row['id'], 'Add vendor where applicable.'); }
        foreach ($this->db->fetchAll('SELECT id FROM stock_movements WHERE movement_type="adjustment" ORDER BY created_at DESC LIMIT 50') as $row) { $issues[] = $this->issue('Low', 'Manual stock adjustment', 'Stock movement #' . $row['id'] . ' is a manual adjustment.', 'stock_movement', (string)$row['id'], 'Verify adjustment note and approval.'); }
        return $issues;
    }

    private function filterOptions(): array
    {
        return ['rooms' => $this->db->fetchAll('SELECT id, name FROM rooms WHERE active=1 ORDER BY name'), 'room_types' => $this->db->fetchAll('SELECT DISTINCT type FROM rooms WHERE active=1 ORDER BY type'), 'payment_methods' => ['cash', 'momo', 'card', 'bank', 'other'], 'expense_categories' => $this->db->fetchAll('SELECT id, name FROM expense_categories WHERE active=1 ORDER BY name'), 'staff' => $this->db->fetchAll('SELECT id, name FROM users WHERE active=1 ORDER BY name')];
    }

    private function resolveRange(array $query): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $preset = (string)($query['preset'] ?? 'this_month');
        return match ($preset) {
            'today' => [$today->format('Y-m-d'), $today->format('Y-m-d'), $preset],
            'yesterday' => [$today->modify('-1 day')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), $preset],
            'this_week' => [$today->modify('monday this week')->format('Y-m-d'), $today->format('Y-m-d'), $preset],
            'last_month' => [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d'), $preset],
            'custom' => [$this->validDate($query['start'] ?? null, $today->format('Y-m-01')), $this->validDate($query['end'] ?? null, $today->format('Y-m-d')), $preset],
            default => [$today->format('Y-m-01'), $today->format('Y-m-d'), 'this_month'],
        };
    }

    private function filters(array $query): array
    {
        return ['room_id' => !empty($query['room_id']) ? (int)$query['room_id'] : null, 'room_type' => trim((string)($query['room_type'] ?? '')), 'payment_method' => trim((string)($query['payment_method'] ?? '')), 'expense_category_id' => !empty($query['expense_category_id']) ? (int)$query['expense_category_id'] : null, 'staff_id' => !empty($query['staff_id']) ? (int)$query['staff_id'] : null];
    }

    private function outstandingTotal(): float
    {
        return $this->scalar('SELECT COALESCE(SUM(balance_due),0) FROM (SELECT GREATEST(0, ((GREATEST(1, DATEDIFF(DATE(COALESCE(b.checkout_at, UTC_TIMESTAMP())), DATE(b.checkin_at))) * b.rate_per_night) + COALESCE(be.extras_total,0)) - COALESCE(p.paid_total,0)) balance_due FROM bookings b LEFT JOIN (SELECT booking_id, SUM(qty*unit_price) extras_total FROM booking_extras WHERE voided_at IS NULL GROUP BY booking_id) be ON be.booking_id=b.id LEFT JOIN (SELECT booking_id, SUM(amount) paid_total FROM payments WHERE voided_at IS NULL GROUP BY booking_id) p ON p.booking_id=b.id WHERE b.status IN ("active","checked_out")) x');
    }

    private function paymentsByDay(string $start, string $end): array
    {
        return $this->db->fetchAll('SELECT DATE(created_at) AS day, SUM(amount) AS total FROM payments WHERE voided_at IS NULL AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY day', [$start, $end]);
    }

    private function scalar(string $sql, array $params = []): float
    {
        $row = $this->db->fetch($sql, $params);
        if (!$row) return 0.0;
        return (float)(array_values($row)[0] ?? 0);
    }

    private function growth(?float $previous, float $current): ?float
    {
        if ($previous === null || abs($previous) < 0.01) return null;
        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    private function validDate(?string $date, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) ? (string)$date : $fallback;
    }

    private function maskName(string $name): string
    {
        $name = trim($name);
        return $name === '' ? 'Guest' : mb_substr($name, 0, 1) . '***';
    }

    private function maskContact(string $contact): string
    {
        return $contact === '' ? '' : '***' . substr($contact, -4);
    }

    private function issue(string $severity, string $type, string $description, string $entity, string $entityId, string $action): array
    {
        return ['severity' => $severity, 'type' => $type, 'description' => $description, 'entity' => $entity, 'entity_id' => $entityId, 'suggested_action' => $action];
    }
}
