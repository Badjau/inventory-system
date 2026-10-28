<?php
declare(strict_types=1);

use Inventory\BulkPreview;
use Inventory\InventoryService;
use Inventory\HrDirectory;
use Inventory\ValidationException;

function bulkTests(PDO $db, HrDirectory $hr, bool $mysql): void
{
    $service = new InventoryService($db, ['id' => 700, 'name' => 'Bulk tester', 'username' => 'bulk']);
    $base = ['device_type' => 'Other', 'device_condition' => 'Unknown', 'serial_number' => 'repeat', 'asset_tag' => 'repeat'];
    $a = $service->save([...$base, 'name' => 'Bulk A', 'specifications' => ['MICROSOFT  365' => 'No', 'Keep' => 'Original', 'Remove me' => 'old']]);
    $source = $db->prepare('UPDATE inv_devices SET import_key = ?, source_json = ? WHERE id = ?');
    $source->execute(['bulk-fixture-original-row', json_encode(['sheet' => 'Fixture', 'row' => 5, 'cells' => ['Original source']], JSON_THROW_ON_ERROR), $a['id']]);
    $a = $service->device($a['id']);
    $b = $service->save([...$base, 'name' => 'Bulk B', 'specifications' => ['Keep' => 'Different']]);
    $ids = [$a['id'], $b['id']];
    $targets = static fn () => array_map(static fn ($id) => array_intersect_key($service->device($id), array_flip(['id', 'version'])), $ids);
    $input = static fn (array $extra = []) => ['mode' => 'details', 'devices' => $targets(), ...$extra];
    $plan = $service->previewBulk($input(['patch' => ['device_condition' => 'Good', 'location_branch_id' => 3], 'specifications' => [
        ['name' => 'Microsoft 365', 'action' => 'set', 'value' => 'Yes'],
        ['name' => 'Anti Virus', 'action' => 'set', 'value' => 'Yes'],
        ['name' => 'remove ME', 'action' => 'remove']
    ]]), $hr);
    check($service->device($a['id']) === $a, 'Bulk preview never changes records');
    $preview = BulkPreview::create($plan);
    $display = BulkPreview::display($preview);
    check($display['changed'] === 2 && !isset($display['rows'][0]['before'], $display['rows'][0]['values']), 'Public preview omits full snapshots and persistence values');
    check(BulkPreview::approved($preview, ['token' => $preview['token']]) === $plan, 'Approval uses session plan');
    rejects(static fn () => BulkPreview::approved($preview, ['token' => 'wrong']), 409);
    rejects(static fn () => BulkPreview::approved([...$preview, 'expires' => time() - 1], ['token' => $preview['token']]), 409);
    rejects(static fn () => BulkPreview::approved(null, ['token' => $preview['token']]), 409);
    $newPreview = BulkPreview::create($plan);
    rejects(static fn () => BulkPreview::approved($newPreview, ['token' => $preview['token']]), 409);
    check($service->applyBulk($plan, $hr) === ['changed' => 2, 'unchanged' => 0], 'Bulk applies multiple fields and specifications');
    $afterA = $service->device($a['id']);
    $afterB = $service->device($b['id']);
    check($afterA['specifications'] === ['MICROSOFT  365' => 'Yes', 'Keep' => 'Original', 'Anti Virus' => 'Yes'], 'Normalized specification match preserves original key and unrelated values');
    check($afterB['specifications'] === ['Keep' => 'Different', 'Microsoft 365' => 'Yes', 'Anti Virus' => 'Yes'], 'Missing specifications added to every target');
    check($afterA['location_branch_id'] === 3 && $afterA['location'] === 'APO' && $afterA['device_condition'] === 'Good', 'Bulk details use validated branch directory');
    foreach (['id', 'import_key', 'serial_number', 'asset_tag', 'source', 'holder_kind'] as $field) check($afterA[$field] === $a[$field], 'Bulk preserves ' . $field);
    check($service->history($a['id'])[0]['actor']['id'] === 700, 'Bulk history attributes the actor');
    check(str_contains($service->history($a['id'])[0]['message'], 'MICROSOFT  365: No'), 'Bulk history describes specification change');
    $noop = $service->previewBulk($input(['patch' => ['device_condition' => 'Good']]), $hr);
    $eventCount = count($service->history());
    check($service->applyBulk($noop, $hr) === ['changed' => 0, 'unchanged' => 2], 'No-op batch reports unchanged targets');
    check($service->device($a['id'])['version'] === $afterA['version'] && count($service->history()) === $eventCount, 'No-op adds no versions or events');
    $service->save([...$service->device($b['id']), 'name' => 'Concurrent unchanged target']);
    rejects(static fn () => $service->applyBulk($noop, $hr), 409);
    $mixed = $service->previewBulk($input(['specifications' => [['name' => 'Remove me', 'action' => 'remove']]]), $hr);
    check(BulkPreview::display(BulkPreview::create($mixed))['unchanged'] === 2, 'Removing missing specifications is a no-op');
    rejects(static fn () => $service->previewBulk($input(['patch' => ['name' => 'Wrong']]), $hr));
    rejects(static fn () => $service->previewBulk($input(['specifications' => [['name' => 'a', 'action' => 'set', 'value' => 'Yes'], ['name' => ' A ', 'action' => 'remove']]]), $hr));
    rejects(static fn () => $service->previewBulk($input(['specifications' => [['name' => 'a', 'action' => 'set', 'value' => ' ']]]), $hr));
    rejects(static fn () => $service->previewBulk($input(['patch' => ['location_branch_id' => 999999]]), $hr));
    rejects(static fn () => $service->previewBulk($input(['devices' => [$targets()[0], $targets()[0]], 'patch' => ['device_condition' => 'Good']]), $hr));
    $ambiguous = $service->save([...$base, 'name' => 'Ambiguous', 'specifications' => ['Microsoft 365' => 'No', 'MICROSOFT 365' => 'Yes']]);
    rejects(static fn () => $service->previewBulk(['mode' => 'details', 'devices' => [['id' => $ambiguous['id'], 'version' => $ambiguous['version']]], 'specifications' => [['name' => 'Microsoft 365', 'action' => 'set', 'value' => 'Yes']]], $hr));
    $full = $service->save([...$base, 'name' => 'Full specs', 'specifications' => array_fill_keys(array_map(static fn ($i) => 'Spec ' . $i, range(1, 128)), 'Yes')]);
    rejects(static fn () => $service->previewBulk(['mode' => 'details', 'devices' => [['id' => $full['id'], 'version' => $full['version']]], 'specifications' => [['name' => 'Extra', 'action' => 'set', 'value' => 'Yes']]], $hr));
    $stale = $service->previewBulk($input(['patch' => ['device_condition' => 'Faulty']]), $hr);
    $service->save([...$service->device($b['id']), 'name' => 'Concurrent name']);
    $beforeFailure = $service->device($a['id']);
    try { $service->applyBulk($stale, $hr); throw new RuntimeException('Expected structured conflict'); }
    catch (ValidationException $error) { check($error->status === 409 && $error->conflicts === [$b['id']], 'Stale target returns structured conflict IDs'); }
    check($service->device($a['id']) === $beforeFailure, 'A stale target prevents every write');
    $rollback = $service->previewBulk($input(['patch' => ['device_condition' => 'Faulty']]), $hr);
    $beforeRollback = array_map($service->device(...), $ids);
    $eventsBeforeRollback = count($service->history());
    $failId = $b['id'];
    $db->exec($mysql
        ? "CREATE TRIGGER inv_bulk_fail BEFORE INSERT ON inv_events FOR EACH ROW BEGIN IF NEW.device_id = $failId THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bulk test failure'; END IF; END"
        : "CREATE TRIGGER inv_bulk_fail BEFORE INSERT ON inv_events WHEN NEW.device_id = $failId BEGIN SELECT RAISE(ABORT, 'bulk test failure'); END");
    try {
        try { $service->applyBulk($rollback, $hr); throw new RuntimeException('Expected history failure'); }
        catch (PDOException) {
            check(array_map($service->device(...), $ids) === $beforeRollback, 'History failure rolls back all device changes');
            check(count($service->history()) === $eventsBeforeRollback, 'History failure rolls back earlier batch events');
        }
    } finally { $db->exec('DROP TRIGGER inv_bulk_fail'); }
    $assignment = static fn (array $assign) => $service->previewBulk(['mode' => 'assignment', 'devices' => $targets(), 'assignment' => $assign], $hr);
    foreach ([['holder_kind' => 'employee', 'hr_employee_id' => 1], ['holder_kind' => 'shared', 'department_id' => 1]] as $assign) {
        $service->applyBulk($assignment([...$assign, 'handover_date' => '2026-10-28', 'notes' => 'Batch handover']), $hr);
        foreach ($ids as $id) check($service->device($id)['holder_kind'] === $assign['holder_kind'], 'Bulk assignment supports ' . $assign['holder_kind']);
    }
    $associate = (new Inventory\Associates($db))->add(['name' => 'Bulk associate']);
    $service->applyBulk($assignment(['holder_kind' => 'associate', 'associate_id' => $associate['id']]), $hr);
    check($service->device($a['id'])['associate_id'] === $associate['id'], 'Bulk associate uses separate numeric identity');
    $service->applyBulk($assignment(['holder_kind' => 'unassigned']), $hr);
    check($service->device($a['id'])['holder_kind'] === 'unassigned' && $service->device($a['id'])['handover_date'] === null, 'Bulk return clears holder and handover date');
    check($service->history($a['id'])[0]['handover']['from']['name'] === 'Bulk associate', 'Returns preserve previous holder snapshot');
    $directoryPlan = $assignment(['holder_kind' => 'employee', 'hr_employee_id' => 1]);
    $db->exec("UPDATE employees SET first_name = 'Changed' WHERE id = 1");
    try { rejects(static fn () => $service->applyBulk($directoryPlan, $hr), 409); }
    finally { $db->exec("UPDATE employees SET first_name = 'Alex' WHERE id = 1"); }
    $clear = $service->previewBulk($input(['patch' => ['location_branch_id' => null]]), $hr);
    $service->applyBulk($clear, $hr);
    check($service->device($a['id'])['location'] === null, 'Explicit location clearing is supported');
    $disposed = $service->previewBulk($input(['patch' => ['device_condition' => 'Good']]), $hr);
    $db->exec('UPDATE inv_devices SET lifecycle_status = \'for_disposal\' WHERE id = ' . $b['id']);
    rejects(static fn () => $service->applyBulk($disposed, $hr), 409);
    $missing = $service->previewBulk(['mode' => 'details', 'devices' => [$targets()[0]], 'patch' => ['device_condition' => 'Good']], $hr);
    $db->exec('DELETE FROM inv_events WHERE device_id = ' . $a['id']);
    $db->exec('DELETE FROM inv_devices WHERE id = ' . $a['id']);
    rejects(static fn () => $service->applyBulk($missing, $hr), 409);
    rejects(static fn () => Inventory\Auth::authorize(null, true), 401);
    rejects(static fn () => Inventory\Auth::authorize(['role' => 'viewer'], true), 403);
    rejects(static fn () => Inventory\Http::verifyToken('wrong', 'expected'), 403);
}
