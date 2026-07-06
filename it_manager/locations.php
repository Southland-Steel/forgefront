<?php
require_once __DIR__ . '/../includes/db.php';
$activePage = 'locations';
$pdo   = getPDO();
$campuses = $pdo->query("SELECT campus_id, name FROM campuses ORDER BY name")->fetchAll();
include __DIR__ . '/../includes/header.php';
?>
<style>
.campus-loc-table td { padding-top: .65rem; padding-bottom: .65rem; }
</style>
<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title"><i class="fas fa-location-dot me-2 it-header-icon"></i>Locations</h4>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addCampusModal">
                <i class="fas fa-plus me-1"></i>Add Campus
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                <i class="fas fa-plus me-1"></i>Add Location
            </button>
        </div>
    </div>

    <div id="locationsContainer" class="row g-3">
        <div class="col-12 text-center py-4 text-muted">
            <i class="fas fa-spinner fa-spin me-2"></i>Loading…
        </div>
    </div>
</div>

<!-- Confirm Delete Modal -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="fas fa-triangle-exclamation me-2"></i>Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <p id="confirmDeleteMsg" class="mb-0"></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger btn-sm" id="confirmDeleteBtn"><i class="fas fa-trash-can me-1"></i>Delete</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Campus Modal -->
<div class="modal fade" id="addCampusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Campus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="newCampusName" placeholder="e.g. Amite">
                <div id="addCampusError" class="text-danger mt-2" style="display:none;font-size:.85rem"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="saveCampusBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Campus <span class="text-danger">*</span></label>
                    <select class="form-select" id="newLocCampus">
                        <option value="">Select campus…</option>
                        <?php foreach ($campuses as $c): ?>
                        <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Location Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newLocName" placeholder="e.g. Server Room, Front Office">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="saveLocationBtn"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Location Assets Modal -->
<div class="modal fade" id="locationAssetsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-location-dot me-2"></i><span id="locationAssetsTitle">Assets</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="locationAssetsLoading" class="text-muted text-center py-4">Loading…</div>
                <p id="locationAssetsEmpty" class="text-muted text-center py-4 mb-0" style="display:none">No assets at this location.</p>
                <div class="table-responsive" style="max-height:60vh">
                <table class="table table-sm table-hover mb-0" id="locationAssetsTable" style="display:none">
                    <thead style="position: sticky; top: 0; z-index: 1;"><tr>
                        <th class="ps-3">Asset</th>
                        <th>Category</th>
                        <th>Make / Model</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th class="pe-3"></th>
                    </tr></thead>
                    <tbody id="locationAssetsBody"></tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const locationStatusBadge = {
    'Active':    '<span class="badge badge-active">Active</span>',
    'Inactive':  '<span class="badge badge-retired">Inactive</span>',
    'In Repair': '<span class="badge badge-repair">In Repair</span>',
    'Retired':   '<span class="badge badge-retired">Retired</span>',
    'Lost':      '<span class="badge badge-lost">Lost</span>',
};

function showLocationAssets(locationId, locationName) {
    document.getElementById('locationAssetsTitle').textContent = locationName;
    const loading = document.getElementById('locationAssetsLoading');
    const empty   = document.getElementById('locationAssetsEmpty');
    const table   = document.getElementById('locationAssetsTable');
    loading.style.display = 'block';
    empty.style.display = 'none';
    table.style.display = 'none';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('locationAssetsModal')).show();

    fetch('/it_manager/ajax/get_assets.php?location=' + locationId)
        .then(r => r.json())
        .then(assets => {
            loading.style.display = 'none';
            if (!assets.length) { empty.style.display = 'block'; return; }
            table.style.display = 'table';
            document.getElementById('locationAssetsBody').innerHTML = assets.map(a => `
                <tr>
                    <td class="ps-3"><a href="/it_manager/asset.php?id=${a.asset_id}" class="asset-tag text-decoration-none">${a.asset_tag}</a></td>
                    <td class="text-muted small">${a.category_name ?? ''}</td>
                    <td>${[a.make, a.model].filter(Boolean).join(' ') || '<span class="text-muted">—</span>'}</td>
                    <td>${locationStatusBadge[a.status] ?? a.status}</td>
                    <td class="small">${a.employee_name ?? '<span class="text-muted">—</span>'}</td>
                    <td class="pe-3 text-end"><a href="/it_manager/asset.php?id=${a.asset_id}" class="btn btn-sm btn-outline-primary btn-action"><i class="fas fa-eye"></i></a></td>
                </tr>
            `).join('');
        });
}

