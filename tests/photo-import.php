<?php
declare(strict_types=1);

use Inventory\PhotoImport;
use Inventory\InventoryService;

$importer = new InventoryService($db, ['id' => 72, 'name' => 'Import Editor', 'username' => 'import-editor']);
$photoTarget = $importer->save(['device_type' => 'Laptop', 'name' => 'Photo import fixture', 'device_condition' => 'Good']);
$originalTarget = $photoTarget;
$photoSource = ['file' => 'Room/photo.jpg', 'name' => 'Alex Santos', 'status' => 'ok', 'needs_review' => false, 'contentHash' => str_repeat('a', 64)];
$photoPreview = PhotoImport::create(['records' => [$photoSource]], [$photoTarget], $hr->all());
$photoSelection = ['token' => $photoPreview['token'], 'row' => 0, 'id' => $photoTarget['id']];
$resolved = PhotoImport::selection($photoPreview, $photoSelection);
check($resolved['id'] === $photoTarget['id'] && $resolved['version'] === 1, 'Photo preview binds server-side device version and source row');
rejects(static fn () => PhotoImport::selection($photoPreview, [...$photoSelection, 'token' => 'wrong']), 403);
rejects(static fn () => PhotoImport::selection([...$photoPreview, 'expires_at' => time() - 1], $photoSelection), 409);
rejects(static fn () => PhotoImport::selection($photoPreview, [...$photoSelection, 'row' => '0']));
rejects(static fn () => PhotoImport::selection($photoPreview, [...$photoSelection, 'id' => 999999]));
foreach (['../photo.jpg', '/photo.jpg', 'C:/photo.jpg', 'x/../photo.jpg', 'x\\photo.jpg', 'photo.txt'] as $badPath) {
    rejects(static fn () => PhotoImport::create(['records' => [[...$photoSource, 'file' => $badPath]]], [], []));
}
rejects(static fn () => PhotoImport::create(['records' => [$photoSource, $photoSource]], [], []));
rejects(static fn () => PhotoImport::create(['records' => [[...$photoSource, 'contentHash' => 'invalid']]], [], []));
rejects(static fn () => PhotoImport::create(['records' => []], [], []));
rejects(static fn () => PhotoImport::create(['records' => array_fill(0, 2001, $photoSource)], [], []));
$unfinished = PhotoImport::create(['records' => [[...$photoSource, 'status' => 'error']]], [$photoTarget], []);
rejects(static fn () => PhotoImport::selection($unfinished, [...$photoSelection, 'token' => $unfinished['token']]));
$reviewPreview = PhotoImport::create(['records' => [[...$photoSource, 'status' => 'review', 'name' => null, 'needs_review' => true]]], [], []);
check($reviewPreview['rows'][0]['importable'] && $reviewPreview['rows'][0]['review'], 'Unreadable review rows allow explicit manual matching');
$inactive = PhotoImport::create(['records' => [$photoSource]], [[...$photoTarget, 'lifecycle_status' => 'for_disposal']], []);
check($inactive['devices'] === [], 'Disposed devices are omitted from photo targets');

$importPhoto = ['file' => str_repeat('c', 32) . '.jpg', 'name' => 'photo.jpg', 'mime' => 'image/jpeg', 'sha256' => str_repeat('b', 64)];
$imported = $importer->importPhoto($photoTarget['id'], 1, $importPhoto, $photoSource);
check(!$imported['skipped'] && $imported['device']['version'] === 2, 'Photo import increments version once');
foreach (['name', 'holder_kind', 'hr_employee_id', 'holder_name', 'specifications', 'source'] as $field) {
    check($imported['device'][$field] === $originalTarget[$field], 'Photo import preserves ' . $field);
}
$events = $importer->history($photoTarget['id']);
check($events[0]['event_type'] === 'photo-import' && $events[0]['actor']['id'] === 72 && $events[0]['photos'] === [$importPhoto], 'Import uses existing gallery and actor history');
$duplicate = $importer->importPhoto($photoTarget['id'], 1, $importPhoto, $photoSource);
check($duplicate['skipped'] && $duplicate['device']['version'] === 2 && count($importer->history($photoTarget['id'])) === 2, 'Lost-response retry skips original without extra version/history');
$newSource = [...$photoSource, 'contentHash' => str_repeat('d', 64)];
check($importer->importPhoto($photoTarget['id'], 2, $importPhoto, $newSource)['skipped'], 'Same prepared image under different source hash is skipped');
$newPhoto = [...$importPhoto, 'file' => str_repeat('d', 32) . '.jpg', 'sha256' => str_repeat('e', 64)];
rejects(static fn () => $importer->importPhoto($photoTarget['id'], 1, $newPhoto, $newSource), 409);
check($importer->device($photoTarget['id'])['version'] === 2, 'Stale upload rolls back without changes');
$db->exec($mysql ? "CREATE TRIGGER inv_import_photo_fail BEFORE INSERT ON inv_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test failure'" :
    "CREATE TRIGGER inv_import_photo_fail BEFORE INSERT ON inv_events BEGIN SELECT RAISE(FAIL, 'Test failure'); END;");
try {
    $importer->importPhoto($photoTarget['id'], 2, $newPhoto, $newSource);
    throw new RuntimeException('Expected import audit failure');
} catch (PDOException) {
    check($importer->device($photoTarget['id'])['version'] === 2, 'Photo import audit failure rolls back version');
} finally { $db->exec('DROP TRIGGER inv_import_photo_fail'); }
$next = $importer->importPhoto($photoTarget['id'], 2, $newPhoto, $newSource);
check($next['device']['version'] === 3, 'Multiple distinct photos can be added to the same device');
$importer->removePhoto(['id' => $photoTarget['id'], 'version' => 3, 'event' => (int) $events[0]['id'], 'photo' => 0]);
check($importer->importPhoto($photoTarget['id'], 4, $importPhoto, $photoSource)['skipped'], 'Removed import originals remain deduplicated');
$otherTarget = $importer->save(['device_type' => 'Laptop', 'name' => 'Other photo target', 'device_condition' => 'Good']);
check(!$importer->importPhoto($otherTarget['id'], 1, $importPhoto, $photoSource)['skipped'], 'The same original can be deliberately attached to another device');
$db->exec('UPDATE inv_devices SET lifecycle_status = \'for_disposal\' WHERE id = ' . $otherTarget['id']);
rejects(static fn () => $importer->importPhoto($otherTarget['id'], 2, $newPhoto, $newSource), 409);
