<?php
// Known IPs: harvest source IPs from sign-in data into a portable SQLite
// store, then mark each with a label and type (plant/home/customer/…).
define('ENTRA_NO_AUTORUN', true);
include 'entra-sync.php';
set_time_limit(180);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ip = trim($_POST['ip'] ?? '');
    if ($action === 'add' && $ip !== '') {
        addKnownIp($ip, trim($_POST['label'] ?? ''), $_POST['type'] ?? 'other', 'known-ips.php');
        if (isset($_POST['category'])) updateIpCategory($ip, $_POST['category']);
    } elseif ($action === 'remove' && $ip !== '') {
        removeKnownIp($ip);
    } elseif ($action === 'bulk') {
        $ips = array_filter(array_map('trim', explode(',', $_POST['ips'] ?? '')));
        $label = trim($_POST['label'] ?? '');
        $count = bulkUpdateIps($ips, $_POST['type'] ?? null, $label !== '' ? $label : null, $_POST['category'] ?? null);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    } elseif ($action === 'harvest') {
        try {
            $res = fetchRecentSuccesses(getEntraAccessToken(), 5);
            $ipUsers = [];
            $ipLoc = [];
            foreach ($res['events'] as $e) {
                $a = $e['ipAddress'] ?? '';
                if ($a === '') continue;
                $upn = strtolower($e['userPrincipalName'] ?? '');
                $ipLoc[$a] = [
                    'city'    => $e['location']['city'] ?? '',
                    'state'   => $e['location']['state'] ?? '',
                    'country' => $e['location']['countryOrRegion'] ?? '',
                ];
                if (!isset($ipUsers[$a][$upn])) {
                    $ipUsers[$a][$upn] = ['name' => $e['userDisplayName'] ?? '', 'count' => 0, 'last' => ''];
                }
                $ipUsers[$a][$upn]['count']++;
                $w = $e['createdDateTime'] ?? '';
                if ($w > $ipUsers[$a][$upn]['last']) $ipUsers[$a][$upn]['last'] = $w;
            }
            $providers = lookupProviders(array_keys($ipUsers));   // offline ISP/ASN lookup, one pass
            $existing = knownIps();
            $added = 0;
            foreach ($ipUsers as $a => $users) {
                storeIpUsers($a, $users);                         // cache the who-logged-in list for the modal
                $loc = $ipLoc[$a];
                if (!isset($existing[$a])) {
                    $n = count($users);
                    $hint = (fmtLocation($loc['city'], $loc['state'], $loc['country']) ?: 'unknown loc')
                          . " ({$n} user" . ($n > 1 ? 's' : '') . ')';
                    addKnownIp($a, $hint, 'other', 'harvest');   // logged as unmarked ('other') for you to classify
                    updateIpCategory($a, deriveCategory($a, $providers[$a] ?? '', 'other'));  // auto connection category
                    $added++;
                }
                updateIpLocation($a, $loc['city'], $loc['state'], $loc['country']);  // refresh geo for all
                updateIpProvider($a, $providers[$a] ?? '');                          // refresh provider for all
            }
            header('Location: known-ips.php?added=' . $added . '&capped=' . ($res['capped'] ? 1 : 0));
            exit;
        } catch (Throwable $e) {
            header('Location: known-ips.php?err=' . urlencode($e->getMessage()));
            exit;
        }
    }
    if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
    header('Location: known-ips.php');
    exit;
}

