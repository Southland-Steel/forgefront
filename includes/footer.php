    </main>
<?php if (isset($inItInventory) && $inItInventory): ?>
</div><!-- /.it-layout -->
<?php endif; ?>
<style>
    .text-muted {
        color: #6c757d !important;
    }
</style>
<footer class="bg-dark text-light py-4 mt-5">
    <div class="container-fluid" style="padding-left: 10rem; padding-right: 10rem;">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5>Forgefront</h5>
                <small class="text-muted">Version 1.0 - <?php echo date('Y'); ?></small>
            </div>
            <?php if (isset($inItInventory) && $inItInventory):
                $printConfig        = require __DIR__ . '/../print_labels_config.php';
                $selectedPrinterKey = $_SESSION['ff_label_printer'] ?? array_key_first($printConfig['printers']);
            ?>
            <div class="col-md-6 d-flex justify-content-end align-items-center">
                <form action="/set_printer.php" method="post" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="redirect_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <label class="text-muted mb-0"><small>Label Printer:</small></label>
                    <select name="printer" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        <?php foreach ($printConfig['printers'] as $key => $printer): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $key === $selectedPrinterKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($printer['label']) ?> (<?= htmlspecialchars($printer['ip']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Prevent zoom on input focus
        const inputs = document.querySelectorAll('input[type="text"], input[type="email"], input[type="number"], textarea');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.style.fontSize = '16px';
            });
        });

        // Touch-friendly hover effects
        const cards = document.querySelectorAll('.card');
        cards.forEach(card => {
            card.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            });
            card.addEventListener('touchend', function() {
                this.style.transform = 'scale(1)';
            });
        });

        // Auto-hide navbar on scroll
        let lastScrollTop = 0;
        const navbar = document.querySelector('.navbar');

        window.addEventListener('scroll', function() {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            if (scrollTop > lastScrollTop && scrollTop > 100) {
                navbar.style.transform = 'translateY(-100%)';
            } else {
                navbar.style.transform = 'translateY(0)';
            }
            lastScrollTop = scrollTop;
        });
    });

    window.addEventListener('error', function(e) {
        console.error('Global error:', e.error);
    });

    window.addEventListener('online',  function() { showNetworkStatus('online');  });
    window.addEventListener('offline', function() { showNetworkStatus('offline'); });

    function showNetworkStatus(status) {
        const statusDiv = document.getElementById('network-status') || createNetworkStatusDiv();
        statusDiv.className = `alert alert-${status === 'online' ? 'success' : 'warning'} alert-dismissible fade show position-fixed`;
        statusDiv.style.cssText = 'top: 80px; right: 20px; z-index: 1050; min-width: 200px;';
        statusDiv.innerHTML = `
            <i class="fas fa-wifi me-2"></i>
            Network ${status === 'online' ? 'Connected' : 'Disconnected'}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        setTimeout(() => {
            const alert = bootstrap.Alert.getOrCreateInstance(statusDiv);
            alert.close();
        }, 3000);
    }

    function createNetworkStatusDiv() {
        const div = document.createElement('div');
        div.id = 'network-status';
        document.body.appendChild(div);
        return div;
    }
</script>
</body>
</html>
