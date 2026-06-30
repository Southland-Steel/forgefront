<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$pdo = getPDO();

$servers = $pdo->query("SELECT * FROM servers ORDER BY name")->fetchAll();

$uptimeMap  = [];
$statusMap  = [];
$lastMsMap  = [];
$lastAtMap  = [];
if (!empty($servers)) {
    $ids = implode(',', array_map('intval', array_column($servers, 'server_id')));

    // 30-day uptime %
    $rows = $pdo->query("
        SELECT server_id,
               ROUND(SUM(status = 'online') / COUNT(*) * 100, 2) AS uptime
        FROM server_checks
        WHERE server_id IN ($ids) AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY server_id
    ")->fetchAll();
    foreach ($rows as $r) $uptimeMap[$r['server_id']] = (float)$r['uptime'];

    // Last check status + ms + time per server
    $rows = $pdo->query("
        SELECT sc.server_id, sc.status, sc.response_ms, sc.checked_at
        FROM server_checks sc
        INNER JOIN (
            SELECT server_id, MAX(check_id) AS max_id FROM server_checks WHERE server_id IN ($ids) GROUP BY server_id
        ) latest ON sc.server_id = latest.server_id AND sc.check_id = latest.max_id
    ")->fetchAll();
    foreach ($rows as $r) {
        $statusMap[$r['server_id']] = $r['status'];
        $lastMsMap[$r['server_id']] = $r['response_ms'];
        $lastAtMap[$r['server_id']] = $r['checked_at'];
    }
}

include __DIR__ . '/../includes/header.php';
?>
<style>
.status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.status-dot.online   { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
.status-dot.offline  { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.2); }
.status-dot.warning  { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
.status-dot.checking { background: #9ca3af; animation: sdot-pulse 1.2s ease-in-out infinite; }
@keyframes sdot-pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.status-cell  { display: flex; align-items: center; gap: 8px; }
.ms-badge     { font-size: 0.72rem; color: #6b7280; }
.host-mono    { font-family: monospace; font-size: 0.88rem; color: #374151; }
.proto-badge  { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; padding: 2px 7px; border-radius: 4px; }
.pb-http      { background: #dbeafe; color: #1d4ed8; }
.pb-https     { background: #d1fae5; color: #065f46; }
.pb-tcp       { background: #ede9fe; color: #6d28d9; }
.pb-ping      { background: #fef3c7; color: #92400e; }
.pb-dns       { background: #e0f2fe; color: #0369a1; }
.pb-mysql     { background: #fce7f3; color: #9d174d; }
.srv-row      { cursor: pointer; }
.uptime-high  { color: #16a34a; font-weight: 600; }
.uptime-med   { color: #ca8a04; font-weight: 600; }
.uptime-low   { color: #dc2626; font-weight: 600; }
.uptime-none  { color: #9ca3af; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title">
            <i class="fas fa-server me-2" style="background:linear-gradient(to bottom,#4ade80,#16a34a);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;"></i>
            Server Monitor
        </h4>
        <div class="d-flex gap-2">
<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#serverModal" onclick="openAddModal()">
                <i class="fas fa-plus me-1"></i>Add Server
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($servers)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-server fa-3x mb-3 d-block" style="opacity:.2"></i>
                No servers added yet. Click <strong>Add Server</strong> to get started.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width:170px">Status</th>
                            <th>Name</th>
                            <th>Host</th>
                            <th style="width:90px">Protocol</th>
                            <th>Description</th>
                            <th style="width:90px">30d Uptime</th>
                            <th style="width:100px" class="pe-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servers as $s):
                            $sid   = $s['server_id'];
                            $u     = $uptimeMap[$sid] ?? null;
                            $uCls  = $u === null ? 'uptime-none' : ($u >= 99 ? 'uptime-high' : ($u >= 95 ? 'uptime-med' : 'uptime-low'));
                            $st    = $statusMap[$sid] ?? null;
                            $stMs  = $lastMsMap[$sid] ?? null;
                            $stAt  = $lastAtMap[$sid] ?? null;
                            $dotCls = $st ?? 'checking';
                            if ($st === 'online')       $stLabel = 'Online' . ($stMs !== null ? ' <span class="ms-badge">' . $stMs . 'ms</span>' : '');
                            elseif ($st === 'offline')  $stLabel = '<span class="text-danger">Offline</span>';
                            elseif ($st === 'warning')  $stLabel = '<span class="text-warning">Degraded</span>';
                            else                        $stLabel = '<span class="text-muted">No data</span>';
                        ?>
                        <tr class="srv-row" onclick="window.location='/server_board/server.php?id=<?= $sid ?>'">
                            <td class="ps-3">
                                <div class="status-cell">
                                    <span class="status-dot <?= $dotCls ?>" data-sid="<?= $sid ?>"></span>
                                    <span class="small status-text" data-sid="<?= $sid ?>"><?= $stLabel ?></span>
                                </div>
                            </td>
                            <td class="fw-semibold"><?= htmlspecialchars($s['name']) ?></td>
                            <td class="host-mono"><?= htmlspecialchars($s['host']) ?>:<?= $s['port'] ?></td>
                            <td><span class="proto-badge pb-<?= $s['protocol'] ?>"><?= strtoupper($s['protocol']) ?></span></td>
                            <td class="small text-muted"><?= htmlspecialchars($s['description'] ?? '') ?></td>
                            <td id="uptime-pct-<?= $sid ?>" class="small <?= $uCls ?>"><?= $u !== null ? $u . '%' : '—' ?></td>
                            <td class="pe-3 text-end" onclick="event.stopPropagation()">
                                <button class="btn btn-sm btn-outline-secondary py-0 px-2 me-1"
                                        onclick="openEditModal(<?= htmlspecialchars(json_encode($s)) ?>)">
                                    <i class="fas fa-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger py-0 px-2"
                                        onclick="confirmDelete(<?= $sid ?>, <?= htmlspecialchars(json_encode($s['name'])) ?>)">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($servers)): ?>
        <div class="card-footer text-muted small py-2">
            <i class="fas fa-rotate me-1"></i>Checked every 5 minutes automatically
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ Add/Edit Modal ══ -->
<div class="modal fade" id="serverModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serverModalTitle">Add Server</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editServerId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="serverName" placeholder="e.g. Web Server">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Host / IP <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="serverHost" placeholder="e.g. 192.168.1.10 or server.local">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Protocol <span class="text-danger">*</span></label>
                        <select class="form-select" id="serverProtocol" onchange="onProtocolChange()">
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
                        <input type="number" class="form-control" id="serverPort" min="1" max="65535" placeholder="—">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" class="form-control" id="serverDesc" placeholder="Optional notes">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Alert Email</label>
                    <input type="email" class="form-control" id="serverEmail" placeholder="alerts@example.com — leave blank to disable">
                    <div class="form-text">Sends an email when this server goes offline or recovers.</div>
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
let pendingDeleteId = null;
const defaultPorts = { tcp: '', http: 80, https: 443, ping: '', dns: 53, mysql: 3306 };

function pollStatus() {
    fetch('/server_board/ajax/get_status.php')
        .then(r => r.json())
        .then(data => {
            for (const [sid, d] of Object.entries(data)) {
                const dot  = document.querySelector(`.status-dot[data-sid="${sid}"]`);
                const text = document.querySelector(`.status-text[data-sid="${sid}"]`);
                const uEl  = document.getElementById(`uptime-pct-${sid}`);

                if (dot)  dot.className = 'status-dot ' + (d.status ?? 'checking');
                if (text) {
                    if (d.status === 'online')      text.innerHTML = 'Online' + (d.ms != null ? ` <span class="ms-badge">${d.ms}ms</span>` : '');
                    else if (d.status === 'offline') text.innerHTML = '<span class="text-danger">Offline</span>';
                    else if (d.status === 'warning') text.innerHTML = '<span class="text-warning">Degraded</span>';
                }
                if (uEl && d.uptime !== undefined) {
                    const pct = d.uptime;
                    uEl.textContent = pct !== null ? pct + '%' : '—';
                    uEl.className   = 'small ' + (pct === null ? 'uptime-none' : pct >= 99 ? 'uptime-high' : pct >= 95 ? 'uptime-med' : 'uptime-low');
                }
            }
        })
        .catch(() => {});
}
setInterval(pollStatus, 60000);

function onProtocolChange() {
    const proto = document.getElementById('serverProtocol').value;
    const p = defaultPorts[proto];
    document.getElementById('serverPort').value = p !== '' ? p : '';
}

function openAddModal() {
    document.getElementById('serverModalTitle').textContent = 'Add Server';
    ['editServerId','serverName','serverHost','serverPort','serverDesc','serverEmail']
        .forEach(id => document.getElementById(id).value = '');
    document.getElementById('serverProtocol').value = 'tcp';
    document.getElementById('serverModalError').style.display = 'none';
}

function openEditModal(s) {
    document.getElementById('serverModalTitle').textContent = 'Edit Server';
    document.getElementById('editServerId').value   = s.server_id;
    document.getElementById('serverName').value     = s.name;
    document.getElementById('serverHost').value     = s.host;
    document.getElementById('serverProtocol').value = s.protocol;
    document.getElementById('serverPort').value     = s.port;
    document.getElementById('serverDesc').value     = s.description ?? '';
    document.getElementById('serverEmail').value    = s.notify_email ?? '';
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
    }).then(r => r.json()).then(d => { if (d.success) location.reload(); });
});

document.getElementById('deleteModal').addEventListener('hidden.bs.modal', () => { pendingDeleteId = null; });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
