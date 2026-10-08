<?php
declare(strict_types=1);

$presetDirectory = __DIR__ . '/preset_' . bin2hex(random_bytes(8));
mkdir($presetDirectory);
try {
    $preset = new Inventory\DevicePreset($presetDirectory . '/desktop.json');
    $defaults = $preset->read();
    check($defaults['version'] === 0 && count($defaults['fields']) === 30, 'Desktop preset starts with the requested fields');
    check(!is_file($presetDirectory . '/desktop.json'), 'Reading default preset does not write data');
    $fields = [['name' => 'Custom spec', 'value' => "Line one\nLine two"], ...array_reverse($defaults['fields'])];
    $saved = $preset->save(['version' => 0, 'fields' => $fields]);
    check($saved['version'] === 1 && $preset->read()['fields'] === $fields, 'Custom values and field order persist');
    rejects(static fn () => $preset->save(['version' => 0, 'fields' => []]), 409);
    check($preset->read() === $saved, 'Stale preset save leaves saved template intact');
    rejects(static fn () => $preset->save(['version' => 1, 'fields' => [['name' => 'RAM', 'value' => ''], ['name' => ' ram ', 'value' => '']]]));
    rejects(static fn () => Inventory\DevicePreset::validate([['name' => '', 'value' => 'x']]));
    rejects(static fn () => Inventory\DevicePreset::validate([['name' => 'RAM', 'value' => str_repeat('a', 4001)]]));
    rejects(static fn () => Inventory\DevicePreset::validate(array_fill(0, 129, ['name' => 'RAM', 'value' => ''])));
    check($preset->save(['version' => 1, 'fields' => []])['fields'] === [], 'All default fields can be removed');
    $beforeDevices = $service->devices();
    $custom = $preset->save(['version' => 2, 'name' => '  Router  ']);
    check(in_array('Router', $preset->read()['device_types'], true), 'New device types persist with trimmed names');
    check($custom['fields'] === [], 'Creating a type preserves Desktop defaults');
    check($service->devices() === $beforeDevices, 'Preset and type changes leave existing devices and specifications untouched');
    rejects(static fn () => $preset->save(['version' => 3, 'name' => 'router']));
    rejects(static fn () => $preset->save(['version' => 3, 'name' => ' desktop ']));
    rejects(static fn () => $preset->save(['version' => 2, 'name' => 'Scanner']), 409);
    rejects(static fn () => $preset->save(['version' => 3, 'name' => ' ']));
    rejects(static fn () => $preset->save(['version' => 3, 'name' => str_repeat('a', 31)]));
    rejects(static fn () => $preset->save(['version' => 3, 'name' => "Bad\nname"]));
    $updated = $preset->save(['version' => 3, 'fields' => $fields]);
    check(in_array('Router', $updated['device_types'], true), 'Saving Desktop defaults retains custom types');
    $customService = new Inventory\InventoryService($db, null, $updated['device_types']);
    $router = $customService->save(['device_type' => 'Router', 'name' => 'Test router', 'device_condition' => 'Good']);
    check($router['device_type'] === 'Router' && $router['specifications'] === [], 'Custom types create devices without default specifications');
    $sameRouter = $customService->save(['id' => $router['id'], 'version' => $router['version'], 'device_type' => 'Router', 'name' => 'Edited router', 'device_condition' => 'Good', 'specifications' => ['Ports' => '8']]);
    check($sameRouter['specifications'] === ['Ports' => '8'], 'Custom type devices support specification editing');
    $legacy = $presetDirectory . '/legacy.json';
    file_put_contents($legacy, json_encode(['version' => 4, 'fields' => $fields]));
    check((new Inventory\DevicePreset($legacy))->read()['device_types'] === Inventory\InventoryService::TYPES, 'Old preset files keep Desktop fields and acquire built-in types');
} finally {
    foreach (glob($presetDirectory . '/*') as $file) unlink($file);
    rmdir($presetDirectory);
}
