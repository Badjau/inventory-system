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

    public function __construct(private PDO $db) {}

    public function devices(): array
    {
        return array_map($this->decode(...), $this->db->query('SELECT * FROM inv_devices ORDER BY id')->fetchAll());
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
        $query = $this->db->prepare('SELECT id, device_id, event_type, message, handover_date, recorded_at
            FROM inv_events' . ($deviceId === null ? ' ORDER BY id DESC LIMIT 100' : ' WHERE device_id = ? ORDER BY id DESC'));
        $query->execute($deviceId === null ? [] : [$deviceId]);
        return $query->fetchAll();
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
        if (!in_array($type, self::TYPES, true) || !in_array($condition, self::CONDITIONS, true)) {
            throw new ValidationException('Choose a supported device type and condition.');
        }
        $specs = $input['specifications'] ?? [];
        if (!is_array($specs) || count($specs) > 20) {
            throw new ValidationException('Invalid specifications.');
        }
        $clean = [];
        foreach ($specs as $key => $value) {
            if (!is_string($key) || !is_string($value) || mb_strlen($key) > 60 || mb_strlen($value) > 255) {
                throw new ValidationException('Invalid specification name or value.');
            }
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
        $query = $this->db->prepare("UPDATE inv_devices SET $assignments, version = version + 1,
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

    private function event(int $id, string $type, string $message, ?string $date = null, ?array $before = null): void
    {
        $query = $this->db->prepare('INSERT INTO inv_events
            (device_id, event_type, message, handover_date, snapshot_json) VALUES (?, ?, ?, ?, ?)');
        $query->execute([$id, $type, $message, $date,
            json_encode(['before' => $before, 'after' => $this->device($id)], JSON_THROW_ON_ERROR)]);
    }

    public function save(array $input): array
    {
        $values = $this->editable($input);
        return $this->transaction(function () use ($input, $values): array {
            if (array_key_exists('id', $input)) {
                $id = self::positiveInt($input['id'], 'device ID');
                $version = self::positiveInt($input['version'] ?? null, 'version');
                $before = $this->device($id);
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
                $this->event($id, 'edit', $message, before: $before);
            } else {
                $id = $this->insert($values);
                $this->event($id, 'create', 'Device registered; available for assignment.');
            }
            return $this->device($id);
        });
    }

    public function assign(array $input, HrDirectory $hr): array
    {
        $id = self::positiveInt($input['id'] ?? null, 'device ID');
        $version = self::positiveInt($input['version'] ?? null, 'version');
        $kind = self::text($input, 'holder_kind', true);
        $date = self::text($input, 'handover_date');
        if ($date !== null) self::validateDate($date);
        $notes = self::text($input, 'notes', max: 2000);
        $values = ['holder_kind' => $kind, 'hr_employee_id' => null, 'holder_name' => null,
            'department' => null, 'department_id' => null, 'branch' => null, 'job_title' => null,
            'handover_date' => $kind === 'unassigned' ? null : $date];
        if ($kind === 'employee') {
            $employee = $hr->find(self::positiveInt($input['hr_employee_id'] ?? null, 'employee ID'));
            $values = array_merge($values, ['hr_employee_id' => (int) $employee['id'], 'holder_name' => $employee['name'],
                'department' => $employee['department'], 'department_id' => $employee['department_id'],
                'branch' => $employee['branch'], 'job_title' => $employee['job_title']]);
            $target = $employee['name'];
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
        return $this->transaction(function () use ($id, $version, $kind, $values, $target, $date, $notes): array {
            $before = $this->device($id);
            $previous = $before['holder_kind'] === 'shared' ? $before['department'] : ($before['holder_name'] ?? 'unassigned pool');
            $this->update($id, $version, $values);
            $this->event($id, $kind === 'unassigned' ? 'return' : 'assign',
                "Holder changed from $previous to $target." . ($notes ? " $notes" : ''), $date, $before);
            return $this->device($id);
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
