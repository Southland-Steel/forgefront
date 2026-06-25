<?php
require_once __DIR__ . '/../includes/db.php';
$activePage = 'index';
$pdo = getPDO();

$stats = $pdo->query("
    SELECT
        COUNT(*)                         AS total,
        COALESCE(SUM(status = 'Active'),    0) AS active,
        COALESCE(SUM(status = 'Inactive'),  0) AS inactive,
        COALESCE(SUM(status = 'In Repair'), 0) AS in_repair,
        COALESCE(SUM(status = 'Retired'),   0) AS retired,
        COALESCE(SUM(status = 'Lost'),      0) AS lost
    FROM assets
")->fetch();

$byCategory = $pdo->query("
    SELECT ac.category_id, ac.name, COUNT(a.asset_id) AS cnt
    FROM asset_categories ac
    LEFT JOIN assets a ON a.category_id = ac.category_id
    GROUP BY ac.category_id, ac.name
    ORDER BY CASE WHEN ac.name = 'Miscellaneous' THEN 1 ELSE 0 END, ac.name
")->fetchAll();

$recent = $pdo->query("
    SELECT h.*, a.asset_id, a.asset_tag, a.make, a.model,
           e.name AS employee_name, l.name AS location_name
    FROM asset_history h
    JOIN assets a ON a.asset_id = h.asset_id
    LEFT JOIN employees e ON e.employee_id = h.employee_id
    LEFT JOIN locations l ON l.location_id = h.location_id
    ORDER BY h.changed_at DESC
    LIMIT 50
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<style>
.stat-card { border: 1px solid #e9ecef; box-shadow: 0 1px 4px rgba(0,0,0,.06); height: 100%; text-decoration: none; color: inherit; display: block; transition: box-shadow 0.15s, transform 0.15s; }
.stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.12); transform: translateY(-2px); color: inherit; text-decoration: none; }
.stat-card .card-body { padding: 1.25rem; display: flex; align-items: center; gap: 1rem; min-height: 90px; }
.stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
    padding: 0;
}
.stat-count { font-size: 1.75rem; font-weight: 700; line-height: 1.1; }
.stat-label { font-size: 0.8rem; color: #6c757d; margin-top: 3px; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="fas fa-gauge-high me-2 it-header-icon"></i>Dashboard</h5>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-2">
            <div class="card stat-card" style="cursor:default">
                <div class="card-body">
                    <div class="stat-icon" style="background:#dbeafe;color:#1d4ed8"><i class="fas fa-boxes-stacked"></i></div>
                    <div>
                        <div class="stat-count"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total Assets</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-2">
            <a class="card stat-card" href="/it_manager/inventory.php?status=Active">
                <div class="card-body">
                    <div class="stat-icon" style="background:#d1f0e0;color:#0a6640"><i class="fas fa-circle-check"></i></div>
                    <div>
                        <div class="stat-count"><?= $stats['active'] ?></div>
                        <div class="stat-label">Active</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-xl-2">
            <a class="card stat-card" href="/it_manager/inventory.php?status=Inactive">
                <div class="card-body">
                    <div class="stat-icon" style="background:#e2e3e5;color:#41464b"><i class="fas fa-circle-minus"></i></div>
                    <div>
                        <div class="stat-count"><?= $stats['inactive'] ?></div>
                        <div class="stat-label">Inactive</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-xl-2">
            <a class="card stat-card" href="/it_manager/inventory.php?status=In+Repair">
                <div class="card-body">
                    <div class="stat-icon" style="background:#fff3cd;color:#856404"><i class="fas fa-screwdriver-wrench"></i></div>
                    <div>
                        <div class="stat-count"><?= $stats['in_repair'] ?></div>
                        <div class="stat-label">In Repair</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-xl-2">
            <a class="card stat-card" href="/it_manager/inventory.php?status=Retired">
                <div class="card-body">
                    <div class="stat-icon" style="background:#ede9fe;color:#6d28d9"><i class="fas fa-box-archive"></i></div>
                    <div>
                        <div class="stat-count"><?= $stats['retired'] ?></div>
                        <div class="stat-label">Retired</div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-xl-2">
            <a class="card stat-card" href="/it_manager/inventory.php?status=Lost">
                <div class="card-body">
                    <div class="stat-icon" style="background:#f8d7da;color:#842029"><i class="fas fa-triangle-exclamation"></i></div>
                    <div>
                        <div class="stat-count"><?= $stats['lost'] ?></div>
                        <div class="stat-label">Lost</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-3">
        <!-- Category breakdown -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-tags me-2 text-secondary"></i>By Category</span>
                    <button class="btn btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus me-1"></i>Add
                    </button>
                </div>
                <div class="card-body p-0">
                    <div style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0">
                        <tbody>
                        <?php foreach ($byCategory as $row): ?>
                        <tr>
                            <td class="ps-3"><?= htmlspecialchars($row['name']) ?></td>
                            <td class="fw-semibold text-center"><?= $row['cnt'] ?></td>
                            <td class="pe-2 text-end">
                                <button class="btn btn-sm btn-outline-danger py-0 px-2"
                                    onclick="confirmDeleteCategory(<?= $row['category_id'] ?>, '<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>', <?= $row['cnt'] ?>)">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($byCategory)): ?>
                        <tr><td colspan="3" class="text-muted text-center py-3">No categories yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent activity -->
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-clock-rotate-left me-2 text-secondary"></i>Recent Activity
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent)): ?>
                    <p class="text-muted text-center py-4 mb-0">No activity yet.</p>
                    <?php else: ?>
                    <div style="max-height: 520px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0">
                        <thead style="position: sticky; top: 0; z-index: 1;"><tr>
                            <th class="ps-3">Asset</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th class="pe-3">When</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($recent as $h): ?>
                        <tr>
                            <td class="ps-3">
                                <a href="/it_manager/asset.php?id=<?= $h['asset_id'] ?>" class="asset-tag text-decoration-none">
                                    <?= htmlspecialchars($h['asset_tag']) ?>
                                </a>
                                <div class="text-muted" style="font-size:.78rem"><?= htmlspecialchars(trim($h['make'] . ' ' . $h['model'])) ?></div>
                            </td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($h['action']) ?></span></td>
                            <td class="text-muted small">
                                <?= $h['employee_name'] ? htmlspecialchars($h['employee_name']) : '' ?>
                                <?= $h['location_name'] ? '<br>' . htmlspecialchars($h['location_name']) : '' ?>
                            </td>
                            <td class="pe-3 text-muted small"><?= date('m/d/y g:ia', strtotime($h['changed_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="newCategoryName" placeholder="e.g. Laptop">
                <div id="addCategoryError" class="text-danger mt-2" style="display:none;font-size:.85rem"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="saveCategoryBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Delete Category Modal -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <p id="deleteCategoryMsg" class="mb-0"></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="deleteCategoryConfirmBtn"><i class="fas fa-trash-can me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('saveCategoryBtn').addEventListener('click', () => {
    const name = document.getElementById('newCategoryName').value.trim();
    const errEl = document.getElementById('addCategoryError');
    if (!name) { errEl.textContent = 'Name is required.'; errEl.style.display = 'block'; return; }
    errEl.style.display = 'none';
    fetch('/it_manager/ajax/save_category.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else { errEl.textContent = d.error; errEl.style.display = 'block'; }
    });
});

document.getElementById('addCategoryModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('newCategoryName').value = '';
    document.getElementById('addCategoryError').style.display = 'none';
});

let pendingCategoryId = null;

function confirmDeleteCategory(id, name, cnt) {
    const msg = document.getElementById('deleteCategoryMsg');
    const btn = document.getElementById('deleteCategoryConfirmBtn');
    if (cnt > 0) {
        msg.innerHTML = `<span class="text-danger"><i class="fas fa-circle-xmark me-1"></i>Cannot delete <strong>${name}</strong> — ${cnt} asset${cnt !== 1 ? 's are' : ' is'} assigned to it.</span>`;
        btn.style.display = 'none';
    } else {
        msg.textContent = `Delete category "${name}"? This cannot be undone.`;
        btn.style.display = '';
        pendingCategoryId = id;
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteCategoryModal')).show();
}

document.getElementById('deleteCategoryConfirmBtn').addEventListener('click', () => {
    if (!pendingCategoryId) return;
    fetch('/it_manager/ajax/delete_category.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ category_id: pendingCategoryId })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('deleteCategoryModal')).hide();
            location.reload();
        } else {
            document.getElementById('deleteCategoryMsg').innerHTML =
                `<span class="text-danger"><i class="fas fa-circle-xmark me-1"></i>${d.error}</span>`;
        }
    });
});

document.getElementById('deleteCategoryModal').addEventListener('hidden.bs.modal', () => {
    pendingCategoryId = null;
    document.getElementById('deleteCategoryConfirmBtn').style.display = '';
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
