<?php
declare(strict_types=1);

/**
 * Micro-API Pública de Leitura de Categorias
 * Endpoint: GET /api/categories.php
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ObjectCache;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

// Cache em RAM Redis por 300s (alinhado a Cache-Control: max-age=300)
// Elimina full scan / GROUP BY em cada requisição GET (AUD-SEC-03)
$categories = ObjectCache::remember('api_categories_list', 300, function () use ($canonicalOrigin): array {
    $nowUtc = gmdate('Y-m-d H:i:s');
    $rows = Database::fetchAll(
        "SELECT c.id, c.slug, c.name, COUNT(p.id) as total_posts 
         FROM categories c
         LEFT JOIN posts p ON p.category = c.slug AND p.status IN ('published', 'scheduled') AND p.published_at <= ?
         WHERE c.is_active = 1
         GROUP BY c.id
         ORDER BY c.name ASC",
        [$nowUtc]
    );

    foreach ($rows as &$cat) {
        $cat['url'] = "{$canonicalOrigin}/categoria/{$cat['slug']}/";
        $cat['total_posts'] = (int)$cat['total_posts'];
    }
    unset($cat);

    return $rows;
}, 'categories');

echo json_encode([
    'ok' => true,
    'count' => count($categories),
    'categories' => $categories,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
