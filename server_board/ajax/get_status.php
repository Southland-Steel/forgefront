<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLogin();
header('Content-Type: application/json');

$pdo     = getPDO();
$servers = $pdo->query("SELECT server_id FROM servers")->fetchAll();

if (empty($servers)) { echo json_encode([]); exit; }

$ids = implode(',', array_map('intval', array_column($servers, 'server_id')));

$statusRows = $pdo->query("
    SELECT sc.server_id, sc.status, sc.response_ms
    FROM server_checks sc
    INNER JOIN (
        SELECT server_id, MAX(check_id) AS max_id
        FROM server_checks WHERE server_id IN ($ids)
        GROUP BY server_id
    ) latest ON sc.server_id = latest.server_id AND sc.check_id = latest.max_id
")->fetchAll();

$uptimeRows = $pdo->query("
    SELECT server_id, ROUND(SUM(status = 'online') / COUNT(*) * 100, 2) AS uptime
    FROM server_checks
    WHERE server_id IN ($ids) AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY server_id
")->fetchAll();

$result = [];
foreach ($uptimeRows as $r) $result[$r['server_id']]['uptime'] = (float)$r['uptime'];
foreach ($statusRows as $r) {
    $result[$r['server_id']]['status'] = $r['status'];
    $result[$r['server_id']]['ms']     = $r['response_ms'];
}

echo json_encode($result);
