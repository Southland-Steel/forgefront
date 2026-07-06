<?php
// License Utilization: for a chosen SKU, list assigned users with their
// Microsoft 365 per-service last-activity dates, so unused licenses (reclaim
// candidates) stand out. Requires Reports.Read.All (+ admin consent).
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';

// Staleness thresholds (days since last activity)
define('STALE_DAYS', 90);   // >= this (or never) → reclaim candidate
define('WARN_DAYS', 30);    // >= this → watch

$skuFilter = $_GET['sku'] ?? '';
$rows = [];
$skus = [];
$syncError = null;
$reportDate = null;
$reportError = null;

try {
    if (isset($_GET['refresh'])) cacheClear();
    $token = getEntraAccessToken();
    $skus  = cacheRemember('skus', CACHE_TTL_SKUS, fn() => fetchSubscribedSkus($token));
    $users = cacheRemember('users_v2', CACHE_TTL_USERS, fn() => fetchEntraUsers($token));

    // Reports are optional — degrade gracefully if the permission is missing.
    $usage = [];
    $mailbox = [];
    try {
        $usage   = cacheRemember('usage_D90', 6 * 3600, fn() => fetchUsageReport($token, 'D90'));
        $mailbox = cacheRemember('mailbox_D90', 6 * 3600, fn() => fetchMailboxUsage($token, 'D90'));
    } catch (RuntimeException $e) {
        // Any report failure is non-fatal — still show assigned users.
        if (str_contains($e->getMessage(), 'Reports.Read.All') || str_contains($e->getMessage(), '403')) {
            $reportError = 'Activity data unavailable: the app needs the Reports.Read.All permission '
                . '(grant admin consent, then Refresh). Showing assigned users without activity dates.';
        } else {
            $reportError = 'Activity report could not be loaded (' . $e->getMessage() . '). Showing assigned users without activity dates.';
        }
    }

    if ($skuFilter !== '') {
        $assigned = array_values(array_filter($users, fn($u) =>
            in_array($skuFilter, array_column($u['assignedLicenses'] ?? [], 'skuId'))));

        foreach ($assigned as $u) {
            $upn = strtolower($u['userPrincipalName'] ?? '');
            $r = $usage[$upn] ?? null;
            $mb = $mailbox[$upn] ?? null;
            $svc = [
                'Teams'      => $r['teamsLastActivityDate']      ?? null,
                'SharePoint' => $r['sharePointLastActivityDate'] ?? null,
                'OneDrive'   => $r['oneDriveLastActivityDate']   ?? null,
            ];
            $mbActivity = $mb['lastActivityDate'] ?? ($r['exchangeLastActivityDate'] ?? null);
            $lastSignIn = $u['signInActivity']['lastSuccessfulSignInDateTime'] ?? null;

            // Overall last activity = most recent across mailbox + services
            $stamps = array_filter(array_map(
                fn($d) => $d ? strtotime($d) : 0,
                array_merge($svc, ['mb' => $mbActivity])
            ));
            $lastTs = $stamps ? max($stamps) : 0;
            $days = $lastTs ? (int) floor((time() - $lastTs) / 86400) : null;

            $rows[] = [
                'displayName'   => $u['displayName'] ?? $u['userPrincipalName'] ?? '(no name)',
                'upn'           => $u['userPrincipalName'] ?? '',
                'enabled'       => (bool) ($u['accountEnabled'] ?? false),
                'services'      => $svc,
                'mbActivity'    => $mbActivity,
                'items'         => $mb['itemCount'] ?? null,
                'storageBytes'  => $mb['storageBytes'] ?? null,
                'lastSignIn'    => $lastSignIn,
                'lastTs'        => $lastTs,
                'days'          => $days,
                'hasReportRow'  => $r !== null || $mb !== null,
            ];
        }
        // Most stale first: never-active (days null) at top, then largest gap
        usort($rows, fn($a, $b) => ($a['lastTs'] ?: 0) <=> ($b['lastTs'] ?: 0));
    }

    // Report freshness stamp (from any usage row)
    if (!empty($usage)) {
        $first = reset($usage);
        $reportDate = $first['reportRefreshDate'] ?? null;
    }
} catch (Throwable $e) {
    $syncError = $e->getMessage();
}

