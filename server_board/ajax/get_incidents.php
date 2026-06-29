<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLogin();
header('Content-Type: application/json');

$id  = (int)($_GET['id'] ?? 0);
$pdo = getPDO();

if (!$id) { echo json_encode(['error' => 'Missing ID']); exit; }

$stmt = $pdo->prepare("
    SELECT incident_id, started_at, resolved_at, detail,
           TIMESTAMPDIFF(SECOND, started_at, COALESCE(resolved_at, NOW())) AS duration_sec
    FROM server_incidents
    WHERE server_id = ?
    ORDER BY started_at DESC
    LIMIT 50
");
$stmt->execute([$id]);
echo json_encode(['incidents' => $stmt->fetchAll()]);
