<?php
declare(strict_types=1);

namespace Inventory;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

final class WorkbookDepartmentCleanup
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public static function plan(string $path, DepartmentNames $names): array
    {
        $changes = $unresolved = [];
        foreach (Workbook::sheets($path) as $sheet => $rows) {
            foreach ($rows as $row => $cells) {
                if ($row === 1 || Workbook::value($cells['B'] ?? null) === null) continue;
                $old = $cells['B'];
                $department = $names->resolve($old);
                if (!$department) {
                    $unresolved[$old] = true;
                } elseif ($old !== $department['name']) {
                    $changes[] = ['sheet' => $sheet, 'row' => $row, 'from' => $old, 'to' => $department['name']];
                }
            }
        }
        return ['changes' => $changes, 'unresolved' => array_keys($unresolved)];
    }

    public static function apply(string $path, array $plan, ?string $output = null): ?string
    {
        if ($plan['unresolved']) throw new ValidationException('Resolve these Excel departments first: ' . implode(', ', $plan['unresolved']));
        if (!$plan['changes']) return null;
        $output ??= $path;
        $backup = $path . '.before-department-cleanup.xlsx';
        if ($output === $path) {
            $sourceHash = hash_file('sha256', $path);
            $revision = 1;
            while (is_file($backup) && hash_file('sha256', $backup) !== $sourceHash) {
                $backup = $path . '.before-department-cleanup.' . ++$revision . '.xlsx';
            }
        }
        $temporary = $path . '.department-cleanup.tmp.xlsx';
        if (is_file($temporary)) throw new RuntimeException('A department cleanup temporary file already exists.');
        if (!copy($path, $temporary)) throw new RuntimeException('Cannot create temporary workbook.');
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($temporary) !== true) throw new RuntimeException('Cannot open temporary workbook.');
            $opened = true;
            $entries = [];
            $relations = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
            foreach ($relations->children('http://schemas.openxmlformats.org/package/2006/relationships') as $relation) {
                $target = (string) $relation->attributes()['Target'];
                $entries[(string) $relation->attributes()['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
            $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
            foreach ($workbook->children(self::NS)->sheets->sheet as $sheet) {
                $sheetName = (string) $sheet->attributes()['name'];
                $sheetChanges = array_filter($plan['changes'], static fn (array $change): bool => $change['sheet'] === $sheetName);
                if (!$sheetChanges) continue;
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                $entry = $entries[$id];
                $document = new DOMDocument();
                if (!$document->loadXML($zip->getFromName($entry), LIBXML_NONET)) throw new RuntimeException('Invalid worksheet XML.');
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('s', self::NS);
                foreach ($sheetChanges as $change) {
                    $cell = $xpath->query('//s:sheetData/s:row/s:c[@r="B' . (int) $change['row'] . '"]')->item(0);
                    if (!$cell) throw new RuntimeException('Department cell missing from worksheet.');
                    while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                    $cell->setAttribute('t', 'inlineStr');
                    $inline = $document->createElementNS(self::NS, 'is');
                    $text = $document->createElementNS(self::NS, 't');
                    $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                    $text->appendChild($document->createTextNode($change['to']));
                    $inline->appendChild($text);
                    $cell->appendChild($inline);
                }
                if (!$zip->addFromString($entry, $document->saveXML())) throw new RuntimeException('Cannot update worksheet.');
            }
            if (!$zip->close()) throw new RuntimeException('Cannot save temporary workbook.');
            $opened = false;
            $expected = Workbook::sheets($path);
            foreach ($plan['changes'] as $change) $expected[$change['sheet']][$change['row']]['B'] = $change['to'];
            $actual = Workbook::sheets($temporary);
            if ($actual !== $expected) {
                foreach ($expected as $sheet => $rows) {
                    foreach ($rows as $row => $cells) {
                        foreach ($cells as $column => $value) {
                            if (($actual[$sheet][$row][$column] ?? null) !== $value) {
                                $details = $column === 'B' ? ' (' . json_encode(['expected' => $value, 'actual' => $actual[$sheet][$row][$column] ?? null]) . ')' : '';
                                throw new RuntimeException("Workbook verification failed at $sheet row $row column $column$details; original file was kept.");
                            }
                        }
                    }
                }
                throw new RuntimeException('Workbook verification failed; original file was kept.');
            }
            if ($output === $path && !is_file($backup) && !copy($path, $backup)) throw new RuntimeException('Cannot back up original workbook.');
            if (!rename($temporary, $output)) throw new RuntimeException('Cannot replace workbook. Close Excel and retry.');
            return $output === $path ? $backup : $path;
        } finally {
            if ($opened) $zip->close();
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
