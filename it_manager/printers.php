<?php
$activePage = 'printers';
include __DIR__ . '/../includes/header.php';

// Static list — no login/password values are stored here on purpose.
$printers = [
    ['ip' => '192.168.80.21', 'location' => 'GR-MO', 'sublocation' => null,           'model' => 'IPR C265'],
    ['ip' => '192.168.80.22', 'location' => 'GR-SO', 'sublocation' => null,           'model' => 'IR-ADV C3930'],
    ['ip' => '192.168.0.159', 'location' => 'AM-MO', 'sublocation' => '1st Floor',    'model' => 'IR-ADV C5550 III'],
    ['ip' => '192.168.0.20',  'location' => 'AM-MO', 'sublocation' => '2nd Floor',    'model' => 'IR-ADV C355'],
    ['ip' => '192.168.4.200', 'location' => 'AM-AO', 'sublocation' => null,           'model' => 'IR-ADV C3935'],
    ['ip' => '192.168.0.160', 'location' => 'AM-DH', 'sublocation' => 'Duncan House', 'model' => null],
];
?>
<style>
.printer-tile {
    text-decoration: none;
    color: inherit;
    aspect-ratio: 16 / 9;
    display: flex;
    border: 1px solid #e9ecef;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    transition: box-shadow 0.12s ease, transform 0.12s ease;
}
.printer-tile:hover {
    box-shadow: 0 6px 16px rgba(0,0,0,.12);
    transform: translateY(-2px);
    color: inherit;
    text-decoration: none;
}
.printer-tile .card-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    text-align: center;
}
.printer-icon { font-size: 3rem; color: #2D6BF5; }
.printer-location { font-size: 1.2rem; font-weight: 800; color: #1f2937; }
.printer-sublocation { font-size: 0.85rem; color: #6c757d; }
.printer-model { font-size: 0.95rem; color: #333; }
.printer-ip { font-family: monospace; font-size: 0.78rem; color: #9ca3af; }
</style>

<div class="container-fluid px-4 pt-3">
    <div class="page-header d-flex justify-content-between align-items-center mb-3">
        <h4 class="page-title"><i class="fas fa-print me-2 it-header-icon"></i>Printers</h4>
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        <?php foreach ($printers as $p): ?>
        <div class="col">
            <a class="card shadow-sm printer-tile" href="http://<?= htmlspecialchars($p['ip']) ?>/" target="_blank" rel="noopener">
                <div class="card-body">
                    <i class="fas fa-print printer-icon"></i>
                    <div class="printer-location"><?= htmlspecialchars($p['location']) ?></div>
                    <?php if ($p['sublocation']): ?>
                    <div class="printer-sublocation"><?= htmlspecialchars($p['sublocation']) ?></div>
                    <?php endif; ?>
                    <?php if ($p['model']): ?>
                    <div class="printer-model"><?= htmlspecialchars($p['model']) ?></div>
                    <?php endif; ?>
                    <div class="printer-ip"><?= htmlspecialchars($p['ip']) ?></div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
