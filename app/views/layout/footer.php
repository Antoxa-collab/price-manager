            </div>
        </main>
    </div>

    <!-- Футер -->
    <footer class="footer bg-dark border-top border-secondary py-3 mt-auto">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    <span class="text-muted">
                        <?= APP_NAME ?> v<?= APP_VERSION ?> &copy; <?= date('Y') ?>
                    </span>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <span class="text-muted">
                        <i class="bi bi-clock me-1"></i>
                        <?= date('d.m.Y H:i') ?>
                    </span>
                </div>
            </div>
        </div>
    </footer>

    <!-- Модальное окно загрузки -->
    <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content bg-dark">
                <div class="modal-body text-center py-4">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <p class="mb-0" id="loadingMessage">Загрузка...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно подтверждения -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="confirmTitle">Подтверждение</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="confirmMessage">
                    Вы уверены?
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" id="confirmButton">Подтвердить</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery 3.7 -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

    <!-- Bootstrap 5.3 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom JS -->
    <script src="/js/app.js"></script>

    <!-- Справочник раскроя листов -->
    <script src="/js/cutting-reference.js"></script>

    <?php if (isset($pageScript)): ?>
    <script src="/js/<?= e($pageScript) ?>.js"></script>
    <?php endif; ?>

    <!-- CSRF токен для AJAX -->
    <script>
        window.csrfToken = '<?= e(generateCsrfToken()) ?>';
    </script>
</body>
</html>
