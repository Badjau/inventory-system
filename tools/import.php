<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $records = Inventory\Workbook::records(dirname(__DIR__) . '/INVENTORY 2026.xlsx');
    $db = null;
    $locate = in_array('--location-apo', $argv, true);
    if ($locate) {
        $db = Inventory\Database::connect(inventory_config()['database']);
        $branch = (new Inventory\HrDirectory($db))->branchNamed('APO');
        foreach ($records as &$record) {
            $record['location_branch_id'] = $branch['id'];
            $record['location'] = $branch['name'];
        }
        unset($record);
        echo json_encode(['location' => $branch], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    echo json_encode(Inventory\Workbook::summary($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (in_array('--apply', $argv, true)) {
        $db ??= Inventory\Database::connect(inventory_config()['database']);
        $departmentNames = Inventory\DepartmentNames::configured((new Inventory\HrDirectory($db))->departments());
        foreach ($records as &$record) {
            if (!$record['department']) continue;
            $department = $departmentNames->required($record['department']);
            $record['department'] = $department['name'];
            $record['department_id'] = $department['id'];
        }
        unset($record);
        $service = new Inventory\InventoryService($db);
        echo json_encode($service->import($records), JSON_THROW_ON_ERROR) . PHP_EOL;
        if ($locate) echo json_encode($service->locateImported($records), JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo "Preview only. Use --apply after tools/setup.php to import.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
