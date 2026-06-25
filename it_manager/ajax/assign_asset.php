<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

try {
    $assetId = (int)($data['asset_id'] ?? 0);
    $empId   = $data['employee_id'] ? (int)$data['employee_id'] : null;
    $locId   = $data['location_id'] ? (int)$data['location_id'] : null;
    $notes   = trim($data['notes'] ?? '');

    if (!$assetId) { echo json_encode(['success' => false, 'error' => 'Missing asset_id']); exit; }

    $old = $pdo->prepare("SELECT assigned_employee_id, assigned_location_id FROM assets WHERE asset_id = ?");
    $old->execute([$assetId]);
    $old = $old->fetch();

    $pdo->prepare("UPDATE assets SET assigned_employee_id=?, assigned_location_id=?, updated_at=NOW() WHERE asset_id=?")
        ->execute([$empId, $locId, $assetId]);

    $prevEmp = (int)($old['assigned_employee_id'] ?? 0);
    $prevLoc = (int)($old['assigned_location_id'] ?? 0);

    if ($empId && $empId !== $prevEmp) {
        logHistory($pdo, $assetId, 'Assigned', $empId, $locId, $notes);
    } elseif (!$empId && $prevEmp) {
        logHistory($pdo, $assetId, 'Unassigned', null, $locId, $notes);
    } elseif ($locId !== $prevLoc) {
        logHistory($pdo, $assetId, 'Moved', $empId, $locId, $notes);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
