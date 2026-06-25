<?php
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

define('DB_HOST',    $_ENV['FFDB_HOST']    ?? 'mysql_db');
define('DB_NAME',    $_ENV['FFDB_NAME']    ?? 'forgefront');
define('DB_USER',    $_ENV['FFDB_USER']    ?? '');
define('DB_PASS',    $_ENV['FFDB_PASS']    ?? '');
define('DB_CHARSET',   'utf8mb4');
define('APP_BASE_URL', 'http://forgefront.test');
define('AGENT_TOKEN',  $_ENV['AGENT_TOKEN'] ?? '');

date_default_timezone_set('America/Chicago');
