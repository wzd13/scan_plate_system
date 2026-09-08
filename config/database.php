<?php
declare(strict_types=1);

/**
 * Zero-config SQLite database (no install.php required).
 * File is auto-created under /data on first request.
 */
return [
    'driver' => 'sqlite',
    'path' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'parking.sqlite',
];
