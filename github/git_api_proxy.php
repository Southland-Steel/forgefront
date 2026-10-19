<?php
// Same-origin proxy for ForgeFront's git puller (deploy/git-puller/git-forgefront.py).
// The puller listens on 127.0.0.1:5090 of this server only, so the hop happens
// server-side; that also avoids the HTTPS mixed-content block ssf ran into.
header('Content-Type: application/json');

$actions = [
    'fetch'  => ['method' => 'POST', 'path' => '/api/fetch'],
    'status' => ['method' => 'GET',  'path' => '/api/status'],
    'log'    => ['method' => 'GET',  'path' => '/api/log'],
];

$action = $_GET['action'] ?? '';
if (!isset($actions[$action])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== $actions[$action]['method']) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$ch = curl_init('http://127.0.0.1:5090' . $actions[$action]['path']);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_POST           => $actions[$action]['method'] === 'POST',
]);
$body = curl_exec($ch);
if ($body === false) {
    http_response_code(502);
    echo json_encode(['error' => 'ForgeFront git puller unreachable (is git-forgefront.py running?): ' . curl_error($ch)]);
} else {
    http_response_code(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 200);
    echo $body;
}
curl_close($ch);
