<?php
declare(strict_types=1);

use Inventory\InventoryService;

$removalService = new InventoryService($db, ['id' => 71, 'name' => 'Photo Editor', 'username' => 'photo-editor']);
$removalInput = ['device_type' => 'Other', 'name' => 'Photo removal fixture', 'device_condition' => 'Good'];
$photoA = ['file' => str_repeat('a', 32) . '.jpg', 'name' => 'First.jpg', 'mime' => 'image/jpeg'];
$photoB = ['file' => str_repeat('b', 32) . '.jpg', 'name' => 'Second.jpg', 'mime' => 'image/jpeg'];
$removalDevice = $removalService->save($removalInput, [$photoA, $photoB]);
$uploadEvent = (int) $removalService->history($removalDevice['id'])[0]['id'];
$snapshotQuery = $db->prepare('SELECT snapshot_json FROM inv_events WHERE id = ?');
$snapshotQuery->execute([$uploadEvent]);
$originalUploadSnapshot = $snapshotQuery->fetchColumn();
$removalRequest = ['id' => $removalDevice['id'], 'version' => $removalDevice['version'], 'event' => $uploadEvent, 'photo' => 0];
rejects(static fn () => $removalService->removePhoto([...$removalRequest, 'photo' => -1]));
rejects(static fn () => $removalService->removePhoto([...$removalRequest, 'photo' => true]));
rejects(static fn () => $removalService->removePhoto([...$removalRequest, 'photo' => 9]), 409);
$otherDevice = $removalService->save($removalInput, [$photoA]);
rejects(static fn () => $removalService->removePhoto([...$removalRequest, 'id' => $otherDevice['id']]), 409);
$removalDevice = $removalService->removePhoto($removalRequest);
$removalHistory = $removalService->history($removalDevice['id']);
check($removalDevice['version'] === 2, 'Individual photo removal increments device version');
check($removalHistory[0]['event_type'] === 'photo-remove' && $removalHistory[0]['actor']['id'] === 71, 'Removal records the initiating user in history/activity');
check($removalHistory[0]['removed_photo'] === ['event_id' => $uploadEvent, 'index' => 0, 'name' => 'First.jpg'], 'Removal identifies exactly one photo');
check($removalHistory[1]['photos'] === [$photoA, $photoB] && $removalHistory[1]['removed_photo_indexes'] === [0], 'Original photos stay in history; only targeted index is removed from current gallery');
$snapshotQuery->execute([$uploadEvent]);
check($snapshotQuery->fetchColumn() === $originalUploadSnapshot, 'Removal never alters original upload snapshot');
$portraitAfterRemoval = array_column($removalService->devices(true), null, 'id')[$removalDevice['id']];
check($portraitAfterRemoval['photo_event_id'] === $uploadEvent && $portraitAfterRemoval['photo_index'] === 1, 'Portrait advances to first remaining image, including second photo of same upload');
rejects(static fn () => $removalService->removePhoto($removalRequest), 409);
rejects(static fn () => $removalService->removePhoto([...$removalRequest, 'photo' => 1]), 409);
check(count($removalService->history($removalDevice['id'])) === 2, 'Duplicate/stale removals create no extra history');
$db->exec($mysql ? "CREATE TRIGGER inv_photo_fail BEFORE INSERT ON inv_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test failure'" :
    "CREATE TRIGGER inv_photo_fail BEFORE INSERT ON inv_events BEGIN SELECT RAISE(FAIL, 'Test failure'); END;");
try {
    $removalService->removePhoto([...$removalRequest, 'version' => 2, 'photo' => 1]);
    throw new RuntimeException('Expected removal audit failure');
} catch (PDOException) {
    check($removalService->device($removalDevice['id'])['version'] === 2, 'History failure rolls back photo removal version');
    $portrait = array_column($removalService->devices(true), null, 'id')[$removalDevice['id']];
    check($portrait['photo_index'] === 1, 'History failure leaves remaining photo visible');
} finally { $db->exec('DROP TRIGGER inv_photo_fail'); }
$removalService->removePhoto([...$removalRequest, 'version' => 2, 'photo' => 1]);
$portrait = array_column($removalService->devices(true), null, 'id')[$removalDevice['id']];
check($portrait['photo_event_id'] === null && $portrait['photo_index'] === null, 'Removing final current photo restores the device symbol');
$removalHistory = $removalService->history($removalDevice['id']);
check($removalHistory[2]['photos'] === [$photoA, $photoB] && count($removalHistory[2]['removed_photo_indexes']) === 2, 'All original photos remain in history after individual removals');
check($removalService->history()[0]['event_type'] === 'photo-remove', 'Activity includes individual photo removal');
