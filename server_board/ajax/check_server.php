<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLogin();
header('Content-Type: application/json');

$id  = (int)($_GET['id'] ?? 0);
$pdo = getPDO();

if (!$id) { echo json_encode(['error' => 'Missing ID']); exit; }

$server = $pdo->prepare("SELECT * FROM servers WHERE server_id = ?")->execute([$id])
    ? $pdo->prepare("SELECT * FROM servers WHERE server_id = ?") : null;

$stmt = $pdo->prepare("SELECT * FROM servers WHERE server_id = ?");
$stmt->execute([$id]);
$server = $stmt->fetch();

if (!$server) { echo json_encode(['error' => 'Not found']); exit; }

$host     = $server['host'];
$port     = (int)$server['port'];
$protocol = $server['protocol'];
$start    = microtime(true);

if ($protocol === 'http' || $protocol === 'https') {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "$protocol://$host:$port",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_NOBODY         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_errno($ch);
    curl_close($ch);
    $ms = round((microtime(true) - $start) * 1000);

    if ($curlErr) {
        echo json_encode(['status' => 'offline', 'detail' => 'Connection failed', 'ms' => null]);
    } elseif ($httpCode >= 200 && $httpCode < 400) {
        echo json_encode(['status' => 'online', 'detail' => "HTTP $httpCode", 'ms' => $ms]);
    } else {
        echo json_encode(['status' => 'warning', 'detail' => "HTTP $httpCode", 'ms' => $ms]);
    }
} else {
    $conn = @fsockopen($host, $port, $errno, $errstr, 3);
    $ms   = round((microtime(true) - $start) * 1000);
    if ($conn) {
        fclose($conn);
        echo json_encode(['status' => 'online', 'detail' => "Port $port open", 'ms' => $ms]);
    } else {
        echo json_encode(['status' => 'offline', 'detail' => $errstr ?: 'Connection refused', 'ms' => null]);
    }
}
