<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');
$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true);

$raw = trim($data['scan_data'] ?? '');
if (!$raw) { echo json_encode(['success' => false, 'error' => 'No scan data received']); exit; }

// Accept bare tag (FF-0001) or full scanned URL containing tag=
if (strpos($raw, 'tag=') !== false) {
    parse_str(parse_url($raw, PHP_URL_QUERY) ?? '', $params);
    $tag = trim($params['tag'] ?? '');
} else {
    $tag = $raw;
}

if (!$tag) {
    echo json_encode(['success' => false, 'error' => 'Could not parse asset tag from: ' . $raw]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT a.asset_id, a.asset_tag, a.status, a.make, a.model,
           a.assigned_employee_id, a.assigned_location_id,
           ac.name AS category_name,
           e.name AS employee_name,
           l.name AS location_name
    FROM assets a
    LEFT JOIN asset_categories ac ON ac.category_id = a.category_id
    LEFT JOIN employees e ON e.employee_id = a.assigned_employee_id
    LEFT JOIN locations l ON l.location_id = a.assigned_location_id
    WHERE a.asset_tag = ?
");
$stmt->execute([$tag]);
$asset = $stmt->fetch();

if (!$asset) {
    echo json_encode(['success' => false, 'error' => "No asset found for tag: $tag"]);
    exit;
}

$oldStatus = $asset['status'];
$hadEmployee = $asset['assigned_employee_id'] !== null;
$hadLocation = $asset['assigned_location_id'] !== null;

$pdo->prepare("
    UPDATE assets
    SET status = 'Inactive', assigned_employee_id = NULL, assigned_location_id = NULL, updated_at = NOW()
    WHERE asset_id = ?
")->execute([$asset['asset_id']]);

if ($hadEmployee || $hadLocation) {
    logHistory($pdo, $asset['asset_id'], 'Unassigned', null, null, 'Checked in via scan station');
}
logHistory($pdo, $asset['asset_id'], 'Status Changed', null, null, 'Checked in via scan station');

echo json_encode([
    'success'       => true,
    'asset_tag'     => $asset['asset_tag'],
    'asset_name'    => trim($asset['make'] . ' ' . $asset['model']) ?: ($asset['category_name'] ?? ''),
    'category'      => $asset['category_name'] ?? '',
    'old_status'    => $oldStatus,
    'new_status'    => 'Inactive',
    'action'        => 'Checked In',
    'asset_id'      => $asset['asset_id'],
    'had_employee'  => $hadEmployee,
    'had_location'  => $hadLocation,
    'prev_employee' => $asset['employee_name'] ? trim($asset['employee_name']) : null,
    'prev_location' => $asset['location_name'],
]);
