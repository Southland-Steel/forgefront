<?php
// Landing page for the Entra Manager tools.
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';

// Cheap connectivity check so the dashboard reflects live auth status.
try {
    getEntraAccessToken();
    $connected = true;
    $connError = null;
} catch (Throwable $e) {
    $connected = false;
    $connError = $e->getMessage();
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/_nav.php';
?>
<style>
    .entratrak-card { display: block; text-decoration: none; color: inherit; height: 100%; border: 2px solid transparent; transition: all 0.3s ease; }
    .entratrak-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(190,24,93,.25); border-color: #be185d; }
    .entratrak-card .card-title { color: #1f2937; }
    .entratrak-card .card-text { color: #6c757d; font-size: 0.9rem; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title">
            <i class="fas fa-user-shield me-2" style="background:linear-gradient(to bottom,#f472b6,#be185d);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;"></i>
            Entratrak
        </h4>
        <?php if ($connected): ?>
            <span class="badge bg-success px-3 py-2">● Connected to Entra</span>
        <?php else: ?>
            <span class="badge bg-danger px-3 py-2">● Not connected — <?= htmlspecialchars($connError) ?></span>
        <?php endif; ?>
    </div>

    <div class="row g-3">
        <div class="col-12 col-md-6 col-lg-4">
            <a class="card entratrak-card" href="entra.php">
                <div class="card-body">
                    <h3 class="card-title h5">User License &amp; Role Grid</h3>
                    <p class="card-text">All users with their license assignments, mail property, and SSO app roles.</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <a class="card entratrak-card" href="skus.php">
                <div class="card-body">
                    <h3 class="card-title h5">Tenant License SKUs</h3>
                    <p class="card-text">Every subscribed SKU with GUIDs for the ignore list and grid display names.</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <a class="card entratrak-card" href="usage.php">
                <div class="card-body">
                    <h3 class="card-title h5">License Utilization</h3>
                    <p class="card-text">Who's actually using an assigned license, based on M365 activity — spot reclaim candidates.</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <a class="card entratrak-card" href="attacks.php">
                <div class="card-body">
                    <h3 class="card-title h5">Attack Surveillance</h3>
                    <p class="card-text">Users ranked by failed sign-ins — spot who's being sprayed, legacy-auth exposure, and admins under attack.</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <a class="card entratrak-card" href="legacy.php">
                <div class="card-body">
                    <h3 class="card-title h5">Legacy Authentication</h3>
                    <p class="card-text">Accounts using MFA-bypassing legacy protocols — separates real dependencies from attack noise.</p>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg-4">
            <a class="card entratrak-card" href="known-ips.php">
                <div class="card-body">
                    <h3 class="card-title h5">Known IPs</h3>
                    <p class="card-text">Manage trusted source IPs and scan sign-ins to prepopulate shared office/site addresses.</p>
                </div>
            </a>
        </div>
    </div>
    <p class="text-muted mt-3">Per-user sign-in diagnostics (IP, location, failure reason) open from any user's row in the grid.</p>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
