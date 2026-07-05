<?php

declare(strict_types=1);

/**
 * Settings API controller. Stores small operational settings with audit logs for administrator changes.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuditService;

final class SettingController extends Controller
{
    public function index(Request $request): void
    {
        $this->role(['administrator', 'manager']);
        $this->ok('Settings loaded.', [
            'settings' => $this->db->fetchAll('SELECT setting_key, setting_value FROM settings ORDER BY setting_key'),
        ]);
    }

    public function save(Request $request): void
    {
        $user = $this->role(['administrator']);
        $input = $request->input();
        $this->db->execute(
            'INSERT INTO settings(setting_key, setting_value, updated_by, updated_at)
             VALUES(?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by), updated_at=UTC_TIMESTAMP()',
            [$input['setting_key'], $input['setting_value'], (int)$user['id']]
        );
        (new AuditService($this->db))->log($user['id'], 'setting.saved', 'setting', null, [], $input);
        $this->ok('Setting saved.');
    }
}

