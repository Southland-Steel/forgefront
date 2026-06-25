<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
Auth::requireLogin();
include __DIR__ . '/includes/header.php';
?>
<div class="container-fluid px-4 pt-4 text-center">
    <i class="fas fa-ban fa-4x text-danger mb-3"></i>
    <h3>Access Denied</h3>
    <p class="text-muted">You don't have permission to view that page.</p>
    <a href="/index.php" class="btn btn-primary">Back to Hub</a>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
