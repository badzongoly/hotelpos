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
        $this->ok('Stock loaded.', [
            'extras' => $this->db->fetchAll('SELECT id, name, stock_qty, stock_tracked FROM extras WHERE active = 1 ORDER BY name'),
            'movements' => $this->db->fetchAll(
                'SELECT sm.*, e.name AS extra_name, u.name AS user_name
                 FROM stock_movements sm JOIN extras e ON e.id=sm.extra_id LEFT JOIN users u ON u.id=sm.created_by
                 ORDER BY sm.created_at DESC LIMIT 100'
            ),
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

