<?php

declare(strict_types=1);

/**
 * Minimal API router. Maps HTTP method + path to controller callables and enforces CSRF checks for state-changing requests.
 */

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler, bool $csrf = true): void
    {
        $this->routes[strtoupper($method)][$this->normalize($path)] = [$handler, $csrf];
    }

    public function dispatch(Request $request, Csrf $csrf): void
    {
        $method = $request->method();
        $path = $this->normalize($request->path());
        $route = $this->routes[$method][$path] ?? null;

        if (!$route) {
            Response::error('Endpoint not found.', ['path' => $path], 404);
            return;
        }

        [$handler, $requiresCsrf] = $route;
        // Only mutating verbs need CSRF protection. Read endpoints can be loaded
        // by normal page refreshes or dashboards without a token race.
        if ($requiresCsrf && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->header('X-CSRF-Token') ?? ($request->input()['_csrf'] ?? null);
            if (!$csrf->verify($token)) {
                Response::error('Invalid CSRF token.', ['csrf' => 'invalid'], 419);
                return;
            }
        }

        $handler($request);
    }

    private function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }
}

