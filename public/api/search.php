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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$query = trim($_GET['q'] ?? '');
$limit = min(10, max(1, (int)($_GET['limit'] ?? 5)));

if (strlen($query) < 2) {
    echo json_encode(['ok' => true, 'count' => 0, 'results' => []]);
    exit;
}

$nowUtc = gmdate('Y-m-d H:i:s');
$canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

$searchTerm = "%{$query}%";

$posts = Database::fetchAll(
    "SELECT id, slug, title, summary, category, featured_image, published_at
     FROM posts
     WHERE status = 'published' AND published_at <= ? AND (title LIKE ? OR summary LIKE ?)
     ORDER BY published_at DESC
     LIMIT ?",
    [$nowUtc, $searchTerm, $searchTerm, $limit]
);

foreach ($posts as &$item) {
    $item['url'] = "{$canonicalOrigin}/noticia/{$item['slug']}/";
}
unset($item);

echo json_encode([
    'ok' => true,
    'query' => $query,
    'count' => count($posts),
    'results' => $posts,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
