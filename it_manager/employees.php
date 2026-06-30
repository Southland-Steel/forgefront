<?php
require_once __DIR__ . '/../includes/db.php';
$activePage = 'employees';
$pdo      = getPDO();
$sites    = $pdo->query("SELECT site_id, name, abbreviation FROM sites ORDER BY name")->fetchAll();
$campuses = $pdo->query("SELECT campus_id, name FROM campuses ORDER BY name")->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<style>
.row-cb { width: 16px; height: 16px; cursor: pointer; }
</style>
<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title"><i class="fas fa-users me-2 it-header-icon"></i>Employees</h4>
        <div class="d-flex gap-2 align-items-center">
            <button class="btn btn-danger d-none" id="bulkDeleteBtn" onclick="openBulkDeleteModal()">
                <i class="fas fa-trash-can me-1"></i>Delete Selected (<span id="bulkCount">0</span>)
            </button>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus me-1"></i>Add Employee
            </button>
        </div>
    </div>

    <!-- Search -->
    <div class="card filter-card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" id="empSearch" class="form-control form-control-sm" placeholder="Search name or email…">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-outline-secondary" id="clearSearch">
                        <i class="fas fa-xmark me-1"></i>Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div id="loadingIndicator" class="text-center py-4 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i>Loading…
            </div>
            <table class="table table-hover mb-0" id="employeesTable" style="display:none">
                <thead><tr>
                    <th class="ps-3" style="width:36px"><input type="checkbox" class="row-cb" id="selectAllChk" title="Select all"></th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Companies</th>
                    <th>Campuses</th>
                    <th>Assets Assigned</th>
                    <th class="pe-3"></th>
                </tr></thead>
                <tbody id="employeesBody"></tbody>
            </table>
            <p id="emptyMsg" class="text-muted text-center py-4 mb-0" style="display:none">No employees found.</p>
        </div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="employeeModalTitle"><i class="fas fa-plus me-2"></i>Add Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="empId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="empName">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" class="form-control" id="empEmail">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Companies</label>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($sites as $s): ?>
                        <div class="form-check">
                            <input class="form-check-input emp-site-check" type="checkbox"
                                   value="<?= $s['site_id'] ?>" id="empSite<?= $s['site_id'] ?>">
                            <label class="form-check-label" for="empSite<?= $s['site_id'] ?>">
                                <?= htmlspecialchars($s['name']) ?> <span class="text-muted">(<?= $s['abbreviation'] ?>)</span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Campuses</label>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($campuses as $c): ?>
                        <div class="form-check">
                            <input class="form-check-input emp-campus-check" type="checkbox"
                                   value="<?= $c['campus_id'] ?>" id="empCampus<?= $c['campus_id'] ?>">
                            <label class="form-check-label" for="empCampus<?= $c['campus_id'] ?>">
                                <?= htmlspecialchars($c['name']) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="empModalError" class="text-danger small" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveEmpBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Single Modal -->
<div class="modal fade" id="deleteEmpModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2"><p id="deleteEmpMsg" class="mb-0"></p></div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="deleteEmpConfirmBtn">
                    <i class="fas fa-trash-can me-1"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete Employees</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2"><p id="bulkDeleteMsg" class="mb-0"></p></div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="bulkDeleteConfirmBtn">
                    <i class="fas fa-trash-can me-1"></i>Delete All Selected
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let allEmployees   = [];
let pendingDeleteId = null;

function getCheckedIds() {
    return [...document.querySelectorAll('.emp-cb:checked')].map(c => parseInt(c.dataset.id));
}

function updateBulkBar() {
    const ids = getCheckedIds();
    const btn = document.getElementById('bulkDeleteBtn');
    document.getElementById('bulkCount').textContent = ids.length;
    btn.classList.toggle('d-none', ids.length === 0);
    const allCbs  = document.querySelectorAll('.emp-cb');
    const allChk  = document.getElementById('selectAllChk');
    if (allChk) allChk.indeterminate = ids.length > 0 && ids.length < allCbs.length;
    if (allChk) allChk.checked = allCbs.length > 0 && ids.length === allCbs.length;
}

