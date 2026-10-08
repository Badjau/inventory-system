<?php
declare(strict_types=1);

// Copy to config.local.php. This file and all PHP source stay outside public/.
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'attendance_monitoring',
        'user' => 'root',
        'password' => '',
    ],
    // Login uses attendance users. host-lan.ps1 checks admin access before enabling LAN.
    'local_only' => true,
];
