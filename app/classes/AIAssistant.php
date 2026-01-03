<?php
/**
 * AI Assistant для автоматизации ответов на отзывы и вопросы
 * Использует Claude API для генерации ответов
 */
class AIAssistant
{
    private Database $db;
    private ?ClaudeAPI $claude = null;
    private string $marketplace;

    /**
     * Конструктор
     * @param string $marketplace Маркетплейс (ozon, wildberries, yandex)
     */
    public function __construct(string $marketplace = 'ozon')
    {
        $this->db = Database::getInstance();
        $this->marketplace = $marketplace;
    }

    /**
     * Инициализация Claude API с ключом из настроек
     * @return bool
     */
    public function initClaude(): bool
    {
        $apiKey = $this->getClaudeApiKey();
        if (empty($apiKey)) {
            return false;
        }

        $settings = $this->getSettings();

        $this->claude = new ClaudeAPI($apiKey, [
            'model' => $settings['model'] ?? 'claude-3-haiku-20240307',
            'max_tokens' => (int)($settings['max_tokens'] ?? 1024),
            'temperature' => (float)($settings['temperature'] ?? 0.7)
        ]);

        return true;
    }

    /**
     * Получить API ключ Claude из настроек пользователя
     * @return string|null
     */
    private function getClaudeApiKey(): ?string
    {
        // Получаем из таблицы user_api_keys
        $result = $this->db->fetchOne(
            "SELECT api_key FROM user_api_keys WHERE service = 'claude' AND is_active = 1 LIMIT 1"
        );

        return $result['api_key'] ?? null;
    }

    /**
     * Получить настройки AI
     * @return array
     */
    public function getSettings(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM ai_settings
             WHERE marketplace IN ('all', ?)
             ORDER BY FIELD(marketplace, ?, 'all')",
            [$this->marketplace, $this->marketplace]
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // Значения по умолчанию
        return array_merge([
            'model' => 'claude-3-haiku-20240307',
            'max_tokens' => '1024',
            'temperature' => '0.7',
            'moderation_enabled' => '1',
            'auto_generate' => '0',
            'store_name' => 'Наш магазин',
            'store_signature' => 'С уважением, команда магазина'
        ], $settings);
    }

    /**
     * Сохранить настройку
     * @param string $key Ключ настройки
     * @param string $value Значение
     * @param string $marketplace Маркетплейс (all для глобальных)
     * @return bool
     */
    public function saveSetting(string $key, string $value, string $marketplace = 'all'): bool
    {
        try {
            error_log("[AI Settings] Saving: key=$key, marketplace=$marketplace, value=" . mb_substr($value, 0, 50));
            $this->db->query(
                "INSERT INTO ai_settings (setting_key, setting_value, marketplace)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?",
                [$key, $value, $marketplace, $value]
            );
            error_log("[AI Settings] Saved successfully");
            return true;
        } catch (Exception $e) {
            error_log("[AI Settings] ERROR saving: " . $e->getMessage());
            return false;
        }
    }

    // ==================== ПРОМПТЫ ====================

