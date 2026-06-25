<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

$id = (int)($data['asset_id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'error' => 'Invalid asset']); exit; }

try {
    $pdo->prepare("DELETE FROM asset_history WHERE asset_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM assets WHERE asset_id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
