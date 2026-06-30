<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);
$id   = (int)($data['employee_id'] ?? 0);

if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing ID']); exit; }

try {
    // Unassign any assets belonging to this employee
    $pdo->prepare("UPDATE assets SET assigned_employee_id = NULL WHERE assigned_employee_id = ?")
        ->execute([$id]);

    $pdo->prepare("DELETE FROM employee_sites WHERE employee_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM employee_campuses WHERE employee_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM employees WHERE employee_id = ?")->execute([$id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
