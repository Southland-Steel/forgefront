<?php
require_once __DIR__ . '/../includes/db.php';
$activePage = 'employees';
$pdo   = getPDO();
$sites    = $pdo->query("SELECT site_id, name, abbreviation FROM sites ORDER BY name")->fetchAll();
$campuses = $pdo->query("SELECT campus_id, name FROM campuses ORDER BY name")->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title"><i class="fas fa-users me-2 it-header-icon"></i>Employees</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
            <i class="fas fa-plus me-1"></i>Add Employee
        </button>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div id="loadingIndicator" class="text-center py-4 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i>Loading…
            </div>
            <table class="table table-hover mb-0" id="employeesTable" style="display:none">
                <thead><tr>
                    <th class="ps-3">Name</th>
                    <th>Email</th>
                    <th>Companies</th>
                    <th>Campuses</th>
                    <th>Assets Assigned</th>
                    <th class="pe-3"></th>
                </tr></thead>
                <tbody id="employeesBody"></tbody>
            </table>
            <p id="emptyMsg" class="text-muted text-center py-4 mb-0" style="display:none">No employees yet.</p>
        </div>
    </div>
</div>

<!-- Add Employee Modal -->
<div class="modal fade" id="addEmployeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newEmpName">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" class="form-control" id="newEmpEmail">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Companies</label>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($sites as $s): ?>
                        <div class="form-check">
                            <input class="form-check-input site-check" type="checkbox" value="<?= $s['site_id'] ?>" id="site<?= $s['site_id'] ?>">
                            <label class="form-check-label" for="site<?= $s['site_id'] ?>">
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
                            <input class="form-check-input campus-check" type="checkbox" value="<?= $c['campus_id'] ?>" id="campus<?= $c['campus_id'] ?>">
                            <label class="form-check-label" for="campus<?= $c['campus_id'] ?>">
                                <?= htmlspecialchars($c['name']) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveEmployeeBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<script>
function loadEmployees() {
    fetch('/it_manager/ajax/get_employees.php?with_details=1')
        .then(r => r.json())
        .then(data => {
            const loading = document.getElementById('loadingIndicator');
            const table   = document.getElementById('employeesTable');
            const empty   = document.getElementById('emptyMsg');
            loading.style.display = 'none';
            if (!data.length) { empty.style.display = 'block'; return; }
            table.style.display = 'table';
            document.getElementById('employeesBody').innerHTML = data.map(e => `
                <tr>
                    <td class="ps-3 fw-semibold">${e.name}</td>
                    <td class="text-muted small">${e.email ?? '—'}</td>
                    <td class="small">${e.sites ?? '<span class="text-muted">None</span>'}</td>
                    <td class="small">${e.campuses ?? '<span class="text-muted">None</span>'}</td>
                    <td class="text-center">${e.asset_count ?? 0}</td>
                    <td class="pe-3 text-end">
                        <button class="btn btn-sm btn-outline-secondary btn-action" onclick="editEmployee(${e.employee_id})">
                            <i class="fas fa-pen"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        });
}

document.getElementById('saveEmployeeBtn').addEventListener('click', () => {
    const name = document.getElementById('newEmpName').value.trim();
    if (!name) { alert('Name is required.'); return; }
    const sites    = [...document.querySelectorAll('.site-check:checked')].map(c => c.value);
    const campuses = [...document.querySelectorAll('.campus-check:checked')].map(c => c.value);
    fetch('/it_manager/ajax/save_employee.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, email: document.getElementById('newEmpEmail').value, sites, campuses })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('addEmployeeModal')).hide();
            document.getElementById('newEmpName').value = '';
            document.getElementById('newEmpEmail').value = '';
            document.querySelectorAll('.site-check, .campus-check').forEach(c => c.checked = false);
            loadEmployees();
        } else alert('Error: ' + d.error);
    });
});

function editEmployee(id) {
    // TODO: populate edit modal
    alert('Edit coming soon — employee ID ' + id);
}

loadEmployees();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
