<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

// Turns a frame drawn on a task's whiteboard into a goal — the "sketch the
// goals out, then make them real" step. With goal_id it links the frame to a
// goal that already exists instead of creating one.
$data    = json_decode(file_get_contents('php://input'), true);
$itemId  = (int)($data['item_id'] ?? 0);
$frameId = trim($data['frame_id'] ?? '');
$goalId  = (int)($data['goal_id'] ?? 0);
$name    = trim($data['frame_name'] ?? '');
$unlink  = !empty($data['unlink']);
$rename  = !empty($data['rename']);
$pdo     = getPDO();

if (!$itemId || $frameId === '') {
    echo json_encode(['success' => false, 'error' => 'Missing task or region.']); exit;
}

// Renaming the goal from the canvas, by typing over the goal field. Only the
// title is written here — the caller has the board open and updates the frame's
// own label itself, so the scene is left to whoever is drawing on it.
if ($rename) {
    $title = trim($data['title'] ?? '');
    if ($title === '') {
        echo json_encode(['success' => false, 'error' => 'A goal needs a name.']); exit;
    }
    $stmt = $pdo->prepare("SELECT goal_id, title FROM roadmap_goals WHERE frame_id = ? AND item_id = ?");
    $stmt->execute([$frameId, $itemId]);
    $current = $stmt->fetch();
    if (!$current) {
        echo json_encode(['success' => false, 'error' => 'That region is not linked to a goal.']); exit;
    }
    $pdo->prepare("UPDATE roadmap_goals SET title = ? WHERE goal_id = ?")
        ->execute([mb_substr($title, 0, 200), $current['goal_id']]);
    echo json_encode([
        'success'  => true,
        'renamed'  => true,
        'goal_id'  => (int)$current['goal_id'],
        'title'    => mb_substr($title, 0, 200),
        'previous' => $current['title'],
    ]);
    exit;
}

// Clearing the goal field releases the region. The GOAL survives — it goes
// back to the list with no region, ready to be put somewhere else — and the
// frame stays on the canvas. Nothing is drawn or deleted either way.
if ($unlink) {
    $stmt = $pdo->prepare("SELECT goal_id, title FROM roadmap_goals WHERE frame_id = ? AND item_id = ?");
    $stmt->execute([$frameId, $itemId]);
    $current = $stmt->fetch();
    if (!$current) {
        echo json_encode(['success' => false, 'error' => 'That region is not linked to a goal.']); exit;
    }
    $pdo->prepare("UPDATE roadmap_goals SET frame_id = NULL WHERE goal_id = ?")->execute([$current['goal_id']]);
    echo json_encode([
        'success'  => true,
        'unlinked' => true,
        'goal_id'  => (int)$current['goal_id'],
        'title'    => $current['title'],
    ]);
    exit;
}

$stmt = $pdo->prepare("SELECT scene_json FROM roadmap_boards WHERE item_id = ?");
$stmt->execute([$itemId]);
$sceneJson = $stmt->fetchColumn();
if ($sceneJson === false) {
    echo json_encode(['success' => false, 'error' => 'Save the board before turning a region into a goal.']); exit;
}

// frame_id arrives from an editable client and becomes a goal's identity, so
// it is checked against the saved scene: it has to be a real frame on THIS
// task's board, not just any string.
$scene = json_decode($sceneJson, true);
$frame = null;
foreach (($scene['elements'] ?? []) as $el) {
    if (($el['id'] ?? '') === $frameId && ($el['type'] ?? '') === 'frame' && empty($el['isDeleted'])) {
        $frame = $el; break;
    }
}
if (!$frame) {
    echo json_encode(['success' => false, 'error' => 'That region is not on this board — save the board and try again.']); exit;
}

// A region belongs to one goal. The UNIQUE key enforces it too, but catching
// it here gives a message that says which goal already has it.
$stmt = $pdo->prepare("SELECT goal_id, title FROM roadmap_goals WHERE frame_id = ?");
$stmt->execute([$frameId]);
$taken = $stmt->fetch();
if ($taken && (int)$taken['goal_id'] !== $goalId) {
    echo json_encode(['success' => false, 'error' => 'That region is already the goal “' . $taken['title'] . '”.']); exit;
}

if ($goalId) {
    // Linking an existing goal: it must belong to this task, or a region could
    // be attached to a goal on someone else's board.
    $stmt = $pdo->prepare("SELECT item_id FROM roadmap_goals WHERE goal_id = ?");
    $stmt->execute([$goalId]);
    $owner = $stmt->fetchColumn();
    if ($owner === false) {
        echo json_encode(['success' => false, 'error' => 'That goal no longer exists.']); exit;
    }
    if ((int)$owner !== $itemId) {
        echo json_encode(['success' => false, 'error' => 'That goal belongs to a different task.']); exit;
    }
    $pdo->prepare("UPDATE roadmap_goals SET frame_id = ? WHERE goal_id = ?")->execute([$frameId, $goalId]);
    echo json_encode(['success' => true, 'goal_id' => $goalId, 'created' => false]);
    exit;
}

// New goal from the region. An unnamed frame still makes a usable goal — the
// title can be edited on the task page — so this never refuses for a blank name.
$title = $name !== '' ? mb_substr($name, 0, 200) : 'Untitled region';

$stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM roadmap_goals WHERE item_id = ?");
$stmt->execute([$itemId]);
$sortOrder = (int)$stmt->fetchColumn();

$pdo->prepare("INSERT INTO roadmap_goals (item_id, frame_id, title, status, sort_order, created_by) VALUES (?,?,?,'open',?,?)")
    ->execute([$itemId, $frameId, $title, $sortOrder, Auth::getFullName()]);

echo json_encode(['success' => true, 'goal_id' => (int)$pdo->lastInsertId(), 'created' => true, 'title' => $title]);
