<?php
declare(strict_types=1);

namespace Inventory;

final class ImportPreview
{
    public static function name(string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($name)));
    }

    public static function create(array $records, array $devices, array $employees): array
    {
        if (!$records || count($records) > 2000) throw new ValidationException('Import must contain 1 to 2,000 devices.');
        $byId = $byKey = $people = $names = [];
        foreach ($devices as $device) {
            $byId[$device['id']] = $device;
            if ($device['import_key']) $byKey[$device['import_key']] = $device;
        }
        foreach ($employees as $employee) {
            $people[(int) $employee['id']] = $employee;
            $names[self::name($employee['name'])][] = (int) $employee['id'];
        }
        $seen = $buckets = [];
        foreach ($records as $record) {
            $before = isset($record['target_id']) ? ($byId[$record['target_id']] ?? null) : ($byKey[$record['import_key'] ?? ''] ?? null);
            if (isset($record['target_id']) && !$before) throw new ValidationException('Exported inventory ID no longer exists.');
            if ($before && ($record['exchange'] ?? false) && ($record['import_key'] ?? null) !== $before['import_key']) {
                throw new ValidationException('Inventory ID and source key do not match.');
            }
            if (!$before && ($record['exchange'] ?? false) && $record['import_key']) {
                throw new ValidationException('Clear the source key when adding a new export row.');
            }
            $target = $before ? 'id:' . $before['id'] : 'key:' . ($record['import_key'] ?? 'row:' . $record['source']['row']);
            if (isset($seen[$target])) throw new ValidationException('The workbook targets the same device more than once.');
            $seen[$target] = true;
            $record['target_id'] = $before['id'] ?? null;
            $record['target_version'] = $before['version'] ?? null;
            $record['operation'] = $before ? 'Update' : 'Add';
            $record['before'] = $before;
            $person = $record['holder_kind'] !== 'shared' && $record['holder_kind'] !== 'unassigned';
            $bucket = $record['holder_kind'] === 'shared' ? 'shared:' . self::name($record['department'] ?? '') :
                ($person ? 'name:' . self::name($record['holder_name'] ?? '') : 'unassigned');
            $link = ($record['exchange'] ?? false) ? $record['hr_employee_id'] : ($before['hr_employee_id'] ?? null);
            $link = isset($people[(int) $link]) && $person ? (int) $link : null;
            if (!$link && $person) {
                $matches = $names[self::name($record['holder_name'] ?? '')] ?? [];
                if (count($matches) === 1) $link = $matches[0];
            }
            $buckets[$bucket]['records'][] = $record;
            $buckets[$bucket]['links'][] = $link;
        }
        $groups = [];
        foreach ($buckets as $bucket => $data) {
            $links = array_values(array_unique(array_filter($data['links'], static fn ($id): bool => $id !== null)));
            $conflict = count($links) > 1;
            $link = !$conflict && count($links) === 1 ? $links[0] : null;
            $key = $link ? 'employee:' . $link : $bucket;
            if (!isset($groups[$key])) $groups[$key] = ['id' => count($groups) + 1, 'hr_employee_id' => $link,
                'conflict' => $conflict, 'records' => [], 'label' => $link ? $people[$link]['name'] :
                    ($data['records'][0]['holder_kind'] === 'shared' ? ($data['records'][0]['department'] . ' (shared)') :
                    ($data['records'][0]['holder_name'] ?: 'Unassigned'))];
            array_push($groups[$key]['records'], ...$data['records']);
        }
        return ['token' => bin2hex(random_bytes(24)), 'expires_at' => time() + 1800, 'groups' => array_values($groups), 'employees' => $employees];
    }

    public static function selections(array $preview, array $input): array
    {
        if ($preview['expires_at'] < time()) throw new ValidationException('Preview expired. Upload the workbook again.', 409);
        Http::verifyToken(is_string($input['token'] ?? null) ? $input['token'] : '', $preview['token']);
        $selections = $input['groups'] ?? null;
        if (!is_array($selections) || !$selections || count($selections) > count($preview['groups'])) throw new ValidationException('Select groups to approve.');
        $groups = array_column($preview['groups'], null, 'id');
        $records = $seen = [];
        foreach ($selections as $selection) {
            if (!is_array($selection)) throw new ValidationException('Invalid group selection.');
            $id = InventoryService::positiveInt($selection['id'] ?? null, 'group ID');
            if (!isset($groups[$id]) || isset($seen[$id])) throw new ValidationException('Invalid or repeated group.');
            $seen[$id] = true;
            if (!array_key_exists('hr_employee_id', $selection)) throw new ValidationException('Choose an employee or explicitly leave the link blank.');
            $employeeId = $selection['hr_employee_id'];
            $employeeId = $employeeId === null || $employeeId === '' ? null : InventoryService::positiveInt($employeeId, 'employee ID');
            if ($groups[$id]['conflict'] && !($selection['resolved'] ?? false)) throw new ValidationException('Resolve conflicting employee links before approving.');
            foreach ($groups[$id]['records'] as $record) {
                if (in_array($record['holder_kind'], ['shared', 'unassigned'], true) && $employeeId !== null) throw new ValidationException('This group does not accept an employee link.');
                $record['approved_employee_id'] = $employeeId;
                $records[] = $record;
            }
        }
        return $records;
    }
}
