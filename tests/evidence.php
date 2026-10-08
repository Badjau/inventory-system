<?php
declare(strict_types=1);

// Uses the isolated inventory fixture created by run.php.
$photoPath = tempnam(__DIR__, 'inv-photo-');
if ($photoPath === false) throw new RuntimeException('Cannot allocate photo test fixture.');
try {
    file_put_contents($photoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $photo = Inventory\EvidencePhotos::inspect($photoPath, '../test.png');
    check($photo['mime'] === 'image/png' && $photo['name'] === 'test.png', 'Evidence validates image content and normalizes filename');
    $photoService = new Inventory\InventoryService($db);
    $photoInput = ['device_type' => 'Other', 'name' => 'Evidence test', 'device_condition' => 'Good', 'specifications' => []];
    $photoDevice = $photoService->save($photoInput, [$photo]);
    $photoHistory = $photoService->history($photoDevice['id']);
    check($photoHistory[0]['photos'] === [$photo] && $photoHistory[0]['event_type'] === 'create', 'Create evidence retained in history');
    check(!isset($photoHistory[0]['snapshot_json']), 'History does not expose full snapshots');
    $photoDevice = $photoService->save([...$photoInput, 'id' => $photoDevice['id'], 'version' => $photoDevice['version']], [$photo]);
    $photoHistory = $photoService->history($photoDevice['id']);
    check($photoHistory[0]['photos'] === [$photo] && $photoHistory[0]['event_type'] === 'edit', 'Edit evidence retained in history');
    rejects(static fn () => $photoService->save([...$photoInput, 'id' => $photoDevice['id'], 'version' => 1], [$photo]), 409);
    check(count($photoService->history($photoDevice['id'])) === 2, 'Stale photo save does not add history');
    $photoService->save([...$photoInput, 'id' => $photoDevice['id'], 'version' => $photoDevice['version']]);
    check($photoService->history($photoDevice['id'])[1]['photos'] === [$photo], 'Later save preserves earlier evidence');
    file_put_contents($photoPath, '<?php echo "invalid photo";');
    rejects(static fn () => Inventory\EvidencePhotos::inspect($photoPath, 'fake.jpg'));
    rejects(static fn () => Inventory\EvidencePhotos::receive(['error' => array_fill(0, 6, UPLOAD_ERR_OK)]));
    rejects(static fn () => Inventory\EvidencePhotos::receive(['error' => [UPLOAD_ERR_PARTIAL]]));
    check(Inventory\EvidencePhotos::receive(null) === [], 'Photo upload is optional');
} finally {
    unlink($photoPath);
}
