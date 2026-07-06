<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

$name = trim($data['name'] ?? '');
if (!$name) { echo json_encode(['success' => false, 'error' => 'Name is required']); exit; }

try {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM campuses WHERE name = ?");
    $exists->execute([$name]);
    if ($exists->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => "Campus \"$name\" already exists"]);
        exit;
    }
    $pdo->prepare("INSERT INTO campuses (name) VALUES (?)")->execute([$name]);
    echo json_encode(['success' => true, 'campus_id' => (int)$pdo->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
