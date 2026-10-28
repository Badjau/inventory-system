<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Inventory\Database;
use Inventory\HrDirectory;
use Inventory\Http;
use Inventory\InventoryService;
use Inventory\ValidationException;

try {
    $localConfig = dirname(__DIR__) . '/config.local.php';
    Http::start(is_file($localConfig) ? require $localConfig : ['local_only' => true]);
    $action = $_GET['action'] ?? 'state';
    $method = $_SERVER['REQUEST_METHOD'];
    $getActions = ['state', 'history', 'export', 'evidence'];
    $postActions = ['bulk-preview', 'bulk-apply', 'preset', 'associate', 'device', 'assignment', 'disposal', 'note', 'photo-remove', 'import-preview', 'import-apply', 'photo-import-preview', 'photo-import'];
    if (!is_string($action) || !in_array($action, [...$getActions, ...$postActions], true)) {
        throw new ValidationException('Endpoint not found.', 404);
    }
    $expected = in_array($action, $getActions, true) ? 'GET' : 'POST';
    if ($method !== $expected) {
        header('Allow: ' . $expected);
        throw new ValidationException('Method not allowed.', 405);
    }
    $user = Inventory\Auth::current();
    Inventory\Auth::authorize($user, $method === 'POST');
    $multipartDevice = $action === 'device' && str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $input = $method === 'POST' && !in_array($action, ['import-preview', 'photo-import'], true) && !$multipartDevice ? Http::input() : [];
    if ($multipartDevice) {
        Http::verifyToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', $_SESSION['csrf'] ?? '');
        $payload = $_POST['device'] ?? null;
        if (!is_string($payload) || strlen($payload) > 1048576) throw new ValidationException('Upload too large or missing device details. Check PHP post_max_size.', 413);
        try { $input = json_decode($payload, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ValidationException('Invalid device details.'); }
        if (!is_array($input) || !str_starts_with(ltrim($payload), '{')) throw new ValidationException('Send a device object.');
    }
    $db = Database::connect(inventory_config()['database']);
    $preset = new Inventory\DevicePreset(dirname(__DIR__) . '/storage/desktop-preset.json');
    if ($action === 'preset') Http::json(['data' => $preset->save($input)]);
    $creationPresets = $preset->read();
    $service = new InventoryService($db, $user, $creationPresets['device_types'], $creationPresets['presets']);
    $hr = new HrDirectory($db);
    if ($action === 'bulk-preview') {
        unset($_SESSION['bulk_preview']);
        $_SESSION['bulk_preview'] = Inventory\BulkPreview::create($service->previewBulk($input, $hr));
        Http::json(['data' => Inventory\BulkPreview::display($_SESSION['bulk_preview'])]);
    }
    if ($action === 'bulk-apply') {
        $plan = Inventory\BulkPreview::approved($_SESSION['bulk_preview'] ?? null, $input);
        $result = $service->applyBulk($plan, $hr);
        unset($_SESSION['bulk_preview']);
        Http::json(['data' => $result]);
    }
    if ($action === 'photo-import-preview') {
        unset($_SESSION['photo_import']);
        $preview = Inventory\PhotoImport::create($input, $service->devices(), $hr->all());
        $_SESSION['photo_import'] = $preview;
        Http::json(['data' => $preview]);
    }
    if ($action === 'photo-import') {
        Http::verifyToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', $_SESSION['csrf'] ?? '');
        $raw = $_POST['selection'] ?? null;
        if (!is_string($raw) || strlen($raw) > 4096) throw new ValidationException('Missing photo selection. Check PHP post_max_size.', 413);
        try { $selection = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ValidationException('Invalid photo selection.'); }
        if (!is_array($selection) || !str_starts_with(ltrim($raw), '{')) throw new ValidationException('Send a photo selection object.');
        if (!isset($_SESSION['photo_import'])) throw new ValidationException('Preview photos first.', 409);
        $target = Inventory\PhotoImport::selection($_SESSION['photo_import'], $selection);
        $photos = Inventory\EvidencePhotos::receive($_FILES['photos'] ?? null);
        try {
            if (count($photos) !== 1) throw new ValidationException('Upload exactly one photo per request.');
            $photos[0]['sha256'] = hash_file('sha256', Inventory\EvidencePhotos::directory() . '/' . $photos[0]['file']);
            $saved = $service->importPhoto($target['id'], $target['version'], $photos[0], $target['row']);
        } catch (Throwable $error) { Inventory\EvidencePhotos::discard($photos); throw $error; }
        if ($saved['skipped']) Inventory\EvidencePhotos::discard($photos);
        // Advance only this preview's expected version, including safe retries.
        foreach ($_SESSION['photo_import']['devices'] as &$device) {
            if (!$saved['skipped'] && $device['id'] === $target['id']) $device['version'] = $saved['device']['version'];
        }
        unset($device);
        Http::json(['data' => ['skipped' => $saved['skipped']]]);
    }
    if ($action === 'evidence') {
        $eventId = InventoryService::positiveInt($_GET['event'] ?? null, 'event ID');
        $index = filter_var($_GET['photo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($index === false || $index === null) throw new ValidationException('Invalid photo index.');
        $query = $db->prepare('SELECT snapshot_json FROM inv_events WHERE id = ?');
        $query->execute([$eventId]);
        $snapshot = $query->fetchColumn();
        $photo = $snapshot === false ? null : (json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)['evidence_photos'][$index] ?? null);
        if (!$photo || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', $photo['file'] ?? '')) throw new ValidationException('Photo not found.', 404);
        $path = Inventory\EvidencePhotos::directory() . '/' . $photo['file'];
        if (!is_file($path)) throw new ValidationException('Photo not found.', 404);
        if (($_GET['thumbnail'] ?? '') === '1') {
            $path = Inventory\EvidencePhotos::thumbnail($path);
            // Revalidate through authentication on every request; reuse image bytes on 304.
            $etag = '"' . hash('sha256', $photo['file'] . ':thumb96-v1') . '"';
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('ETag: ' . $etag);
            session_write_close();
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
            header('Content-Type: image/jpeg');
            header('Content-Disposition: inline; filename="thumbnail.jpg"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        header('Content-Type: ' . $photo['mime']);
        header('Content-Disposition: inline; filename="evidence.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    if ($action === 'device') {
        $photos = $multipartDevice ? Inventory\EvidencePhotos::receive($_FILES['photos'] ?? null) : [];
        try { $saved = $service->save($input, $photos); }
        catch (Throwable $error) { Inventory\EvidencePhotos::discard($photos); throw $error; }
        Http::json(['data' => $saved]);
    }
    $departments = $hr->departments();
    $departmentNames = Inventory\DepartmentNames::configured($departments);
    if ($action === 'export') {
        $path = tempnam(dirname(__DIR__) . '/storage', 'inv-export-');
        if ($path === false) throw new RuntimeException('Cannot allocate export file.');
        try {
            $employees = array_column($hr->all(), null, 'id');
            $devices = array_map($departmentNames->display(...), $service->devices());
            foreach ($devices as &$device) {
                if ($device['holder_kind'] === 'associate') $device['branch'] = 'Other';
                $employee = $employees[$device['hr_employee_id']] ?? null;
                if ($device['holder_kind'] === 'employee' && $employee) {
                    foreach (['department', 'department_id', 'branch', 'job_title'] as $field) $device[$field] = $employee[$field];
                    $device['holder_name'] = $employee['name'];
                }
            }
            unset($device);
            Inventory\WorkbookExchange::write($path, $devices);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="inventory-' . date('Y-m-d') . '.xlsx"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } finally { unlink($path); }
        exit;
    }
    if ($action === 'import-preview') {
        Http::verifyToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', $_SESSION['csrf'] ?? '');
        unset($_SESSION['import_preview']);
        $file = $_FILES['workbook'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new ValidationException('Upload an XLSX workbook within the PHP upload limit.');
        if (!is_string($file['name']) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx'
            || $file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) throw new ValidationException('Upload an XLSX workbook no larger than 5 MiB.');
        $preview = Inventory\ImportPreview::create(Inventory\Workbook::records($file['tmp_name']), $service->devices(), $hr->all());
        $_SESSION['import_preview'] = $preview;
        Http::json(['data' => $preview]);
    }
    if ($action === 'import-apply') {
        if (!isset($_SESSION['import_preview'])) throw new ValidationException('Upload a workbook to create a preview first.', 409);
        $records = Inventory\ImportPreview::selections($_SESSION['import_preview'], $input);
        $result = $service->applyImport($records, $hr);
        unset($_SESSION['import_preview']);
        Http::json(['data' => $result]);
    }
    $data = match ($action) {
        'state' => ['devices' => array_map($departmentNames->display(...), $service->devices(true)),
            'employees' => $hr->all(), 'associates' => (new Inventory\Associates($db))->all(), 'departments' => $departments, 'branches' => $hr->branches(), 'activity' => $service->history(), 'desktop_preset' => $preset->read()],
        'associate' => (new Inventory\Associates($db))->add($input),
        'history' => $service->history(InventoryService::positiveInt($_GET['id'] ?? null, 'device ID')),
        'assignment' => $service->assign($input, $hr),
        'disposal' => $service->disposal($input),
        'photo-remove' => $service->removePhoto($input),
        'note' => (static function () use ($service, $input): array { $service->note($input); return ['saved' => true]; })(),
    };
    Http::json(['data' => $data]);
} catch (ValidationException $error) {
    Http::json(['error' => $error->getMessage(), ...($error->conflicts ? ['conflicts' => $error->conflicts] : [])], $error->status);
} catch (Throwable $error) {
    error_log('Inventory: ' . $error->getMessage());
    Http::json(['error' => 'Inventory is unavailable. Check the MySQL connection and complete the setup/import commands in README.md.'], 503);
}
