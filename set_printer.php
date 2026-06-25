<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
Auth::requireLogin();

$config     = require __DIR__ . '/print_labels_config.php';
$key        = $_POST['printer']      ?? '';
$redirect   = $_POST['redirect_url'] ?? '/it_manager/index.php';

if (array_key_exists($key, $config['printers'])) {
    $_SESSION['ff_label_printer'] = $key;
}

header('Location: ' . $redirect);
exit;
