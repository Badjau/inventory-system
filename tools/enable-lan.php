<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
    $hr = new Inventory\HrDirectory(Inventory\Database::connect(inventory_config()['database']));
    if (!$hr->hasInventoryEditor()) {
        throw new RuntimeException('Create an admin or IT admin in the attendance dashboard before enabling LAN access.');
    }
    $path = dirname(__DIR__) . '/config.local.php';
    if (!is_file($path)) throw new RuntimeException('Configure the database first using README.md.');
    $config = file_get_contents($path);
    $updated = preg_replace("/(['\"])local_only\\1\\s*=>\\s*(?:true|false)/", "'local_only' => false", $config, -1, $count);
    if ($count !== 1) throw new RuntimeException('Expected one local_only setting in config.local.php; update it manually after creating an account.');
    if (file_put_contents($path, $updated, LOCK_EX) === false) throw new RuntimeException('Cannot update local configuration.');
    echo "LAN access enabled. Inventory login is required.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
