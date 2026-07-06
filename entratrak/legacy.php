<?php
// Legacy authentication view: which accounts use legacy protocols (SMTP,
// IMAP, POP, etc.) that bypass MFA. Successful legacy sign-ins are real
// dependencies that break if you block legacy auth; failure-only accounts
// are just attackers (safe to block). Requires AuditLog.Read.All (+ P1).
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';
set_time_limit(120);

$syncError = null;
$capped = false;
$rows = [];
$siteCounts = [];
$plantIps = [];
$totalEvents = 0;

try {
    if (isset($_GET['refresh'])) cacheClear();
    $token = getEntraAccessToken();
    $users = cacheRemember('users_v2', CACHE_TTL_USERS, fn() => fetchEntraUsers($token));
    $leg   = cacheRemember('legacy', 600, fn() => fetchLegacySignIns($token, 3));
    $events = $leg['events'];
    $capped = $leg['capped'];
    $totalEvents = count($events);

    $byUpn = [];
    foreach ($users as $u) $byUpn[strtolower($u['userPrincipalName'] ?? '')] = $u;

    $agg = [];
    foreach ($events as $e) {
        $upn = strtolower($e['userPrincipalName'] ?? '');
        if ($upn === '') continue;
        if (!isset($agg[$upn])) {
            $agg[$upn] = ['ok' => 0, 'fail' => 0, 'protocols' => [], 'lastOk' => '', 'lastAny' => '', 'okIps' => []];
        }
        $a = &$agg[$upn];
        $ok = (int) ($e['status']['errorCode'] ?? 0) === 0;
        if ($ok) {
            $a['ok']++;
            $ip = $e['ipAddress'] ?? '';
            if ($ip !== '') $a['okIps'][$ip] = ($a['okIps'][$ip] ?? 0) + 1;
        } else {
            $a['fail']++;
        }
        $app = $e['clientAppUsed'] ?? 'Unknown';
        $a['protocols'][$app] = 1;
        $when = $e['createdDateTime'] ?? '';
        if ($when > $a['lastAny']) $a['lastAny'] = $when;
        if ($ok && $when > $a['lastOk']) $a['lastOk'] = $when;
        unset($a);
    }

    foreach ($agg as $upn => $a) {
        $u = $byUpn[$upn] ?? null;
        $dependency = $a['ok'] > 0;   // real, successful legacy use = will break if blocked
        $okIps = $a['okIps'];
        arsort($okIps);
        $rows[] = [
            'upn'         => $u['userPrincipalName'] ?? $upn,
            'displayName' => $u['displayName'] ?? $upn,
            'enabled'     => $u ? (bool) ($u['accountEnabled'] ?? false) : null,
            'ok'          => $a['ok'],
            'fail'        => $a['fail'],
            'protocols'   => array_keys($a['protocols']),
            'lastOk'      => $a['lastOk'],
            'dependency'  => $dependency,
            'primaryIp'   => $okIps ? array_key_first($okIps) : '',
            'ipList'      => array_keys($okIps),
        ];
    }

    // Roll successful-legacy source IPs up to the known SITES only (plants).
    // Home / off-site addresses are intentionally excluded from the inventory.
    $ipUsers = [];
    foreach ($rows as $r) {
        foreach ($r['ipList'] as $ip) $ipUsers[$ip][$r['upn']] = 1;
    }
    $plantIps = knownIpsOfType('plant');   // ip => label (from the known-IP DB)
    $siteCounts = [];
    foreach ($plantIps as $ip => $name) {
        if (!empty($ipUsers[$ip])) $siteCounts[$ip] = count($ipUsers[$ip]);
    }
    arsort($siteCounts);
    // Dependencies first (most successful legacy use), then attack-only volume
    usort($rows, function ($x, $y) {
        if ($x['dependency'] !== $y['dependency']) return $y['dependency'] <=> $x['dependency'];
        return $x['dependency'] ? ($y['ok'] <=> $x['ok']) : ($y['fail'] <=> $x['fail']);
    });
} catch (Throwable $e) {
    $syncError = $e->getMessage();
    $rows = [];
}

