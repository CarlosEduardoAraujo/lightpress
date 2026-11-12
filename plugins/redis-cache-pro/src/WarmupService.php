<?php
declare(strict_types=1);

namespace LightPress\Plugin\RedisCachePro;

use LightPress\Core\Database;
use LightPress\Core\Settings;
use Throwable;

/**
 * Serviço de Pré-aquecimento Inteligente (Pre-warming / Warmup) do LightPress.
 * Carrega dados críticos de configurações, notícias recentes e categorias diretamente no Redis.
 */
final class WarmupService
{
    private RedisClient $client;

    public function __construct(?RedisClient $client = null)
    {
        $this->client = $client ?? RedisClient::getInstance();
    }

    /**
     * Executa o ciclo completo de pré-aquecimento.
     *
     * @param array<string, mixed> $options
     * @return array{settings_count: int, posts_count: int, categories_count: int, urls_count: int, duration_ms: float}
     */
    public function run(array $options = []): array
    {
        $startTime = microtime(true);
        $settingsCount = 0;
        $postsCount = 0;
        $categoriesCount = 0;
        $urlsCount = 0;

        $postsLimit = (int)($options['warmup_posts'] ?? 10);
        $warmupCategories = !empty($options['warmup_categories']);
        $customUrls = trim((string)($options['warmup_custom_urls'] ?? ''));

        // 1. Aquecer Opções Globais do LightPress
        try {
            $allSettings = Database::fetchAll("SELECT `key`, `value` FROM settings");
            foreach ($allSettings as $row) {
                if (!empty($row['key'])) {
                    $this->client->set((string)$row['key'], $row['value'], 'settings', 86400);
                    $settingsCount++;
                }
            }
        } catch (Throwable) {}

        // 2. Aquecer Posts Mais Recentes
        if ($postsLimit > 0) {
            try {
                $posts = Database::fetchAll(
                    "SELECT id, slug, title, summary, category, status, published_at, featured_image, views_count 
                     FROM posts 
                     WHERE status = 'published' 
                     ORDER BY published_at DESC 
                     LIMIT ?",
                    [$postsLimit]
                );

                foreach ($posts as $post) {
                    $postId = (string)$post['id'];
                    $slug = (string)$post['slug'];

                    // Cache por ID e por Slug
                    $this->client->set("post:{$postId}", $post, 'posts', 43200);
                    $this->client->set("post_slug:{$slug}", $post, 'posts', 43200);
                    $postsCount++;
                }
            } catch (Throwable) {}
        }

        // 3. Aquecer Categorias
        if ($warmupCategories) {
            try {
                $categories = Database::fetchAll("SELECT * FROM categories ORDER BY name ASC");
                if (!empty($categories)) {
                    $this->client->set('all_categories', $categories, 'categories', 86400);
                    foreach ($categories as $cat) {
                        if (!empty($cat['slug'])) {
                            $this->client->set("cat:{$cat['slug']}", $cat, 'categories', 86400);
                            $categoriesCount++;
                        }
                    }
                }
            } catch (Throwable) {}
        }

        // 4. Aquecer URLs personalizadas (Home e URLs adicionais)
        $urlsToWarm = [];
        $siteOrigin = rtrim($GLOBALS['config']['app']['canonical_origin'] ?? 'http://localhost', '/');
        $urlsToWarm[] = $siteOrigin . '/';

        if ($customUrls !== '') {
            $lines = preg_split('/[\r\n]+/', $customUrls) ?: [];
            foreach ($lines as $line) {
                $url = trim($line);
                if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                    $urlsToWarm[] = $url;
                }
            }
        }

        $urlsToWarm = array_unique($urlsToWarm);
        foreach ($urlsToWarm as $targetUrl) {
            try {
                $res = \LightPress\Core\SafeHttpClient::request($targetUrl, [
                    CURLOPT_TIMEOUT        => 3,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_USERAGENT      => 'LightPress-Warmup/1.0',
                ], \LightPress\Core\SafeHttpClient::PROFILE_LOCAL_ORIGIN_ONLY, $siteOrigin);

                if ($res['status'] > 0) {
                    $urlsCount++;
                }
            } catch (Throwable) {}
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'settings_count'   => $settingsCount,
            'posts_count'      => $postsCount,
            'categories_count' => $categoriesCount,
            'urls_count'       => $urlsCount,
            'duration_ms'      => $durationMs,
        ];
    }
}
