<?php

declare(strict_types=1);

/**
 * User administration API controller. Protects roles, password resets, and last-administrator safety.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Validator;
use App\Services\AuditService;

final class UserController extends Controller
{
    public function index(Request $request): void
    {
        $this->role(['administrator']);
        $this->ok('Users loaded.', [
            'users' => $this->db->fetchAll('SELECT id, email, name, role, active, created_at FROM users ORDER BY created_at DESC'),
        ]);
    }

    public function save(Request $request): void
    {
        $user = $this->role(['administrator']);
        $input = $request->input();
        $errors = Validator::required($input, ['email', 'name', 'role']);
        if (!in_array($input['role'] ?? '', ['administrator', 'manager', 'reception', 'auditor'], true)) {
            $errors['role'] = 'Invalid role.';
        }
        if (empty($input['id']) && empty($input['password'])) {
            $errors['password'] = 'Password required.';
        }
        if ($errors) {
            $this->fail('Validation failed.', $errors, 422);
            return;
        }
        $audit = new AuditService($this->db);
        if (!empty($input['id'])) {
            $old = $this->db->fetch('SELECT id, email, name, role, active FROM users WHERE id = ?', [(int)$input['id']]);
            if (!empty($input['password'])) {
                $this->db->execute('UPDATE users SET email=?, name=?, role=?, password_hash=?, active=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [
                    trim($input['email']), trim($input['name']), $input['role'], password_hash($input['password'], PASSWORD_DEFAULT), (int)($input['active'] ?? 1), (int)$input['id'],
                ]);
            } else {
                $this->db->execute('UPDATE users SET email=?, name=?, role=?, active=?, updated_at=UTC_TIMESTAMP() WHERE id=?', [
                    trim($input['email']), trim($input['name']), $input['role'], (int)($input['active'] ?? 1), (int)$input['id'],
                ]);
            }
            $audit->log($user['id'], 'user.updated', 'user', (int)$input['id'], $old ?: [], ['email' => $input['email'], 'name' => $input['name'], 'role' => $input['role']]);
            $this->ok('User updated.');
            return;
        }
        $this->db->execute(
            'INSERT INTO users(email, password_hash, name, role, active, created_at) VALUES(?,?,?,?,1,UTC_TIMESTAMP())',
            [trim($input['email']), password_hash($input['password'], PASSWORD_DEFAULT), trim($input['name']), $input['role']]
        );
        $id = (int)$this->db->pdo()->lastInsertId();
        $audit->log($user['id'], 'user.created', 'user', $id, [], ['email' => $input['email'], 'name' => $input['name'], 'role' => $input['role']]);
        $this->ok('User created.', ['id' => $id]);
    }

    public function disable(Request $request): void
    {
        $user = $this->role(['administrator']);
        $input = $request->input();
        $id = (int)$input['user_id'];
        $target = $this->db->fetch('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$target) {
            $this->fail('User not found.');
            return;
        }
        if ($target['role'] === 'administrator') {
            $admins = (int)$this->db->fetch('SELECT COUNT(*) c FROM users WHERE role="administrator" AND active = 1')['c'];
            if ($admins <= 1) {
                $this->fail('Cannot disable the last active administrator.');
                return;
            }
        }
        $this->db->execute('UPDATE users SET active=0, updated_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
        (new AuditService($this->db))->log($user['id'], 'user.disabled', 'user', $id, $target, []);
        $this->ok('User disabled.');
    }
}