function renderEmployees(data) {
    const loading = document.getElementById('loadingIndicator');
    const table   = document.getElementById('employeesTable');
    const empty   = document.getElementById('emptyMsg');
    loading.style.display = 'none';

    if (!data.length) {
        table.style.display = 'none';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';
    table.style.display = 'table';

    document.getElementById('employeesBody').innerHTML = data.map(e => `
        <tr data-name="${(e.name ?? '').toLowerCase()}" data-email="${(e.email ?? '').toLowerCase()}">
            <td class="ps-3"><input type="checkbox" class="row-cb emp-cb" data-id="${e.employee_id}" onchange="updateBulkBar()"></td>
            <td class="fw-semibold">${e.name}</td>
            <td class="text-muted small">${e.email ?? '—'}</td>
            <td class="small">${e.sites ?? '<span class="text-muted">None</span>'}</td>
            <td class="small">${e.campuses ?? '<span class="text-muted">None</span>'}</td>
            <td class="text-center">${e.asset_count ?? 0}</td>
            <td class="pe-3 text-end">
                <button class="btn btn-sm btn-outline-secondary btn-action me-1" onclick="openEditModal(${JSON.stringify(e).replace(/"/g, '&quot;')})">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger btn-action" onclick="confirmDelete(${e.employee_id}, ${JSON.stringify(e.name).replace(/"/g, '&quot;')}, ${e.asset_count ?? 0})">
                    <i class="fas fa-trash-can"></i>
                </button>
            </td>
        </tr>
    `).join('');

    updateBulkBar();
}

function applySearch() {
    const q = document.getElementById('empSearch').value.toLowerCase().trim();
    const filtered = q
        ? allEmployees.filter(e => (e.name ?? '').toLowerCase().includes(q) || (e.email ?? '').toLowerCase().includes(q))
        : allEmployees;
    renderEmployees(filtered);
}

function loadEmployees() {
    fetch('/it_manager/ajax/get_employees.php?with_details=1')
        .then(r => r.json())
        .then(data => {
            allEmployees = data;
            applySearch();
        });
}

document.getElementById('empSearch').addEventListener('input', applySearch);
document.getElementById('clearSearch').addEventListener('click', () => {
    document.getElementById('empSearch').value = '';
    applySearch();
});

document.getElementById('selectAllChk').addEventListener('change', function () {
    document.querySelectorAll('.emp-cb').forEach(cb => cb.checked = this.checked);
    updateBulkBar();
});

// ── Add / Edit Modal ──
function resetModal() {
    document.getElementById('empId').value    = '';
    document.getElementById('empName').value  = '';
    document.getElementById('empEmail').value = '';
    document.querySelectorAll('.emp-site-check, .emp-campus-check').forEach(c => c.checked = false);
    document.getElementById('empModalError').style.display = 'none';
}

function openAddModal() {
    resetModal();
    document.getElementById('employeeModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Add Employee';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('employeeModal')).show();
}

function openEditModal(e) {
    resetModal();
    document.getElementById('employeeModalTitle').innerHTML = '<i class="fas fa-pen me-2"></i>Edit Employee';
    document.getElementById('empId').value    = e.employee_id;
    document.getElementById('empName').value  = e.name;
    document.getElementById('empEmail').value = e.email ?? '';
    (e.site_id_list   ? e.site_id_list.split(',').map(Number)   : []).forEach(id => { const el = document.getElementById(`empSite${id}`);   if (el) el.checked = true; });
    (e.campus_id_list ? e.campus_id_list.split(',').map(Number) : []).forEach(id => { const el = document.getElementById(`empCampus${id}`); if (el) el.checked = true; });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('employeeModal')).show();
}

document.getElementById('saveEmpBtn').addEventListener('click', () => {
    const errEl = document.getElementById('empModalError');
    const name  = document.getElementById('empName').value.trim();
    if (!name) { errEl.textContent = 'Name is required.'; errEl.style.display = 'block'; return; }
    errEl.style.display = 'none';
    fetch('/it_manager/ajax/save_employee.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            employee_id: parseInt(document.getElementById('empId').value) || 0,
            name,
            email:    document.getElementById('empEmail').value.trim(),
            sites:    [...document.querySelectorAll('.emp-site-check:checked')].map(c => c.value),
            campuses: [...document.querySelectorAll('.emp-campus-check:checked')].map(c => c.value),
        }),
    }).then(r => r.json()).then(d => {
        if (d.success) { bootstrap.Modal.getInstance(document.getElementById('employeeModal')).hide(); loadEmployees(); }
        else { errEl.textContent = d.error; errEl.style.display = 'block'; }
    });
});

// ── Single Delete ──
function confirmDelete(id, name, assetCount) {
    pendingDeleteId = id;
    let msg = `Delete "${name}"? This cannot be undone.`;
    if (assetCount > 0) msg += ` They have ${assetCount} asset${assetCount !== 1 ? 's' : ''} assigned — these will be unassigned automatically.`;
    document.getElementById('deleteEmpMsg').textContent = msg;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteEmpModal')).show();
}

document.getElementById('deleteEmpConfirmBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    fetch('/it_manager/ajax/delete_employee.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_id: pendingDeleteId }),
    }).then(r => r.json()).then(d => {
        if (d.success) { bootstrap.Modal.getInstance(document.getElementById('deleteEmpModal')).hide(); loadEmployees(); }
    });
});
document.getElementById('deleteEmpModal').addEventListener('hidden.bs.modal', () => { pendingDeleteId = null; });

// ── Bulk Delete ──
function openBulkDeleteModal() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    document.getElementById('bulkDeleteMsg').textContent =
        `Delete ${ids.length} employee${ids.length !== 1 ? 's' : ''}? Any assets they have assigned will be unassigned automatically. This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkDeleteModal')).show();
}

document.getElementById('bulkDeleteConfirmBtn').addEventListener('click', () => {
    const ids = getCheckedIds();
    if (!ids.length) return;
    fetch('/it_manager/ajax/bulk_delete_employees.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ employee_ids: ids }),
    }).then(r => r.json()).then(d => {
        if (d.success) { bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal')).hide(); loadEmployees(); }
    });
});

loadEmployees();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
