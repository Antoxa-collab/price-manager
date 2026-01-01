<!-- Боковое меню -->
<aside class="sidebar bg-dark border-end border-secondary">
    <div class="sidebar-content">
        <nav class="nav flex-column">
            <!-- Раздел: Маркетплейсы -->
            <div class="sidebar-heading text-muted small px-3 py-2 text-uppercase">
                <i class="bi bi-shop me-1"></i>Маркетплейсы
            </div>

            <!-- Ozon - активный -->
            <div class="nav-item-group">
                <div class="nav-group-header px-3 py-2 d-flex align-items-center">
                    <i class="bi bi-circle-fill text-info me-2" style="font-size: 0.5rem;"></i>
                    <span class="fw-medium">Ozon</span>
                </div>
                <a class="nav-link nav-link-sub <?= isActive('/ozon') ?>" href="/ozon">
                    <i class="bi bi-calculator"></i>
                    <span>Калькулятор цен</span>
                </a>
                <a class="nav-link nav-link-sub <?= isActive('/ozon/mapping') ?>" href="/ozon/mapping">
                    <i class="bi bi-link-45deg"></i>
                    <span>Сопоставление товаров</span>
                </a>
            </div>

            <!-- Wildberries - скоро -->
            <div class="nav-item-group disabled-group">
                <div class="nav-group-header px-3 py-2 d-flex align-items-center text-muted">
                    <i class="bi bi-circle-fill me-2" style="font-size: 0.5rem;"></i>
                    <span class="fw-medium">Wildberries</span>
                    <span class="badge bg-secondary ms-auto small">скоро</span>
                </div>
                <span class="nav-link nav-link-sub disabled text-muted">
                    <i class="bi bi-calculator"></i>
                    <span>Калькулятор цен</span>
                </span>
            </div>

            <!-- Яндекс.Маркет - скоро -->
            <div class="nav-item-group disabled-group">
                <div class="nav-group-header px-3 py-2 d-flex align-items-center text-muted">
                    <i class="bi bi-circle-fill me-2" style="font-size: 0.5rem;"></i>
                    <span class="fw-medium">Яндекс.Маркет</span>
                    <span class="badge bg-secondary ms-auto small">скоро</span>
                </div>
                <span class="nav-link nav-link-sub disabled text-muted">
                    <i class="bi bi-calculator"></i>
                    <span>Калькулятор цен</span>
                </span>
            </div>

            <hr class="my-2 border-secondary">

            <!-- Раздел: Товары -->
            <div class="sidebar-heading text-muted small px-3 py-2 text-uppercase">
                <i class="bi bi-box-seam me-1"></i>Товары
            </div>

            <a class="nav-link <?= isActive('/products') ?>" href="/products">
                <i class="bi bi-list-ul"></i>
                <span>Управление товарами</span>
            </a>

            <hr class="my-2 border-secondary">

            <!-- Раздел: Система -->
            <div class="sidebar-heading text-muted small px-3 py-2 text-uppercase">
                <i class="bi bi-gear-wide-connected me-1"></i>Система
            </div>

            <a class="nav-link <?= isActive('/history') ?>" href="/history">
                <i class="bi bi-clock-history"></i>
                <span>История операций</span>
            </a>

            <a class="nav-link <?= isActive('/settings') ?>" href="/settings">
                <i class="bi bi-sliders"></i>
                <span>Настройки API</span>
            </a>

            <?php if (isset($auth) && $auth->isLoggedIn() && $auth->getUserRole() === 'admin'): ?>
            <a class="nav-link <?= isActive('/logs') ?>" href="/logs">
                <i class="bi bi-bug"></i>
                <span>Логи ошибок</span>
            </a>
            <?php endif; ?>

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
            // Проверяем статус подключения к API маркетплейсов
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
                <span class="status-dot <?= $ozonConfigured ? 'bg-success' : 'bg-danger' ?> me-2"></span>
                <span class="small">Ozon</span>
                <?php if ($ozonConfigured): ?>
                <i class="bi bi-check-circle-fill text-success ms-auto small"></i>
                <?php else: ?>
                <i class="bi bi-x-circle-fill text-danger ms-auto small"></i>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center mb-1">
                <span class="status-dot <?= $wbConfigured ? 'bg-success' : 'bg-secondary' ?> me-2"></span>
                <span class="small text-muted">Wildberries</span>
            </div>

            <div class="d-flex align-items-center">
                <span class="status-dot bg-secondary me-2"></span>
                <span class="small text-muted">Яндекс.Маркет</span>
            </div>
        </div>
    </div>
</aside>
