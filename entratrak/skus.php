<?php
// SKU explorer: lists every license SKU the tenant subscribes to,
// with the GUIDs you need for IGNORED_SKUS / LICENSE_NAMES in entra-sync.php.
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';

try {
    if (isset($_GET['refresh'])) cacheClear();
    $skus = cacheRemember('skus', CACHE_TTL_SKUS,
        fn() => fetchSubscribedSkus(getEntraAccessToken()));
    $syncError = null;
} catch (Throwable $e) {
    $skus = [];
    $syncError = $e->getMessage();
}

// Sort by part number for a stable listing
usort($skus, fn($a, $b) => strcmp($a['skuPartNumber'] ?? '', $b['skuPartNumber'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tenant License SKUs</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: none; margin: 0; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2 { color: #1e3d59; margin-bottom: 20px; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px; }
        p.hint { color: #657786; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e1e8ed; }
        th { background-color: #1e3d59; color: white; font-weight: 600; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .guid { font-family: ui-monospace, monospace; font-size: 0.85em; color: #555; }
        .num { text-align: right; }
        a.deep { color: #1e6fce; text-decoration: none; font-weight: 600; }
        a.deep:hover { text-decoration: underline; }
        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: bold; }
        .shown { background: #e8f8f5; color: #2e7d32; }
        .ignored { background: #fce4d6; color: #c0392b; }
        .error-banner { background: #fdecea; color: #c0392b; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        .nav { margin-bottom: 20px; font-size: 0.9em; }
        .nav a { color: #1e3d59; text-decoration: none; font-weight: 600; }
        .nav a:hover { text-decoration: underline; }
        .nav .sep { color: #ccd6dd; margin: 0 10px; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav">
        <a href="index.php">← Home</a><span class="sep">|</span><a href="entra.php">User License &amp; Role Grid</a>
    </div>
    <h2>Tenant License SKUs (subscribedSkus)</h2>
    <p class="hint">To hide a SKU from the user grid, copy its GUID into <code>IGNORED_SKUS</code> in entra-sync.php.
       To rename a column, add the GUID to <code>LICENSE_NAMES</code>.</p>

    <?php if (!empty($syncError)): ?>
        <div class="error-banner">SKU fetch failed: <?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Part Number</th>
                <th>Grid Display Name</th>
                <th>SKU GUID</th>
                <th class="num">Assigned</th>
                <th class="num">Purchased</th>
                <th>Grid Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($skus as $sku):
                $guid      = $sku['skuId'];
                $ignored   = in_array($guid, IGNORED_SKUS);
                $name      = LICENSE_NAMES[$guid] ?? $sku['skuPartNumber'];
                $purchased = $sku['prepaidUnits']['enabled'] ?? 0;
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($sku['skuPartNumber'] ?? ''); ?></strong></td>
                    <td><?php echo htmlspecialchars($name); ?></td>
                    <td class="guid"><?php echo htmlspecialchars($guid); ?></td>
                    <td class="num">
                        <?php $assigned = (int)($sku['consumedUnits'] ?? 0); ?>
                        <?php if ($assigned > 0): ?>
                            <a class="deep" href="usage.php?sku=<?php echo urlencode($guid); ?>" title="View utilization for the users assigned this license"><?php echo $assigned; ?></a>
                        <?php else: ?>
                            0
                        <?php endif; ?>
                    </td>
                    <td class="num"><?php echo (int)$purchased; ?></td>
                    <td><span class="status-badge <?php echo $ignored ? 'ignored' : 'shown'; ?>">
                        <?php echo $ignored ? 'Ignored' : 'Shown'; ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>
