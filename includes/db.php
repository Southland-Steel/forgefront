<?php
require_once __DIR__ . '/config.php';

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function generateAssetTag(PDO $pdo): string {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(asset_tag, 4) AS UNSIGNED)) AS max_num FROM assets WHERE asset_tag LIKE 'FF-%'");
    $row  = $stmt->fetch();
    $next = (int)($row['max_num'] ?? 0) + 1;
    return 'FF-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function logHistory(PDO $pdo, int $assetId, string $action, ?int $employeeId, ?int $locationId, string $notes = ''): void {
    $changedBy = class_exists('Auth') ? Auth::getFullName() : 'system';
    $stmt = $pdo->prepare("
        INSERT INTO asset_history (asset_id, action, employee_id, location_id, changed_by, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$assetId, $action, $employeeId, $locationId, $changedBy, $notes]);
}
