<?php

declare(strict_types=1);

/**
 * JSON response helper. Every API endpoint should use this envelope so the frontend can handle success/errors consistently.
 */

namespace App\Core;

final class Response
{
    public static function json(bool $success, string $message, array $data = [], array $errors = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function success(string $message, array $data = [], int $status = 200): void
    {
        self::json(true, $message, $data, [], $status);
    }

    public static function error(string $message, array $errors = [], int $status = 400): void
    {
        self::json(false, $message, [], $errors, $status);
    }
}

