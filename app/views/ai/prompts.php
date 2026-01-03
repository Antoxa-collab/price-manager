<?php
$pageTitle = 'AI Помощник - Промпты';
include VIEWS_PATH . '/layout/header.php';
?>

<div class="row mb-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4 class="mb-0">
                <i class="bi bi-robot me-2"></i>
                AI Помощник - Промпты
            </h4>
            <div class="btn-group">
                <a href="/ai/reviews" class="btn btn-outline-primary">
                    <i class="bi bi-chat-left-text me-1"></i> Отзывы
                </a>
                <a href="/ai/questions" class="btn btn-outline-primary">
                    <i class="bi bi-question-circle me-1"></i> Вопросы
                </a>
                <a href="/ai/prompts" class="btn btn-primary">
                    <i class="bi bi-file-text me-1"></i> Промпты
                </a>
                <a href="/ai/settings" class="btn btn-outline-primary">
                    <i class="bi bi-gear me-1"></i> Настройки
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры и действия -->
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
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-success" id="btnAddPrompt">
                            <i class="bi bi-plus-circle me-1"></i> Добавить промпт
                        </button>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnCreateDefaults">
                            <i class="bi bi-magic me-1"></i> Создать стандартные промпты
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Список промптов -->
<div class="row">
    <div class="col-12">
        <div class="card bg-dark border-secondary">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 200px;">Название</th>
                                <th style="width: 100px;">Тип</th>
                                <th style="width: 120px;">Тональность</th>
                                <th>Системный промпт</th>
                                <th style="width: 100px;">По умолч.</th>
                                <th style="width: 100px;">Активен</th>
                                <th style="width: 120px;">Действия</th>
                            </tr>
                        </thead>
                        <tbody id="promptsTable">
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
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

