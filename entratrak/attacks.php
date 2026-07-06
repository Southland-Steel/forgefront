<?php
// Attack surveillance: aggregate recent FAILED sign-ins tenant-wide to show
// who is being attacked, the attack pattern, legacy-auth exposure, and which
// targets are admins. Requires AuditLog.Read.All (+ P1). Admin flags also
// need RoleManagement.Read.Directory (degrades gracefully without it).
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';

// The initial failure pull pages through the sign-in logs and can exceed
// PHP's 30s default; the result is then cached for 10 minutes.
set_time_limit(180);

define('SPRAY_IP_THRESHOLD', 10);   // distinct IPs at/above this = distributed spray
define('EXPOSED_DAYS', 30);         // a successful sign-in this recent, while under attack, is worth verifying

$syncError = null;
$adminError = null;
$capped = false;
$agg = [];
$totalFailures = 0;

try {
    if (isset($_GET['refresh'])) cacheClear();
    $token = getEntraAccessToken();
    $users = cacheRemember('users_v2', CACHE_TTL_USERS, fn() => fetchEntraUsers($token));
    $fail  = cacheRemember('failures', 600, fn() => fetchRecentFailures($token, 4));
    $events = $fail['events'];
    $capped = $fail['capped'];
    $totalFailures = count($events);

    // Admin roles are optional (separate permission)
    $admins = [];
    try {
        $admins = fetchAdminRoles($token);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), 'Authorization')) {
            $adminError = 'Admin flags off: grant RoleManagement.Read.Directory (+ consent) to highlight which targets hold admin roles.';
        } else {
            throw $e;
        }
    }

    // Index the roster for display name / status / last success
    $byUpn = [];
    foreach ($users as $u) $byUpn[strtolower($u['userPrincipalName'] ?? '')] = $u;

    // Aggregate failures per user
    foreach ($events as $e) {
        $upn = strtolower($e['userPrincipalName'] ?? '');
        if ($upn === '') continue;
        if (!isset($agg[$upn])) {
            $agg[$upn] = ['fails' => 0, 'ips' => [], 'countries' => [], 'codes' => [], 'legacy' => false, 'last' => ''];
        }
        $a = &$agg[$upn];
        $a['fails']++;
        if (!empty($e['ipAddress'])) $a['ips'][$e['ipAddress']] = 1;
        $c = $e['location']['countryOrRegion'] ?? '';
        if ($c !== '') $a['countries'][$c] = 1;
        $a['codes'][$e['status']['errorCode'] ?? 0] = 1;
        if (isLegacyAuth($e)) $a['legacy'] = true;
        $when = $e['createdDateTime'] ?? '';
        if ($when > $a['last']) $a['last'] = $when;
        unset($a);
    }

    // Build rows
    $rows = [];
    foreach ($agg as $upn => $a) {
        $u = $byUpn[$upn] ?? null;
        $ips = count($a['ips']);
        // "Under attack" = distributed across many IPs. A handful of IPs in one
        // place is almost always the user's own devices / MFA friction, not an
        // attacker — so failure COUNT alone never means attack.
        $attack = $ips >= SPRAY_IP_THRESHOLD;
        $pattern = $attack ? 'Distributed spray'
                 : ($ips <= 2 ? 'Single source' : 'Multiple sources');
        $lastSuccess = $u['signInActivity']['lastSuccessfulSignInDateTime'] ?? null;
        // Only worth "verify" when a success lands DURING a real attack.
        $exposed = $attack && $lastSuccess && strtotime($lastSuccess) > time() - EXPOSED_DAYS * 86400;
        $rows[] = [
            'upn'         => $u['userPrincipalName'] ?? $upn,
            'displayName' => $u['displayName'] ?? $upn,
            'enabled'     => $u ? (bool) ($u['accountEnabled'] ?? false) : null,
            'roles'       => $admins[$upn] ?? [],
            'fails'       => $a['fails'],
            'ips'         => $ips,
            'countries'   => count($a['countries']),
            'legacy'      => $a['legacy'],
            'last'        => $a['last'],
            'pattern'     => $pattern,
            'attack'      => $attack,
            'exposed'     => $exposed,
        ];
    }
    usort($rows, fn($x, $y) => $y['fails'] <=> $x['fails']);
} catch (Throwable $e) {
    $syncError = $e->getMessage();
    $rows = [];
}

