<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        passthru('"' . PHP_BINARY . '" -l ' . escapeshellarg($file->getPathname()), $status);
        $failed = $failed || $status !== 0;
    }
}
exit($failed ? 1 : 0);
