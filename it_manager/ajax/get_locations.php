<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo = getPDO();

$grouped = isset($_GET['grouped']);

if ($grouped) {
    $rows = $pdo->query("
        SELECT l.location_id, l.name, c.name AS campus_name,
               COUNT(a.asset_id) AS asset_count
        FROM locations l
        JOIN campuses c ON c.campus_id = l.campus_id
        LEFT JOIN assets a ON a.assigned_location_id = l.location_id
        GROUP BY l.location_id, l.name, c.name
        ORDER BY c.name, l.name
    ")->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['campus_name']][] = $row;
    }
    echo json_encode($grouped);
} else {
    $rows = $pdo->query("
        SELECT l.location_id, l.name, c.name AS campus_name
        FROM locations l
        JOIN campuses c ON c.campus_id = l.campus_id
        ORDER BY c.name, l.name
    ")->fetchAll();
    echo json_encode($rows);
}