$dependencies = count(array_filter($rows, fn($r) => $r['dependency']));
$attackOnly   = count($rows) - $dependencies;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Legacy Authentication</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: none; margin: 0; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2 { color: #1e3d59; margin-bottom: 6px; }
        .nav { margin-bottom: 20px; font-size: 0.9em; }
        .nav a { color: #1e3d59; text-decoration: none; font-weight: 600; }
        .nav a:hover { text-decoration: underline; }
        .nav .sep { color: #ccd6dd; margin: 0 10px; }
        .hint { color: #657786; font-size: 0.9em; }
        .bar { margin: 12px 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .filter { padding: 9px 12px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.95em; min-width: 260px; }
        .filter:focus { outline: none; border-color: #1e3d59; }
        .filter-count { color: #657786; font-size: 0.9em; }
        .toggle-btn { padding: 9px 14px; border: 1px solid #ccd6dd; background: #fff; border-radius: 6px; font-size: 0.9em; font-weight: 600; color: #1e3d59; cursor: pointer; }
        .toggle-btn:hover { border-color: #1e3d59; }
        .toggle-btn.active { background: #8a5a00; border-color: #8a5a00; color: #fff; }
        .summary { display: flex; gap: 16px; margin: 16px 0; flex-wrap: wrap; }
        .stat { border: 1px solid #e1e8ed; border-radius: 8px; padding: 12px 18px; min-width: 130px; }
        .stat .n { font-size: 1.6em; font-weight: 700; color: #1e3d59; }
        .stat.warn .n { color: #8a5a00; }
        .stat .l { color: #657786; font-size: 0.85em; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e1e8ed; font-size: 0.9em; }
        th { background-color: #1e3d59; color: white; }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #274d6e; }
        th .arrow { font-size: 0.8em; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .num { text-align: right; }
        .upn { display: block; color: #657786; font-size: 0.82em; }
        .pill { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 0.8em; font-weight: bold; }
        .pill.dep { background: #fff4e0; color: #8a5a00; }
        .pill.atk { background: #eef3f8; color: #34506b; }
        .badge.dis { background: #eef3f8; color: #657786; padding: 3px 8px; border-radius: 12px; font-size: 0.78em; font-weight: bold; }
        .error-banner { background: #fdecea; color: #c0392b; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        a.deep { color: #1e6fce; text-decoration: none; font-weight: 600; }
        a.deep:hover { text-decoration: underline; }
        .mono { font-family: ui-monospace, monospace; font-size: 0.88em; }
        .shared-panel { background: #fff4e0; border: 1px solid #f0d9a8; border-radius: 8px; padding: 14px 16px; margin: 8px 0 16px; font-size: 0.9em; color: #6b4700; }
        .chips { margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap; }
        .ipchip { background: #fff; border: 1px solid #e0c48a; border-radius: 14px; padding: 4px 12px; font-family: ui-monospace, monospace; font-size: 0.85em; color: #8a5a00; cursor: pointer; }
        .ipchip:hover { background: #8a5a00; color: #fff; }
        .ipchip.clear { font-family: inherit; color: #657786; border-color: #ccd6dd; }
        .shared-badge { display: inline-block; background: #fce4d6; color: #c0392b; border-radius: 10px; padding: 1px 7px; font-size: 0.72em; font-weight: 700; margin-left: 4px; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav">
        <a href="index.php">← Home</a><span class="sep">|</span><a href="entra.php">User License Grid</a><span class="sep">|</span><a href="attacks.php">Attack Surveillance</a>
    </div>
    <h2>Legacy Authentication</h2>
    <p class="hint">Accounts seen using legacy protocols (SMTP, IMAP, POP…) that bypass MFA. <strong>Successful</strong> legacy sign-ins are real dependencies — remediate these before blocking legacy auth. Failure-only accounts are just attackers (safe to block).
        <?php if ($capped): ?> Based on the most recent <?php echo number_format($totalEvents); ?> events (capped).<?php else: ?> Based on <?php echo number_format($totalEvents); ?> events in the retained window.<?php endif; ?></p>

    <div class="bar">
        <input type="text" id="userFilter" class="filter" placeholder="Search name or UPN…" oninput="filterRows()" autocomplete="off">
        <button type="button" class="toggle-btn" id="depToggle" onclick="toggleDeps()">⚠ Dependencies only</button>
        <span class="filter-count" id="filterCount"></span>
        <a class="deep" href="?refresh=1">↻ Refresh</a>
    </div>

    <?php if ($syncError): ?>
        <div class="error-banner">Failed: <?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>

    <?php if (!$syncError): ?>
        <div class="summary">
            <div class="stat warn"><div class="n"><?php echo $dependencies; ?></div><div class="l">Legacy dependencies<br>(successful — fix first)</div></div>
            <div class="stat"><div class="n"><?php echo $attackOnly; ?></div><div class="l">Attack-only<br>(failures only)</div></div>
            <div class="stat"><div class="n"><?php echo number_format($totalEvents); ?></div><div class="l">Legacy events analyzed</div></div>
        </div>

        <?php if (!empty($siteCounts)): ?>
            <div class="shared-panel">
                <strong>Site legacy sources</strong> — accounts relaying legacy auth from each plant (likely the site copier). Click a site to filter; home/off-site sources are excluded.
                <div class="chips">
                    <?php foreach ($siteCounts as $ip => $n): ?>
                        <span class="ipchip" onclick="filterIp('<?php echo htmlspecialchars($ip); ?>')"><?php echo htmlspecialchars($plantIps[$ip]); ?> · <?php echo $n; ?> accounts</span>
                    <?php endforeach; ?>
                    <span class="ipchip clear" onclick="filterIp('')">clear</span>
                </div>
            </div>
        <?php endif; ?>

        <div style="overflow-x:auto">
        <table id="legTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(this)">User</th>
                    <th class="sortable" onclick="sortTable(this)">Status</th>
                    <th class="sortable num" onclick="sortTable(this)">Successful</th>
                    <th class="sortable num" onclick="sortTable(this)">Failed</th>
                    <th class="sortable" onclick="sortTable(this)">Protocols</th>
                    <th class="sortable" onclick="sortTable(this)">Source IP (success)</th>
                    <th class="sortable" onclick="sortTable(this)">Last Success</th>
                    <th class="sortable" onclick="sortTable(this)">Assessment</th>
                    <th>Diagnose</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $siteIpsOnly = array_values(array_filter($r['ipList'], fn($ip) => isset($plantIps[$ip]))); ?>
                    <tr data-dep="<?php echo $r['dependency'] ? 1 : 0; ?>" data-ips="<?php echo htmlspecialchars(implode(',', $siteIpsOnly)); ?>">
                        <td data-sort="<?php echo htmlspecialchars(strtolower($r['displayName'])); ?>">
                            <strong><?php echo htmlspecialchars($r['displayName']); ?></strong>
                            <?php if ($r['enabled'] === false): ?> <span class="badge dis">disabled</span><?php endif; ?>
                            <span class="upn"><?php echo htmlspecialchars($r['upn']); ?></span>
                        </td>
                        <td data-sort="<?php echo $r['enabled'] ? 1 : 0; ?>"><?php echo $r['enabled'] ? 'Enabled' : 'Disabled'; ?></td>
                        <td class="num" data-sort="<?php echo $r['ok']; ?>"><?php echo $r['ok']; ?></td>
                        <td class="num" data-sort="<?php echo $r['fail']; ?>"><?php echo number_format($r['fail']); ?></td>
                        <td data-sort="<?php echo htmlspecialchars(implode(',', $r['protocols'])); ?>"><?php echo htmlspecialchars(implode(', ', $r['protocols'])); ?></td>
                        <?php
                            // Label by site; anything not a known site shows as off-site
                            // (home IPs are never printed). Prefer a site IP if the account
                            // used one, even when its highest-count IP is off-site.
                            $siteIp = '';
                            foreach ($r['ipList'] as $ip) { if (isset($plantIps[$ip])) { $siteIp = $ip; break; } }
                            $srcLabel = $siteIp ? $plantIps[$siteIp] : ($r['primaryIp'] ? 'off-site' : '');
                            $offSite = !$siteIp && $r['primaryIp'];
                        ?>
                        <td data-sort="<?php echo htmlspecialchars($srcLabel); ?>">
                            <?php if ($srcLabel === ''): ?>—
                            <?php elseif ($offSite): ?><span class="upn">off-site</span>
                            <?php else: ?><?php echo htmlspecialchars($srcLabel); ?><?php endif; ?>
                        </td>
                        <td data-sort="<?php echo htmlspecialchars($r['lastOk']); ?>"><?php echo htmlspecialchars($r['lastOk'] ? substr($r['lastOk'], 0, 10) : '—'); ?></td>
                        <td data-sort="<?php echo $r['dependency'] ? 1 : 0; ?>">
                            <?php if ($r['dependency']): ?>
                                <span class="pill dep">Dependency — fix before blocking</span>
                            <?php else: ?>
                                <span class="pill atk">Attack noise — safe to block</span>
                            <?php endif; ?>
                        </td>
                        <td><a class="deep" href="signins.php?user=<?php echo urlencode($r['upn']); ?>">logs →</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="hint">Remediation for a dependency: move the client to modern auth (OAuth), or if it's an app/device that can't, isolate it — don't leave tenant-wide legacy auth on for everyone because of a few. Blocking is done via a Conditional Access policy targeting legacy authentication clients.</p>
    <?php endif; ?>
</div>

<script>
let depsOnly = false;
let ipFilter = '';
function filterRows() {
    const q = document.getElementById('userFilter').value.trim().toLowerCase();
    const rows = document.querySelectorAll('#legTable tbody tr');
    let shown = 0;
    rows.forEach(r => {
        const textOk = q === '' || r.textContent.toLowerCase().includes(q);
        const depOk = !depsOnly || r.dataset.dep === '1';
        const ipOk = ipFilter === '' || (r.dataset.ips || '').split(',').includes(ipFilter);
        const show = textOk && depOk && ipOk;
        r.style.display = show ? '' : 'none';
        if (show) shown++;
    });
    const label = ipFilter ? ` · IP ${ipFilter}` : '';
    document.getElementById('filterCount').textContent =
        (q === '' && !depsOnly && ipFilter === '') ? `${rows.length} accounts` : `${shown} of ${rows.length}${label}`;
}
function filterIp(ip) {
    ipFilter = ip;
    filterRows();
}
function toggleDeps() {
    depsOnly = !depsOnly;
    document.getElementById('depToggle').classList.toggle('active', depsOnly);
    filterRows();
}
let sortState = { col: null, dir: 1 };
function sortTable(th) {
    const table = th.closest('table'), tbody = table.tBodies[0], col = th.cellIndex;
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

</body>
</html>
