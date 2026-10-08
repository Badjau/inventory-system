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
    $getActions = ['state', 'history', 'export'];
    $postActions = ['device', 'assignment', 'note', 'import-preview', 'import-apply'];
    if (!is_string($action) || !in_array($action, [...$getActions, ...$postActions], true)) {
        throw new ValidationException('Endpoint not found.', 404);
    }
    $expected = in_array($action, $getActions, true) ? 'GET' : 'POST';
    if ($method !== $expected) {
        header('Allow: ' . $expected);
        throw new ValidationException('Method not allowed.', 405);
    }
    Inventory\Auth::authorize(Inventory\Auth::current(), $method === 'POST');
    $input = $method === 'POST' && $action !== 'import-preview' ? Http::input() : [];
    $db = Database::connect(inventory_config()['database']);
    $service = new InventoryService($db);
    $hr = new HrDirectory($db);
    $departments = $hr->departments();
    $departmentNames = Inventory\DepartmentNames::configured($departments);
    if ($action === 'export') {
        $path = tempnam(dirname(__DIR__) . '/storage', 'inv-export-');
        if ($path === false) throw new RuntimeException('Cannot allocate export file.');
        try {
            $employees = array_column($hr->all(), null, 'id');
            $devices = array_map($departmentNames->display(...), $service->devices());
            foreach ($devices as &$device) {
                $employee = $employees[$device['hr_employee_id']] ?? null;
                if ($device['holder_kind'] === 'employee' && $employee) {
                    foreach (['department', 'department_id', 'branch', 'job_title'] as $field) $device[$field] = $employee[$field];
                    $device['holder_name'] = $employee['name'];
                }
            }
            unset($device);
            Inventory\WorkbookExchange::write($path, $devices);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="inventory-' . date('Y-m-d') . '.xlsx"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } finally { unlink($path); }
        exit;
    }
    if ($action === 'import-preview') {
        Http::verifyToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', $_SESSION['csrf'] ?? '');
        unset($_SESSION['import_preview']);
        $file = $_FILES['workbook'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new ValidationException('Upload an XLSX workbook within the PHP upload limit.');
        if (!is_string($file['name']) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx'
            || $file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) throw new ValidationException('Upload an XLSX workbook no larger than 5 MiB.');
        $preview = Inventory\ImportPreview::create(Inventory\Workbook::records($file['tmp_name']), $service->devices(), $hr->all());
        $_SESSION['import_preview'] = $preview;
        Http::json(['data' => $preview]);
    }
    if ($action === 'import-apply') {
        if (!isset($_SESSION['import_preview'])) throw new ValidationException('Upload a workbook to create a preview first.', 409);
        $records = Inventory\ImportPreview::selections($_SESSION['import_preview'], $input);
        $result = $service->applyImport($records, $hr);
        unset($_SESSION['import_preview']);
        Http::json(['data' => $result]);
    }
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
