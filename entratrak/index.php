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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Entra Manager</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: none; margin: 0; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h1 { color: #1e3d59; margin-bottom: 6px; }
        p.sub { color: #657786; margin-top: 0; margin-bottom: 24px; }
        .status { display: inline-block; padding: 6px 12px; border-radius: 12px; font-size: 0.9em; font-weight: bold; margin-bottom: 24px; }
        .ok { background: #e8f8f5; color: #2e7d32; }
        .bad { background: #fce4d6; color: #c0392b; }
        .cards { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .card { display: block; text-decoration: none; color: inherit; border: 1px solid #e1e8ed; border-radius: 8px; padding: 20px; transition: box-shadow 0.15s, border-color 0.15s; }
        .card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: #1e3d59; }
        .card h3 { color: #1e3d59; margin: 0 0 8px; }
        .card p { color: #657786; margin: 0; font-size: 0.9em; }
    </style>
</head>
<body>

<div class="container">
    <h1>Entra Manager</h1>
    <p class="sub">Internal identity, license, and role tools</p>

    <?php if ($connected): ?>
        <div class="status ok">● Connected to Entra</div>
    <?php else: ?>
        <div class="status bad">● Not connected — <?php echo htmlspecialchars($connError); ?></div>
    <?php endif; ?>

    <div class="cards">
        <a class="card" href="entra.php">
            <h3>User License &amp; Role Grid</h3>
            <p>All users with their license assignments, mail property, and SSO app roles.</p>
        </a>
        <a class="card" href="skus.php">
            <h3>Tenant License SKUs</h3>
            <p>Every subscribed SKU with GUIDs for the ignore list and grid display names.</p>
        </a>
        <a class="card" href="usage.php">
            <h3>License Utilization</h3>
            <p>Who's actually using an assigned license, based on M365 activity — spot reclaim candidates.</p>
        </a>
        <a class="card" href="attacks.php">
            <h3>Attack Surveillance</h3>
            <p>Users ranked by failed sign-ins — spot who's being sprayed, legacy-auth exposure, and admins under attack.</p>
        </a>
        <a class="card" href="legacy.php">
            <h3>Legacy Authentication</h3>
            <p>Accounts using MFA-bypassing legacy protocols — separates real dependencies from attack noise.</p>
        </a>
        <a class="card" href="known-ips.php">
            <h3>Known IPs</h3>
            <p>Manage trusted source IPs and scan sign-ins to prepopulate shared office/site addresses.</p>
        </a>
    </div>
    <p class="sub" style="margin-top:20px">Per-user sign-in diagnostics (IP, location, failure reason) open from any user's row in the grid.</p>
</div>

</body>
</html>
