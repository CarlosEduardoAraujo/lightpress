<?php
declare(strict_types=1);

/**
 * Micro-API Pública de Leitura de Artigos (Headless / Jamstack Ready)
 * Endpoint: GET /api/posts.php?limit=10&page=1&category=geral
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

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;
$category = trim($_GET['category'] ?? '');

$nowUtc = gmdate('Y-m-d H:i:s');
$canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

$params = [$nowUtc];
$whereCategory = '';
if (!empty($category)) {
    $whereCategory = 'AND p.category = ?';
    $params[] = $category;
}

$paramsWithLimit = $params;
$paramsWithLimit[] = $limit;
$paramsWithLimit[] = $offset;

$posts = Database::fetchAll(
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.featured_image, p.published_at,
            u.name as author_name, COALESCE(s.views, 0) as views
     FROM posts p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN post_stats s ON s.post_id = p.id
     WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? {$whereCategory}
     ORDER BY p.published_at DESC
     LIMIT ? OFFSET ?",
    $paramsWithLimit
);

// Formatar URLs canônicas
foreach ($posts as &$item) {
    $item['url'] = "{$canonicalOrigin}/{$item['slug']}/";
    $item['views'] = (int)$item['views'];
}
unset($item);

echo json_encode([
    'ok' => true,
    'page' => $page,
    'limit' => $limit,
    'count' => count($posts),
    'posts' => $posts,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
