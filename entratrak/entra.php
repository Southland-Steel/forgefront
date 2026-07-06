<?php
include 'entra-sync.php';

// Optional server-side filter by license SKU (linked from skus.php).
$skuFilter = $_GET['sku'] ?? '';
$skuFilterName = '';
if ($skuFilter !== '' && !empty($users)) {
    $skuFilterName = LICENSE_NAMES[$skuFilter] ?? $skuFilter;
    $users = array_values(array_filter($users, fn($u) =>
        in_array($skuFilter, array_column($u['assignedLicenses'] ?? [], 'skuId'))));
}

// Mailbox usage (last mail activity, item count, storage) for the modal.
// Optional — needs Reports.Read.All; empty if unavailable.
$mailboxUsage = [];
if (empty($syncError)) {
    try {
        $mailboxUsage = cacheRemember('mailbox_D90', 6 * 3600, fn() => fetchMailboxUsage(getEntraAccessToken(), 'D90'));
    } catch (Throwable $e) {
        $mailboxUsage = [];
    }
}

// Admin (directory role) membership for marking/filtering. Optional —
// needs RoleManagement.Read.Directory; empty map if unavailable.
$adminRoles = [];
$adminUnavailable = false;
if (empty($syncError)) {
    try {
        $adminRoles = cacheRemember('admins', 3600, fn() => fetchAdminRoles(getEntraAccessToken()));
    } catch (Throwable $e) {
        $adminUnavailable = true;
    }
}

