<?php

declare(strict_types=1);

/**
 * Reporting service. Keeps financial aggregate queries in one place and excludes voided rows from revenue/expense totals.
 */

namespace App\Services;

use App\Core\Database;

final class ReportService
{
    public function __construct(private Database $db)
    {
    }

    public function summary(string $start, string $end): array
    {
        $params = [$start . ' 00:00:00', $end . ' 23:59:59'];
        $revenue = (float)$this->db->fetch(
            'SELECT COALESCE(SUM(amount),0) AS total FROM payments WHERE voided_at IS NULL AND created_at BETWEEN ? AND ?',
            $params
        )['total'];
        $expenses = (float)$this->db->fetch(
            'SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE voided_at IS NULL AND expense_date BETWEEN ? AND ?',
            [$start, $end]
        )['total'];
        $byMethod = $this->db->fetchAll(
            'SELECT method, SUM(amount) AS total FROM payments WHERE voided_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY method',
            $params
        );
        $daily = $this->db->fetchAll(
            'SELECT DATE(created_at) AS day, SUM(amount) AS total FROM payments WHERE voided_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY day',
            $params
        );

        return [
            'revenue' => round($revenue, 2),
            'expenses' => round($expenses, 2),
            'net_income' => round($revenue - $expenses, 2),
            'payments_by_method' => $byMethod,
            'daily_revenue' => $daily,
        ];
    }
}

