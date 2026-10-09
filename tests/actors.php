<?php
declare(strict_types=1);

use Inventory\InventoryService;

$auditActor = ['id' => 901, 'name' => 'Audit Admin', 'username' => 'audit-admin', 'password' => 'never-store'];
$auditService = new InventoryService($db, $auditActor);
$auditInput = ['device_type' => 'Other', 'name' => 'Audit fixture', 'device_condition' => 'Good', 'specifications' => []];
$auditDevice = $auditService->save([...$auditInput, 'actor' => ['id' => 999, 'name' => 'Forged']]);
$expectedActor = ['id' => 901, 'name' => 'Audit Admin', 'username' => 'audit-admin'];
check($auditService->history($auditDevice['id'])[0]['actor'] === $expectedActor, 'Audit actor comes from trusted service context, excludes credentials');
$auditService->note(['id' => $auditDevice['id'], 'message' => 'Audit note']);
check($auditService->history($auditDevice['id'])[0]['actor'] === $expectedActor, 'Notes record the authenticated actor');
$secondService = new InventoryService($db, ['id' => 902, 'name' => 'Second Admin', 'username' => 'second']);
$auditDevice = $secondService->save([...$auditInput, 'id' => $auditDevice['id'], 'version' => $auditDevice['version']]);
$auditHistory = $auditService->history($auditDevice['id']);
check($auditHistory[0]['actor']['id'] === 902 && $auditHistory[1]['actor'] === $expectedActor, 'Later actors preserve previous identity snapshots');
rejects(static fn () => $auditService->save([...$auditInput, 'id' => $auditDevice['id'], 'version' => 1]), 409);
check(count($auditService->history($auditDevice['id'])) === 3, 'Failed changes do not record actors or events');
(new InventoryService($db))->note(['id' => $auditDevice['id'], 'message' => 'CLI note']);
check($auditService->history($auditDevice['id'])[0]['actor']['name'] === 'System / CLI', 'New CLI events identify system origin');
$legacyEvent = $db->prepare('INSERT INTO inv_events (device_id, event_type, message, snapshot_json) VALUES (?, ?, ?, ?)');
$legacyEvent->execute([$auditDevice['id'], 'note', 'Historical note', '{}']);
check($auditService->history($auditDevice['id'])[0]['actor'] === null, 'Legacy events have no invented actor');
check($auditService->history()[0]['actor'] === null, 'Activity exposes the same audit identity as device history');
$allActivity = $auditService->history();
$eventCount = (int) $db->query('SELECT COUNT(*) FROM inv_events')->fetchColumn();
check($eventCount > 100 && count($allActivity) === $eventCount, 'Activity returns all changes beyond the former 100-event limit');
check((int) $allActivity[array_key_last($allActivity)]['id'] === (int) $db->query('SELECT MIN(id) FROM inv_events')->fetchColumn(), 'Activity includes the oldest recorded change');
$activityIds = array_map('intval', array_column($allActivity, 'id'));
$descendingIds = $activityIds;
rsort($descendingIds, SORT_NUMERIC);
check($activityIds === $descendingIds, 'Activity orders all changes newest first');
