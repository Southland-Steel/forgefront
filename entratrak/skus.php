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

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/_nav.php';
?>
<style>
    .guid { font-family: ui-monospace, monospace; font-size: 0.85em; color: #6c757d; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header mb-3">
        <h4 class="page-title">Tenant License SKUs</h4>
        <p class="text-muted mb-0">To hide a SKU from the user grid, copy its GUID into <code>IGNORED_SKUS</code> in entra-sync.php.
           To rename a column, add the GUID to <code>LICENSE_NAMES</code>.</p>
    </div>

    <?php if (!empty($syncError)): ?>
        <div class="alert alert-danger">SKU fetch failed: <?= htmlspecialchars($syncError) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Part Number</th>
                        <th>Grid Display Name</th>
                        <th>SKU GUID</th>
                        <th class="text-end">Assigned</th>
                        <th class="text-end">Purchased</th>
                        <th class="pe-3">Grid Status</th>
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
                            <td class="ps-3"><strong><?= htmlspecialchars($sku['skuPartNumber'] ?? '') ?></strong></td>
                            <td><?= htmlspecialchars($name) ?></td>
                            <td class="guid"><?= htmlspecialchars($guid) ?></td>
                            <td class="text-end">
                                <?php $assigned = (int)($sku['consumedUnits'] ?? 0); ?>
                                <?php if ($assigned > 0): ?>
                                    <a href="usage.php?sku=<?= urlencode($guid) ?>" title="View utilization for the users assigned this license"><?= $assigned ?></a>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= (int)$purchased ?></td>
                            <td class="pe-3"><span class="badge <?= $ignored ? 'bg-secondary' : 'bg-success' ?>">
                                <?= $ignored ? 'Ignored' : 'Shown' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
