<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Inventory\Database;
use Inventory\HrDirectory;
use Inventory\Http;
use Inventory\InventoryService;
use Inventory\ValidationException;

try {
    $localConfig = dirname(__DIR__) . '/config.local.php';
    Http::start(is_file($localConfig) ? require $localConfig : ['local_only' => true]);
    $action = $_GET['action'] ?? 'state';
    $method = $_SERVER['REQUEST_METHOD'];
    $getActions = ['state', 'history'];
    $postActions = ['device', 'assignment', 'note'];
    if (!is_string($action) || !in_array($action, [...$getActions, ...$postActions], true)) {
        throw new ValidationException('Endpoint not found.', 404);
    }
    $expected = in_array($action, $getActions, true) ? 'GET' : 'POST';
    if ($method !== $expected) {
        header('Allow: ' . $expected);
        throw new ValidationException('Method not allowed.', 405);
    }
    Inventory\Auth::authorize(Inventory\Auth::current(), $method === 'POST');
    $input = $method === 'POST' ? Http::input() : [];
    $db = Database::connect(inventory_config()['database']);
    $service = new InventoryService($db);
    $hr = new HrDirectory($db);
    $departments = $hr->departments();
    $departmentNames = Inventory\DepartmentNames::configured($departments);
    $data = match ($action) {
        'state' => ['devices' => array_map($departmentNames->display(...), $service->devices()),
            'employees' => $hr->all(), 'departments' => $departments, 'branches' => $hr->branches(), 'activity' => $service->history()],
        'history' => $service->history(InventoryService::positiveInt($_GET['id'] ?? null, 'device ID')),
        'device' => $service->save($input),
        'assignment' => $service->assign($input, $hr),
        'note' => (static function () use ($service, $input): array { $service->note($input); return ['saved' => true]; })(),
    };
    Http::json(['data' => $data]);
} catch (ValidationException $error) {
    Http::json(['error' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('Inventory: ' . $error->getMessage());
    Http::json(['error' => 'Inventory is unavailable. Check the MySQL connection and complete the setup/import commands in README.md.'], 503);
}