// Distinct email domains present (for the domain filter dropdown).
$domainCounts = [];
foreach ($users as $u) {
    $d = strtolower(substr(strrchr($u['userPrincipalName'] ?? '', '@') ?: '', 1));
    if ($d !== '') $domainCounts[$d] = ($domainCounts[$d] ?? 0) + 1;
}
ksort($domainCounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Internal Identity Roster Matrix</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: none; margin: 0; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h2 { color: #1e3d59; margin-bottom: 20px; border-bottom: 2px solid #f4f6f9; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 15px; text-align: center; border-bottom: 1px solid #e1e8ed; }
        th { background-color: #1e3d59; color: white; font-weight: 600; }
        /* Keep the header row visible while scrolling the long user list */
        thead th { position: sticky; top: 0; z-index: 3; }
        /* Vertical (bottom-to-top) headers so license columns stay narrow */
        th.rot { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap;
                 padding: 10px 6px; vertical-align: bottom; height: 140px; }
        td.lic { padding: 12px 6px; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .text-left { text-align: left; }
        .check { color: #2ecc71; font-weight: bold; font-size: 1.2em; }
        .dash { color: #ccd6dd; }
        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: bold; }
        .active { background: #e8f8f5; color: #2e7d32; }
        .disabled { background: #fce4d6; color: #c0392b; }
        .error-banner { background: #fdecea; color: #c0392b; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        .warn-banner { background: #fff4e0; color: #8a5a00; padding: 12px 15px; border-radius: 6px; margin-bottom: 15px; font-weight: 600; }
        .filter-note { background: #eef3f8; color: #1e3d59; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; }
        .nav { margin-bottom: 20px; font-size: 0.9em; }
        .nav a { color: #1e3d59; text-decoration: none; font-weight: 600; }
        .nav a:hover { text-decoration: underline; }
        .nav .sep { color: #ccd6dd; margin: 0 10px; }
        .nav .age { color: #657786; font-weight: 400; }
        .roles { font-family: ui-monospace, monospace; font-size: 0.8em; text-align: left; max-width: 260px; overflow-wrap: anywhere; color: #555; }
        .upn { display: block; color: #657786; font-weight: 400; font-size: 0.82em; margin-top: 2px; }
        .flag-badge { display: inline-block; margin-left: 8px; background: #fce4d6; color: #c0392b; border-radius: 12px; padding: 2px 8px; font-size: 0.72em; font-weight: 700; text-decoration: none; vertical-align: middle; white-space: nowrap; }
        .flag-badge:hover { background: #f6bfa9; }
        .admin-badge { display: inline-block; margin-left: 8px; background: #4a148c; color: #fff; border-radius: 12px; padding: 2px 8px; font-size: 0.72em; font-weight: 700; vertical-align: middle; }
        tbody tr { cursor: pointer; }
        tbody tr:hover { background: #eef3f8; }
        /* Detail modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 100; }
        .modal-overlay.open { display: flex; align-items: flex-start; justify-content: center; }
        .modal { background: #fff; max-width: 760px; width: 92%; margin-top: 5vh; max-height: 88vh; overflow: auto; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid #e1e8ed; position: sticky; top: 0; background: #fff; }
        .modal-header h3 { margin: 0; color: #1e3d59; }
        .modal-close { border: none; background: none; font-size: 1.6em; line-height: 1; cursor: pointer; color: #657786; }
        .modal-body { padding: 20px 24px; }
        .detail-grid { display: grid; grid-template-columns: 170px 1fr; gap: 8px 16px; margin-bottom: 10px; }
        .detail-grid dt { color: #657786; font-weight: 600; }
        .detail-grid dd { margin: 0; word-break: break-word; }
        .chip { display: inline-block; background: #eef3f8; color: #1e3d59; border-radius: 12px; padding: 3px 10px; margin: 2px 4px 2px 0; font-size: 0.85em; }
        .section-title { color: #1e3d59; font-weight: 700; margin: 20px 0 8px; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; }
        .signin-row { display: grid; grid-template-columns: 200px 160px 1fr; gap: 6px 16px; padding: 8px 0; border-bottom: 1px solid #f0f3f6; align-items: baseline; }
        .signin-k { font-weight: 600; color: #1e3d59; }
        .signin-v { font-family: ui-monospace, monospace; font-size: 0.9em; }
        .signin-m { color: #657786; font-size: 0.85em; }
        .signin-warn { background: #fff4e0; color: #8a5a00; padding: 10px 12px; border-radius: 6px; margin: 12px 0 0; font-size: 0.85em; }
        a.deep { color: #1e6fce; text-decoration: none; font-weight: 600; }
        a.deep:hover { text-decoration: underline; }
        pre.raw { background: #1e2830; color: #d6e2ea; padding: 16px; border-radius: 8px; overflow: auto; font-size: 0.8em; max-height: 340px; margin: 0; }
        .copy-btn { font-size: 0.75em; font-weight: 600; background: #1e3d59; color: #fff; border: none; border-radius: 6px; padding: 5px 10px; cursor: pointer; }
        .filter-bar { margin: 10px 0 4px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-bar select { padding: 9px 10px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.9em; background: #fff; color: #1e3d59; cursor: pointer; }
        .filter-bar select:focus { outline: none; border-color: #1e3d59; }
        .filter { flex: 1; min-width: 220px; max-width: 340px; padding: 9px 12px; border: 1px solid #ccd6dd; border-radius: 6px; font-size: 0.95em; }
        .filter:focus { outline: none; border-color: #1e3d59; box-shadow: 0 0 0 2px rgba(30,61,89,0.15); }
        .filter-count { color: #657786; font-size: 0.9em; }
        .toggle-btn { padding: 9px 14px; border: 1px solid #ccd6dd; background: #fff; border-radius: 6px; font-size: 0.9em; font-weight: 600; color: #1e3d59; cursor: pointer; }
        .toggle-btn:hover { border-color: #1e3d59; }
        .toggle-btn.active { background: #c0392b; border-color: #c0392b; color: #fff; }
        .toggle-btn.ghost { color: #657786; }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #274d6e; }
        th .arrow { font-size: 0.8em; opacity: 0.9; }
    </style>
</head>
<body>

<div class="container">
    <div class="nav">
        <a href="index.php">← Home</a><span class="sep">|</span><a href="skus.php">Tenant License SKUs</a><span class="sep">|</span>
        <?php if (!empty($dataAge) || $dataAge === 0): ?>
            <span class="age">Data cached <?php echo htmlspecialchars(humanAge($dataAge)); ?> ago</span><span class="sep">|</span>
        <?php endif; ?>
        <a href="?refresh=1">↻ Refresh</a>
    </div>
    <h2>System Administration: User License & Mail Property Grid</h2>
    <?php if (!empty($syncError)): ?>
        <div class="error-banner">Entra sync failed: <?php echo htmlspecialchars($syncError); ?></div>
    <?php endif; ?>
    <?php if (!empty($signinWarning)): ?>
        <div class="warn-banner"><?php echo htmlspecialchars($signinWarning); ?></div>
    <?php endif; ?>
    <?php if ($skuFilter !== ''): ?>
        <div class="filter-note">
            Showing <strong><?php echo count($users); ?></strong> users assigned
            <strong><?php echo htmlspecialchars($skuFilterName); ?></strong>
            · <a class="deep" href="usage.php?sku=<?php echo urlencode($skuFilter); ?>">check utilization →</a>
            · <a class="deep" href="entra.php">clear filter</a>
        </div>
    <?php endif; ?>
    <div class="filter-bar">
        <input type="text" id="userFilter" class="filter" placeholder="Search name, UPN, type…" oninput="applyFilters()" autocomplete="off">
        <select id="fDomain" onchange="applyFilters()">
            <option value="">All domains (<?php echo count($users); ?>)</option>
            <?php foreach ($domainCounts as $d => $c): ?>
                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?> (<?php echo $c; ?>)</option>
            <?php endforeach; ?>
        </select>
        <select id="fStatus" onchange="applyFilters()">
            <option value="">All statuses</option>
            <option value="enabled">Enabled only</option>
            <option value="disabled">Disabled only</option>
        </select>
        <select id="fSource" onchange="applyFilters()">
            <option value="">All sources</option>
            <option value="cloud">Cloud-only</option>
            <option value="synced">Synced from AD</option>
        </select>
        <select id="fTerminated" onchange="applyFilters()">
            <option value="">Incl. terminated</option>
            <option value="hide">Hide terminated</option>
            <option value="only">Terminated only</option>
        </select>
        <button type="button" class="toggle-btn" id="flaggedToggle" onclick="toggleFlagged()">⚠ Flagged only</button>
        <?php if (!$adminUnavailable): ?>
            <button type="button" class="toggle-btn" id="adminToggle" onclick="toggleAdmins()">🛡 Admins only</button>
        <?php endif; ?>
        <button type="button" class="toggle-btn" onclick="cleanupPreset()" title="Terminated accounts that can still sign in">🧹 Cleanup</button>
        <button type="button" class="toggle-btn ghost" onclick="resetFilters()">Reset</button>
        <span class="filter-count" id="filterCount"></span>
    </div>
    <table>
        <thead>
            <tr>
                <th class="text-left sortable" onclick="sortTable(this)">User</th>
                <th class="sortable" onclick="sortTable(this)">Status</th>
                <th class="sortable" onclick="sortTable(this)">Type</th>
                <th class="sortable" onclick="sortTable(this)">Has 'mail' Property</th>
                <?php if (INCLUDE_SIGNIN_ACTIVITY): ?>
                    <th class="sortable" onclick="sortTable(this)" title="Last confirmed successful login (not attempts)">Last Successful Sign-in</th>
                <?php endif; ?>
                <?php foreach ($licenseColumns as $guid => $name): ?>
                    <th class="rot sortable" onclick="sortTable(this)"><?php echo htmlspecialchars($name); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $i => $user):
                // Check if the official mail property is set
                $hasMail = !empty($user['mail']);
                $statusClass = $user['accountEnabled'] ? 'active' : 'disabled';
                $statusText = $user['accountEnabled'] ? 'Active' : 'Disabled';

                // Extract assigned license SKU GUIDs into a flat array
                $userSkus = array_column($user['assignedLicenses'] ?? [], 'skuId');

                // Values used by the client-side filters
                $displayName = $user['displayName'] ?? $user['userPrincipalName'] ?? '(no name)';
                $userType = $user['userType'] ?? '—';
                $domain = strtolower(substr(strrchr($user['userPrincipalName'] ?? '', '@') ?: '', 1));
                $isTerminated = stripos($displayName, 'terminated') !== false;
                $userRoles = $adminRoles[strtolower($user['userPrincipalName'] ?? '')] ?? [];
                $isAdmin = !empty($userRoles);

                // Flag accounts with a RECENT sign-in attempt (incl. failed/blocked)
                // that is newer than their last SUCCESSFUL sign-in — the pattern
                // seen on actively sprayed/attacked accounts. The recency window
                // (FLAG_RECENT_DAYS) keeps old, long-past failures from crying wolf.
                $flagged = false;
                if (INCLUDE_SIGNIN_ACTIVITY && !empty($user['signInActivity'])) {
                    $sa = $user['signInActivity'];
                    $attempt = !empty($sa['lastSignInDateTime']) ? strtotime($sa['lastSignInDateTime']) : 0;
                    $success = !empty($sa['lastSuccessfulSignInDateTime']) ? strtotime($sa['lastSuccessfulSignInDateTime']) : 0;
                    $isRecent = $attempt && ($attempt > time() - FLAG_RECENT_DAYS * 86400);
                    $flagged = $isRecent && ($attempt - $success > 86400);
                }
            ?>
                <tr class="<?php echo $flagged ? 'flagged' : ''; ?>"
                    data-enabled="<?php echo $user['accountEnabled'] ? 1 : 0; ?>"
                    data-domain="<?php echo htmlspecialchars($domain); ?>"
                    data-terminated="<?php echo $isTerminated ? 1 : 0; ?>"
                    data-admin="<?php echo $isAdmin ? 1 : 0; ?>"
                    data-source="<?php echo !empty($user['onPremisesSyncEnabled']) ? 'synced' : 'cloud'; ?>"
                    onclick="openUser(<?php echo $i; ?>)">
                    <td class="text-left" data-sort="<?php echo ($flagged ? '0 ' : '1 ') . htmlspecialchars(strtolower($displayName)); ?>">
                        <strong><?php echo htmlspecialchars($displayName); ?></strong>
                        <?php if ($isAdmin): ?>
                            <span class="admin-badge" title="<?php echo htmlspecialchars(implode(', ', $userRoles)); ?>">ADMIN</span>
                        <?php endif; ?>
                        <?php if ($flagged): ?>
                            <a class="flag-badge" href="signins.php?user=<?php echo urlencode($user['userPrincipalName'] ?? ''); ?>"
                               onclick="event.stopPropagation()"
                               title="Recent failed or blocked sign-in attempts — last attempt is newer than last successful sign-in. Click to diagnose.">⚠ Flagged</a>
                        <?php endif; ?>
                        <span class="upn"><?php echo htmlspecialchars($user['userPrincipalName'] ?? ''); ?></span>
                    </td>
                    <td data-sort="<?php echo $user['accountEnabled'] ? 1 : 0; ?>"><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                    <td data-sort="<?php echo htmlspecialchars($userType); ?>"><?php echo htmlspecialchars($userType); ?></td>
                    <td data-sort="<?php echo $hasMail ? 1 : 0; ?>">
                        <?php echo $hasMail ? '<span class="check">✓</span>' : '<span class="dash">—</span>'; ?>
                    </td>
                    <?php if (INCLUDE_SIGNIN_ACTIVITY):
                        $lastOk = $user['signInActivity']['lastSuccessfulSignInDateTime'] ?? null;
                    ?>
                        <td data-sort="<?php echo $lastOk ? strtotime($lastOk) : 0; ?>">
                            <?php echo $lastOk ? htmlspecialchars(date('Y-m-d', strtotime($lastOk))) : '<span class="dash">—</span>'; ?>
                        </td>
                    <?php endif; ?>

                    <!-- Loop through columns dynamically to check active licenses -->
                    <?php foreach ($licenseColumns as $guid => $name): $has = in_array($guid, $userSkus); ?>
                        <td class="lic" data-sort="<?php echo $has ? 1 : 0; ?>" title="<?php echo htmlspecialchars($name); ?>">
                            <?php echo $has ? '<span class="check">✓</span>' : '<span class="dash">—</span>'; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Detail modal -->
<div class="modal-overlay" id="userModal" onclick="if(event.target===this)closeUser()">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-header">
            <h3 id="m-title">User</h3>
            <button class="modal-close" onclick="closeUser()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <dl class="detail-grid" id="m-details"></dl>

            <div class="section-title">Sign-in Activity</div>
            <div id="m-signin"></div>

            <div class="section-title">Mailbox</div>
            <dl class="detail-grid" id="m-mailbox"></dl>

            <div class="section-title">Licenses</div>
            <div id="m-licenses"></div>

            <div class="section-title">App Roles (SSO)</div>
            <div id="m-roles"></div>

            <div class="section-title">Raw Graph JSON
                <button class="copy-btn" style="float:right" onclick="copyRaw()">Copy</button>
            </div>
            <pre class="raw" id="m-raw"></pre>
        </div>
    </div>
</div>

<script>
const USERS = <?php echo json_encode(array_values($users), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
const LICENSE_NAMES = <?php echo json_encode((object) LICENSE_NAMES, JSON_UNESCAPED_SLASHES); ?>;
const ROLE_ATTR = <?php echo json_encode(ROLE_ATTRIBUTE); ?>;
const MAILBOX = <?php echo json_encode((object) $mailboxUsage, JSON_UNESCAPED_SLASHES); ?>;

function fmtBytes(b) {
    if (b === null || b === undefined) return '—';
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    return Math.round(b / 1024) + ' KB';
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
}

function openUser(i) {
    const u = USERS[i];
    document.getElementById('m-title').textContent = u.displayName || u.userPrincipalName || '(no name)';

    const fmtDate = s => {
        if (!s) return '—';
        const d = new Date(s);
        return isNaN(d) ? s : d.toLocaleString();
    };
    const source = u.onPremisesSyncEnabled
        ? 'Synced from on-prem AD (roles read-only)'
        : 'Cloud-only';

    const rows = [
        ['Display Name', u.displayName],
        ['UPN', u.userPrincipalName],
        ['Object ID', u.id],
        ['Mail', u.mail || '—'],
        ['Account', u.accountEnabled ? 'Active' : 'Disabled'],
        ['User Type', u.userType || '—'],
        ['Source', source],
        ['Job Title', u.jobTitle || '—'],
        ['Department', u.department || '—'],
        ['Company', u.companyName || '—'],
        ['Office', u.officeLocation || '—'],
        ['Usage Location', u.usageLocation || '—'],
        ['Created', fmtDate(u.createdDateTime)],
        ['Last Password Change', fmtDate(u.lastPasswordChangeDateTime)],
        ['Mobile', u.mobilePhone || '—'],
        ['Employee ID', u.employeeId || '—'],
    ];
    document.getElementById('m-details').innerHTML = rows
        .map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v ?? '—')}</dd>`).join('');

    // Licenses → friendly names, falling back to the raw SKU GUID
    const lics = (u.assignedLicenses || []).map(l => LICENSE_NAMES[l.skuId] || l.skuId);
    document.getElementById('m-licenses').innerHTML = lics.length
        ? lics.map(n => `<span class="chip">${esc(n)}</span>`).join('')
        : '<span class="dash">— none —</span>';

    // App roles from the extension attribute JSON
    let roles = [];
    const raw = u.onPremisesExtensionAttributes ? u.onPremisesExtensionAttributes[ROLE_ATTR] : null;
    if (raw) { try { const p = JSON.parse(raw); if (Array.isArray(p)) roles = p; } catch (e) {} }
    document.getElementById('m-roles').innerHTML = roles.length
        ? roles.map(r => `<span class="chip">${esc(r)}</span>`).join('')
        : '<span class="dash">— none —</span>';

    // Sign-in activity — all three timestamps with their meaning
    const sa = u.signInActivity;
    const signinEl = document.getElementById('m-signin');
    if (sa) {
        const items = [
            ['Last successful sign-in', sa.lastSuccessfulSignInDateTime,
             'The last time this account actually authenticated. This is the real "last used" date.'],
            ['Last interactive attempt', sa.lastSignInDateTime,
             'Most recent interactive sign-in attempt (someone at a login prompt). Includes failed or blocked attempts.'],
            ['Last non-interactive attempt', sa.lastNonInteractiveSignInDateTime,
             'Background token refreshes from apps/mail clients. Also includes failures.'],
        ];
        signinEl.innerHTML = items.map(([k, v, m]) =>
            `<div class="signin-row"><div class="signin-k">${esc(k)}</div>` +
            `<div class="signin-v">${esc(fmtDate(v))}</div>` +
            `<div class="signin-m">${esc(m)}</div></div>`).join('');
        if (u.userPrincipalName) {
            signinEl.innerHTML +=
                `<p style="margin:12px 0 0"><a class="deep" href="signins.php?user=${encodeURIComponent(u.userPrincipalName)}">` +
                `Diagnose sign-in attempts (IP, location, failure reason) →</a></p>`;
        }
        // Flag the case that confused us: recent attempt but no matching success
        const attempt = sa.lastSignInDateTime ? new Date(sa.lastSignInDateTime) : null;
        const success = sa.lastSuccessfulSignInDateTime ? new Date(sa.lastSuccessfulSignInDateTime) : null;
        if (attempt && (!success || attempt - success > 86400000)) {
            signinEl.innerHTML +=
                `<p class="signin-warn">⚠ The last interactive attempt is more recent than the last successful ` +
                `sign-in — i.e. a failed or blocked sign-in (common on a disabled account still being targeted). ` +
                `Check the Entra sign-in logs for this user to see the source and reason.</p>`;
        }
    } else {
        signinEl.innerHTML = '<span class="dash">— sign-in data not available (AuditLog.Read.All + Entra ID P1 required) —</span>';
    }

    // Mailbox — last mail activity, item count, storage (from usage report)
    const mb = MAILBOX[(u.userPrincipalName || '').toLowerCase()] || null;
    const mbRows = mb ? [
        ['Last Mail Activity', mb.lastActivityDate || 'never'],
        ['Mailbox Size', fmtBytes(mb.storageBytes)],
        ['Item Count', mb.itemCount != null ? mb.itemCount.toLocaleString() : '—'],
    ] : [['Mailbox', 'No data (needs Reports.Read.All, or no mailbox)']];
    document.getElementById('m-mailbox').innerHTML = mbRows
        .map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('');

    document.getElementById('m-raw').textContent = JSON.stringify(u, null, 2);
    document.getElementById('userModal').classList.add('open');
}

function closeUser() {
    document.getElementById('userModal').classList.remove('open');
}

function copyRaw() {
    navigator.clipboard.writeText(document.getElementById('m-raw').textContent);
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeUser(); });

// Client-side filter — text match (name, UPN, type…) plus optional flagged-only.
let showFlaggedOnly = false;
let showAdminsOnly = false;
function applyFilters() {
    const q = document.getElementById('userFilter').value.trim().toLowerCase();
    const domain = document.getElementById('fDomain').value;
    const status = document.getElementById('fStatus').value;      // '', 'enabled', 'disabled'
    const source = document.getElementById('fSource').value;      // '', 'cloud', 'synced'
    const term = document.getElementById('fTerminated').value;    // '', 'hide', 'only'
    const rows = document.querySelectorAll('tbody tr');
    let shown = 0;
    rows.forEach(r => {
        const textOk = q === '' || r.textContent.toLowerCase().includes(q);
        const flagOk = !showFlaggedOnly || r.classList.contains('flagged');
        const adminOk = !showAdminsOnly || r.dataset.admin === '1';
        const domOk = domain === '' || r.dataset.domain === domain;
        const statusOk = status === '' ||
            (status === 'enabled' ? r.dataset.enabled === '1' : r.dataset.enabled === '0');
        const sourceOk = source === '' || r.dataset.source === source;
        const isTerm = r.dataset.terminated === '1';
        const termOk = term === '' || (term === 'only' ? isTerm : !isTerm);
        const show = textOk && flagOk && adminOk && domOk && statusOk && sourceOk && termOk;
        r.style.display = show ? '' : 'none';
        if (show) shown++;
    });
    const anyFilter = q !== '' || showFlaggedOnly || showAdminsOnly || domain !== '' || status !== '' || source !== '' || term !== '';
    document.getElementById('filterCount').textContent =
        anyFilter ? `${shown} of ${rows.length}` : `${rows.length} users`;
}
function toggleFlagged() {
    showFlaggedOnly = !showFlaggedOnly;
    document.getElementById('flaggedToggle').classList.toggle('active', showFlaggedOnly);
    applyFilters();
}
function toggleAdmins() {
    showAdminsOnly = !showAdminsOnly;
    document.getElementById('adminToggle').classList.toggle('active', showAdminsOnly);
    applyFilters();
}
// Preset: terminated accounts that can still sign in — the actionable cleanup list.
function clearToggles() {
    showFlaggedOnly = false;
    showAdminsOnly = false;
    document.getElementById('flaggedToggle').classList.remove('active');
    const at = document.getElementById('adminToggle');
    if (at) at.classList.remove('active');
}
function cleanupPreset() {
    document.getElementById('userFilter').value = '';
    document.getElementById('fDomain').value = '';
    document.getElementById('fStatus').value = 'enabled';
    document.getElementById('fSource').value = '';
    document.getElementById('fTerminated').value = 'only';
    clearToggles();
    applyFilters();
}
// Clear every filter back to the full roster.
function resetFilters() {
    document.getElementById('userFilter').value = '';
    document.getElementById('fDomain').value = '';
    document.getElementById('fStatus').value = '';
    document.getElementById('fSource').value = '';
    document.getElementById('fTerminated').value = '';
    clearToggles();
    applyFilters();
}
// Label the button with the flagged count and initialise the filter
(function () {
    const n = document.querySelectorAll('tbody tr.flagged').length;
    document.getElementById('flaggedToggle').textContent = `⚠ Flagged only (${n})`;
    const at = document.getElementById('adminToggle');
    if (at) {
        const a = document.querySelectorAll('tbody tr[data-admin="1"]').length;
        at.textContent = `🛡 Admins only (${a})`;
    }
    applyFilters();
})();

// Column sorting
let sortState = { col: null, dir: 1 };
function sortTable(th) {
    const table = th.closest('table');
    const tbody = table.tBodies[0];
    const col = th.cellIndex;
    sortState.dir = (sortState.col === col) ? -sortState.dir : 1;
    sortState.col = col;

    const rows = Array.from(tbody.rows);
    rows.sort((a, b) => {
        const av = a.cells[col].dataset.sort ?? a.cells[col].textContent.trim();
        const bv = b.cells[col].dataset.sort ?? b.cells[col].textContent.trim();
        const an = parseFloat(av), bn = parseFloat(bv);
        const cmp = (!isNaN(an) && !isNaN(bn)) ? an - bn : String(av).localeCompare(String(bv));
        return cmp * sortState.dir;
    });
    rows.forEach(r => tbody.appendChild(r));

    // Update header arrows
    table.querySelectorAll('th .arrow').forEach(a => a.remove());
    const arrow = document.createElement('span');
    arrow.className = 'arrow';
    arrow.textContent = sortState.dir > 0 ? ' ▲' : ' ▼';
    th.appendChild(arrow);
}
</script>

</body>
</html>