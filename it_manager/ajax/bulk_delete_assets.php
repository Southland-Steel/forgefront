<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

$ids = array_map('intval', $data['asset_ids'] ?? []);
$ids = array_filter($ids);

if (empty($ids)) { echo json_encode(['success' => false, 'error' => 'No assets selected']); exit; }

try {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM asset_history WHERE asset_id IN ($ph)")->execute(array_values($ids));
    $pdo->prepare("DELETE FROM assets WHERE asset_id IN ($ph)")->execute(array_values($ids));
    echo json_encode(['success' => true, 'deleted' => count($ids)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
