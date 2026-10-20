<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $db = Inventory\Database::connect(inventory_config()['database']);
    foreach (explode(';', file_get_contents(dirname(__DIR__) . '/database/schema.sql')) as $statement) {
        if (trim($statement) !== '') {
            $db->exec($statement);
        }
    }
    // Upgrade existing inventory tables without touching HR or replacing devices.
    $columns = $db->query('SHOW COLUMNS FROM inv_devices')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('lifecycle_status', $columns, true)) {
        $db->exec("ALTER TABLE inv_devices ADD COLUMN lifecycle_status VARCHAR(20) NOT NULL DEFAULT 'active'");
    }
    if (!in_array('associate_id', $columns, true)) {
        $db->exec('ALTER TABLE inv_devices ADD COLUMN associate_id BIGINT UNSIGNED NULL AFTER hr_employee_id');
    }
    if (!in_array('location_branch_id', $columns, true)) {
        $db->exec('ALTER TABLE inv_devices ADD COLUMN location_branch_id BIGINT UNSIGNED NULL AFTER location');
    }
    if (!in_array('handover_date', $columns, true)) {
        $db->exec('ALTER TABLE inv_devices ADD COLUMN handover_date DATE NULL AFTER job_title');
    }
    if (!in_array('department_id', $columns, true)) {
        $db->exec('ALTER TABLE inv_devices ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER department');
    }
    echo "Inventory tables are ready. HR tables were not changed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Setup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
