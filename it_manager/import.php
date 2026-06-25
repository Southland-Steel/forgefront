<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
Auth::requirePermission('users.manage');
$pdo = getPDO();

$domainSiteMap = [
    'southlandsteel.com' => 'SSF',
    'gridstructures.com' => 'GRID',
    'solarpileusa.com'   => 'SPUSA',
    'southlandic.com'    => 'GALV',
];
$skipDomains   = ['southlandsteelphey.onmicrosoft.com'];
$categoriesAll = $pdo->query("SELECT category_id, name FROM asset_categories ORDER BY name")->fetchAll();
$categoryNames = array_column($categoriesAll, 'name', 'category_id');

$sitesRows    = $pdo->query("SELECT site_id, abbreviation FROM sites")->fetchAll();
$siteIdByAbbr = array_column($sitesRows, 'site_id', 'abbreviation');

$empByEmail = [];
foreach ($pdo->query("SELECT employee_id, name, email FROM employees WHERE email IS NOT NULL")->fetchAll() as $e) {
    $empByEmail[strtolower($e['email'])] = $e;
}

$existingTags = [];
foreach ($pdo->query("SELECT asset_id, asset_tag FROM assets")->fetchAll() as $a) {
    $existingTags[strtoupper($a['asset_tag'])] = $a['asset_id'];
}

function inferCategory(string $name): int {
    $n = strtoupper($name);
    // Servers — word match, known names, or common suffix abbreviations
    if (strpos($n, 'SERVER')         !== false) return 9;
    if (strpos($n, 'DATA-WAREHOUSE') !== false) return 9;
    if (strpos($n, 'DATA WAREHOUSE') !== false) return 9;
    $serverSuffixes = ['-SRV', '-HV', '-WEBSRV', '-DC', '-FS', '-SQL', '-NAS', '-MAIL', '-PROXY'];
    foreach ($serverSuffixes as $sfx) {
        if (strpos($n, $sfx) !== false) return 9;
    }
    if (strpos($n, 'IPAD')    !== false) return 3;
    if (strpos($n, 'LAPTOP')  !== false) return 1;
    if (strpos($n, 'SURFACE') !== false) return 1;
    if (strpos($n, 'BOOK')    !== false) return 1;
    if (strpos($n, 'TABLET')  !== false) return 3;
    if (strpos($n, 'DESKTOP') !== false) return 2;
    if (strpos($n, 'SKIPPY')  !== false) return 2;
    return 16;
}

