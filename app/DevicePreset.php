<?php
declare(strict_types=1);

namespace Inventory;

/** Ordered creation templates, independent of inventory records and HR. */
final class DevicePreset
{
    public const DESKTOP = ['MAC ADDRESS', 'RAM', 'CPU', 'STORAGE', 'SYSTEM UNIT ASSET TAG', 'LICENSE', 'MICROSOFT 365', 'DATE PURCHASED', 'OS', 'ANTI VIRUS', 'SAP', 'ADOBE ACROBAT', 'ADOBE PHOTOSHOP', 'ADOBE ILLUSTRATOR', 'ADOBE AFTEREFFECTS', 'ADOBE PREMIRE', 'VIBER', 'LINE', 'WHATSAPP', 'BRAVE', 'GOOGLE', 'MICROSOFT TEAMS', 'CAPCUT', 'CANVA', 'HP SMART', 'RELIEF VALIDATION', 'RELIEF DATABASE', 'RELIEF DATA ENTRY', 'ALPHALIST DATA ENTRY', 'ALPHALIST VALIDATION'];

    public function __construct(private readonly string $path) {}

    public function read(): array
    {
        $data = is_file($this->path) ? json_decode(file_get_contents($this->path), true, 32, JSON_THROW_ON_ERROR) :
            ['version' => 0, 'fields' => array_map(static fn ($name) => ['name' => $name, 'value' => ''], self::DESKTOP)];
        $types = $data['device_types'] ?? InventoryService::TYPES;
        $presets = [];
        foreach ($types as $type) {
            if ($type === 'Other') continue;
            $presets[$type] = self::validate($data['presets'][$type] ?? ($type === 'Desktop' ? $data['fields'] : []));
        }
        return ['version' => $data['version'], 'fields' => $presets['Desktop'], 'device_types' => $types, 'presets' => $presets];
    }

    public static function validate(mixed $fields): array
    {
        if (!is_array($fields) || !array_is_list($fields) || count($fields) > 128) throw new ValidationException('Use up to 128 preset fields.');
        $seen = [];
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null) || !is_string($field['value'] ?? null)) throw new ValidationException('Each preset field needs a name and value.');
            $name = trim($field['name']);
            $value = trim($field['value']);
            $key = mb_strtolower($name);
            if ($name === '' || mb_strlen($name) > 100 || mb_strlen($value) > 4000) throw new ValidationException('Preset names must be 1–100 characters and values at most 4,000 characters.');
            if (isset($seen[$key])) throw new ValidationException('Repeated preset specification: ' . $name);
            $seen[$key] = true;
            $result[] = ['name' => $name, 'value' => $value];
        }
        return $result;
    }

    public function save(array $input): array
    {
        $creatingType = array_key_exists('name', $input);
        $fields = $creatingType ? null : self::validate($input['fields'] ?? null);
        if ($creatingType && (!is_string($input['name']) || trim($input['name']) === '' || mb_strlen(trim($input['name'])) > 30 || preg_match('/[\x00-\x1f\x7f]/u', $input['name']))) {
            throw new ValidationException('Device type names must be 1–30 characters without control characters.');
        }
        if (!is_int($input['version'] ?? null) || $input['version'] < 0) throw new ValidationException('Invalid preset version.');
        $lock = fopen($this->path . '.lock', 'c');
        if (!$lock) throw new \RuntimeException('Cannot open preset lock.');
        $temp = false;
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock preset.');
            $current = $this->read();
            if ($current['version'] !== $input['version']) throw new ValidationException('Preset changed elsewhere. Refresh before saving.', 409);
            $types = $current['device_types'];
            if ($creatingType) {
                $name = trim($input['name']);
                foreach ($types as $type) {
                    if (mb_strtolower($type) === mb_strtolower($name)) throw new ValidationException('That device type already exists.');
                }
                if (count($types) >= 128) throw new ValidationException('Use up to 128 device types.');
                $types[] = $name;
            }
            $presets = $current['presets'];
            if ($creatingType) $presets[$name] = [];
            else {
                $type = $input['device_type'] ?? 'Desktop';
                if (!is_string($type) || !array_key_exists($type, $presets)) throw new ValidationException('Choose an existing device preset.');
                $presets[$type] = $fields;
            }
            $data = ['version' => $current['version'] + 1, 'fields' => $presets['Desktop'], 'device_types' => $types, 'presets' => $presets];
            $temp = tempnam(dirname($this->path), 'preset-');
            if ($temp === false || file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false || !rename($temp, $this->path)) throw new \RuntimeException('Cannot save preset.');
            return $data;
        } finally {
            if ($temp && is_file($temp)) unlink($temp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
