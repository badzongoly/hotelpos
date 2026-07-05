<?php

declare(strict_types=1);

/**
 * Extras catalog API controller. Prices here are current catalog prices; booking extras copy unit price at sale time.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuditService;

final class ExtraController extends Controller
{
    public function index(Request $request): void
    {
        $this->user();
        $this->ok('Extras loaded.', [
            'extras' => $this->db->fetchAll('SELECT * FROM extras ORDER BY active DESC, name'),
        ]);
    }

    public function save(Request $request): void
    {
        $user = $this->role(['administrator', 'manager']);
        $input = $request->input();
        $errors = Validator::required($input, ['name', 'price']);
        if ($errors || !is_numeric($input['price'])) {
            $this->fail('Validation failed.', $errors + ['price' => 'Valid price required.'], 422);
            return;
        }
        $audit = new AuditService($this->db);
        if (!empty($input['id'])) {
            $old = $this->db->fetch('SELECT * FROM extras WHERE id = ?', [(int)$input['id']]);
            $this->db->execute(
                'UPDATE extras SET name=?, price=?, active=?, stock_tracked=?, updated_at=UTC_TIMESTAMP() WHERE id=?',
                [trim($input['name']), (float)$input['price'], (int)($input['active'] ?? 1), (int)($input['stock_tracked'] ?? 1), (int)$input['id']]
            );
            $audit->log($user['id'], 'extra.updated', 'extra', (int)$input['id'], $old ?: [], $input);
            $this->ok('Extra updated.');
            return;
        }
        $this->db->execute(
            'INSERT INTO extras(name, price, active, stock_tracked, stock_qty, created_at) VALUES(?,?,?,?,0,UTC_TIMESTAMP())',
            [trim($input['name']), (float)$input['price'], (int)($input['active'] ?? 1), (int)($input['stock_tracked'] ?? 1)]
        );
        $id = (int)$this->db->pdo()->lastInsertId();
        $audit->log($user['id'], 'extra.created', 'extra', $id, [], $input);
        $this->ok('Extra created.', ['id' => $id]);
    }
}

