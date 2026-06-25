<?php
if (!ob_get_level()) ob_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
Auth::requireLogin();

$scriptPath    = $_SERVER['SCRIPT_NAME'] ?? '';
$inItInventory = strpos($scriptPath, '/it_manager/') !== false;
$currentPage   = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="format-detection" content="telephone=no">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $inItInventory ? 'IT Inventory — ' : '' ?>Forgefront</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-size: 16px;
            line-height: 1.6;
            background-color: #f8f9fa;
        }

        body.it-page {
            background: linear-gradient(to right, #e8eaef 259px, #dee2e6 259px, #dee2e6 260px, #f8f9fa 260px);
        }

        .navbar {
            display: flex;
            background: linear-gradient(135deg, #3a5fd9 0%, #06123a 100%);
            align-items: center;
            gap: 1rem;
            margin: 0;
            padding: 1.25rem 0;
            list-style: none;
            min-height: 70px;
        }

        .navbar-nav li {
            display: flex;
            align-items: center;
        }

        .navbar-brand {
            font-size: 1.5rem;
            font-weight: bold;
            font-family: 'Exo 2', sans-serif;
            color: #fff !important;
            padding: 0.3125rem 0 0.3125rem 10rem;
            margin-right: 1rem;
        }

        .navbar-brand:hover {
            text-decoration: underline;
            text-underline-offset: 4px;
        }

        .navbar-nav .nav-link, .navbar-nav span {
            font-size: 1.1rem;
            padding: 0.5rem 1rem;
            color: #8fa8c8 !important;
        }

        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
            color: #fff !important;
        }

        .navbar-nav .nav-link.active {
            font-weight: 700;
        }

        .navbar-nav .nav-link:hover {
            text-decoration: underline;
            text-underline-offset: 4px;
        }

        .main-content {
            min-height: calc(100vh - 200px);
            padding: 2rem 0;
        }

        .btn:not(.btn-sm) {
            min-height: 44px;
            padding: 0.75rem 1.5rem;
        }

        .btn-lg {
            min-height: 56px;
            padding: 1rem 2rem;
        }

        .navbar-user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: rgba(255,255,255,0.9);
            font-size: 0.95rem;
        }

        .navbar-user-info i {
            font-size: 1.2rem;
        }

        /* Sidebar layout for IT Inventory */
        .it-layout {
            display: flex;
            min-height: calc(100vh - 70px);
        }

        .it-sidebar {
            width: 260px;
            min-width: 260px;
            background: linear-gradient(180deg, #f0f2f5 0%, #e8eaef 100%);
            border-right: none;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            align-self: flex-start;
            flex-shrink: 0;
        }

        .sidebar-section-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6c757d;
            padding: 0.85rem 1.5rem 0.35rem;
            text-align: center;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            color: #333;
            text-decoration: none;
            font-weight: 500;
            font-size: 1rem;
            border-left: 6px solid transparent;
            transition: background-color 0.15s, border-color 0.15s, color 0.15s;
        }

        .sidebar-link:hover {
            background-color: #e2e6ea;
            color: #1a2e9e;
            text-decoration: none;
        }

        .sidebar-link.active {
            background-color: #8AB4F8;
            color: #fff;
            border-left-color: #2D6BF5;
            font-weight: 600;
        }

        .sidebar-link.active i {
            color: #fff;
        }

        .it-main-content {
            flex: 1;
            min-width: 0;
        }

        /* Shared IT Manager page styles */
        .it-main-content .card {
            border: 1px solid #e9ecef !important;
            box-shadow: 0 1px 4px rgba(0,0,0,.06) !important;
            border-radius: 10px !important;
        }
        .it-main-content .card-header {
            background: #f8f9fa !important;
            border-bottom: 1px solid #e9ecef !important;
            padding: 0.8rem 1.25rem !important;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 10px 10px 0 0 !important;
        }
        .it-main-content .table thead th {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #6c757d;
            font-weight: 700;
            background: #f8f9fa;
            border-bottom: 2px solid #e9ecef !important;
            white-space: nowrap;
            padding: 0.65rem 0.75rem;
        }
        .it-main-content .table tbody td {
            vertical-align: middle;
            font-size: 0.9rem;
            border-bottom: 1px solid #f3f4f6;
        }
        .it-main-content .table tbody tr:last-child td { border-bottom: none; }
        .it-main-content .table tbody tr:hover td { background: #fafafa; }
        .detail-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #9ca3af;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .detail-value { font-size: 0.95rem; font-weight: 500; color: #1f2937; }
        .page-title { font-size: 1.6rem; font-weight: 700; color: #1f2937; margin: 0; }
        .it-header-icon {
            background: linear-gradient(to bottom, #00BCD4 0%, #2D6BF5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .page-header h5 { font-size: 1.6rem; font-weight: 700; }

        /* IT Inventory helpers */
        .asset-tag { font-family: monospace; font-weight: 700; color: #002f77; }
        .badge-active  { background: #d1f0e0; color: #0a6640; }
        .badge-repair  { background: #fff3cd; color: #856404; }
        .badge-retired { background: #e2e3e5; color: #41464b; }
        .badge-lost    { background: #f8d7da; color: #842029; }
        .table th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; font-weight: 600; }
        .module-card { cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent; min-height: 260px; max-width: 460px; margin: 0 auto; }
        .module-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(45,107,245,.25); border-color: #2D6BF5; }
        .module-card .card-body { padding: 2.5rem; }
        .module-card .card-title { font-size: 1.6rem; font-weight: 700; color: #333; }
        .module-card .card-text { font-size: 1.1rem; color: #666; }
    </style>
</head>
<body<?= $inItInventory ? ' class="it-page"' : '' ?>>

    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="/index.php">
                <img src="/assets/img/forgefront_icon.png" alt="Forgefront" height="55" style="filter: brightness(0) invert(1); margin-right: 0.6rem;">
                ForgeFront
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= $scriptPath === '/index.php' ? 'active' : '' ?>" href="/index.php">
                            <i class="fas fa-home me-1"></i>Home
                        </a>
                    </li>
                </ul>

                <ul class="navbar-nav ms-auto" style="padding-right: 10rem;">
                    <?php if (Auth::hasPermission('users.manage')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>" href="/admin/users.php">
                            <i class="fas fa-users-cog me-1"></i>Admin
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item d-none d-lg-block">
                        <span class="navbar-user-info">
                            <i class="fas fa-user-circle"></i>
                            <?= htmlspecialchars(Auth::getFullName()) ?>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/auth/logout.php">
                            <i class="fas fa-sign-out-alt me-1"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

<?php if ($inItInventory): ?>
<div class="it-layout">
    <aside class="it-sidebar">
        <div class="py-3">
            <div class="sidebar-section-label">IT Manager</div>
            <?php
            $sidebarItems = [
                'index'     => ['label' => 'Dashboard',    'icon' => 'fas fa-gauge-high',   'file' => 'index'],
                'inventory' => ['label' => 'IT Inventory', 'icon' => 'fas fa-box',           'file' => 'inventory'],
                'employees' => ['label' => 'Employees',    'icon' => 'fas fa-users',         'file' => 'employees'],
                'locations' => ['label' => 'Locations',    'icon' => 'fas fa-location-dot',  'file' => 'locations'],
                'import'    => ['label' => 'CSV Import',   'icon' => 'fas fa-file-import',   'file' => 'import'],
            ];
            foreach ($sidebarItems as $key => $item): ?>
            <a href="/it_manager/<?= $item['file'] ?>.php"
               class="sidebar-link <?= ($activePage ?? '') === $key ? 'active' : '' ?>">
                <i class="<?= $item['icon'] ?> me-2"></i><?= $item['label'] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </aside>
    <main class="it-main-content">
<?php else: ?>
    <main class="main-content" style="padding: 2px">
<?php endif; ?>
