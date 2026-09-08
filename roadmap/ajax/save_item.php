<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$data     = json_decode(file_get_contents('php://input'), true);
$pdo      = getPDO();
$id       = (int)($data['item_id'] ?? 0);
$type     = $data['type'] ?? 'idea';
$title    = trim($data['title'] ?? '');
$desc     = trim($data['description'] ?? '') ?: null;
$status   = $data['status'] ?? 'backlog';
$priority  = $data['priority'] ?? 'medium';
$startDate = trim($data['start_date'] ?? '') ?: null;
$dueDate   = trim($data['due_date'] ?? '') ?: null;

$validTypes      = ['idea', 'task'];
$validStatuses   = ['backlog', 'in_progress', 'done', 'archived'];
$validPriorities = ['low', 'medium', 'high'];

if (!$title) {
    echo json_encode(['success' => false, 'error' => 'Title is required.']); exit;
}
if (!in_array($type, $validTypes, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid type.']); exit;
}
if (!in_array($status, $validStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status.']); exit;
}
if (!in_array($priority, $validPriorities, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid priority.']); exit;
}
if ($type === 'task' && (!$startDate || !$dueDate)) {
    echo json_encode(['success' => false, 'error' => 'Tasks require both a start date and a due date.']); exit;
}
if ($startDate !== null && !DateTime::createFromFormat('Y-m-d', $startDate)) {
    echo json_encode(['success' => false, 'error' => 'Invalid start date.']); exit;
}
if ($dueDate !== null && !DateTime::createFromFormat('Y-m-d', $dueDate)) {
    echo json_encode(['success' => false, 'error' => 'Invalid due date.']); exit;
}
if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
    echo json_encode(['success' => false, 'error' => 'Start date must be on or before the due date.']); exit;
}

if ($id) {
    $pdo->prepare("UPDATE roadmap_items SET type=?, title=?, description=?, status=?, priority=?, start_date=?, due_date=? WHERE item_id=?")
        ->execute([$type, $title, $desc, $status, $priority, $startDate, $dueDate, $id]);
    echo json_encode(['success' => true]);
} else {
    $pdo->prepare("INSERT INTO roadmap_items (type, title, description, status, priority, start_date, due_date, created_by) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$type, $title, $desc, $status, $priority, $startDate, $dueDate, Auth::getFullName()]);
    echo json_encode(['success' => true, 'item_id' => (int)$pdo->lastInsertId()]);
}
