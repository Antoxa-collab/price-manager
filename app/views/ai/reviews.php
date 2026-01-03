<?php
$pageTitle = 'AI Помощник - Отзывы';
include VIEWS_PATH . '/layout/header.php';
?>

<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0">
                <i class="bi bi-robot me-2"></i>
                AI Помощник - Отзывы
            </h4>
            <div class="btn-group">
                <a href="/ai/reviews" class="btn btn-primary">
                    <i class="bi bi-chat-left-text me-1"></i> Отзывы
                </a>
                <a href="/ai/questions" class="btn btn-outline-primary">
                    <i class="bi bi-question-circle me-1"></i> Вопросы
                </a>
                <a href="/ai/prompts" class="btn btn-outline-primary">
                    <i class="bi bi-file-text me-1"></i> Промпты
                </a>
                <a href="/ai/settings" class="btn btn-outline-primary">
                    <i class="bi bi-gear me-1"></i> Настройки
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Статистика -->
<div class="row mb-3" id="statsRow">
    <div class="col-md-2 col-6">
        <div class="card bg-dark border-secondary text-center">
            <div class="card-body py-2">
                <div class="text-muted small">Новых</div>
                <div class="h5 mb-0 text-info" id="statNew">-</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card bg-dark border-secondary text-center">
            <div class="card-body py-2">
                <div class="text-muted small">Сгенерировано</div>
                <div class="h5 mb-0 text-warning" id="statGenerated">-</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card bg-dark border-secondary text-center">
            <div class="card-body py-2">
                <div class="text-muted small">Одобрено</div>
                <div class="h5 mb-0 text-success" id="statApproved">-</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card bg-dark border-secondary text-center">
            <div class="card-body py-2">
                <div class="text-muted small">Отправлено</div>
                <div class="h5 mb-0 text-primary" id="statSent">-</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card bg-dark border-secondary text-center">
            <div class="card-body py-2">
                <div class="text-muted small">Ошибки</div>
                <div class="h5 mb-0 text-danger" id="statError">-</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card bg-dark border-secondary text-center">
            <div class="card-body py-2">
                <div class="text-muted small">Токенов</div>
                <div class="h5 mb-0 text-secondary" id="statTokens">-</div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card bg-dark border-secondary">
            <div class="card-body py-2">
                <div class="row g-2 align-items-center">
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="filterMarketplace">
                            <option value="ozon">Ozon</option>
                            <option value="wildberries">Wildberries</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="filterStatus">
                            <option value="">Все статусы</option>
                            <option value="new">Новые</option>
                            <option value="generated">Сгенерированы</option>
                            <option value="approved">Одобрены</option>
                            <option value="sent">Отправлены</option>
                            <option value="error">Ошибки</option>
                            <option value="skipped">Пропущены</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select form-select-sm" id="filterRating">
                            <option value="">Все оценки</option>
                            <option value="5">5 звёзд</option>
                            <option value="4">4 звезды</option>
                            <option value="3">3 звезды</option>
                            <option value="2">2 звезды</option>
                            <option value="1">1 звезда</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control form-control-sm" id="filterSearch" placeholder="Поиск...">
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-warning" id="btnSyncOzon">
                                <i class="bi bi-cloud-download"></i> Ozon
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" id="btnSyncWb">
                                <i class="bi bi-cloud-download"></i> Wildberries
                            </button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnRefresh">
                            <i class="bi bi-arrow-clockwise"></i> Обновить
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnGenerateAll">
                            <i class="bi bi-magic"></i> Сгенерировать все
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Лог синхронизации -->
<div class="row mb-3 d-none" id="syncLogRow">
    <div class="col-12">
        <div class="alert alert-info mb-0" id="syncLogAlert">
            <span id="syncLogText">Синхронизация...</span>
        </div>
    </div>
</div>