<!-- Модальное окно редактирования промпта -->
<div class="modal fade" id="promptModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <h5 class="modal-title">
                    <i class="bi bi-file-text me-2"></i>
                    <span id="modalTitle">Редактирование промпта</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="promptId">

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Название</label>
                            <input type="text" class="form-control bg-dark text-light" id="promptName" placeholder="Например: Ответ на положительный отзыв">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Тип</label>
                            <select class="form-select bg-dark text-light" id="promptType">
                                <option value="review">Отзыв</option>
                                <option value="question">Вопрос</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">Тональность</label>
                            <select class="form-select bg-dark text-light" id="promptSentiment">
                                <option value="">Любая</option>
                                <option value="positive">Положительная</option>
                                <option value="negative">Негативная</option>
                                <option value="neutral">Нейтральная</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Системный промпт
                        <small class="text-muted">(инструкции для AI)</small>
                    </label>
                    <textarea class="form-control bg-dark text-light font-monospace" id="systemPrompt" rows="8" placeholder="Инструкции для AI..."></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Шаблон пользовательского промпта
                        <small class="text-muted">(переменные: {{review_text}}, {{author_name}}, {{rating}}, {{product_name}}, {{knowledge}}, {{store_signature}})</small>
                    </label>
                    <textarea class="form-control bg-dark text-light font-monospace" id="userPromptTemplate" rows="6" placeholder="Напиши ответ на отзыв от {{author_name}}..."></textarea>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="promptIsDefault">
                            <label class="form-check-label" for="promptIsDefault">По умолчанию для этого типа</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="promptIsActive" checked>
                            <label class="form-check-label" for="promptIsActive">Активен</label>
                        </div>
                    </div>
                </div>

                <!-- Примеры -->
                <hr class="border-secondary my-4">
                <h6>
                    <i class="bi bi-list-ul me-1"></i>
                    Примеры (few-shot learning)
                    <button type="button" class="btn btn-sm btn-outline-success ms-2" id="btnAddExample">
                        <i class="bi bi-plus"></i> Добавить пример
                    </button>
                </h6>
                <div id="examplesContainer"></div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-success" id="btnSavePrompt">
                    <i class="bi bi-save me-1"></i> Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const AIPrompts = {
        prompts: [],
        currentPromptId: null,
        examples: [],

        async init() {
            this.bindEvents();
            await this.loadPrompts();
        },

        getMarketplace() {
            return document.getElementById('filterMarketplace').value;
        },

        bindEvents() {
            document.getElementById('btnAddPrompt').addEventListener('click', () => this.openPromptModal());
            document.getElementById('btnCreateDefaults').addEventListener('click', () => this.createDefaults());
            document.getElementById('btnSavePrompt').addEventListener('click', () => this.savePrompt());
            document.getElementById('btnAddExample').addEventListener('click', () => this.addExample());
            document.getElementById('filterMarketplace').addEventListener('change', () => this.loadPrompts());
        },

        async loadPrompts() {
            try {
                const result = await App.fetch('/api/ai/prompts?marketplace=' + this.getMarketplace());
                if (result.success) {
                    this.prompts = result.prompts || [];
                    this.renderPrompts();
                }
            } catch (e) {
                App.showToast('Ошибка загрузки промптов', 'danger');
            }
        },

        renderPrompts() {
            const tbody = document.getElementById('promptsTable');

            if (this.prompts.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-inbox me-2"></i>Промптов нет. Нажмите "Создать стандартные промпты"
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = this.prompts.map(prompt => `
                <tr data-id="${prompt.id}">
                    <td>${this.escapeHtml(prompt.name)}</td>
                    <td>${prompt.type === 'review' ? '<span class="badge bg-info">Отзыв</span>' : '<span class="badge bg-warning">Вопрос</span>'}</td>
                    <td>${this.renderSentiment(prompt.sentiment)}</td>
                    <td>
                        <div class="text-truncate" style="max-width: 300px;" title="${this.escapeHtml(prompt.system_prompt)}">
                            ${this.escapeHtml(prompt.system_prompt)}
                        </div>
                    </td>
                    <td>${prompt.is_default ? '<i class="bi bi-check-circle text-success"></i>' : ''}</td>
                    <td>${prompt.is_active ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>'}</td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="AIPrompts.editPrompt(${prompt.id})" title="Редактировать">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-outline-danger" onclick="AIPrompts.deletePrompt(${prompt.id})" title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        },

        renderSentiment(sentiment) {
            const map = {
                'positive': '<span class="badge bg-success">Положительная</span>',
                'negative': '<span class="badge bg-danger">Негативная</span>',
                'neutral': '<span class="badge bg-secondary">Нейтральная</span>'
            };
            return map[sentiment] || '<span class="text-muted">-</span>';
        },

        openPromptModal(promptData = null) {
            this.currentPromptId = promptData?.id || null;
            this.examples = [];
            this.originalExampleIds = []; // Для отслеживания удалённых примеров

            document.getElementById('modalTitle').textContent = promptData ? 'Редактирование промпта' : 'Новый промпт';
            document.getElementById('promptId').value = promptData?.id || '';
            document.getElementById('promptName').value = promptData?.name || '';
            document.getElementById('promptType').value = promptData?.type || 'review';
            document.getElementById('promptSentiment').value = promptData?.sentiment || '';
            document.getElementById('systemPrompt').value = promptData?.system_prompt || '';
            document.getElementById('userPromptTemplate').value = promptData?.user_prompt_template || '';
            document.getElementById('promptIsDefault').checked = !!promptData?.is_default;
            document.getElementById('promptIsActive').checked = promptData?.is_active !== 0;

            this.renderExamples();

            const modal = new bootstrap.Modal(document.getElementById('promptModal'));
            modal.show();
        },

        async editPrompt(id) {
            try {
                const result = await App.fetch(`/api/ai/prompt?id=${id}&marketplace=${this.getMarketplace()}`);
                if (result.success) {
                    // Сначала открываем модалку с данными промпта
                    this.openPromptModal(result.prompt);
                    // Затем устанавливаем примеры (после очистки в openPromptModal)
                    this.examples = result.examples || [];
                    this.originalExampleIds = this.examples.map(e => e.id); // Запоминаем оригинальные ID
                    this.renderExamples();
                }
            } catch (e) {
                App.showToast('Ошибка загрузки промпта', 'danger');
            }
        },

        addExample() {
            this.examples.push({ id: null, input_text: '', output_text: '', is_active: 1 });
            this.renderExamples();
        },

        renderExamples() {
            const container = document.getElementById('examplesContainer');

            if (this.examples.length === 0) {
                container.innerHTML = '<p class="text-muted small">Нет примеров. Примеры помогают AI генерировать более качественные ответы.</p>';
                return;
            }

            container.innerHTML = this.examples.map((ex, idx) => `
                <div class="card bg-secondary bg-opacity-25 mb-2" data-idx="${idx}">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <strong class="small">Пример ${idx + 1}</strong>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="AIPrompts.removeExample(${idx})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Входной текст (отзыв/вопрос)</label>
                            <textarea class="form-control form-control-sm bg-dark text-light" rows="2" onchange="AIPrompts.updateExample(${idx}, 'input_text', this.value)">${this.escapeHtml(ex.input_text)}</textarea>
                        </div>
                        <div>
                            <label class="form-label small mb-1">Ожидаемый ответ</label>
                            <textarea class="form-control form-control-sm bg-dark text-light" rows="2" onchange="AIPrompts.updateExample(${idx}, 'output_text', this.value)">${this.escapeHtml(ex.output_text)}</textarea>
                        </div>
                    </div>
                </div>
            `).join('');
        },

        updateExample(idx, field, value) {
            if (this.examples[idx]) {
                this.examples[idx][field] = value;
            }
        },

        removeExample(idx) {
            this.examples.splice(idx, 1);
            this.renderExamples();
        },

        async savePrompt() {
            const data = {
                id: document.getElementById('promptId').value || null,
                name: document.getElementById('promptName').value,
                type: document.getElementById('promptType').value,
                sentiment: document.getElementById('promptSentiment').value || null,
                system_prompt: document.getElementById('systemPrompt').value,
                user_prompt_template: document.getElementById('userPromptTemplate').value,
                is_default: document.getElementById('promptIsDefault').checked ? 1 : 0,
                is_active: document.getElementById('promptIsActive').checked ? 1 : 0,
                marketplace: this.getMarketplace()
            };

            if (!data.name) {
                App.showToast('Укажите название промпта', 'warning');
                return;
            }

            try {
                const result = await App.fetch('/api/ai/save-prompt', {
                    method: 'POST',
                    body: data
                });

                if (result.success) {
                    const promptId = result.prompt_id;

                    // Определяем какие примеры были удалены
                    const currentExampleIds = this.examples
                        .filter(ex => ex.id)
                        .map(ex => ex.id);

                    const deletedExampleIds = this.originalExampleIds
                        .filter(id => !currentExampleIds.includes(id));

                    // Удаляем удалённые примеры
                    for (const exampleId of deletedExampleIds) {
                        await App.fetch('/api/ai/delete-example', {
                            method: 'POST',
                            body: { id: exampleId, marketplace: this.getMarketplace() }
                        });
                    }

                    // Сохраняем примеры (новые и изменённые)
                    for (const ex of this.examples) {
                        if (ex.input_text && ex.output_text) {
                            await App.fetch('/api/ai/save-example', {
                                method: 'POST',
                                body: {
                                    id: ex.id,
                                    prompt_id: promptId,
                                    input_text: ex.input_text,
                                    output_text: ex.output_text,
                                    is_active: 1,
                                    marketplace: this.getMarketplace()
                                }
                            });
                        }
                    }

                    App.showToast('Промпт сохранён', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('promptModal')).hide();
                    await this.loadPrompts();
                } else {
                    App.showToast(result.error || 'Ошибка сохранения', 'danger');
                }
            } catch (e) {
                App.showToast('Ошибка сохранения', 'danger');
            }
        },

        async deletePrompt(id) {
            if (!confirm('Удалить этот промпт?')) return;

            try {
                const result = await App.fetch('/api/ai/delete-prompt', {
                    method: 'POST',
                    body: { id: id, marketplace: this.getMarketplace() }
                });

                if (result.success) {
                    App.showToast('Промпт удалён', 'success');
                    await this.loadPrompts();
                }
            } catch (e) {
                App.showToast('Ошибка удаления', 'danger');
            }
        },

        async createDefaults() {
            if (!confirm('Создать стандартные промпты для отзывов и вопросов?')) return;

            try {
                const result = await App.fetch('/api/ai/create-default-prompts', {
                    method: 'POST',
                    body: { marketplace: this.getMarketplace() }
                });

                if (result.success) {
                    App.showToast('Стандартные промпты созданы', 'success');
                    await this.loadPrompts();
                }
            } catch (e) {
                App.showToast('Ошибка', 'danger');
            }
        },

        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    window.AIPrompts = AIPrompts;
    AIPrompts.init();
});
</script>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
