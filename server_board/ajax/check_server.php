<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLogin();
header('Content-Type: application/json');

$id  = (int)($_GET['id'] ?? 0);
$pdo = getPDO();

if (!$id) { echo json_encode(['error' => 'Missing ID']); exit; }

$stmt = $pdo->prepare("SELECT * FROM servers WHERE server_id = ?");
$stmt->execute([$id]);
$server = $stmt->fetch();

if (!$server) { echo json_encode(['error' => 'Not found']); exit; }

$host     = $server['host'];
$port     = (int)$server['port'];
$protocol = $server['protocol'];
$start    = microtime(true);
$status   = 'offline';
$ms       = null;
$detail   = '';

switch ($protocol) {
    case 'http':
    case 'https':
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
            $status = 'offline'; $detail = 'Connection failed';
        } elseif ($httpCode >= 200 && $httpCode < 400) {
            $status = 'online'; $detail = "HTTP $httpCode";
        } else {
            $status = 'warning'; $detail = "HTTP $httpCode";
        }
        break;

    case 'ping':
        $escaped = escapeshellarg($host);
        $canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
        if ($canExec) {
            exec("ping -n 1 -w 1000 $escaped 2>&1", $out, $ret);
            foreach ($out as $line) {
                if (preg_match('/[=<]([\d]+)ms/i', $line, $m)) {
                    $ms = (int)round((float)$m[1]);
                    break;
                }
            }
            if ($ms === null && $ret === 0) $ms = round((microtime(true) - $start) * 1000);
            $status = ($ret === 0) ? 'online'  : 'offline';
            $detail = ($ret === 0) ? 'ICMP reply' : 'No ICMP reply';
        } else {
            $status = 'warning';
            $detail = 'exec() disabled — cannot ping';
        }
        break;

    case 'dns':
        $resolved = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ms       = round((microtime(true) - $start) * 1000);
        if (!empty($resolved)) {
            $ip     = $resolved[0]['ip'] ?? ($resolved[0]['ipv6'] ?? '?');
            $status = 'online';
            $detail = "Resolves to $ip";
        } else {
            $status = 'offline';
            $detail = 'DNS resolution failed';
        }
        break;

    case 'mysql':
        $conn = @fsockopen($host, $port, $errno, $errstr, 3);
        $ms   = round((microtime(true) - $start) * 1000);
        if ($conn) {
            $banner = @fread($conn, 128);
            fclose($conn);
            $status = 'online';
            $detail = strlen((string)$banner) > 4 ? 'MySQL responding' : "Port $port open";
        } else {
            $status = 'offline';
            $detail = $errstr ?: 'Connection refused';
        }
        break;

    default: // tcp
        $conn = @fsockopen($host, $port, $errno, $errstr, 3);
        $ms   = round((microtime(true) - $start) * 1000);
        if ($conn) {
            fclose($conn);
            $status = 'online';
            $detail = "Port $port open";
        } else {
            $status = 'offline';
            $detail = $errstr ?: 'Connection refused';
        }
        break;
}

// Store result
$pdo->prepare("INSERT INTO server_checks (server_id, status, response_ms, detail) VALUES (?,?,?,?)")
    ->execute([$id, $status, $ms, $detail]);

// Keep last 500 per server
$pdo->prepare("
    DELETE FROM server_checks WHERE server_id = ? AND check_id NOT IN (
        SELECT check_id FROM (
            SELECT check_id FROM server_checks WHERE server_id = ? ORDER BY check_id DESC LIMIT 500
        ) AS t
    )
")->execute([$id, $id]);

// Incident management
$openStmt = $pdo->prepare("SELECT * FROM server_incidents WHERE server_id = ? AND resolved_at IS NULL ORDER BY started_at DESC LIMIT 1");
$openStmt->execute([$id]);
$incident = $openStmt->fetch();

if ($status !== 'online') {
    if (!$incident) {
        $pdo->prepare("INSERT INTO server_incidents (server_id, started_at, detail) VALUES (?, NOW(), ?)")
            ->execute([$id, $detail]);

        if (!empty($server['notify_email'])) {
            $to      = $server['notify_email'];
            $subject = "[ForgeFront Alert] {$server['name']} is {$status}";
            $body    = "Server \"{$server['name']}\" ({$host}:{$port}) went {$status}.\r\n"
                     . "Detail: {$detail}\r\n"
                     . "Time: " . date('Y-m-d H:i:s') . "\r\n\r\n"
                     . "View dashboard: " . APP_BASE_URL . "/server_board/\r\n";
            @mail($to, $subject, $body, "From: noreply@forgefront.local\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        }
    }
} elseif ($incident) {
    $pdo->prepare("UPDATE server_incidents SET resolved_at = NOW() WHERE incident_id = ?")
        ->execute([$incident['incident_id']]);

    if (!empty($server['notify_email'])) {
        $durSec  = time() - strtotime($incident['started_at']);
        $dur     = gmdate($durSec >= 3600 ? 'G\h i\m' : 'i\m s\s', $durSec);
        $to      = $server['notify_email'];
        $subject = "[ForgeFront] {$server['name']} recovered";
        $body    = "Server \"{$server['name']}\" ({$host}:{$port}) is back online.\r\n"
                 . "Was down for: {$dur}\r\n"
                 . "Time: " . date('Y-m-d H:i:s') . "\r\n\r\n"
                 . "View dashboard: " . APP_BASE_URL . "/server_board/\r\n";
        @mail($to, $subject, $body, "From: noreply@forgefront.local\r\nContent-Type: text/plain; charset=UTF-8\r\n");
    }
}

// Return last 90 checks + uptime % — use short keys to match initData shape
$histStmt = $pdo->prepare("SELECT status AS s, response_ms AS ms, checked_at FROM server_checks WHERE server_id = ? ORDER BY check_id DESC LIMIT 90");
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();

$uptimeStmt = $pdo->prepare("
    SELECT COUNT(*) AS total, SUM(status = 'online') AS online_count
    FROM server_checks
    WHERE server_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$uptimeStmt->execute([$id]);
$u = $uptimeStmt->fetch();
$uptime30d = $u['total'] > 0 ? round(($u['online_count'] / $u['total']) * 100, 2) : null;

echo json_encode([
    'status'     => $status,
    'ms'         => $ms,
    'detail'     => $detail,
    'history'    => $history,
    'uptime_30d' => $uptime30d,
]);
