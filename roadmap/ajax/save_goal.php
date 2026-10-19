<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../frame_label.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true);
$pdo    = getPDO();
$id     = (int)($data['goal_id'] ?? 0);
$itemId = (int)($data['item_id'] ?? 0);
$title  = trim($data['title'] ?? '');
$desc   = trim($data['description'] ?? '') ?: null;
$status = $data['status'] ?? 'open';
$owner  = ($data['owner_id'] ?? null) === null || $data['owner_id'] === '' ? null : (int)$data['owner_id'];

$validStatuses = ['open', 'in_progress', 'done'];

if (!$title) {
    echo json_encode(['success' => false, 'error' => 'A goal needs a title.']); exit;
}
if (!in_array($status, $validStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status.']); exit;
}
// A goal only exists to describe a task, so it must belong to a real one.
if (!$id) {
    $stmt = $pdo->prepare("SELECT 1 FROM roadmap_items WHERE item_id = ?");
    $stmt->execute([$itemId]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'That task no longer exists.']); exit;
    }
}
// Client-supplied id going into a foreign key, so it gets re-checked.
if ($owner !== null) {
    $stmt = $pdo->prepare("SELECT 1 FROM ff_users WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$owner]);
    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Invalid owner.']); exit;
    }
}

if ($id) {
    // The old title is needed before the update: the goal is written into its
    // frame's label on the canvas, and the old one has to come off that label
    // for the new one to replace it rather than stack onto it.
    $stmt = $pdo->prepare("SELECT title, frame_id, item_id FROM roadmap_goals WHERE goal_id = ?");
    $stmt->execute([$id]);
    $before = $stmt->fetch();

    // item_id is deliberately NOT updatable — a goal doesn't move between
    // tasks, and its whiteboard would make no sense if it did.
    $pdo->prepare("UPDATE roadmap_goals SET title=?, description=?, status=?, owner_id=? WHERE goal_id=?")
        ->execute([$title, $desc, $status, $owner, $id]);

    if ($before && $before['frame_id'] && $before['title'] !== $title) {
        rmApplyFrameLabel($pdo, (int)$before['item_id'], $before['frame_id'], $title, $before['title']);
    }
    echo json_encode(['success' => true]);
} else {
    // New goals land at the end of the task's list.
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM roadmap_goals WHERE item_id = ?");
    $stmt->execute([$itemId]);
    $sortOrder = (int)$stmt->fetchColumn();

    $pdo->prepare("INSERT INTO roadmap_goals (item_id, title, description, status, owner_id, sort_order, created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$itemId, $title, $desc, $status, $owner, $sortOrder, Auth::getFullName()]);
    echo json_encode(['success' => true, 'goal_id' => (int)$pdo->lastInsertId()]);
}
