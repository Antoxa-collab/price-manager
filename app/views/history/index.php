<?php
$pageTitle = 'История операций';
include VIEWS_PATH . '/layout/header.php';
?>

<div class="row">
    <div class="col-12">
        <h4 class="mb-4">
            <i class="bi bi-clock-history me-2"></i>
            История операций
        </h4>
    </div>
</div>

<div class="card bg-dark border-secondary">
    <div class="card-header border-secondary d-flex justify-content-between align-items-center">
        <span>Последние операции</span>
        <span class="badge bg-secondary"><?= count($history ?? []) ?> записей</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($history)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-clock-history display-4"></i>
            <p class="mt-3">История операций пуста</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-striped table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 150px;">Дата</th>
                        <th style="width: 120px;">Пользователь</th>
                        <th>Действие</th>
                        <th>Объект</th>
                        <th>Детали</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $entry): ?>
                    <tr>
                        <td class="text-muted small">
                            <?= formatDate($entry['created_at']) ?>
                        </td>
                        <td>
                            <span class="badge bg-secondary">
                                <?= e($entry['user_name'] ?? 'Система') ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $actionClass = match($entry['action']) {
                                'login', 'logout' => 'text-info',
                                'create_product' => 'text-success',
                                'update_product', 'update_stock' => 'text-warning',
                                'delete_product' => 'text-danger',
                                'wb_update_prices', 'ozon_update_prices' => 'text-primary',
                                default => ''
                            };
                            ?>
                            <span class="<?= $actionClass ?>">
                                <?= e(OperationsLog::formatAction($entry['action'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-dark">
                                <?= e($entry['entity_type']) ?>
                                <?php if ($entry['entity_id']): ?>
                                    #<?= e($entry['entity_id']) ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="small">
                            <?php if ($entry['new_values']): ?>
                                <button class="btn btn-sm btn-outline-secondary"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#details-<?= $entry['id'] ?>">
                                    <i class="bi bi-eye"></i>
                                </button>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($entry['new_values']): ?>
                    <tr class="collapse" id="details-<?= $entry['id'] ?>">
                        <td colspan="5" class="bg-dark">
                            <pre class="mb-0 small text-muted"><?= e(json_encode($entry['new_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include VIEWS_PATH . '/layout/footer.php'; ?>