    /**
     * Получить промпт по ID
     * @param int $id
     * @return array|null
     */
    public function getPrompt(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM ai_prompts WHERE id = ?",
            [$id]
        );
    }

    /**
     * Получить промпт по типу и тональности
     * @param string $type review или question
     * @param string|null $sentiment positive, negative, neutral
     * @return array|null
     */
    public function getPromptByType(string $type, ?string $sentiment = null): ?array
    {
        $sql = "SELECT * FROM ai_prompts
                WHERE marketplace = ? AND type = ? AND is_active = 1";
        $params = [$this->marketplace, $type];

        if ($sentiment !== null) {
            $sql .= " AND sentiment = ?";
            $params[] = $sentiment;
        }

        $sql .= " ORDER BY is_default DESC LIMIT 1";

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Получить все промпты
     * @param string|null $type Фильтр по типу
     * @return array
     */
    public function getPrompts(?string $type = null): array
    {
        $sql = "SELECT * FROM ai_prompts WHERE marketplace = ?";
        $params = [$this->marketplace];

        if ($type !== null) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }

        $sql .= " ORDER BY type, sentiment, name";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Сохранить промпт
     * @param array $data Данные промпта
     * @return int ID промпта
     */
    public function savePrompt(array $data): int
    {
        $data['marketplace'] = $this->marketplace;

        if (isset($data['id']) && $data['id'] > 0) {
            $id = (int)$data['id'];
            unset($data['id']);
            $this->db->update('ai_prompts', $data, 'id = ?', [$id]);
            return $id;
        } else {
            unset($data['id']);
            return $this->db->insert('ai_prompts', $data);
        }
    }

    /**
     * Удалить промпт
     * @param int $id
     * @return bool
     */
    public function deletePrompt(int $id): bool
    {
        return $this->db->delete('ai_prompts', 'id = ?', [$id]) > 0;
    }

    // ==================== ПРИМЕРЫ ====================

    /**
     * Получить примеры для промпта
     * @param int $promptId
     * @return array
     */
    public function getExamples(int $promptId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM ai_examples WHERE prompt_id = ? AND is_active = 1 ORDER BY id",
            [$promptId]
        );
    }

    /**
     * Сохранить пример
     * @param array $data
     * @return int
     */
    public function saveExample(array $data): int
    {
        if (isset($data['id']) && $data['id'] > 0) {
            $id = (int)$data['id'];
            unset($data['id']);
            $this->db->update('ai_examples', $data, 'id = ?', [$id]);
            return $id;
        } else {
            unset($data['id']);
            return $this->db->insert('ai_examples', $data);
        }
    }

    /**
     * Удалить пример
     * @param int $id
     * @return bool
     */
    public function deleteExample(int $id): bool
    {
        return $this->db->delete('ai_examples', 'id = ?', [$id]) > 0;
    }

    // ==================== ЗНАНИЯ О ТОВАРАХ ====================

    /**
     * Получить знания о товаре
     * @param int|null $productId
     * @param string|null $marketplaceProductId
     * @return array
     */
    public function getProductKnowledge(?int $productId = null, ?string $marketplaceProductId = null): array
    {
        $sql = "SELECT * FROM ai_product_knowledge WHERE is_active = 1";
        $params = [];

        if ($productId !== null) {
            $sql .= " AND (product_id = ? OR product_id IS NULL)";
            $params[] = $productId;
        }

        if ($marketplaceProductId !== null) {
            $sql .= " AND (marketplace_product_id = ? OR marketplace_product_id IS NULL)";
            $params[] = $marketplaceProductId;
        }

        $sql .= " ORDER BY product_id IS NULL, knowledge_type, id";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Собрать контекст знаний для генерации
     * @param int|null $productId
     * @param string|null $marketplaceProductId
     * @return string
     */
    public function buildKnowledgeContext(?int $productId = null, ?string $marketplaceProductId = null): string
    {
        $knowledge = $this->getProductKnowledge($productId, $marketplaceProductId);

        if (empty($knowledge)) {
            return '';
        }

        $context = "\n\nИнформация о товаре:\n";

        $grouped = [];
        foreach ($knowledge as $item) {
            $grouped[$item['knowledge_type']][] = $item;
        }

        $typeNames = [
            'description' => 'Описание',
            'specs' => 'Характеристики',
            'faq' => 'Частые вопросы',
            'note' => 'Заметки'
        ];

        foreach ($grouped as $type => $items) {
            $context .= "\n" . ($typeNames[$type] ?? $type) . ":\n";
            foreach ($items as $item) {
                if (!empty($item['title'])) {
                    $context .= "- {$item['title']}: {$item['content']}\n";
                } else {
                    $context .= "- {$item['content']}\n";
                }
            }
        }

        return $context;
    }

    // ==================== ОТЗЫВЫ ====================

    /**
     * Получить отзывы с фильтрацией
     * @param array $filters
     * @return array
     */
    public function getReviews(array $filters = []): array
    {
        $sql = "SELECT r.*, COALESCE(r.product_name, p.name) as product_name
                FROM ai_reviews r
                LEFT JOIN products p ON r.product_id = p.id
                WHERE r.marketplace = ?";
        $params = [$this->marketplace];

        if (!empty($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['rating'])) {
            $sql .= " AND r.rating = ?";
            $params[] = $filters['rating'];
        }

        if (!empty($filters['product_id'])) {
            $sql .= " AND r.product_id = ?";
            $params[] = $filters['product_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (r.review_text LIKE ? OR r.author_name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY r.review_date DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Получить отзыв по ID
     * @param int $id
     * @return array|null
     */
    public function getReview(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT r.*, COALESCE(r.product_name, p.name) as product_name
             FROM ai_reviews r
             LEFT JOIN products p ON r.product_id = p.id
             WHERE r.id = ?",
            [$id]
        );
    }

    /**
     * Синхронизировать отзывы с маркетплейса
     * @param array $reviews Отзывы из API маркетплейса
     * @return array Статистика синхронизации
     */
    public function syncReviews(array $reviews): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($reviews as $review) {
            $existing = $this->db->fetchOne(
                "SELECT id, status FROM ai_reviews
                 WHERE marketplace = ? AND marketplace_review_id = ?",
                [$this->marketplace, $review['marketplace_review_id']]
            );

            if ($existing) {
                // Не обновляем если уже обработан
                if (in_array($existing['status'], ['approved', 'sent'])) {
                    $stats['skipped']++;
                    continue;
                }

                $this->db->update('ai_reviews', [
                    'author_name' => $review['author_name'] ?? null,
                    'rating' => $review['rating'] ?? null,
                    'review_text' => $review['review_text'] ?? null,
                    'review_pros' => $review['review_pros'] ?? null,
                    'review_cons' => $review['review_cons'] ?? null,
                    'review_date' => $review['review_date'] ?? null,
                    'marketplace_product_id' => $review['marketplace_product_id'] ?? null,
                    'product_id' => $review['product_id'] ?? null
                ], 'id = ?', [$existing['id']]);

                $stats['updated']++;
            } else {
                $this->db->insert('ai_reviews', [
                    'marketplace' => $this->marketplace,
                    'marketplace_review_id' => $review['marketplace_review_id'],
                    'marketplace_product_id' => $review['marketplace_product_id'] ?? null,
                    'product_id' => $review['product_id'] ?? null,
                    'author_name' => $review['author_name'] ?? null,
                    'rating' => $review['rating'] ?? null,
                    'review_text' => $review['review_text'] ?? null,
                    'review_pros' => $review['review_pros'] ?? null,
                    'review_cons' => $review['review_cons'] ?? null,
                    'review_date' => $review['review_date'] ?? null,
                    'status' => 'new'
                ]);

                $stats['added']++;
            }
        }

        return $stats;
    }

    /**
     * Генерировать ответ на отзыв
     * @param int $reviewId
     * @param int|null $promptId Конкретный промпт (опционально)
     * @return array Результат генерации
     */
    public function generateReviewResponse(int $reviewId, ?int $promptId = null): array
    {
        error_log("========== AI GENERATION START (Review #{$reviewId}) ==========");

        $review = $this->getReview($reviewId);
        if (!$review) {
            error_log("[AI] ERROR: Review not found");
            return ['success' => false, 'error' => 'Отзыв не найден'];
        }

        error_log("[AI] Review data:");
        error_log("[AI]   - Marketplace: {$this->marketplace}");
        error_log("[AI]   - Rating: {$review['rating']}");
        error_log("[AI]   - Author: " . ($review['author_name'] ?? 'N/A'));
        error_log("[AI]   - Product ID: " . ($review['marketplace_product_id'] ?? 'N/A'));
        error_log("[AI]   - Product Name: " . ($review['product_name'] ?? 'N/A'));
        error_log("[AI]   - Review text (100 chars): " . mb_substr($review['review_text'] ?? '', 0, 100));

        if (!$this->initClaude()) {
            error_log("[AI] ERROR: Claude API not configured");
            return ['success' => false, 'error' => 'Claude API не настроен'];
        }

        // Определяем тональность по рейтингу
        $sentiment = $this->determineSentiment($review['rating']);
        error_log("[AI] Sentiment determined: {$sentiment} (from rating {$review['rating']})");

        // Получаем промпт
        if ($promptId) {
            $prompt = $this->getPrompt($promptId);
            error_log("[AI] Using explicit prompt ID: {$promptId}");
        } else {
            $prompt = $this->getPromptByType('review', $sentiment);
            error_log("[AI] Searching prompt: type=review, sentiment={$sentiment}, marketplace={$this->marketplace}");
        }

        if (!$prompt) {
            error_log("[AI] ERROR: No prompt found for sentiment={$sentiment}");
            return ['success' => false, 'error' => 'Промпт не найден для тональности: ' . $sentiment];
        }

        error_log("[AI] Selected prompt:");
        error_log("[AI]   - ID: {$prompt['id']}");
        error_log("[AI]   - Name: {$prompt['name']}");
        error_log("[AI]   - Sentiment: " . ($prompt['sentiment'] ?? 'NULL'));
        error_log("[AI]   - Is Default: " . ($prompt['is_default'] ? 'Yes' : 'No'));
        error_log("[AI]   - System prompt (200 chars): " . mb_substr($prompt['system_prompt'], 0, 200));

        // Получаем примеры
        $examples = $this->getExamples($prompt['id']);
        error_log("[AI] Examples (few-shot) count: " . count($examples));
        $examplesForApi = [];
        foreach ($examples as $idx => $ex) {
            error_log("[AI]   Example #{$idx}: input=" . mb_substr($ex['input_text'], 0, 50) . "...");
            $examplesForApi[] = [
                'input' => $ex['input_text'],
                'output' => $ex['output_text']
            ];
        }

        // Собираем контекст знаний о товаре
        $knowledgeContext = $this->buildKnowledgeContext(
            $review['product_id'],
            $review['marketplace_product_id']
        );
        error_log("[AI] Knowledge context length: " . strlen($knowledgeContext) . " chars");
        if ($knowledgeContext) {
            error_log("[AI] Knowledge context (300 chars): " . mb_substr($knowledgeContext, 0, 300));
        }

        // Собираем текст отзыва
        $reviewText = $this->formatReviewText($review);

        // Формируем информацию о товаре
        $productInfo = $this->buildProductInfo($review);
        error_log("[AI] Product info length: " . strlen($productInfo) . " chars");
        if ($productInfo) {
            error_log("[AI] Product info (300 chars): " . mb_substr($productInfo, 0, 300));
        }

        // Подставляем переменные в шаблон
        $settings = $this->getSettings();
        $userPrompt = $this->replaceTemplateVars($prompt['user_prompt_template'], [
            'review_text' => $reviewText,
            'author_name' => $review['author_name'] ?? 'Покупатель',
            'rating' => $review['rating'] ?? '?',
            'product_name' => $review['product_name'] ?? 'товар',
            'product_article' => $review['product_article'] ?? '',
            'product_info' => $productInfo,
            'knowledge' => $knowledgeContext,
            'store_name' => $settings['store_name'],
            'store_signature' => $settings['store_signature']
        ]);

        error_log("[AI] Final user prompt (500 chars): " . mb_substr($userPrompt, 0, 500));
        error_log("========== SENDING TO CLAUDE ==========");

        // Обновляем статус
        $this->db->update('ai_reviews', ['status' => 'generating'], 'id = ?', [$reviewId]);

        // Генерируем ответ
        $response = $this->claude->generate(
            $prompt['system_prompt'],
            $userPrompt,
            $examplesForApi
        );

        // Логируем генерацию
        $this->logGeneration('review', $reviewId, $prompt['id'], $response !== null);

        // Сохраняем метаданные генерации
        $generationMeta = [
            'prompt_id' => $prompt['id'],
            'prompt_name' => $prompt['name'],
            'prompt_sentiment' => $prompt['sentiment'],
            'detected_sentiment' => $sentiment,
            'examples_count' => count($examples),
            'product_info_used' => !empty($productInfo),
            'knowledge_used' => !empty($knowledgeContext),
            'model' => $this->claude->getModel(),
            'generated_at' => date('Y-m-d H:i:s')
        ];
        error_log("[AI] Generation meta: " . json_encode($generationMeta, JSON_UNESCAPED_UNICODE));

        if ($response === null) {
            error_log("[AI] ERROR: Generation failed - " . $this->claude->getLastError());
            error_log("========== AI GENERATION END (FAILED) ==========");

            $this->db->update('ai_reviews', [
                'status' => 'error',
                'error_message' => $this->claude->getLastError()
            ], 'id = ?', [$reviewId]);

            return ['success' => false, 'error' => $this->claude->getLastError()];
        }

        error_log("[AI] SUCCESS: Response generated (" . strlen($response) . " chars)");
        error_log("[AI] Response (200 chars): " . mb_substr($response, 0, 200));
        error_log("========== AI GENERATION END (SUCCESS) ==========");

        // Сохраняем результат с метаданными
        $this->db->update('ai_reviews', [
            'status' => 'generated',
            'generated_response' => $response,
            'prompt_id' => $prompt['id'],
            'tokens_used' => $this->claude->getLastUsage()['total_tokens'] ?? null,
            'generation_meta' => json_encode($generationMeta, JSON_UNESCAPED_UNICODE),
            'error_message' => null
        ], 'id = ?', [$reviewId]);

        return [
            'success' => true,
            'response' => $response,
            'tokens' => $this->claude->getLastUsage(),
            'generation_time' => $this->claude->getLastGenerationTime(),
            'generation_meta' => $generationMeta
        ];
    }

    /**
     * Одобрить ответ на отзыв
     * @param int $reviewId
     * @param string|null $editedResponse Отредактированный ответ
     * @return bool
     */
    public function approveReviewResponse(int $reviewId, ?string $editedResponse = null): bool
    {
        $data = ['status' => 'approved'];

        if ($editedResponse !== null) {
            $data['edited_response'] = $editedResponse;
        }

        return $this->db->update('ai_reviews', $data, 'id = ?', [$reviewId]) > 0;
    }

    /**
     * Пропустить отзыв
     * @param int $reviewId
     * @return bool
     */
    public function skipReview(int $reviewId): bool
    {
        return $this->db->update('ai_reviews', ['status' => 'skipped'], 'id = ?', [$reviewId]) > 0;
    }

    /**
     * Отметить ответ как отправленный
     * @param int $reviewId
     * @param string $sentResponse
     * @return bool
     */
    public function markReviewSent(int $reviewId, string $sentResponse): bool
    {
        return $this->db->update('ai_reviews', [
            'status' => 'sent',
            'sent_response' => $sentResponse,
            'sent_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$reviewId]) > 0;
    }

    // ==================== ВОПРОСЫ ====================

    /**
     * Получить вопросы с фильтрацией
     * @param array $filters
     * @return array
     */
    public function getQuestions(array $filters = []): array
    {
        $sql = "SELECT q.*, COALESCE(q.product_name, p.name) as product_name
                FROM ai_questions q
                LEFT JOIN products p ON q.product_id = p.id
                WHERE q.marketplace = ?";
        $params = [$this->marketplace];

        if (!empty($filters['status'])) {
            $sql .= " AND q.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['product_id'])) {
            $sql .= " AND q.product_id = ?";
            $params[] = $filters['product_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (q.question_text LIKE ? OR q.author_name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY q.question_date DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Получить вопрос по ID
     * @param int $id
     * @return array|null
     */
    public function getQuestion(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT q.*, COALESCE(q.product_name, p.name) as product_name
             FROM ai_questions q
             LEFT JOIN products p ON q.product_id = p.id
             WHERE q.id = ?",
            [$id]
        );
    }

    /**
     * Синхронизировать вопросы с маркетплейса
     * @param array $questions Вопросы из API маркетплейса
     * @return array Статистика синхронизации
     */
    public function syncQuestions(array $questions): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($questions as $question) {
            $existing = $this->db->fetchOne(
                "SELECT id, status FROM ai_questions
                 WHERE marketplace = ? AND marketplace_question_id = ?",
                [$this->marketplace, $question['marketplace_question_id']]
            );

            if ($existing) {
                if (in_array($existing['status'], ['approved', 'sent'])) {
                    $stats['skipped']++;
                    continue;
                }

                $this->db->update('ai_questions', [
                    'author_name' => $question['author_name'] ?? null,
                    'question_text' => $question['question_text'],
                    'question_date' => $question['question_date'] ?? null,
                    'marketplace_product_id' => $question['marketplace_product_id'] ?? null,
                    'product_id' => $question['product_id'] ?? null
                ], 'id = ?', [$existing['id']]);

                $stats['updated']++;
            } else {
                $this->db->insert('ai_questions', [
                    'marketplace' => $this->marketplace,
                    'marketplace_question_id' => $question['marketplace_question_id'],
                    'marketplace_product_id' => $question['marketplace_product_id'] ?? null,
                    'product_id' => $question['product_id'] ?? null,
                    'author_name' => $question['author_name'] ?? null,
                    'question_text' => $question['question_text'],
                    'question_date' => $question['question_date'] ?? null,
                    'status' => 'new'
                ]);

                $stats['added']++;
            }
        }

        return $stats;
    }

    /**
     * Генерировать ответ на вопрос
     * @param int $questionId
     * @param int|null $promptId
     * @return array
     */
    public function generateQuestionResponse(int $questionId, ?int $promptId = null): array
    {
        $question = $this->getQuestion($questionId);
        if (!$question) {
            return ['success' => false, 'error' => 'Вопрос не найден'];
        }

        if (!$this->initClaude()) {
            return ['success' => false, 'error' => 'Claude API не настроен'];
        }

        // Получаем промпт
        if ($promptId) {
            $prompt = $this->getPrompt($promptId);
        } else {
            $prompt = $this->getPromptByType('question');
        }

        if (!$prompt) {
            return ['success' => false, 'error' => 'Промпт для вопросов не найден'];
        }

        // Получаем примеры
        $examples = $this->getExamples($prompt['id']);
        $examplesForApi = [];
        foreach ($examples as $ex) {
            $examplesForApi[] = [
                'input' => $ex['input_text'],
                'output' => $ex['output_text']
            ];
        }

        // Собираем контекст знаний о товаре
        $knowledgeContext = $this->buildKnowledgeContext(
            $question['product_id'],
            $question['marketplace_product_id']
        );
        error_log("[AI Question] Knowledge context length: " . strlen($knowledgeContext) . " chars");

        // Формируем информацию о товаре
        $productInfo = $this->buildProductInfo($question);
        error_log("[AI Question] Product info length: " . strlen($productInfo) . " chars");
        if ($productInfo) {
            error_log("[AI Question] Product info (500 chars): " . mb_substr($productInfo, 0, 500));
        } else {
            error_log("[AI Question] WARNING: No product info available for nmId: " . ($question['marketplace_product_id'] ?? 'NULL'));
        }

        // Подставляем переменные
        $settings = $this->getSettings();
        $userPrompt = $this->replaceTemplateVars($prompt['user_prompt_template'], [
            'question_text' => $question['question_text'],
            'author_name' => $question['author_name'] ?? 'Покупатель',
            'product_name' => $question['product_name'] ?? 'товар',
            'product_article' => $question['product_article'] ?? '',
            'product_info' => $productInfo,
            'knowledge' => $knowledgeContext,
            'store_name' => $settings['store_name'],
            'store_signature' => $settings['store_signature']
        ]);

        error_log("[AI Question] Final user prompt (800 chars): " . mb_substr($userPrompt, 0, 800));

        // Обновляем статус
        $this->db->update('ai_questions', ['status' => 'generating'], 'id = ?', [$questionId]);

        // Генерируем ответ
        $response = $this->claude->generate(
            $prompt['system_prompt'],
            $userPrompt,
            $examplesForApi
        );

        // Логируем генерацию
        $this->logGeneration('question', $questionId, $prompt['id'], $response !== null);

        if ($response === null) {
            $this->db->update('ai_questions', [
                'status' => 'error',
                'error_message' => $this->claude->getLastError()
            ], 'id = ?', [$questionId]);

            return ['success' => false, 'error' => $this->claude->getLastError()];
        }

        // Сохраняем результат
        $this->db->update('ai_questions', [
            'status' => 'generated',
            'generated_response' => $response,
            'prompt_id' => $prompt['id'],
            'tokens_used' => $this->claude->getLastUsage()['total_tokens'] ?? null,
            'error_message' => null
        ], 'id = ?', [$questionId]);

        return [
            'success' => true,
            'response' => $response,
            'tokens' => $this->claude->getLastUsage(),
            'generation_time' => $this->claude->getLastGenerationTime()
        ];
    }

    /**
     * Одобрить ответ на вопрос
     * @param int $questionId
     * @param string|null $editedResponse
     * @return bool
     */
    public function approveQuestionResponse(int $questionId, ?string $editedResponse = null): bool
    {
        $data = ['status' => 'approved'];

        if ($editedResponse !== null) {
            $data['edited_response'] = $editedResponse;
        }

        return $this->db->update('ai_questions', $data, 'id = ?', [$questionId]) > 0;
    }

    /**
     * Пропустить вопрос
     * @param int $questionId
     * @return bool
     */
    public function skipQuestion(int $questionId): bool
    {
        return $this->db->update('ai_questions', ['status' => 'skipped'], 'id = ?', [$questionId]) > 0;
    }

    /**
     * Отметить ответ на вопрос как отправленный
     * @param int $questionId
     * @param string $sentResponse
     * @return bool
     */
    public function markQuestionSent(int $questionId, string $sentResponse): bool
    {
        return $this->db->update('ai_questions', [
            'status' => 'sent',
            'sent_response' => $sentResponse,
            'sent_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$questionId]) > 0;
    }

    // ==================== СТАТИСТИКА ====================

    /**
     * Получить статистику по отзывам и вопросам
     * @return array
     */
    public function getStatistics(): array
    {
        $reviewStats = $this->db->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(status = 'new') as new_count,
                SUM(status = 'generated') as generated_count,
                SUM(status = 'approved') as approved_count,
                SUM(status = 'sent') as sent_count,
                SUM(status = 'error') as error_count,
                SUM(tokens_used) as total_tokens
             FROM ai_reviews WHERE marketplace = ?",
            [$this->marketplace]
        );

        $questionStats = $this->db->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(status = 'new') as new_count,
                SUM(status = 'generated') as generated_count,
                SUM(status = 'approved') as approved_count,
                SUM(status = 'sent') as sent_count,
                SUM(status = 'error') as error_count,
                SUM(tokens_used) as total_tokens
             FROM ai_questions WHERE marketplace = ?",
            [$this->marketplace]
        );

        return [
            'reviews' => $reviewStats,
            'questions' => $questionStats,
            'total_tokens' => ($reviewStats['total_tokens'] ?? 0) + ($questionStats['total_tokens'] ?? 0)
        ];
    }

    // ==================== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ====================

    /**
     * Определить тональность по рейтингу
     * @param int|null $rating
     * @return string
     */
    private function determineSentiment(?int $rating): string
    {
        if ($rating === null) {
            return 'neutral';
        }

        if ($rating >= 4) {
            return 'positive';
        } elseif ($rating <= 2) {
            return 'negative';
        }

        return 'neutral';
    }

    /**
     * Формировать информацию о товаре для промпта
     * Сначала пробует получить из базы знаний (там полная информация),
     * если нет — использует данные из самого вопроса/отзыва
     *
     * @param array $item Отзыв или вопрос
     * @return string
     */
    private function buildProductInfo(array $item): string
    {
        // Сначала пробуем получить из базы знаний (там полная информация)
        if (!empty($item['marketplace_product_id'])) {
            try {
                require_once __DIR__ . '/ProductKnowledge.php';
                $productKnowledge = new ProductKnowledge();
                $context = $productKnowledge->getProductContextForAI($item['marketplace_product_id']);

                if (!empty($context)) {
                    return $context;
                }
            } catch (Exception $e) {
                // Если не удалось получить из базы знаний — используем данные из item
                error_log("[AIAssistant] Ошибка получения контекста товара: " . $e->getMessage());
            }
        }

        // Fallback: используем данные из самого вопроса/отзыва
        $parts = [];

        if (!empty($item['product_name'])) {
            $parts[] = "Название: " . $item['product_name'];
        }

        if (!empty($item['product_article'])) {
            $parts[] = "Артикул: " . $item['product_article'];
        }

        if (!empty($item['marketplace_product_id'])) {
            $parts[] = "ID на маркетплейсе: " . $item['marketplace_product_id'];
        }

        if (empty($parts)) {
            return '';
        }

        return "Информация о товаре:\n" . implode("\n", $parts);
    }

    /**
     * Форматировать текст отзыва
     * @param array $review
     * @return string
     */
    private function formatReviewText(array $review): string
    {
        $parts = [];

        if (!empty($review['review_text'])) {
            $parts[] = $review['review_text'];
        }

        if (!empty($review['review_pros'])) {
            $parts[] = "Достоинства: " . $review['review_pros'];
        }

        if (!empty($review['review_cons'])) {
            $parts[] = "Недостатки: " . $review['review_cons'];
        }

        return implode("\n", $parts);
    }

    /**
     * Заменить переменные в шаблоне
     * @param string $template
     * @param array $vars
     * @return string
     */
    private function replaceTemplateVars(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value ?? '', $template);
        }

        return $template;
    }

    /**
     * Логировать генерацию
     * @param string $type
     * @param int $itemId
     * @param int|null $promptId
     * @param bool $success
     */
    private function logGeneration(string $type, int $itemId, ?int $promptId, bool $success): void
    {
        $usage = $this->claude->getLastUsage();

        $this->db->insert('ai_generation_log', [
            'type' => $type,
            'item_id' => $itemId,
            'prompt_id' => $promptId,
            'model' => $this->claude->getModel(),
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'generation_time_ms' => $this->claude->getLastGenerationTime(),
            'status' => $success ? 'success' : 'error',
            'error_message' => $success ? null : $this->claude->getLastError()
        ]);
    }

    /**
     * Создать промпты по умолчанию
     * @return void
     */
    public function createDefaultPrompts(): void
    {
        // Проверяем есть ли уже промпты
        $existing = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM ai_prompts WHERE marketplace = ?",
            [$this->marketplace]
        );

        if ($existing > 0) {
            return;
        }

        // Промпт для положительных отзывов
        $this->savePrompt([
            'type' => 'review',
            'sentiment' => 'positive',
            'name' => 'Ответ на положительный отзыв',
            'is_default' => 1,
            'system_prompt' => 'Ты — вежливый представитель интернет-магазина. Твоя задача — написать благодарственный ответ на положительный отзыв покупателя.

Правила:
- Поблагодари за покупку и отзыв
- Упомяни конкретные плюсы, которые отметил покупатель
- Пожелай приятного использования
- Пригласи за новыми покупками
- Ответ должен быть 2-4 предложения
- Не используй восклицательные знаки слишком часто
- Будь искренним, не используй шаблонные фразы',
            'user_prompt_template' => 'Напиши ответ на отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}'
        ]);

        // Промпт для негативных отзывов
        $this->savePrompt([
            'type' => 'review',
            'sentiment' => 'negative',
            'name' => 'Ответ на негативный отзыв',
            'is_default' => 1,
            'system_prompt' => 'Ты — вежливый представитель интернет-магазина. Твоя задача — написать конструктивный ответ на негативный отзыв покупателя.

Правила:
- Извинись за неудобства
- Прояви понимание проблемы покупателя
- Предложи решение или попроси связаться для решения
- НЕ оправдывайся и не спорь
- Ответ должен быть 3-5 предложений
- Будь вежливым и профессиональным',
            'user_prompt_template' => 'Напиши ответ на негативный отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}'
        ]);

        // Промпт для нейтральных отзывов
        $this->savePrompt([
            'type' => 'review',
            'sentiment' => 'neutral',
            'name' => 'Ответ на нейтральный отзыв',
            'is_default' => 1,
            'system_prompt' => 'Ты — вежливый представитель интернет-магазина. Твоя задача — написать ответ на нейтральный отзыв покупателя.

Правила:
- Поблагодари за обратную связь
- Отметь положительные моменты, если есть
- Если есть критика — учти её и предложи помощь
- Ответ должен быть 2-4 предложения',
            'user_prompt_template' => 'Напиши ответ на отзыв от {{author_name}} (оценка: {{rating}}/5):

{{review_text}}
{{knowledge}}

Подпись: {{store_signature}}'
        ]);

        // Промпт для вопросов
        $this->savePrompt([
            'type' => 'question',
            'sentiment' => null,
            'name' => 'Ответ на вопрос покупателя',
            'is_default' => 1,
            'system_prompt' => 'Ты — консультант интернет-магазина строительных материалов.

ГЛАВНОЕ ПРАВИЛО: Все данные о товаре (вес, размеры, количество, толщина) бери ТОЛЬКО из раздела "ДАННЫЕ О ТОВАРЕ" ниже. НИКОГДА не выдумывай характеристики!

Правила ответа:
1. Вес, размеры, количество — ТОЛЬКО из данных о товаре
2. При расчётах площади — используй размеры из данных и показывай формулу
3. Округляй количество упаковок ВВЕРХ (с запасом на подрезку)
4. Если данных нет — честно скажи "уточните у менеджера"
5. Ответ краткий: 2-4 предложения
6. В конце ВСЕГДА добавляй подпись (из контекста)

Телефон для связи пиши БУКВАМИ: восемь-девятьсот двадцать-семьсот шестьдесят шесть-сорок шесть-шестьдесят два

ФОРМУЛЫ:
- Площадь листа = длина × ширина (в метрах)
- Площадь упаковки = площадь листа × кол-во листов
- Нужно упаковок = ваша площадь ÷ площадь упаковки (округлить вверх)',
            'user_prompt_template' => '{{product_info}}

{{knowledge}}

ВОПРОС ПОКУПАТЕЛЯ:
{{question_text}}

Подпись: {{store_signature}}'
        ]);
    }
}
