<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress - Ponto Único de Entrada Público (Front Controller)
 */

// Injeção de Cabeçalhos HTTP de Segurança Defensivos
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; frame-ancestors 'self';");

// Inicialização do Core
$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Router;
use LightPress\Core\Security;

$router = new Router();

// Rota 1: Home Grid (Últimas notícias e destaques)
$router->get('/', function () use ($config): void {
    $nowUtc = gmdate('Y-m-d H:i:s');
    
    // Buscar artigo em destaque principal
    $featured = Database::fetchOne(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE status = 'published' AND is_featured = 1 AND published_at <= ?
         ORDER BY published_at DESC LIMIT 1",
        [$nowUtc]
    );

    // Buscar últimas publicações (12 por página)
    $posts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE status = 'published' AND published_at <= ?
         ORDER BY published_at DESC LIMIT 12",
        [$nowUtc]
    );

    $pageTitle = $config['app']['name'] . ' - Início';
    require $config['paths']['templates'] . '/home.php';
});

// Rota 2: Home Paginada (/page/{page}/)
$router->get('/page/{page}/', function (array $params) use ($config): void {
    $page = max(2, (int)($params['page'] ?? 2));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    $nowUtc = gmdate('Y-m-d H:i:s');

    $posts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE status = 'published' AND published_at <= ?
         ORDER BY published_at DESC LIMIT ? OFFSET ?",
        [$nowUtc, $perPage, $offset]
    );

    $pageTitle = "Página {$page} - " . $config['app']['name'];
    require $config['paths']['templates'] . '/home.php';
});

// Rota 3: Artigo Individual (/noticia/{slug}/)
$router->get('/noticia/{slug}/', function (array $params) use ($config): void {
    $slug = $params['slug'] ?? '';
    $nowUtc = gmdate('Y-m-d H:i:s');

    $post = Database::fetchOne(
        "SELECT p.*, u.name as author_name, u.avatar as author_avatar
         FROM posts p
         JOIN users u ON u.id = p.user_id
         WHERE p.slug = ? AND p.status = 'published' AND p.published_at <= ?
         LIMIT 1",
        [$slug, $nowUtc]
    );

    if (!$post) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $pageTitle = (!empty($post['seo_title']) ? $post['seo_title'] : $post['title']) . ' - ' . $config['app']['name'];
    require $config['paths']['templates'] . '/post.php';
});

// Rota 4: Listagem por Categoria (/categoria/{slug}/)
$router->get('/categoria/{slug}/', function (array $params) use ($config): void {
    $categorySlug = $params['slug'] ?? '';
    $nowUtc = gmdate('Y-m-d H:i:s');

    $category = Database::fetchOne(
        "SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1",
        [$categorySlug]
    );

    if (!$category) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $posts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE category = ? AND status = 'published' AND published_at <= ?
         ORDER BY published_at DESC LIMIT 12",
        [$categorySlug, $nowUtc]
    );

    $pageTitle = $category['name'] . ' - ' . $config['app']['name'];
    require $config['paths']['templates'] . '/category.php';
});

// Rota 5: Contador de Visualizações (POST /api/view/{id})
$router->post('/api/view/{id}', function (array $params): void {
    $_GET['id'] = $params['id'] ?? 0;
    require __DIR__ . '/api/view.php';
});

// Configurar manipulador 404
$router->setNotFound(function () use ($config): void {
    http_response_code(404);
    if (file_exists($config['paths']['templates'] . '/404.php')) {
        require $config['paths']['templates'] . '/404.php';
    } else {
        echo "404 - Página não encontrada.";
    }
});

// Despachar requisição
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($requestMethod, $requestUri);
