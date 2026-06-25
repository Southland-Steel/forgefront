<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

try {
    $locationId = (int)($data['location_id'] ?? 0);
    $campusName = trim($data['campus_name'] ?? '');

    if ($locationId) {
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE assigned_location_id = ?");
        $inUse->execute([$locationId]);
        if ((int)$inUse->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'This location has assets assigned to it. Reassign or remove them first.']);
            exit;
        }
        $pdo->prepare("DELETE FROM locations WHERE location_id = ?")->execute([$locationId]);
        echo json_encode(['success' => true]);

    } elseif ($campusName) {
        $campus = $pdo->prepare("SELECT campus_id FROM campuses WHERE name = ?");
        $campus->execute([$campusName]);
        $campusId = (int)($campus->fetchColumn());
        if (!$campusId) { echo json_encode(['success' => false, 'error' => 'Campus not found']); exit; }

        $inUse = $pdo->prepare("
            SELECT COUNT(*) FROM assets a
            JOIN locations l ON l.location_id = a.assigned_location_id
            WHERE l.campus_id = ?
        ");
        $inUse->execute([$campusId]);
        if ((int)$inUse->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'One or more locations in this campus have assets assigned. Reassign or remove them first.']);
            exit;
        }
        $pdo->prepare("DELETE FROM locations WHERE campus_id = ?")->execute([$campusId]);
        echo json_encode(['success' => true]);

    } else {
        echo json_encode(['success' => false, 'error' => 'Nothing to delete']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
