<?php

declare(strict_types=1);

/**
 * Payment API controller. Restricts financial visibility/actions by role and delegates mutation rules to PaymentService.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\PaymentService;

final class PaymentController extends Controller
{
    public function index(Request $request): void
    {
        $this->role(['administrator', 'manager', 'auditor']);
        $this->ok('Payments loaded.', [
            'payments' => $this->db->fetchAll(
                'SELECT p.*, b.guest_name, r.name AS room_name
                 FROM payments p JOIN bookings b ON b.id=p.booking_id JOIN rooms r ON r.id=b.room_id
                 ORDER BY p.created_at DESC LIMIT 100'
            ),
        ]);
    }

    public function record(Request $request): void
    {
        $user = $this->role(['administrator', 'manager', 'reception']);
        try {
            $input = $request->input();
            $id = (new PaymentService($this->db, new AuditService($this->db)))
                ->record((int)$input['booking_id'], $input['method'] ?? 'cash', (float)$input['amount'], $input['note'] ?? null, (int)$user['id']);
            $this->ok('Payment recorded.', ['payment_id' => $id]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }

    public function void(Request $request): void
    {
        $user = $this->role(['administrator', 'manager']);
        try {
            $input = $request->input();
            (new PaymentService($this->db, new AuditService($this->db)))
                ->void((int)$input['payment_id'], trim((string)($input['reason'] ?? 'Voided')), (int)$user['id']);
            $this->ok('Payment voided.');
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }
}

