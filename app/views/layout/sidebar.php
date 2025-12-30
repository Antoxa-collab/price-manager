<!-- Боковое меню -->
<aside class="sidebar bg-dark border-end border-secondary">
    <div class="sidebar-content">
        <nav class="nav flex-column">
            <a class="nav-link <?= isActive('/') ?>" href="/">
                <i class="bi bi-calculator"></i>
                <span>Калькулятор</span>
            </a>

            <a class="nav-link <?= isActive('/products') ?>" href="/products">
                <i class="bi bi-box-seam"></i>
                <span>Товары</span>
            </a>

            <a class="nav-link <?= isActive('/history') ?>" href="/history">
                <i class="bi bi-clock-history"></i>
                <span>История</span>
            </a>

            <hr class="my-2 border-secondary">

            <a class="nav-link <?= isActive('/settings') ?>" href="/settings">
                <i class="bi bi-gear"></i>
                <span>Настройки API</span>
            </a>

            <hr class="my-2 border-secondary">

            <a class="nav-link text-danger" href="/logout">
                <i class="bi bi-box-arrow-right"></i>
                <span>Выход</span>
            </a>
        </nav>

        <!-- Статус подключений -->
        <div class="sidebar-footer mt-auto p-3">
            <div class="small text-muted mb-2">Статус API:</div>

            <?php
            $wbConfigured = false;
            $ozonConfigured = false;

            if (isset($auth) && $auth->isLoggedIn()) {
                $userId = $auth->getUserId();
                $wbApi = new WildberriesAPI($userId);
                $ozonApi = new OzonAPI($userId);
                $wbConfigured = $wbApi->isConfigured();
                $ozonConfigured = $ozonApi->isConfigured();
            }
            ?>

            <div class="d-flex align-items-center mb-1">
                <span class="status-dot <?= $wbConfigured ? 'bg-success' : 'bg-danger' ?> me-2"></span>
                <span class="small">Wildberries</span>
            </div>

            <div class="d-flex align-items-center">
                <span class="status-dot <?= $ozonConfigured ? 'bg-success' : 'bg-danger' ?> me-2"></span>
                <span class="small">Ozon</span>
            </div>
        </div>
    </div>
</aside>
