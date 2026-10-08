<?php
declare(strict_types=1);

function associateTests(PDO $db, Inventory\InventoryService $service, Inventory\HrDirectory $hr): void
{
    $directory = new Inventory\Associates($db);
    $employees = $hr->all();
    rejects(static fn () => $directory->add(['name' => '   ']));
    rejects(static fn () => $directory->add(['name' => str_repeat('x', 256)]));
    $person = $directory->add(['name' => 'Alex Santos', 'job_title' => 'Associate']);
    check($hr->all() === $employees, 'Creating an associate leaves HR employees unchanged');
    check(count($directory->all()) === 1, 'Associate persists in its inventory directory');
    $device = $service->save(['name' => 'Associate test device', 'device_type' => 'Other', 'device_condition' => 'Good']);
    $assigned = $service->assign(['id' => $device['id'], 'version' => $device['version'],
        'holder_kind' => 'associate', 'associate_id' => $person['id']], $hr);
    check((int) $assigned['associate_id'] === (int) $person['id'] && $assigned['hr_employee_id'] === null,
        'Associate assignment cannot collide with an HR ID');
    check($assigned['holder_name'] === 'Alex Santos', 'Associate assignment records a holder snapshot');
    rejects(static fn () => $service->assign(['id' => $device['id'], 'version' => $assigned['version'],
        'holder_kind' => 'associate', 'associate_id' => 999999], $hr), 404);
    rejects(static fn () => $service->assign(['id' => $device['id'], 'version' => $device['version'],
        'holder_kind' => 'associate', 'associate_id' => $person['id']], $hr), 409);
    $path = tempnam(__DIR__, 'associate-roundtrip-');
    try {
        Inventory\WorkbookExchange::write($path, [$assigned]);
        $preview = Inventory\ImportPreview::create(Inventory\Workbook::records($path), $service->devices(), $employees);
        check($preview['groups'][0]['hr_employee_id'] === null, 'Associate export never suggests a same-name HR employee');
        $records = Inventory\ImportPreview::selections($preview, ['token' => $preview['token'],
            'groups' => [['id' => 1, 'hr_employee_id' => null]]]);
        $service->applyImport($records, $hr);
        $assigned = $service->device($device['id']);
        check($assigned['holder_kind'] === 'associate' && (int) $assigned['associate_id'] === (int) $person['id'],
            'Excel roundtrip retains the associate link');
    } finally { unlink($path); }
    $returned = $service->assign(['id' => $device['id'], 'version' => $assigned['version'], 'holder_kind' => 'unassigned'], $hr);
    check($returned['associate_id'] === null && $returned['hr_employee_id'] === null, 'Return clears both holder links');
    $history = $service->history($device['id']);
    check(str_contains($history[0]['message'], 'Alex Santos'), 'Return history retains the associate name');
    check($hr->all() === $employees, 'Associate lifecycle never changes attendance employees');
}
