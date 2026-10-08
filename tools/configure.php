<?php
declare(strict_types=1);

// Optional: reuse local HR connection settings without printing credentials.
$source = $argv[1] ?? null;
$target = dirname(__DIR__) . '/config.local.php';
if (!$source || !is_file($source)) {
    fwrite(STDERR, "Usage: php tools/configure.php PATH_TO_HR_ENV\n");
    exit(1);
}
$values = [];
foreach (file($source, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^(DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=(.*)$/', trim($line), $match)) {
        $value = trim($match[2]);
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[0] === substr($value, -1)) {
            $value = substr($value, 1, -1);
        }
        $values[$match[1]] = $value;
    }
}
if (($values['DB_DATABASE'] ?? '') !== 'attendance_monitoring') {
    fwrite(STDERR, "The source database differs from attendance_monitoring. Configure config.local.php manually.\n");
    echo 'Source database: ' . ($values['DB_DATABASE'] ?? '(not found)') . '; host: ' . ($values['DB_HOST'] ?? '(not found)') . '; port: ' . ($values['DB_PORT'] ?? '(not found)') . PHP_EOL;
    exit(1);
}
$config = ['database' => ['host' => $values['DB_HOST'] ?? '127.0.0.1', 'port' => (int) ($values['DB_PORT'] ?? 3306),
    'name' => 'attendance_monitoring', 'user' => $values['DB_USERNAME'] ?? '', 'password' => $values['DB_PASSWORD'] ?? ''],
    'local_only' => true];
file_put_contents($target, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n");
echo "Saved local connection settings. Credentials were not printed.\n";
