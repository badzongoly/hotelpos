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

        $query = $request->query();
        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = min(50, max(5, (int)($query['per_page'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if (!empty($query['entity'])) {
            $where[] = 'a.entity LIKE ?';
            $params[] = '%' . trim((string)$query['entity']) . '%';
        }

        if (!empty($query['action'])) {
            $where[] = 'a.action LIKE ?';
            $params[] = '%' . trim((string)$query['action']) . '%';
        }

        if (!empty($query['from'])) {
            $where[] = 'DATE(a.created_at) >= ?';
            $params[] = trim((string)$query['from']);
        }

        if (!empty($query['to'])) {
            $where[] = 'DATE(a.created_at) <= ?';
            $params[] = trim((string)$query['to']);
        }

        if (!empty($query['search'])) {
            $search = '%' . trim((string)$query['search']) . '%';
            $where[] = '(a.action LIKE ? OR a.entity LIKE ? OR u.name LIKE ? OR CAST(a.entity_id AS CHAR) LIKE ?)';
            array_push($params, $search, $search, $search, $search);
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $countRow = $this->db->fetch(
            "SELECT COUNT(*) c FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id {$whereSql}",
            $params
        );
        $total = (int)($countRow['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $perPage));

        $logs = $this->db->fetchAll(
            "SELECT a.created_at, a.action, a.entity, a.entity_id, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id
             {$whereSql}
             ORDER BY a.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $this->ok('Audit logs loaded.', [
            'logs' => $logs,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $pages,
                'total' => $total,
            ],
        ]);
    }
}
