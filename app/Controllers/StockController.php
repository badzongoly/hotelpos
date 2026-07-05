<?php

declare(strict_types=1);

/**
 * Stock API controller. Provides stock balances and movement entry for tracked extras.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\StockService;

final class StockController extends Controller
{
    public function index(Request $request): void
    {
        $this->role(['administrator', 'manager', 'reception', 'auditor']);

        $query = $request->query();
        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = min(50, max(5, (int)($query['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        // Inventory is used both by the movement form and the inventory tab.
        // The UI no longer needs internal IDs or stock-tracked flags in the table,
        // but the ID stays in the payload so filters/forms can still post safely.
        $extras = $this->db->fetchAll('SELECT id, name, stock_qty FROM extras WHERE active = 1 ORDER BY name');

        $where = [];
        $params = [];

        if (!empty($query['extra_id'])) {
            $where[] = 'sm.extra_id = ?';
            $params[] = (int)$query['extra_id'];
        }

        if (!empty($query['movement_type'])) {
            $where[] = 'sm.movement_type = ?';
            $params[] = trim((string)$query['movement_type']);
        }

        if (!empty($query['from'])) {
            $where[] = 'DATE(sm.created_at) >= ?';
            $params[] = trim((string)$query['from']);
        }

        if (!empty($query['to'])) {
            $where[] = 'DATE(sm.created_at) <= ?';
            $params[] = trim((string)$query['to']);
        }

        if (!empty($query['search'])) {
            $search = '%' . trim((string)$query['search']) . '%';
            $where[] = '(e.name LIKE ? OR sm.note LIKE ? OR u.name LIKE ?)';
            array_push($params, $search, $search, $search);
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $countRow = $this->db->fetch(
            "SELECT COUNT(*) c FROM stock_movements sm JOIN extras e ON e.id=sm.extra_id LEFT JOIN users u ON u.id=sm.created_by {$whereSql}",
            $params
        );
        $total = (int)($countRow['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $perPage));

        $movements = $this->db->fetchAll(
            "SELECT sm.created_at, sm.movement_type, sm.qty, sm.note, e.name AS extra_name, u.name AS user_name
             FROM stock_movements sm JOIN extras e ON e.id=sm.extra_id LEFT JOIN users u ON u.id=sm.created_by
             {$whereSql}
             ORDER BY sm.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $this->ok('Stock loaded.', [
            'extras' => $extras,
            'movements' => $movements,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
                'total' => $total,
            ],
        ]);
    }

    public function movement(Request $request): void
    {
        $user = $this->role(['administrator', 'manager', 'reception']);
        $input = $request->input();
        try {
            $id = (new StockService($this->db, new AuditService($this->db), (bool)$this->config['app']['allow_negative_stock']))
                ->movement((int)$input['extra_id'], $input['movement_type'] ?? 'in', (float)$input['qty'], isset($input['unit_cost']) ? (float)$input['unit_cost'] : null, $input['note'] ?? null, 'manual', null, (int)$user['id']);
            $this->ok('Stock movement recorded.', ['stock_movement_id' => $id]);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage());
        }
    }
}
