<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$data     = json_decode(file_get_contents('php://input'), true);
$pdo      = getPDO();
$id       = (int)($data['server_id'] ?? 0);
$name     = trim($data['name'] ?? '');
$host     = trim($data['host'] ?? '');
$port     = (int)($data['port'] ?? 0);
$protocol = $data['protocol'] ?? 'tcp';
$desc     = trim($data['description'] ?? '') ?: null;

if (!$name || !$host || !$port) {
    echo json_encode(['success' => false, 'error' => 'Name, host, and port are required.']); exit;
}
if (!in_array($protocol, ['tcp', 'http', 'https'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid protocol.']); exit;
}

if ($id) {
    $pdo->prepare("UPDATE servers SET name=?, host=?, port=?, protocol=?, description=? WHERE server_id=?")
        ->execute([$name, $host, $port, $protocol, $desc, $id]);
    echo json_encode(['success' => true]);
} else {
    $pdo->prepare("INSERT INTO servers (name, host, port, protocol, description) VALUES (?,?,?,?,?)")
        ->execute([$name, $host, $port, $protocol, $desc]);
    echo json_encode(['success' => true, 'server_id' => (int)$pdo->lastInsertId()]);
}
