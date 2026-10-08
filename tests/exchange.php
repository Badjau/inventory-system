<?php
declare(strict_types=1);

use Inventory\ImportPreview;
use Inventory\WorkbookExchange;

function exchangeTests(PDO $db, Inventory\InventoryService $service, Inventory\HrDirectory $hr, array $records, bool $mysql, string $workbookPath): void
{
    $sheets = Inventory\Workbook::sheets($workbookPath);
    foreach ($records as $record) {
        if (!in_array($record['device_type'], ['Desktop', 'Laptop'], true)) continue;
        $sheet = $record['source']['sheet'];
        $cells = $record['source']['cells'];
        foreach ($sheets[$sheet][1] as $column => $header) {
            if (($sheet === 'PC' && in_array($column, ['S', 'T', 'X', 'Y', 'Z', 'AA', 'AR'], true))
                || ($sheet === 'Laptop' && in_array($column, ['K', 'L', 'M', 'N', 'O', 'P', 'AB'], true))) {
                $key = $header === 'OR NUMBER' ? "$header ($column)" : $header;
                check(($record['specifications'][$key] ?? null) === ($cells[$column] ?? ''), "Full software column $sheet $column preserved");
            }
        }
        check($record['specifications']['OS'] === ($cells[$sheet === 'PC' ? 'W' : 'J'] ?? ''), 'OS uses the original mapped column');
    }
    $keyboard = array_values(array_filter($records, static fn ($r) => $r['device_type'] === 'Keyboard'))[0];
    check($keyboard['specifications'] === [], 'PC software is not copied onto accessories');
    $fixture = tempnam(__DIR__, 'inventory-columns-');
    if ($fixture === false) throw new RuntimeException('Cannot create column test fixture.');
    try {
        copy($workbookPath, $fixture);
        $zip = new ZipArchive(); $zip->open($fixture);
        foreach (['xl/worksheets/sheet2.xml' => ['AS1' => 'Future software', 'AS2' => 'Installed'],
            'xl/worksheets/sheet3.xml' => ['Q2' => 'Unnamed data', 'Z2' => 'Receipt one', 'AA2' => 'Receipt two', 'AB2' => 'Receipt three']] as $entry => $values) {
            $xml = simplexml_load_string($zip->getFromName($entry));
            $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($values as $reference => $value) {
                foreach ($xml->xpath('//s:c[@r="' . $reference . '"]') as $old) { $dom = dom_import_simplexml($old); $dom->parentNode->removeChild($dom); }
                $row = $xml->xpath('//s:row[@r="' . preg_replace('/\D/', '', $reference) . '"]')[0];
                $cell = $row->addChild('c'); $cell->addAttribute('r', $reference); $cell->addAttribute('t', 'inlineStr');
                $cell->addChild('is')->addChild('t', $value);
            }
            $zip->addFromString($entry, $xml->asXML());
        }
        $zip->close();
        $extended = Inventory\Workbook::records($fixture);
        $desktop = array_values(array_filter($extended, static fn ($r) => $r['device_type'] === 'Desktop'))[0];
        $laptop = array_values(array_filter($extended, static fn ($r) => $r['device_type'] === 'Laptop'))[0];
        check($desktop['specifications']['Future software'] === 'Installed', 'New specification columns need no mapping changes');
        check($laptop['specifications']['Column Q'] === 'Unnamed data', 'Populated unnamed columns preserved');
        check($laptop['specifications']['OR NUMBER (Z)'] === 'Receipt one' && $laptop['specifications']['OR NUMBER (AA)'] === 'Receipt two'
            && $laptop['specifications']['OR NUMBER (AB)'] === 'Receipt three', 'Repeated headers retain each distinct value');
    } finally { unlink($fixture); }
    $base = array_values(array_filter($records, static fn ($r) => $r['device_type'] === 'Desktop'))[0];
    $first = [...$base, 'import_key' => 'exchange-test:a', 'holder_name' => ' Alex  Santos ', 'department' => 'Accounting',
        'specifications' => ['OS' => 'Windows', 'LICENSE' => 'license', 'MICROSOFT 365' => 'Yes', 'SAP' => 'Yes']];
    $second = [...$first, 'import_key' => 'exchange-test:b', 'device_type' => 'Keyboard', 'specifications' => []];
    $unmatched = [...$first, 'import_key' => 'exchange-test:c', 'holder_name' => 'Unknown Holder'];
    $preview = ImportPreview::create([$first, $second, $unmatched], $service->devices(), $hr->all());
    check(count($preview['groups']) === 2 && count($preview['groups'][0]['records']) === 2, 'Devices group once per employee');
    check($preview['groups'][0]['hr_employee_id'] === 1 && $preview['groups'][1]['hr_employee_id'] === null, 'Unique exact normalized matches only; unmatched blank');
    $ambiguous = ImportPreview::create([$first], [], [...$hr->all(), ['id' => 9999, 'name' => 'Alex Santos']]);
    check($ambiguous['groups'][0]['hr_employee_id'] === null, 'Ambiguous names never infer employee IDs');
    $fuzzy = ImportPreview::create([[...$first, 'holder_name' => 'Alex S.']], [], $hr->all());
    check($fuzzy['groups'][0]['hr_employee_id'] === null, 'Partial names are not fuzzy matched');
    $fakeDevices = [[...$first, 'id' => 10001, 'version' => 1, 'hr_employee_id' => 1], [...$second, 'id' => 10002, 'version' => 1, 'hr_employee_id' => 2]];
    $conflict = ImportPreview::create([$first, $second], $fakeDevices, $hr->all());
    check($conflict['groups'][0]['conflict'] && $conflict['groups'][0]['hr_employee_id'] === null, 'Conflicting existing links require review');
    rejects(static fn () => ImportPreview::selections($conflict, ['token' => $conflict['token'], 'groups' => [['id' => 1, 'hr_employee_id' => null]]]));
    check(count(ImportPreview::selections($conflict, ['token' => $conflict['token'], 'groups' => [['id' => 1, 'hr_employee_id' => null, 'resolved' => true]]])) === 2, 'Conflicting groups allow explicit blank selection');
    $input = ['token' => $preview['token'], 'groups' => [['id' => $preview['groups'][0]['id'], 'hr_employee_id' => 1]]];
    $count = count($service->devices());
    $selected = ImportPreview::selections($preview, $input);
    check($service->applyImport($selected, $hr) === ['added' => 2, 'updated' => 0], 'One approval imports a whole employee group');
    check(count($service->devices()) === $count + 2, 'Unselected groups are not imported');
    rejects(static fn () => $service->applyImport($selected, $hr), 409);
    $targets = array_values(array_filter($service->devices(), static fn ($d) => in_array($d['import_key'], ['exchange-test:a', 'exchange-test:b'], true)));
    $originalSource = $targets[0]['source'];
    $first['specifications'] = ['OS' => 'Linux', 'LICENSE' => '', 'VIBER' => 'Installed'];
    $preview = ImportPreview::create([$first, $second], $service->devices(), $hr->all());
    $selected = ImportPreview::selections($preview, ['token' => $preview['token'], 'groups' => [['id' => 1, 'hr_employee_id' => 1]]]);
    check($service->applyImport($selected, $hr) === ['added' => 0, 'updated' => 2], 'Reimport updates by source key, retaining repeated identifiers');
    $updated = $service->device($targets[0]['id']);
    check($updated['source'] === $originalSource && $updated['hr_employee_id'] === 1 && $updated['specifications'] === ['OS' => 'Linux', 'VIBER' => 'Installed'], 'Overwrite clears blank specifications and preserves source and IDs');
    $query = $db->prepare('SELECT snapshot_json FROM inv_events WHERE device_id = ? ORDER BY id DESC LIMIT 1');
    $query->execute([$updated['id']]);
    $snapshot = json_decode($query->fetchColumn(), true);
    check(isset($snapshot['incoming_source']) && $snapshot['before']['specifications']['OS'] === 'Windows', 'Import update records incoming source and before/after history');
    $preview = ImportPreview::create([$unmatched], $service->devices(), $hr->all());
    $service->applyImport(ImportPreview::selections($preview, ['token' => $preview['token'], 'groups' => [['id' => 1, 'hr_employee_id' => null]]]), $hr);
    $last = array_values(array_filter($service->devices(), static fn ($d) => $d['import_key'] === 'exchange-test:c'))[0];
    check($last['holder_kind'] === 'imported' && $last['holder_name'] === 'Unknown Holder' && $last['hr_employee_id'] === null, 'Unmatched approved names retain assigned snapshots');
    rejects(static fn () => ImportPreview::create([$first, $first], $service->devices(), $hr->all()));
    rejects(static fn () => ImportPreview::selections([...$preview, 'expires_at' => time() - 1], ['token' => $preview['token'], 'groups' => [['id' => 1, 'hr_employee_id' => null]]]), 409);
    rejects(static fn () => ImportPreview::selections($preview, ['token' => 'wrong', 'groups' => [['id' => 1, 'hr_employee_id' => null]]]), 403);
    rejects(static fn () => ImportPreview::selections($preview, ['token' => $preview['token'], 'groups' => [['id' => 9, 'hr_employee_id' => null]]]));
    rejects(static fn () => $service->applyImport([[...$selected[0], 'target_version' => $updated['version'], 'approved_employee_id' => 9999]], $hr));
    $preview = ImportPreview::create([$first, $second], $service->devices(), $hr->all());
    $selected = ImportPreview::selections($preview, ['token' => $preview['token'], 'groups' => [['id' => 1, 'hr_employee_id' => 1]]]);
    $stale = $service->device($targets[1]['id']);
    $service->save([...$stale, 'name' => 'Changed after preview']);
    $before = $service->devices();
    rejects(static fn () => $service->applyImport($selected, $hr), 409);
    check($service->devices() === $before, 'A stale device rolls back the entire approved group');
    $website = $service->save(['name' => '=literal formula', 'device_type' => 'Other', 'device_condition' => 'Good',
        'serial_number' => '0000123', 'specifications' => ['Custom: field' => "line one\nline two", 'Long value' => str_repeat('x', 4000)]]);
    $path = tempnam(__DIR__, 'inventory-exchange-test-');
    if ($path === false) throw new RuntimeException('Cannot allocate workbook test file.');
    try {
        WorkbookExchange::write($path, [$updated, $last, $website]);
        $exported = Inventory\Workbook::records($path);
        $sheetRows = Inventory\Workbook::sheets($path)['Devices'];
        $missingHeaders = $sheetRows; unset($missingHeaders[1]['A']);
        rejects(static fn () => WorkbookExchange::records($missingHeaders));
        $duplicateHeaders = $sheetRows; $duplicateHeaders[1]['B'] = $duplicateHeaders[1]['A'];
        rejects(static fn () => WorkbookExchange::records($duplicateHeaders));
        $badId = $sheetRows; $badId[2]['A'] = '1 OR 1=1';
        rejects(static fn () => WorkbookExchange::records($badId));
        check($exported[2]['serial_number'] === '0000123' && $exported[2]['name'] === '=literal formula', 'XLSX preserves leading zeros and literal formula-like strings');
        check($exported[2]['specifications']['Custom: field'] === "line one\nline two", 'Export preserves multiline/custom specifications');
        $zip = new ZipArchive(); $zip->open($path);
        check(!str_contains($zip->getFromName('xl/worksheets/sheet1.xml'), '<f>'), 'Export never writes formulas');
        $zip->close();
        $preview = ImportPreview::create($exported, $service->devices(), $hr->all());
        $selections = array_map(static fn ($g) => ['id' => $g['id'], 'hr_employee_id' => $g['hr_employee_id']], $preview['groups']);
        check($service->applyImport(ImportPreview::selections($preview, ['token' => $preview['token'], 'groups' => $selections]), $hr)['updated'] === 3, 'Export is reimportable with stable inventory IDs');
        check($service->device($website['id'])['specifications'] === $website['specifications'], 'Export roundtrip retains custom specification values');
        rejects(static fn () => ImportPreview::create([[...$exported[0], 'import_key' => 'wrong']], $service->devices(), $hr->all()));
        rejects(static fn () => ImportPreview::create([[...$exported[0], 'target_id' => 999999]], $service->devices(), $hr->all()));
        $deleted = ImportPreview::create([[...$exported[1], 'hr_employee_id' => 99999, 'holder_kind' => 'employee']], $service->devices(), $hr->all());
        check($deleted['groups'][0]['hr_employee_id'] === null, 'Missing exported HR employee leaves the selection blank');
        $service->applyImport(ImportPreview::selections($deleted, ['token' => $deleted['token'], 'groups' => [['id' => 1, 'hr_employee_id' => null]]]), $hr);
        check($service->device($last['id'])['holder_name'] === 'Unknown Holder', 'Deleted HR employee retains the recorded holder snapshot');
        $invalid = tempnam(__DIR__, 'inventory-invalid-');
        try {
            file_put_contents($invalid, 'not a zip');
            rejects(static fn () => Inventory\Workbook::records($invalid));
        } finally { unlink($invalid); }
    } finally { unlink($path); }
    rejects(static fn () => ImportPreview::create([], [], $hr->all()));
    rejects(static fn () => ImportPreview::create(array_fill(0, 2001, $first), [], $hr->all()));
    $large = tempnam(__DIR__, 'inventory-large-');
    if ($large === false) throw new RuntimeException('Cannot allocate ZIP test file.');
    try {
        $zip = new ZipArchive(); $zip->open($large, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('oversized.xml', str_repeat('x', 32 * 1024 * 1024 + 1)); $zip->close();
        rejects(static fn () => Inventory\Workbook::sheets($large));
        $zip->open($large, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        for ($i = 0; $i < 257; $i++) $zip->addFromString('entry' . $i, 'x');
        $zip->close();
        rejects(static fn () => Inventory\Workbook::sheets($large));
    } finally { unlink($large); }
    $preview = ImportPreview::create([$first, $second], $service->devices(), $hr->all());
    $selected = ImportPreview::selections($preview, ['token' => $preview['token'], 'groups' => [['id' => 1, 'hr_employee_id' => 1]]]);
    $before = $service->devices();
    $db->exec($mysql ? "CREATE TRIGGER inv_exchange_fail BEFORE INSERT ON inv_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test failure'" : "CREATE TRIGGER inv_exchange_fail BEFORE INSERT ON inv_events BEGIN SELECT RAISE(FAIL, 'Test failure'); END;");
    try {
        try { $service->applyImport($selected, $hr); throw new RuntimeException('Expected history failure.'); }
        catch (PDOException) { check($service->devices() === $before, 'Import rolls back when event insertion fails'); }
    } finally { $db->exec('DROP TRIGGER inv_exchange_fail'); }
    rejects(static fn () => $service->save(['name' => 'Bad specs', 'device_type' => 'Other', 'device_condition' => 'Good', 'specifications' => ['Long' => str_repeat('x', 4001)]]));
    $many = array_fill_keys(array_map(static fn ($n) => 'Field ' . $n, range(1, 129)), 'value');
    rejects(static fn () => $service->save(['name' => 'Too many specs', 'device_type' => 'Other', 'device_condition' => 'Good', 'specifications' => $many]));
}
