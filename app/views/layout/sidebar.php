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

            <!-- Wildberries - активный -->
            <div class="nav-item-group">
                <div class="nav-group-header px-3 py-2 d-flex align-items-center">
                    <i class="bi bi-circle-fill text-danger me-2" style="font-size: 0.5rem;"></i>
                    <span class="fw-medium">Wildberries</span>
                </div>
                <a class="nav-link nav-link-sub <?= isActive('/wildberries') ?>" href="/wildberries">
                    <i class="bi bi-calculator"></i>
                    <span>Калькулятор цен</span>
                </a>
                <a class="nav-link nav-link-sub <?= isActive('/wildberries/mapping') ?>" href="/wildberries/mapping">
                    <i class="bi bi-link-45deg"></i>
                    <span>Сопоставление товаров</span>
                </a>
            </div>

            <!-- Яндекс.Маркет - активный -->
            <div class="nav-item-group">
                <div class="nav-group-header px-3 py-2 d-flex align-items-center">
                    <i class="bi bi-circle-fill text-warning me-2" style="font-size: 0.5rem;"></i>
                    <span class="fw-medium">Яндекс.Маркет</span>
                </div>
                <a class="nav-link nav-link-sub <?= isActive('/yandex') ?>" href="/yandex">
                    <i class="bi bi-calculator"></i>
                    <span>Калькулятор цен</span>
                </a>
                <a class="nav-link nav-link-sub <?= isActive('/yandex/mapping') ?>" href="/yandex/mapping">
                    <i class="bi bi-link-45deg"></i>
                    <span>Сопоставление товаров</span>
                </a>
            </div>

            <hr class="my-2 border-secondary">

            <!-- Раздел: AI Помощник -->
            <div class="sidebar-heading text-muted small px-3 py-2 text-uppercase">
                <i class="bi bi-robot me-1"></i>AI Помощник
            </div>

            <a class="nav-link <?= isActive('/ai/reviews') || isActive('/ai') ?>" href="/ai/reviews">
                <i class="bi bi-chat-left-text"></i>
                <span>Отзывы</span>
            </a>

            <a class="nav-link <?= isActive('/ai/questions') ?>" href="/ai/questions">
                <i class="bi bi-question-circle"></i>
                <span>Вопросы</span>
            </a>

            <a class="nav-link <?= isActive('/ai/prompts') ?>" href="/ai/prompts">
                <i class="bi bi-file-text"></i>
                <span>Промпты</span>
            </a>

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
            <a class="nav-link <?= isActive('/system/logs') || isActive('/diagnostics') ?>" href="/system/logs">
                <i class="bi bi-activity"></i>
                <span>Диагностика</span>
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
            $ymConfigured = false;

            if (isset($auth) && $auth->isLoggedIn()) {
                $userId = $auth->getUserId();
                $wbApi = new WildberriesAPI($userId);
                $ozonApi = new OzonAPI($userId);
                $ymApi = new YandexMarketAPI($userId);
                $wbConfigured = $wbApi->isConfigured();
                $ozonConfigured = $ozonApi->isConfigured();
                $ymConfigured = $ymApi->isConfigured();
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
                <span class="status-dot <?= $wbConfigured ? 'bg-success' : 'bg-danger' ?> me-2"></span>
                <span class="small">Wildberries</span>
                <?php if ($wbConfigured): ?>
                <i class="bi bi-check-circle-fill text-success ms-auto small"></i>
                <?php else: ?>
                <i class="bi bi-x-circle-fill text-danger ms-auto small"></i>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center">
                <span class="status-dot <?= $ymConfigured ? 'bg-success' : 'bg-danger' ?> me-2"></span>
                <span class="small">Яндекс.Маркет</span>
                <?php if ($ymConfigured): ?>
                <i class="bi bi-check-circle-fill text-success ms-auto small"></i>
                <?php else: ?>
                <i class="bi bi-x-circle-fill text-danger ms-auto small"></i>
                <?php endif; ?>
            </div>
        </div>
    </div>
</aside>
