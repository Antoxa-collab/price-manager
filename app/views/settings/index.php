<?php
$pageTitle = 'Настройки API';
include VIEWS_PATH . '/layout/header.php';

// Получаем текущие настройки
$userId = $auth->getUserId();
$db = Database::getInstance();

$wbSettings = $db->fetchOne(
    "SELECT * FROM api_settings WHERE user_id = ? AND platform = 'wildberries'",
    [$userId]
);

$ozonSettings = $db->fetchOne(
    "SELECT * FROM api_settings WHERE user_id = ? AND platform = 'ozon'",
    [$userId]
);

$ymSettings = $db->fetchOne(
    "SELECT * FROM api_settings WHERE user_id = ? AND platform = 'yandex_market'",
    [$userId]
);

// Получаем настройки Claude
$claudeKey = $db->fetchOne(
    "SELECT * FROM user_api_keys WHERE user_id = ? AND service = 'claude'",
    [$userId]
);
?>

<div class="row">
    <div class="col-12">
        <h4 class="mb-4">
            <i class="bi bi-gear me-2"></i>
            <span class="hide-mobile">Настройки API маркетплейсов</span>
            <span class="show-mobile d-none">Настройки API</span>
        </h4>
    </div>
</div>

<div class="row">
    <!-- Wildberries -->
    <div class="col-lg-6 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-shop me-2"></i>
                    Wildberries
                </span>
                <?php if (!empty($wbSettings['api_key'])): ?>
                <span class="badge bg-success">Подключено</span>
                <?php else: ?>
                <span class="badge bg-danger">Не настроено</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="wbSettingsForm" method="POST" action="/api/settings/wb">
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label for="wbApiToken" class="form-label">
                            API токен
                            <a href="https://seller.wildberries.ru/supplier-settings/access-to-api" target="_blank" class="text-muted small">
                                <i class="bi bi-question-circle"></i>
                            </a>
                        </label>
                        <textarea class="form-control font-monospace"
                                  id="wbApiToken"
                                  name="api_token"
                                  rows="3"
                                  placeholder="Вставьте API токен из личного кабинета WB"><?= e($wbSettings['api_key'] ?? '') ?></textarea>
                        <div class="form-text">
                            Получите токен в личном кабинете: Настройки &rarr; Доступ к API
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="wbWarehouseId" class="form-label">ID склада</label>
                        <input type="text"
                               class="form-control"
                               id="wbWarehouseId"
                               name="warehouse_id"
                               value="<?= e($wbSettings['warehouse_id'] ?? '') ?>"
                               placeholder="Например: 123456">
                        <div class="form-text">
                            ID вашего склада для обновления остатков
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Сохранить
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="testWbBtn">
                            <i class="bi bi-check-circle me-1"></i> Проверить
                        </button>
                    </div>
                </form>
            </div>
            <?php if (!empty($wbSettings['last_sync_at'])): ?>
            <div class="card-footer border-secondary text-muted small">
                <i class="bi bi-clock me-1"></i>
                Последняя синхронизация: <?= formatDate($wbSettings['last_sync_at']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ozon -->
    <div class="col-lg-6 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-shop me-2"></i>
                    Ozon
                </span>
                <?php if (!empty($ozonSettings['client_id']) && !empty($ozonSettings['api_key'])): ?>
                <span class="badge bg-success">Подключено</span>
                <?php else: ?>
                <span class="badge bg-danger">Не настроено</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="ozonSettingsForm" method="POST" action="/api/settings/ozon">
                    <?= csrfField() ?>

                    <div class="mb-3">
                        <label for="ozonClientId" class="form-label">
                            Client-Id
                            <a href="https://seller.ozon.ru/app/settings/api-keys" target="_blank" class="text-muted small">
                                <i class="bi bi-question-circle"></i>
                            </a>
                        </label>
                        <input type="text"
                               class="form-control"
                               id="ozonClientId"
                               name="client_id"
                               value="<?= e($ozonSettings['client_id'] ?? '') ?>"
                               placeholder="Например: 123456">
                        <div class="form-text">
                            Идентификатор клиента из личного кабинета Ozon Seller
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ozonApiKey" class="form-label">API-Key</label>
                        <textarea class="form-control font-monospace"
                                  id="ozonApiKey"
                                  name="api_key"
                                  rows="3"
                                  placeholder="Вставьте API ключ"><?= e($ozonSettings['api_key'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="ozonWarehouseId" class="form-label">ID склада</label>
                        <input type="text"
                               class="form-control"
                               id="ozonWarehouseId"
                               name="warehouse_id"
                               value="<?= e($ozonSettings['warehouse_id'] ?? '') ?>"
                               placeholder="Например: 123456789">
                        <div class="form-text">
                            ID вашего склада для обновления остатков
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Сохранить
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="testOzonBtn">
                            <i class="bi bi-check-circle me-1"></i> Проверить
                        </button>
                    </div>
                </form>
            </div>
            <?php if (!empty($ozonSettings['last_sync_at'])): ?>
            <div class="card-footer border-secondary text-muted small">
                <i class="bi bi-clock me-1"></i>
                Последняя синхронизация: <?= formatDate($ozonSettings['last_sync_at']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Яндекс.Маркет -->
    <div class="col-lg-6 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-shop me-2"></i>
                    Яндекс.Маркет
                </span>
                <?php if (!empty($ymSettings['api_key']) && !empty($ymSettings['client_id'])): ?>
                <span class="badge bg-success">Подключено</span>
                <?php else: ?>
                <span class="badge bg-danger">Не настроено</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="ymSettingsForm">
                    <div class="mb-3">
                        <label for="ymApiKey" class="form-label">
                            API-Key
                            <a href="https://partner.market.yandex.ru" target="_blank" class="text-muted small">
                                <i class="bi bi-question-circle"></i>
                            </a>
                        </label>
                        <textarea class="form-control font-monospace"
                                  id="ymApiKey"
                                  name="api_key"
                                  rows="2"
                                  placeholder="ACMA:..."><?= e($ymSettings['api_key'] ?? '') ?></textarea>
                        <div class="form-text">
                            Токен авторизации из кабинета Яндекс.Маркет
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ymBusinessId" class="form-label">Business ID (ID кабинета)</label>
                        <input type="text"
                               class="form-control"
                               id="ymBusinessId"
                               name="business_id"
                               value="<?= e($ymSettings['client_id'] ?? '') ?>"
                               placeholder="Например: 12345678">
                        <div class="form-text">
                            Идентификатор кабинета из раздела Настройки &rarr; API
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ymCampaignId" class="form-label">Campaign ID (ID кампании/магазина)</label>
                        <input type="text"
                               class="form-control"
                               id="ymCampaignId"
                               name="campaign_id"
                               value="<?= e($ymSettings['shop_id'] ?? '') ?>"
                               placeholder="Например: 87654321">
                        <div class="form-text">
                            Идентификатор кампании (магазина)
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="ymWarehouseId" class="form-label">ID склада</label>
                        <input type="text"
                               class="form-control"
                               id="ymWarehouseId"
                               name="warehouse_id"
                               value="<?= e($ymSettings['warehouse_id'] ?? '') ?>"
                               placeholder="Например: 123456789">
                        <div class="form-text">
                            ID вашего склада для обновления остатков (FBY/FBS)
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Сохранить
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="testYmBtn">
                            <i class="bi bi-check-circle me-1"></i> Проверить
                        </button>
                    </div>
                </form>
            </div>
            <?php if (!empty($ymSettings['last_sync_at'])): ?>
            <div class="card-footer border-secondary text-muted small">
                <i class="bi bi-clock me-1"></i>
                Последняя синхронизация: <?= formatDate($ymSettings['last_sync_at']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Claude AI -->
<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-robot me-2"></i>
                    Claude AI (Anthropic)
                </span>
                <?php if (!empty($claudeKey['api_key']) && ($claudeKey['is_active'] ?? 0)): ?>
                <span class="badge bg-success">Подключено</span>
                <?php else: ?>
                <span class="badge bg-danger">Не настроено</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="claudeSettingsForm">
                    <div class="mb-3">
                        <label for="claudeApiKey" class="form-label">
                            API Key
                            <a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-muted small">
                                <i class="bi bi-question-circle"></i>
                            </a>
                        </label>
                        <input type="password"
                               class="form-control font-monospace"
                               id="claudeApiKey"
                               name="api_key"
                               value="<?= !empty($claudeKey['api_key']) ? '••••••••••••••••' : '' ?>"
                               placeholder="sk-ant-api03-...">
                        <div class="form-text">
                            API ключ для генерации ответов на отзывы и вопросы.
                            <a href="https://console.anthropic.com/settings/keys" target="_blank">Получить ключ</a>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> Сохранить
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="testClaudeBtn">
                            <i class="bi bi-check-circle me-1"></i> Проверить
                        </button>
                        <a href="/ai" class="btn btn-outline-info ms-auto">
                            <i class="bi bi-robot me-1"></i> AI Помощник
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header border-secondary">
                <i class="bi bi-info-circle me-2"></i>
                О Claude AI
            </div>
            <div class="card-body">
                <p class="small mb-2">
                    Claude AI используется для автоматической генерации ответов на отзывы и вопросы покупателей на маркетплейсах.
                </p>
                <ul class="small mb-0">
                    <li>Генерация персонализированных ответов</li>
                    <li>Учёт тональности отзыва (положительный/негативный)</li>
                    <li>Использование информации о товаре</li>
                    <li>Модерация перед отправкой</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Инструкция -->
<div class="row">
    <div class="col-12">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <i class="bi bi-book me-2"></i>
                Инструкция по подключению
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6>Wildberries</h6>
                        <ol class="small">
                            <li>Войдите в <a href="https://seller.wildberries.ru" target="_blank">личный кабинет WB</a></li>
                            <li>Перейдите в раздел "Настройки" &rarr; "Доступ к API"</li>
                            <li>Создайте новый токен с правами на управление ценами и остатками</li>
                            <li>Скопируйте токен и вставьте в поле выше</li>
                            <li>Укажите ID склада (можно найти в разделе "Склады")</li>
                        </ol>
                    </div>
                    <div class="col-md-4">
                        <h6>Ozon</h6>
                        <ol class="small">
                            <li>Войдите в <a href="https://seller.ozon.ru" target="_blank">личный кабинет Ozon Seller</a></li>
                            <li>Перейдите в раздел "Настройки" &rarr; "API ключи"</li>
                            <li>Создайте новый ключ с правами Admin (или Seller API)</li>
                            <li>Скопируйте Client-Id и API-Key</li>
                            <li>Укажите ID склада из раздела "FBO склады"</li>
                        </ol>
                    </div>
                    <div class="col-md-4">
                        <h6>Яндекс.Маркет</h6>
                        <ol class="small">
                            <li>Войдите в <a href="https://partner.market.yandex.ru" target="_blank">личный кабинет Яндекс.Маркет</a></li>
                            <li>Перейдите: Настройки &rarr; API и модули</li>
                            <li>Создайте новый API-Key токен с правами на управление ценами и остатками</li>
                            <li>Скопируйте API-Key и вставьте в поле выше</li>
                            <li>Business ID и Campaign ID найдите в разделе "API и модули"</li>
                            <li>Укажите ID склада из раздела "Склады"</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Сохранение настроек WB
    document.getElementById('wbSettingsForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        fetch('/api/settings/wb', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                App.showToast('Настройки Wildberries сохранены', 'success');
            } else {
                App.showToast(data.message || 'Ошибка сохранения', 'danger');
            }
        })
        .catch(error => {
            App.showToast('Ошибка соединения', 'danger');
        });
    });

    // Сохранение настроек Ozon
    document.getElementById('ozonSettingsForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        fetch('/api/settings/ozon', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                App.showToast('Настройки Ozon сохранены', 'success');
            } else {
                App.showToast(data.message || 'Ошибка сохранения', 'danger');
            }
        })
        .catch(error => {
            App.showToast('Ошибка соединения', 'danger');
        });
    });

    // Проверка WB
    document.getElementById('testWbBtn').addEventListener('click', function() {
        App.showLoading('Проверка подключения к Wildberries...');

        fetch('/api/test/wb', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            }
        })
        .then(response => response.json())
        .then(data => {
            App.hideLoading();
            if (data.success) {
                App.showToast('Подключение к Wildberries работает!', 'success');
            } else {
                App.showToast(data.message || 'Ошибка подключения', 'danger');
            }
        })
        .catch(error => {
            App.hideLoading();
            App.showToast('Ошибка проверки', 'danger');
        });
    });

    // Проверка Ozon
    document.getElementById('testOzonBtn').addEventListener('click', function() {
        App.showLoading('Проверка подключения к Ozon...');

        fetch('/api/test/ozon', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            }
        })
        .then(response => response.json())
        .then(data => {
            App.hideLoading();
            if (data.success) {
                App.showToast('Подключение к Ozon работает!', 'success');
            } else {
                App.showToast(data.message || 'Ошибка подключения', 'danger');
            }
        })
        .catch(error => {
            App.hideLoading();
            App.showToast('Ошибка проверки', 'danger');
        });
    });

    // Сохранение настроек Яндекс.Маркет
    document.getElementById('ymSettingsForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const data = {
            api_key: document.getElementById('ymApiKey').value.trim(),
            business_id: document.getElementById('ymBusinessId').value.trim(),
            campaign_id: document.getElementById('ymCampaignId').value.trim(),
            warehouse_id: document.getElementById('ymWarehouseId').value.trim()
        };

        App.showLoading('Сохранение настроек...');

        fetch('/api/settings/yandex', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            App.hideLoading();
            if (data.success) {
                App.showToast('Настройки Яндекс.Маркет сохранены', 'success');
                // Обновляем бейдж
                const badge = document.querySelector('#ymSettingsForm').closest('.card').querySelector('.badge');
                if (badge) {
                    badge.className = 'badge bg-success';
                    badge.textContent = 'Подключено';
                }
            } else {
                App.showToast(data.message || data.error || 'Ошибка сохранения', 'danger');
            }
        })
        .catch(error => {
            App.hideLoading();
            App.showToast('Ошибка соединения', 'danger');
        });
    });

    // Проверка Яндекс.Маркет
    document.getElementById('testYmBtn').addEventListener('click', function() {
        App.showLoading('Проверка подключения к Яндекс.Маркет...');

        fetch('/api/test/yandex', {
            method: 'GET',
            headers: {
                'X-CSRF-Token': window.csrfToken
            }
        })
        .then(response => response.json())
        .then(data => {
            App.hideLoading();
            if (data.success) {
                App.showToast(data.message || 'Подключение к Яндекс.Маркет работает!', 'success');
            } else {
                App.showToast(data.message || data.error || 'Ошибка подключения', 'danger');
            }
        })
        .catch(error => {
            App.hideLoading();
            App.showToast('Ошибка проверки', 'danger');
        });
    });

    // Сохранение настроек Claude
    document.getElementById('claudeSettingsForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const apiKey = document.getElementById('claudeApiKey').value;

        // Не отправляем если это маска
        if (apiKey === '••••••••••••••••' || apiKey === '') {
            App.showToast('Введите API ключ', 'warning');
            return;
        }

        App.showLoading('Проверка и сохранение ключа...');

        fetch('/api/ai/save-claude-key', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            },
            body: JSON.stringify({ api_key: apiKey })
        })
        .then(response => response.json())
        .then(data => {
            App.hideLoading();
            if (data.success) {
                App.showToast('API ключ Claude сохранён и проверен', 'success');
                document.getElementById('claudeApiKey').value = '••••••••••••••••';
                // Обновляем бейдж статуса
                const badge = document.querySelector('#claudeSettingsForm').closest('.card').querySelector('.badge');
                if (badge) {
                    badge.className = 'badge bg-success';
                    badge.textContent = 'Подключено';
                }
            } else {
                App.showToast(data.error || 'Ошибка сохранения', 'danger');
            }
        })
        .catch(error => {
            App.hideLoading();
            App.showToast('Ошибка соединения', 'danger');
        });
    });

    // Проверка Claude
    document.getElementById('testClaudeBtn').addEventListener('click', function() {
        App.showLoading('Проверка подключения к Claude...');

        fetch('/api/ai/test-claude', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken
            }
        })
        .then(response => response.json())
        .then(data => {
            App.hideLoading();
            if (data.success) {
                App.showToast('Подключение к Claude работает!', 'success');
            } else {
                App.showToast(data.error || 'Ошибка подключения', 'danger');
            }
        })
        .catch(error => {
            App.hideLoading();
            App.showToast('Ошибка проверки', 'danger');
        });
    });

    // Очистка поля при фокусе если там маска
    document.getElementById('claudeApiKey').addEventListener('focus', function() {
        if (this.value === '••••••••••••••••') {
            this.value = '';
            this.type = 'text';
        }
    });

    document.getElementById('claudeApiKey').addEventListener('blur', function() {
        if (this.value === '') {
            this.value = '••••••••••••••••';
            this.type = 'password';
        }
    });
});
</script>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
