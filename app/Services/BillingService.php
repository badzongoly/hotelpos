<?php

declare(strict_types=1);

/**
 * Billing service.
 *
 * This class is the single source of truth for chargeable nights and booking
 * totals. Any page, report, export, or API response that needs booking totals
 * should call this service rather than reimplementing the math.
 */

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class BillingService
{
    /**
     * Database is optional so computeNights() can be tested without MySQL.
     * totals() explicitly fails if a caller asks for database-backed totals
     * without passing a Database instance.
     */
    public function __construct(private ?Database $db = null)
    {
    }

    /**
     * Compute chargeable nights using the SRS noon cutoff rule.
     *
     * Rules:
     * - Checkout at or before noon on the day after check-in is 1 night.
     * - Checkout after that noon boundary adds another night.
     * - Every additional noon boundary crossed adds another night.
     * - Invalid/same-time checkouts still bill as 1 night to avoid zero totals.
     */
    public function computeNights(DateTimeInterface $checkin, DateTimeInterface $checkout): int
    {
        // Defensive guard: billing should never return zero or negative nights.
        if ($checkout <= $checkin) {
            return 1;
        }

        // First billable boundary is noon on the day after check-in, regardless
        // of whether the guest arrived before noon or after noon.
        $firstNoon = (new DateTimeImmutable($checkin->format('Y-m-d H:i:s'), $checkin->getTimezone()))
            ->setTime(12, 0, 0)
            ->modify('+1 day');

        // Exactly noon is still included in the first night. 12:01 PM is not.
        if ($checkout <= $firstNoon) {
            return 1;
        }

        // After the first noon, every started 24-hour noon window adds a night.
        $seconds = $checkout->getTimestamp() - $firstNoon->getTimestamp();
        return 1 + max(0, (int)ceil($seconds / 86400));
    }

    /**
     * Return all billing totals for a booking using stored historical values.
     *
     * Important financial rules:
     * - Use bookings.rate_per_night, never the current room rate.
     * - Ignore voided extras and voided payments.
     * - Compute balance from the same values shown on checkout screens/reports.
     */
    public function totals(int $bookingId, ?string $checkoutAt = null): array
    {
        if (!$this->db) {
            throw new \RuntimeException('Database is required for totals.');
        }

        $booking = $this->db->fetch(
            'SELECT b.*, r.name AS room_name
             FROM bookings b JOIN rooms r ON r.id = b.room_id
             WHERE b.id = ?',
            [$bookingId]
        );
        if (!$booking) {
            throw new \RuntimeException('Booking not found.');
        }

        // All server-side timestamps are UTC so reports remain consistent.
        $tz = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($booking['checkin_at'], $tz);
        $end = new DateTimeImmutable($checkoutAt ?: ($booking['checkout_at'] ?: 'now'), $tz);

        $nights = $this->computeNights($start, $end);
        $rate = (float)$booking['rate_per_night'];
        $roomTotal = $nights * $rate;

        // Voided extras are excluded so corrected sales do not inflate totals.
        $extras = $this->db->fetchAll(
            'SELECT id, booking_id, extra_id, description, qty, unit_price, created_at,
                    (qty * unit_price) AS line_total
             FROM booking_extras
             WHERE booking_id = ? AND voided_at IS NULL
             ORDER BY created_at DESC, id DESC',
            [$bookingId]
        );
        $extrasTotal = 0.0;
        foreach ($extras as &$extra) {
            $extra['qty'] = (float)$extra['qty'];
            $extra['unit_price'] = (float)$extra['unit_price'];
            $extra['line_total'] = round((float)$extra['line_total'], 2);
            $extrasTotal += (float)$extra['line_total'];
        }
        unset($extra);

        // Voided payments are excluded from revenue and balance calculations.
        $payments = $this->db->fetchAll(
            'SELECT id, booking_id, method, amount, note, created_at
             FROM payments
             WHERE booking_id = ? AND voided_at IS NULL
             ORDER BY created_at DESC, id DESC',
            [$bookingId]
        );
        $paidTotal = 0.0;
        foreach ($payments as &$payment) {
            $payment['amount'] = round((float)$payment['amount'], 2);
            $paidTotal += (float)$payment['amount'];
        }
        unset($payment);

        $grandTotal = $roomTotal + $extrasTotal;

        return [
            'booking' => $booking,
            'nights' => $nights,
            'rate_per_night' => $rate,
            'room_total' => round($roomTotal, 2),
            'extras_total' => round($extrasTotal, 2),
            'extras' => $extras,
            'paid_total' => round($paidTotal, 2),
            'payments' => $payments,
            'grand_total' => round($grandTotal, 2),
            'balance' => round(max(0, $grandTotal - $paidTotal), 2),
        ];
    }
}
