<?php

declare(strict_types=1);

/**
 * Base controller. Provides shared access to database, auth, CSRF, config, and JSON response helpers.
 */

namespace App\Core;

abstract class Controller
{
    public function __construct(
        protected Database $db,
        protected Auth $auth,
        protected Csrf $csrf,
        protected array $config
    ) {
    }

    protected function user(): array
    {
        return $this->auth->requireUser();
    }

    protected function role(array $roles): array
    {
        return $this->auth->requireRole($roles);
    }

    protected function ok(string $message, array $data = []): void
    {
        Response::success($message, $data);
    }

    protected function fail(string $message, array $errors = [], int $status = 400): void
    {
        Response::error($message, $errors, $status);
    }
}

