<?php

declare(strict_types=1);

/**
 * Audit logging service. Captures sensitive business actions with user, entity, old/new values, IP, and user agent when available.
 */

namespace App\Services;

use App\Core\Database;

final class AuditService
{
    public function __construct(private Database $db)
    {
    }

    public function log(?int $userId, string $action, string $entity, ?int $entityId = null, array $old = [], array $new = []): void
    {
        $this->db->execute(
            'INSERT INTO audit_logs(user_id, action, entity, entity_id, old_values, new_values, ip_address, user_agent, created_at)
             VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
            [
                $userId,
                $action,
                $entity,
                $entityId,
                $old ? json_encode($old, JSON_UNESCAPED_SLASHES) : null,
                $new ? json_encode($new, JSON_UNESCAPED_SLASHES) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]
        );
    }
}

