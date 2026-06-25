<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLoginJson();
header('Content-Type: application/json');

$config = require __DIR__ . '/../../print_labels_config.php';
$data   = json_decode(file_get_contents('php://input'), true);

$assetTag   = trim($data['asset_tag'] ?? '');
$scanUrl    = trim($data['scan_url']  ?? '');
$category   = trim($data['category'] ?? '');
$make       = trim($data['make']     ?? '');
$model      = trim($data['model']    ?? '');
$type       = $data['type']          ?? 'qr';
$printerKey = $data['printer'] ?? $_SESSION['ff_label_printer'] ?? array_key_first($config['printers']);

if (!$assetTag) {
    echo json_encode(['success' => false, 'error' => 'Asset tag required']);
    exit;
}

$printerIp = $config['printers'][$printerKey]['ip'] ?? null;
if (!$printerIp || $printerIp === '192.168.X.X') {
    echo json_encode(['success' => false, 'error' => 'Printer IP not configured in print_labels_config.php']);
    exit;
}

$makeModel  = trim("$make $model") ?: '—';
$port       = (int)($config['port'] ?? 9100);
$toggleUrl  = APP_BASE_URL . '/it_manager/scan.php?tag=' . urlencode($assetTag) . '&action=toggle';

if ($type === 'barcode') {
    // Code 128 barcode label — 2"x3" at 203dpi (406x609 dots)
    // Encode only the short asset tag (FF-XXXX) — keeps bars wide and easy to scan.
    // Scan with a hardware scanner into the scan station to check in/out.
    $zpl = "^XA\n"
         . "^PW406\n"
         . "^LL609\n"
         . "^CI28\n"
         . "^FO15,20^A0N,50,50^FD{$assetTag}^FS\n"
         . "^FO15,75^A0N,28,28^FD{$category}^FS\n"
         . "^FO15,108^A0N,28,28^FD{$makeModel}^FS\n"
         . "^FO15,165^BY3,3,150^BCN,150,N,N,N^FD{$assetTag}^FS\n"
         . "^XZ";
} else {
    // QR code label — 2"x3" at 203dpi (406x609 dots)
    $zpl = "^XA\n"
         . "^PW406\n"
         . "^LL609\n"
         . "^CI28\n"
         . "^FO30,15^BQN,2,5^FDMA,{$scanUrl}^FS\n"
         . "^FO15,280^A0N,50,50^FD{$assetTag}^FS\n"
         . "^FO15,335^A0N,28,28^FD{$category}^FS\n"
         . "^FO15,368^A0N,28,28^FD{$makeModel}^FS\n"
         . "^XZ";
}

$fp = @fsockopen($printerIp, $port, $errno, $errstr, 5);
if (!$fp) {
    echo json_encode(['success' => false, 'error' => "Could not reach printer at {$printerIp} — {$errstr}"]);
    exit;
}
fwrite($fp, $zpl);
fclose($fp);

echo json_encode(['success' => true, 'printer' => $printerIp, 'type' => $type]);
