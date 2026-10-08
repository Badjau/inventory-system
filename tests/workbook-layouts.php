<?php
declare(strict_types=1);

function workbookLayoutTests(string $baselinePath, array $baseline, Inventory\InventoryService $service, Inventory\HrDirectory $hr): void
{
    $rows = Inventory\Workbook::sheets($baselinePath)['PC'];
    $column = static function (int $index): string {
        $result = '';
        do { $result = chr(65 + $index % 26) . $result; $index = intdiv($index, 26) - 1; } while ($index >= 0);
        return $result;
    };
    $moves = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E', 'F' => 'F', 'G' => 'G', 'H' => 'H',
        'K' => 'J', 'N' => 'L', 'O' => 'M', 'P' => 'N', 'Q' => 'O', 'R' => 'P'];
    for ($index = 18; $index < 44; $index++) $moves[$column($index)] = $column($index - 2);
    $combined = [];
    foreach ($rows as $row => $cells) {
        foreach ($moves as $old => $new) if (isset($cells[$old])) $combined[$row][$new] = $cells[$old];
        foreach (['KEYBOARD' => ['I', 'J', 'I'], 'MOUSE' => ['L', 'M', 'K']] as $type => [$serial, $tag, $target]) {
            $combined[$row][$target] = $row === 1 ? "$type  SERIAL NO#/ASSET TAG" :
                ($cells[$serial] ?? 'NA') . '/' . ($cells[$tag] ?? 'NA');
        }
    }
    $writeSheet = static function (array $rows): string {
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $row => $cells) {
            $xml .= '<row r="' . $row . '">';
            foreach ($cells as $column => $value) $xml .= '<c r="' . $column . $row . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    };
    $path = tempnam(__DIR__, 'inventory-layout-');
    if ($path === false) throw new RuntimeException('Cannot create layout test fixture.');
    try {
        copy($baselinePath, $path);
        $zip = new ZipArchive(); $zip->open($path); $zip->addFromString('xl/worksheets/sheet2.xml', $writeSheet($combined)); $zip->close();
        $parsed = Inventory\Workbook::records($path);
        check(Inventory\Workbook::summary($parsed) === Inventory\Workbook::summary($baseline), 'Combined accessory columns retain device counts, identifiers, and stable source keys');
        foreach ($baseline as $index => $record) {
            $actual = $parsed[$index]; unset($record['source'], $actual['source']);
            check($record === $actual, 'Both PC layouts produce the same devices and all software specifications');
        }
        $pc = array_values(array_filter($parsed, static fn ($r) => $r['import_key'] === 'inventory2026:PC:2:Desktop'))[0];
        check($pc['source']['cells']['U'] === $pc['specifications']['OS'], 'Combined layout preserves original column cells, without rewriting source');
        $preview = Inventory\ImportPreview::create([$pc], $service->devices(), $hr->all());
        check($preview['groups'][0]['records'][0]['operation'] === 'Update', 'A layout change updates the same existing device by source key');
        $before = $service->device($preview['groups'][0]['records'][0]['target_id']);
        $approved = Inventory\ImportPreview::selections($preview, ['token' => $preview['token'],
            'groups' => [['id' => 1, 'hr_employee_id' => $preview['groups'][0]['hr_employee_id']]]]);
        $service->applyImport($approved, $hr);
        $after = $service->device($before['id']);
        check($after['source'] === $before['source'] && $after['id'] === $before['id'], 'Updating from the combined layout preserves internal IDs and original source');
        // Ambiguous or missing hardware headers fail instead of reading the wrong columns.
        $bad = $combined; $bad[1]['AS'] = 'OS';
        $zip->open($path); $zip->addFromString('xl/worksheets/sheet2.xml', $writeSheet($bad)); $zip->close();
        rejects(static fn () => Inventory\Workbook::records($path));
        $bad = $combined; unset($bad[1]['S']);
        $zip->open($path); $zip->addFromString('xl/worksheets/sheet2.xml', $writeSheet($bad)); $zip->close();
        rejects(static fn () => Inventory\Workbook::records($path));
        // Verify the user's actual workbook separately from the fixed historical fixture.
        $current = Inventory\Workbook::records(dirname(__DIR__) . '/INVENTORY 2026.xlsx');
        $headers = Inventory\Workbook::sheets(dirname(__DIR__) . '/INVENTORY 2026.xlsx')['PC'][1];
        $normalized = [];
        foreach ($headers as $field => $header) $normalized[strtoupper(preg_replace('/\s+/', ' ', trim($header)))] = $field;
        foreach ($current as $record) {
            if ($record['device_type'] !== 'Desktop') continue;
            foreach (['OS' => 'OS', 'MAC ADDRESS' => 'MAC address', 'LICENSE' => 'LICENSE', 'MICROSOFT 365' => 'MICROSOFT 365', 'SAP' => 'SAP', 'VIBER' => 'VIBER'] as $header => $spec) {
                check($record['specifications'][$spec] === ($record['source']['cells'][$normalized[$header]] ?? ''), 'Current workbook maps each hardware/software column by header');
            }
        }
    } finally { unlink($path); }
}
