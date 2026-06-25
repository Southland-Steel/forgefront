<?php
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: application/json');

$data  = json_decode(file_get_contents('php://input'), true);
$token = trim($data['token'] ?? '');

if (!AGENT_TOKEN || !hash_equals(AGENT_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$raw = trim($data['scan_data'] ?? '');

if (!$raw) { echo json_encode(['success' => false, 'error' => 'No scan data']); exit; }

if (strpos($raw, 'tag=') !== false) {
    parse_str(parse_url($raw, PHP_URL_QUERY) ?? '', $params);
    $tag = trim($params['tag'] ?? '');
} else {
    $tag = strtoupper($raw);
}

if (!$tag) { echo json_encode(['success' => false, 'error' => 'Could not parse tag']); exit; }

$stmt = $pdo->prepare("
    SELECT a.asset_id, a.asset_tag, a.status, a.make, a.model,
           ac.name AS category_name
    FROM assets a
    LEFT JOIN asset_categories ac ON ac.category_id = a.category_id
    WHERE a.asset_tag = ?
");
$stmt->execute([$tag]);
$asset = $stmt->fetch();

if (!$asset) {
    echo json_encode(['success' => false, 'error' => "No asset found: $tag"]);
    exit;
}

if ($asset['status'] === 'Active') {
    $newStatus = 'Inactive';
    $action    = 'Checked In';
} elseif ($asset['status'] === 'Inactive') {
    $newStatus = 'Active';
    $action    = 'Checked Out';
} else {
    echo json_encode([
        'success'   => false,
        'error'     => "Cannot toggle — status is \"{$asset['status']}\"",
        'asset_tag' => $asset['asset_tag'],
    ]);
    exit;
}

$pdo->prepare("UPDATE assets SET status = ?, updated_at = NOW() WHERE asset_id = ?")
    ->execute([$newStatus, $asset['asset_id']]);
logHistory($pdo, $asset['asset_id'], 'Status Changed', null, null, "$action via scanner agent");

echo json_encode([
    'success'    => true,
    'asset_tag'  => $asset['asset_tag'],
    'asset_name' => trim($asset['make'] . ' ' . $asset['model']) ?: ($asset['category_name'] ?? ''),
    'old_status' => $asset['status'],
    'new_status' => $newStatus,
    'action'     => $action,
    'asset_id'   => $asset['asset_id'],
]);
