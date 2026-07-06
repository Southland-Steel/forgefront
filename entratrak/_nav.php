<?php
// Shared sub-nav for the entratrak module. Included by every top-level
// page (not signins.php — that's a per-user drill-down reached via links,
// not a top-level destination).
$entratrakPage = basename($_SERVER['PHP_SELF'], '.php');
$entratrakNavItems = [
    'index'     => ['label' => 'Dashboard',       'icon' => 'fas fa-gauge-high'],
    'entra'     => ['label' => 'Users & Licenses','icon' => 'fas fa-users'],
    'skus'      => ['label' => 'SKUs',             'icon' => 'fas fa-tags'],
    'usage'     => ['label' => 'Utilization',      'icon' => 'fas fa-chart-line'],
    'attacks'   => ['label' => 'Attacks',          'icon' => 'fas fa-triangle-exclamation'],
    'legacy'    => ['label' => 'Legacy Auth',      'icon' => 'fas fa-clock-rotate-left'],
    'known-ips' => ['label' => 'Known IPs',        'icon' => 'fas fa-map-location-dot'],
];
?>
<style>
    .entratrak-subnav { background: #fff; border-bottom: 1px solid #e9ecef; }
    .entratrak-subnav .nav-link {
        color: #6c757d; font-weight: 600; font-size: 0.9rem; padding: 1rem 1.1rem;
        border-bottom: 3px solid transparent; border-radius: 0;
    }
    .entratrak-subnav .nav-link:hover { color: #be185d; }
    .entratrak-subnav .nav-link.active {
        color: #be185d; border-bottom-color: #be185d; background: none;
    }
</style>
<div class="entratrak-subnav">
    <div class="container-fluid px-4">
        <ul class="nav">
            <?php foreach ($entratrakNavItems as $file => $item): ?>
            <li class="nav-item">
                <a class="nav-link <?= $entratrakPage === $file ? 'active' : '' ?>" href="/entratrak/<?= $file ?>.php">
                    <i class="<?= $item['icon'] ?> me-1"></i><?= $item['label'] ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
