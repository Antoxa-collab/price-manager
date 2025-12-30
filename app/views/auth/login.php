<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход - <?= APP_NAME ?></title>

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        html, body {
            height: 100%;
        }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
        }
        .login-card {
            background: rgba(33, 37, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.5);
        }
        .login-header {
            text-align: center;
            padding: 2rem 2rem 1rem;
        }
        .login-header i {
            font-size: 3rem;
            color: #0d6efd;
            margin-bottom: 1rem;
        }
        .login-body {
            padding: 1rem 2rem 2rem;
        }
        .form-floating > .form-control {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.1);
        }
        .form-floating > .form-control:focus {
            background: rgba(255, 255, 255, 0.1);
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .btn-login {
            padding: 0.75rem;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="bi bi-calculator"></i>
                <h4 class="mb-0"><?= APP_NAME ?></h4>
                <p class="text-muted small mt-2">Калькулятор цен для маркетплейсов</p>
            </div>

            <div class="login-body">
                <!-- Сообщения об ошибках -->
                <?php foreach (getFlash() as $flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endforeach; ?>

                <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?= e($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="/login" id="loginForm">
                    <?= csrfField() ?>

                    <div class="form-floating mb-3">
                        <input type="text"
                               class="form-control"
                               id="username"
                               name="username"
                               placeholder="Логин"
                               value="<?= e($username ?? '') ?>"
                               required
                               autofocus>
                        <label for="username">
                            <i class="bi bi-person me-1"></i> Логин
                        </label>
                    </div>

                    <div class="form-floating mb-4">
                        <input type="password"
                               class="form-control"
                               id="password"
                               name="password"
                               placeholder="Пароль"
                               required>
                        <label for="password">
                            <i class="bi bi-lock me-1"></i> Пароль
                        </label>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="remember" name="remember">
                        <label class="form-check-label" for="remember">
                            Запомнить меня
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 btn-login">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Войти
                    </button>
                </form>
            </div>
        </div>

        <p class="text-center text-muted mt-4 small">
            <?= APP_NAME ?> v<?= APP_VERSION ?>
        </p>
    </div>

    <!-- Bootstrap 5.3 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
