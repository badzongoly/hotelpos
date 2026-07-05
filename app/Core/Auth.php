<?php

declare(strict_types=1);

/**
 * Session authentication helper. Owns login/logout, idle timeout enforcement, and role-based API guards.
 */

namespace App\Core;

final class Auth
{
    public function __construct(private int $idleSeconds)
    {
    }

    public function user(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            return null;
        }
        // Idle timeout protects an unattended reception or management computer.
        if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $this->idleSeconds) {
            $this->logout();
            return null;
        }
        $_SESSION['last_activity'] = time();
        return $user;
    }

    public function login(array $user): void
    {
        // Regenerate on login to prevent session fixation.
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role'],
        ];
        $_SESSION['last_activity'] = time();
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    public function requireUser(): array
    {
        $user = $this->user();
        if (!$user) {
            Response::error('Authentication required.', ['auth' => 'login_required'], 401);
            exit;
        }
        return $user;
    }

    public function requireRole(array $roles): array
    {
        $user = $this->requireUser();
        if (!in_array($user['role'], $roles, true)) {
            Response::error('You are not allowed to perform this action.', ['role' => 'forbidden'], 403);
            exit;
        }
        return $user;
    }
}

