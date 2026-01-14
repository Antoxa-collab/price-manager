<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Price Manager - Калькулятор цен для маркетплейсов">
    <meta name="csrf-token" content="<?= e(generateCsrfToken()) ?>">
    <title><?= e($pageTitle ?? 'Price Manager') ?> - <?= APP_NAME ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/images/og-image.png">
    <link rel="apple-touch-icon" href="/images/og-image.png">

    <!-- Open Graph мета-теги для ссылок в соцсетях -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= e($pageTitle ?? 'Распилим') ?> - <?= APP_NAME ?>">
    <meta property="og:description" content="Система управления ценами для маркетплейсов Wildberries и Ozon">
    <meta property="og:image" content="<?= 'http://' . ($_SERVER['HTTP_HOST'] ?? '192.168.0.213:8080') ?>/images/og-image.png">
    <meta property="og:image:width" content="225">
    <meta property="og:image:height" content="225">
    <meta property="og:url" content="<?= 'http://' . ($_SERVER['HTTP_HOST'] ?? '192.168.0.213:8080') . ($_SERVER['REQUEST_URI'] ?? '/') ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= e($pageTitle ?? 'Распилим') ?> - <?= APP_NAME ?>">
    <meta name="twitter:description" content="Система управления ценами для маркетплейсов">
    <meta name="twitter:image" content="<?= 'http://' . ($_SERVER['HTTP_HOST'] ?? '192.168.0.213:8080') ?>/images/og-image.png">

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="/css/style.css" rel="stylesheet">

    <!-- Mobile CSS -->
    <link href="/css/mobile.css" rel="stylesheet">
</head>
<body>
    <!-- Верхняя навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top border-bottom border-secondary">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center logo-animated" href="/">
                <img src="/images/logo.png" alt="<?= APP_NAME ?>" height="32" class="me-2">
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('/') ?>" href="/">
                            <i class="bi bi-calculator me-1"></i> Калькулятор
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('/products') ?>" href="/products">
                            <i class="bi bi-box-seam me-1"></i> Товары
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('/history') ?>" href="/history">
                            <i class="bi bi-clock-history me-1"></i> История
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= isActive('/settings') ?>" href="/settings">
                            <i class="bi bi-gear me-1"></i> Настройки
                        </a>
                    </li>
                </ul>

                <?php if (isset($auth) && $auth->isLoggedIn()): ?>
                <!-- Индикатор деплоя -->
                <div class="deploy-info d-none d-lg-flex align-items-center me-3" id="deployInfo" title="Последняя синхронизация с сервером">
                    <span class="deploy-icon">🚀</span>
                    <span class="deploy-label text-muted me-1">Деплой:</span>
                    <span class="deploy-time" id="deployTime">—</span>
                </div>

                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i>
                            <?= e($auth->getUsername()) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                            <li>
                                <a class="dropdown-item" href="/settings">
                                    <i class="bi bi-gear me-2"></i> Настройки
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="/logout">
                                    <i class="bi bi-box-arrow-right me-2"></i> Выход
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Mobile Header (visible only on mobile) -->
    <?php if (isset($auth) && $auth->isLoggedIn()): ?>
    <div class="mobile-header">
        <button class="hamburger-btn" id="sidebarToggle" aria-label="Toggle menu">
            <i class="bi bi-list"></i>
        </button>
        <span class="mobile-title"><?= e($pageTitle ?? APP_NAME) ?></span>
    </div>
    <?php endif; ?>

    <!-- Sidebar Overlay (for mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Основной контейнер -->
    <div class="wrapper">
        <?php if (isset($auth) && $auth->isLoggedIn()): ?>
        <!-- Боковое меню -->
        <?php include VIEWS_PATH . '/layout/sidebar.php'; ?>
        <?php endif; ?>

        <!-- Основной контент -->
        <main class="main-content <?= (isset($auth) && $auth->isLoggedIn()) ? 'with-sidebar' : '' ?>">
            <div class="container-fluid py-4">
                <!-- Flash сообщения -->
                <?php foreach (getFlash() as $flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endforeach; ?>
