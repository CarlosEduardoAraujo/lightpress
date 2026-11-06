<?php
declare(strict_types=1);

namespace LightPress\Core;

use RuntimeException;

/**
 * Serviço Editorial e Gestor de Ciclo de Vida de Publicações
 * 
 * Gerencia a criação atômica de artigos, isolamento de estatísticas (`post_stats`),
 * histórico de revisões (`post_revisions`), detecção de conflitos de gravação (HTTP 409)
 * e acionamento de jobs de purga de cache.
 */
class PostService
{
    public const RESERVED_SLUGS = [
        'admin', 'api', 'uploads', 'assets', 'storage', 'busca', 'feed',
        'feed.xml', 'sitemap', 'sitemap.xml', 'sitemap-main.xml', 'page', 'categoria', 'tag', 'noticia'
    ];

    private CacheManager $cacheManager;

    public function __construct(?CacheManager $cacheManager = null)
    {
        $this->cacheManager = $cacheManager ?? new CacheManager();
    }

    private static bool $enumChecked = false;

    /**
     * Garante que as colunas ENUM suportam 'scheduled' e 'pending_review' no MySQL.
     * Auto-migra instantaneamente caso o banco de produção esteja com schema legado.
     */
    private function ensureScheduledEnumSupported(): void
    {
        if (self::$enumChecked || Database::getDriver() !== 'mysql') {
            return;
        }

        try {
            $col = Database::fetchOne("SHOW COLUMNS FROM posts LIKE 'status'");
            if ($col && !empty($col['Type']) && !str_contains($col['Type'], "'scheduled'")) {
                Database::getConnection()->exec(
                    "ALTER TABLE posts MODIFY COLUMN status ENUM('draft', 'pending_review', 'scheduled', 'published', 'archived') NOT NULL DEFAULT 'draft'"
                );
            }

            $revCol = Database::fetchOne("SHOW COLUMNS FROM post_revisions LIKE 'status'");
            if ($revCol && !empty($revCol['Type']) && !str_contains($revCol['Type'], "'pending_review'")) {
                Database::getConnection()->exec(
                    "ALTER TABLE post_revisions MODIFY COLUMN status ENUM('draft', 'pending_review', 'applied') NOT NULL DEFAULT 'draft'"
                );
            }

            self::$enumChecked = true;
        } catch (\Throwable $e) {
            // Silencioso se não tiver permissão DDL
        }
    }