// AJAX: who logged in from a given IP (for the modal)
if (isset($_GET['ipusers'])) {
    header('Content-Type: application/json');
    try {
        $ip = $_GET['ipusers'];
        $det = knownIpsDetailed()[$ip] ?? null;
        $loc = $det ? fmtLocation($det['city'], $det['state'], $det['country']) : '';
        $prov = $det['isp'] ?? '';
        // Fast path: stored user list from the last harvest.
        $stored = ipUsersFromDb($ip);
        if ($stored) {
            $users = array_map(fn($r) => [
                'upn'  => $r['upn'],
                'name' => $r['name'],
                'ok'   => (int) $r['logins'],
                'last' => $r['last_seen'],
            ], $stored);
            echo json_encode([
                'ip' => $ip, 'source' => 'stored', 'location' => $loc, 'provider' => $prov,
                'total' => array_sum(array_column($users, 'ok')),
                'users' => $users,
            ]);
            exit;
        }
        // Fallback: live query for IPs not covered by a harvest.
        $events = cacheRemember('ipusers_' . md5($ip), 300,
            fn() => fetchSignInsByIp(getEntraAccessToken(), $ip, 500));
        $u = [];
        foreach ($events as $e) {
            $upn = $e['userPrincipalName'] ?? '?';
            $ok = (int) ($e['status']['errorCode'] ?? 0) === 0;
            $when = $e['createdDateTime'] ?? '';
            if (!isset($u[$upn])) $u[$upn] = ['upn' => $upn, 'name' => $e['userDisplayName'] ?? '', 'ok' => 0, 'last' => ''];
            if ($ok) $u[$upn]['ok']++;
            if ($when > $u[$upn]['last']) $u[$upn]['last'] = $when;
        }
        usort($u, fn($a, $b) => $b['ok'] <=> $a['ok']);
        echo json_encode(['ip' => $ip, 'source' => 'live', 'location' => $loc, 'provider' => $prov, 'total' => count($events), 'users' => array_values($u)]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$dbUp = db() !== null;
$rows = [];
if ($dbUp) {
    foreach (db()->query('SELECT ip, label, type, city, state, country, isp, category, added_by, created_at FROM known_ips ORDER BY type, ip') as $r) {
        $rows[] = $r;
    }
}
// Count per type for the summary
$typeCounts = array_fill_keys(IP_TYPES, 0);
foreach ($rows as $r) { $t = in_array($r['type'], IP_TYPES, true) ? $r['type'] : 'other'; $typeCounts[$t]++; }

// ip => [users], plus per-user IP counts (for the "filter by user" dropdown)
$ipUsersMap = [];
$userCounts = [];
if ($dbUp) {
    foreach (db()->query('SELECT ip, upn FROM ip_users') as $r) {
        $ipUsersMap[$r['ip']][] = $r['upn'];
        $userCounts[$r['upn']] = ($userCounts[$r['upn']] ?? 0) + 1;
    }
}
ksort($userCounts);

// provider => number of known IPs (for the "filter by provider" dropdown)
$providerCounts = [];
$categoryCounts = [];
foreach ($rows as $r) {
    if ($r['isp'] !== '') $providerCounts[$r['isp']] = ($providerCounts[$r['isp']] ?? 0) + 1;
    $ct = $r['category'] !== '' ? $r['category'] : 'other';
    $categoryCounts[$ct] = ($categoryCounts[$ct] ?? 0) + 1;
}
arsort($providerCounts);
arsort($categoryCounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Known IPs</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: none; margin: 0; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2 { color: #1e3d59; margin-bottom: 6px; } h3 { color: #1e3d59; }
        .nav { margin-bottom: 20px; font-size: 0.9em; }
        .nav a { color: #1e3d59; text-decoration: none; font-weight: 600; }
        .nav a:hover { text-decoration: underline; }
        .nav .sep { color: #ccd6dd; margin: 0 10px; }
        .hint { color: #657786; font-size: 0.9em; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e1e8ed; font-size: 0.9em; vertical-align: middle; }
        th { background-color: #1e3d59; color: white; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .mono { font-family: ui-monospace, monospace; font-size: 0.88em; }
        input[type=text] { padding: 6px 9px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.88em; }
        select { padding: 6px 8px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.88em; }
        button { padding: 6px 12px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
        button.primary { background: #1e3d59; color: #fff; }
        button.save { background: #e8f8f5; color: #2e7d32; }
        button.link { background: none; color: #c0392b; padding: 4px; }
        .bar { margin: 14px 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filters { margin: 14px 0; display: flex; flex-direction: column; gap: 8px; background: #f8f9fa; padding: 12px 14px; border-radius: 8px; }
        .frow { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .frow > label { width: 78px; color: #657786; font-size: 0.85em; font-weight: 600; }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #274d6e; }
        th .arrow { font-size: 0.8em; }
        .chips { display: flex; gap: 8px; flex-wrap: wrap; margin: 6px 0 14px; }
        .chip { background: #eef3f8; color: #34506b; border-radius: 12px; padding: 3px 11px; font-size: 0.82em; font-weight: 600; }
        .chip-btn { cursor: pointer; user-select: none; }
        .chip-btn:hover { background: #dbe5ef; }
        .chip-btn.active { background: #1e3d59; color: #fff; }
        .saved { color: #2e7d32; font-size: 0.8em; font-weight: 600; }
        .clear-btn { background: #eef3f8; color: #34506b; }
        .clear-btn:hover { background: #dbe5ef; }
        .banner { padding: 10px 14px; border-radius: 6px; margin: 10px 0; font-weight: 600; }
        .banner.ok { background: #e8f8f5; color: #2e7d32; }
        .banner.warn { background: #fff4e0; color: #8a5a00; }
        .banner.err { background: #fdecea; color: #c0392b; }
        .rowform { display: flex; gap: 6px; align-items: center; }
        .who { color: #1e6fce; text-decoration: none; font-size: 0.8em; font-weight: 600; }
        .who:hover { text-decoration: underline; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; }
        .modal-overlay.open { display: flex; align-items: flex-start; justify-content: center; }
        .modal { background: #fff; max-width: 640px; width: 92%; margin-top: 6vh; max-height: 84vh; overflow: auto; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; border-bottom: 1px solid #e1e8ed; position: sticky; top: 0; background: #fff; }
        .modal-header h3 { margin: 0; }
        .modal-close { border: none; background: none; font-size: 1.5em; cursor: pointer; color: #657786; }
        .modal-body { padding: 16px 22px; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav">
        <a href="index.php">← Home</a><span class="sep">|</span><a href="entra.php">User License Grid</a><span class="sep">|</span><a href="signins.php">Sign-in Diagnostics</a>
    </div>
    <h2>Known IPs</h2>
    <p class="hint">Harvest source IPs seen in sign-in data, then mark each with a label and type. Only <strong>plant</strong>-type IPs drive the Legacy page's site rollup; the rest are just classified for reference.</p>

    <?php if (isset($_GET['added'])): ?>
        <div class="banner ok">Harvest complete — logged <?php echo (int) $_GET['added']; ?> new source IP(s), all as type "other" for you to classify below.<?php echo !empty($_GET['capped']) ? ' (Covered the most-recent logins; run again later to catch more.)' : ''; ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
        <div class="banner err">Harvest failed: <?php echo htmlspecialchars($_GET['err']); ?></div>
    <?php endif; ?>
    <?php if (!$dbUp): ?>
        <div class="banner warn">Known-IP store unavailable (SQLite). Set DB_SQLITE_PATH in .env to enable.</div>
    <?php endif; ?>

    <div class="bar">
        <form method="post">
            <input type="hidden" name="action" value="harvest">
            <button class="primary" type="submit" <?php echo $dbUp ? '' : 'disabled'; ?>>⟳ Harvest source IPs from sign-ins</button>
        </form>
        <span class="hint">Logs every successful source IP (any user count) as unmarked, skipping ones already saved.</span>
    </div>

    <div class="filters">
        <div class="frow">
            <label>Search</label>
            <input type="text" id="ipFilter" placeholder="IP, description, location, provider…" size="42" oninput="filterKnown()" autocomplete="off">
            <button type="button" class="clear-btn" onclick="clearFilters()">Clear</button>
            <span class="hint" id="knownCount"></span>
        </div>
        <div class="frow">
            <label>Type</label>
            <span class="chip chip-btn active" data-type="" onclick="setTypeFilter('')">All: <?php echo count($rows); ?></span>
            <?php foreach ($typeCounts as $t => $n): ?>
                <span class="chip chip-btn" data-type="<?php echo $t; ?>" onclick="setTypeFilter('<?php echo $t; ?>')"><?php echo ucfirst($t); ?>: <?php echo $n; ?></span>
            <?php endforeach; ?>
        </div>
        <div class="frow">
            <label>Category</label>
            <select id="catSel" onchange="filterKnown()">
                <option value="">All categories…</option>
                <?php foreach ($categoryCounts as $c => $n): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo ucfirst($c); ?> (<?php echo $n; ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="frow">
            <label>Provider</label>
            <select id="provSel" onchange="filterKnown()">
                <option value="">All providers…</option>
                <?php foreach ($providerCounts as $p => $c): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?> (<?php echo $c; ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="frow">
            <label>User</label>
            <select id="userSel" onchange="filterKnown()">
                <option value="">All users…</option>
                <?php foreach ($userCounts as $u => $c): ?>
                    <option value="<?php echo htmlspecialchars($u); ?>"><?php echo htmlspecialchars($u); ?> (<?php echo $c; ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3>Known IPs (<?php echo count($rows); ?>)</h3>
    <div class="bar" style="background:#f8f9fa;padding:10px 12px;border-radius:6px">
        <strong>Bulk edit:</strong>
        <input type="text" id="bulkLabel" placeholder="description (optional)" size="24">
        <label class="hint">Type</label>
        <select id="bulkType">
            <option value="">— keep —</option>
            <?php foreach (IP_TYPES as $t): ?><option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option><?php endforeach; ?>
        </select>
        <label class="hint">Category</label>
        <select id="bulkCat">
            <option value="">— keep —</option>
            <?php foreach (IP_CATEGORIES as $c): ?><option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="primary" onclick="applyBulk()">Apply to selected</button>
        <span class="hint" id="bulkCount">0 selected</span>
    </div>
    <table>
        <thead><tr>
            <th><input type="checkbox" id="selAll" onclick="toggleAll(this)"></th>
            <th class="sortable" onclick="sortRows('ip')">IP</th>
            <th class="sortable" onclick="sortRows('label')">Description</th>
            <th class="sortable" onclick="sortRows('type')">Type</th>
            <th class="sortable" onclick="sortRows('cat')">Category</th>
            <th class="sortable" onclick="sortRows('loc')">Location</th>
            <th class="sortable" onclick="sortRows('isp')">Provider</th>
            <th class="sortable" onclick="sortRows('addedby')">Added by</th>
            <th></th>
        </tr></thead>
        <tbody id="ki-body">
            <?php if (!$rows): ?>
                <tr><td colspan="9"><em>None yet — click Harvest above, or add one below.</em></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): $locStr = fmtLocation($r['city'], $r['state'], $r['country']); ?>
                <tr data-ip="<?php echo htmlspecialchars($r['ip']); ?>"
                    data-label="<?php echo htmlspecialchars(strtolower($r['label'])); ?>"
                    data-type="<?php echo htmlspecialchars($r['type']); ?>"
                    data-cat="<?php echo htmlspecialchars($r['category']); ?>"
                    data-loc="<?php echo htmlspecialchars(strtolower($locStr)); ?>"
                    data-isp="<?php echo htmlspecialchars($r['isp']); ?>"
                    data-addedby="<?php echo htmlspecialchars($r['added_by']); ?>">
                    <td><input type="checkbox" class="rowchk" value="<?php echo htmlspecialchars($r['ip']); ?>" onclick="updBulkCount()"></td>
                    <td class="mono"><?php echo htmlspecialchars($r['ip']); ?><br>
                        <a href="#" class="who" onclick="showUsers('<?php echo htmlspecialchars($r['ip'], ENT_QUOTES); ?>');return false;">who logged in? →</a>
                    </td>
                    <td colspan="3">
                        <form method="post" class="rowform save-form">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($r['ip']); ?>">
                            <input type="text" name="label" value="<?php echo htmlspecialchars($r['label']); ?>" size="24">
                            <select name="type">
                                <?php foreach (IP_TYPES as $t): ?>
                                    <option value="<?php echo $t; ?>" <?php echo $r['type'] === $t ? 'selected' : ''; ?>><?php echo ucfirst($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="category">
                                <?php foreach (IP_CATEGORIES as $c): ?>
                                    <option value="<?php echo $c; ?>" <?php echo $r['category'] === $c ? 'selected' : ''; ?>><?php echo ucfirst($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="save" type="submit">save</button>
                        </form>
                    </td>
                    <td><?php echo htmlspecialchars($locStr); ?></td>
                    <td class="hint"><?php echo htmlspecialchars($r['isp'] ?: '—'); ?></td>
                    <td class="hint"><?php echo htmlspecialchars($r['added_by']); ?></td>
                    <td>
                        <form method="post" class="remove-form">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="ip" value="<?php echo htmlspecialchars($r['ip']); ?>">
                            <button class="link" type="submit">remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Add an IP manually</h3>
    <form method="post" class="bar">
        <input type="hidden" name="action" value="add">
        <input type="text" name="ip" placeholder="IP address" size="20" required <?php echo $dbUp ? '' : 'disabled'; ?>>
        <input type="text" name="label" placeholder="description (e.g. Employee A, Customer A)" size="30" <?php echo $dbUp ? '' : 'disabled'; ?>>
        <select name="type" <?php echo $dbUp ? '' : 'disabled'; ?>>
            <?php foreach (IP_TYPES as $t): ?><option value="<?php echo $t; ?>"><?php echo ucfirst($t); ?></option><?php endforeach; ?>
        </select>
        <select name="category" <?php echo $dbUp ? '' : 'disabled'; ?>>
            <?php foreach (IP_CATEGORIES as $c): ?><option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option><?php endforeach; ?>
        </select>
        <button class="primary" type="submit" <?php echo $dbUp ? '' : 'disabled'; ?>>+ Add</button>
    </form>
</div>

<div class="modal-overlay" id="usersModal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="modal">
        <div class="modal-header">
            <h3 id="um-title">Who logged in</h3>
            <button class="modal-close" onclick="document.getElementById('usersModal').classList.remove('open')">&times;</button>
        </div>
        <div class="modal-body" id="um-body">Loading…</div>
    </div>
</div>

<script>
function esc(s){ return String(s).replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
const IP_USERS = <?php echo json_encode((object) $ipUsersMap, JSON_UNESCAPED_SLASHES); ?>;

// Faceted filtering: search + type chips + category/provider/user selects.
// Selecting one narrows the options (and counts) shown in the others.
let typeFilter = '';
const allRows = () => Array.from(document.querySelectorAll('#ki-body tr'));
function curFilters(except) {
    return {
        q:    except === 'q'    ? '' : (document.getElementById('ipFilter').value || '').trim().toLowerCase(),
        type: except === 'type' ? '' : typeFilter,
        cat:  except === 'cat'  ? '' : document.getElementById('catSel').value,
        prov: except === 'prov' ? '' : document.getElementById('provSel').value,
        user: except === 'user' ? '' : document.getElementById('userSel').value,
    };
}
function rowPasses(r, f) {
    if (f.q && !r.textContent.toLowerCase().includes(f.q)) return false;
    if (f.type && r.dataset.type !== f.type) return false;
    if (f.cat && r.dataset.cat !== f.cat) return false;
    if (f.prov && r.dataset.isp !== f.prov) return false;
    if (f.user && !(IP_USERS[r.dataset.ip] || []).includes(f.user)) return false;
    return true;
}
function filterKnown() {
    const f = curFilters(null);
    let shown = 0;
    allRows().forEach(r => {
        const show = rowPasses(r, f);
        r.style.display = show ? '' : 'none';
        if (show) shown++;
    });
    const any = f.q || f.type || f.cat || f.prov || f.user;
    const c = document.getElementById('knownCount');
    if (c) c.textContent = any ? `${shown} shown` : '';
    rebuildFacets();
}
// Repopulate a <select>'s options + counts from rows matching the OTHER filters
function rebuildFacet(selId, except, keyFn, alpha) {
    const sel = document.getElementById(selId);
    const cur = sel.value;
    const f = curFilters(except);
    const counts = {};
    allRows().forEach(r => { if (rowPasses(r, f)) keyFn(r).forEach(k => { if (k !== '') counts[k] = (counts[k] || 0) + 1; }); });
    const keys = Object.keys(counts).sort(alpha
        ? (a, b) => a.localeCompare(b)
        : (a, b) => counts[b] - counts[a] || a.localeCompare(b));
    const first = sel.querySelector('option[value=""]');
    sel.innerHTML = '';
    sel.appendChild(first);
    keys.forEach(k => {
        const o = document.createElement('option');
        o.value = k; o.textContent = `${k} (${counts[k]})`;
        if (k === cur) o.selected = true;
        sel.appendChild(o);
    });
    if (cur && !counts[cur]) {   // keep an active selection even if it now yields 0 elsewhere
        const o = document.createElement('option'); o.value = cur; o.textContent = `${cur} (0)`; o.selected = true; sel.appendChild(o);
    }
}
function rebuildFacets() {
    rebuildFacet('catSel',  'cat',  r => [r.dataset.cat]);
    rebuildFacet('provSel', 'prov', r => [r.dataset.isp]);
    rebuildFacet('userSel', 'user', r => (IP_USERS[r.dataset.ip] || []), true);  // users alphabetical
}
// Column sorting by row data attributes
let sortState = { key: null, dir: 1 };
function sortRows(key) {
    const tb = document.getElementById('ki-body');
    sortState.dir = sortState.key === key ? -sortState.dir : 1;
    sortState.key = key;
    Array.from(tb.rows).sort((a, b) =>
        (a.dataset[key] || '').localeCompare(b.dataset[key] || '', undefined, { numeric: true }) * sortState.dir
    ).forEach(r => tb.appendChild(r));
    document.querySelectorAll('th .arrow').forEach(a => a.remove());
    const th = [...document.querySelectorAll('th.sortable')].find(t => t.getAttribute('onclick').includes(`'${key}'`));
    if (th) { const s = document.createElement('span'); s.className = 'arrow'; s.textContent = sortState.dir > 0 ? ' ▲' : ' ▼'; th.appendChild(s); }
}
// Bulk selection + apply
function visibleChecks() {
    return Array.from(document.querySelectorAll('#ki-body tr'))
        .filter(tr => tr.style.display !== 'none')
        .map(tr => tr.querySelector('.rowchk')).filter(Boolean);
}
function updBulkCount() {
    const n = document.querySelectorAll('.rowchk:checked').length;
    document.getElementById('bulkCount').textContent = n + ' selected';
}
function toggleAll(cb) {
    visibleChecks().forEach(c => { c.checked = cb.checked; });
    updBulkCount();
}
function applyBulk() {
    const ips = Array.from(document.querySelectorAll('.rowchk:checked')).map(c => c.value);
    if (!ips.length) { alert('No rows selected.'); return; }
    const type = document.getElementById('bulkType').value;      // '' = keep
    const cat = document.getElementById('bulkCat').value;        // '' = keep
    const label = document.getElementById('bulkLabel').value.trim();
    if (!type && !cat && !label) { alert('Nothing to apply — set a description, type, or category.'); return; }
    const parts = [];
    if (type) parts.push(`type "${type}"`);
    if (cat) parts.push(`category "${cat}"`);
    if (label) parts.push(`description "${label}"`);
    if (!confirm(`Set ${parts.join(', ')} on ${ips.length} IP(s)?`)) return;
    const fd = new FormData();
    fd.append('action', 'bulk'); fd.append('ajax', '1');
    fd.append('ips', ips.join(',')); fd.append('type', type); fd.append('category', cat); fd.append('label', label);
    fetch('known-ips.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return;
            document.querySelectorAll('.rowchk:checked').forEach(c => {
                const tr = c.closest('tr');
                if (type) { tr.dataset.type = type; const s = tr.querySelector('select[name=type]'); if (s) s.value = type; }
                if (cat)  { tr.dataset.cat = cat;  const s = tr.querySelector('select[name=category]'); if (s) s.value = cat; }
                if (label){ tr.dataset.label = label.toLowerCase(); const i = tr.querySelector('input[name=label]'); if (i) i.value = label; }
                c.checked = false;
            });
            document.getElementById('selAll').checked = false;
            updBulkCount();
            filterKnown();
        })
        .catch(() => alert('Bulk update failed.'));
}
function clearFilters() {
    document.getElementById('ipFilter').value = '';
    document.getElementById('userSel').value = '';
    document.getElementById('provSel').value = '';
    document.getElementById('catSel').value = '';
    typeFilter = '';
    document.querySelectorAll('.chip-btn').forEach(el => el.classList.toggle('active', el.dataset.type === ''));
    filterKnown();
}
function setTypeFilter(t) {
    typeFilter = t;
    document.querySelectorAll('.chip-btn').forEach(el => el.classList.toggle('active', el.dataset.type === t));
    filterKnown();
}

// Save/remove in place (no reload, no scroll jump)
document.querySelectorAll('.save-form').forEach(f => f.addEventListener('submit', e => {
    e.preventDefault();
    const fd = new FormData(f); fd.append('ajax', '1');
    const tr = f.closest('tr');
    fetch('known-ips.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return;
            tr.dataset.type = fd.get('type');          // keep filters in sync
            if (fd.get('category') !== null) tr.dataset.cat = fd.get('category');
            tr.dataset.label = (fd.get('label') || '').toLowerCase();
            let s = f.querySelector('.saved');
            if (!s) { s = document.createElement('span'); s.className = 'saved'; f.appendChild(s); }
            s.textContent = ' saved ✓';
            setTimeout(() => { s.textContent = ''; }, 1500);
        })
        .catch(() => { f.submit(); });                  // fall back to normal submit on error
}));
document.querySelectorAll('.remove-form').forEach(f => f.addEventListener('submit', e => {
    e.preventDefault();
    const ip = f.querySelector('[name=ip]').value;
    if (!confirm('Remove ' + ip + '?')) return;
    const fd = new FormData(f); fd.append('ajax', '1');
    const tr = f.closest('tr');
    fetch('known-ips.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.ok) tr.remove(); })
        .catch(() => { f.submit(); });
}));
function showUsers(ip) {
    const modal = document.getElementById('usersModal');
    document.getElementById('um-title').textContent = 'Who logged in from ' + ip;
    document.getElementById('um-body').textContent = 'Loading…';
    modal.classList.add('open');
    fetch('known-ips.php?ipusers=' + encodeURIComponent(ip))
        .then(r => r.json())
        .then(d => {
            if (d.error) { document.getElementById('um-body').textContent = 'Error: ' + d.error; return; }
            if (!d.users || !d.users.length) { document.getElementById('um-body').textContent = 'No sign-ins found for this IP in the retained window.'; return; }
            const loc = d.location && d.location !== '—' ? esc(d.location) + ' · ' : '';
            const prov = d.provider ? esc(d.provider) + ' · ' : '';
            const src = d.source === 'live' ? ' · live lookup' : '';
            let html = `<p class="hint">${loc}${prov}${d.total} successful logins · ${d.users.length} distinct user(s)${src}</p>`;
            html += '<table><thead><tr><th>User</th><th>Logins</th><th>Last seen</th></tr></thead><tbody>';
            for (const u of d.users) {
                const last = esc((u.last || '').replace('T', ' ').slice(0, 16));
                html += `<tr><td><strong>${esc(u.name||u.upn)}</strong><br><span class="hint mono">${esc(u.upn)}</span></td>`
                     +  `<td>${u.ok}</td><td class="hint">${last}</td></tr>`;
            }
            html += '</tbody></table>';
            document.getElementById('um-body').innerHTML = html;
        })
        .catch(e => { document.getElementById('um-body').textContent = 'Request failed: ' + e; });
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.getElementById('usersModal').classList.remove('open'); });
// Initialise facets + counts on load
filterKnown();
updBulkCount();
</script>

</body>
</html>