document.getElementById('saveCampusBtn').addEventListener('click', () => {
    const name = document.getElementById('newCampusName').value.trim();
    const errEl = document.getElementById('addCampusError');
    if (!name) { errEl.textContent = 'Name is required.'; errEl.style.display = 'block'; return; }
    errEl.style.display = 'none';
    fetch('/it_manager/ajax/save_campus.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else { errEl.textContent = d.error; errEl.style.display = 'block'; }
    });
});

document.getElementById('addCampusModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('newCampusName').value = '';
    document.getElementById('addCampusError').style.display = 'none';
});

function loadLocations() {
    fetch('/it_manager/ajax/get_locations.php?grouped=1')
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('locationsContainer');
            if (!Object.keys(data).length) {
                container.innerHTML = '<div class="col-12 text-center text-muted py-4">No locations yet.</div>';
                return;
            }
            container.innerHTML = Object.entries(data).map(([campus, locs]) => `
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="fw-semibold"><i class="fas fa-map-pin me-2 text-secondary"></i>${campus}</span>
                            <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="deleteCampusLocations('${campus}')" title="Delete all locations in ${campus}">
                                <i class="fas fa-trash-can me-1"></i>Delete All
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div${locs.length > 4 ? ' style="max-height:185px;overflow-y:auto"' : ''}>
                            <table class="table table-sm table-hover mb-0 campus-loc-table">
                                <tbody>
                                ${locs.map(l => `
                                    <tr style="cursor:pointer" onclick="showLocationAssets(${l.location_id}, '${l.name.replace(/'/g, "\\'")}')">
                                        <td class="ps-3">${l.name}</td>
                                        <td class="text-muted small">${l.asset_count} asset${l.asset_count != 1 ? 's' : ''}</td>
                                        <td class="pe-2 text-end">
                                            <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="event.stopPropagation(); deleteLocation(${l.location_id}, '${l.name.replace(/'/g, "\\'")}')">
                                                <i class="fas fa-xmark"></i>
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
        });
}

document.getElementById('saveLocationBtn').addEventListener('click', () => {
    const campus = document.getElementById('newLocCampus').value;
    const name = document.getElementById('newLocName').value.trim();
    if (!campus || !name) { alert('Campus and name are required.'); return; }
    fetch('/it_manager/ajax/save_location.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ campus_id: campus, name })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('addLocationModal')).hide();
            document.getElementById('newLocCampus').value = '';
            document.getElementById('newLocName').value = '';
            loadLocations();
        } else alert('Error: ' + d.error);
    });
});

let pendingDelete = null;
const confirmBtn = document.getElementById('confirmDeleteBtn');
const confirmMsg = document.getElementById('confirmDeleteMsg');

confirmBtn.addEventListener('click', () => {
    if (!pendingDelete) return;
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Deleting…';
    fetch('/it_manager/ajax/delete_location.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(pendingDelete)
    }).then(r => r.json()).then(d => {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fas fa-trash-can me-1"></i>Delete';
        if (d.success) {
            bootstrap.Modal.getInstance(document.getElementById('confirmDeleteModal')).hide();
            pendingDelete = null;
            loadLocations();
        } else {
            confirmMsg.innerHTML = `<span class="text-danger"><i class="fas fa-circle-xmark me-1"></i>${d.error}</span>`;
        }
    });
});

document.getElementById('confirmDeleteModal').addEventListener('hidden.bs.modal', () => {
    pendingDelete = null;
    confirmMsg.textContent = '';
});

function deleteLocation(locationId, name) {
    pendingDelete = { location_id: locationId };
    confirmMsg.textContent = `Delete location "${name}"? This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmDeleteModal')).show();
}

function deleteCampusLocations(campusName) {
    pendingDelete = { campus_name: campusName };
    confirmMsg.textContent = `Delete ALL locations in ${campusName}? This cannot be undone.`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmDeleteModal')).show();
}

loadLocations();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
