<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AllocationController;
use App\Controllers\AuthController;
use App\Controllers\BillController;
use App\Controllers\DashboardController;
use App\Controllers\OccurrenceController;
use App\Controllers\SettingsController;
use App\Router;

$router = new Router();

$auth = new AuthController();
$dashboard = new DashboardController();
$bills = new BillController();
$occurrences = new OccurrenceController();
$allocations = new AllocationController();
$settings = new SettingsController();

$router->get('/', static function (): void {
    redirect('/dashboard');
});

$router->get('/login', [$auth, 'loginForm']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);

$router->get('/dashboard', [$dashboard, 'index']);
$router->post('/paychecks/amount', [$dashboard, 'updateAmount']);

$router->get('/bills', [$bills, 'index']);
$router->get('/bills/create', [$bills, 'createForm']);
$router->post('/bills/create', [$bills, 'create']);
$router->get('/bills/edit', [$bills, 'editForm']);
$router->post('/bills/edit', [$bills, 'update']);
$router->post('/bills/toggle', [$bills, 'toggleActive']);
$router->post('/bills/delete', [$bills, 'delete']);

$router->post('/occurrences/paid', [$occurrences, 'setPaid']);
$router->post('/occurrences/amount', [$occurrences, 'updateAmount']);
$router->post('/occurrences/skip', [$occurrences, 'skip']);

$router->get('/allocations/edit', [$allocations, 'editForm']);
$router->post('/allocations/edit', [$allocations, 'save']);

$router->get('/settings', [$settings, 'form']);
$router->post('/settings', [$settings, 'save']);

$router->dispatch();
