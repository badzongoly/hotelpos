<?php

declare(strict_types=1);

/**
 * Booking workflow service. Uses transactions to keep bookings, payments, room status, extras, stock, and audit logs consistent.
 */

namespace App\Services;

use App\Core\Database;

final class BookingService
{
    public function __construct(
        private Database $db,
        private BillingService $billing,
        private PaymentService $payments,
        private StockService $stock,
        private AuditService $audit
    ) {
    }

    public function checkin(array $input, int $userId): int
    {
        // Check-in changes several records together: booking, optional payment,
        // room status, and audit log. A transaction prevents half-created stays.
        // Adding an extra may also deduct stock and create a stock movement, so
        // the line item and inventory change must commit or roll back together.
        return (int)$this->db->transaction(function () use ($input, $userId) {
            $roomId = (int)$input['room_id'];
            // Lock onto the current room master row. The current room rate is
            // copied below into bookings.rate_per_night for historical accuracy.
            $room = $this->db->fetch('SELECT * FROM rooms WHERE id = ? AND active = 1', [$roomId]);
            if (!$room || $room['status'] !== 'vacant') {
                throw new \RuntimeException('Room is not available.');
            }
            // A room may not have two active bookings at the same time.
            $active = $this->db->fetch('SELECT id FROM bookings WHERE room_id = ? AND status = "active"', [$roomId]);
            if ($active) {
                throw new \RuntimeException('Room already has an active booking.');
            }

            $this->db->execute(
                'INSERT INTO bookings(room_id, rate_per_night, guest_name, gender, nationality, contact, checkin_at, status, created_by, created_at)
                 VALUES(?,?,?,?,?,?,?, "active", ?, UTC_TIMESTAMP())',
                [
                    $roomId,
                    // Preserve historical pricing: future room rate changes must
                    // not change this booking's bill or old reports.
                    (float)$room['rate'],
                    trim((string)$input['guest_name']),
                    $input['gender'] ?? null,
                    trim((string)($input['nationality'] ?? '')),
                    trim((string)($input['contact'] ?? '')),
                    ($input['checkin_at'] ?? '') ?: gmdate('Y-m-d H:i:s'),
                    $userId,
                ]
            );
            $bookingId = (int)$this->db->pdo()->lastInsertId();
            $this->db->execute('UPDATE rooms SET status = "occupied" WHERE id = ?', [$roomId]);

            $deposit = (float)($input['amount_paid'] ?? 0);
            // Deposits are normal payment rows, so reports and balances use the
            // same payment aggregation logic for check-in and checkout money.
            if ($deposit > 0) {
                $this->payments->record($bookingId, $input['method'] ?? 'cash', $deposit, 'Check-in deposit', $userId);
            }
            $this->audit->log($userId, 'booking.checked_in', 'booking', $bookingId, [], $input);
            return $bookingId;
        });
    }

    public function addExtra(array $input, int $userId): int
    {
        // Check-in changes several records together: booking, optional payment,
        // room status, and audit log. A transaction prevents half-created stays.
        // Adding an extra may also deduct stock and create a stock movement, so
        // the line item and inventory change must commit or roll back together.
        return (int)$this->db->transaction(function () use ($input, $userId) {
            $bookingId = (int)$input['booking_id'];
            $extraId = (int)$input['extra_id'];
            $qty = (float)($input['qty'] ?? 1);
            if ($qty <= 0) {
                throw new \InvalidArgumentException('Quantity must be greater than zero.');
            }
            $booking = $this->db->fetch('SELECT * FROM bookings WHERE id = ? AND status = "active"', [$bookingId]);
            $extra = $this->db->fetch('SELECT * FROM extras WHERE id = ? AND active = 1', [$extraId]);
            if (!$booking || !$extra) {
                throw new \RuntimeException('Booking or extra not found.');
            }
            // Copy the current/overridden price into the booking extra. This is
            // the historical unit price used for the guest's bill.
            $unitPrice = isset($input['unit_price']) && $input['unit_price'] !== '' ? (float)$input['unit_price'] : (float)$extra['price'];
            $this->db->execute(
                'INSERT INTO booking_extras(booking_id, extra_id, description, qty, unit_price, created_by, created_at)
                 VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())',
                [$bookingId, $extraId, $extra['name'], $qty, $unitPrice, $userId]
            );
            $lineId = (int)$this->db->pdo()->lastInsertId();
            if ((int)$extra['stock_tracked'] === 1) {
                $this->stock->movement($extraId, 'out', $qty, null, 'Booking extra sale', 'booking_extra', $lineId, $userId);
            }
            $this->audit->log($userId, 'booking.extra_added', 'booking_extra', $lineId, [], $input);
            return $lineId;
        });
    }

    public function checkout(int $bookingId, int $userId, bool $allowOverride = false): array
    {
        // Checkout finalizes billing and frees the room. Keeping this in one
        // transaction avoids a checked-out booking with an occupied room, or the reverse.
        return $this->db->transaction(function () use ($bookingId, $userId, $allowOverride) {
            $totals = $this->billing->totals($bookingId);
            if ($totals['balance'] > 0 && !$allowOverride) {
                throw new \RuntimeException('Outstanding balance must be settled before checkout.');
            }
            $booking = $totals['booking'];
            $this->db->execute(
                'UPDATE bookings SET status = "checked_out", checkout_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$bookingId]
            );
            $this->db->execute('UPDATE rooms SET status = "vacant" WHERE id = ?', [(int)$booking['room_id']]);
            $this->audit->log($userId, 'booking.checked_out', 'booking', $bookingId, [], $totals);
            return $totals;
        });
    }
}

