<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--date=')) {
            $date = substr($argument, 7);
        } elseif ($argument !== '--apply') {
            throw new RuntimeException('Usage: php tools/set-equipment-baseline.php [--date=YYYY-MM-DD] [--apply]');
        }
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || (int) $parsed->format('Y') < 1000) {
        throw new RuntimeException('Use a valid handover date in YYYY-MM-DD format.');
    }
    $service = new Inventory\InventoryService(Inventory\Database::connect(inventory_config()['database']));
    if (in_array('--apply', $argv, true)) {
        echo json_encode($service->setEquipmentBaseline($date), JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        $devices = $service->devices();
        $changes = array_filter($devices, static fn (array $device): bool => $device['device_condition'] !== 'Good' || $device['handover_date'] !== $date);
        echo json_encode(['total' => count($devices), 'to_update' => count($changes), 'condition' => 'Good', 'handover_date' => $date], JSON_THROW_ON_ERROR) . PHP_EOL;
        echo "Preview only. Run tools/setup.php, then use --apply to update existing equipment.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Equipment update failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
