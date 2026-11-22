<?php
declare(strict_types=1);

/**
 * Micro-API Pública de Busca em Tempo Real (Autocomplete / Live Search)
 * Endpoint: GET /api/search.php?q=termo&limit=5
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ObjectCache;
use LightPress\Core\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$query = Security::getSafeString($_GET, 'q');
$limit = min(10, max(1, (int)($_GET['limit'] ?? 5)));

if (strlen($query) < 2) {
    echo json_encode(['ok' => true, 'count' => 0, 'results' => []]);
    exit;
}

$nowUtc = gmdate('Y-m-d H:i:s');
$canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

// Cache de resultados de busca por 60s (alinhado a Cache-Control: max-age=60)
// Utiliza índice FULLTEXT booleano de alta performance com fallback para LIKE (AUD-SEC-03)
$searchHash = md5(mb_strtolower($query) . ':' . $limit);
$posts = ObjectCache::remember("api_search:{$searchHash}", 60, function () use ($config, $query, $limit, $nowUtc, $canonicalOrigin): array {
    $driver = $config['database']['driver'] ?? 'mysql';
    $posts = [];

    // 1. Tenta busca FULLTEXT booleana de alta performance (InnoDB MySQL/MariaDB)
    if ($driver === 'mysql') {
        try {
            $words = array_filter(preg_split('/\s+/', $query) ?: []);
            $boolQuery = '';
            foreach ($words as $w) {
                $cleanW = preg_replace('/[+\-><()~*\"@]+/', '', $w);
                if (strlen($cleanW) >= 2) {
                    $boolQuery .= "+{$cleanW}* ";
                }
            }
            $boolQuery = trim($boolQuery);

            if (!empty($boolQuery)) {
                $posts = Database::fetchAll(
                    "SELECT id, slug, title, summary, category, featured_image, published_at
                     FROM posts
                     WHERE status IN ('published', 'scheduled') AND published_at <= ?
                       AND MATCH(title, summary, tags) AGAINST(? IN BOOLEAN MODE)
                     ORDER BY published_at DESC
                     LIMIT ?",
                    [$nowUtc, $boolQuery, $limit]
                );
            }
        } catch (\Throwable) {
            $posts = [];
        }
    }

    // 2. Fallback via LIKE caso o FULLTEXT não retorne resultados ou o driver seja SQLite
    if (empty($posts)) {
        $safeQuery = Security::escapeLike($query);
        $searchTerm = "%{$safeQuery}%";

        $posts = Database::fetchAll(
            "SELECT id, slug, title, summary, category, featured_image, published_at
             FROM posts
             WHERE status IN ('published', 'scheduled') AND published_at <= ? AND (title LIKE ? OR summary LIKE ?)
             ORDER BY published_at DESC
             LIMIT ?",
            [$nowUtc, $searchTerm, $searchTerm, $limit]
        );
    }

    foreach ($posts as &$item) {
        $item['url'] = "{$canonicalOrigin}/{$item['slug']}/";
    }
    unset($item);

    return $posts;
}, 'posts');

echo json_encode([
    'ok' => true,
    'query' => $query,
    'count' => count($posts),
    'results' => $posts,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
