<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ReportRepository
{
    public function __construct(private Database $db)
    {
    }

    public function paymentsByDate(string $start, string $end): array
    {
        return $this->db->fetchAll('SELECT DATE(created_at) d, SUM(amount) s FROM payments WHERE voided_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at)', [$start, $end]);
    }
}