function cleanUPN(string $raw): ?string {
    $raw = trim($raw);
    if (!$raw) return null;
    if (filter_var($raw, FILTER_VALIDATE_EMAIL)) return strtolower($raw);
    if (preg_match('/[a-z][a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $raw, $m)) return strtolower($m[1]);
    return null;
}

$view        = 'upload';
$type        = $_REQUEST['type'] ?? 'employees';
$error       = null;
$previewRows = [];
$importResult = null;
$counts      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? 'preview');
    $type   = trim($_POST['type']   ?? 'employees');

    if ($action === 'preview') {
        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a valid CSV file.';
        } else {
            $fh = fopen($_FILES['csv']['tmp_name'], 'r');
            $bom = fread($fh, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($fh);
            fgetcsv($fh); // skip header row

            if ($type === 'employees') {
                while (($row = fgetcsv($fh)) !== false) {
                    if (count($row) < 8) continue;
                    $displayName = trim($row[0]);
                    $upn         = strtolower(trim($row[2]));
                    $firstName   = trim($row[4] ?? '');
                    $lastName    = trim($row[5] ?? '');
                    $softDeleted = trim($row[7] ?? '');
                    $blockCred   = strtolower(trim($row[18] ?? ''));

                    $status     = 'new';
                    $skipReason = null;

                    if ($softDeleted) {
                        $status = 'skip'; $skipReason = 'Soft deleted';
                    } elseif (stripos($displayName, 'terminated') !== false) {
                        $status = 'skip'; $skipReason = 'Terminated employee';
                    } elseif ($blockCred === 'true') {
                        $status = 'skip'; $skipReason = 'Account disabled';
                    } elseif (!$firstName && !$lastName) {
                        $status = 'skip'; $skipReason = 'No personal name (service account)';
                    } elseif (!filter_var($upn, FILTER_VALIDATE_EMAIL)) {
                        $status = 'skip'; $skipReason = 'Invalid email format';
                    } else {
                        $domain = substr(strrchr($upn, '@'), 1);
                        if (in_array($domain, $skipDomains, true)) {
                            $status = 'skip'; $skipReason = 'Microsoft tenant domain';
                        } elseif (isset($empByEmail[$upn])) {
                            $status = 'update';
                        }
                    }

                    $domain   = $upn ? substr(strrchr($upn, '@'), 1) : '';
                    $siteAbbr = $domainSiteMap[$domain] ?? null;
                    $siteId   = $siteAbbr ? ($siteIdByAbbr[$siteAbbr] ?? null) : null;

                    $previewRows[] = [
                        'status'      => $status,
                        'skip_reason' => $skipReason,
                        'name'        => $displayName,
                        'email'       => $upn,
                        'site_abbr'   => $siteAbbr,
                        'site_id'     => $siteId,
                        'employee_id' => $empByEmail[$upn]['employee_id'] ?? null,
                    ];
                }
            } elseif ($type === 'devices') {
                while (($row = fgetcsv($fh)) !== false) {
                    if (count($row) < 8) continue;
                    $deviceId   = trim($row[0]);
                    $deviceName = trim($row[1]);
                    $ownership  = trim($row[3]);
                    $os         = trim($row[5]);
                    $osVer      = trim($row[6]);
                    $rawUPN     = trim($row[7]);
                    $lastIn     = trim($row[8] ?? '');

                    $upn      = cleanUPN($rawUPN);
                    $tagUpper = strtoupper($deviceName);
                    $catId    = inferCategory($deviceName);

                    $status     = 'new';
                    $skipReason = null;
                    $warning    = null;

                    if (!$deviceName) {
                        $status = 'skip'; $skipReason = 'No device name';
                    } elseif (isset($existingTags[$tagUpper])) {
                        $status = 'update';
                    }

                    if ($status !== 'skip') {
                        if (!$upn) {
                            $warning = 'Cannot parse UPN: ' . $rawUPN;
                        } elseif (!isset($empByEmail[$upn])) {
                            $warning = 'Employee not found: ' . $upn;
                        }
                    }

                    $empId   = ($upn && isset($empByEmail[$upn])) ? $empByEmail[$upn]['employee_id'] : null;
                    $checkin = $lastIn ? substr($lastIn, 0, 10) : '';
                    $notes   = implode(' | ', array_filter([
                        "Intune: $deviceId",
                        "OS: $os $osVer",
                        "Ownership: $ownership",
                        $checkin ? "Last check-in: $checkin" : '',
                    ]));

                    $previewRows[] = [
                        'status'        => $status,
                        'skip_reason'   => $skipReason,
                        'warning'       => $warning,
                        'asset_tag'     => $deviceName,
                        'category_id'   => $catId,
                        'category_name' => $categoryNames[$catId] ?? 'Unknown',
                        'upn'           => $upn ?? $rawUPN,
                        'employee_id'   => $empId,
                        'employee_name' => $empId ? $empByEmail[$upn]['name'] : null,
                        'notes'         => $notes,
                        'asset_id'      => $existingTags[$tagUpper] ?? null,
                    ];
                }
            }
            fclose($fh);

            $_SESSION['ff_import'] = ['type' => $type, 'rows' => $previewRows];
            $view = 'preview';
        }

    } elseif ($action === 'import') {
        $data = $_SESSION['ff_import'] ?? null;
        if (!$data || $data['type'] !== $type) {
            $error = 'Session expired — please re-upload the file.';
        } else {
            $imported = $updated = $skipped = 0;

            if ($type === 'employees') {
                foreach ($data['rows'] as $row) {
                    if ($row['status'] === 'skip') { $skipped++; continue; }
                    if ($row['status'] === 'update' && $row['employee_id']) {
                        $pdo->prepare("UPDATE employees SET name=? WHERE employee_id=?")
                            ->execute([$row['name'], $row['employee_id']]);
                        if ($row['site_id']) {
                            $chk = $pdo->prepare("SELECT 1 FROM employee_sites WHERE employee_id=? AND site_id=?");
                            $chk->execute([$row['employee_id'], $row['site_id']]);
                            if (!$chk->fetchColumn()) {
                                $pdo->prepare("INSERT INTO employee_sites (employee_id, site_id, is_primary) VALUES (?,?,0)")
                                    ->execute([$row['employee_id'], $row['site_id']]);
                            }
                        }
                        $updated++;
                    } else {
                        $pdo->prepare("INSERT INTO employees (name, email) VALUES (?,?)")
                            ->execute([$row['name'], $row['email']]);
                        $empId = (int)$pdo->lastInsertId();
                        if ($row['site_id']) {
                            $pdo->prepare("INSERT INTO employee_sites (employee_id, site_id, is_primary) VALUES (?,?,1)")
                                ->execute([$empId, $row['site_id']]);
                        }
                        $imported++;
                    }
                }
            } elseif ($type === 'devices') {
                foreach ($data['rows'] as $i => $row) {
                    if ($row['status'] === 'skip') { $skipped++; continue; }
                    $catId = (int)($_POST['cat_override'][$i] ?? $row['category_id']);
                    if ($row['status'] === 'update' && $row['asset_id']) {
                        $pdo->prepare("UPDATE assets SET category_id=?, assigned_employee_id=?, notes=?, updated_at=NOW() WHERE asset_id=?")
                            ->execute([$catId, $row['employee_id'], $row['notes'], $row['asset_id']]);
                        $updated++;
                    } else {
                        $pdo->prepare("INSERT INTO assets (asset_tag, category_id, status, assigned_employee_id, notes) VALUES (?,?,'Active',?,?)")
                            ->execute([$row['asset_tag'], $catId, $row['employee_id'], $row['notes']]);
                        $assetId = (int)$pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO asset_history (asset_id, action, changed_by, notes) VALUES (?,?,?,?)")
                            ->execute([$assetId, 'Created', 'CSV Import', 'Imported from Intune CSV']);
                        if ($row['employee_id']) {
                            $pdo->prepare("INSERT INTO asset_history (asset_id, action, employee_id, changed_by) VALUES (?,?,?,?)")
                                ->execute([$assetId, 'Assigned', $row['employee_id'], 'CSV Import']);
                        }
                        $imported++;
                    }
                }
            }

            unset($_SESSION['ff_import']);
            $importResult = compact('imported', 'updated', 'skipped');
            $view = 'result';
        }
    }
}

