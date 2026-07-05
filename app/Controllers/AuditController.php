<?php

declare(strict_types=1);

/**
 * Audit API controller. Read-only access to sensitive action history for authorized roles.
 */

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;

final class AuditController extends Controller
{
    public function index(Request $request): void
    {
        $this->role(['administrator', 'manager', 'auditor']);
        $this->ok('Audit logs loaded.', [
            'logs' => $this->db->fetchAll(
                'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 200'
            ),
        ]);
    }
}

