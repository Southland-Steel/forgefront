<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();
$activePage = 'inventory';
$pdo = getPDO();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /it_manager/inventory.php'); exit; }

$asset = $pdo->prepare("
    SELECT a.*, ac.name AS category_name,
           e.name AS employee_name, e.employee_id,
           l.name AS location_name, l.location_id,
           c.name AS campus_name
    FROM assets a
    LEFT JOIN asset_categories ac ON ac.category_id = a.category_id
    LEFT JOIN employees e ON e.employee_id = a.assigned_employee_id
    LEFT JOIN locations l ON l.location_id = a.assigned_location_id
    LEFT JOIN campuses c ON c.campus_id = l.campus_id
    WHERE a.asset_id = ?
");
$asset->execute([$id]);
$asset = $asset->fetch();
if (!$asset) { header('Location: /it_manager/inventory.php'); exit; }

$history = $pdo->prepare("
    SELECT h.*, e.name AS employee_name, l.name AS location_name
    FROM asset_history h
    LEFT JOIN employees e ON e.employee_id = h.employee_id
    LEFT JOIN locations l ON l.location_id = h.location_id
    WHERE h.asset_id = ?
    ORDER BY h.changed_at DESC
");
$history->execute([$id]);
$history = $history->fetchAll();

$employees = $pdo->query("SELECT employee_id, name FROM employees ORDER BY name")->fetchAll();
$locations = $pdo->query("
    SELECT l.location_id, l.name, c.name AS campus_name
    FROM locations l JOIN campuses c ON c.campus_id = l.campus_id
    ORDER BY c.name, l.name
")->fetchAll();
$categories  = $pdo->query("SELECT category_id, name FROM asset_categories ORDER BY category_id")->fetchAll();
$printConfig        = require __DIR__ . '/../print_labels_config.php';
$selectedPrinterKey = $_SESSION['ff_label_printer'] ?? array_key_first($printConfig['printers']);

$statusColors = [
    'Active'    => 'success',
    'Inactive'  => 'secondary',
    'In Repair' => 'warning',
    'Retired'   => 'secondary',
    'Lost'      => 'danger',
];

include __DIR__ . '/../includes/header.php';
?>
<style>
.action-badge { font-size: 0.7rem; padding: 0.3em 0.65em; border-radius: 6px; font-weight: 600; background: #e9ecef; color: #495057; }
.qr-card .card-body { padding: 1.75rem 1.25rem; }
.back-link { font-size: 0.85rem; color: #6c757d; text-decoration: none; }
.back-link:hover { color: #002f77; }
</style>
<div class="container-fluid px-4 pt-3">

    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4>
            <a href="/it_manager/inventory.php" class="back-link me-2">
                <i class="fas fa-arrow-left"></i> Assets
            </a>
            <span class="asset-tag" style="font-size:1.3rem"><?= htmlspecialchars($asset['asset_tag']) ?></span>
        </h4>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
                <i class="fas fa-user-tag me-1"></i>Assign / Move
            </button>
            <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#editModal">
                <i class="fas fa-pen me-1"></i>Edit
            </button>
        </div>
    </div>

    <div class="row g-3">
        <!-- Asset details -->
        <div class="col-md-8">
            <div class="card mb-3">
                <div class="card-header">
                    Asset Details
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-4">
                            <div class="detail-label">Category</div>
                            <div><?= htmlspecialchars($asset['category_name']) ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="detail-label">Make</div>
                            <div><?= htmlspecialchars($asset['make'] ?? '—') ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="detail-label">Model</div>
                            <div><?= htmlspecialchars($asset['model'] ?? '—') ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="detail-label">Serial Number</div>
                            <div style="font-family:monospace"><?= htmlspecialchars($asset['serial_number'] ?? '—') ?></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="detail-label">Status</div>
                            <div><span class="badge bg-<?= $statusColors[$asset['status']] ?? 'secondary' ?>"><?= $asset['status'] ?></span></div>
                        </div>
                        <div class="col-sm-4">
                            <div class="detail-label">Added</div>
                            <div><?= date('m/d/Y', strtotime($asset['created_at'])) ?></div>
                        </div>
                        <?php if ($asset['notes']): ?>
                        <div class="col-12">
                            <div class="detail-label">Notes</div>
                            <div><?= nl2br(htmlspecialchars($asset['notes'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Assignment -->
            <div class="card mb-3">
                <div class="card-header">
                    Current Assignment
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="detail-label"><i class="fas fa-user me-1"></i>Employee</div>
                            <div><?= $asset['employee_name'] ? htmlspecialchars($asset['employee_name']) : '<span class="text-muted">Unassigned</span>' ?></div>
                        </div>
                        <div class="col-sm-6">
                            <div class="detail-label"><i class="fas fa-location-dot me-1"></i>Location</div>
                            <div>
                                <?php if ($asset['location_name']): ?>
                                    <?= htmlspecialchars($asset['location_name']) ?>
                                    <span class="text-muted small">(<?= htmlspecialchars($asset['campus_name']) ?>)</span>
                                <?php else: ?>
                                    <span class="text-muted">No location</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-clock-rotate-left me-2 text-secondary"></i>History
                </div>
                <div class="card-body p-0">
                    <?php if (empty($history)): ?>
                    <p class="text-muted text-center py-4 mb-0">No history yet.</p>
                    <?php else: ?>
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr>
                            <th class="ps-3">Action</th>
                            <th>Employee</th>
                            <th>Location</th>
                            <th>Notes</th>
                            <th class="pe-3">When</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td class="ps-3"><span class="badge bg-secondary"><?= htmlspecialchars($h['action']) ?></span></td>
                            <td class="small"><?= htmlspecialchars($h['employee_name'] ?? '—') ?></td>
                            <td class="small"><?= htmlspecialchars($h['location_name'] ?? '—') ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($h['notes'] ?? '') ?></td>
                            <td class="pe-3 small text-muted"><?= date('m/d/y g:ia', strtotime($h['changed_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- QR Code & Barcode -->
        <div class="col-md-4 d-flex flex-column gap-3">
            <div class="card text-center">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-qrcode me-2 text-secondary"></i>QR Code</span>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" data-print-type="qr" data-bs-toggle="modal" data-bs-target="#printLabelModal">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
                <div class="card-body">
                    <div id="qrcode" class="d-inline-block mb-3"></div>
                    <div class="asset-tag fs-5 mb-1"><?= htmlspecialchars($asset['asset_tag']) ?></div>
                    <div class="text-muted small mb-3"><?= htmlspecialchars(trim($asset['make'] . ' ' . $asset['model'])) ?></div>
                    <a href="/it_manager/scan.php?tag=<?= urlencode($asset['asset_tag']) ?>" class="text-muted" style="font-size:.75rem; word-break:break-all">
                        <?= APP_BASE_URL ?>/it_manager/scan.php?tag=<?= urlencode($asset['asset_tag']) ?>
                    </a>
                </div>
            </div>

            <div class="card text-center">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-barcode me-2 text-secondary"></i>Barcode</span>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" data-print-type="barcode" data-bs-toggle="modal" data-bs-target="#printLabelModal">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
                <div class="card-body">
                    <svg id="barcode" class="mb-2 mw-100"></svg>
                    <div class="text-muted small"><?= htmlspecialchars(trim($asset['make'] . ' ' . $asset['model'])) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-tag me-2"></i>Assign / Move</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Employee</label>
                    <select class="form-select" id="assignEmployee">
                        <option value="">None</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['employee_id'] ?>" <?= $asset['assigned_employee_id'] == $e['employee_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Location</label>
                    <select class="form-select" id="assignLocation">
                        <option value="">None</option>
                        <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['location_id'] ?>" <?= $asset['assigned_location_id'] == $l['location_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($l['campus_name']) ?> — <?= htmlspecialchars($l['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea class="form-control" id="assignNotes" rows="2" placeholder="Optional reason for this change"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveAssignBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit Asset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Category</label>
                        <select class="form-select" id="editCategory">
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['category_id'] ?>" <?= $asset['category_id'] == $c['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" id="editStatus">
                            <?php foreach (['Active','Inactive','In Repair','Retired','Lost'] as $s): ?>
                            <option <?= $asset['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Make</label>
                        <input type="text" class="form-control" id="editMake" value="<?= htmlspecialchars($asset['make'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Model</label>
                        <input type="text" class="form-control" id="editModel" value="<?= htmlspecialchars($asset['model'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Serial Number</label>
                        <input type="text" class="form-control" id="editSerial" value="<?= htmlspecialchars($asset['serial_number'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control" id="editNotes" rows="2"><?= htmlspecialchars($asset['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveEditBtn"><i class="fas fa-save me-1"></i>Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Print Label Modal -->
<div class="modal fade" id="printLabelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-print me-2"></i>Print Label</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Label Type</label>
                    <select class="form-select" id="printLabelType">
                        <option value="qr">QR Code</option>
                        <option value="barcode">Barcode</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Printer</label>
                    <select class="form-select" id="printLabelPrinter">
                        <?php foreach ($printConfig['printers'] as $key => $printer): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $key === $selectedPrinterKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($printer['label']) ?> (<?= htmlspecialchars($printer['ip']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="printLabelMsg" class="alert mb-0" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="printLabelBtn"><i class="fas fa-print me-1"></i>Print</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
new QRCode(document.getElementById('qrcode'), {
    text: '<?= APP_BASE_URL ?>/it_manager/scan.php?tag=<?= urlencode($asset['asset_tag']) ?>',
    width: 180,
    height: 180,
});

JsBarcode('#barcode', <?= json_encode($asset['asset_tag']) ?>, {
    format:       'CODE128',
    width:        3,
    height:       120,
    displayValue: true,
    fontSize:     16,
    margin:       12,
});

document.querySelectorAll('[data-print-type]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('printLabelType').value = btn.dataset.printType;
    });
});

document.getElementById('saveAssignBtn').addEventListener('click', () => {
    fetch('/it_manager/ajax/assign_asset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            asset_id:    <?= $id ?>,
            employee_id: document.getElementById('assignEmployee').value || null,
            location_id: document.getElementById('assignLocation').value || null,
            notes:       document.getElementById('assignNotes').value,
        })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + d.error);
    });
});

document.getElementById('printLabelBtn').addEventListener('click', () => {
    const btn   = document.getElementById('printLabelBtn');
    const msgEl = document.getElementById('printLabelMsg');
    msgEl.style.display = 'none';
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Printing…';
    fetch('/it_manager/ajax/print_label.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            asset_tag: <?= json_encode($asset['asset_tag']) ?>,
            scan_url:  <?= json_encode(APP_BASE_URL . '/it_manager/scan.php?tag=' . urlencode($asset['asset_tag'])) ?>,
            category:  <?= json_encode($asset['category_name'] ?? '') ?>,
            make:      <?= json_encode($asset['make'] ?? '') ?>,
            model:     <?= json_encode($asset['model'] ?? '') ?>,
            type:      document.getElementById('printLabelType').value,
            printer:   document.getElementById('printLabelPrinter').value,
        })
    }).then(r => r.json()).then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-print me-1"></i>Print';
        msgEl.className = 'alert mb-0 ' + (d.success ? 'alert-success' : 'alert-danger');
        msgEl.textContent = d.success ? 'Label sent to printer.' : (d.error ?? 'Unknown error');
        msgEl.style.display = 'block';
    }).catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-print me-1"></i>Print';
        msgEl.className = 'alert mb-0 alert-danger';
        msgEl.textContent = 'Request failed.';
        msgEl.style.display = 'block';
    });
});

document.getElementById('printLabelModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('printLabelMsg').style.display = 'none';
});

document.getElementById('saveEditBtn').addEventListener('click', () => {
    fetch('/it_manager/ajax/save_asset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            asset_id:    <?= $id ?>,
            category_id: document.getElementById('editCategory').value,
            make:        document.getElementById('editMake').value,
            model:       document.getElementById('editModel').value,
            serial_number: document.getElementById('editSerial').value,
            status:      document.getElementById('editStatus').value,
            notes:       document.getElementById('editNotes').value,
        })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + d.error);
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
