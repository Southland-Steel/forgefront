<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

try {
    $campusId = (int)($data['campus_id'] ?? 0);
    $name     = trim($data['name'] ?? '');

    if (!$campusId || !$name) { echo json_encode(['success' => false, 'error' => 'Campus and name required']); exit; }

    $locId = (int)($data['location_id'] ?? 0);
    if ($locId) {
        $pdo->prepare("UPDATE locations SET campus_id=?, name=? WHERE location_id=?")->execute([$campusId, $name, $locId]);
    } else {
        $pdo->prepare("INSERT INTO locations (campus_id, name) VALUES (?, ?)")->execute([$campusId, $name]);
        $locId = (int)$pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'location_id' => $locId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