<!-- Список отзывов -->
<div class="row">
    <div class="col-12">
        <div class="card bg-dark border-secondary">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Оценка</th>
                                <th style="width: 120px;">Статус</th>
                                <th>Отзыв</th>
                                <th style="width: 200px;">Товар</th>
                                <th style="width: 120px;">Дата</th>
                                <th style="width: 150px;">Действия</th>
                            </tr>
                        </thead>
                        <tbody id="reviewsTable">
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="bi bi-hourglass-split me-2"></i>Загрузка...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для просмотра/редактирования ответа -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-chat-left-text me-2"></i>
                    Отзыв
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalReviewId">

                <!-- Информация об отзыве -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="badge bg-secondary me-2" id="modalRating"></span>
                            <span class="text-muted" id="modalAuthor"></span>
                        </div>
                        <small class="text-muted" id="modalDate"></small>
                    </div>
                    <div class="small text-info mb-2">
                        <span id="modalProduct"></span>
                        <span class="text-muted ms-2" id="modalArticle"></span>
                    </div>
                    <div class="p-3 bg-secondary bg-opacity-25 rounded" id="modalReviewText"></div>
                </div>

                <!-- Сгенерированный ответ -->
                <div class="mb-3">
                    <label class="form-label">
                        <i class="bi bi-robot me-1"></i>
                        Сгенерированный ответ
                    </label>
                    <textarea class="form-control bg-dark text-light" id="modalResponse" rows="6" placeholder="Ответ будет сгенерирован..."></textarea>
                </div>

                <!-- Статус генерации -->
                <div class="alert alert-info d-none" id="modalGenerating">
                    <i class="bi bi-hourglass-split me-2"></i>
                    Генерация ответа...
                </div>

                <!-- Информация о генерации -->
                <div class="d-none" id="modalGenerationInfo">
                    <hr class="border-secondary">
                    <div class="small text-muted">
                        <strong><i class="bi bi-info-circle me-1"></i>Информация о генерации:</strong>
                        <div class="row mt-2">
                            <div class="col-6">
                                <span class="text-secondary">Промпт:</span> <span id="metaPromptName">-</span>
                            </div>
                            <div class="col-6">
                                <span class="text-secondary">Тональность:</span> <span id="metaSentiment">-</span>
                            </div>
                            <div class="col-6">
                                <span class="text-secondary">Примеров:</span> <span id="metaExamples">-</span>
                            </div>
                            <div class="col-6">
                                <span class="text-secondary">Товар:</span> <span id="metaProductInfo">-</span>
                            </div>
                            <div class="col-6">
                                <span class="text-secondary">Модель:</span> <span id="metaModel">-</span>
                            </div>
                            <div class="col-6">
                                <span class="text-secondary">Дата:</span> <span id="metaDate">-</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-secondary" id="btnSkipReview">
                    <i class="bi bi-x-circle me-1"></i> Пропустить
                </button>
                <button type="button" class="btn btn-outline-primary" id="btnRegenerateReview">
                    <i class="bi bi-arrow-clockwise me-1"></i> Перегенерировать
                </button>
                <button type="button" class="btn btn-success" id="btnApproveReview">
                    <i class="bi bi-check-circle me-1"></i> Одобрить
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const AIReviews = {
        reviews: [],
        currentReviewId: null,

        async init() {
            this.bindEvents();
            await this.loadStatistics();
            await this.loadReviews();
        },

        getMarketplace() {
            return document.getElementById('filterMarketplace').value;
        },

        // Преобразование ошибок Claude API в понятные сообщения
        formatApiError(errorMessage) {
            if (!errorMessage) return 'Неизвестная ошибка';

            // Перегрузка сервера
            if (errorMessage.includes('529') || errorMessage.includes('overloaded')) {
                return 'Сервер AI временно перегружен. Попробуйте через минуту.';
            }
            // Превышение лимита запросов
            if (errorMessage.includes('429') || errorMessage.includes('rate_limit')) {
                return 'Превышен лимит запросов к AI. Подождите немного.';
            }
            // Проблемы с сервером
            if (errorMessage.includes('500') || errorMessage.includes('502') || errorMessage.includes('503')) {
                return 'Сервер AI временно недоступен. Попробуйте позже.';
            }
            // Ошибка после retry
            if (errorMessage.includes('недоступен после')) {
                return 'AI сервис временно недоступен. Попробуйте через несколько минут.';
            }
            // Проблема с API ключом
            if (errorMessage.includes('authentication') || errorMessage.includes('401')) {
                return 'Проблема с API ключом Claude. Проверьте настройки.';
            }
            // Таймаут
            if (errorMessage.includes('timeout') || errorMessage.includes('timed out')) {
                return 'Превышено время ожидания ответа. Попробуйте снова.';
            }

            return errorMessage;
        },

        bindEvents() {
            document.getElementById('btnSyncOzon').addEventListener('click', () => this.syncFromOzon());
            document.getElementById('btnSyncWb').addEventListener('click', () => this.syncFromWb());
            document.getElementById('btnRefresh').addEventListener('click', () => this.loadReviews());
            document.getElementById('btnGenerateAll').addEventListener('click', () => this.generateAllNew());
            document.getElementById('filterMarketplace').addEventListener('change', () => {
                this.loadReviews();
                this.loadStatistics();
            });
            document.getElementById('filterStatus').addEventListener('change', () => this.loadReviews());
            document.getElementById('filterRating').addEventListener('change', () => this.loadReviews());
            document.getElementById('filterSearch').addEventListener('input', debounce(() => this.loadReviews(), 500));

            document.getElementById('btnSkipReview').addEventListener('click', () => this.skipReview());
            document.getElementById('btnRegenerateReview').addEventListener('click', () => this.regenerateReview());
            document.getElementById('btnApproveReview').addEventListener('click', () => this.approveReview());
        },

        async syncFromOzon() {
            const logRow = document.getElementById('syncLogRow');
            const logText = document.getElementById('syncLogText');
            const logAlert = document.getElementById('syncLogAlert');
            const btn = document.getElementById('btnSyncOzon');

            // Показываем лог
            logRow.classList.remove('d-none');
            logAlert.className = 'alert alert-info mb-0';
            logText.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Загрузка отзывов с Ozon...';
            btn.disabled = true;

            try {
                const result = await App.fetch('/api/ai/sync-reviews', {
                    method: 'POST',
                    body: { marketplace: 'ozon' }
                });

                if (result.success) {
                    const s = result.stats;
                    logAlert.className = 'alert alert-success mb-0';
                    logText.innerHTML = `
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>Готово!</strong>
                        На Ozon: ${s.ozon_total} (${s.ozon_unprocessed} необработ.),
                        загружено: ${s.total_from_ozon},
                        <span class="text-success fw-bold">${s.added} новых</span>,
                        ${s.updated} обновлено,
                        ${s.skipped} пропущено
                    `;

                    App.showToast(`Загружено ${s.added} новых отзывов`, 'success');

                    // Перезагружаем данные
                    await this.loadReviews();
                    await this.loadStatistics();

                    // Скрываем через 10 секунд
                    setTimeout(() => {
                        logRow.classList.add('d-none');
                    }, 10000);

                } else {
                    logAlert.className = 'alert alert-danger mb-0';
                    logText.innerHTML = `<i class="bi bi-x-circle me-2"></i>Ошибка: ${result.error}`;
                    App.showToast('Ошибка: ' + result.error, 'danger');
                }

            } catch (e) {
                logAlert.className = 'alert alert-danger mb-0';
                logText.innerHTML = `<i class="bi bi-x-circle me-2"></i>Ошибка: ${e.message}`;
                App.showToast('Ошибка синхронизации', 'danger');
            } finally {
                btn.disabled = false;
            }
        },

        async syncFromWb() {
            const logRow = document.getElementById('syncLogRow');
            const logText = document.getElementById('syncLogText');
            const logAlert = document.getElementById('syncLogAlert');
            const btn = document.getElementById('btnSyncWb');

            // Показываем лог
            logRow.classList.remove('d-none');
            logAlert.className = 'alert alert-info mb-0';
            logText.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Загрузка отзывов с Wildberries...';
            btn.disabled = true;

            try {
                const result = await App.fetch('/api/ai/sync-wb-reviews', {
                    method: 'POST',
                    body: {}
                });

                if (result.success) {
                    const s = result.stats;
                    logAlert.className = 'alert alert-success mb-0';
                    logText.innerHTML = `
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>Готово!</strong>
                        С WB загружено: ${s.total_from_wb},
                        <span class="text-success fw-bold">${s.added} новых</span>,
                        ${s.updated} обновлено,
                        ${s.skipped} пропущено
                    `;

                    App.showToast(`Загружено ${s.added} новых отзывов с WB`, 'success');

                    // Перезагружаем данные
                    await this.loadReviews();
                    await this.loadStatistics();

                    // Скрываем через 10 секунд
                    setTimeout(() => {
                        logRow.classList.add('d-none');
                    }, 10000);

                } else {
                    logAlert.className = 'alert alert-danger mb-0';
                    logText.innerHTML = `<i class="bi bi-x-circle me-2"></i>Ошибка: ${result.error}`;
                    App.showToast('Ошибка: ' + result.error, 'danger');
                }

            } catch (e) {
                logAlert.className = 'alert alert-danger mb-0';
                logText.innerHTML = `<i class="bi bi-x-circle me-2"></i>Ошибка: ${e.message}`;
                App.showToast('Ошибка синхронизации WB', 'danger');
            } finally {
                btn.disabled = false;
            }
        },

        async loadStatistics() {
            try {
                const result = await App.fetch('/api/ai/statistics?marketplace=' + this.getMarketplace());
                if (result.success && result.statistics) {
                    const stats = result.statistics.reviews || {};
                    document.getElementById('statNew').textContent = stats.new_count || 0;
                    document.getElementById('statGenerated').textContent = stats.generated_count || 0;
                    document.getElementById('statApproved').textContent = stats.approved_count || 0;
                    document.getElementById('statSent').textContent = stats.sent_count || 0;
                    document.getElementById('statError').textContent = stats.error_count || 0;
                    document.getElementById('statTokens').textContent = this.formatNumber(result.statistics.total_tokens || 0);
                }
            } catch (e) {
                console.error('Error loading statistics:', e);
            }
        },

        async loadReviews() {
            const params = new URLSearchParams({
                marketplace: this.getMarketplace(),
                status: document.getElementById('filterStatus').value,
                rating: document.getElementById('filterRating').value,
                search: document.getElementById('filterSearch').value,
                limit: 50
            });

            try {
                const result = await App.fetch('/api/ai/reviews?' + params.toString());
                if (result.success) {
                    this.reviews = result.reviews || [];
                    this.renderReviews();
                }
            } catch (e) {
                App.showToast('Ошибка загрузки отзывов', 'danger');
            }
        },

        renderReviews() {
            const tbody = document.getElementById('reviewsTable');

            if (this.reviews.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-inbox me-2"></i>Отзывов не найдено
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = this.reviews.map(review => `
                <tr data-id="${review.id}">
                    <td>${this.renderRating(review.rating)}</td>
                    <td>${this.renderStatus(review.status)}</td>
                    <td>
                        <div class="text-truncate" style="max-width: 400px;" title="${this.escapeHtml(review.review_text || '')}">
                            ${this.escapeHtml(review.review_text || 'Без текста')}
                        </div>
                        <small class="text-muted">${this.escapeHtml(review.author_name || 'Аноним')}</small>
                    </td>
                    <td>
                        <div class="text-truncate" style="max-width: 180px;" title="${this.escapeHtml(review.product_name || '')}">
                            ${this.escapeHtml(review.product_name || '-')}
                        </div>
                    </td>
                    <td>
                        <small class="text-muted">${this.formatDate(review.review_date)}</small>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="AIReviews.openReview(${review.id})" title="Открыть">
                                <i class="bi bi-eye"></i>
                            </button>
                            ${review.status === 'new' ? `
                                <button class="btn btn-outline-success" onclick="AIReviews.generateResponse(${review.id})" title="Сгенерировать">
                                    <i class="bi bi-magic"></i>
                                </button>
                            ` : ''}
                            ${review.status === 'generated' ? `
                                <button class="btn btn-success" onclick="AIReviews.quickApprove(${review.id})" title="Одобрить">
                                    <i class="bi bi-check"></i>
                                </button>
                            ` : ''}
                            ${review.status === 'approved' ? `
                                <button class="btn btn-primary" onclick="AIReviews.sendToMarketplace(${review.id})" title="Отправить">
                                    <i class="bi bi-send"></i>
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('');
        },

        renderRating(rating) {
            if (!rating) return '<span class="text-muted">-</span>';
            const stars = '★'.repeat(rating) + '☆'.repeat(5 - rating);
            const color = rating >= 4 ? 'text-success' : rating >= 3 ? 'text-warning' : 'text-danger';
            return `<span class="${color}">${stars}</span>`;
        },

        renderStatus(status) {
            const statusMap = {
                'new': '<span class="badge bg-info">Новый</span>',
                'generating': '<span class="badge bg-warning">Генерация...</span>',
                'generated': '<span class="badge bg-primary">Сгенерирован</span>',
                'approved': '<span class="badge bg-success">Одобрен</span>',
                'sent': '<span class="badge bg-secondary">Отправлен</span>',
                'skipped': '<span class="badge bg-dark">Пропущен</span>',
                'error': '<span class="badge bg-danger">Ошибка</span>'
            };
            return statusMap[status] || status;
        },

        async openReview(id) {
            const review = this.reviews.find(r => r.id === id);
            if (!review) return;

            this.currentReviewId = id;
            document.getElementById('modalReviewId').value = id;
            document.getElementById('modalRating').textContent = review.rating ? `${review.rating}/5` : '-';
            document.getElementById('modalAuthor').textContent = review.author_name || 'Аноним';
            document.getElementById('modalDate').textContent = this.formatDate(review.review_date);
            document.getElementById('modalProduct').textContent = review.product_name || '-';
            document.getElementById('modalArticle').textContent = review.product_article ? `(арт. ${review.product_article})` : '';

            let reviewText = review.review_text || '';
            if (review.review_pros) reviewText += `\n\nДостоинства: ${review.review_pros}`;
            if (review.review_cons) reviewText += `\n\nНедостатки: ${review.review_cons}`;
            document.getElementById('modalReviewText').textContent = reviewText || 'Без текста';

            document.getElementById('modalResponse').value = review.edited_response || review.generated_response || '';

            // Показать метаданные генерации если есть
            this.showGenerationMeta(review.generation_meta);

            const modal = new bootstrap.Modal(document.getElementById('reviewModal'));
            modal.show();
        },

        showGenerationMeta(metaJson) {
            const infoDiv = document.getElementById('modalGenerationInfo');
            if (!metaJson) {
                infoDiv.classList.add('d-none');
                return;
            }

            try {
                const meta = typeof metaJson === 'string' ? JSON.parse(metaJson) : metaJson;
                document.getElementById('metaPromptName').textContent = meta.prompt_name || '-';
                document.getElementById('metaSentiment').textContent = this.formatSentimentLabel(meta.detected_sentiment);
                document.getElementById('metaExamples').textContent = meta.examples_count || 0;
                document.getElementById('metaProductInfo').textContent = meta.product_info_used ? 'Да' : 'Нет';
                document.getElementById('metaModel').textContent = meta.model || '-';
                document.getElementById('metaDate').textContent = meta.generated_at || '-';
                infoDiv.classList.remove('d-none');
            } catch (e) {
                infoDiv.classList.add('d-none');
            }
        },

        formatSentimentLabel(sentiment) {
            const labels = {
                'positive': 'Положительная',
                'negative': 'Негативная',
                'neutral': 'Нейтральная'
            };
            return labels[sentiment] || sentiment || '-';
        },

        async generateResponse(id, inModal = false) {
            if (inModal) {
                document.getElementById('modalGenerating').classList.remove('d-none');
            }

            try {
                const result = await App.fetch('/api/ai/generate-review-response', {
                    method: 'POST',
                    body: { review_id: id, marketplace: this.getMarketplace() }
                });

                if (result.success) {
                    App.showToast('Ответ сгенерирован', 'success');
                    if (inModal) {
                        document.getElementById('modalResponse').value = result.response || '';
                    }
                    await this.loadReviews();
                    await this.loadStatistics();
                } else {
                    App.showToast(this.formatApiError(result.error), 'danger');
                }
            } catch (e) {
                App.showToast(this.formatApiError(e.message), 'danger');
            } finally {
                if (inModal) {
                    document.getElementById('modalGenerating').classList.add('d-none');
                }
            }
        },

        async regenerateReview() {
            const id = this.currentReviewId;
            if (!id) return;
            await this.generateResponse(id, true);
        },

        async approveReview() {
            const id = this.currentReviewId;
            if (!id) return;

            const editedResponse = document.getElementById('modalResponse').value;

            try {
                const result = await App.fetch('/api/ai/approve-review', {
                    method: 'POST',
                    body: { review_id: id, edited_response: editedResponse, marketplace: this.getMarketplace() }
                });

                if (result.success) {
                    App.showToast('Ответ одобрен', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
                    await this.loadReviews();
                    await this.loadStatistics();
                } else {
                    App.showToast('Ошибка', 'danger');
                }
            } catch (e) {
                App.showToast('Ошибка', 'danger');
            }
        },

        async quickApprove(id) {
            try {
                const result = await App.fetch('/api/ai/approve-review', {
                    method: 'POST',
                    body: { review_id: id, marketplace: this.getMarketplace() }
                });

                if (result.success) {
                    App.showToast('Ответ одобрен', 'success');
                    await this.loadReviews();
                    await this.loadStatistics();
                }
            } catch (e) {
                App.showToast('Ошибка', 'danger');
            }
        },

        async skipReview() {
            const id = this.currentReviewId;
            if (!id) return;

            try {
                const result = await App.fetch('/api/ai/skip-review', {
                    method: 'POST',
                    body: { review_id: id, marketplace: this.getMarketplace() }
                });

                if (result.success) {
                    App.showToast('Отзыв пропущен', 'info');
                    bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
                    await this.loadReviews();
                }
            } catch (e) {
                App.showToast('Ошибка', 'danger');
            }
        },

        async generateAllNew() {
            const newReviews = this.reviews.filter(r => r.status === 'new');
            if (newReviews.length === 0) {
                App.showToast('Нет новых отзывов для генерации', 'info');
                return;
            }

            if (!confirm(`Сгенерировать ответы для ${newReviews.length} отзывов?`)) {
                return;
            }

            App.showLoading('Генерация ответов...');

            let success = 0;
            let errors = 0;

            for (const review of newReviews) {
                try {
                    const result = await App.fetch('/api/ai/generate-review-response', {
                        method: 'POST',
                        body: { review_id: review.id, marketplace: this.getMarketplace() }
                    });
                    if (result.success) {
                        success++;
                    } else {
                        errors++;
                    }
                } catch (e) {
                    errors++;
                }
            }

            App.hideLoading();
            App.showToast(`Сгенерировано: ${success}, ошибок: ${errors}`, success > 0 ? 'success' : 'warning');
            await this.loadReviews();
            await this.loadStatistics();
        },

        async sendToMarketplace(id) {
            const marketplace = this.getMarketplace();
            const apiUrl = marketplace === 'wildberries'
                ? '/api/ai/send-wb-review-response'
                : '/api/ai/send-review-response';

            try {
                const result = await App.fetch(apiUrl, {
                    method: 'POST',
                    body: { review_id: id, marketplace: marketplace }
                });

                if (result.success) {
                    App.showToast(result.message || 'Ответ отправлен', 'success');
                    await this.loadReviews();
                    await this.loadStatistics();
                } else {
                    App.showToast(this.formatApiError(result.error), 'danger');
                }
            } catch (e) {
                App.showToast(this.formatApiError(e.message), 'danger');
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
        },

        formatNumber(num) {
            if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
            return num;
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    window.AIReviews = AIReviews;
    AIReviews.init();
});
</script>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
