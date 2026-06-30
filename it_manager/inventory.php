<?php
require_once __DIR__ . '/../includes/db.php';
$activePage = 'inventory';
$pdo = getPDO();
$categories = $pdo->query("SELECT category_id, name FROM asset_categories ORDER BY category_id")->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<style>
.filter-card .card-body { padding: 0.75rem 1.25rem; }
.asset-tag-link { font-family: monospace; font-weight: 700; color: #002f77; text-decoration: none; }
.asset-tag-link:hover { text-decoration: underline; color: #001d4a; }
.row-cb { width: 16px; height: 16px; cursor: pointer; }
</style>
<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title"><i class="fas fa-box me-2 it-header-icon"></i>IT Inventory</h4>
        <div class="d-flex gap-2 align-items-center">
            <button class="btn btn-danger d-none" id="bulkDeleteBtn" onclick="openBulkDeleteModal()">
                <i class="fas fa-trash-can me-1"></i>Delete Selected (<span id="bulkCount">0</span>)
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssetModal">
                <i class="fas fa-plus me-1"></i>Add Asset
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card filter-card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search tag, make, model, serial…">
                </div>
                <div class="col-md-2">
                    <select id="filterCategory" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['category_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="filterStatus" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option>Active</option>
                        <option>Inactive</option>
                        <option>In Repair</option>
                        <option>Retired</option>
                        <option>Lost</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-outline-secondary w-100" id="clearFilters">
                        <i class="fas fa-xmark me-1"></i>Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div id="loadingIndicator" class="text-center py-4 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i>Loading…
            </div>
            <table class="table table-hover mb-0" id="assetsTable" style="display:none">
                <thead><tr>
                    <th class="ps-3" style="width:36px"><input type="checkbox" class="row-cb" id="selectAllChk" title="Select all"></th>
                    <th>Tag</th>
                    <th>Category</th>
                    <th>Make / Model</th>
                    <th>Serial #</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Location</th>
                    <th class="pe-3"></th>
                </tr></thead>
                <tbody id="assetsBody"></tbody>
            </table>
            <p id="emptyMsg" class="text-muted text-center py-4 mb-0" style="display:none">No assets found.</p>
        </div>
    </div>
</div>

<!-- Add Asset Modal -->
<div class="modal fade" id="addAssetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Asset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Asset Tag</label>
                        <input type="text" class="form-control" id="newAssetTag" readonly placeholder="Auto-generated">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="newCategory" required>
                            <option value="">Select…</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['category_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" id="newStatus">
                            <option>Active</option>
                            <option selected>Inactive</option>
                            <option>In Repair</option>
                            <option>Retired</option>
                            <option>Lost</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Make</label>
                        <input type="text" class="form-control" id="newMake" placeholder="e.g. Dell">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Model</label>
                        <input type="text" class="form-control" id="newModel" placeholder="e.g. Latitude 5540">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Serial Number</label>
                        <input type="text" class="form-control" id="newSerial">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Assign to Employee</label>
                        <input type="text" class="form-control mb-1" id="newEmployeeSearch" placeholder="Search employees…">
                        <select class="form-select" id="newEmployee" size="4" style="height:auto">
                            <option value="">None</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Assign to Location</label>
                        <select class="form-select" id="newLocation">
                            <option value="">None</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control" id="newNotes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveAssetBtn"><i class="fas fa-save me-1"></i>Save Asset</button>
            </div>
        </div>
    </div>
</div>

<script>
const statusBadge = {
    'Active':    '<span class="badge badge-active">Active</span>',
    'Inactive':  '<span class="badge badge-retired">Inactive</span>',
    'In Repair': '<span class="badge badge-repair">In Repair</span>',
    'Retired':   '<span class="badge badge-retired">Retired</span>',
    'Lost':      '<span class="badge badge-lost">Lost</span>',
};

let debounceTimer;
function loadAssets() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        const params = new URLSearchParams({
            search:   document.getElementById('searchInput').value,
            category: document.getElementById('filterCategory').value,
            status:   document.getElementById('filterStatus').value,
        });
        fetch('/it_manager/ajax/get_assets.php?' + params)
            .then(r => r.json())
            .then(renderAssets);
    }, 250);
}

function renderAssets(assets) {
    const tbody   = document.getElementById('assetsBody');
    const table   = document.getElementById('assetsTable');
    const empty   = document.getElementById('emptyMsg');
    const loading = document.getElementById('loadingIndicator');
    loading.style.display = 'none';
    if (!assets.length) {
        table.style.display = 'none';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';
    table.style.display = 'table';
    tbody.innerHTML = assets.map(a => `
        <tr data-id="${a.asset_id}">
            <td class="ps-3"><input type="checkbox" class="row-cb asset-cb" data-id="${a.asset_id}"></td>
            <td><a href="/it_manager/asset.php?id=${a.asset_id}" class="asset-tag text-decoration-none">${a.asset_tag}</a></td>
            <td class="text-muted small">${a.category_name ?? ''}</td>
            <td>${[a.make, a.model].filter(Boolean).join(' ') || '<span class="text-muted">—</span>'}</td>
            <td class="text-muted small" style="font-family:monospace">${a.serial_number ?? '—'}</td>
            <td>${statusBadge[a.status] ?? a.status}</td>
            <td class="small">${a.employee_name ?? '<span class="text-muted">—</span>'}</td>
            <td class="small">${a.location_name ? `${a.location_name} <span class="text-muted">(${a.campus_name})</span>` : '<span class="text-muted">—</span>'}</td>
            <td class="pe-3 text-end">
                <a href="/it_manager/asset.php?id=${a.asset_id}" class="btn btn-sm btn-outline-primary btn-action me-1"><i class="fas fa-eye"></i></a>
                <button class="btn btn-sm btn-outline-danger btn-action" onclick="confirmDeleteAsset(${a.asset_id}, '${a.asset_tag}')"><i class="fas fa-trash-can"></i></button>
            </td>
        </tr>
    `).join('');
    updateBulkBar();
}

let allEmployeeOptions = [];
function filterEmployeeSelect(searchId, selectId) {
    const q   = document.getElementById(searchId).value.toLowerCase();
    const sel = document.getElementById(selectId);
    sel.innerHTML = '<option value="">None</option>';
    allEmployeeOptions
        .filter(e => !q || e.name.toLowerCase().includes(q))
        .forEach(e => sel.insertAdjacentHTML('beforeend', `<option value="${e.employee_id}">${e.name}</option>`));
}

function loadDropdowns() {
    fetch('/it_manager/ajax/get_employees.php').then(r => r.json()).then(data => {
        allEmployeeOptions = data;
        filterEmployeeSelect('newEmployeeSearch', 'newEmployee');
    });
    fetch('/it_manager/ajax/get_locations.php').then(r => r.json()).then(data => {
        const sel = document.getElementById('newLocation');
        data.forEach(l => sel.insertAdjacentHTML('beforeend', `<option value="${l.location_id}">${l.campus_name} — ${l.name}</option>`));
    });
}

document.getElementById('newEmployeeSearch').addEventListener('input', () => filterEmployeeSelect('newEmployeeSearch', 'newEmployee'));

document.getElementById('addAssetModal').addEventListener('show.bs.modal', () => {
    fetch('/it_manager/ajax/get_assets.php?next_tag=1').then(r => r.json()).then(d => {
        document.getElementById('newAssetTag').value = d.next_tag ?? '';
    });
});

document.getElementById('saveAssetBtn').addEventListener('click', () => {
    if (!document.getElementById('newCategory').value) { alert('Please select a category.'); return; }
    fetch('/it_manager/ajax/save_asset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            category_id:          document.getElementById('newCategory').value,
            make:                 document.getElementById('newMake').value,
            model:                document.getElementById('newModel').value,
            serial_number:        document.getElementById('newSerial').value,
            status:               document.getElementById('newStatus').value,
            assigned_employee_id: document.getElementById('newEmployee').value || null,
            assigned_location_id: document.getElementById('newLocation').value || null,
            notes:                document.getElementById('newNotes').value,
        })
    }).then(r => r.json()).then(d => {
        if (d.success) window.location.href = '/it_manager/asset.php?id=' + d.asset_id;
        else alert('Error: ' + d.error);
    });
});

