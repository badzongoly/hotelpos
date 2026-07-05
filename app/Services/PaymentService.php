<?php

declare(strict_types=1);

/**
 * Payment service. Records and voids payments; normal UI should void/reverse financial rows instead of deleting them.
 */

namespace App\Services;

use App\Core\Database;

final class PaymentService
{
    // Keep payment methods centralized so validation, UI, and reports stay aligned.
    private const METHODS = ['cash', 'momo', 'card', 'bank', 'other'];

    public function __construct(private Database $db, private AuditService $audit)
    {
    }

    /**
     * Record a payment as an append-only financial event.
     * Corrections should call void() rather than deleting or overwriting rows.
     */
    public function record(int $bookingId, string $method, float $amount, ?string $note, int $userId): int
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Invalid payment method.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $this->db->execute(
            'INSERT INTO payments(booking_id, method, amount, note, created_by, created_at)
             VALUES(?,?,?,?,?,UTC_TIMESTAMP())',
            [$bookingId, $method, $amount, $note, $userId]
        );
        $id = (int)$this->db->pdo()->lastInsertId();
        $this->audit->log($userId, 'payment.recorded', 'payment', $id, [], compact('bookingId', 'method', 'amount', 'note'));
        return $id;
    }

    /**
     * Void a payment without deleting it so audit/reconciliation history remains intact.
     */
    public function void(int $paymentId, string $reason, int $userId): void
    {
        $payment = $this->db->fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$payment || $payment['voided_at']) {
            throw new \RuntimeException('Payment not found or already voided.');
        }
        $this->db->execute(
            'UPDATE payments SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?',
            [$userId, $reason, $paymentId]
        );
        $this->audit->log($userId, 'payment.voided', 'payment', $paymentId, $payment, ['reason' => $reason]);
    }
}

