<?php

declare(strict_types=1);

/**
 * Single JSON API front controller. Bootstraps dependencies, registers routes, and dispatches the current request.
 */

use App\Controllers\AuthController;
use App\Controllers\AuditController;
use App\Controllers\BookingController;
use App\Controllers\DashboardController;
use App\Controllers\ExpenseController;
use App\Controllers\ExtraController;
use App\Controllers\PaymentController;
use App\Controllers\ReportController;
use App\Controllers\RoomController;
use App\Controllers\SettingController;
use App\Controllers\StockController;
use App\Controllers\UserController;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;

// Bootstrap loads autoloading, config, session settings, and UTC timezone.
$config = require dirname(__DIR__, 2) . '/app/bootstrap.php';

// Always return JSON for uncaught runtime/database exceptions. Without this,
// PHP would emit an HTML fatal error page and the frontend would only know
// that it received an "Invalid JSON response".
set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'data' => [],
        'errors' => ['exception' => get_class($e)],
    ], JSON_UNESCAPED_SLASHES);
});

$db = new Database($config['database']);
$auth = new Auth((int)$config['app']['session_idle_minutes'] * 60);
$csrf = new Csrf($config['app']['csrf_key']);
$router = new Router();

// Small factory so every controller receives the same shared dependencies.
$make = fn(string $class) => new $class($db, $auth, $csrf, $config);

// Session/security endpoints.
$router->add('GET', '/csrf', fn(Request $r) => App\Core\Response::success('CSRF token generated.', ['token' => $csrf->token()]), false);
$router->add('GET', '/me', [$make(AuthController::class), 'me'], false);
$router->add('POST', '/login', [$make(AuthController::class), 'login'], false);
$router->add('POST', '/password/forgot', [$make(AuthController::class), 'forgotPassword']);
$router->add('POST', '/password/reset', [$make(AuthController::class), 'resetPassword']);
$router->add('POST', '/password/reset-token', [$make(AuthController::class), 'resetWithToken']);
$router->add('POST', '/logout', [$make(AuthController::class), 'logout']);

// Reception dashboard and room-management endpoints.
$router->add('GET', '/dashboard', [$make(DashboardController::class), 'index'], false);
$router->add('GET', '/rooms', [$make(RoomController::class), 'index'], false);
$router->add('POST', '/rooms/save', [$make(RoomController::class), 'save']);
$router->add('POST', '/rooms/status', [$make(RoomController::class), 'status']);

// Booking workflow endpoints. Mutations are CSRF-protected by default.
$router->add('GET', '/bookings', [$make(BookingController::class), 'index'], false);
$router->add('POST', '/checkin', [$make(BookingController::class), 'checkin']);
$router->add('POST', '/checkout', [$make(BookingController::class), 'checkout']);
$router->add('POST', '/bookings/extra', [$make(BookingController::class), 'addExtra']);

// Payment ledger endpoints. Voids are separate actions, not deletes.
$router->add('GET', '/payments', [$make(PaymentController::class), 'index'], false);
$router->add('POST', '/payments/record', [$make(PaymentController::class), 'record']);
$router->add('POST', '/payments/void', [$make(PaymentController::class), 'void']);

// Extras catalog and stock endpoints.
$router->add('GET', '/extras', [$make(ExtraController::class), 'index'], false);
$router->add('POST', '/extras/save', [$make(ExtraController::class), 'save']);
$router->add('GET', '/stock', [$make(StockController::class), 'index'], false);
$router->add('POST', '/stock/movement', [$make(StockController::class), 'movement']);

// Expense ledger endpoints. Voids preserve audit history.
$router->add('GET', '/expenses', [$make(ExpenseController::class), 'index'], false);
$router->add('POST', '/expenses/save', [$make(ExpenseController::class), 'save']);
$router->add('POST', '/expenses/void', [$make(ExpenseController::class), 'void']);

// Manager/auditor endpoints for reporting, users, settings, and audit review.
$router->add('GET', '/reports/summary', [$make(ReportController::class), 'summary'], false);
$router->add('GET', '/reports/analytics', [$make(ReportController::class), 'analytics'], false);
$router->add('GET', '/users', [$make(UserController::class), 'index'], false);
$router->add('POST', '/users/save', [$make(UserController::class), 'save']);
$router->add('POST', '/users/disable', [$make(UserController::class), 'disable']);
$router->add('GET', '/settings', [$make(SettingController::class), 'index'], false);
$router->add('POST', '/settings/save', [$make(SettingController::class), 'save']);
$router->add('GET', '/audit', [$make(AuditController::class), 'index'], false);

// Dispatch the current HTTP request after all routes are registered.
$router->dispatch(new Request(), $csrf);

