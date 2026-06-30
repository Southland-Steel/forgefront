<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);
$ids  = array_values(array_filter(array_map('intval', $data['employee_ids'] ?? [])));

if (empty($ids)) { echo json_encode(['success' => false, 'error' => 'No employees selected']); exit; }

try {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE assets SET assigned_employee_id = NULL WHERE assigned_employee_id IN ($ph)")->execute($ids);
    $pdo->prepare("DELETE FROM employee_sites WHERE employee_id IN ($ph)")->execute($ids);
    $pdo->prepare("DELETE FROM employee_campuses WHERE employee_id IN ($ph)")->execute($ids);
    $pdo->prepare("DELETE FROM employees WHERE employee_id IN ($ph)")->execute($ids);
    echo json_encode(['success' => true, 'deleted' => count($ids)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
