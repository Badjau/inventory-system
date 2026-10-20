<?php
declare(strict_types=1);

use Inventory\InventoryService;

$oldHolder = ['holder_kind' => 'employee', 'hr_employee_id' => 10, 'holder_name' => 'Old name', 'name' => 'Desktop', 'device_type' => 'Desktop'];
$associateHolder = ['holder_kind' => 'associate', 'associate_id' => 10, 'holder_name' => 'Associate', 'name' => 'Desktop', 'device_type' => 'Desktop'];
$handover = InventoryService::handover(['before' => $oldHolder, 'after' => $associateHolder]);
check($handover['from']['id'] === 10 && $handover['to']['id'] === 10 && $handover['from']['kind'] !== $handover['to']['kind'], 'Equal HR/associate IDs remain separate holder identities');
check(InventoryService::handover(['before' => $oldHolder, 'after' => [...$oldHolder, 'holder_name' => 'Renamed person']]) === null, 'Renaming a linked holder does not invent a handover');
check(InventoryService::handover(['before' => $oldHolder, 'after' => [...$oldHolder, 'handover_date' => '2026-10-20']]) === null, 'Date-only edits do not invent a handover');
check(InventoryService::handover(['after' => $oldHolder])['from'] === null, 'Initial assignment does not invent a previous holder');
check(InventoryService::handover(['after' => ['holder_kind' => 'unassigned']]) === null, 'Creating available equipment is not a handover');
$returnMovement = InventoryService::handover(['before' => $associateHolder, 'after' => ['holder_kind' => 'unassigned']]);
check($returnMovement['from']['name'] === 'Associate' && $returnMovement['to']['name'] === 'Available pool', 'Returns retain the former holder snapshot');
$imported = ['holder_kind' => 'imported', 'holder_name' => 'Old name'];
check(InventoryService::handover(['before' => $imported, 'after' => $oldHolder])['from']['id'] === null, 'Unlinked imported names never acquire an inferred HR ID');
check(InventoryService::handover(['before' => $imported, 'after' => [...$imported, 'holder_name' => 'Different name']]) !== null, 'Changed unlinked holder snapshots remain visible as movements');
check(InventoryService::handover(['before' => $oldHolder]) === null, 'Legacy events without an after snapshot do not fabricate a movement');
check(!array_key_exists('specifications', $handover) && array_keys($handover['from']) === ['kind', 'id', 'name'], 'Handover projection exposes only relevant holder and device fields');
