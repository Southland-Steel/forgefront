<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id   = (int)($data['server_id'] ?? 0);
$pdo  = getPDO();

if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing server ID.']); exit; }

$pdo->prepare("DELETE FROM server_checks    WHERE server_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM server_incidents WHERE server_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM servers          WHERE server_id = ?")->execute([$id]);

echo json_encode(['success' => true]);
