<?php

declare(strict_types=1);

$src = is_dir(__DIR__ . '/src') ? __DIR__ . '/src' : dirname(__DIR__) . '/src';
require_once $src . '/helpers.php';

$path = request_path();

if ($path !== '/' && $path !== '/index.html' && $path !== '/index.php') {
    if (serve_spa_file($path)) {
        exit;
    }
}

if (str_starts_with($path, '/api')) {
    require_once $src . '/bootstrap.php';
    cors();

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        Response::error('Sunucuda PDO SQLite kapalı. Plesk > PHP Settings içinde pdo_sqlite ve sqlite3 açın.', 500);
    }

    try {
        Database::connection();
    } catch (Throwable $e) {
        Response::error($e->getMessage(), 500);
    }

    $router = new Router();

    $router->add('GET', '/api/health', static function (): void {
        Response::json(['ok' => true, 'service' => 'remedolt-ticket-panel']);
    });

    $router->add('POST', '/api/auth/login', static function (): void {
        $body = json_input();
        $email = (string) ($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');
        if ($email === '' || $password === '') {
            Response::error('E-posta ve şifre gerekli.');
        }
        Response::json(Auth::login($email, $password));
    });

    $router->add('POST', '/api/auth/logout', static function (): void {
        Auth::logout(Auth::bearerToken());
        Response::json(['ok' => true]);
    });

    $router->add('GET', '/api/auth/me', static function (): void {
        Response::json(['user' => Auth::requireUser()]);
    });

    $router->add('PUT', '/api/profile', [ProfileController::class, 'update']);
    $router->add('GET', '/api/notifications', [NotificationController::class, 'index']);
    $router->add('GET', '/api/search', [SearchController::class, 'index']);
    $router->add('GET', '/api/reports', [ReportController::class, 'index']);

    $router->add('GET', '/api/dashboard', [DashboardController::class, 'stats']);
    $router->add('GET', '/api/tickets/export', [TicketController::class, 'export']);
    $router->add('POST', '/api/tickets/bulk', [TicketController::class, 'bulk']);
    $router->add('GET', '/api/tickets', [TicketController::class, 'index']);
    $router->add('GET', '/api/tickets/{id}', [TicketController::class, 'show']);
    $router->add('POST', '/api/tickets', [TicketController::class, 'store']);
    $router->add('POST', '/api/tickets/{id}/duplicate', [TicketController::class, 'duplicate']);
    $router->add('PUT', '/api/tickets/{id}', [TicketController::class, 'update']);
    $router->add('DELETE', '/api/tickets/{id}', [TicketController::class, 'destroy']);
    $router->add('POST', '/api/tickets/{id}/comments', [TicketController::class, 'comment']);

    $router->add('GET', '/api/work-orders', [WorkOrderController::class, 'index']);
    $router->add('POST', '/api/work-orders', [WorkOrderController::class, 'store']);
    $router->add('PUT', '/api/work-orders/{id}', [WorkOrderController::class, 'update']);

    $router->add('GET', '/api/users', [UserController::class, 'index']);
    $router->add('GET', '/api/users/staff', [UserController::class, 'staff']);
    $router->add('POST', '/api/users', [UserController::class, 'store']);
    $router->add('PUT', '/api/users/{id}', [UserController::class, 'update']);

    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
}

serve_spa();
