<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

$id = (int)($data['category_id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'error' => 'Invalid category']); exit; }

try {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE category_id = ?");
    $cnt->execute([$id]);
    if ($cnt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot delete — assets are assigned to this category']);
        exit;
    }
    $pdo->prepare("DELETE FROM asset_categories WHERE category_id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
