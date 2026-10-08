<?php
declare(strict_types=1);

namespace Inventory;

use RuntimeException;
use ZipArchive;

// A reader for this workbook's three sheets, not a general spreadsheet engine.
final class Workbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public static function sheets(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open workbook: ' . basename($path));
        }
        try {
            if ($zip->numFiles > 256) throw new ValidationException('Workbook has too many entries.');
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) $expanded += $zip->statIndex($i)['size'];
            if ($expanded > 32 * 1024 * 1024) throw new ValidationException('Workbook is too large when expanded.');
            $xml = static function (string $entry) use ($zip): \SimpleXMLElement {
                $value = $zip->getFromName($entry);
                $node = $value === false ? false : simplexml_load_string($value, \SimpleXMLElement::class, LIBXML_NONET);
                if ($node === false) {
                    throw new RuntimeException('Invalid or missing workbook XML: ' . $entry);
                }
                return $node;
            };
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($xml('xl/sharedStrings.xml')->children(self::NS)->si as $item) {
                    $item->registerXPathNamespace('s', self::NS);
                    $strings[] = implode('', array_map('strval', $item->xpath('.//s:t')));
                }
            }
            $relations = [];
            foreach ($xml('xl/_rels/workbook.xml.rels')->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
                $target = (string) $rel->attributes()['Target'];
                $relations[(string) $rel->attributes()['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
            $sheets = [];
            foreach ($xml('xl/workbook.xml')->children(self::NS)->sheets->sheet as $sheet) {
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $rows = [];
                foreach ($xml($relations[$id])->children(self::NS)->sheetData->row as $row) {
                    $cells = [];
                    foreach ($row->c as $cell) {
                        $column = preg_replace('/\d+/', '', (string) $cell->attributes()['r']);
                        $value = (string) $cell->v;
                        if ((string) $cell->attributes()['t'] === 's') {
                            $value = $strings[(int) $value] ?? '';
                        } elseif ((string) $cell->attributes()['t'] === 'inlineStr') {
                            $cell->registerXPathNamespace('s', self::NS);
                            $value = implode('', array_map('strval', $cell->xpath('.//s:t')));
                        }
                        if (trim($value) !== '') {
                            $cells[$column] = trim($value);
                        }
                    }
                    if ($cells) {
                        $rows[(int) $row->attributes()['r']] = $cells;
                    }
                }
                $sheets[(string) $sheet->attributes()['name']] = $rows;
            }
            return $sheets;
        } finally {
            $zip->close();
        }
    }

    public static function value(?string $value): ?string
    {
        $value = trim($value ?? '');
        return in_array(strtoupper($value), ['', 'NONE', 'NA', 'N/A', 'N.A.', '-', 'NO ASSET TAG'], true) ? null : $value;
    }

    public static function identifiers(?string $value): array
    {
        $serial = $tag = null;
        foreach (explode('/', $value ?? '') as $part) {
            $part = self::value($part);
            if ($part === null) {
                continue;
            }
            if (str_starts_with(strtoupper($part), 'CH-')) {
                $tag = $part;
            } else {
                $serial = self::value(preg_replace('/^SN\s*:\s*/i', '', $part));
            }
        }
        return [$serial, $tag];
    }

    public static function records(string $path): array
    {
        $sheets = self::sheets($path);
        foreach (['Printer' => ['A' => 'BRAND', 'E' => 'SERIAL NUMBER'],
            'PC' => ['A' => 'NAME', 'B' => 'DEPARTMENT', 'C' => 'POSITION',
                'D' => 'RAM', 'E' => 'CPU', 'F' => 'STORAGE', 'G' => 'SYSTEM UNIT ASSET TAG',
                'H' => 'KEY BOARD BRAND', 'I' => 'KEYBOARD SERIAL NO#', 'J' => 'KEYBOARD ASSET TAG',
                'K' => 'MOUSE BRAND', 'L' => 'MOUSE SERIAL NO#', 'M' => 'MOUSE ASSET TAG',
                'N' => 'MONITOR COUNT', 'O' => 'MONITOR SIZE', 'P' => 'MONITOR BRAND',
                'Q' => 'MONITOR 1 ASSET TAG', 'R' => 'MONITOR 2 ASSET TAG',
                'U' => 'MAC ADDRESS', 'W' => 'OS'],
            'Laptop' => ['A' => 'NAME', 'G' => 'LAPTOP BRAND', 'H' => 'LAPTOP SERIAL NO#']] as $sheet => $headers) {
            foreach ($headers as $column => $header) {
                if (strtoupper(trim($sheets[$sheet][1][$column] ?? '')) !== $header) {
                    throw new RuntimeException("Unexpected $sheet header in column $column; review the import mapping.");
                }
            }
        }
        $records = [];
        $add = static function (string $sheet, int $row, string $component, array $cells, array $data) use (&$records, $sheets): void {
            // Keep every additional column, including software, alongside the hardware mapping.
            $mapped = $sheet === 'PC' ? range('A', 'R') : ($sheet === 'Laptop' ? range('A', 'J') : ['A', 'B', 'C', 'D', 'E', 'G']);
            $specs = $data['specifications'] ?? [];
            foreach ($sheets[$sheet][1] as $column => $header) {
                if (!in_array($column, $mapped, true) && self::value($cells[$column] ?? null) !== null) {
                    $specs[$header] = $cells[$column];
                }
            }
            $data['specifications'] = $specs;
            $records[] = array_merge([
                'import_key' => "inventory2026:$sheet:$row:$component",
                'device_type' => $component, 'name' => '', 'serial_number' => null, 'asset_tag' => null,
                'device_condition' => 'Unknown', 'location' => null,
                'holder_kind' => $sheet === 'Printer' ? 'shared' : (self::value($cells['A'] ?? null) ? 'imported' : 'unassigned'),
                'hr_employee_id' => null, 'holder_name' => $sheet === 'Printer' ? null : self::value($cells['A'] ?? null),
                'department' => self::value($cells['B'] ?? null), 'branch' => null,
                'job_title' => $sheet === 'Printer' ? null : self::value($cells['C'] ?? null),
                'specifications' => [], 'source' => ['sheet' => $sheet, 'row' => $row, 'cells' => $cells],
            ], $data);
        };
        foreach ($sheets['Printer'] as $row => $cells) {
            if ($row === 1 || !self::value($cells['A'] ?? null)) {
                continue;
            }
            $add('Printer', $row, 'Printer', $cells, ['name' => $cells['A'], 'serial_number' => self::value($cells['E'] ?? null),
                'specifications' => ['MAC address' => $cells['C'] ?? '', 'Vendor' => $cells['D'] ?? '', 'IP address' => $cells['G'] ?? '']]);
        }
        foreach ($sheets['PC'] as $row => $cells) {
            if ($row === 1) {
                continue;
            }
            if (self::value($cells['G'] ?? null) || self::value($cells['E'] ?? null)) {
                $add('PC', $row, 'Desktop', $cells, ['name' => 'Desktop system unit', 'asset_tag' => self::value($cells['G'] ?? null),
                    'specifications' => ['CPU' => $cells['E'] ?? '', 'RAM' => $cells['D'] ?? '', 'Storage' => $cells['F'] ?? '',
                        'OS' => $cells['W'] ?? '', 'MAC address' => $cells['U'] ?? '']]);
            }
            foreach (['Keyboard' => ['H', 'I', 'J'], 'Mouse' => ['K', 'L', 'M']] as $type => [$brandColumn, $serialColumn, $tagColumn]) {
                $brand = self::value($cells[$brandColumn] ?? null);
                $serial = self::value(preg_replace('/^SN\s*:\s*/i', '', $cells[$serialColumn] ?? ''));
                $tag = self::value($cells[$tagColumn] ?? null);
                if ($brand || $serial || $tag) {
                    $add('PC', $row, $type, $cells, ['name' => trim(($brand ?? '') . ' ' . strtolower($type)),
                        'serial_number' => $serial, 'asset_tag' => $tag]);
                }
            }
            $count = max((int) ($cells['N'] ?? 0), self::value($cells['R'] ?? null) ? 2 : (self::value($cells['Q'] ?? null) ? 1 : 0));
            if ($count > 2) {
                throw new RuntimeException("PC row $row lists more than two monitors; review the workbook.");
            }
            for ($monitor = 1; $monitor <= $count; $monitor++) {
                [$serial, $tag] = self::identifiers($cells[$monitor === 1 ? 'Q' : 'R'] ?? null);
                $add('PC', $row, "Monitor$monitor", $cells, ['device_type' => 'Monitor',
                    'name' => trim((self::value($cells['P'] ?? null) ?? '') . ' monitor'),
                    'serial_number' => $serial, 'asset_tag' => $tag,
                    'specifications' => ['Size (workbook)' => $cells['O'] ?? '', 'Display' => (string) $monitor]]);
            }
        }
        foreach ($sheets['Laptop'] as $row => $cells) {
            if ($row === 1 || !self::value($cells['G'] ?? null)) {
                continue;
            }
            $add('Laptop', $row, 'Laptop', $cells, ['name' => $cells['G'], 'serial_number' => self::value($cells['H'] ?? null),
                'specifications' => ['CPU' => $cells['E'] ?? '', 'RAM' => $cells['D'] ?? '', 'Storage' => $cells['F'] ?? '',
                    'OS' => $cells['J'] ?? '', 'MAC address' => $cells['I'] ?? '']]);
        }
        return $records;
    }

    public static function summary(array $records): array
    {
        $types = [];
        $missing = [];
        $duplicates = [];
        foreach (['serial_number', 'asset_tag'] as $field) {
            $seen = [];
            foreach ($records as $record) {
                if ($record[$field]) {
                    $key = mb_strtoupper($record[$field]);
                    $seen[$key][] = $record['import_key'];
                }
            }
            $duplicates[$field] = array_filter($seen, static fn (array $keys): bool => count($keys) > 1);
        }
        foreach ($records as $record) {
            $types[$record['device_type']] = ($types[$record['device_type']] ?? 0) + 1;
            if (!$record['serial_number'] && !$record['asset_tag']) {
                $missing[] = $record['import_key'];
            }
        }
        return ['total' => count($records), 'types' => $types, 'missing_identifiers' => $missing, 'duplicate_identifiers' => $duplicates];
    }
}
