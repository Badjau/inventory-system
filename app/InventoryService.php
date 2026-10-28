<?php
declare(strict_types=1);

namespace Inventory;

use DateTimeImmutable;
use PDO;
use Throwable;

final class InventoryService
{
    public const TYPES = ['Laptop', 'Desktop', 'Monitor', 'Printer', 'Keyboard', 'Mouse', 'Other'];
    public const CONDITIONS = ['Good', 'Needs attention', 'Faulty', 'Unknown'];

    private array $actor;

    public function __construct(private PDO $db, ?array $actor = null, private ?array $deviceTypes = null, private array $devicePresets = [])
    {
        // Copy only audit identity, never credentials or client-supplied fields.
        $this->actor = $actor === null
            ? ['id' => null, 'name' => 'System / CLI', 'username' => null]
            : ['id' => self::positiveInt($actor['id'] ?? null, 'actor ID'),
                'name' => $actor['name'] ?? '', 'username' => $actor['username'] ?? ''];
    }

    public function devices(bool $withPhotos = false): array
    {
        $devices = array_map($this->decode(...), $this->db->query('SELECT * FROM inv_devices ORDER BY id')->fetchAll());
        if ($withPhotos) {
            $portraits = [];
            foreach ($this->currentPhotos() as $photo) $portraits[$photo['device_id']] ??= $photo;
            foreach ($devices as &$device) {
                $device['photo_event_id'] = $portraits[$device['id']]['event_id'] ?? null;
                $device['photo_index'] = $portraits[$device['id']]['index'] ?? null;
            }
            unset($device);
        }
        return $devices;
    }

