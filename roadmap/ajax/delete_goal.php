<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../frame_label.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id   = (int)($data['goal_id'] ?? 0);
$pdo  = getPDO();

if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing goal ID.']); exit; }

// Read the region first: deleting the goal leaves the frame on the canvas, so
// its label has to drop the goal's name and go back to what it was.
$stmt = $pdo->prepare("SELECT title, frame_id, item_id FROM roadmap_goals WHERE goal_id = ?");
$stmt->execute([$id]);
$before = $stmt->fetch();

$pdo->prepare("DELETE FROM roadmap_goals WHERE goal_id = ?")->execute([$id]);

if ($before && $before['frame_id']) {
    rmApplyFrameLabel($pdo, (int)$before['item_id'], $before['frame_id'], null, $before['title']);
}

echo json_encode(['success' => true]);