if ($view === 'preview' && !empty($previewRows)) {
    $counts = ['new' => 0, 'update' => 0, 'skip' => 0, 'warn' => 0];
    foreach ($previewRows as $r) {
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
        if (!empty($r['warning'])) $counts['warn']++;
    }
}

$activePage = 'import';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-4 pt-3">

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($view === 'upload'): ?>

    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title"><i class="fas fa-file-import me-2 it-header-icon"></i>CSV Import</h4>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-users me-2"></i>Import Employees</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Upload the Microsoft 365 users CSV export. Disabled accounts and shared mailboxes are skipped. Email domain determines company assignment.</p>
                    <div class="small text-muted mb-3">
                        <div class="fw-semibold mb-1">Domain → Company:</div>
                        <code>southlandsteel.com → SSF</code><br>
                        <code>gridstructures.com → GRID</code><br>
                        <code>solarpileusa.com &nbsp;→ SPUSA</code><br>
                        <code>southlandic.com &nbsp; → GALV</code>
                    </div>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="type" value="employees">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">CSV File</label>
                            <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-eye me-1"></i>Preview Import</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-laptop me-2"></i>Import Devices</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Upload the Intune DevicesWithInventory CSV export. Device name becomes the asset tag. Existing devices are updated; new ones are created as Active.</p>
                    <div class="small text-muted mb-3">
                        <div class="fw-semibold mb-1">Category inference:</div>
                        <code>*LAPTOP*, *SURFACE*, *BOOK* → Laptop</code><br>
                        <code>*DESKTOP* → Desktop</code><br>
                        <code>*TABLET* → Tablet</code><br>
                        <code>everything else → Miscellaneous</code>
                    </div>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="type" value="devices">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">CSV File</label>
                            <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-eye me-1"></i>Preview Import</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($view === 'preview'): ?>

    <div class="d-flex align-items-center gap-3 mb-3">
        <h4 class="page-title mb-0">
            <i class="fas fa-eye me-2 it-header-icon"></i>Preview — <?= $type === 'employees' ? 'Employees' : 'Devices' ?>
        </h4>
        <a href="import.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
        <span class="badge bg-success fs-6 px-3 py-2"><i class="fas fa-plus me-1"></i><?= $counts['new'] ?? 0 ?> New</span>
        <span class="badge bg-primary fs-6 px-3 py-2"><i class="fas fa-sync me-1"></i><?= $counts['update'] ?? 0 ?> Update</span>
        <span class="badge bg-secondary fs-6 px-3 py-2"><i class="fas fa-minus me-1"></i><?= $counts['skip'] ?? 0 ?> Skipped</span>
        <?php if (!empty($counts['warn'])): ?>
        <span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i><?= $counts['warn'] ?> Warning<?= $counts['warn'] > 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <div class="form-check ms-auto mb-0">
            <input class="form-check-input" type="checkbox" id="showSkipped" checked onchange="toggleSkipped(this.checked)">
            <label class="form-check-label small" for="showSkipped">Show skipped rows</label>
        </div>
    </div>

    <?php $actionCount = ($counts['new'] ?? 0) + ($counts['update'] ?? 0); ?>
    <form method="post">
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">

    <div class="card mb-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="previewTable">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width:90px">Status</th>
                            <?php if ($type === 'employees'): ?>
                            <th>Name</th>
                            <th>Email</th>
                            <th style="width:80px">Company</th>
                            <th>Note</th>
                            <?php else: ?>
                            <th>Asset Tag</th>
                            <th style="width:160px">Category</th>
                            <th>Assigned To</th>
                            <th>Note / Warning</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $rowIdx => $row):
                            $isSkip = $row['status'] === 'skip';
                            $hasWarn = !$isSkip && !empty($row['warning']);
                            $rowClass = $isSkip ? 'table-secondary skip-row' : ($hasWarn ? 'table-warning' : ($row['status'] === 'update' ? 'table-info' : ''));
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="ps-3">
                                <?php if ($row['status'] === 'new'): ?>
                                    <span class="badge bg-success">NEW</span>
                                <?php elseif ($row['status'] === 'update'): ?>
                                    <span class="badge bg-primary">UPDATE</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">SKIP</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($type === 'employees'): ?>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td class="small"><?= htmlspecialchars($row['email']) ?></td>
                            <td>
                                <?php if ($row['site_abbr']): ?>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['site_abbr']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($row['skip_reason'] ?? '') ?></td>
                            <?php else: ?>
                            <td class="asset-tag"><?= htmlspecialchars($row['asset_tag']) ?></td>
                            <td>
                                <?php if (!$isSkip): ?>
                                <select name="cat_override[<?= $rowIdx ?>]" class="form-select form-select-sm">
                                    <?php foreach ($categoriesAll as $cat): ?>
                                    <option value="<?= $cat['category_id'] ?>" <?= $cat['category_id'] == $row['category_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['category_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($row['employee_name']): ?>
                                    <?= htmlspecialchars($row['employee_name']) ?>
                                <?php elseif ($row['upn']): ?>
                                    <span class="text-muted"><?= htmlspecialchars($row['upn']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($row['skip_reason']): ?>
                                    <span class="text-muted"><?= htmlspecialchars($row['skip_reason']) ?></span>
                                <?php elseif (!empty($row['warning'])): ?>
                                    <i class="fas fa-exclamation-triangle text-warning me-1"></i><?= htmlspecialchars($row['warning']) ?>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($actionCount > 0): ?>
    <div class="d-flex gap-2">
        <a href="import.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-success">
            <i class="fas fa-check me-1"></i>Confirm — Import <?= $actionCount ?> Record<?= $actionCount !== 1 ? 's' : '' ?>
        </button>
    </div>
    <?php else: ?>
    <div class="alert alert-info">Nothing to import — all rows were skipped.</div>
    <a href="import.php" class="btn btn-outline-secondary">Back</a>
    <?php endif; ?>
    </form>

<?php elseif ($view === 'result'): ?>

    <div class="page-header mb-3">
        <h4 class="page-title"><i class="fas fa-check-circle me-2 it-header-icon"></i>Import Complete</h4>
    </div>

    <div class="card mb-4" style="max-width:380px">
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-4">
                    <div class="fs-1 fw-bold text-success"><?= $importResult['imported'] ?></div>
                    <div class="small text-muted">Created</div>
                </div>
                <div class="col-4">
                    <div class="fs-1 fw-bold text-primary"><?= $importResult['updated'] ?></div>
                    <div class="small text-muted">Updated</div>
                </div>
                <div class="col-4">
                    <div class="fs-1 fw-bold text-secondary"><?= $importResult['skipped'] ?></div>
                    <div class="small text-muted">Skipped</div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="import.php" class="btn btn-outline-secondary">Import Another File</a>
        <?php if ($type === 'employees'): ?>
            <a href="employees.php" class="btn btn-primary">View Employees</a>
        <?php else: ?>
            <a href="inventory.php" class="btn btn-primary">View Inventory</a>
        <?php endif; ?>
    </div>

<?php endif; ?>

</div>

<script>
function toggleSkipped(show) {
    document.querySelectorAll('.skip-row').forEach(r => r.style.display = show ? '' : 'none');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
