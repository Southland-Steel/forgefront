<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

try {
    $name     = trim($data['name']  ?? '');
    $email    = trim($data['email'] ?? '');
    $sites    = array_map('intval', $data['sites']    ?? []);
    $campuses = array_map('intval', $data['campuses'] ?? []);

    if (!$name) { echo json_encode(['success' => false, 'error' => 'Name required']); exit; }

    $empId = (int)($data['employee_id'] ?? 0);

    if ($empId) {
        $pdo->prepare("UPDATE employees SET name=?, email=? WHERE employee_id=?")->execute([$name, $email ?: null, $empId]);
        $pdo->prepare("DELETE FROM employee_sites WHERE employee_id=?")->execute([$empId]);
        $pdo->prepare("DELETE FROM employee_campuses WHERE employee_id=?")->execute([$empId]);
    } else {
        $pdo->prepare("INSERT INTO employees (name, email) VALUES (?, ?)")->execute([$name, $email ?: null]);
        $empId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("INSERT INTO employee_sites (employee_id, site_id, is_primary) VALUES (?, ?, 0)");
    foreach ($sites as $siteId) {
        $stmt->execute([$empId, $siteId]);
    }

    $stmt = $pdo->prepare("INSERT INTO employee_campuses (employee_id, campus_id) VALUES (?, ?)");
    foreach ($campuses as $campusId) {
        $stmt->execute([$empId, $campusId]);
    }

    echo json_encode(['success' => true, 'employee_id' => $empId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
