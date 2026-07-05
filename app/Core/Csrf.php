<?php

declare(strict_types=1);

/**
 * CSRF token manager. State-changing AJAX requests must send this token with X-CSRF-Token.
 */

namespace App\Core;

final class Csrf
{
    public function __construct(private string $sessionKey)
    {
    }

    public function token(): string
    {
        if (empty($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$this->sessionKey];
    }

    public function verify(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION[$this->sessionKey])
            && hash_equals((string)$_SESSION[$this->sessionKey], $token);
    }
}

