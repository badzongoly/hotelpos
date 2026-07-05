<?php

declare(strict_types=1);

/**
 * Booking API controller. Thin HTTP layer over BookingService so business rules stay centralized.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\StockService;

final class BookingController extends Controller
{
    public function index(Request $request): void
    {
        $this->user();
        $query = $request->query();
        $status = (string)($query['status'] ?? 'active');
        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = min(50, max(5, (int)($query['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($status === 'previous') {
            $where[] = 'b.status IN ("checked_out", "cancelled")';
        } elseif ($status !== 'all') {
            $where[] = 'b.status = ?';
            $params[] = $status;
        }

        $search = trim((string)($query['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(b.guest_name LIKE ? OR b.contact LIKE ? OR r.name LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term);
        }

        if (!empty($query['from'])) {
            $where[] = 'DATE(b.checkin_at) >= ?';
            $params[] = (string)$query['from'];
        }
        if (!empty($query['to'])) {
            $where[] = 'DATE(COALESCE(b.checkout_at, b.checkin_at)) <= ?';
            $params[] = (string)$query['to'];
        }

        $whereSql = $where ? implode(' AND ', $where) : '1=1';
        $countRow = $this->db->fetch(
            "SELECT COUNT(*) AS total
             FROM bookings b JOIN rooms r ON r.id = b.room_id
             WHERE $whereSql",
            $params
        );
        $total = (int)($countRow['total'] ?? 0);

        $bookings = $this->db->fetchAll(
            "SELECT b.*, r.name AS room_name
             FROM bookings b JOIN rooms r ON r.id = b.room_id
             WHERE $whereSql
             ORDER BY COALESCE(b.checkout_at,b.checkin_at) DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $billing = new BillingService($this->db);
        foreach ($bookings as &$booking) {
            $booking['totals'] = $billing->totals((int)$booking['id']);
        }
        unset($booking);

        $this->ok('Bookings loaded.', [
            'bookings' => $bookings,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $perPage)),
            ],
            'filters' => [
                'status' => $status,
                'search' => $search,
                'from' => $query['from'] ?? '',
                'to' => $query['to'] ?? '',
            ],
        ]);
    }

    public function checkin(Request $request): void
    {
        $user = $this->role(['administrator', 'manager', 'reception']);
        $input = $request->input();
        $errors = Validator::required($input, ['room_id', 'guest_name']);
        if ($errors) {
            $this->fail('Validation failed.', $errors, 422);
            return;
        }
        try {
            $id = $this->service()->checkin($input, (int)$user['id']);
            $this->ok('Guest checked in.', ['booking_id' => $id]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function addExtra(Request $request): void
    {
        $user = $this->role(['administrator', 'manager', 'reception']);
        try {
            $id = $this->service()->addExtra($request->input(), (int)$user['id']);
            $this->ok('Extra added.', ['booking_extra_id' => $id]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function checkout(Request $request): void
    {
        $user = $this->role(['administrator', 'manager', 'reception']);
        $input = $request->input();
        try {
            $override = !empty($input['allow_override']) && in_array($user['role'], ['administrator', 'manager'], true);
            $totals = $this->service()->checkout((int)$input['booking_id'], (int)$user['id'], $override);
            $this->ok('Checkout completed.', $totals);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    private function service(): BookingService
    {
        $audit = new AuditService($this->db);
        $stock = new StockService($this->db, $audit, (bool)$this->config['app']['allow_negative_stock']);
        return new BookingService($this->db, new BillingService($this->db), new PaymentService($this->db, $audit), $stock, $audit);
    }
}

