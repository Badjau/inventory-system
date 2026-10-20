<?php
declare(strict_types=1);

$disposalInput = ['device_type' => 'Desktop', 'name' => 'Recovery fixture', 'device_condition' => 'Faulty',
    'serial_number' => 'retain-me', 'specifications' => ['RAM' => '16GB DDR4', 'SSD' => '512GB', 'Long' => str_repeat('x', 255)]];
$disposalService = new Inventory\InventoryService($db, ['id' => 901, 'name' => 'Recovery Admin', 'username' => 'recovery']);
$original = $disposalService->save($disposalInput);
$payload = ['id' => $original['id'], 'version' => 1, 'disposal_date' => '2026-10-19'];
foreach ([['RAM', 'RAM'], ['missing'], ['Long']] as $invalid) {
    rejects(fn () => $disposalService->disposal([...$payload, 'specification_names' => $invalid]));
}
rejects(fn () => $disposalService->disposal([...$payload, 'version' => 99]), 409);
check($disposalService->device($original['id']) === $original, 'Rejected disposal preserves original');
$result = $disposalService->disposal([...$payload, 'specification_names' => ['RAM', 'SSD']]);
check(count($result['recovered_device_ids']) === 2, 'Multiple components recovered');
$disposed = $result['device'];
check($disposed['lifecycle_status'] === 'for_disposal' && $disposed['specifications'] === $original['specifications'] && $disposed['serial_number'] === 'retain-me', 'Original identity and specifications preserved');
foreach ($result['recovered_device_ids'] as $index => $componentId) {
    $component = $disposalService->device($componentId);
    $name = ['RAM', 'SSD'][$index];
    check($component['name'] === $name . ': ' . $original['specifications'][$name], 'Exact generated name');
    check($component['device_type'] === 'Other' && $component['device_condition'] === 'Unknown' && $component['holder_kind'] === 'unassigned'
        && $component['handover_date'] === null && $component['serial_number'] === null && $component['asset_tag'] === null && $component['source'] === null
        && $component['import_key'] === null && $component['lifecycle_status'] === 'active', 'Recovered defaults');
    $event = $disposalService->history($componentId)[0];
    check($event['recovery']['original_device_id'] === $original['id'] && $event['recovery']['recovered_device_ids'] === $result['recovered_device_ids'] && $event['actor']['id'] === 901, 'Reciprocal attributed recovery history');
}
rejects(fn () => $disposalService->disposal($payload), 409);
rejects(fn () => $disposalService->save([...$disposalInput, 'id' => $disposed['id'], 'version' => $disposed['version']]), 409);
rejects(fn () => $disposalService->assign(['id' => $disposed['id'], 'version' => $disposed['version'], 'holder_kind' => 'unassigned'], $hr), 409);
$disposalService->note(['id' => $disposed['id'], 'message' => 'Notes still allowed']);
$empty = $disposalService->save($disposalInput);
$zero = $disposalService->disposal([...$payload, 'id' => $empty['id']]);
check($zero['recovered_device_ids'] === [], 'Zero recovery disposal supported');
$exchangePath = tempnam(__DIR__, 'disposal-export-');
if ($exchangePath === false) throw new RuntimeException('Cannot create disposal export fixture.');
try {
    $active = $disposalService->save([...$disposalInput, 'specifications' => []]);
    Inventory\WorkbookExchange::write($exchangePath, [$active, $disposed]);
    $sheets = Inventory\Workbook::sheets($exchangePath);
    $sheet = $sheets['Devices'];
    $exchangeRecords = Inventory\WorkbookExchange::records($sheet);
    check($exchangeRecords[1]['lifecycle_status'] === 'for_disposal', 'Lifecycle exported and parsed');
    foreach ($exchangeRecords as &$record) {
        $record['target_version'] = $disposalService->device($record['target_id'])['version'];
        $record['approved_employee_id'] = null;
    }
    unset($record);
    $exchangeRecords[0]['name'] = 'Must roll back';
    rejects(fn () => $disposalService->applyImport($exchangeRecords, $hr), 409);
    check($disposalService->device($active['id']) === $active, 'Disposed import target rolls back earlier active update');
    $exchangeRecords[0]['lifecycle_status'] = 'for_disposal';
    $disposalService->applyImport([$exchangeRecords[0]], $hr);
    check($disposalService->device($active['id'])['lifecycle_status'] === 'active', 'Import cannot set lifecycle');
    $lifecycleColumn = array_search('Lifecycle status', $sheet[1], true);
    foreach ($sheet as &$cells) unset($cells[$lifecycleColumn]);
    unset($cells);
    check(Inventory\WorkbookExchange::records($sheet)[0]['lifecycle_status'] === null, 'Older exports without lifecycle supported');
} finally { unlink($exchangePath); }
if (!$mysql) {
    foreach (['insert' => "BEFORE INSERT ON inv_devices WHEN NEW.device_type = 'Other'", 'history' => "BEFORE INSERT ON inv_events WHEN NEW.event_type = 'recovery'"] as $failure => $trigger) {
        $candidate = $disposalService->save($disposalInput);
        $countBefore = count($disposalService->devices());
        $db->exec("CREATE TRIGGER fail_recovery $trigger BEGIN SELECT RAISE(ABORT, 'Injected failure'); END");
        try {
            $disposalService->disposal([...$payload, 'id' => $candidate['id'], 'specification_names' => ['RAM']]);
            throw new RuntimeException('Expected injected failure');
        } catch (PDOException) {
            check($disposalService->device($candidate['id']) === $candidate && count($disposalService->devices()) === $countBefore && count($disposalService->history($candidate['id'])) === 1, "$failure failure rolls back all recovery writes");
        } finally { $db->exec('DROP TRIGGER fail_recovery'); }
    }
}
