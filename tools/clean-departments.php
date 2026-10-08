<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $reference = null;
    $apply = false;
    $reconcile = false;
    $output = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--hr-list=')) $reference = substr($argument, 10);
        elseif (str_starts_with($argument, '--output=')) $output = substr($argument, 9);
        elseif ($argument === '--apply') $apply = true;
        elseif ($argument === '--reconcile-inventory') $reconcile = true;
        else throw new RuntimeException('Usage: php tools/clean-departments.php [--hr-list=PATH] [--output=PATH] [--apply] [--reconcile-inventory]');
    }
    $db = null;
    if ($reference) {
        $list = json_decode(file_get_contents($reference), true, 512, JSON_THROW_ON_ERROR);
        $departments = array_map(static fn ($entry): array => is_string($entry) ? ['name' => $entry] : $entry, $list);
    } else {
        $db = Inventory\Database::connect(inventory_config()['database']);
        $departments = (new Inventory\HrDirectory($db))->departments();
    }
    $names = Inventory\DepartmentNames::configured($departments);
    $path = dirname(__DIR__) . '/INVENTORY 2026.xlsx';
    $plan = Inventory\WorkbookDepartmentCleanup::plan($path, $names);
    $mapping = [];
    foreach ($plan['changes'] as $change) {
        $key = $change['from'];
        $mapping[$key] ??= ['to' => $change['to'], 'cells' => 0];
        $mapping[$key]['cells']++;
    }
    echo json_encode(['changed_cells' => count($plan['changes']), 'mapping' => $mapping, 'unresolved' => $plan['unresolved']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if ($plan['unresolved']) throw new RuntimeException('Complete data/department-aliases.json with confirmed HR department names before applying.');
    if ($apply) {
        $backup = Inventory\WorkbookDepartmentCleanup::apply($path, $plan, $output);
        echo json_encode(['changed_cells' => count($plan['changes']), 'output' => $output ?? $path, 'original' => $backup], JSON_THROW_ON_ERROR) . PHP_EOL;
        if ($reconcile) {
            $db ??= Inventory\Database::connect(inventory_config()['database']);
            $liveNames = Inventory\DepartmentNames::configured((new Inventory\HrDirectory($db))->departments());
            echo json_encode((new Inventory\InventoryService($db))->normalizeDepartments($liveNames), JSON_THROW_ON_ERROR) . PHP_EOL;
        }
    } else {
        echo "Preview only. Use --apply to clean Excel; add --reconcile-inventory to standardize existing inventory departments.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Department cleanup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
