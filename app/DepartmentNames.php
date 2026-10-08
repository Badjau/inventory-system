<?php
declare(strict_types=1);

namespace Inventory;

// Formatting variants may match; abbreviations require an explicit mapping.
final class DepartmentNames
{
    public function __construct(private array $departments, private array $aliases = []) {}

    public static function key(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($name));
    }

    public static function configured(array $departments): self
    {
        $path = dirname(__DIR__) . '/data/department-aliases.json';
        return new self($departments, is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : []);
    }

    public function resolve(?string $name): ?array
    {
        if ($name === null || trim($name) === '') return null;
        $key = self::key($name);
        foreach ($this->aliases as $alias => $target) {
            if (!is_string($target)) throw new ValidationException('Department mappings must use HR department names.');
            if (self::key((string) $alias) === $key) {
                $key = self::key($target);
                break;
            }
        }
        $matches = array_values(array_filter($this->departments,
            static fn (array $department): bool => self::key($department['name']) === $key));
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function required(string $name): array
    {
        return $this->resolve($name) ?? throw new ValidationException('No unique HR department match for: ' . $name);
    }

    public function display(array $device): array
    {
        if (!empty($device['department_id'])) {
            foreach ($this->departments as $department) {
                if ((int) $department['id'] === (int) $device['department_id']) {
                    return [...$device, 'department' => $department['name']];
                }
            }
            return $device; // Retain the last snapshot if HR removes the department.
        }
        $department = $this->resolve($device['department'] ?? null);
        return $department ? [...$device, 'department' => $department['name'], 'department_id' => $department['id'] ?? null] : $device;
    }
}
