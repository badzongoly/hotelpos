<?php

declare(strict_types=1);

/**
 * Room API controller. Manager/admin-only writes are audited because room rates affect future booking totals.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuditService;

final class RoomController extends Controller
{
    public function index(Request $request): void
    {
        $this->user();
        $this->ok('Rooms loaded.', [
            'rooms' => $this->db->fetchAll('SELECT * FROM rooms ORDER BY sort_order, id'),
        ]);
    }

    public function save(Request $request): void
    {
        $user = $this->role(['administrator', 'manager']);
        $input = $request->input();
        $errors = Validator::required($input, ['name', 'type', 'rate']);
        if ($errors || !is_numeric($input['rate'])) {
            $this->fail('Validation failed.', $errors + ['rate' => 'Valid rate required.'], 422);
            return;
        }
        $audit = new AuditService($this->db);
        if (!empty($input['id'])) {
            $old = $this->db->fetch('SELECT * FROM rooms WHERE id = ?', [(int)$input['id']]);
            $this->db->execute(
                'UPDATE rooms SET name=?, type=?, rate=?, active=?, occupancy_counted=?, updated_at=UTC_TIMESTAMP() WHERE id=?',
                [trim($input['name']), trim($input['type']), (float)$input['rate'], (int)($input['active'] ?? 1), (int)($input['occupancy_counted'] ?? 1), (int)$input['id']]
            );
            $audit->log($user['id'], 'room.updated', 'room', (int)$input['id'], $old ?: [], $input);
            $this->ok('Room updated.');
            return;
        }
        $this->db->execute(
            'INSERT INTO rooms(name, type, rate, status, active, occupancy_counted, created_at) VALUES(?,?,?,"vacant",?,?,UTC_TIMESTAMP())',
            [trim($input['name']), trim($input['type']), (float)$input['rate'], (int)($input['active'] ?? 1), (int)($input['occupancy_counted'] ?? 1)]
        );
        $id = (int)$this->db->pdo()->lastInsertId();
        $audit->log($user['id'], 'room.created', 'room', $id, [], $input);
        $this->ok('Room created.', ['id' => $id]);
    }

    public function status(Request $request): void
    {
        $user = $this->role(['administrator', 'manager']);
        $input = $request->input();
        if (!Validator::enum($input['status'] ?? null, ['vacant', 'occupied', 'dirty', 'maintenance'])) {
            $this->fail('Invalid room status.', ['status' => 'invalid'], 422);
            return;
        }
        $old = $this->db->fetch('SELECT * FROM rooms WHERE id = ?', [(int)$input['id']]);
        $this->db->execute('UPDATE rooms SET status=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [$input['status'], (int)$input['id']]);
        (new AuditService($this->db))->log($user['id'], 'room.status_changed', 'room', (int)$input['id'], $old ?: [], $input);
        $this->ok('Room status updated.');
    }
}

