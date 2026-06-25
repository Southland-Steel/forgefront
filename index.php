<?php require_once __DIR__ . '/includes/header.php'; ?>

<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;600;700&display=swap" rel="stylesheet">

<div class="container-fluid py-4 px-4">
    <div class="row justify-content-center mb-4">
        <div class="col-12 text-center">
            <div class="d-flex justify-content-center align-items-center gap-3">
                <span style="display:inline-block; width:128px; height:128px; flex-shrink:0; background: linear-gradient(to bottom, #5578e8 0%, #1a3d9e 100%); -webkit-mask: url('/assets/img/forgefront_icon.png') no-repeat center/contain; mask: url('/assets/img/forgefront_icon.png') no-repeat center/contain;"></span>
                <div class="text-start">
                    <h1 class="fw-bold text-dark mb-0" style="font-family: 'Exo 2', sans-serif;">ForgeFront</h1>
                    <p class="text-muted mb-0" style="font-family: 'Exo 2', sans-serif;">IT &amp; Systems Integration Portal</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-12 col-md-6 col-lg-4 mb-4">
            <div class="card h-100 module-card" onclick="window.location.href='/it_manager/index.php'">
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <i class="fas fa-laptop fa-4x mb-4" style="background: linear-gradient(to bottom, #00BCD4 0%, #2D6BF5 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"></i>
                    <h2 class="card-title mb-2">IT Management</h2>
                    <p class="card-text">Track and manage IT assets across all sites</p>
                    <div class="mt-3">
                        <span class="badge bg-success px-3 py-2">Available</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-4 mb-4">
            <div class="card h-100 module-card" onclick="window.location.href='/server_board/index.php'">
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <i class="fas fa-server fa-4x mb-4" style="background: linear-gradient(to bottom, #4ade80 0%, #16a34a 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"></i>
                    <h2 class="card-title mb-2">Server Board</h2>
                    <p class="card-text">Monitor server and network device availability</p>
                    <div class="mt-3">
                        <span class="badge bg-success px-3 py-2">Available</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row justify-content-center mt-4">
        <div class="col-12 text-center">
            <small class="text-muted">Additional modules will be added as they become available</small>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
