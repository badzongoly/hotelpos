<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Room;

final class RoomRepository extends Repository
{
    public function __construct(Database $db)
    {
        parent::__construct($db, Room::class);
    }

    public function available(): array
    {
        return array_map(fn(array $row) => new Room($row), $this->db->fetchAll('SELECT * FROM rooms WHERE active = 1 AND status = "vacant" ORDER BY sort_order, id'));
    }
}
