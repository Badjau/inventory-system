<?php
declare(strict_types=1);

// Uses the isolated inventory fixture created by run.php.
$httpSource = file_get_contents(dirname(__DIR__) . '/app/Http.php');
check(str_contains($httpSource, "img-src 'self' data: blob:;"), 'CSP permits local blob photos for browser resizing');
check(str_contains($httpSource, "script-src 'self';"), 'Photo blob support does not enable blob scripts');
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
    $firstPhotoEvent = (int) $photoHistory[0]['id'];
    $photoDevices = array_column($photoService->devices(true), null, 'id');
    check($photoDevices[$photoDevice['id']]['photo_event_id'] === $firstPhotoEvent, 'Device portrait selects its first upload event');
    check($photoHistory[0]['photos'] === [$photo] && $photoHistory[0]['event_type'] === 'create', 'Create evidence retained in history');
    check(!isset($photoHistory[0]['snapshot_json']), 'History does not expose full snapshots');
    $photoDevice = $photoService->save([...$photoInput, 'id' => $photoDevice['id'], 'version' => $photoDevice['version']], [$photo]);
    $photoHistory = $photoService->history($photoDevice['id']);
    check($photoHistory[0]['photos'] === [$photo] && $photoHistory[0]['event_type'] === 'edit', 'Edit evidence retained in history');
    $photoDevices = array_column($photoService->devices(true), null, 'id');
    check($photoDevices[$photoDevice['id']]['photo_event_id'] === $firstPhotoEvent, 'Later photo uploads do not replace the first portrait');
    rejects(static fn () => $photoService->save([...$photoInput, 'id' => $photoDevice['id'], 'version' => 1], [$photo]), 409);
    check(count($photoService->history($photoDevice['id'])) === 2, 'Stale photo save does not add history');
    $photoService->save([...$photoInput, 'id' => $photoDevice['id'], 'version' => $photoDevice['version']]);
    check($photoService->history($photoDevice['id'])[1]['photos'] === [$photo], 'Later save preserves earlier evidence');
    $noPhotoDevice = $photoService->save($photoInput);
    $photoDevices = array_column($photoService->devices(true), null, 'id');
    check($photoDevices[$noPhotoDevice['id']]['photo_event_id'] === null, 'Devices without photos retain their symbol');
    check(!isset($photoService->device($photoDevice['id'])['photo_event_id']), 'Portrait metadata does not change stored device snapshots');
    if (extension_loaded('gd')) {
        $thumbnailSource = imagecreatetruecolor(640, 480);
        imagefill($thumbnailSource, 0, 0, imagecolorallocate($thumbnailSource, 40, 100, 60));
        imagepng($thumbnailSource, $photoPath);
        imagedestroy($thumbnailSource);
        $thumbnail = Inventory\EvidencePhotos::thumbnail($photoPath);
        $thumbnailInfo = getimagesize($thumbnail);
        check($thumbnailInfo[0] === 96 && $thumbnailInfo[1] === 96 && $thumbnailInfo['mime'] === 'image/jpeg', 'Portrait is a small square JPEG');
        check(filesize($thumbnail) < 12000, 'Portrait bytes fit a small bandwidth budget');
        check(Inventory\EvidencePhotos::thumbnail($photoPath) === $thumbnail, 'Thumbnail derivative is cached and reused');
        check((new finfo(FILEINFO_MIME_TYPE))->file($photoPath) === 'image/png', 'Thumbnail generation leaves original evidence intact');
    }
    file_put_contents($photoPath, '<?php echo "invalid photo";');
    rejects(static fn () => Inventory\EvidencePhotos::inspect($photoPath, 'fake.jpg'));
    rejects(static fn () => Inventory\EvidencePhotos::receive(['error' => array_fill(0, 6, UPLOAD_ERR_OK)]));
    rejects(static fn () => Inventory\EvidencePhotos::receive(['error' => [UPLOAD_ERR_PARTIAL]]));
    check(Inventory\EvidencePhotos::receive(null) === [], 'Photo upload is optional');
} finally {
    if (is_file($photoPath . '.thumb96.jpg')) unlink($photoPath . '.thumb96.jpg');
    unlink($photoPath);
}
