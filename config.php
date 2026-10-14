<?php
declare(strict_types=1);

// Set WATCHWORKSHOP_DATA_DIR to a private absolute path in hosting environment
// for best security. The default keeps data alongside the application.
$dataDir = getenv('WATCHWORKSHOP_DATA_DIR');
if (!$dataDir) {
    $dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
}
$dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);
if ($dataDir === '') {
    $dataDir = DIRECTORY_SEPARATOR;
}

define('APP_NAME', 'Custom Watch Workshop Manager');
define('APP_DATA_DIR', $dataDir);
define('APP_DB_DIR', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'database');
define('APP_DB_PATH', APP_DB_DIR . DIRECTORY_SEPARATOR . 'watch_workshop.db');
define('APP_IMAGE_DIR', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'images');
define('APP_EXPORT_DIR', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'exports');
define('APP_MAX_UPLOAD', 15 * 1024 * 1024);

// Change this to a long random secret before using legacy login reset.
// It is not the administrator password.
$setupKey = getenv('WATCHWORKSHOP_SETUP_KEY');
if ($setupKey === false || $setupKey === '') {
    $setupKey = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_BEFORE_USE';
}
define('APP_SETUP_KEY', $setupKey);