    private function currentPhotos(?int $deviceId = null): array
    {
        // Read only photo metadata and removal references, never full device snapshots.
        $query = $this->db->prepare("SELECT id, device_id,
            JSON_EXTRACT(snapshot_json, '$.evidence_photos') AS photos,
            JSON_EXTRACT(snapshot_json, '$.removed_photo') AS removed_photo
            FROM inv_events WHERE (event_type = 'photo-remove' OR JSON_EXTRACT(snapshot_json, '$.evidence_photos[0].file') IS NOT NULL)"
            . ($deviceId === null ? '' : ' AND device_id = ?') . ' ORDER BY id');
        $query->execute($deviceId === null ? [] : [$deviceId]);
        $photos = [];
        foreach ($query->fetchAll() as $event) {
            foreach (json_decode($event['photos'] ?? '[]', true, 512, JSON_THROW_ON_ERROR) as $index => $photo) {
                $photos[$event['id'] . ':' . $index] = ['device_id' => (int) $event['device_id'], 'event_id' => (int) $event['id'], 'index' => $index, 'name' => $photo['name']];
            }
            $removed = json_decode($event['removed_photo'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            if ($removed) unset($photos[$removed['event_id'] . ':' . $removed['index']]);
        }
        return array_values($photos);
    }

    public function removePhoto(array $input): array
    {
        $id = self::positiveInt($input['id'] ?? null, 'device ID');
        $version = self::positiveInt($input['version'] ?? null, 'version');
        $eventId = self::positiveInt($input['event'] ?? null, 'event ID');
        $rawIndex = $input['photo'] ?? null;
        $index = (is_int($rawIndex) || is_string($rawIndex)) ? filter_var($rawIndex, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
        if ($index === false) throw new ValidationException('Invalid photo index.');
        return $this->transaction(function () use ($id, $version, $eventId, $index): array {
            $before = $this->device($id);
            $photo = null;
            foreach ($this->currentPhotos($id) as $candidate) {
                if ($candidate['event_id'] === $eventId && $candidate['index'] === $index) $photo = $candidate;
            }
            if (!$photo) throw new ValidationException('Photo was already removed or does not belong to this device. Refresh and try again.', 409);
            $this->update($id, $version, []);
            $this->event($id, 'photo-remove', 'Photo removed from current device photos: ' . $photo['name'] . '. Original retained in history.',
                before: $before, extra: ['removed_photo' => ['event_id' => $eventId, 'index' => $index, 'name' => $photo['name']]]);
            return $this->device($id);
        });
    }

    public function device(int $id): array
    {
        $query = $this->db->prepare('SELECT * FROM inv_devices WHERE id = ?');
        $query->execute([$id]);
        $row = $query->fetch();
        if (!$row) {
            throw new ValidationException('Device not found.', 404);
        }
        return $this->decode($row);
    }

    private function decode(array $row): array
    {
        $row['lifecycle_status'] ??= 'active';
        foreach (['id', 'version'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['hr_employee_id'] = $row['hr_employee_id'] === null ? null : (int) $row['hr_employee_id'];
        $row['location_branch_id'] = isset($row['location_branch_id']) ? (int) $row['location_branch_id'] : null;
        $row['handover_date'] = $row['handover_date'] ?? null;
        $row['department_id'] = isset($row['department_id']) ? (int) $row['department_id'] : null;
        $row['specifications'] = json_decode($row['specifications_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['source'] = $row['source_json'] ? json_decode($row['source_json'], true, 512, JSON_THROW_ON_ERROR) : null;
        unset($row['specifications_json'], $row['source_json']);
        return $row;
    }

    public function history(?int $deviceId = null): array
    {
        if ($deviceId !== null) {
            $this->device($deviceId);
        }
        $query = $this->db->prepare('SELECT id, device_id, event_type, message, handover_date, recorded_at, snapshot_json
            FROM inv_events' . ($deviceId === null ? ' ORDER BY id DESC' : ' WHERE device_id = ? ORDER BY id DESC'));
        $query->execute($deviceId === null ? [] : [$deviceId]);
        $events = array_map(static function (array $event): array {
            $snapshot = json_decode($event['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $event['handover'] = self::handover($snapshot);
            $event['holder_names'] = array_values(array_unique(array_filter([
                $snapshot['before']['holder_name'] ?? null,
                $snapshot['after']['holder_name'] ?? null,
            ], static fn ($name): bool => is_string($name) && trim($name) !== '')));
            $event['photos'] = $snapshot['evidence_photos'] ?? [];
            $event['actor'] = $snapshot['actor'] ?? null;
            $event['removed_photo'] = $snapshot['removed_photo'] ?? null;
            $event['recovery'] = $snapshot['recovery'] ?? null;
            unset($event['snapshot_json']);
            return $event;
        }, $query->fetchAll());
        $removed = [];
        foreach ($events as $event) if ($event['removed_photo']) $removed[$event['removed_photo']['event_id']][] = $event['removed_photo']['index'];
        foreach ($events as &$event) $event['removed_photo_indexes'] = $removed[$event['id']] ?? [];
        unset($event);
        return $events;
    }

    /** A small history projection; never expose complete source/device snapshots. */
    public static function handover(array $snapshot): ?array
    {
        $holder = static function (?array $device): array {
            $kind = $device['holder_kind'] ?? 'unassigned';
            return ['kind' => $kind,
                'id' => match ($kind) {
                    'employee' => isset($device['hr_employee_id']) ? (int) $device['hr_employee_id'] : null,
                    'associate' => isset($device['associate_id']) ? (int) $device['associate_id'] : null,
                    'shared' => isset($device['department_id']) ? (int) $device['department_id'] : null,
                    default => null,
                },
                'name' => $kind === 'unassigned' ? 'Available pool' : ($kind === 'shared'
                    ? ($device['department'] ?? 'Shared department') : ($device['holder_name'] ?? 'Recorded holder'))];
        };
        $before = $snapshot['before'] ?? null;
        $after = $snapshot['after'] ?? null;
        if (!$after) return null;
        $from = $holder($before);
        $to = $holder($after);
        // Renaming a linked person, editing dates, and device edits are not handovers.
        if ($from['kind'] === $to['kind'] && $from['id'] === $to['id']
            && ($from['id'] !== null || $from['name'] === $to['name'])) return null;
        return ['from' => $before === null ? null : $from, 'to' => $to,
            'device_name' => $after['name'] ?? 'Device', 'device_type' => $after['device_type'] ?? 'Other'];
    }

    public static function text(array $input, string $field, bool $required = false, int $max = 255): ?string
    {
        if (isset($input[$field]) && !is_string($input[$field])) {
            throw new ValidationException("Invalid $field.");
        }
        $value = trim($input[$field] ?? '');
        if (($required && $value === '') || mb_strlen($value) > $max) {
            throw new ValidationException("$field is required or exceeds $max characters.");
        }
        return $value === '' ? null : $value;
    }

    public static function positiveInt(mixed $value, string $label): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new ValidationException("Invalid $label.");
        }
        return (int) $value;
    }

    private function editable(array $input): array
    {
        $type = self::text($input, 'device_type', true);
        $condition = self::text($input, 'device_condition', true);
        $types = $this->deviceTypes ?? (new DevicePreset(dirname(__DIR__) . '/storage/desktop-preset.json'))->read()['device_types'];
        if (!in_array($type, $types, true) || !in_array($condition, self::CONDITIONS, true)) {
            throw new ValidationException('Choose a supported device type and condition.');
        }
        $specs = $input['specifications'] ?? [];
        if (!is_array($specs) || count($specs) > 128) {
            throw new ValidationException('Invalid specifications.');
        }
        $clean = [];
        foreach ($specs as $key => $value) {
            if (!is_string($key) || !is_string($value) || mb_strlen($key) > 100 || mb_strlen($value) > 4000) {
                throw new ValidationException('Invalid specification name or value.');
            }
            if (trim($key) === '' && trim($value) !== '') throw new ValidationException('Specification names cannot be blank.');
            if (trim($key) !== '' && trim($value) !== '') {
                $clean[trim($key)] = trim($value);
            }
        }
        $values = ['device_type' => $type, 'name' => self::text($input, 'name', true),
            'serial_number' => self::text($input, 'serial_number'), 'asset_tag' => self::text($input, 'asset_tag'),
            'device_condition' => $condition, 'location' => self::text($input, 'location'),
            'specifications_json' => json_encode($clean, JSON_THROW_ON_ERROR)];
        if (array_key_exists('location_branch_id', $input)) {
            $branchId = $input['location_branch_id'];
            $branch = $branchId === null || $branchId === '' ? null : (new HrDirectory($this->db))->branch(self::positiveInt($branchId, 'location branch ID'));
            $values['location_branch_id'] = $branch['id'] ?? null;
            $values['location'] = $branch['name'] ?? null;
        } elseif (array_key_exists('location', $input)) {
            $values['location_branch_id'] = null;
        } elseif (array_key_exists('id', $input)) {
            unset($values['location']);
        }
        return $values;
    }

    private function transaction(callable $callback): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $callback();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function update(int $id, int $version, array $values): void
    {
        // Fixed keys originate in this class, never in request field names.
        $assignments = implode(', ', array_map(static fn (string $key): string => "$key = ?", array_keys($values)));
        $setValues = $assignments === '' ? '' : $assignments . ', ';
        $query = $this->db->prepare("UPDATE inv_devices SET {$setValues}version = version + 1,
            updated_at = CURRENT_TIMESTAMP WHERE id = ? AND version = ?");
        $query->execute([...array_values($values), $id, $version]);
        if ($query->rowCount() !== 1) {
            throw new ValidationException('This device changed since you opened it. Refresh and try again.', 409);
        }
    }

    private function insert(array $values): int
    {
        $columns = implode(', ', array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $query = $this->db->prepare("INSERT INTO inv_devices ($columns) VALUES ($placeholders)");
        $query->execute(array_values($values));
        return (int) $this->db->lastInsertId();
    }

    private function event(int $id, string $type, string $message, ?string $date = null, ?array $before = null, ?array $incoming = null, array $photos = [], array $extra = []): void
    {
        $query = $this->db->prepare('INSERT INTO inv_events
            (device_id, event_type, message, handover_date, snapshot_json) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$id, $type, $message, $date,
            json_encode(['actor' => $this->actor, 'before' => $before, 'after' => $this->device($id), ...($incoming === null ? [] : ['incoming_source' => $incoming]), ...($photos ? ['evidence_photos' => $photos] : []), ...$extra], JSON_THROW_ON_ERROR)]);
    }

    public function save(array $input, array $photos = []): array
    {
        // Only an omitted creation specification map uses defaults. Explicit edits,
        // cleared values, and empty maps retain the caller's intended specifications.
        if (!array_key_exists('id', $input) && !array_key_exists('specifications', $input)) {
            $type = self::text($input, 'device_type', true);
            $input['specifications'] = [];
            foreach ($this->devicePresets[$type] ?? [] as $field) {
                $input['specifications'][$field['name']] = $field['value'];
            }
        }
        $values = $this->editable($input);
        return $this->transaction(function () use ($input, $values, $photos): array {
            if (array_key_exists('id', $input)) {
                $id = self::positiveInt($input['id'], 'device ID');
                $version = self::positiveInt($input['version'] ?? null, 'version');
                $before = $this->device($id);
                $this->requireActive($before);
                $this->update($id, $version, $values);
                $specs = json_decode($values['specifications_json'], true, 512, JSON_THROW_ON_ERROR);
                $changes = [];
                foreach (array_unique([...array_keys($before['specifications']), ...array_keys($specs)]) as $key) {
                    $old = $before['specifications'][$key] ?? null;
                    $new = $specs[$key] ?? null;
                    if ($old === $new) continue;
                    if ($old === null) {
                        $changes[] = "$key added: $new";
                    } elseif ($new === null) {
                        $changes[] = "$key removed (was: $old)";
                    } else {
                        $changes[] = "$key changed from $old to $new";
                    }
                }
                $message = 'Device details updated.';
                if ($changes) $message .= ' Specifications: ' . implode('; ', $changes) . '.';
                if ($photos) $message .= ' Evidence photos attached: ' . count($photos) . '.';
                $this->event($id, 'edit', $message, before: $before, photos: $photos);
            } else {
                $id = $this->insert($values);
                $this->event($id, 'create', 'Device registered; available for assignment.' . ($photos ? ' Evidence photos attached: ' . count($photos) . '.' : ''), photos: $photos);
            }
            return $this->device($id);
        });
    }

    public function importPhoto(int $id, int $version, array $photo, array $source): array
    {
        return $this->transaction(function () use ($id, $version, $photo, $source): array {
            // Serialize imports for the same device before checking duplicates.
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $this->db->prepare('SELECT id FROM inv_devices WHERE id = ? FOR UPDATE');
                $lock->execute([$id]);
                $lock->fetchColumn();
            }
            $before = $this->device($id);
            $this->requireActive($before);
            $query = $this->db->prepare("SELECT JSON_EXTRACT(snapshot_json, '$.photo_import') AS imported FROM inv_events WHERE device_id = ? AND event_type = 'photo-import'");
            $query->execute([$id]);
            foreach ($query->fetchAll() as $event) {
                $imported = json_decode($event['imported'] ?? 'null', true, 32, JSON_THROW_ON_ERROR);
                if ($imported && ($imported['source_hash'] === $source['contentHash'] || $imported['stored_hash'] === $photo['sha256'])) {
                    return ['skipped' => true, 'device' => $before];
                }
            }
            $this->update($id, $version, []);
            $this->event($id, 'photo-import', 'Evidence photo imported: ' . $source['file'], before: $before, photos: [$photo],
                extra: ['photo_import' => ['source_hash' => $source['contentHash'], 'stored_hash' => $photo['sha256'],
                    'source_file' => $source['file'], 'extracted_name' => $source['name']]]);
            return ['skipped' => false, 'device' => $this->device($id)];
        });
    }

    public function assign(array $input, HrDirectory $hr): array
    {
        $id = self::positiveInt($input['id'] ?? null, 'device ID');
        $version = self::positiveInt($input['version'] ?? null, 'version');
        [$values, $target, $date, $notes] = $this->assignmentValues($input, $hr);
        $kind = $values['holder_kind'];
        return $this->transaction(function () use ($id, $version, $kind, $values, $target, $date, $notes): array {
            $before = $this->device($id);
            $this->requireActive($before);
            $previous = $before['holder_kind'] === 'shared' ? $before['department'] : ($before['holder_name'] ?? 'unassigned pool');
            $this->update($id, $version, $values);
            $this->event($id, $kind === 'unassigned' ? 'return' : 'assign',
                "Holder changed from $previous to $target." . ($notes ? " $notes" : ''), $date, $before);
            return $this->device($id);
        });
    }

    private function assignmentValues(array $input, HrDirectory $hr): array
    {
        $kind = self::text($input, 'holder_kind', true);
        $date = self::text($input, 'handover_date');
        if ($date !== null) self::validateDate($date);
        $notes = self::text($input, 'notes', max: 2000);
        $values = ['holder_kind' => $kind, 'hr_employee_id' => null, 'associate_id' => null, 'holder_name' => null,
            'department' => null, 'department_id' => null, 'branch' => null, 'job_title' => null,
            'handover_date' => $kind === 'unassigned' ? null : $date];
        if ($kind === 'employee') {
            $employee = $hr->find(self::positiveInt($input['hr_employee_id'] ?? null, 'employee ID'));
            $values = array_merge($values, ['hr_employee_id' => (int) $employee['id'], 'holder_name' => $employee['name'],
                'department' => $employee['department'], 'department_id' => $employee['department_id'],
                'branch' => $employee['branch'], 'job_title' => $employee['job_title']]);
            $target = $employee['name'];
        } elseif ($kind === 'associate') {
            $associate = (new Associates($this->db))->find(self::positiveInt($input['associate_id'] ?? null, 'associate ID'));
            $values['associate_id'] = (int) $associate['id'];
            $values['holder_name'] = $associate['name'];
            $values['job_title'] = $associate['job_title'];
            $values['branch'] = $associate['branch'];
            $target = $associate['name'];
        } elseif ($kind === 'shared') {
            $department = isset($input['department_id'])
                ? $hr->department(self::positiveInt($input['department_id'], 'department ID'))
                : DepartmentNames::configured($hr->departments())->required(self::text($input, 'department', true));
            $values['department'] = $department['name'];
            $values['department_id'] = $department['id'];
            $target = $values['department'] . ' (shared)';
        } elseif ($kind === 'unassigned') {
            $target = 'unassigned pool';
        } else {
            throw new ValidationException('Choose an employee, a shared department, or the unassigned pool.');
        }
        return [$values, $target, $date, $notes];
    }

    /** Build a validated patch against every target; only this server plan is approved. */
    public function previewBulk(array $input, HrDirectory $hr): array
    {
        $targets = $input['devices'] ?? null;
        if (!is_array($targets) || !array_is_list($targets) || !$targets || count($targets) > 1000) {
            throw new ValidationException('Select between 1 and 1,000 devices.');
        }
        $mode = $input['mode'] ?? null;
        if (!in_array($mode, ['details', 'assignment'], true)) throw new ValidationException('Choose a bulk operation.');
        $patch = $input['patch'] ?? [];
        if (!is_array($patch) || array_diff(array_keys($patch), ['device_condition', 'location_branch_id'])) {
            throw new ValidationException('Unsupported bulk detail field.');
        }
        $operations = $input['specifications'] ?? [];
        if (!is_array($operations) || !array_is_list($operations) || count($operations) > 128) throw new ValidationException('Invalid specification operations.');
        $names = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) throw new ValidationException('Invalid specification operation.');
            $name = self::text($operation, 'name', true, 100);
            $normalized = self::specificationName($name);
            if (isset($names[$normalized])) throw new ValidationException('Repeated specification operation: ' . $name);
            $names[$normalized] = true;
            if (!in_array($operation['action'] ?? null, ['set', 'remove'], true)) throw new ValidationException('Choose Set or Remove.');
            if ($operation['action'] === 'set') self::text($operation, 'value', true, 4000);
        }
        $assignment = null;
        if ($mode === 'assignment') {
            if ($patch || $operations || !is_array($input['assignment'] ?? null)) throw new ValidationException('Keep assignment separate from detail edits.');
            $assignment = $this->assignmentValues($input['assignment'], $hr);
        } elseif (!$patch && !$operations) throw new ValidationException('Choose at least one change.');
        $rows = [];
        $conflicts = [];
        $seen = [];
        foreach ($targets as $target) {
            if (!is_array($target)) throw new ValidationException('Invalid bulk target.');
            $id = self::positiveInt($target['id'] ?? null, 'device ID');
            $version = self::positiveInt($target['version'] ?? null, 'version');
            if (isset($seen[$id])) throw new ValidationException('Repeated device ID.');
            $seen[$id] = true;
            try { $before = $this->device($id); }
            catch (ValidationException $error) { if ($error->status !== 404) throw $error; $conflicts[] = $id; continue; }
            if ($before['version'] !== $version || $before['lifecycle_status'] !== 'active') { $conflicts[] = $id; continue; }
            $diffs = [];
            if ($assignment !== null) {
                $values = $assignment[0];
            } else {
                $specs = $before['specifications'];
                foreach ($operations as $operation) {
                    $name = trim($operation['name']);
                    $matches = array_values(array_filter(array_keys($specs), static fn ($key) => self::specificationName($key) === self::specificationName($name)));
                    if (count($matches) > 1) throw new ValidationException('Device #' . $id . ' has ambiguous specification keys: ' . implode(', ', $matches));
                    $key = $matches[0] ?? $name;
                    $old = $specs[$key] ?? null;
                    $new = $operation['action'] === 'remove' ? null : trim($operation['value']);
                    if ($new === null) unset($specs[$key]); else $specs[$key] = $new;
                    if ($old !== $new) $diffs[] = ['field' => 'Spec: ' . $key, 'before' => $old, 'after' => $new,
                        'kind' => $old === null ? 'addition' : ($new === null ? 'removal' : 'change')];
                }
                $editableBefore = $before;
                unset($editableBefore['location'], $editableBefore['location_branch_id']);
                $validated = $this->editable([...$editableBefore, ...$patch, 'specifications' => $specs]);
                $values = array_intersect_key($validated, $patch);
                if (array_key_exists('location_branch_id', $patch)) $values['location'] = $validated['location'];
                if ($operations) $values['specifications_json'] = $validated['specifications_json'];
            }
            $changes = [];
            foreach ($values as $key => $value) {
                $old = $key === 'specifications_json' ? json_encode($before['specifications'], JSON_THROW_ON_ERROR) : ($before[$key] ?? null);
                if ($old === $value) continue;
                $changes[$key] = $value;
                $labels = ['device_condition' => 'Condition', 'location' => 'Location', 'handover_date' => 'Handover date',
                    'department' => 'Department', 'branch' => 'Holder branch', 'job_title' => 'Job title'];
                if (isset($labels[$key])) $diffs[] = ['field' => $labels[$key], 'before' => $old, 'after' => $value, 'kind' => 'change'];
            }
            if ($assignment !== null) {
                $holderChanged = false;
                foreach (['holder_kind', 'hr_employee_id', 'associate_id', 'holder_name', 'department_id'] as $key) {
                    if (($before[$key] ?? null) !== $values[$key]) $holderChanged = true;
                }
                if ($holderChanged) array_unshift($diffs, ['field' => 'Holder',
                    'before' => $before['holder_kind'] === 'shared' ? $before['department'] . ' (shared)' : ($before['holder_name'] ?? 'Available pool'),
                    'after' => $assignment[1], 'kind' => 'change']);
            }
            if (array_key_exists('location_branch_id', $changes) && !array_filter($diffs, static fn ($diff) => $diff['field'] === 'Location')) {
                $diffs[] = ['field' => 'Location', 'before' => $before['location'] === null ? null : $before['location'] . ' (#' . ($before['location_branch_id'] ?? 'unlinked') . ')',
                    'after' => $values['location'] === null ? null : $values['location'] . ' (#' . $values['location_branch_id'] . ')', 'kind' => 'change'];
            }
            $rows[] = ['id' => $id, 'version' => $version, 'name' => $before['name'],
                'holder' => $before['holder_kind'] === 'shared' ? $before['department'] : ($before['holder_name'] ?? 'Available pool'),
                'before' => $before, 'values' => $changes, 'diffs' => $diffs];
        }
        if ($conflicts) throw new ValidationException('Refresh and review devices: #' . implode(', #', $conflicts), 409, $conflicts);
        return ['input' => $input, 'rows' => $rows, 'assignment' => $assignment];
    }

    private static function specificationName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }

    public function applyBulk(array $plan, HrDirectory $hr): array
    {
        return $this->transaction(function () use ($plan, $hr): array {
            $ids = array_column($plan['rows'], 'id');
            sort($ids, SORT_NUMERIC);
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $lock = $this->db->prepare('SELECT id FROM inv_devices WHERE id = ? FOR UPDATE');
                foreach ($ids as $id) { $lock->execute([$id]); $lock->fetchColumn(); $lock->closeCursor(); }
            }
            $fresh = $this->previewBulk($plan['input'], $hr);
            if ($fresh['assignment'] !== $plan['assignment'] || array_column($fresh['rows'], 'values') !== array_column($plan['rows'], 'values')) {
                throw new ValidationException('Directory or device details changed. Refresh and review again.', 409, $ids);
            }
            $changed = 0;
            foreach ($fresh['rows'] as $row) {
                if (!$row['values']) continue;
                $this->update($row['id'], $row['version'], $row['values']);
                $assignment = $fresh['assignment'];
                $message = $assignment === null ? 'Bulk device details updated.' : 'Bulk holder change from ' . $row['holder'] . ' to ' . $assignment[1] . '.';
                if ($assignment !== null && $assignment[3]) $message .= ' ' . $assignment[3];
                $message .= ' Changes: ' . implode('; ', array_map(static fn ($diff) => $diff['field'] . ': ' . ($diff['before'] ?? 'Not recorded') . ' → ' . ($diff['after'] ?? 'Removed'), $row['diffs']));
                $type = $assignment === null ? 'edit' : ($row['values']['holder_kind'] ?? $row['before']['holder_kind']);
                $this->event($row['id'], $assignment === null ? 'edit' : ($type === 'unassigned' ? 'return' : 'assign'),
                    $message, $assignment[2] ?? null, $row['before']);
                $changed++;
            }
            return ['changed' => $changed, 'unchanged' => count($fresh['rows']) - $changed];
        });
    }

    private function requireActive(array $device): void
    {
        if ($device['lifecycle_status'] !== 'active') {
            throw new ValidationException('This device is permanently marked For disposal. Assignment and device edits are unavailable.', 409);
        }
    }

    public function disposal(array $input): array
    {
        $id = self::positiveInt($input['id'] ?? null, 'device ID');
        $version = self::positiveInt($input['version'] ?? null, 'version');
        $date = self::text($input, 'disposal_date', true);
        self::validateDate($date);
        $notes = self::text($input, 'notes', max: 2000);
        $names = $input['specification_names'] ?? [];
        if (!is_array($names) || !array_is_list($names) || count($names) > 128) throw new ValidationException('Invalid component selections.');
        return $this->transaction(function () use ($id, $version, $date, $notes, $names): array {
            $before = $this->device($id);
            $this->requireActive($before);
            $selected = [];
            foreach ($names as $name) {
                if (!is_string($name) || isset($selected[$name]) || !isset($before['specifications'][$name]) || trim($before['specifications'][$name]) === '') {
                    throw new ValidationException('Select each existing populated specification at most once.');
                }
                $value = $before['specifications'][$name];
                self::text(['name' => "$name: $value"], 'name', true);
                $selected[$name] = $value;
            }
            $this->update($id, $version, ['lifecycle_status' => 'for_disposal', 'holder_kind' => 'unassigned',
                'hr_employee_id' => null, 'associate_id' => null, 'holder_name' => null, 'department' => null,
                'department_id' => null, 'branch' => null, 'job_title' => null, 'handover_date' => null]);
            $recovered = [];
            foreach ($selected as $name => $value) {
                $recovered[] = $this->insert(['device_type' => 'Other', 'name' => "$name: $value", 'device_condition' => 'Unknown',
                    'location' => $before['location'], 'location_branch_id' => $before['location_branch_id'],
                    'specifications_json' => json_encode([$name => $value], JSON_THROW_ON_ERROR)]);
            }
            $metadata = ['original_device_id' => $id, 'recovered_device_ids' => $recovered, 'specifications' => $selected, 'disposal_date' => $date];
            $this->event($id, 'disposal', 'Marked For disposal; recovered ' . count($recovered) . ' components.' . ($notes ? " $notes" : ''),
                $date, $before, extra: ['recovery' => $metadata]);
            foreach ($recovered as $componentId) {
                $this->event($componentId, 'recovery', "Component recovered from device #$id; available for assignment.", $date,
                    extra: ['recovery' => $metadata]);
            }
            return ['device' => $this->device($id), 'recovered_device_ids' => $recovered];
        });
    }

    public function note(array $input): void
    {
        $id = self::positiveInt($input['id'] ?? null, 'device ID');
        $message = self::text($input, 'message', true, 2000);
        $this->transaction(function () use ($id, $message): void {
            $this->device($id);
            $this->event($id, 'note', $message);
        });
    }

    public function import(array $records): array
    {
        return $this->transaction(function () use ($records): array {
            $added = $skipped = 0;
            $exists = $this->db->prepare('SELECT id FROM inv_devices WHERE import_key = ?');
            foreach ($records as $record) {
                $exists->execute([$record['import_key']]);
                if ($exists->fetchColumn() !== false) {
                    $skipped++;
                    continue;
                }
                $values = $record;
                unset($values['specifications'], $values['source']);
                $values['specifications_json'] = json_encode($record['specifications'], JSON_THROW_ON_ERROR);
                $values['source_json'] = json_encode($record['source'], JSON_THROW_ON_ERROR);
                $id = $this->insert($values);
                $this->event($id, 'import', 'Imported from ' . $record['source']['sheet'] . ' row ' . $record['source']['row']
                    . '. Original handover date and condition unknown.');
                $added++;
            }
            return ['added' => $added, 'skipped' => $skipped];
        });
    }

    public function applyImport(array $records, HrDirectory $hr): array
    {
        $employees = array_column($hr->all(), null, 'id');
        $departments = DepartmentNames::configured($hr->departments());
        try {
            return $this->transaction(function () use ($records, $hr, $employees, $departments): array {
            $added = $updated = 0;
            $seen = [];
            $exists = $this->db->prepare('SELECT id FROM inv_devices WHERE import_key = ?');
            foreach ($records as $record) {
                $id = $record['target_id'];
                $before = $id === null ? null : $this->device($id);
                if ($before) $this->requireActive($before);
                if ($before && $before['version'] !== $record['target_version']) throw new ValidationException('A previewed device changed. Upload again to review current information.', 409);
                if (isset($seen[$id ?? $record['import_key'] ?? 'new:' . $record['source']['row']])) throw new ValidationException('Repeated import target.');
                $seen[$id ?? $record['import_key'] ?? 'new:' . $record['source']['row']] = true;
                if (!$before && $record['import_key']) {
                    $exists->execute([$record['import_key']]);
                    if ($exists->fetchColumn() !== false) throw new ValidationException('A device was imported after this preview. Upload again.', 409);
                }
                $exchange = $record['exchange'] ?? false;
                $input = $record;
                if ($before) $input['id'] = $id;
                if (!$exchange) {
                    unset($input['location']);
                    $input['device_condition'] = $before['device_condition'] ?? 'Unknown';
                }
                $input['specifications'] = array_filter($record['specifications'], static fn ($v): bool => $v !== '');
                $values = $this->editable($input);
                $kind = $record['holder_kind'];
                if (!in_array($kind, ['employee', 'associate', 'imported', 'shared', 'unassigned'], true)) throw new ValidationException('Invalid holder kind in workbook.');
                $holder = ['holder_kind' => $kind, 'hr_employee_id' => null, 'associate_id' => null, 'holder_name' => null,
                    'department' => null, 'department_id' => null, 'branch' => null, 'job_title' => null];
                $employeeId = $record['approved_employee_id'];
                if ($employeeId !== null) {
                    $employee = $employees[$employeeId] ?? null;
                    if (!$employee) throw new ValidationException('A selected HR employee no longer exists. Upload again.');
                    $holder = ['holder_kind' => 'employee', 'hr_employee_id' => $employeeId, 'holder_name' => $employee['name'],
                        'department' => $employee['department'], 'department_id' => $employee['department_id'],
                        'branch' => $employee['branch'], 'job_title' => $employee['job_title']];
                } elseif ($kind === 'associate') {
                    $associate = (new Associates($this->db))->find(self::positiveInt($record['associate_id'] ?? null, 'associate ID'));
                    $holder['associate_id'] = (int) $associate['id'];
                    $holder['holder_name'] = $associate['name'];
                    $holder['job_title'] = $associate['job_title'];
                    $holder['branch'] = $associate['branch'];
                } elseif ($kind === 'shared') {
                    $department = $exchange && $record['department_id'] !== null
                        ? $hr->department(self::positiveInt($record['department_id'], 'department ID'))
                        : $departments->required(self::text($record, 'department', true));
                    $holder['department'] = $department['name'];
                    $holder['department_id'] = $department['id'];
                } elseif ($kind !== 'unassigned') {
                    $holder['holder_name'] = self::text($record, 'holder_name');
                    $holder['holder_kind'] = $holder['holder_name'] === null ? 'unassigned' : 'imported';
                    $department = $record['department'] ? $departments->required($record['department']) : null;
                    $holder['department'] = $department['name'] ?? null;
                    $holder['department_id'] = $department['id'] ?? null;
                    $holder['branch'] = self::text($record, 'branch');
                    $holder['job_title'] = self::text($record, 'job_title');
                }
                $values = array_merge($values, $holder);
                if ($exchange) {
                    $date = self::text($record, 'handover_date');
                    if ($date !== null) self::validateDate($date);
                    $values['handover_date'] = $holder['holder_kind'] === 'unassigned' ? null : $date;
                }
                if ($before) {
                    $this->update($id, $record['target_version'], $values);
                    $updated++;
                } else {
                    $values['import_key'] = $record['import_key'];
                    $values['source_json'] = json_encode($record['source'], JSON_THROW_ON_ERROR);
                    $id = $this->insert($values);
                    $added++;
                }
                $this->event($id, $before ? 'import-update' : 'import',
                    ($before ? 'Updated' : 'Imported') . ' from approved Excel group; ' . $record['source']['sheet'] . ' row ' . $record['source']['row'] . '.',
                    before: $before, incoming: $record['source']);
            }
            return ['added' => $added, 'updated' => $updated];
            });
        } catch (\PDOException $error) {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                && in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1213, 1205], true)) {
                throw new ValidationException('Inventory changed during import. Upload again to review current devices.', 409);
            }
            throw $error;
        }
    }

    public function locateImported(array $records): array
    {
        return $this->transaction(function () use ($records): array {
            $located = 0;
            $query = $this->db->prepare('SELECT id FROM inv_devices WHERE import_key = ?');
            foreach ($records as $record) {
                $query->execute([$record['import_key']]);
                $id = $query->fetchColumn();
                if ($id === false) continue;
                $before = $this->device((int) $id);
                if ($before['lifecycle_status'] !== 'active') continue;
                $values = [];
                if ($before['location_branch_id'] === null && !$before['location']) {
                    $values['location_branch_id'] = $record['location_branch_id'];
                    $values['location'] = $record['location'];
                    $located++;
                }
                if (!$values) continue;
                $this->update((int) $id, $before['version'], $values);
                $this->event((int) $id, 'location', 'Location set to ' . $values['location'] . '.', before: $before);
            }
            return ['located' => $located];
        });
    }

    private static function validateDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (int) $parsed->format('Y') < 1000) {
            throw new ValidationException('Use a valid handover date in YYYY-MM-DD format.');
        }
    }

    public function normalizeDepartments(DepartmentNames $names): array
    {
        return $this->transaction(function () use ($names): array {
            $updated = 0;
            foreach ($this->devices() as $before) {
                if ($before['lifecycle_status'] !== 'active') continue;
                if (!$before['department']) continue;
                $department = $names->required($names->display($before)['department']);
                if ($before['department_id'] === (int) $department['id'] && $before['department'] === $department['name']) continue;
                $this->update($before['id'], $before['version'], ['department_id' => $department['id'], 'department' => $department['name']]);
                $this->event($before['id'], 'department', 'Department standardized to ' . $department['name'] . '.', before: $before);
                $updated++;
            }
            return ['updated' => $updated];
        });
    }

    public function setEquipmentBaseline(string $date): array
    {
        self::validateDate($date);
        return $this->transaction(function () use ($date): array {
            $updated = $skipped = 0;
            foreach ($this->devices() as $before) {
                if ($before['lifecycle_status'] !== 'active') continue;
                if ($before['device_condition'] === 'Good' && $before['handover_date'] === $date) {
                    $skipped++;
                    continue;
                }
                $this->update($before['id'], $before['version'], ['device_condition' => 'Good', 'handover_date' => $date]);
                $this->event($before['id'], 'baseline', 'Equipment condition set to Good; handover date set to ' . $date . '.', $date, $before);
                $updated++;
            }
            return ['updated' => $updated, 'skipped' => $skipped, 'condition' => 'Good', 'handover_date' => $date];
        });
    }
}
