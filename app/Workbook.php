<?php
declare(strict_types=1);

namespace Inventory;

use ZipArchive;

// A reader for this workbook's three sheets, not a general spreadsheet engine.
final class Workbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public static function sheets(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ValidationException('Cannot open this XLSX workbook.');
        }
        try {
            if ($zip->numFiles > 256) throw new ValidationException('Workbook has too many entries.');
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) $expanded += $zip->statIndex($i)['size'];
            if ($expanded > 32 * 1024 * 1024) throw new ValidationException('Workbook is too large when expanded.');
            $xml = static function (string $entry) use ($zip): \SimpleXMLElement {
                $value = $zip->getFromName($entry);
                $node = $value === false ? false : @simplexml_load_string($value, \SimpleXMLElement::class, LIBXML_NONET);
                if ($node === false) {
                    throw new ValidationException('Invalid or missing workbook XML: ' . $entry);
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
        if (isset($sheets['Devices'])) return WorkbookExchange::records($sheets['Devices']);
        $pcColumns = self::pcColumns($sheets['PC'][1] ?? []);
        foreach (['Printer' => ['A' => 'BRAND', 'E' => 'SERIAL NUMBER'],
            'Laptop' => ['A' => 'NAME', 'G' => 'LAPTOP BRAND', 'H' => 'LAPTOP SERIAL NO#']] as $sheet => $headers) {
            foreach ($headers as $column => $header) {
                if (strtoupper(trim($sheets[$sheet][1][$column] ?? '')) !== $header) {
                    throw new ValidationException("Unexpected $sheet header in column $column; review the import mapping.");
                }
            }
        }
        $records = [];
        $add = static function (string $sheet, int $row, string $component, array $cells, array $data) use (&$records, $sheets, $pcColumns): void {
            // Keep every additional column, including software, alongside the hardware mapping.
            $mapped = $sheet === 'PC' ? array_values($pcColumns) : ($sheet === 'Laptop' ? range('A', 'J') : ['A', 'B', 'C', 'D', 'E', 'G']);
            $specs = $data['specifications'] ?? [];
            if (in_array($component, ['Desktop', 'Laptop', 'Printer'], true)) {
                $headers = $sheets[$sheet][1];
                $counts = array_count_values(array_map(static fn (string $v): string => mb_strtoupper(trim($v)), $headers));
                foreach (array_unique([...array_keys($headers), ...array_keys($cells)]) as $column) {
                    if (in_array($column, $mapped, true)) continue;
                    $header = trim($headers[$column] ?? '') ?: "Column $column";
                    if (($counts[mb_strtoupper($header)] ?? 0) > 1) $header .= " ($column)";
                    $specs[$header] = $cells[$column] ?? '';
                }
            }
            $data['specifications'] = $specs;
            $records[] = array_merge([
                'import_key' => "inventory2026:$sheet:$row:$component",
                'device_type' => $component, 'name' => '', 'serial_number' => null, 'asset_tag' => null,
                'device_condition' => 'Unknown', 'location' => null,
                'holder_kind' => $sheet === 'Printer' ? 'shared' : (self::value($cells[$sheet === 'PC' ? $pcColumns['A'] : 'A'] ?? null) ? 'imported' : 'unassigned'),
                'hr_employee_id' => null, 'holder_name' => $sheet === 'Printer' ? null : self::value($cells[$sheet === 'PC' ? $pcColumns['A'] : 'A'] ?? null),
                'department' => self::value($cells[$sheet === 'PC' ? $pcColumns['B'] : 'B'] ?? null), 'branch' => null,
                'job_title' => $sheet === 'Printer' ? null : self::value($cells[$sheet === 'PC' ? $pcColumns['C'] : 'C'] ?? null),
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
            $pc = [];
            foreach ($pcColumns as $field => $column) $pc[$field] = $column === null ? '' : ($cells[$column] ?? '');
            if (self::value($pc['G']) || self::value($pc['E'])) {
                $add('PC', $row, 'Desktop', $cells, ['name' => 'Desktop system unit', 'asset_tag' => self::value($pc['G']),
                    'specifications' => ['CPU' => $pc['E'], 'RAM' => $pc['D'], 'Storage' => $pc['F'],
                        'OS' => $pc['W'], 'MAC address' => $pc['U']]]);
            }
            foreach (['Keyboard' => ['H', 'I', 'J'], 'Mouse' => ['K', 'L', 'M']] as $type => [$brandColumn, $serialColumn, $tagColumn]) {
                $brand = self::value($pc[$brandColumn]);
                if ($pcColumns[$tagColumn] === null) {
                    [$serial, $tag] = self::identifiers($pc[$serialColumn]);
                } else {
                    $serial = self::value(preg_replace('/^SN\s*:\s*/i', '', $pc[$serialColumn]));
                    $tag = self::value($pc[$tagColumn]);
                }
                if ($brand || $serial || $tag) {
                    $add('PC', $row, $type, $cells, ['name' => trim(($brand ?? '') . ' ' . strtolower($type)),
                        'serial_number' => $serial, 'asset_tag' => $tag]);
                }
            }
            $count = max((int) $pc['N'], self::value($pc['R']) ? 2 : (self::value($pc['Q']) ? 1 : 0));
            if ($count > 2) {
                throw new ValidationException("PC row $row lists more than two monitors; review the workbook.");
            }
            for ($monitor = 1; $monitor <= $count; $monitor++) {
                [$serial, $tag] = self::identifiers($pc[$monitor === 1 ? 'Q' : 'R']);
                $add('PC', $row, "Monitor$monitor", $cells, ['device_type' => 'Monitor',
                    'name' => trim((self::value($pc['P']) ?? '') . ' monitor'),
                    'serial_number' => $serial, 'asset_tag' => $tag,
                    'specifications' => ['Size (workbook)' => $pc['O'], 'Display' => (string) $monitor]]);
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

    private static function pcColumns(array $headers): array
    {
        // Logical slots retain the legacy component mapping; physical columns come from headers.
        $normalize = static fn (string $header): string => strtoupper(preg_replace('/\s+/', ' ', trim($header)));
        $available = [];
        foreach ($headers as $column => $header) $available[$normalize($header)][] = $column;
        $find = static function (array $names) use ($available): string {
            $matches = [];
            foreach ($names as $name) array_push($matches, ...($available[$name] ?? []));
            if (count($matches) !== 1) throw new ValidationException('PC sheet needs exactly one header for ' . implode(' or ', $names) . '.');
            return $matches[0];
        };
        $columns = [];
        foreach (['A' => 'NAME', 'B' => 'DEPARTMENT', 'C' => 'POSITION', 'D' => 'RAM', 'E' => 'CPU',
            'F' => 'STORAGE', 'G' => 'SYSTEM UNIT ASSET TAG', 'K' => 'MOUSE BRAND', 'N' => 'MONITOR COUNT',
            'O' => 'MONITOR SIZE', 'P' => 'MONITOR BRAND', 'Q' => 'MONITOR 1 ASSET TAG',
            'R' => 'MONITOR 2 ASSET TAG', 'U' => 'MAC ADDRESS', 'W' => 'OS'] as $field => $header) {
            $columns[$field] = $find([$header]);
        }
        $columns['H'] = $find(['KEY BOARD BRAND', 'KEYBOARD BRAND']);
        foreach (['KEYBOARD' => ['I', 'J'], 'MOUSE' => ['L', 'M']] as $type => [$serial, $tag]) {
            $columns[$serial] = $find(["$type SERIAL NO#", "$type SERIAL NO#/ASSET TAG"]);
            $combined = $normalize($headers[$columns[$serial]]) === "$type SERIAL NO#/ASSET TAG";
            $columns[$tag] = $combined ? null : $find(["$type ASSET TAG"]);
            if ($combined && isset($available["$type ASSET TAG"])) throw new ValidationException("PC sheet mixes combined and separate $type identifier columns.");
        }
        return $columns;
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