// SKU dropdown options (from subscribed SKUs, friendly names where known)
$skuOptions = [];
foreach ($skus as $s) {
    $skuOptions[$s['skuId']] = LICENSE_NAMES[$s['skuId']] ?? $s['skuPartNumber'];
}
asort($skuOptions);
$skuName = $skuOptions[$skuFilter] ?? $skuFilter;

// Verdict helper
function verdict(?int $days, bool $hasRow): array {
    if (!$hasRow || $days === null) return ['Never / no data', 'bad'];
    if ($days >= STALE_DAYS) return [$days . 'd ago', 'bad'];
    if ($days >= WARN_DAYS)  return [$days . 'd ago', 'warn'];
    return [$days . 'd ago', 'ok'];
}
function fmt(?string $d): string { return $d ?: '—'; }
function fmtStorage(?int $b): string {
    if ($b === null) return '—';
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
    return round($b / 1024) . ' KB';
}

$reclaim = count(array_filter($rows, fn($r) => !$r['hasReportRow'] || $r['days'] === null || $r['days'] >= STALE_DAYS));
$verdictBadge = ['ok' => 'bg-success', 'warn' => 'bg-warning text-dark', 'bad' => 'bg-danger'];

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/_nav.php';
?>
<style>
    th.sortable { cursor: pointer; user-select: none; }
    th.sortable .arrow { font-size: 0.8em; }
    .upn { display: block; color: #6c757d; font-size: 0.82em; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header mb-3">
        <h4 class="page-title">License Utilization</h4>
        <p class="text-muted mb-0">Who is actually using an assigned license, based on Microsoft 365 activity (Exchange, Teams, SharePoint, OneDrive) over the last 90 days.
            <?php if ($reportDate): ?>Report data as of <?= htmlspecialchars($reportDate) ?>.<?php endif; ?></p>
    </div>

    <form class="d-flex gap-3 align-items-center flex-wrap mb-3" method="get">
        <label class="d-flex align-items-center gap-2 mb-0">License:
            <select name="sku" class="form-select" style="width:auto" onchange="this.form.submit()">
                <option value="">— choose a SKU —</option>
                <?php foreach ($skuOptions as $guid => $name): ?>
                    <option value="<?= htmlspecialchars($guid) ?>" <?= $guid === $skuFilter ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($skuFilter !== ''): ?>
            <a href="?sku=<?= urlencode($skuFilter) ?>&refresh=1">↻ Refresh</a>
        <?php endif; ?>
    </form>

    <?php if ($syncError): ?>
        <div class="alert alert-danger">Failed: <?= htmlspecialchars($syncError) ?></div>
    <?php endif; ?>
    <?php if ($reportError): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($reportError) ?></div>
    <?php endif; ?>

    <?php if ($skuFilter === '' && !$syncError): ?>
        <p class="text-muted">Pick a license above to see who's using it. Reclaim candidates (no activity in <?= STALE_DAYS ?>+ days) are listed first.</p>
    <?php elseif (!$syncError): ?>
        <div class="d-flex gap-3 mb-3">
            <div class="card px-3 py-2"><div class="fs-4 fw-bold"><?= count($rows) ?></div><div class="text-muted small">Assigned</div></div>
            <div class="card px-3 py-2"><div class="fs-4 fw-bold text-danger"><?= $reclaim ?></div><div class="text-muted small">Reclaim candidates<br>(<?= STALE_DAYS ?>+ days idle)</div></div>
        </div>

        <div class="mb-2 d-flex align-items-center gap-2">
            <input type="text" id="userFilter" class="form-control" style="max-width:320px" placeholder="Search name or UPN…" oninput="filterRows()" autocomplete="off">
            <span class="text-muted small" id="filterCount"></span>
        </div>
        <div class="card">
        <div class="table-responsive">
        <table id="usageTable" class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="sortable ps-3" onclick="sortTable(this)">User</th>
                    <th class="sortable" onclick="sortTable(this)">Status</th>
                    <th class="sortable" onclick="sortTable(this)">Verdict</th>
                    <th class="sortable" onclick="sortTable(this)">Last Sign-in</th>
                    <th class="sortable" onclick="sortTable(this)" title="Mailbox-level activity (counts delegated access)">Mailbox Activity</th>
                    <th class="sortable" onclick="sortTable(this)">Items</th>
                    <th class="sortable" onclick="sortTable(this)">Storage</th>
                    <th class="sortable" onclick="sortTable(this)">Teams</th>
                    <th class="sortable" onclick="sortTable(this)">SharePoint</th>
                    <th class="sortable pe-3" onclick="sortTable(this)">OneDrive</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    [$label, $cls] = verdict($r['days'], $r['hasReportRow']);
                    $ts = fn($d) => $d ? strtotime($d) : 0;
                ?>
                    <tr>
                        <td class="ps-3" data-sort="<?= htmlspecialchars(strtolower($r['displayName'])) ?>">
                            <strong><?= htmlspecialchars($r['displayName']) ?></strong>
                            <span class="upn"><?= htmlspecialchars($r['upn']) ?></span>
                        </td>
                        <td data-sort="<?= $r['enabled'] ? 1 : 0 ?>"><?= $r['enabled'] ? 'Enabled' : '<span class="badge bg-danger">Disabled</span>' ?></td>
                        <td data-sort="<?= (int) $r['lastTs'] ?>"><span class="badge <?= $verdictBadge[$cls] ?>"><?= htmlspecialchars($label) ?></span></td>
                        <td data-sort="<?= $ts($r['lastSignIn']) ?>"><?= htmlspecialchars($r['lastSignIn'] ? substr($r['lastSignIn'], 0, 10) : 'never') ?></td>
                        <td data-sort="<?= $ts($r['mbActivity']) ?>"><?= htmlspecialchars(fmt($r['mbActivity'])) ?></td>
                        <td data-sort="<?= (int) ($r['items'] ?? 0) ?>"><?= $r['items'] === null ? '—' : number_format($r['items']) ?></td>
                        <td data-sort="<?= (int) ($r['storageBytes'] ?? 0) ?>"><?= htmlspecialchars(fmtStorage($r['storageBytes'])) ?></td>
                        <td data-sort="<?= $ts($r['services']['Teams']) ?>"><?= htmlspecialchars(fmt($r['services']['Teams'])) ?></td>
                        <td data-sort="<?= $ts($r['services']['SharePoint']) ?>"><?= htmlspecialchars(fmt($r['services']['SharePoint'])) ?></td>
                        <td class="pe-3" data-sort="<?= $ts($r['services']['OneDrive']) ?>"><?= htmlspecialchars(fmt($r['services']['OneDrive'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
        <script>
        function filterRows() {
            const q = document.getElementById('userFilter').value.trim().toLowerCase();
            const rows = document.querySelectorAll('#usageTable tbody tr');
            let shown = 0;
            rows.forEach(r => {
                const match = q === '' || r.textContent.toLowerCase().includes(q);
                r.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            document.getElementById('filterCount').textContent =
                q === '' ? `${rows.length} users` : `${shown} of ${rows.length}`;
        }
        let sortState = { col: null, dir: 1 };
        function sortTable(th) {
            const table = th.closest('table');
            const tbody = table.tBodies[0];
            const col = th.cellIndex;
            sortState.dir = (sortState.col === col) ? -sortState.dir : 1;
            sortState.col = col;
            Array.from(tbody.rows).sort((a, b) => {
                const av = a.cells[col].dataset.sort ?? a.cells[col].textContent.trim();
                const bv = b.cells[col].dataset.sort ?? b.cells[col].textContent.trim();
                const an = parseFloat(av), bn = parseFloat(bv);
                const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : String(av).localeCompare(String(bv));
                return cmp * sortState.dir;
            }).forEach(r => tbody.appendChild(r));
            table.querySelectorAll('th .arrow').forEach(a => a.remove());
            const arrow = document.createElement('span');
            arrow.className = 'arrow';
            arrow.textContent = sortState.dir > 0 ? ' ▲' : ' ▼';
            th.appendChild(arrow);
        }
        filterRows();
        </script>
        <p class="text-muted">Note: this report covers core Microsoft 365 services only. SKUs like Power BI, Project, or Visio won't show activity here even when used.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
