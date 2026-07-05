<?php

declare(strict_types=1);

/**
 * Dashboard API controller. Returns reception-safe summary data and recent activity for the first app screen.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;

final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $this->user();

        // Build a complete 30-day series so Chart.js always receives stable,
        // ordered labels, even on days with no payments.
        $dailyRows = $this->db->fetchAll(
            'SELECT DATE(created_at) AS day, COALESCE(SUM(amount),0) AS total
             FROM payments
             WHERE voided_at IS NULL AND created_at >= DATE_SUB(UTC_DATE(), INTERVAL 29 DAY)
             GROUP BY DATE(created_at)
             ORDER BY day'
        );
        $dailyByDate = [];
        foreach ($dailyRows as $row) {
            $dailyByDate[(string)$row['day']] = (float)$row['total'];
        }

        $tz = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable('today', $tz))->modify('-29 days');
        $end = (new DateTimeImmutable('tomorrow', $tz));
        $dailyRevenue = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end) as $date) {
            $key = $date->format('Y-m-d');
            $dailyRevenue[] = [
                'day' => $key,
                'total' => round($dailyByDate[$key] ?? 0, 2),
            ];
        }

        // Today's extras total is calculated from booking_extras instead
        // of stock movements because booking_extras stores the historical
        // unit price that was charged to the guest.
        $extrasSoldToday = $this->db->fetch(
            'SELECT COALESCE(SUM(qty * unit_price), 0) AS total_amount
             FROM booking_extras
             WHERE voided_at IS NULL
               AND DATE(created_at) = UTC_DATE()'
        );

        // Month-to-date extras totals power the dashboard visualization below
        // the revenue chart. The quantity and amount use the copied sale-time
        // unit price from booking_extras, so old catalog price changes cannot
        // rewrite this month's sales statistics.
        $extrasSoldMonth = $this->db->fetchAll(
            'SELECT
                COALESCE(e.name, be.description, "Unknown extra") AS extra_name,
                COALESCE(SUM(be.qty), 0) AS total_qty,
                COALESCE(SUM(be.qty * be.unit_price), 0) AS total_amount
             FROM booking_extras be
             LEFT JOIN extras e ON e.id = be.extra_id
             WHERE be.voided_at IS NULL
               AND be.created_at >= DATE_FORMAT(UTC_DATE(), "%Y-%m-01")
               AND be.created_at < DATE_ADD(LAST_DAY(UTC_DATE()), INTERVAL 1 DAY)
             GROUP BY COALESCE(e.name, be.description, "Unknown extra")
             ORDER BY total_qty DESC, extra_name ASC'
        );
        $data = [
            'rooms' => [
                'total' => (int)$this->db->fetch('SELECT COUNT(*) c FROM rooms WHERE active = 1 AND occupancy_counted = 1')['c'],
                'occupied' => (int)$this->db->fetch('SELECT COUNT(*) c FROM rooms WHERE active = 1 AND occupancy_counted = 1 AND status = "occupied"')['c'],
                'vacant' => (int)$this->db->fetch('SELECT COUNT(*) c FROM rooms WHERE active = 1 AND occupancy_counted = 1 AND status = "vacant"')['c'],
            ],
            'extras_sold_today' => [
                'total_amount' => (float)$extrasSoldToday['total_amount'],
            ],
            'today_revenue' => (float)$this->db->fetch('SELECT COALESCE(SUM(amount),0) total FROM payments WHERE voided_at IS NULL AND DATE(created_at) = UTC_DATE()')['total'],
            'daily_revenue' => $dailyRevenue,
            'extras_sold_month' => array_map(static fn(array $row): array => [
                'extra_name' => (string)$row['extra_name'],
                'total_qty' => (float)$row['total_qty'],
                'total_amount' => (float)$row['total_amount'],
            ], $extrasSoldMonth),
            'outstanding' => $this->db->fetchAll(
                'SELECT b.id, b.guest_name, r.name AS room_name, b.checkin_at
                 FROM bookings b JOIN rooms r ON r.id = b.room_id
                 WHERE b.status = "active" ORDER BY b.checkin_at DESC LIMIT 10'
            ),
            'recent_activity' => $this->db->fetchAll(
                'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 10'
            ),
        ];
        $this->ok('Dashboard loaded.', $data);
    }
}
