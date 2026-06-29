<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pdo = getPDO();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /server_board/'); exit; }

$stmt = $pdo->prepare("SELECT * FROM servers WHERE server_id = ?");
$stmt->execute([$id]);
$server = $stmt->fetch();
if (!$server) { header('Location: /server_board/'); exit; }

// Last 90 checks
$checks = $pdo->prepare("
    SELECT status AS s, response_ms AS ms, checked_at
    FROM server_checks WHERE server_id = ? ORDER BY check_id DESC LIMIT 90
");
$checks->execute([$id]);
$history = $checks->fetchAll(); // newest first

// 30-day uptime
$uStmt = $pdo->prepare("
    SELECT COUNT(*) AS total, SUM(status = 'online') AS online_count
    FROM server_checks
    WHERE server_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$uStmt->execute([$id]);
$uRow      = $uStmt->fetch();
$uptime30d = $uRow['total'] > 0 ? round(($uRow['online_count'] / $uRow['total']) * 100, 2) : null;

// Recent incidents
$iStmt = $pdo->prepare("
    SELECT incident_id, started_at, resolved_at, detail,
           TIMESTAMPDIFF(SECOND, started_at, COALESCE(resolved_at, NOW())) AS duration_sec
    FROM server_incidents WHERE server_id = ? ORDER BY started_at DESC LIMIT 20
");
$iStmt->execute([$id]);
$incidents = $iStmt->fetchAll();

// Total downtime last 30 days (seconds)
$dtStmt = $pdo->prepare("
    SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(resolved_at, NOW()))), 0) AS total_sec
    FROM server_incidents
    WHERE server_id = ? AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$dtStmt->execute([$id]);
$totalDownSec = (int)$dtStmt->fetchColumn();

// Longest single outage ever
$loStmt = $pdo->prepare("
    SELECT TIMESTAMPDIFF(SECOND, started_at, COALESCE(resolved_at, NOW())) AS duration_sec
    FROM server_incidents WHERE server_id = ?
    ORDER BY duration_sec DESC LIMIT 1
");
$loStmt->execute([$id]);
$longestOutageSec = (int)($loStmt->fetchColumn() ?: 0);

// 24-hour timeline — one bucket per hour (bucket 0 = last hour, bucket 23 = 23-24h ago)
$tlStmt = $pdo->prepare("
    SELECT FLOOR(TIMESTAMPDIFF(SECOND, checked_at, NOW()) / 3600) AS bucket,
           SUM(status = 'online') AS online_count,
           COUNT(*) AS total_count
    FROM server_checks
    WHERE server_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    GROUP BY bucket
    ORDER BY bucket
");
$tlStmt->execute([$id]);
$timelineRaw = $tlStmt->fetchAll();
$timelineBuckets = array_fill(0, 24, null);
foreach ($timelineRaw as $row) {
    $b = (int)$row['bucket'];
    if ($b >= 0 && $b < 24) $timelineBuckets[$b] = [(int)$row['online_count'], (int)$row['total_count']];
}

// Last check time
$lastCheck = !empty($history) ? $history[0]['checked_at'] : null;

// MySQL stores UTC datetimes; convert to local for display
function utcToLocal(string $utc, string $fmt = 'M j, Y g:i A'): string {
    $dt = new DateTime($utc, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('America/Chicago'));
    return $dt->format($fmt);
}

function fmtDuration(int $sec): string {
    if ($sec <= 0)   return '0s';
    if ($sec < 60)   return $sec . 's';
    if ($sec < 3600) return floor($sec / 60) . 'm ' . ($sec % 60) . 's';
    return floor($sec / 3600) . 'h ' . floor(($sec % 3600) / 60) . 'm';
}

$lastStatus = !empty($history) ? $history[0]['s'] : null;

include __DIR__ . '/../includes/header.php';
?>
<style>
/* ── Uptime bar ── */
.ubar       { display: flex; gap: 2px; height: 32px; }
.useg       { flex: 1; border-radius: 3px; min-width: 2px; cursor: default; }
.useg-ok    { background: #22c55e; }
.useg-err   { background: #ef4444; }
.useg-warn  { background: #f59e0b; }
.useg-empty { background: #e9ecef; }

/* ── Status dot ── */
.sdot         { width: 14px; height: 14px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.sdot.online  { background: #22c55e; box-shadow: 0 0 0 4px rgba(34,197,94,.2); }
.sdot.offline { background: #ef4444; box-shadow: 0 0 0 4px rgba(239,68,68,.2); }
.sdot.warning { background: #f59e0b; box-shadow: 0 0 0 4px rgba(245,158,11,.2); }
.sdot.checking{ background: #9ca3af; animation: sdot-pulse 1.2s ease-in-out infinite; }
@keyframes sdot-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }

/* ── Protocol badge ── */
.pbadge  { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; padding:3px 8px; border-radius:5px; }
.pb-http  { background:#dbeafe; color:#1d4ed8; }
.pb-https { background:#d1fae5; color:#065f46; }
.pb-tcp   { background:#ede9fe; color:#6d28d9; }
.pb-ping  { background:#fef3c7; color:#92400e; }
.pb-dns   { background:#e0f2fe; color:#0369a1; }
.pb-mysql { background:#fce7f3; color:#9d174d; }

/* ── Stat cards ── */
.stat-card       { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:1rem 1.25rem; }
.stat-label      { font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; font-weight:700; margin-bottom:.25rem; }
.stat-value      { font-size:1.6rem; font-weight:700; color:#111827; line-height:1.1; }
.stat-value.good { color:#16a34a; }
.stat-value.warn { color:#d97706; }
.stat-value.bad  { color:#dc2626; }
.good { color:#16a34a; }
.warn { color:#d97706; }
.bad  { color:#dc2626; }
.stat-sub        { font-size:.75rem; color:#6b7280; margin-top:.15rem; }

/* ── Section header ── */
.section-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#6b7280; margin-bottom:.6rem; }

/* ── Sparkline dots ── */
.spark-dot { fill: transparent; stroke: transparent; stroke-width: 2; cursor: default; }

/* ── Custom tooltip ── */
#tip { display:none; position:fixed; background:#1f2937; color:#fff; font-size:.75rem;
       padding:5px 10px; border-radius:6px; pointer-events:none; z-index:9999;
       white-space:nowrap; box-shadow:0 2px 8px rgba(0,0,0,.3); }
</style>

<div class="container-fluid px-4 pt-3" style="max-width:1200px">

    <!-- Back + page header -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="/server_board/" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Back
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="sdot checking" id="main-dot"></span>
            <h4 class="page-title mb-0"><?= htmlspecialchars($server['name']) ?></h4>
            <span class="pbadge pb-<?= $server['protocol'] ?>"><?= strtoupper($server['protocol']) ?></span>
        </div>
        <div class="ms-auto d-flex gap-2">
            <button class="btn btn-outline-secondary" id="refreshBtn" onclick="runCheck()">
                <i class="fas fa-rotate me-1" id="refreshIcon"></i>Check Now
            </button>
            <button class="btn btn-outline-secondary"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($server)) ?>)">
                <i class="fas fa-pencil me-1"></i>Edit
            </button>
            <button class="btn btn-outline-danger"
                    onclick="confirmDelete(<?= $id ?>, <?= htmlspecialchars(json_encode($server['name'])) ?>)">
                <i class="fas fa-trash-can me-1"></i>Delete
            </button>
        </div>
    </div>

    <!-- Server meta row -->
    <div class="d-flex align-items-center gap-3 mb-4 text-muted small">
        <span><i class="fas fa-network-wired me-1"></i>
            <span style="font-family:monospace"><?= htmlspecialchars($server['host']) ?>:<?= $server['port'] ?></span>
        </span>
        <?php if (!empty($server['description'])): ?>
        <span><i class="fas fa-circle" style="font-size:.35rem;vertical-align:middle"></i></span>
        <span><?= htmlspecialchars($server['description']) ?></span>
        <?php endif; ?>
        <?php if (!empty($server['notify_email'])): ?>
        <span><i class="fas fa-circle" style="font-size:.35rem;vertical-align:middle"></i></span>
        <span><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($server['notify_email']) ?></span>
        <?php endif; ?>
        <span class="ms-auto" id="last-checked-label" style="font-size:.75rem">
            <?= $lastCheck ? 'Last checked: ' . utcToLocal($lastCheck, 'g:i:s A') : 'No checks yet' ?>
        </span>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">Current Status</div>
                <div class="stat-value" id="stat-status" style="font-size:1.1rem">
                    <?php
                    if ($lastStatus === 'online')       echo '<span class="good">Online</span>';
                    elseif ($lastStatus === 'offline')  echo '<span class="bad">Offline</span>';
                    elseif ($lastStatus === 'warning')  echo '<span class="warn">Degraded</span>';
                    else                                echo '<span style="color:#9ca3af">Unknown</span>';
                    ?>
                </div>
                <div class="stat-sub" id="stat-detail"><?= $lastCheck ? utcToLocal($lastCheck, 'g:i A') : '' ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">Response Time</div>
                <div class="stat-value" id="stat-ms">
                    <?php
                    $lastMs = !empty($history) ? $history[0]['ms'] : null;
                    echo $lastMs !== null ? $lastMs . '<span style="font-size:.9rem;color:#6b7280">ms</span>' : '—';
                    ?>
                </div>
                <div class="stat-sub" id="stat-ms-sub">
                    <?php
                    $msList = array_filter(array_column($history, 'ms'), fn($v) => $v !== null);
                    if (!empty($msList)) echo 'avg ' . round(array_sum($msList) / count($msList)) . 'ms over last ' . count($history) . ' checks';
                    ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">30-Day Uptime</div>
                <div class="stat-value <?= $uptime30d === null ? '' : ($uptime30d > 50 ? 'good' : 'bad') ?>">
                    <?= $uptime30d !== null ? $uptime30d . '<span style="font-size:.9rem;color:inherit">%</span>' : '—' ?>
                </div>
                <div class="stat-sub"><?= $uRow['total'] ?> checks recorded</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">Total Incidents</div>
                <div class="stat-value <?= count($incidents) > 0 ? 'bad' : 'good' ?>">
                    <?= count($incidents) ?>
                </div>
                <div class="stat-sub">
                    <?php
                    $open = array_filter($incidents, fn($i) => !$i['resolved_at']);
                    echo count($open) > 0 ? count($open) . ' currently open' : 'none active';
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat cards row 2 -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">Downtime (30d)</div>
                <div class="stat-value <?= $totalDownSec > 0 ? 'bad' : 'good' ?>" style="font-size:1.2rem">
                    <?= $totalDownSec > 0 ? fmtDuration($totalDownSec) : 'None' ?>
                </div>
                <div class="stat-sub">across all incidents</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">Longest Outage</div>
                <div class="stat-value <?= $longestOutageSec > 0 ? 'bad' : 'good' ?>" style="font-size:1.2rem">
                    <?= $longestOutageSec > 0 ? fmtDuration($longestOutageSec) : 'None' ?>
                </div>
                <div class="stat-sub">worst single incident</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">Response Trend</div>
                <div class="stat-value" id="stat-trend" style="font-size:1.2rem">—</div>
                <div class="stat-sub" id="stat-trend-sub">last 10 vs previous 10</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-label">Consecutive Failures</div>
                <div class="stat-value" id="stat-streak" style="font-size:1.2rem">—</div>
                <div class="stat-sub" id="stat-streak-sub">checks in a row</div>
            </div>
        </div>
    </div>

    <!-- 24-hour timeline -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="section-label">24-Hour Timeline</div>
            <div class="ubar" id="timeline-24h" style="height:28px">
                <?php
                // Render oldest (index 23) → newest (index 0)
                for ($b = 23; $b >= 0; $b--):
                    $bucket = $timelineBuckets[$b];
                    if ($bucket === null):
                        echo '<span class="useg useg-empty" title="No data"></span>';
                    else:
                        [$on, $tot] = $bucket;
                        $pct = $tot > 0 ? $on / $tot : 0;
                        $cls = $pct >= 0.8 ? 'useg-ok' : ($pct >= 0.5 ? 'useg-warn' : 'useg-err');
                        $label = round($pct * 100) . '% up · ' . $tot . ' check' . ($tot !== 1 ? 's' : '');
                        // Compute the actual hour label
                        $hourLabel = (new DateTime('now', new DateTimeZone('America/Chicago')))
                            ->modify("-{$b} hours")->format('g A');
                        echo "<span class=\"useg {$cls}\" data-tip=\"{$hourLabel} — {$label}\"></span>";
                    endif;
                endfor;
                ?>
            </div>
            <div class="d-flex justify-content-between text-muted mt-1" style="font-size:.65rem">
                <span>24h ago</span>
                <span>18h ago</span>
                <span>12h ago</span>
                <span>6h ago</span>
                <span>Now</span>
            </div>
        </div>
    </div>

    <!-- Uptime bar -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="section-label">Uptime — last 90 checks</div>
            <div class="ubar" id="ubar"></div>
            <div class="d-flex justify-content-between text-muted mt-1" style="font-size:.65rem">
                <span>Oldest</span><span>Now</span>
            </div>
        </div>
    </div>

    <!-- Response time chart -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="section-label mb-0">Response Time</div>
                <span class="text-muted" style="font-size:.75rem" id="spark-avg-label"></span>
            </div>
            <svg id="sparkline" width="100%" height="180" preserveAspectRatio="none" style="display:block"></svg>
        </div>
    </div>

    <!-- Incident history -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold" style="font-size:.875rem">Incident History</span>
            <span class="badge bg-secondary"><?= count($incidents) ?></span>
        </div>
        <?php if (empty($incidents)): ?>
        <div class="card-body text-center text-muted py-4">
            <i class="fas fa-check-circle text-success me-2"></i>No incidents recorded.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th class="ps-3" style="width:90px">Status</th>
                        <th>Started</th>
                        <th>Resolved</th>
                        <th>Duration</th>
                        <th class="pe-3">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incidents as $inc):
                        $durSec = (int)$inc['duration_sec'];
                        if ($durSec < 60)        $dur = $durSec . 's';
                        elseif ($durSec < 3600)  $dur = floor($durSec/60) . 'm ' . ($durSec%60) . 's';
                        else                     $dur = floor($durSec/3600) . 'h ' . floor(($durSec%3600)/60) . 'm';
                    ?>
                    <tr>
                        <td class="ps-3">
                            <?php if ($inc['resolved_at']): ?>
                            <span class="badge bg-secondary">Resolved</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Active</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= utcToLocal($inc['started_at']) ?></td>
                        <td class="small"><?= $inc['resolved_at'] ? utcToLocal($inc['resolved_at']) : '—' ?></td>
                        <td class="small">
                            <?php if (!$inc['resolved_at']): ?>
                            <span class="text-danger fw-semibold">Ongoing — <?= $dur ?></span>
                            <?php else: ?>
                            <?= $dur ?>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted pe-3"><?= htmlspecialchars($inc['detail'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="tip"></div>

<!-- ══ Edit Modal ══ -->
<div class="modal fade" id="serverModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Server</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editServerId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="serverName">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Host / IP <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="serverHost">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Protocol <span class="text-danger">*</span></label>
                        <select class="form-select" id="serverProtocol">
                            <option value="tcp">TCP</option>
                            <option value="http">HTTP</option>
                            <option value="https">HTTPS</option>
                            <option value="ping">Ping (ICMP)</option>
                            <option value="dns">DNS</option>
                            <option value="mysql">MySQL</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Port <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="serverPort" min="1" max="65535">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" class="form-control" id="serverDesc">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Alert Email</label>
                    <input type="email" class="form-control" id="serverEmail" placeholder="Leave blank to disable">
                </div>
                <div id="serverModalError" class="text-danger small" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="saveServerBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Delete Modal ══ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete Server</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2"><p id="deleteMsg" class="mb-0"></p></div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="deleteConfirmBtn"><i class="fas fa-trash-can me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
const serverId   = <?= $id ?>;

const tipEl = document.getElementById('tip');
function showTip(e, text) { tipEl.textContent = text; tipEl.style.display = 'block'; moveTip(e); }
function moveTip(e) {
    tipEl.style.left = '';
    tipEl.style.top  = '';
    const tw   = tipEl.offsetWidth;
    const th   = tipEl.offsetHeight;
    let   left = e.clientX + 14;
    let   top  = e.clientY - th - 10;
    if (top < 6)                          top  = e.clientY + 14;   // flip below cursor
    if (left + tw > window.innerWidth - 6) left = e.clientX - tw - 14; // flip left
    tipEl.style.left = left + 'px';
    tipEl.style.top  = top  + 'px';
}
function hideTip() { tipEl.style.display = 'none'; }
let currentHistory = <?= json_encode($history) ?>;
let pendingDeleteId = null;

// Initial render
renderBar(currentHistory);
renderSparkline(currentHistory);
updateTrend(currentHistory);
updateStreak(currentHistory);

// 24h timeline tooltip delegation
const tl = document.getElementById('timeline-24h');
if (tl) {
    tl.addEventListener('mouseover', e => { if (e.target.dataset.tip) showTip(e, e.target.dataset.tip); });
    tl.addEventListener('mousemove', e => { if (e.target.dataset.tip) moveTip(e); });
    tl.addEventListener('mouseleave', hideTip);
}

function updateTrend(history) {
    const el  = document.getElementById('stat-trend');
    const sub = document.getElementById('stat-trend-sub');
    if (!el) return;
    const vals = history.filter(h => h.ms != null).map(h => +h.ms);
    if (vals.length < 10) { el.textContent = '—'; return; }
    const avg = arr => arr.reduce((a, b) => a + b, 0) / arr.length;
    const recent = avg(vals.slice(0, 10));
    const prev   = avg(vals.slice(10, 20));
    if (vals.length < 20) { el.textContent = '—'; return; }
    const diff   = Math.round(recent - prev);
    const pct    = Math.abs(Math.round((diff / prev) * 100));
    if (Math.abs(diff) < 5) {
        el.innerHTML = '<span style="color:#6b7280">→ Stable</span>';
        if (sub) sub.textContent = 'no significant change';
    } else if (diff > 0) {
        el.innerHTML = `<span class="bad">↑ +${diff}ms</span>`;
        if (sub) sub.textContent = `${pct}% slower than before`;
    } else {
        el.innerHTML = `<span class="good">↓ ${diff}ms</span>`;
        if (sub) sub.textContent = `${pct}% faster than before`;
    }
}

function updateStreak(history) {
    const el  = document.getElementById('stat-streak');
    const sub = document.getElementById('stat-streak-sub');
    if (!el) return;
    let streak = 0;
    for (const h of history) {
        if (h.s !== 'online') streak++;
        else break;
    }
    if (streak === 0) {
        el.innerHTML = '<span class="good">0</span>';
        if (sub) sub.textContent = 'currently healthy';
    } else {
        el.innerHTML = `<span class="bad">${streak}</span>`;
        if (sub) sub.textContent = `check${streak !== 1 ? 's' : ''} failed in a row`;
    }
}

function fmtUtc(str) {
    if (!str) return '';
    return new Date(str.replace(' ', 'T') + 'Z').toLocaleString('en-US', {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});
}

function renderBar(history) {
    const el  = document.getElementById('ubar');
    if (!el) return;
    const MAX = 90;
    const rev = [...history].reverse(); // oldest first
    const pad = Array(Math.max(0, MAX - rev.length)).fill(null).concat(rev).slice(-MAX);
    el.innerHTML = pad.map(d => {
        if (!d) return '<span class="useg useg-empty"></span>';
        const cls   = { online:'useg-ok', offline:'useg-err', warning:'useg-warn' }[d.s] ?? 'useg-empty';
        const parts = [d.s, d.ms != null ? d.ms + 'ms' : null, fmtUtc(d.checked_at)].filter(Boolean);
        return `<span class="useg ${cls}" data-tip="${parts.join(' · ')}"></span>`;
    }).join('');

    // Tooltip via event delegation
    el.addEventListener('mouseover', e => { if (e.target.dataset.tip) showTip(e, e.target.dataset.tip); });
    el.addEventListener('mousemove', e => { if (e.target.dataset.tip) moveTip(e); });
    el.addEventListener('mouseleave', hideTip);
}

function renderSparkline(history) {
    const svg = document.getElementById('sparkline');
    const avg = document.getElementById('spark-avg-label');

    const items = [...history].reverse().filter(h => h.ms != null);
    const vals  = items.map(h => +h.ms);

    if (vals.length < 2) {
        svg.innerHTML = '';
        if (avg) avg.textContent = '';
        return;
    }

    const W     = svg.clientWidth || svg.parentElement.clientWidth || 600;
    const H     = 180;
    const padL  = 42;  // left margin for y-axis labels
    const padR  = 8;
    const padT  = 16;
    const padB  = 16;
    const plotW = W - padL - padR;
    const plotH = H - padT - padB;
    const min   = Math.min(...vals);
    const max   = Math.max(...vals);
    const rng   = Math.max(max - min, 1);
    const mean  = Math.round(vals.reduce((a, b) => a + b, 0) / vals.length);

    const coords = vals.map((v, i) => ({
        x:  padL + (i / (vals.length - 1)) * plotW,
        y:  padT + (1 - (v - min) / rng) * plotH,
        ms: v,
        ts: items[i].checked_at
            ? new Date(items[i].checked_at.replace(' ', 'T') + 'Z')
                .toLocaleString('en-US', {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'})
            : '',
    }));

    const ptStr    = coords.map(c => `${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' ');
    const areaPath = `M${coords[0].x.toFixed(1)},${padT + plotH} `
        + `L${coords[0].x.toFixed(1)},${coords[0].y.toFixed(1)} `
        + coords.slice(1).map(c => `L${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' ')
        + ` L${coords[coords.length-1].x.toFixed(1)},${padT + plotH} Z`;

    const dots = coords.map(c => {
        const label = [c.ms + 'ms', c.ts].filter(Boolean).join(' · ');
        return `<circle cx="${c.x.toFixed(1)}" cy="${c.y.toFixed(1)}" r="3" class="spark-dot"
            onmouseenter="showTip(event,'${label}'); this.setAttribute('r','6'); this.style.fill='#3b82f6'; this.style.stroke='#fff';"
            onmouseleave="hideTip(); this.setAttribute('r','8'); this.style.fill='transparent'; this.style.stroke='transparent';"/>`;
    }).join('');

    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.innerHTML = `
        <defs>
            <linearGradient id="area-grad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%"   stop-color="#3b82f6" stop-opacity=".15"/>
                <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
            </linearGradient>
        </defs>
        <text x="0" y="${padT + 4}" font-size="10" fill="#9ca3af" font-family="sans-serif">${max}ms</text>
        <text x="0" y="${padT + plotH + 4}" font-size="10" fill="#9ca3af" font-family="sans-serif">${min}ms</text>
        <path d="${areaPath}" fill="url(#area-grad)"/>
        <polyline points="${ptStr}" fill="none" stroke="#3b82f6"
                  stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
        ${dots}`;

    if (avg) avg.textContent = `avg ${mean}ms · min ${min}ms · max ${max}ms`;
}

function runCheck() {
    const icon = document.getElementById('refreshIcon');
    const btn  = document.getElementById('refreshBtn');
    icon.classList.add('fa-spin');
    btn.disabled = true;

    fetch(`/server_board/ajax/check_server.php?id=${serverId}`)
        .then(r => r.json())
        .then(d => {
            icon.classList.remove('fa-spin');
            btn.disabled = false;

            // Update dot
            const dot = document.getElementById('main-dot');
            if (dot) dot.className = 'sdot ' + (d.status || 'checking');

            // Update stat cards
            const statStatus = document.getElementById('stat-status');
            const statDetail = document.getElementById('stat-detail');
            const statMs     = document.getElementById('stat-ms');
            const statMsSub  = document.getElementById('stat-ms-sub');

            if (statStatus) {
                const labels = { online: '<span class="good">Online</span>', offline: '<span class="bad">Offline</span>', warning: '<span class="warn">Degraded</span>' };
                statStatus.innerHTML = labels[d.status] ?? '<span style="color:#9ca3af">Unknown</span>';
            }
            if (statDetail) statDetail.textContent = d.detail ?? '';
            if (statMs) {
                statMs.innerHTML = d.ms != null
                    ? d.ms + '<span style="font-size:.9rem;color:#6b7280">ms</span>'
                    : '—';
            }

            // Update last checked label
            const lbl = document.getElementById('last-checked-label');
            if (lbl) lbl.textContent = 'Last checked: ' + new Date().toLocaleTimeString();

            // Re-render bar + sparkline
            if (d.history?.length) {
                currentHistory = d.history;
                renderBar(d.history);
                renderSparkline(d.history);
                updateTrend(d.history);
                updateStreak(d.history);

                const msList = d.history.filter(h => h.ms != null).map(h => +h.ms);
                if (statMsSub && msList.length) {
                    statMsSub.textContent = `avg ${Math.round(msList.reduce((a,b)=>a+b,0)/msList.length)}ms over last ${d.history.length} checks`;
                }
            }
        })
        .catch(() => {
            icon.classList.remove('fa-spin');
            btn.disabled = false;
            const dot = document.getElementById('main-dot');
            if (dot) dot.className = 'sdot offline';
        });
}

// Auto-refresh every 30s
runCheck();
setInterval(runCheck, 30000);

function openEditModal(s) {
    document.getElementById('editServerId').value    = s.server_id;
    document.getElementById('serverName').value      = s.name;
    document.getElementById('serverHost').value      = s.host;
    document.getElementById('serverProtocol').value  = s.protocol;
    document.getElementById('serverPort').value      = s.port;
    document.getElementById('serverDesc').value      = s.description ?? '';
    document.getElementById('serverEmail').value     = s.notify_email ?? '';
    document.getElementById('serverModalError').style.display = 'none';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('serverModal')).show();
}

document.getElementById('saveServerBtn').addEventListener('click', () => {
    const errEl = document.getElementById('serverModalError');
    const payload = {
        server_id: parseInt(document.getElementById('editServerId').value) || 0,
        name: document.getElementById('serverName').value.trim(),
        host: document.getElementById('serverHost').value.trim(),
        protocol: document.getElementById('serverProtocol').value,
        port: parseInt(document.getElementById('serverPort').value),
        description: document.getElementById('serverDesc').value.trim(),
        notify_email: document.getElementById('serverEmail').value.trim(),
    };
    if (!payload.name || !payload.host || !payload.port) {
        errEl.textContent = 'Name, host, and port are required.';
        errEl.style.display = 'block'; return;
    }
    errEl.style.display = 'none';
    fetch('/server_board/ajax/save_server.php', {
        method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload)
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else { errEl.textContent = d.error; errEl.style.display = 'block'; }
    });
});

function confirmDelete(id, name) {
    pendingDeleteId = id;
    document.getElementById('deleteMsg').textContent =
        `Delete "${name}"? This will also remove all check history and incidents. This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteModal')).show();
}

document.getElementById('deleteConfirmBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    fetch('/server_board/ajax/delete_server.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ server_id: pendingDeleteId })
    }).then(r => r.json()).then(d => {
        if (d.success) window.location.href = '/server_board/';
    });
});

document.getElementById('deleteModal').addEventListener('hidden.bs.modal', () => { pendingDeleteId = null; });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
