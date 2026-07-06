<?php
// One-off: load seed.sql (company plant IPs) into the known-IPs SQLite
// store. Safe to run more than once (CREATE TABLE IF NOT EXISTS / INSERT
// OR IGNORE). Delete this file once you've confirmed the seed worked.
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';

$pdo = db();
if (!$pdo) {
    http_response_code(500);
    exit('Could not open the SQLite database — check that entratrak/ is writable.');
}

$sql = file_get_contents(__DIR__ . '/seed.sql');
$pdo->exec($sql);

$count = $pdo->query('SELECT COUNT(*) FROM known_ips')->fetchColumn();
echo "Seed applied. known_ips now has $count row(s).";
