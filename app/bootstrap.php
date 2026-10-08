<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Inventory\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

function inventory_config(): array
{
    $path = dirname(__DIR__) . '/config.local.php';
    if (!is_file($path)) {
        throw new RuntimeException('Create config.local.php from config.example.php before running the app.');
    }
    return require $path;
}