document.getElementById('clearFilters').addEventListener('click', () => {
    ['searchInput','filterCategory','filterStatus'].forEach(id => document.getElementById(id).value = '');
    loadAssets();
});

['filterCategory','filterStatus'].forEach(id => document.getElementById(id).addEventListener('change', loadAssets));
document.getElementById('searchInput').addEventListener('input', loadAssets);

loadDropdowns();

<?php if (!empty($_GET['status'])): ?>
document.getElementById('filterStatus').value = <?= json_encode($_GET['status']) ?>;
<?php endif; ?>

loadAssets();

<?php if (!empty($_GET['add'])): ?>
window.addEventListener('load', () => {
    new bootstrap.Modal(document.getElementById('addAssetModal')).show();
});
<?php endif; ?>
</script>
<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete Assets</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <p id="bulkDeleteMsg" class="mb-0"></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="bulkDeleteConfirmBtn"><i class="fas fa-trash-can me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Delete Asset Modal -->
<div class="modal fade" id="deleteAssetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete Asset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <p id="deleteAssetMsg" class="mb-0"></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="deleteAssetConfirmBtn"><i class="fas fa-trash-can me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingDeleteAssetId = null;

function confirmDeleteAsset(id, tag) {
    pendingDeleteAssetId = id;
    document.getElementById('deleteAssetMsg').textContent = `Permanently delete asset ${tag}? This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteAssetModal')).show();
}

document.getElementById('deleteAssetConfirmBtn').addEventListener('click', () => {
    if (!pendingDeleteAssetId) return;
    const btn = document.getElementById('deleteAssetConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting…';
    fetch('/it_manager/ajax/delete_asset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ asset_id: pendingDeleteAssetId })
    }).then(r => r.json()).then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-can me-1"></i>Delete';
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('deleteAssetModal')).hide();
            loadAssets();
        } else {
            document.getElementById('deleteAssetMsg').innerHTML =
                `<span class="text-danger"><i class="fas fa-circle-xmark me-1"></i>${d.error}</span>`;
        }
    });
});

document.getElementById('deleteAssetModal').addEventListener('hidden.bs.modal', () => {
    pendingDeleteAssetId = null;
    document.getElementById('deleteAssetMsg').textContent = '';
});

// ── Bulk selection ──────────────────────────────────────────
function getChecked() {
    return [...document.querySelectorAll('.asset-cb:checked')].map(cb => parseInt(cb.dataset.id));
}

function updateBulkBar() {
    const ids = getChecked();
    const btn = document.getElementById('bulkDeleteBtn');
    document.getElementById('bulkCount').textContent = ids.length;
    btn.classList.toggle('d-none', ids.length === 0);

    const allCbs = document.querySelectorAll('.asset-cb');
    const sel = document.getElementById('selectAllChk');
    if (sel) {
        sel.indeterminate = ids.length > 0 && ids.length < allCbs.length;
        sel.checked = allCbs.length > 0 && ids.length === allCbs.length;
    }
}

document.getElementById('assetsBody').addEventListener('change', e => {
    if (e.target.classList.contains('asset-cb')) updateBulkBar();
});

document.getElementById('selectAllChk').addEventListener('change', function() {
    document.querySelectorAll('.asset-cb').forEach(cb => cb.checked = this.checked);
    updateBulkBar();
});

function openBulkDeleteModal() {
    const ids = getChecked();
    if (!ids.length) return;
    document.getElementById('bulkDeleteMsg').textContent =
        `Permanently delete ${ids.length} selected asset${ids.length > 1 ? 's' : ''}? This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkDeleteModal')).show();
}

document.getElementById('bulkDeleteConfirmBtn').addEventListener('click', () => {
    const ids = getChecked();
    if (!ids.length) return;
    const btn = document.getElementById('bulkDeleteConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting…';
    fetch('/it_manager/ajax/bulk_delete_assets.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ asset_ids: ids })
    }).then(r => r.json()).then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash-can me-1"></i>Delete';
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal')).hide();
            loadAssets();
        } else {
            document.getElementById('bulkDeleteMsg').innerHTML =
                `<span class="text-danger"><i class="fas fa-circle-xmark me-1"></i>${d.error}</span>`;
        }
    });
});

document.getElementById('bulkDeleteModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('bulkDeleteMsg').textContent = '';
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
