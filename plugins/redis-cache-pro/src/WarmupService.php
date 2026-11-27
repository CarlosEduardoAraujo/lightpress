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

        // 1. Aquecer Opções Globais do LightPress (mesma chave/formato de Settings::getAll)
        try {
            $allSettings = Database::fetchAll("SELECT `key`, `value` FROM settings");
            $settingsMap = [];
            foreach ($allSettings as $row) {
                if (!empty($row['key'])) {
                    $settingsMap[(string)$row['key']] = $row['value'] ?? '';
                    $settingsCount++;
                }
            }
            if (!empty($settingsMap)) {
                $this->client->set('all_settings', $settingsMap, 'settings', 86400);
            }
        } catch (Throwable) {}

        // 2. Aquecer Posts Mais Recentes (mesmo grupo 'post_items' e payload completo de public/index.php)
        if ($postsLimit > 0) {
            try {
                $nowUtc = gmdate('Y-m-d H:i:s');
                $posts = Database::fetchAll(
                    "SELECT p.*, u.name AS author_name, u.avatar AS author_avatar, u.bio AS author_bio
                     FROM posts p
                     JOIN users u ON u.id = p.user_id
                     WHERE p.status = 'published' AND p.published_at <= ?
                     ORDER BY p.published_at DESC
                     LIMIT ?",
                    [$nowUtc, $postsLimit]
                );

                foreach ($posts as $post) {
                    $slug = (string)$post['slug'];
                    if ($slug === '') {
                        continue;
                    }

                    $this->client->set("post_slug:{$slug}", $post, 'post_items', 43200);
                    $postsCount++;
                }
            } catch (Throwable) {}
        }

        // 3. Aquecer Categorias Ativas (mesma chave/formato de public/index.php)
        if ($warmupCategories) {
            try {
                $categories = Database::fetchAll("SELECT * FROM categories WHERE is_active = 1 ORDER BY name ASC");
                if (!empty($categories)) {
                    $activeCategoriesMap = [];
                    foreach ($categories as $cat) {
                        if (!empty($cat['slug'])) {
                            $activeCategoriesMap[(string)$cat['slug']] = $cat;
                            $categoriesCount++;
                        }
                    }
                    if (!empty($activeCategoriesMap)) {
                        $this->client->set('all_active_categories', $activeCategoriesMap, 'categories', 86400);
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