$underAttack = count(array_filter($rows ?? [], fn($r) => $r['attack']));
$adminsUnderAttack = count(array_filter($rows ?? [], fn($r) => $r['attack'] && !empty($r['roles'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attack Surveillance</title>
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
        .toggle-btn.active { background: #c0392b; border-color: #c0392b; color: #fff; }
        .summary { display: flex; gap: 16px; margin: 16px 0; flex-wrap: wrap; }
        .stat { border: 1px solid #e1e8ed; border-radius: 8px; padding: 12px 18px; min-width: 130px; }
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
        .num { text-align: right; }
        .upn { display: block; color: #657786; font-size: 0.82em; }
        .pill { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 0.8em; font-weight: bold; }
        .pill.spray { background: #fce4d6; color: #c0392b; }
        .pill.single { background: #eef3f8; color: #34506b; }
        .pill.mixed { background: #fff4e0; color: #8a5a00; }
        .badge { padding: 3px 8px; border-radius: 12px; font-size: 0.78em; font-weight: bold; }
        .badge.admin { background: #4a148c; color: #fff; }
        .badge.legacy { background: #fce4d6; color: #c0392b; }
        .badge.exposed { background: #c0392b; color: #fff; }
        .badge.dis { background: #eef3f8; color: #657786; }
        .error-banner { background: #fdecea; color: #c0392b; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        .warn-banner { background: #fff4e0; color: #8a5a00; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        a.deep { color: #1e6fce; text-decoration: none; font-weight: 600; }
        a.deep:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav">
        <a href="index.php">← Home</a><span class="sep">|</span><a href="entra.php">User License Grid</a><span class="sep">|</span><a href="legacy.php">Legacy Auth</a>
    </div>
    <h2>Attack Surveillance</h2>
    <p class="hint">Users ranked by recent failed sign-ins. Many distinct IPs = distributed spray; one or two IPs usually means a broken client, not an attack.
        <?php if ($capped): ?><strong>Note:</strong> based on the most recent <?php echo number_format($totalFailures); ?> failure events (there were more — counts are a floor).<?php else: ?>Based on <?php echo number_format($totalFailures); ?> failure events in the retained window.<?php endif; ?></p>

    <div class="bar">
        <input type="text" id="userFilter" class="filter" placeholder="Search name or UPN…" oninput="filterRows()" autocomplete="off">
        <button type="button" class="toggle-btn" id="attackToggle" onclick="toggleAttacks()">🎯 Active attacks only</button>
        <span class="filter-count" id="filterCount"></span>
        <a class="deep" href="?refresh=1">↻ Refresh</a>
    </div>

    <?php if ($syncError): ?>
        <div class="error-banner">Failed: <?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>
    <?php if ($adminError): ?>
        <div class="warn-banner"><?php echo htmlspecialchars($adminError); ?></div>
    <?php endif; ?>

    <?php if (!$syncError): ?>
        <div class="summary">
            <div class="stat"><div class="n"><?php echo count($rows); ?></div><div class="l">Users with failures</div></div>
            <div class="stat bad"><div class="n"><?php echo $underAttack; ?></div><div class="l">Under active attack<br>(distributed spray)</div></div>
            <div class="stat bad"><div class="n"><?php echo $adminsUnderAttack; ?></div><div class="l">Admins under attack</div></div>
            <div class="stat"><div class="n"><?php echo number_format($totalFailures); ?></div><div class="l">Failures analyzed</div></div>
        </div>

        <div style="overflow-x:auto">
        <table id="atkTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(this)">User</th>
                    <th class="sortable" onclick="sortTable(this)">Admin</th>
                    <th class="sortable num" onclick="sortTable(this)">Fails</th>
                    <th class="sortable num" onclick="sortTable(this)">IPs</th>
                    <th class="sortable num" onclick="sortTable(this)">Countries</th>
                    <th class="sortable" onclick="sortTable(this)">Legacy Auth</th>
                    <th class="sortable" onclick="sortTable(this)">Pattern</th>
                    <th class="sortable" onclick="sortTable(this)">Recent Success</th>
                    <th class="sortable" onclick="sortTable(this)">Last Attempt</th>
                    <th>Diagnose</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $pcls = $r['pattern'] === 'Distributed spray' ? 'spray'
                          : (str_starts_with($r['pattern'], 'Single') ? 'single' : 'mixed');
                ?>
                    <tr data-attack="<?php echo $r['attack'] ? 1 : 0; ?>">
                        <td data-sort="<?php echo htmlspecialchars(strtolower($r['displayName'])); ?>">
                            <strong><?php echo htmlspecialchars($r['displayName']); ?></strong>
                            <?php if ($r['enabled'] === false): ?> <span class="badge dis">disabled</span><?php endif; ?>
                            <span class="upn"><?php echo htmlspecialchars($r['upn']); ?></span>
                        </td>
                        <td data-sort="<?php echo empty($r['roles']) ? 0 : 1; ?>">
                            <?php if (!empty($r['roles'])): ?>
                                <span class="badge admin" title="<?php echo htmlspecialchars(implode(', ', $r['roles'])); ?>">ADMIN</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="num" data-sort="<?php echo $r['fails']; ?>"><?php echo number_format($r['fails']); ?></td>
                        <td class="num" data-sort="<?php echo $r['ips']; ?>"><?php echo $r['ips']; ?></td>
                        <td class="num" data-sort="<?php echo $r['countries']; ?>"><?php echo $r['countries']; ?></td>
                        <td data-sort="<?php echo $r['legacy'] ? 1 : 0; ?>"><?php echo $r['legacy'] ? '<span class="badge legacy">legacy</span>' : '—'; ?></td>
                        <td data-sort="<?php echo htmlspecialchars($r['pattern']); ?>"><span class="pill <?php echo $pcls; ?>"><?php echo htmlspecialchars($r['pattern']); ?></span></td>
                        <td data-sort="<?php echo $r['exposed'] ? 1 : 0; ?>"><?php echo $r['exposed'] ? '<span class="badge exposed">verify</span>' : '—'; ?></td>
                        <td data-sort="<?php echo htmlspecialchars($r['last']); ?>"><?php echo htmlspecialchars(substr($r['last'], 0, 16)); ?></td>
                        <td><a class="deep" href="signins.php?user=<?php echo urlencode($r['upn']); ?>">logs →</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="hint">"Recent Success = verify" means this account had a successful sign-in in the last <?php echo EXPOSED_DAYS; ?> days while under attack — expected for active users, but worth confirming it was legitimate for anyone heavily targeted. True compromise scoring needs Entra ID Protection (P2).</p>
    <?php endif; ?>
</div>

<script>
let attacksOnly = false;
function filterRows() {
    const q = document.getElementById('userFilter').value.trim().toLowerCase();
    const rows = document.querySelectorAll('#atkTable tbody tr');
    let shown = 0;
    rows.forEach(r => {
        const textOk = q === '' || r.textContent.toLowerCase().includes(q);
        const attackOk = !attacksOnly || r.dataset.attack === '1';
        const show = textOk && attackOk;
        r.style.display = show ? '' : 'none';
        if (show) shown++;
    });
    document.getElementById('filterCount').textContent =
        (q === '' && !attacksOnly) ? `${rows.length} users` : `${shown} of ${rows.length}`;
}
function toggleAttacks() {
    attacksOnly = !attacksOnly;
    document.getElementById('attackToggle').classList.toggle('active', attacksOnly);
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
