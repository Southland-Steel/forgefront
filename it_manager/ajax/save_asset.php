<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

try {
    $assetId    = (int)($data['asset_id'] ?? 0);
    $categoryId = (int)($data['category_id'] ?? 0);
    $make       = trim($data['make']          ?? '');
    $model      = trim($data['model']         ?? '');
    $serial     = trim($data['serial_number'] ?? '');
    $status     = $data['status']             ?? 'Active';
    $notes      = trim($data['notes']         ?? '');
    $empId      = !empty($data['assigned_employee_id']) ? (int)$data['assigned_employee_id'] : null;
    $locId      = !empty($data['assigned_location_id']) ? (int)$data['assigned_location_id'] : null;

    if (!$categoryId) { echo json_encode(['success' => false, 'error' => 'Category required']); exit; }

    if ($assetId) {
        $old = $pdo->prepare("SELECT status, assigned_employee_id, assigned_location_id FROM assets WHERE asset_id = ?");
        $old->execute([$assetId]);
        $old = $old->fetch();

        $pdo->prepare("
            UPDATE assets SET category_id=?, make=?, model=?, serial_number=?, status=?, notes=?, updated_at=NOW()
            WHERE asset_id=?
        ")->execute([$categoryId, $make, $model, $serial, $status, $notes, $assetId]);

        if ($old['status'] !== $status) {
            logHistory($pdo, $assetId, 'Status Changed', null, null, "Changed to $status");
        }

        echo json_encode(['success' => true, 'asset_id' => $assetId]);
    } else {
        $tag = generateAssetTag($pdo);
        $pdo->prepare("
            INSERT INTO assets (asset_tag, category_id, make, model, serial_number, status, assigned_employee_id, assigned_location_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$tag, $categoryId, $make, $model, $serial, $status, $empId, $locId, $notes]);

        $newId = (int)$pdo->lastInsertId();
        logHistory($pdo, $newId, 'Created', $empId, $locId, 'Asset added to inventory');

        echo json_encode(['success' => true, 'asset_id' => $newId, 'asset_tag' => $tag]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
