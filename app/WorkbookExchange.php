<?php
declare(strict_types=1);

namespace Inventory;

use ZipArchive;

// A small, string-only XLSX interchange format. No spreadsheet formulas are generated.
final class WorkbookExchange
{
    private const FIELDS = ['id' => 'Inventory ID', 'import_key' => 'Source key', 'device_type' => 'Device type',
        'name' => 'Device name', 'lifecycle_status' => 'Lifecycle status', 'serial_number' => 'Serial number', 'asset_tag' => 'Asset tag',
        'device_condition' => 'Condition', 'holder_kind' => 'Holder kind', 'hr_employee_id' => 'HR employee ID', 'associate_id' => 'Associate ID',
        'holder_name' => 'Recorded holder', 'department_id' => 'Department ID', 'department' => 'Department',
        'branch' => 'Holder branch', 'job_title' => 'Job title', 'location_branch_id' => 'Location branch ID',
        'location' => 'Location', 'handover_date' => 'Handover date'];

    public static function records(array $rows): array
    {
        $headers = $rows[1] ?? [];
        if (count($headers) !== count(array_unique($headers))) throw new ValidationException('Duplicate export headers.');
        $columns = array_flip($headers);
        foreach (self::FIELDS as $field => $header) {
            if (in_array($field, ['associate_id', 'lifecycle_status'], true)) continue; // Older exports remain valid.
            if (!isset($columns[$header])) throw new ValidationException("Missing export column: $header.");
        }
        $records = [];
        foreach ($rows as $row => $cells) {
            if ($row === 1) continue;
            $record = [];
            foreach (self::FIELDS as $field => $header) $record[$field] = isset($columns[$header]) ? ($cells[$columns[$header]] ?? null) : null;
            foreach (['id', 'hr_employee_id', 'associate_id', 'department_id', 'location_branch_id'] as $field) {
                if ($record[$field] !== null) $record[$field] = InventoryService::positiveInt($record[$field], $field);
            }
            $record['target_id'] = $record['id'] === null ? null : InventoryService::positiveInt($record['id'], 'inventory ID');
            unset($record['id']);
            $record['specifications'] = [];
            foreach ($headers as $column => $header) {
                if (str_starts_with($header, 'Spec: ')) $record['specifications'][substr($header, 6)] = $cells[$column] ?? '';
                elseif (!in_array($header, self::FIELDS, true)) throw new ValidationException("Unknown export column: $header.");
            }
            $record['source'] = ['sheet' => 'Devices', 'row' => $row, 'cells' => $cells];
            $record['exchange'] = true;
            $records[] = $record;
        }
        return $records;
    }

    private static function column(int $number): string
    {
        $column = '';
        do {
            $column = chr(65 + $number % 26) . $column;
            $number = intdiv($number, 26) - 1;
        } while ($number >= 0);
        return $column;
    }

    public static function write(string $path, array $devices): void
    {
        $specKeys = [];
        foreach ($devices as $device) foreach ($device['specifications'] as $key => $_) $specKeys[$key] = true;
        $keys = array_keys($specKeys);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
        $rows = [[...array_values(self::FIELDS), ...array_map(static fn (string $key): string => 'Spec: ' . $key, $keys)]];
        foreach ($devices as $device) {
            $rows[] = [...array_map(static fn (string $key): mixed => $device[$key] ?? '', array_keys(self::FIELDS)),
                ...array_map(static fn (string $key): string => $device['specifications'][$key] ?? '', $keys)];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $values) {
            $row = $index + 1;
            $xml .= '<row r="' . $row . '">';
            foreach ($values as $column => $value) {
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', (string) $value)) throw new ValidationException('A device contains control characters that Excel cannot store. Edit the device before exporting.');
                $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . self::column($column) . $row . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Cannot create export.');
        try {
            $entries = [
                '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
                '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Devices" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
                'xl/worksheets/sheet1.xml' => $xml,
            ];
            foreach ($entries as $name => $content) if (!$zip->addFromString($name, $content)) throw new \RuntimeException('Cannot write export.');
        } finally {
            if (!$zip->close()) throw new \RuntimeException('Cannot finish export.');
        }
    }
}
