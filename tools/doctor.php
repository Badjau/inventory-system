<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    echo 'PHP ' . PHP_VERSION . PHP_EOL;
    foreach (['pdo_mysql', 'zip', 'simplexml', 'mbstring'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException("Missing PHP extension: $extension");
        }
    }
    $config = inventory_config();
    $db = Inventory\Database::connect($config['database']);
    echo 'Connected to ' . $config['database']['name'] . PHP_EOL;
    foreach (['employees', 'departments', 'branches', 'branch_employee'] as $table) {
        $columns = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll();
        echo $table . ': ' . implode(', ', array_column($columns, 'Field')) . PHP_EOL;
    }
    $hr = new Inventory\HrDirectory($db);
    echo 'Attendance users authentication columns are readable. ' .
        ($hr->hasInventoryEditor() ? 'An admin/IT admin can sign in.' : 'Create an admin/IT admin in the attendance dashboard.') . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Setup check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
