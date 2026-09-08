<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id   = (int)($data['item_id'] ?? 0);
$pdo  = getPDO();

if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing item ID.']); exit; }

$pdo->prepare("DELETE FROM roadmap_items WHERE item_id = ?")->execute([$id]);

echo json_encode(['success' => true]);
