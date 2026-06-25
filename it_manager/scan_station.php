<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$activePage = '';
include __DIR__ . '/../includes/header.php';
?>
<style>
#resultCard { transition: all 0.2s; }
#scanInput  { font-size: 1.1rem; }
.scan-action-badge { font-size: 1.1rem; font-weight: 700; letter-spacing: .03em; }
.status-arrow { font-size: 0.95rem; color: #6c757d; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title"><i class="fas fa-barcode me-2 it-header-icon"></i>Scan Station</h4>
        <span class="badge bg-success fs-6" id="statusBadge"><i class="fas fa-circle-dot me-1"></i>Listening</span>
    </div>

    <!-- Input -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <label class="form-label text-muted small fw-semibold mb-1">Scan barcode or type asset tag</label>
            <div class="input-group">
                <input type="text" id="scanInput" class="form-control form-control-lg"
                    placeholder="e.g. FF-0001" autocomplete="off" autofocus>
                <button class="btn btn-primary" id="scanBtn">
                    <i class="fas fa-barcode me-1"></i>Submit
                </button>
            </div>
            <div class="form-text">Press <kbd>Enter</kbd> or click Submit. Any keystroke on this page goes straight to the input.</div>
        </div>
    </div>

    <!-- Result -->
    <div id="resultCard" class="card mb-3" style="display:none">
        <div class="card-body py-4 text-center" id="resultBody"></div>
    </div>

    <!-- Log -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-clock-rotate-left me-2 text-secondary"></i>Session Log</span>
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" id="clearLogBtn">Clear</button>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" id="scanLogTable" style="display:none">
                <thead><tr>
                    <th class="ps-3">Time</th>
                    <th>Tag</th>
                    <th>Asset</th>
                    <th>Action</th>
                    <th class="pe-3">Status</th>
                </tr></thead>
                <tbody id="scanLogBody"></tbody>
            </table>
            <p id="logEmpty" class="text-muted text-center py-4 mb-0">No scans yet this session.</p>
        </div>
    </div>
</div>

<script>
const scanInput  = document.getElementById('scanInput');
const scanBtn    = document.getElementById('scanBtn');
const resultCard = document.getElementById('resultCard');
const resultBody = document.getElementById('resultBody');
const logBody    = document.getElementById('scanLogBody');
const logTable   = document.getElementById('scanLogTable');
const logEmpty   = document.getElementById('logEmpty');

// Route all keystrokes to the input
document.addEventListener('keydown', e => {
    if (document.activeElement !== scanInput &&
        !['INPUT','SELECT','TEXTAREA','BUTTON'].includes(document.activeElement.tagName)) {
        scanInput.focus();
    }
});

function processScan() {
    const raw = scanInput.value.trim();
    scanInput.value = '';
    if (!raw) return;

    document.getElementById('statusBadge').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Scanning…';

    fetch('/it_manager/ajax/scan_toggle.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scan_data: raw })
    }).then(r => r.json()).then(d => {
        showResult(d);
        addLogEntry(d);
        document.getElementById('statusBadge').className = 'badge fs-6 bg-success';
        document.getElementById('statusBadge').innerHTML = '<i class="fas fa-circle-dot me-1"></i>Listening';
        scanInput.focus();
    }).catch(() => {
        showResult({ success: false, error: 'Request failed' });
        document.getElementById('statusBadge').className = 'badge fs-6 bg-success';
        document.getElementById('statusBadge').innerHTML = '<i class="fas fa-circle-dot me-1"></i>Listening';
        scanInput.focus();
    });
}

function showResult(d) {
    resultCard.style.display = 'block';
    if (d.success) {
        const isOut = d.action === 'Checked Out';
        const color = isOut ? '#d1f0e0' : '#e2e3e5';
        const iconColor = isOut ? '#0a6640' : '#41464b';
        const icon = isOut ? 'fa-arrow-right-from-bracket' : 'fa-arrow-right-to-bracket';
        resultCard.style.border = `2px solid ${iconColor}`;
        resultBody.innerHTML = `
            <div style="font-size:2.5rem;color:${iconColor}" class="mb-2">
                <i class="fas ${icon}"></i>
            </div>
            <div class="scan-action-badge mb-1" style="color:${iconColor}">${d.action}</div>
            <div style="font-size:1.5rem;font-weight:700;font-family:monospace" class="mb-1">
                <a href="/it_manager/asset.php?id=${d.asset_id}" class="text-decoration-none text-dark">${d.asset_tag}</a>
            </div>
            <div class="text-muted mb-2">${d.asset_name || d.category}</div>
            <div class="status-arrow">
                <span class="badge bg-secondary">${d.old_status}</span>
                <i class="fas fa-arrow-right mx-2"></i>
                <span class="badge bg-${isOut ? 'success' : 'secondary'}">${d.new_status}</span>
            </div>`;
    } else {
        resultCard.style.border = '2px solid #842029';
        resultBody.innerHTML = `
            <div style="font-size:2.5rem;color:#842029" class="mb-2"><i class="fas fa-circle-xmark"></i></div>
            <div class="fw-bold text-danger mb-1">Scan Failed</div>
            <div class="text-muted">${d.error ?? 'Unknown error'}${d.asset_tag ? ` — <span style="font-family:monospace">${d.asset_tag}</span>` : ''}</div>`;
    }
}

function addLogEntry(d) {
    const time = new Date().toLocaleTimeString();
    logEmpty.style.display = 'none';
    logTable.style.display = 'table';
    const tr = document.createElement('tr');
    if (d.success) {
        const isOut = d.action === 'Checked Out';
        tr.innerHTML = `
            <td class="ps-3 text-muted small">${time}</td>
            <td style="font-family:monospace;font-weight:700">
                <a href="/it_manager/asset.php?id=${d.asset_id}" class="text-decoration-none">${d.asset_tag}</a>
            </td>
            <td class="small">${d.asset_name || d.category}</td>
            <td><span class="badge bg-${isOut ? 'success' : 'secondary'}">${d.action}</span></td>
            <td class="pe-3 small text-muted">${d.old_status} → ${d.new_status}</td>`;
    } else {
        tr.innerHTML = `
            <td class="ps-3 text-muted small">${time}</td>
            <td colspan="3" class="text-danger small"><i class="fas fa-circle-xmark me-1"></i>${d.error ?? 'Error'}</td>
            <td class="pe-3"></td>`;
    }
    logBody.prepend(tr);
}

scanInput.addEventListener('keydown', e => { if (e.key === 'Enter') processScan(); });
scanBtn.addEventListener('click', processScan);
document.getElementById('clearLogBtn').addEventListener('click', () => {
    logBody.innerHTML = '';
    logTable.style.display = 'none';
    logEmpty.style.display = 'block';
    resultCard.style.display = 'none';
});

scanInput.focus();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
