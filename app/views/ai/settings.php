<?php
$pageTitle = 'AI Помощник - Настройки';
include VIEWS_PATH . '/layout/header.php';
?>

<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0">
                <i class="bi bi-robot me-2"></i>
                AI Помощник - Настройки
            </h4>
            <div class="btn-group">
                <a href="/ai/reviews" class="btn btn-outline-primary">
                    <i class="bi bi-chat-left-text me-1"></i> Отзывы
                </a>
                <a href="/ai/questions" class="btn btn-outline-primary">
                    <i class="bi bi-question-circle me-1"></i> Вопросы
                </a>
                <a href="/ai/prompts" class="btn btn-outline-primary">
                    <i class="bi bi-file-text me-1"></i> Промпты
                </a>
                <a href="/ai/settings" class="btn btn-primary">
                    <i class="bi bi-gear me-1"></i> Настройки
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Основные настройки -->
    <div class="col-lg-6 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <i class="bi bi-sliders me-2"></i>
                Основные настройки
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Модель Claude</label>
                    <select class="form-select bg-dark text-light" id="settingModel">
                        <option value="claude-3-haiku-20240307">Claude 3 Haiku (Fast & Cheap)</option>
                    </select>
                    <div class="form-text">Выберите модель для генерации ответов</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Максимум токенов</label>
                    <input type="number" class="form-control bg-dark text-light" id="settingMaxTokens" value="1024" min="256" max="4096">
                    <div class="form-text">Максимальная длина сгенерированного ответа</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Температура (0-1)</label>
                    <input type="number" class="form-control bg-dark text-light" id="settingTemperature" value="0.7" min="0" max="1" step="0.1">
                    <div class="form-text">Выше = более творческие ответы, ниже = более предсказуемые</div>
                </div>

                <button type="button" class="btn btn-primary" id="btnSaveSettings">
                    <i class="bi bi-save me-1"></i> Сохранить настройки
                </button>
            </div>
        </div>
    </div>

    <!-- Настройки магазина -->
    <div class="col-lg-6 mb-4">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <i class="bi bi-shop me-2"></i>
                Настройки магазина
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Название магазина</label>
                    <input type="text" class="form-control bg-dark text-light" id="settingStoreName" placeholder="Наш магазин">
                    <div class="form-text">Используется в ответах</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Подпись</label>
                    <textarea class="form-control bg-dark text-light" id="settingStoreSignature" rows="2" placeholder="С уважением, команда магазина"></textarea>
                    <div class="form-text">Добавляется в конец ответов</div>
                </div>

                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="settingModerationEnabled" checked>
                        <label class="form-check-label" for="settingModerationEnabled">
                            Модерация включена
                        </label>
                    </div>
                    <div class="form-text">Все ответы требуют одобрения перед отправкой</div>
                </div>

                <button type="button" class="btn btn-primary" id="btnSaveStoreSettings">
                    <i class="bi bi-save me-1"></i> Сохранить настройки магазина
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Статистика использования -->
<div class="row">
    <div class="col-12">
        <div class="card bg-dark border-secondary">
            <div class="card-header border-secondary">
                <i class="bi bi-graph-up me-2"></i>
                Статистика использования
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="h4 text-info" id="statTotalTokens">-</div>
                        <div class="text-muted small">Всего токенов</div>
                    </div>
                    <div class="col-md-3">
                        <div class="h4 text-success" id="statReviewsGenerated">-</div>
                        <div class="text-muted small">Ответов на отзывы</div>
                    </div>
                    <div class="col-md-3">
                        <div class="h4 text-warning" id="statQuestionsGenerated">-</div>
                        <div class="text-muted small">Ответов на вопросы</div>
                    </div>
                    <div class="col-md-3">
                        <div class="h4 text-primary" id="statApprovedTotal">-</div>
                        <div class="text-muted small">Всего одобрено</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const AISettings = {
        settings: {},

        async init() {
            this.bindEvents();
            await this.loadSettings();
            await this.loadStatistics();
        },

        bindEvents() {
            document.getElementById('btnSaveSettings').addEventListener('click', () => this.saveSettings());
            document.getElementById('btnSaveStoreSettings').addEventListener('click', () => this.saveStoreSettings());
        },

        async loadSettings() {
            try {
                const result = await App.fetch('/api/ai/settings?marketplace=ozon');
                if (result.success) {
                    this.settings = result.settings || {};

                    // Заполняем модели
                    if (result.models) {
                        const select = document.getElementById('settingModel');
                        select.innerHTML = '';
                        for (const [value, label] of Object.entries(result.models)) {
                            const opt = document.createElement('option');
                            opt.value = value;
                            opt.textContent = label;
                            select.appendChild(opt);
                        }
                    }

                    // Заполняем значения
                    document.getElementById('settingModel').value = this.settings.model || 'claude-3-haiku-20240307';
                    document.getElementById('settingMaxTokens').value = this.settings.max_tokens || 1024;
                    document.getElementById('settingTemperature').value = this.settings.temperature || 0.7;
                    document.getElementById('settingStoreName').value = this.settings.store_name || '';
                    document.getElementById('settingStoreSignature').value = this.settings.store_signature || '';
                    document.getElementById('settingModerationEnabled').checked = this.settings.moderation_enabled !== '0';
                }
            } catch (e) {
                App.showToast('Ошибка загрузки настроек', 'danger');
            }
        },

        async loadStatistics() {
            try {
                const result = await App.fetch('/api/ai/statistics?marketplace=ozon');
                if (result.success && result.statistics) {
                    const reviews = result.statistics.reviews || {};
                    const questions = result.statistics.questions || {};

                    document.getElementById('statTotalTokens').textContent = this.formatNumber(result.statistics.total_tokens || 0);
                    document.getElementById('statReviewsGenerated').textContent = (reviews.generated_count || 0) + (reviews.approved_count || 0) + (reviews.sent_count || 0);
                    document.getElementById('statQuestionsGenerated').textContent = (questions.generated_count || 0) + (questions.approved_count || 0) + (questions.sent_count || 0);
                    document.getElementById('statApprovedTotal').textContent = (reviews.approved_count || 0) + (reviews.sent_count || 0) + (questions.approved_count || 0) + (questions.sent_count || 0);
                }
            } catch (e) {
                console.error('Error loading statistics:', e);
            }
        },

        async saveSettings() {
            const settings = [
                { key: 'model', value: document.getElementById('settingModel').value },
                { key: 'max_tokens', value: document.getElementById('settingMaxTokens').value },
                { key: 'temperature', value: document.getElementById('settingTemperature').value }
            ];

            try {
                let allSuccess = true;
                for (const setting of settings) {
                    console.log('[Settings] Saving:', setting.key, '=', setting.value);
                    const result = await App.fetch('/api/ai/save-setting', {
                        method: 'POST',
                        body: { key: setting.key, value: setting.value, marketplace: 'all' }
                    });
                    console.log('[Settings] Result:', result);
                    if (!result.success) {
                        allSuccess = false;
                        App.showToast(`Ошибка сохранения ${setting.key}: ${result.error || 'неизвестная ошибка'}`, 'danger');
                    }
                }
                if (allSuccess) {
                    App.showToast('Настройки сохранены', 'success');
                }
            } catch (e) {
                console.error('[Settings] Error:', e);
                App.showToast('Ошибка сохранения: ' + e.message, 'danger');
            }
        },

        async saveStoreSettings() {
            const settings = [
                { key: 'store_name', value: document.getElementById('settingStoreName').value },
                { key: 'store_signature', value: document.getElementById('settingStoreSignature').value },
                { key: 'moderation_enabled', value: document.getElementById('settingModerationEnabled').checked ? '1' : '0' }
            ];

            try {
                let allSuccess = true;
                for (const setting of settings) {
                    console.log('[Settings] Saving:', setting.key, '=', setting.value);
                    const result = await App.fetch('/api/ai/save-setting', {
                        method: 'POST',
                        body: { key: setting.key, value: setting.value, marketplace: 'all' }
                    });
                    console.log('[Settings] Result:', result);
                    if (!result.success) {
                        allSuccess = false;
                        App.showToast(`Ошибка сохранения ${setting.key}: ${result.error || 'неизвестная ошибка'}`, 'danger');
                    }
                }
                if (allSuccess) {
                    App.showToast('Настройки магазина сохранены', 'success');
                }
            } catch (e) {
                console.error('[Settings] Error:', e);
                App.showToast('Ошибка сохранения: ' + e.message, 'danger');
            }
        },

        formatNumber(num) {
            if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
            return num;
        }
    };

    window.AISettings = AISettings;
    AISettings.init();
});
</script>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
