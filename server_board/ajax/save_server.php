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
$email    = trim($data['notify_email'] ?? '') ?: null;

$validProtocols = ['tcp', 'http', 'https', 'ping', 'dns', 'mysql'];

if (!$name || !$host || !$port) {
    echo json_encode(['success' => false, 'error' => 'Name, host, and port are required.']); exit;
}
if (!in_array($protocol, $validProtocols, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid protocol.']); exit;
}
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid notification email address.']); exit;
}

if ($id) {
    $pdo->prepare("UPDATE servers SET name=?, host=?, port=?, protocol=?, description=?, notify_email=? WHERE server_id=?")
        ->execute([$name, $host, $port, $protocol, $desc, $email, $id]);
    echo json_encode(['success' => true]);
} else {
    $pdo->prepare("INSERT INTO servers (name, host, port, protocol, description, notify_email) VALUES (?,?,?,?,?,?)")
        ->execute([$name, $host, $port, $protocol, $desc, $email]);
    echo json_encode(['success' => true, 'server_id' => (int)$pdo->lastInsertId()]);
}
