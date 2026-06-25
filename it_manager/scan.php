<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pdo = getPDO();

$tag    = trim($_GET['tag']    ?? '');
$action = trim($_GET['action'] ?? '');

if (!$tag) { header('Location: /it_manager/inventory.php'); exit; }

$stmt = $pdo->prepare("SELECT asset_id, status FROM assets WHERE asset_tag = ?");
$stmt->execute([$tag]);
$row = $stmt->fetch();

if (!$row) {
    header('Location: /it_manager/inventory.php?scan_error=' . urlencode($tag));
    exit;
}

if ($action === 'toggle') {
    if ($row['status'] === 'Active') {
        $newStatus = 'Inactive';
        $note      = 'Checked in via barcode scan';
    } elseif ($row['status'] === 'Inactive') {
        $newStatus = 'Active';
        $note      = 'Checked out via barcode scan';
    } else {
        // In Repair / Retired / Lost — don't auto-toggle, just view
        header('Location: /it_manager/asset.php?id=' . $row['asset_id']);
        exit;
    }

    $pdo->prepare("UPDATE assets SET status = ?, updated_at = NOW() WHERE asset_id = ?")
        ->execute([$newStatus, $row['asset_id']]);
    logHistory($pdo, $row['asset_id'], 'Status Changed', null, null, $note);
}

header('Location: /it_manager/asset.php?id=' . $row['asset_id']);
exit;
