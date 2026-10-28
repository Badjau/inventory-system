<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Inventory\Database;
use Inventory\HrDirectory;
use Inventory\Http;
use Inventory\InventoryService;
use Inventory\ValidationException;
use Inventory\Workbook;
use Inventory\DepartmentNames;
use Inventory\WorkbookDepartmentCleanup;

// Default uses an in-memory SQLite fixture. --mysql uses a new temporary test
// database and executes the real MySQL DDL. Neither mode writes to HR data.
$assertions = 0;
function check(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}
function rejects(callable $callback, int $status = 422): void
{
    try {
        $callback();
    } catch (ValidationException $error) {
        check($error->status === $status, 'Unexpected validation status');
        return;
    }
    throw new RuntimeException('Expected validation to reject the operation');
}

$admin = null;
$testName = null;
try {
    $mysql = in_array('--mysql', $argv, true);
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($mysql) {
        $config = inventory_config()['database'];
        $admin = Database::connect($config);
        $candidate = 'inventory_tracking_test_' . str_replace('.', '_', uniqid('', true));
        $admin->exec("CREATE DATABASE `$candidate` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $testName = $candidate; // Assigned only after successful creation.
        $config['name'] = $testName;
        $db = Database::connect($config);
    } else {
        $db = new PDO('sqlite::memory:', null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->sqliteCreateFunction('CONCAT_WS', static fn (string $separator, ...$values): string => implode($separator, array_filter($values, static fn ($value): bool => $value !== null)));
        $schema = preg_replace('/^\s*INDEX .*\n/m', '', $schema);
        $schema = str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
        $schema = str_replace(['BIGINT UNSIGNED', 'INT UNSIGNED', ' JSON '], ['INTEGER', 'INTEGER', ' TEXT '], $schema);
        $schema = preg_replace('/\) ENGINE=.*?;/', ');', $schema);
        $schema = preg_replace('/,\s*\)/', "\n)", $schema);
    }
    foreach (explode(';', $schema) as $statement) {
        if (trim($statement) !== '') $db->exec($statement);
    }
    $db->exec("CREATE TABLE departments (id INTEGER PRIMARY KEY, name VARCHAR(255));");
    $db->exec("CREATE TABLE employees (id INTEGER PRIMARY KEY, employee_id VARCHAR(100), first_name VARCHAR(100),
        last_name VARCHAR(100), position VARCHAR(100), role VARCHAR(30), department_id INTEGER, branch VARCHAR(100), password VARCHAR(255));");
    $db->exec('CREATE TABLE branches (id INTEGER PRIMARY KEY, name VARCHAR(100));');
    $db->exec('CREATE TABLE branch_employee (id INTEGER PRIMARY KEY, employee_id INTEGER, branch_id INTEGER, is_primary INTEGER);');
    $db->exec("INSERT INTO departments VALUES (1, 'Accounting');");
    $db->exec("INSERT INTO employees VALUES (1, 'E001', 'Alex', 'Santos', 'Accountant', 'employee', 1, 'Legacy branch', 'private-value');");
    $db->exec("INSERT INTO employees VALUES (2, 'E002', 'Dana', 'Cruz', 'Manager', 'admin', NULL, 'Legacy branch', 'private-value');");
    $db->exec("INSERT INTO branches VALUES (1, 'Primary branch'), (2, 'Other branch'), (3, 'APO');");
    $db->exec('INSERT INTO branch_employee VALUES (1, 1, 2, 0), (2, 1, 1, 1);');

    if (in_array('--bulk-only', $argv, true)) {
        require __DIR__ . '/bulk.php';
        bulkTests($db, new HrDirectory($db), $mysql);
        echo "PASS: $assertions bulk assertions (" . ($mysql ? 'MySQL' : 'isolated SQLite fixture') . ").\n";
    } else {
    // Fixed baseline for count/history regressions; the working workbook is editable.
    $workbookPath = dirname(__DIR__) . '/INVENTORY 2026.xlsx.before-department-cleanup.2.xlsx';
    $records = Workbook::records($workbookPath);
    $summary = Workbook::summary($records);
    check($summary['total'] === 216, 'Workbook device total changed; review mapping');
    check($summary['types'] === ['Printer' => 9, 'Desktop' => 39, 'Keyboard' => 39, 'Mouse' => 39, 'Monitor' => 45, 'Laptop' => 45], 'Workbook type counts');
    check(count(array_unique(array_column($records, 'import_key'))) === 216, 'Import keys must be distinct');
    check(count($summary['missing_identifiers']) === 8, 'Missing IDs should be reported, not manufactured');
    check(count($summary['duplicate_identifiers']['serial_number']) === 7, 'Repeated serials should be retained');
    check(count($summary['duplicate_identifiers']['asset_tag']) === 1, 'Repeated asset tag should be retained');
    check(Workbook::identifiers('NA/CH-0000-MKTG-405') === [null, 'CH-0000-MKTG-405'], 'Combined asset tag');
    check(Workbook::identifiers('NO ASSET TAG/SN:E6705WNL') === ['E6705WNL', null], 'Monitor serial without asset tag');
    check(Workbook::identifiers('SN:PE220200/NA') === ['PE220200', null], 'Serial prefix and NA handling');
    check(Workbook::identifiers('NA/NA') === [null, null], 'Unknown placeholders');
    $sharina = array_values(array_filter($records, static fn (array $record): bool => $record['holder_name'] === 'SHARINA JOY TUAZON'));
    check(count($sharina) === 5, 'PC setup must become five devices');
    check($sharina[0]['hr_employee_id'] === null && $sharina[0]['holder_kind'] === 'imported', 'No guessed employee links');
    check($sharina[0]['device_condition'] === 'Unknown' && $sharina[0]['location'] === null, 'No invented condition/location');
    check($sharina[0]['source']['cells']['AA'] === 'YES', 'Original software information preserved');
    check(!in_array('inventory2026:Laptop:46:Laptop', array_column($records, 'import_key'), true), 'A holder without a laptop must not create a fake device');
    check($sharina[1]['serial_number'] === 'PE201101 23 26' && $sharina[1]['asset_tag'] === 'CH-0000-MKTG-438', 'Keyboard identifiers separated');
    check($sharina[2]['name'] === 'A4TECH mouse' && $sharina[2]['serial_number'] === '24SANO1'
        && $sharina[2]['asset_tag'] === 'CH-0000-MKTG-204', 'Mouse uses its separate identifier columns');
    check($sharina[0]['specifications']['OS'] === 'WINDOWS 10'
        && $sharina[0]['specifications']['MAC address'] === '2C-F0-5D-75-5B-24', 'Shifted desktop specification columns');
    check($sharina[3]['name'] === 'VIEW PLUS monitor' && $sharina[3]['asset_tag'] === 'CH-0000-MKTG-396'
        && $sharina[4]['asset_tag'] === 'CH-0000-MKTG-436'
        && $sharina[3]['specifications']['Size (workbook)'] === '18"', 'Shifted monitor columns');

    $hr = new HrDirectory($db);
    $departmentInsert = $db->prepare('INSERT INTO departments (id, name) VALUES (?, ?)');
    $fixtureDepartmentId = 100;
    foreach (json_decode(file_get_contents(dirname(__DIR__) . '/data/hr-departments.reference.json'), true, 512, JSON_THROW_ON_ERROR) as $name) {
        if ($name !== 'Accounting') $departmentInsert->execute([$fixtureDepartmentId++, $name]);
    }
    $departmentNames = DepartmentNames::configured($hr->departments());
    check($departmentNames->required(' accounting ')['id'] === 1, 'Department formatting resolves to the HR ID');
    check($departmentNames->required('SALES RETAIL')['name'] === 'Sales-Retail', 'Department punctuation uses HR spelling');
    check($departmentNames->required('C&C')['name'] === 'Credit and Collections'
        && $departmentNames->required('ACCOUNTING CNC')['name'] === 'Credit and Collections', 'Collections aliases stay separate from Accounting');
    check($departmentNames->required('BEV')['name'] === 'Beverage'
        && $departmentNames->required('SALES BEV')['name'] === 'Sales-Beverage', 'Beverage and sales beverage stay distinct');
    check($departmentNames->required('SALES MEAT')['name'] === 'Sales-Frozen', 'Confirmed meat department mapping');
    rejects(static fn () => $departmentNames->required('Unconfirmed label'));
    rejects(static fn () => (new DepartmentNames([['id' => 1, 'name' => 'A-B'], ['id' => 2, 'name' => 'A B']]))->required('a b'));
    check($departmentNames->display(['department_id' => 1, 'department' => 'ACCOUNTING'])['department'] === 'Accounting', 'Stored department ID resolves its current HR name');
    check($departmentNames->display(['department' => 'ACCOUNTING'])['department_id'] === 1, 'Legacy formatting resolves a numeric ID for the website');
    check($departmentNames->display(['department_id' => 9999, 'department' => 'Old department'])['department'] === 'Old department', 'Deleted HR department retains its snapshot');
    check(WorkbookDepartmentCleanup::plan($workbookPath, $departmentNames)['unresolved'] === [], 'Workbook departments all resolve to HR');
    $workbookCopy = tempnam(__DIR__, 'inv_departments_');
    if ($workbookCopy === false) throw new RuntimeException('Cannot create workbook test fixture.');
    try {
        copy($workbookPath, $workbookCopy);
        $beforeSheets = Workbook::sheets($workbookCopy);
        $aliases = [];
        foreach ($beforeSheets as $rows) foreach ($rows as $row => $cells) {
            if ($row > 1 && isset($cells['B'])) $aliases[$cells['B']] = 'Fixture department';
        }
        $fixtureNames = new DepartmentNames([['id' => 1, 'name' => 'Fixture department']], $aliases);
        $plan = WorkbookDepartmentCleanup::plan($workbookCopy, $fixtureNames);
        check(count($plan['changes']) > 0 && $plan['unresolved'] === [], 'Cleanup previews changed department cells');
        WorkbookDepartmentCleanup::apply($workbookCopy, $plan);
        $afterSheets = Workbook::sheets($workbookCopy);
        foreach ($plan['changes'] as $change) $beforeSheets[$change['sheet']][$change['row']]['B'] = $change['to'];
        check($beforeSheets === $afterSheets, 'Workbook cleanup changes only department cells and preserves rows and identifiers');
        check(WorkbookDepartmentCleanup::plan($workbookCopy, $fixtureNames)['changes'] === [], 'Workbook cleanup is idempotent');
        $originalBackup = $workbookCopy . '.before-department-cleanup.xlsx';
        $originalHash = hash_file('sha256', $originalBackup);
        $firstCleanHash = hash_file('sha256', $workbookCopy);
        $revisedNames = new DepartmentNames([['id' => 1, 'name' => 'Fixture Department']]);
        $revisedPlan = WorkbookDepartmentCleanup::plan($workbookCopy, $revisedNames);
        $secondBackup = WorkbookDepartmentCleanup::apply($workbookCopy, $revisedPlan);
        check($secondBackup === $workbookCopy . '.before-department-cleanup.2.xlsx', 'A later HR spelling correction creates a separate backup');
        check(hash_file('sha256', $originalBackup) === $originalHash, 'A second cleanup preserves the original backup');
        check(hash_file('sha256', $secondBackup) === $firstCleanHash, 'A second cleanup backs up the current workbook before replacement');
        check(WorkbookDepartmentCleanup::plan($workbookCopy, $revisedNames)['changes'] === [], 'Later spelling corrections can be rerun without changes');
    } finally {
        if (is_file($workbookCopy)) unlink($workbookCopy);
        if (is_file($workbookCopy . '.before-department-cleanup.xlsx')) unlink($workbookCopy . '.before-department-cleanup.xlsx');
        if (is_file($workbookCopy . '.before-department-cleanup.2.xlsx')) unlink($workbookCopy . '.before-department-cleanup.2.xlsx');
    }
    check($hr->branchNamed('apo') === ['id' => 3, 'name' => 'APO'], 'APO uses the actual numeric HR branch ID');
    rejects(static fn () => $hr->branch(9999));
    rejects(static fn () => $hr->branchNamed('Missing branch'));
    $people = $hr->all();
    check(count($people) === 2 && $people[0]['branch'] === 'Primary branch', 'Primary HR branch');
    check($people[1]['branch'] === 'Legacy branch', 'HR branch fallback');
    check(!array_key_exists('password', $people[0]), 'HR projection must exclude secret fields');
    check($people[0]['job_title'] === 'Accountant' && $people[0]['hr_role'] === 'employee', 'Job title and HR access role are separate');
    rejects(static fn () => $hr->find(9999));
    $service = new InventoryService($db);
    check($service->import($records) === ['added' => 216, 'skipped' => 0], 'First import');
    check($service->import($records) === ['added' => 0, 'skipped' => 216], 'Repeated import must not duplicate');
    check((int) $db->query('SELECT COUNT(*) FROM inv_events')->fetchColumn() === 216, 'One import event per device');
    check($service->device(1)['source']['sheet'] === 'Printer', 'Source survives database round trip');
    $keyboardRecord = $sharina[1];
    $keyboardId = (int) $db->query("SELECT id FROM inv_devices WHERE import_key = 'inventory2026:PC:2:Keyboard'")->fetchColumn();
    $unresolvedHolders = array_values(array_filter($service->devices(), static fn (array $device): bool =>
        $device['holder_kind'] === 'imported' && $device['holder_name'] !== null
        && $device['id'] !== $keyboardId && $device['id'] !== $keyboardId + 1));
    check(count($unresolvedHolders) > 0, 'Unresolved workbook holder snapshots remain assigned');
    $keyboard = $service->device($keyboardId);
    $service->save(['id' => $keyboardId, 'version' => $keyboard['version'], 'device_type' => 'Keyboard',
        'name' => 'Website keyboard', 'device_condition' => 'Good', 'serial_number' => 'WEB-SERIAL', 'asset_tag' => 'WEB-TAG']);
    $service->assign(['id' => $keyboardId, 'version' => 2, 'holder_kind' => 'employee', 'hr_employee_id' => 1], $hr);
    $service->import([$keyboardRecord]);
    $keyboard = $service->device($keyboardId);
    check($keyboard['name'] === 'Website keyboard' && $keyboard['serial_number'] === 'WEB-SERIAL'
        && $keyboard['asset_tag'] === 'WEB-TAG' && $keyboard['hr_employee_id'] === 1 && $keyboard['version'] === 3,
        'Website edits and assignment survive reimport of the same source record');
    check($keyboard['source'] === $keyboardRecord['source'] && count($service->history($keyboardId)) === 3,
        'Website edits retain original workbook source and history');
    $locationRecords = array_map(static fn (array $record): array => [...$record, 'location_branch_id' => 3, 'location' => 'APO'], $records);
    check($service->locateImported($locationRecords) === ['located' => 216], 'Existing imported devices get APO location');
    check($service->locateImported($locationRecords) === ['located' => 0], 'Location backfill is idempotent');
    $keyboard = $service->device($keyboardId);
    check($keyboard['location_branch_id'] === 3 && $keyboard['location'] === 'APO'
        && $keyboard['hr_employee_id'] === 1 && $keyboard['source'] === $keyboardRecord['source'], 'Location backfill preserves assignment and source');
    $workbookHolder = $service->device($keyboardId + 1);
    check($workbookHolder['holder_kind'] === 'imported' && $workbookHolder['holder_name'] === 'SHARINA JOY TUAZON',
        'Workbook holder remains assigned by its recorded name without guessing HR IDs');
    $moved = $service->save(['id' => $keyboardId, 'version' => $keyboard['version'], 'device_type' => 'Keyboard',
        'name' => $keyboard['name'], 'device_condition' => 'Good', 'location_branch_id' => '2']);
    check($moved['location_branch_id'] === 2 && $moved['location'] === 'Other branch', 'Website stores branch ID and location name');
    check($service->locateImported($locationRecords) === ['located' => 0], 'APO backfill preserves a website location change');
    rejects(static fn () => $service->save(['device_type' => 'Laptop', 'name' => 'Bad branch',
        'device_condition' => 'Good', 'location_branch_id' => '9999']));
    $newAtApo = $service->save(['device_type' => 'Laptop', 'name' => 'APO test', 'device_condition' => 'Unknown', 'location_branch_id' => '3']);
    check($newAtApo['location_branch_id'] === 3 && $newAtApo['location'] === 'APO', 'New website device uses branch location');
    $created = $service->save(['device_type' => 'Laptop', 'name' => "Test ' device <script>", 'device_condition' => 'Unknown', 'specifications' => ['CPU' => 'Test CPU']]);
    check($created['holder_kind'] === 'unassigned' && $created['version'] === 1, 'New device is unassigned');
    check($created['name'] === "Test ' device <script>", 'Prepared queries preserve literal input');
    rejects(static fn () => $service->save(['device_type' => 'Invalid', 'name' => 'Test', 'device_condition' => 'Good']));
    rejects(static fn () => $service->save(['device_type' => 'Laptop', 'name' => '  ', 'device_condition' => 'Good']));
    rejects(static fn () => $service->save(['device_type' => 'Laptop', 'name' => [], 'device_condition' => 'Good']));
    rejects(static fn () => $service->save(['device_type' => 'Laptop', 'name' => str_repeat('a', 256), 'device_condition' => 'Good']));
    rejects(static fn () => $service->save(['device_type' => 'Laptop', 'name' => 'Test', 'device_condition' => 'Good', 'specifications' => ['CPU' => []]]));
    $id = $created['id'];
    $assignment = ['id' => $id, 'version' => 1, 'holder_kind' => 'employee', 'hr_employee_id' => 1];
    rejects(static fn () => $service->assign([...$assignment, 'hr_employee_id' => 9999], $hr));
    rejects(static fn () => $service->assign([...$assignment, 'handover_date' => '2026-02-30'], $hr));
    rejects(static fn () => $service->assign([...$assignment, 'handover_date' => '0000-01-01'], $hr));
    $assigned = $service->assign([...$assignment, 'handover_date' => '2026-10-02', 'notes' => 'Includes charger.'], $hr);
    check($assigned['holder_name'] === 'Alex Santos' && $assigned['hr_employee_id'] === 1, 'Assignment uses HR employee');
    check($assigned['version'] === 2 && $assigned['branch'] === 'Primary branch', 'Assignment increments version and captures branch');
    check($assigned['handover_date'] === '2026-10-02', 'Assignment stores the current handover date');
    rejects(static fn () => $service->assign($assignment, $hr), 409);
    check(count($service->history($id)) === 2, 'Rejected assignment must not create history');
    $edited = $service->save(['id' => $id, 'version' => 2, 'device_type' => 'Laptop', 'name' => 'Edited device',
        'device_condition' => 'Needs attention', 'specifications' => []]);
    check($edited['holder_name'] === 'Alex Santos' && $edited['version'] === 3, 'Editing preserves assignment');
    check($service->history($id)[0]['handover'] === null, 'Device edits are excluded from person handovers');
    check(str_contains($service->history($id)[0]['message'], 'CPU removed (was: Test CPU)'),
        'Edit history describes removed specifications');
    rejects(static fn () => $service->save(['id' => $id, 'version' => 2, 'device_type' => 'Laptop', 'name' => 'Stale', 'device_condition' => 'Good']), 409);
    $returned = $service->assign(['id' => $id, 'version' => 3, 'holder_kind' => 'unassigned'], $hr);
    check($returned['hr_employee_id'] === null && $returned['holder_name'] === null && $returned['holder_kind'] === 'unassigned', 'Return retains device in available pool');
    check($returned['handover_date'] === null, 'Return clears the current handover date');
    $event = $db->query('SELECT snapshot_json FROM inv_events ORDER BY id DESC LIMIT 1')->fetchColumn();
    check(json_decode($event, true)['before']['holder_name'] === 'Alex Santos', 'Return history retains previous holder');
    check($service->history($id)[0]['holder_names'] === ['Alex Santos'], 'History exposes former holder names for device search');
    check($service->history($id)[0]['handover']['from']['id'] === 1
        && $service->history($id)[0]['handover']['to']['kind'] === 'unassigned', 'History projects the numeric former holder after return');
    rejects(static fn () => $service->assign(['id' => $id, 'version' => 4, 'holder_kind' => 'shared', 'department' => ''], $hr));
    $shared = $service->assign(['id' => $id, 'version' => 4, 'holder_kind' => 'shared', 'department' => 'Accounting'], $hr);
    check($shared['department'] === 'Accounting' && $shared['hr_employee_id'] === null, 'Shared assignment');
    $service->note(['id' => $id, 'message' => 'Inspect battery.']);
    check($service->history($id)[0]['event_type'] === 'note', 'Note event');
    rejects(static fn () => $service->note(['id' => $id, 'message' => '']));
    check($service->import($records)['added'] === 0 && $service->device($id)['name'] === 'Edited device', 'Reimport preserves user changes');
    $specDevice = $service->save(['device_type' => 'Other', 'name' => 'Specification history test',
        'device_condition' => 'Good', 'specifications' => ['RAM' => '8 GB', 'CPU' => 'Original CPU']]);
    $specEdit = ['id' => $specDevice['id'], 'version' => 1, 'device_type' => 'Other',
        'name' => $specDevice['name'], 'device_condition' => 'Good',
        'specifications' => ['RAM' => '16 GB', 'CPU' => 'Original CPU', 'Storage' => '<script>SSD</script>']];
    $specDevice = $service->save($specEdit);
    $specMessage = $service->history($specDevice['id'])[0]['message'];
    check(str_contains($specMessage, 'RAM changed from 8 GB to 16 GB')
        && str_contains($specMessage, 'Storage added: <script>SSD</script>') && !str_contains($specMessage, 'CPU'),
        'Edit history records changed and added specifications and excludes unchanged values');
    $specSnapshot = json_decode($db->query('SELECT snapshot_json FROM inv_events ORDER BY id DESC LIMIT 1')->fetchColumn(), true);
    check($specSnapshot['before']['specifications']['RAM'] === '8 GB'
        && $specSnapshot['after']['specifications']['RAM'] === '16 GB', 'Specification history retains before/after snapshots');
    $service->save([...$specEdit, 'version' => 2, 'specifications' => [
        'Storage' => '<script>SSD</script>', 'CPU' => 'Original CPU', 'RAM' => '16 GB']]);
    check($service->history($specDevice['id'])[0]['message'] === 'Device details updated.',
        'Reordering specifications does not report value changes');
    $specHistoryCount = count($service->history($specDevice['id']));
    rejects(static fn () => $service->save([...$specEdit, 'version' => 2]), 409);
    check(count($service->history($specDevice['id'])) === $specHistoryCount, 'Stale specification edits add no history');
    rejects(static fn () => $service->device(999999), 404);
    rejects(static fn () => $service->history(999999), 404);
    rejects(static fn () => $service->save(['id' => null, 'version' => 1, 'device_type' => 'Laptop', 'name' => 'Bad ID', 'device_condition' => 'Good']));
    $beforeCount = count($service->devices());
    $beforeBaseline = $service->devices();
    $beforeEvents = (int) $db->query('SELECT COUNT(*) FROM inv_events')->fetchColumn();
    $db->exec($mysql ? "CREATE TRIGGER inv_test_fail BEFORE INSERT ON inv_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test failure'" :
        "CREATE TRIGGER inv_test_fail BEFORE INSERT ON inv_events BEGIN SELECT RAISE(FAIL, 'Test failure'); END;");
    try {
        $service->save(['device_type' => 'Other', 'name' => 'Rollback test', 'device_condition' => 'Unknown']);
        throw new RuntimeException('Expected event failure');
    } catch (PDOException) {
        check(count($service->devices()) === $beforeCount, 'Device mutation rolls back if history fails');
    }
    try {
        $service->setEquipmentBaseline('2026-10-02');
        throw new RuntimeException('Expected baseline event failure');
    } catch (PDOException) {
        check($service->devices() === $beforeBaseline, 'Bulk condition/date update rolls back if its history fails');
        check((int) $db->query('SELECT COUNT(*) FROM inv_events')->fetchColumn() === $beforeEvents, 'Failed baseline adds no history');
    }
    $db->exec('DROP TRIGGER inv_test_fail');
    rejects(static fn () => $service->setEquipmentBaseline('2026-02-30'));
    $originalImport = $db->query('SELECT snapshot_json FROM inv_events WHERE id = 1')->fetchColumn();
    $baseline = $service->setEquipmentBaseline('2026-10-02');
    check($baseline === ['updated' => $beforeCount, 'skipped' => 0, 'condition' => 'Good', 'handover_date' => '2026-10-02'],
        'Baseline updates every existing item, including shared and unassigned equipment');
    foreach ($service->devices() as $index => $device) {
        if ($device['device_condition'] !== 'Good' || $device['handover_date'] !== '2026-10-02') {
            throw new RuntimeException('Baseline did not apply condition/date to every device');
        }
        foreach (['id', 'holder_kind', 'holder_name', 'hr_employee_id', 'location_branch_id', 'location', 'source', 'serial_number', 'asset_tag'] as $field) {
            if ($device[$field] !== $beforeBaseline[$index][$field]) throw new RuntimeException('Baseline changed ' . $field);
        }
    }
    check($db->query('SELECT snapshot_json FROM inv_events WHERE id = 1')->fetchColumn() === $originalImport, 'Baseline preserves original history');
    check($service->history(1)[0]['handover_date'] === '2026-10-02' && $service->history(1)[0]['event_type'] === 'baseline', 'Baseline records dated history');
    check($service->setEquipmentBaseline('2026-10-02')['updated'] === 0, 'Repeated baseline does not duplicate updates');
    check((int) $db->query('SELECT COUNT(*) FROM inv_events')->fetchColumn() === $beforeEvents + $beforeCount, 'One baseline event per changed device');
    $sourceBeforeDepartmentCleanup = $service->device(1)['source'];
    check($service->normalizeDepartments($departmentNames)['updated'] > 0, 'Existing inventory departments receive canonical HR IDs');
    check($service->normalizeDepartments($departmentNames)['updated'] === 0, 'Inventory department cleanup is idempotent');
    check($service->device(1)['source'] === $sourceBeforeDepartmentCleanup, 'Department cleanup preserves original source snapshots');
    $sharedDevice = $service->assign(['id' => $id, 'version' => $service->device($id)['version'], 'holder_kind' => 'shared', 'department_id' => 1], $hr);
    check($sharedDevice['department'] === 'Accounting' && $sharedDevice['department_id'] === 1, 'Shared devices uses an HR department ID');
    rejects(static fn () => $service->assign(['id' => $id, 'version' => $sharedDevice['version'], 'holder_kind' => 'shared', 'department_id' => 9999], $hr));
    rejects(static fn () => $service->assign(['id' => $id, 'version' => $sharedDevice['version'], 'holder_kind' => 'shared', 'department' => 'Made-up department'], $hr));
    $db->exec("UPDATE inv_devices SET department = 'hr', department_id = NULL WHERE id = 1");
    $db->exec("UPDATE inv_devices SET department = 'Unconfirmed label', department_id = NULL WHERE id = 2");
    $invalidDepartments = $service->devices();
    rejects(static fn () => $service->normalizeDepartments($departmentNames));
    check($service->devices() === $invalidDepartments, 'Unknown department rolls back the entire inventory cleanup');
    require __DIR__ . '/exchange.php';
    exchangeTests($db, $service, $hr, $records, $mysql, $workbookPath);
    require __DIR__ . '/workbook-layouts.php';
    workbookLayoutTests($workbookPath, $records, $service, $hr);
    require __DIR__ . '/associates.php';
    associateTests($db, $service, $hr);
    check(Http::allowsLocal('127.0.0.1') && Http::allowsLocal('::1'), 'Localhost allowed');
    check(!Http::allowsLocal('192.168.100.72') && !Http::allowsLocal(''), 'LAN blocked by default');
    $authDirectory = __DIR__ . '/inventory_auth_' . bin2hex(random_bytes(8));
    if (!mkdir($authDirectory)) throw new RuntimeException('Cannot create isolated authentication fixture.');
    ini_set('session.save_path', $authDirectory);
    if (!session_start()) throw new RuntimeException('Cannot start test session.');
    try {
        $password = 'synthetic-test-password';
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name VARCHAR(255), username VARCHAR(255),
            email VARCHAR(255), password VARCHAR(255), is_admin INTEGER, is_it_admin INTEGER)');
        $insertUser = $db->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insertUser->execute([1, 'Inventory Admin', 'editor', 'editor@example.test', password_hash($password, PASSWORD_DEFAULT), 1, 0]);
        $insertUser->execute([2, 'Inventory IT', 'it', 'it@example.test', password_hash($password, PASSWORD_DEFAULT), 0, 1]);
        $insertUser->execute([3, 'Inventory Viewer', null, 'viewer@example.test', password_hash($password, PASSWORD_DEFAULT), 0, 0]);
        $account = $hr->loginUser('editor');
        check($hr->hasInventoryEditor(), 'Existing attendance admins allow LAN setup');
        check($hr->loginUser("editor' OR 1=1 --") === null, 'User lookup uses prepared parameters');
        check($hr->loginUserById(9999) === null, 'Missing attendance user rejected');
        check(!array_key_exists('password', $hr->all()[0]), 'Authentication does not expose hashes in the People directory');
        $_SERVER['REMOTE_ADDR'] = '192.168.1.22';
        $oldSession = session_id();
        check(Inventory\Auth::login('editor', $password, $hr, $authDirectory), 'Attendance admin can sign in');
        check(session_id() !== $oldSession, 'Login rotates the session ID');
        $signedIn = $_SESSION;
        $user = Inventory\Auth::user($account, $signedIn);
        check($user['username'] === 'editor' && $user['role'] === 'editor' && $user['id'] === 1, 'Attendance admin receives editor access');
        check(!array_key_exists('password', $user), 'Public session identity omits the password hash');
        Inventory\Auth::authorize($user, true);
        rejects(static fn () => Inventory\Auth::authorize(null, false), 401);
        rejects(static fn () => Inventory\Auth::authorize(['role' => 'viewer'], true), 403);
        Inventory\Auth::authorize(['role' => 'viewer'], false);
        check(Inventory\Auth::user($account, [...$signedIn, 'last_seen' => time() - 1801]) === null, 'Idle session expires');
        check(Inventory\Auth::user($account, [...$signedIn, 'signed_in' => time() - 28801]) === null, 'Absolute session expires');
        check(Inventory\Auth::user(null, $signedIn) === null, 'Deleted attendance user loses access');
        check(Inventory\Auth::user($hr->loginUserById(2), $signedIn) === null, 'Session is bound to numeric user ID');
        $changedAccount = $account;
        $changedAccount['password'] = password_hash('replacement-test-password', PASSWORD_DEFAULT);
        check(Inventory\Auth::user($changedAccount, $signedIn) === null, 'Attendance password reset invalidates sessions');
        $changedAccount = $account;
        $changedAccount['is_admin'] = 0;
        rejects(static fn () => Inventory\Auth::authorize(Inventory\Auth::user($changedAccount, $signedIn), true), 401);
        check(!Inventory\Auth::login('unknown', $password, $hr, $authDirectory), 'Unknown account rejected');
        for ($i = 0; $i < 9; $i++) check(!Inventory\Auth::login('editor', 'wrong', $hr, $authDirectory), 'Bad password rejected');
        check(!Inventory\Auth::login('editor', $password, $hr, $authDirectory), 'IP throttle applies across accounts');
        file_put_contents($authDirectory . '/login-attempts.local.json', json_encode([
            'user:1' => ['count' => 10, 'until' => time() - 1],
            'ip:' . hash('sha256', '192.168.1.22') => ['count' => 10, 'until' => time() - 1]]));
        check(Inventory\Auth::login('editor@example.test', $password, $hr, $authDirectory), 'Expired throttle permits email login');
        for ($i = 0; $i < 10; $i++) Inventory\Auth::login('editor', 'wrong', $hr, $authDirectory);
        $_SERVER['REMOTE_ADDR'] = '192.168.1.23';
        check(!Inventory\Auth::login('editor@example.test', $password, $hr, $authDirectory), 'Username/email share throttle across IPs');
        file_put_contents($authDirectory . '/login-attempts.local.json', '{}');
        check(Inventory\Auth::login('it', $password, $hr, $authDirectory), 'IT admin can sign in');
        check(Inventory\Auth::user($hr->loginUserById(2), $_SESSION)['role'] === 'editor', 'IT admin can edit');
        check(!Inventory\Auth::login('viewer@example.test', $password, $hr, $authDirectory), 'Non-admin attendance user cannot sign in');
        $nonAdmin = $hr->loginUserById(3);
        check(Inventory\Auth::user($nonAdmin, [...$_SESSION, 'user_id' => 3,
            'credential' => hash('sha256', $nonAdmin['password'])]) === null, 'Non-admin account cannot resume a session');
        check(!Inventory\Auth::login('viewer@example.test', 'wrong', $hr, $authDirectory), 'Non-admin wrong-password login also rejected');
        $db->exec('UPDATE users SET is_it_admin = 1 WHERE id = 3');
        check(Inventory\Auth::login('viewer@example.test', $password, $hr, $authDirectory), 'Email-only IT admin can sign in');
        $db->exec('DELETE FROM users WHERE id = 3');
        check(Inventory\Auth::user($hr->loginUserById(3), $_SESSION) === null, 'Deleted attendance account revokes a real session');
        $insertUser->execute([4, 'Ambiguous login', 'other', 'editor', password_hash($password, PASSWORD_DEFAULT), 0, 0]);
        check($hr->loginUser('editor') === null, 'Ambiguous username/email rejected');
        $db->exec('DELETE FROM users WHERE id = 4');
        $db->exec('UPDATE users SET is_admin = 0, is_it_admin = 0');
        check(!$hr->hasInventoryEditor(), 'No editor account blocks LAN setup');
        check(!Inventory\Auth::login('editor', $password, $hr, $authDirectory), 'Demoted attendance admin cannot sign in');
        check($db->query('SELECT password FROM users WHERE id = 1')->fetchColumn() === $account['password'], 'Login never changes attendance password hashes');
        Inventory\Auth::logout();
        check(Inventory\Auth::user($account, $_SESSION) === null && isset($_SESSION['csrf']), 'Logout removes access and rotates CSRF');
    } finally {
        session_destroy();
        foreach (glob($authDirectory . '/*') as $authFile) unlink($authFile);
        rmdir($authDirectory);
    }
    Http::verifyToken('test-token', 'test-token');
    rejects(static fn () => Http::verifyToken('', 'test-token'), 403);
    rejects(static fn () => Http::verifyToken('wrong', 'test-token'), 403);
    rejects(static fn () => InventoryService::positiveInt('1 OR 1=1', 'device ID'));
    rejects(static fn () => InventoryService::positiveInt(true, 'device ID'));
    rejects(static fn () => InventoryService::positiveInt(1.5, 'device ID'));
    require __DIR__ . '/evidence.php';
    require __DIR__ . '/photo-removal.php';
    require __DIR__ . '/photo-import.php';
    require __DIR__ . '/actors.php';
    require __DIR__ . '/handovers.php';
    require __DIR__ . '/presets.php';
    require __DIR__ . '/disposal.php';
    require __DIR__ . '/bulk.php';
    bulkTests($db, $hr, $mysql);
    echo "PASS: $assertions assertions (" . ($mysql ? 'MySQL' : 'isolated SQLite fixture') . ").\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($admin && $testName) {
        $admin->exec("DROP DATABASE `$testName`");
    }
}
exit($exitCode ?? 0);
