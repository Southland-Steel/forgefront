<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLogin();
header('Content-Type: application/json');

$id  = (int)($_GET['id'] ?? 0);
$pdo = getPDO();
if (!$id) { echo json_encode(['error' => 'Missing ID']); exit; }

// Last 90 checks
$stmt = $pdo->prepare("SELECT status AS s, response_ms AS ms, checked_at FROM server_checks WHERE server_id = ? ORDER BY check_id DESC LIMIT 90");
$stmt->execute([$id]);
$history = $stmt->fetchAll();

// 30-day uptime
$uStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'online') AS online_count FROM server_checks WHERE server_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$uStmt->execute([$id]);
$uRow      = $uStmt->fetch();
$uptime30d = $uRow['total'] > 0 ? round(($uRow['online_count'] / $uRow['total']) * 100, 2) : null;

// Incident counts
$iStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(resolved_at IS NULL) AS open_count FROM server_incidents WHERE server_id = ?");
$iStmt->execute([$id]);
$iRow = $iStmt->fetch();

// Total downtime last 30 days
$dtStmt = $pdo->prepare("SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(resolved_at, NOW()))), 0) AS total_sec FROM server_incidents WHERE server_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$dtStmt->execute([$id]);
$totalDownSec = (int)$dtStmt->fetchColumn();

// Longest outage
$loStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, started_at, COALESCE(resolved_at, NOW())) AS duration_sec FROM server_incidents WHERE server_id = ? ORDER BY duration_sec DESC LIMIT 1");
$loStmt->execute([$id]);
$longestOutageSec = (int)($loStmt->fetchColumn() ?: 0);

// 24-hour timeline buckets
$tlStmt = $pdo->prepare("
    SELECT FLOOR(TIMESTAMPDIFF(SECOND, checked_at, NOW()) / 3600) AS bucket,
           SUM(status = 'online') AS online_count,
           COUNT(*) AS total_count
    FROM server_checks
    WHERE server_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY bucket
    ORDER BY bucket
");
$tlStmt->execute([$id]);
$timelineBuckets = array_fill(0, 24, null);
foreach ($tlStmt->fetchAll() as $row) {
    $b = (int)$row['bucket'];
    if ($b >= 0 && $b < 24) $timelineBuckets[$b] = [(int)$row['online_count'], (int)$row['total_count']];
}

$last = !empty($history) ? $history[0] : null;

echo json_encode([
    'status'          => $last ? $last['s']  : null,
    'ms'              => $last ? $last['ms'] : null,
    'checked_at'      => $last ? $last['checked_at'] : null,
    'history'         => $history,
    'uptime_30d'      => $uptime30d,
    'uptime_total'    => (int)$uRow['total'],
    'incident_total'  => (int)$iRow['total'],
    'incident_open'   => (int)$iRow['open_count'],
    'total_down_sec'  => $totalDownSec,
    'longest_outage'  => $longestOutageSec,
    'timeline'        => $timelineBuckets,
]);
