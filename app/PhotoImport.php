<?php
declare(strict_types=1);

namespace Inventory;

/** Session-bound manifest; photos are uploaded one at a time only after approval. */
final class PhotoImport
{
    public static function create(array $input, array $devices, array $employees): array
    {
        $records = $input['records'] ?? null;
        if (!is_array($records) || !array_is_list($records) || !$records || count($records) > 2000) {
            throw new ValidationException('Choose results.json containing 1 to 2,000 photos.');
        }
        $rows = $seen = [];
        foreach ($records as $record) {
            if (!is_array($record)) throw new ValidationException('Invalid photo record.');
            $file = InventoryService::text($record, 'file', true, 1000);
            if (str_contains($file, '\\') || str_starts_with($file, '/') || preg_match('~(^|/)\.\.?(/|$)|[:\x00-\x1f]~', $file)
                || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)
                || isset($seen[$file])) throw new ValidationException('Photo paths must be unique relative JPEG, PNG or WebP paths.');
            $seen[$file] = true;
            $hash = $record['contentHash'] ?? null;
            if ($hash !== null && (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash))) throw new ValidationException('Invalid photo content hash.');
            $status = $record['status'] ?? null;
            if (!in_array($status, ['ok', 'review', 'error', 'pending'], true)) throw new ValidationException('Invalid photo status.');
            $rows[] = ['id' => count($rows), 'file' => $file, 'name' => InventoryService::text($record, 'name'),
                'contentHash' => $hash, 'review' => $status !== 'ok' || ($record['needs_review'] ?? true) !== false,
                'importable' => in_array($status, ['ok', 'review'], true) && $hash !== null];
        }
        $targets = [];
        foreach ($devices as $device) {
            if (($device['lifecycle_status'] ?? 'active') !== 'active') continue;
            $targets[] = array_intersect_key($device, array_flip(['id', 'version', 'name', 'device_type', 'serial_number',
                'asset_tag', 'holder_kind', 'hr_employee_id', 'holder_name', 'department']));
        }
        return ['token' => bin2hex(random_bytes(24)), 'expires_at' => time() + 1800,
            'rows' => $rows, 'devices' => $targets, 'employees' => $employees];
    }

    public static function selection(array $preview, array $input): array
    {
        if ($preview['expires_at'] < time()) throw new ValidationException('Photo preview expired. Preview the files again.', 409);
        Http::verifyToken(is_string($input['token'] ?? null) ? $input['token'] : '', $preview['token']);
        $index = $input['row'] ?? null;
        if (!is_int($index) || !isset($preview['rows'][$index]) || !$preview['rows'][$index]['importable']) {
            throw new ValidationException('Choose an importable photo from this preview.');
        }
        $id = InventoryService::positiveInt($input['id'] ?? null, 'device ID');
        $targets = array_column($preview['devices'], null, 'id');
        if (!isset($targets[$id])) throw new ValidationException('Choose an active device from this preview.');
        return ['row' => $preview['rows'][$index], 'id' => $id, 'version' => $targets[$id]['version']];
    }
}
