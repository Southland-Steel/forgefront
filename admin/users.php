<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requirePermission('users.manage');
$pdo   = getPDO();
$roles = $pdo->query("SELECT role_id, role_name, description FROM ff_roles WHERE is_active = 1 ORDER BY role_id")->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid px-4 pt-3">

    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4><i class="fas fa-users-cog me-2 text-primary"></i>User Management</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-plus me-1"></i>Add User
        </button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div id="loadingIndicator" class="text-center py-4 text-muted">
                <i class="fas fa-spinner fa-spin me-2"></i>Loading…
            </div>
            <table class="table table-hover mb-0" id="usersTable" style="display:none">
                <thead><tr>
                    <th class="ps-3">Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Roles</th>
                    <th>Last Login</th>
                    <th>Status</th>
                    <th class="pe-3"></th>
                </tr></thead>
                <tbody id="usersBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">First Name</label>
                        <input type="text" class="form-control" id="newFirstName">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Last Name</label>
                        <input type="text" class="form-control" id="newLastName">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="newUsername">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" id="newEmail">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="newPassword">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Roles</label>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($roles as $r): ?>
                            <div class="form-check">
                                <input class="form-check-input role-check" type="checkbox" value="<?= $r['role_id'] ?>" id="role<?= $r['role_id'] ?>">
                                <label class="form-check-label" for="role<?= $r['role_id'] ?>">
                                    <strong><?= htmlspecialchars($r['role_name']) ?></strong>
                                    <span class="text-muted small"> — <?= htmlspecialchars($r['description']) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveUserBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editUserId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">First Name</label>
                        <input type="text" class="form-control" id="editFirstName">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Last Name</label>
                        <input type="text" class="form-control" id="editLastName">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Username</label>
                        <input type="text" class="form-control" id="editUsername">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" id="editEmail">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">New Password <span class="text-muted small">(leave blank to keep current)</span></label>
                        <input type="password" class="form-control" id="editPassword" placeholder="Leave blank to keep current">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" id="editActive">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Roles</label>
                        <div class="d-flex flex-column gap-2" id="editRolesContainer">
                            <?php foreach ($roles as $r): ?>
                            <div class="form-check">
                                <input class="form-check-input edit-role-check" type="checkbox" value="<?= $r['role_id'] ?>" id="editRole<?= $r['role_id'] ?>">
                                <label class="form-check-label" for="editRole<?= $r['role_id'] ?>">
                                    <strong><?= htmlspecialchars($r['role_name']) ?></strong>
                                    <span class="text-muted small"> — <?= htmlspecialchars($r['description']) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveEditUserBtn"><i class="fas fa-save me-1"></i>Save Changes</button>
            </div>
        </div>
    </div>
</div>

<script>
function loadUsers() {
    fetch('/admin/ajax/get_users.php').then(r => r.json()).then(data => {
        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('usersTable').style.display = 'table';
        document.getElementById('usersBody').innerHTML = data.map(u => `
            <tr>
                <td class="ps-3 fw-semibold">${u.first_name ?? ''} ${u.last_name ?? ''}</td>
                <td class="text-muted">${u.username}</td>
                <td class="text-muted small">${u.email ?? '—'}</td>
                <td class="small">${u.roles ? u.roles.split(',').map(r => `<span class="badge bg-primary me-1">${r}</span>`).join('') : '<span class="text-muted">None</span>'}</td>
                <td class="text-muted small">${u.last_login ? new Date(u.last_login).toLocaleDateString() : 'Never'}</td>
                <td>${u.is_active == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td>
                <td class="pe-3 text-end">
                    <button class="btn btn-sm btn-outline-primary btn-action" onclick="openEdit(${JSON.stringify(u).replace(/"/g, '&quot;')})">
                        <i class="fas fa-pen"></i>
                    </button>
                </td>
            </tr>
        `).join('');
    });
}

document.getElementById('saveUserBtn').addEventListener('click', () => {
    const username = document.getElementById('newUsername').value.trim();
    const password = document.getElementById('newPassword').value;
    if (!username || !password) { alert('Username and password are required.'); return; }
    const roles = [...document.querySelectorAll('.role-check:checked')].map(c => c.value);
    fetch('/admin/ajax/save_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            username, password,
            first_name: document.getElementById('newFirstName').value,
            last_name:  document.getElementById('newLastName').value,
            email:      document.getElementById('newEmail').value,
            roles,
        })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
            document.getElementById('addUserModal').querySelectorAll('input').forEach(i => i.value = '');
            document.querySelectorAll('.role-check').forEach(c => c.checked = false);
            loadUsers();
        } else alert('Error: ' + d.error);
    });
});

function openEdit(u) {
    document.getElementById('editUserId').value    = u.user_id;
    document.getElementById('editFirstName').value = u.first_name ?? '';
    document.getElementById('editLastName').value  = u.last_name  ?? '';
    document.getElementById('editUsername').value  = u.username;
    document.getElementById('editEmail').value     = u.email ?? '';
    document.getElementById('editPassword').value  = '';
    document.getElementById('editActive').value    = u.is_active;
    const userRoles = (u.role_ids ?? '').split(',').map(r => r.trim());
    document.querySelectorAll('.edit-role-check').forEach(c => {
        c.checked = userRoles.includes(c.value);
    });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
}

document.getElementById('saveEditUserBtn').addEventListener('click', () => {
    const roles = [...document.querySelectorAll('.edit-role-check:checked')].map(c => c.value);
    fetch('/admin/ajax/save_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            user_id:    document.getElementById('editUserId').value,
            username:   document.getElementById('editUsername').value,
            first_name: document.getElementById('editFirstName').value,
            last_name:  document.getElementById('editLastName').value,
            email:      document.getElementById('editEmail').value,
            password:   document.getElementById('editPassword').value,
            is_active:  document.getElementById('editActive').value,
            roles,
        })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
            loadUsers();
        } else alert('Error: ' + d.error);
    });
});

loadUsers();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
