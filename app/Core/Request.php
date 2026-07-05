<?php

declare(strict_types=1);

/**
 * Small request adapter. Normalizes method, path, query/body input, and headers for the lightweight router.
 */

namespace App\Core;

final class Request
{
    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        // Apache accepts PATH_INFO style URLs such as /api/index.php/login.
        // Strip the full script name first so the router receives /login.
        if ($scriptName && str_starts_with($uri, $scriptName)) {
            $uri = substr($uri, strlen($scriptName));
            return '/' . trim($uri, '/');
        }

        // Fallback for URLs routed to the script directory instead of the full
        // script path. This keeps /api/login style setups possible later.
        $scriptDir = dirname($scriptName);
        if ($scriptDir !== '/' && $scriptDir !== '\\' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }
        return '/' . trim($uri, '/');
    }

    public function input(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $_POST ?: $_GET;
    }

    public function query(): array
    {
        return $_GET;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function userAgent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }
}
