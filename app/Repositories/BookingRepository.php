<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Booking;

final class BookingRepository extends Repository
{
    public function __construct(Database $db)
    {
        parent::__construct($db, Booking::class);
    }

    public function activeForRoom(int $roomId): ?Booking
    {
        $row = $this->db->fetch('SELECT * FROM bookings WHERE room_id = ? AND status = "active" LIMIT 1', [$roomId]);
        return $row ? new Booking($row) : null;
    }
}