    /**
     * Promove automaticamente matérias agendadas cujo horário de publicação foi atingido
     * e dispara a invalidação de cache em disco e na borda Cloudflare.
     */
    public static function promoteScheduledPosts(): int
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        try {
            // Transação atômica com FOR UPDATE para bloquear e promover exclusivamente os artigos vencidos
            // evitando qualquer duplicidade em acessos concorrentes ou crons simultâneos
            $duePosts = Database::transaction(function (\PDO $pdo) use ($nowUtc): array {
                $posts = Database::fetchAll(
                    "SELECT id, slug, title, summary, category, tags, featured_image, content_html, published_at
                     FROM posts 
                     WHERE status = 'scheduled' AND published_at <= ?" . (Database::getDriver() === 'mysql' ? ' FOR UPDATE' : ''),
                    [$nowUtc]
                );

                if (empty($posts)) {
                    return [];
                }

                $ids = array_column($posts, 'id');
                $inClause = implode(',', array_map('intval', $ids));
                Database::query("UPDATE posts SET status = 'published' WHERE id IN ({$inClause})");

                return $posts;
            });

            if (empty($duePosts)) {
                return 0;
            }

            $cacheManager = new CacheManager();
            foreach ($duePosts as $p) {
                try {
                    $cacheManager->purgeUrls([
                        '/',
                        "/{$p['slug']}/",
                        "/categoria/{$p['category']}/",
                        '/feed',
                        '/feed.xml',
                        '/sitemap.xml',
                    ]);
                } catch (\Throwable $e) {
                    error_log("[PostService Cache Purge Error] promoteScheduledPosts: " . $e->getMessage());
                }

                // Disparo de hook para plugins (ex: Webhook Sender) quando a matéria agendada atinge o horário e é publicada
                try {
                    Hook::doAction('post_published', (int)$p['id'], $p, ['status' => 'scheduled'], true);
                } catch (\Throwable $e) {
                    error_log("[PostService Hook Error] post_published on promote: " . $e->getMessage());
                }
            }

            return count($duePosts);
        } catch (\Throwable $e) {
            error_log("[PostService] Falha ao auto-promover agendados: " . $e->getMessage());
            return 0;
        }
    }


    /**
     * Cria um novo artigo em modo rascunho (draft) com integridade transacional.
     * Insere concorrentemente a linha correspondente em `post_stats` com zero visualizações.
     *
     * @param array{
     *   title: string,
     *   slug?: string,
     *   summary?: string,
     *   category?: string,
     *   tags?: string,
     *   featured_image?: string,
     *   content_json: string|array,
     *   seo_title?: string,
     *   seo_description?: string,
     *   focus_keyword?: string
     * } $data
     * @param int $userId ID do autor
     * @return int ID do artigo criado
     */
    public function createPost(array $data, int $userId): int
    {
        $this->ensureScheduledEnumSupported();
        $title = trim($data['title']);
        if (empty($title)) {
            throw new RuntimeException("O título da publicação é obrigatório.", 400);
        }

        $baseSlug = !empty($data['slug']) ? Security::slugify($data['slug']) : Security::slugify($title);
        $slug = $this->resolveUniqueSlug($baseSlug);
        $summary = $data['summary'] ?? null;
        $category = !empty($data['category']) ? Security::slugify($data['category']) : 'geral';
        $tags = $data['tags'] ?? null;
        $featuredImage = $data['featured_image'] ?? null;

        if (!empty($data['content_html'])) {
            $contentHtml = BlockCompiler::sanitizeHtml($data['content_html']);
            $contentJson = !empty($data['content_json']) && $data['content_json'] !== '[]'
                ? (is_array($data['content_json']) ? json_encode($data['content_json']) : $data['content_json'])
                : BlockCompiler::htmlToBlocks($contentHtml);
        } else {
            $contentJson = is_array($data['content_json']) ? json_encode($data['content_json']) : ($data['content_json'] ?? '[]');
            $contentHtml = BlockCompiler::compile($contentJson);
        }

        $seoTitle = $data['seo_title'] ?? null;
        $seoDesc = $data['seo_description'] ?? null;
        $focusKw = $data['focus_keyword'] ?? null;

        return Database::transaction(function (\PDO $pdo) use (
            $userId, $slug, $title, $summary, $category, $tags, $featuredImage,
            $contentJson, $contentHtml, $seoTitle, $seoDesc, $focusKw
        ): int {
            // 1. Inserir artigo em posts
            Database::query(
                "INSERT INTO posts (
                    user_id, slug, title, summary, category, tags, featured_image,
                    content_json, content_html, status, is_featured, version,
                    seo_title, seo_description, focus_keyword, published_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, 'draft', 0, 1,
                    ?, ?, ?, NULL, CURRENT_TIMESTAMP
                )",
                [
                    $userId, $slug, $title, $summary, $category, $tags, $featuredImage,
                    $contentJson, $contentHtml,
                    $seoTitle, $seoDesc, $focusKw
                ]
            );

            $postId = (int)Database::lastInsertId();

            // 2. Inicializar post_stats na mesma transação
            Database::query(
                "INSERT INTO post_stats (post_id, views) VALUES (?, 0)",
                [$postId]
            );

            // 3. Gravar snapshot inicial em post_revisions
            $payload = json_encode([
                'title' => $title,
                'slug' => $slug,
                'summary' => $summary,
                'category' => $category,
                'content_json' => $contentJson,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            Database::query(
                "INSERT INTO post_revisions (
                    post_id, editor_id, base_version, status, payload_json, content_html, created_at
                ) VALUES (?, ?, 1, 'draft', ?, ?, CURRENT_TIMESTAMP)",
                [$postId, $userId, $payload, $contentHtml]
            );

            $revId = (int)Database::getConnection()->lastInsertId();
            ImageProcessor::syncRefs($postId, $revId > 0 ? $revId : null, $contentHtml, $featuredImage);

            return $postId;
        });
    }

    /**
     * Publica ou atualiza um artigo já existente.
     * Detecta conflitos de concorrência com base no campo `version` (HTTP 409).
     *
     * @param int $postId
     * @param array $data Dados a serem atualizados
     * @param int $editorId ID do usuário que está aplicando
     * @param int $knownVersion Versão conhecida pelo cliente antes da edição
     * @return bool
     */
    public function publishOrUpdate(int $postId, array $data, int $editorId, int $knownVersion): bool
    {
        $this->ensureScheduledEnumSupported();
        $affectedUrls = [];
        $isNowPublished = false;
        $isFirstPublish = false;

        $success = Database::transaction(function (\PDO $pdo) use ($postId, $data, $editorId, $knownVersion, &$affectedUrls, &$isNowPublished, &$isFirstPublish): bool {
            // Bloqueio pessimista de linha com FOR UPDATE
            $current = Database::fetchOne(
                "SELECT id, user_id, slug, category, status, version, published_at FROM posts WHERE id = ?" . (Database::getDriver() === 'mysql' ? ' FOR UPDATE' : ''),
                [$postId]
            );

            if (!$current) {
                throw new RuntimeException("Publicação não encontrada.", 404);
            }

            // Detecção de conflito de concorrência otimista (HTTP 409)
            if ((int)$current['version'] !== $knownVersion) {
                throw new RuntimeException(
                    "Conflito de edição detectado! A publicação foi alterada por outro usuário (versão atual: {$current['version']}, versão base: {$knownVersion}).",
                    409
                );
            }

            $title = trim($data['title'] ?? '');
            $baseSlug = !empty($data['slug']) ? Security::slugify($data['slug']) : $current['slug'];
            $slug = $this->resolveUniqueSlug($baseSlug, $postId);
            $summary = $data['summary'] ?? null;
            $category = !empty($data['category']) ? Security::slugify($data['category']) : $current['category'];
            $tags = $data['tags'] ?? null;
            $featuredImage = $data['featured_image'] ?? null;
            $isFeatured = array_key_exists('is_featured', $data) ? (!empty($data['is_featured']) ? 1 : 0) : (int)$current['is_featured'];

            if (!empty($data['content_html'])) {
                $contentHtml = BlockCompiler::sanitizeHtml($data['content_html']);
                $contentJson = !empty($data['content_json']) && $data['content_json'] !== '[]'
                    ? (is_array($data['content_json']) ? json_encode($data['content_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $data['content_json'])
                    : BlockCompiler::htmlToBlocks($contentHtml);
            } else {
                $contentJson = is_array($data['content_json']) ? json_encode($data['content_json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($data['content_json'] ?? '');
                $contentHtml = BlockCompiler::compile($contentJson);
            }

            $seoTitle = $data['seo_title'] ?? null;
            $seoDesc = $data['seo_description'] ?? null;
            $focusKw = $data['focus_keyword'] ?? null;

            // Tratamento de Data de Publicação e Agendamento (em UTC estrito)
            $nowUtc = gmdate('Y-m-d H:i:s');
            if (!empty($data['published_at'])) {
                $publishedAt = gmdate('Y-m-d H:i:s', strtotime($data['published_at']));
            } else {
                $publishedAt = $current['published_at'] ?: $nowUtc;
            }

            // Se a data de publicação for no futuro, o status é 'scheduled'. Se for agora/passado, 'published'.
            $targetStatus = ($publishedAt > $nowUtc) ? 'scheduled' : 'published';

            if (!empty($data['status']) && in_array($data['status'], ['draft', 'pending_review', 'archived'], true)) {
                // P2 / Item 4: Proteção contra rebaixamento acidental de matéria pública por snapshot de revisão:
                // Se a matéria já estava publicada ou agendada e o status vindo do payload for 'pending_review',
                // mantém o estado público ('published' ou 'scheduled')
                if ($data['status'] === 'pending_review' && in_array($current['status'], ['published', 'scheduled'], true)) {
                    $targetStatus = ($publishedAt > $nowUtc) ? 'scheduled' : 'published';
                } else {
                    $targetStatus = $data['status'];
                }
            }

            // Validação estrita de autorização no serviço editorial (LP-06)
            $editor = Database::fetchOne("SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1", [$editorId]);
            if (!$editor || !Authorization::can($editor, Authorization::ACTION_PUBLISH_POST)) {
                if (in_array($targetStatus, ['published', 'scheduled'], true)) {
                    throw new RuntimeException("Apenas Editores e Administradores possuem autorização para publicar ou agendar matérias.", 403);
                }
            }

            $isNowPublished = ($targetStatus === 'published');
            $isFirstPublish = ($isNowPublished && ($current['status'] ?? '') !== 'published');

            $newVersion = (int)$current['version'] + 1;

            // Atualiza o artigo condicionalmente
            $stmt = Database::query(
                "UPDATE posts SET
                    slug = ?, title = ?, summary = ?, category = ?, tags = ?, featured_image = ?,
                    content_json = ?, content_html = ?, status = ?, is_featured = ?, version = ?,
                    seo_title = ?, seo_description = ?, focus_keyword = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND version = ?",
                [
                    $slug, $title, $summary, $category, $tags, $featuredImage,
                    $contentJson, $contentHtml, $targetStatus, $isFeatured, $newVersion,
                    $seoTitle, $seoDesc, $focusKw, $publishedAt,
                    $postId, $knownVersion
                ]
            );

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException("Conflito de concorrência detectado durante a gravação. Nenhuma linha foi alterada.", 409);
            }

            // Gravar revisão histórica aplicada
            $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            Database::query(
                "INSERT INTO post_revisions (
                    post_id, editor_id, base_version, status, payload_json, content_html, created_at
                ) VALUES (?, ?, ?, 'applied', ?, ?, CURRENT_TIMESTAMP)",
                [$postId, $editorId, $newVersion, $payload, $contentHtml]
            );

            $revId = (int)Database::getConnection()->lastInsertId();
            ImageProcessor::syncRefs($postId, $revId > 0 ? $revId : null, $contentHtml, $featuredImage);

            // Invalidação e registro de purga de cache (LP-09)
            $affectedUrls = [
                '/',
                "/{$slug}/",
                "/{$current['slug']}/",
                "/noticia/{$slug}/",
                "/noticia/{$current['slug']}/",
                "/categoria/{$category}/",
                "/categoria/{$current['category']}/",
                '/feed.xml',
                '/feed',
                '/sitemap.xml',
                '/page/2/',
                "/categoria/{$category}/page/2/",
            ];

            if (!empty($tags)) {
                $tagItems = explode(',', (string)$tags);
                foreach ($tagItems as $t) {
                    $tSlug = Security::slugify(trim($t));
                    if (!empty($tSlug)) {
                        $affectedUrls[] = '/tag/' . $tSlug . '/';
                    }
                }
            }

            // Registra job de purga atomicamente dentro da transação
            $this->cacheManager->recordPurgeJob($postId, 'publish_update', $affectedUrls);

            return true;
        });

        // Executa a purga física do cache estritamente após o commit (LP-09 / P2)
        if ($success && !empty($affectedUrls)) {
            try {
                $this->cacheManager->purgeUrls($affectedUrls);
            } catch (\Throwable $e) {
                error_log("[PostService Cache Purge Error] " . $e->getMessage());
            }
        }

        if ($success && $isNowPublished) {
            try {
                Hook::doAction('post_published', $postId, $data, $current, $isFirstPublish);
            } catch (\Throwable $e) {
                error_log("[PostService Hook Error] post_published: " . $e->getMessage());
            }
        }

        return $success;
    }

    /**
     * Garante unicidade do slug adicionando sufixo numérico (-2, -3) caso já exista no banco.
     */
    public function resolveUniqueSlug(string $baseSlug, ?int $ignorePostId = null): string
    {
        $baseSlug = trim($baseSlug, '-');
        if (empty($baseSlug)) {
            $baseSlug = 'artigo';
        }

        $slug = $baseSlug;
        $counter = 1;

        while (true) {
            $isReserved = in_array(strtolower($slug), self::RESERVED_SLUGS, true) || str_starts_with($slug, 'sitemap-posts-');
            if ($ignorePostId !== null) {
                $exists = Database::fetchOne("SELECT id FROM posts WHERE slug = ? AND id != ? LIMIT 1", [$slug, $ignorePostId]);
            } else {
                $exists = Database::fetchOne("SELECT id FROM posts WHERE slug = ? LIMIT 1", [$slug]);
            }

            if (!$exists && !$isReserved) {
                return $slug;
            }

            $counter++;
            $slug = "{$baseSlug}-{$counter}";
        }
    }
}
