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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>License Utilization</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: none; margin: 0; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2 { color: #1e3d59; margin-bottom: 6px; }
        .nav { margin-bottom: 20px; font-size: 0.9em; }
        .nav a { color: #1e3d59; text-decoration: none; font-weight: 600; }
        .nav a:hover { text-decoration: underline; }
        .nav .sep { color: #ccd6dd; margin: 0 10px; }
        .hint { color: #657786; font-size: 0.9em; }
        .bar { margin: 14px 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .bar select { padding: 9px 12px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.95em; background: #fff; color: #1e3d59; }
        .summary { display: flex; gap: 16px; margin: 16px 0; }
        .stat { border: 1px solid #e1e8ed; border-radius: 8px; padding: 12px 18px; min-width: 120px; }
        .stat .n { font-size: 1.6em; font-weight: 700; color: #1e3d59; }
        .stat.bad .n { color: #c0392b; }
        .stat .l { color: #657786; font-size: 0.85em; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e1e8ed; font-size: 0.9em; }
        th { background-color: #1e3d59; color: white; }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #274d6e; }
        th .arrow { font-size: 0.8em; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .filter { padding: 9px 12px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.95em; min-width: 260px; }
        .filter:focus { outline: none; border-color: #1e3d59; }
        .filter-count { color: #657786; font-size: 0.9em; margin-left: 10px; }
        .upn { display: block; color: #657786; font-size: 0.82em; }
        .pill { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 0.82em; font-weight: bold; }
        .pill.ok { background: #e8f8f5; color: #2e7d32; }
        .pill.warn { background: #fff4e0; color: #8a5a00; }
        .pill.bad { background: #fce4d6; color: #c0392b; }
        .badge { padding: 3px 8px; border-radius: 12px; font-size: 0.8em; font-weight: bold; }
        .badge.dis { background: #fce4d6; color: #c0392b; }
        .error-banner { background: #fdecea; color: #c0392b; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        .warn-banner { background: #fff4e0; color: #8a5a00; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        a.deep { color: #1e6fce; text-decoration: none; font-weight: 600; }
        a.deep:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav">
        <a href="index.php">← Home</a><span class="sep">|</span><a href="entra.php">User License Grid</a><span class="sep">|</span><a href="skus.php">Tenant License SKUs</a>
    </div>
    <h2>License Utilization</h2>
    <p class="hint">Who is actually using an assigned license, based on Microsoft 365 activity (Exchange, Teams, SharePoint, OneDrive) over the last 90 days.
        <?php if ($reportDate): ?>Report data as of <?php echo htmlspecialchars($reportDate); ?>.<?php endif; ?></p>

    <form class="bar" method="get">
        <label>License:
            <select name="sku" onchange="this.form.submit()">
                <option value="">— choose a SKU —</option>
                <?php foreach ($skuOptions as $guid => $name): ?>
                    <option value="<?php echo htmlspecialchars($guid); ?>" <?php echo $guid === $skuFilter ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($skuFilter !== ''): ?>
            <a class="deep" href="?sku=<?php echo urlencode($skuFilter); ?>&refresh=1">↻ Refresh</a>
        <?php endif; ?>
    </form>

    <?php if ($syncError): ?>
        <div class="error-banner">Failed: <?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>
    <?php if ($reportError): ?>
        <div class="warn-banner"><?php echo htmlspecialchars($reportError); ?></div>
    <?php endif; ?>

    <?php if ($skuFilter === '' && !$syncError): ?>
        <p class="hint">Pick a license above to see who's using it. Reclaim candidates (no activity in <?php echo STALE_DAYS; ?>+ days) are listed first.</p>
    <?php elseif (!$syncError): ?>
        <div class="summary">
            <div class="stat"><div class="n"><?php echo count($rows); ?></div><div class="l">Assigned</div></div>
            <div class="stat bad"><div class="n"><?php echo $reclaim; ?></div><div class="l">Reclaim candidates<br>(<?php echo STALE_DAYS; ?>+ days idle)</div></div>
        </div>

        <div>
            <input type="text" id="userFilter" class="filter" placeholder="Search name or UPN…" oninput="filterRows()" autocomplete="off">
            <span class="filter-count" id="filterCount"></span>
        </div>
        <div style="overflow-x:auto">
        <table id="usageTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(this)">User</th>
                    <th class="sortable" onclick="sortTable(this)">Status</th>
                    <th class="sortable" onclick="sortTable(this)">Verdict</th>
                    <th class="sortable" onclick="sortTable(this)">Last Sign-in</th>
                    <th class="sortable" onclick="sortTable(this)" title="Mailbox-level activity (counts delegated access)">Mailbox Activity</th>
                    <th class="sortable" onclick="sortTable(this)">Items</th>
                    <th class="sortable" onclick="sortTable(this)">Storage</th>
                    <th class="sortable" onclick="sortTable(this)">Teams</th>
                    <th class="sortable" onclick="sortTable(this)">SharePoint</th>
                    <th class="sortable" onclick="sortTable(this)">OneDrive</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    [$label, $cls] = verdict($r['days'], $r['hasReportRow']);
                    $ts = fn($d) => $d ? strtotime($d) : 0;
                ?>
                    <tr>
                        <td data-sort="<?php echo htmlspecialchars(strtolower($r['displayName'])); ?>">
                            <strong><?php echo htmlspecialchars($r['displayName']); ?></strong>
                            <span class="upn"><?php echo htmlspecialchars($r['upn']); ?></span>
                        </td>
                        <td data-sort="<?php echo $r['enabled'] ? 1 : 0; ?>"><?php echo $r['enabled'] ? 'Enabled' : '<span class="badge dis">Disabled</span>'; ?></td>
                        <td data-sort="<?php echo (int) $r['lastTs']; ?>"><span class="pill <?php echo $cls; ?>"><?php echo htmlspecialchars($label); ?></span></td>
                        <td data-sort="<?php echo $ts($r['lastSignIn']); ?>"><?php echo htmlspecialchars($r['lastSignIn'] ? substr($r['lastSignIn'], 0, 10) : 'never'); ?></td>
                        <td data-sort="<?php echo $ts($r['mbActivity']); ?>"><?php echo htmlspecialchars(fmt($r['mbActivity'])); ?></td>
                        <td data-sort="<?php echo (int) ($r['items'] ?? 0); ?>"><?php echo $r['items'] === null ? '—' : number_format($r['items']); ?></td>
                        <td data-sort="<?php echo (int) ($r['storageBytes'] ?? 0); ?>"><?php echo htmlspecialchars(fmtStorage($r['storageBytes'])); ?></td>
                        <td data-sort="<?php echo $ts($r['services']['Teams']); ?>"><?php echo htmlspecialchars(fmt($r['services']['Teams'])); ?></td>
                        <td data-sort="<?php echo $ts($r['services']['SharePoint']); ?>"><?php echo htmlspecialchars(fmt($r['services']['SharePoint'])); ?></td>
                        <td data-sort="<?php echo $ts($r['services']['OneDrive']); ?>"><?php echo htmlspecialchars(fmt($r['services']['OneDrive'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
        <p class="hint">Note: this report covers core Microsoft 365 services only. SKUs like Power BI, Project, or Visio won't show activity here even when used.</p>
    <?php endif; ?>
</div>

</body>
</html>
