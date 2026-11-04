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
    private CacheManager $cacheManager;

    public function __construct(?CacheManager $cacheManager = null)
    {
        $this->cacheManager = $cacheManager ?? new CacheManager();
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
        $title = trim($data['title']);
        if (empty($title)) {
            throw new RuntimeException("O título da publicação é obrigatório.", 400);
        }

        $slug = !empty($data['slug']) ? Security::slugify($data['slug']) : Security::slugify($title);
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
            ]);

            Database::query(
                "INSERT INTO post_revisions (
                    post_id, editor_id, base_version, status, payload_json, content_html, created_at
                ) VALUES (?, ?, 1, 'draft', ?, ?, CURRENT_TIMESTAMP)",
                [$postId, $userId, $payload, $contentHtml]
            );

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
        return Database::transaction(function (\PDO $pdo) use ($postId, $data, $editorId, $knownVersion): bool {
            // Bloqueio pessimista de linha com FOR UPDATE
            $current = Database::fetchOne(
                "SELECT id, user_id, slug, category, status, version, published_at FROM posts WHERE id = ? FOR UPDATE",
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
            $slug = !empty($data['slug']) ? Security::slugify($data['slug']) : $current['slug'];
            $summary = $data['summary'] ?? null;
            $category = !empty($data['category']) ? Security::slugify($data['category']) : $current['category'];
            $tags = $data['tags'] ?? null;
            $featuredImage = $data['featured_image'] ?? null;
            $isFeatured = !empty($data['is_featured']) ? 1 : 0;

            if (!empty($data['content_html'])) {
                $contentHtml = BlockCompiler::sanitizeHtml($data['content_html']);
                $contentJson = !empty($data['content_json']) && $data['content_json'] !== '[]'
                    ? (is_array($data['content_json']) ? json_encode($data['content_json']) : $data['content_json'])
                    : BlockCompiler::htmlToBlocks($contentHtml);
            } else {
                $contentJson = is_array($data['content_json']) ? json_encode($data['content_json']) : ($data['content_json'] ?? '');
                $contentHtml = BlockCompiler::compile($contentJson);
            }

            $seoTitle = $data['seo_title'] ?? null;
            $seoDesc = $data['seo_description'] ?? null;
            $focusKw = $data['focus_keyword'] ?? null;

            // Se for primeira publicação, define published_at
            $publishedAt = $current['published_at'];
            if (empty($publishedAt)) {
                $publishedAt = gmdate('Y-m-d H:i:s');
            }

            $newVersion = (int)$current['version'] + 1;

            // Atualiza o artigo
            Database::query(
                "UPDATE posts SET
                    slug = ?, title = ?, summary = ?, category = ?, tags = ?, featured_image = ?,
                    content_json = ?, content_html = ?, status = 'published', is_featured = ?, version = ?,
                    seo_title = ?, seo_description = ?, focus_keyword = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [
                    $slug, $title, $summary, $category, $tags, $featuredImage,
                    $contentJson, $contentHtml, $isFeatured, $newVersion,
                    $seoTitle, $seoDesc, $focusKw, $publishedAt,
                    $postId
                ]
            );

            // Gravar revisão histórica aplicada
            $payload = json_encode($data);
            Database::query(
                "INSERT INTO post_revisions (
                    post_id, editor_id, base_version, status, payload_json, content_html, created_at
                ) VALUES (?, ?, ?, 'applied', ?, ?, CURRENT_TIMESTAMP)",
                [$postId, $editorId, $newVersion, $payload, $contentHtml]
            );

            // Invalidação e registro de purga de cache
            $affectedUrls = [
                '/',
                "/noticia/{$slug}/",
                "/noticia/{$current['slug']}/",
                "/categoria/{$category}/",
                "/categoria/{$current['category']}/",
            ];

            $this->cacheManager->recordPurgeJob($postId, 'publish_update', $affectedUrls);
            $this->cacheManager->purgeUrls($affectedUrls);

            return true;
        });
    }
}
