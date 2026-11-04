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
    $query = trim($_GET['q'] ?? '');
    if (!empty($query)) {
        header('Location: /busca?q=' . urlencode($query));
        exit;
    }

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

// Rota 5: Busca Pública de Notícias (/busca)
$router->get('/busca', function () use ($config): void {
    $query = trim($_GET['q'] ?? '');
    $nowUtc = gmdate('Y-m-d H:i:s');
    $searchTerm = "%{$query}%";

    $posts = [];
    if (strlen($query) >= 2) {
        $posts = Database::fetchAll(
            "SELECT id, slug, title, summary, category, featured_image, published_at
             FROM posts
             WHERE status = 'published' AND published_at <= ? AND (title LIKE ? OR summary LIKE ?)
             ORDER BY published_at DESC LIMIT 30",
            [$nowUtc, $searchTerm, $searchTerm]
        );
    }

    $pageTitle = "Busca: {$query} - " . $config['app']['name'];
    require $config['paths']['templates'] . '/search.php';
});

// Rota 6: RSS Feed (/feed.xml)
$router->get('/feed.xml', function () use ($config): void {
    header('Content-Type: application/rss+xml; charset=utf-8');
    $nowUtc = gmdate('Y-m-d H:i:s');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $posts = Database::fetchAll(
        "SELECT slug, title, summary, published_at 
         FROM posts 
         WHERE status = 'published' AND published_at <= ? 
         ORDER BY published_at DESC LIMIT 50",
        [$nowUtc]
    );

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?= Security::e($config['app']['name']) ?></title>
    <link><?= Security::e($canonicalOrigin) ?>/</link>
    <description>Feed de notícias do <?= Security::e($config['app']['name']) ?></description>
    <language>pt-BR</language>
    <atom:link href="<?= Security::e($canonicalOrigin) ?>/feed.xml" rel="self" type="application/rss+xml" />
    <?php foreach ($posts as $item): ?>
      <item>
        <title><?= Security::e($item['title']) ?></title>
        <link><?= Security::e($canonicalOrigin) ?>/noticia/<?= Security::e($item['slug']) ?>/</link>
        <description><?= Security::e($item['summary'] ?? '') ?></description>
        <pubDate><?= date(DATE_RSS, strtotime($item['published_at'])) ?></pubDate>
        <guid isPermaLink="true"><?= Security::e($canonicalOrigin) ?>/noticia/<?= Security::e($item['slug']) ?>/</guid>
      </item>
    <?php endforeach; ?>
  </channel>
</rss>
    <?php
});

// Rota 6: Sitemap XML (/sitemap.xml)
$router->get('/sitemap.xml', function () use ($config): void {
    header('Content-Type: application/xml; charset=utf-8');
    $nowUtc = gmdate('Y-m-d H:i:s');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $posts = Database::fetchAll(
        "SELECT slug, updated_at 
         FROM posts 
         WHERE status = 'published' AND published_at <= ? 
         ORDER BY updated_at DESC LIMIT 1000",
        [$nowUtc]
    );

    $categories = Database::fetchAll("SELECT slug FROM categories WHERE is_active = 1");

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= Security::e($canonicalOrigin) ?>/</loc>
    <changefreq>hourly</changefreq>
    <priority>1.0</priority>
  </url>
  <?php foreach ($categories as $cat): ?>
    <url>
      <loc><?= Security::e($canonicalOrigin) ?>/categoria/<?= Security::e($cat['slug']) ?>/</loc>
      <changefreq>daily</changefreq>
      <priority>0.8</priority>
    </url>
  <?php endforeach; ?>
  <?php foreach ($posts as $p): ?>
    <url>
      <loc><?= Security::e($canonicalOrigin) ?>/noticia/<?= Security::e($p['slug']) ?>/</loc>
      <lastmod><?= date('c', strtotime($p['updated_at'])) ?></lastmod>
      <changefreq>monthly</changefreq>
      <priority>0.6</priority>
    </url>
  <?php endforeach; ?>
</urlset>
    <?php
});

// Rota 7: Contador de Visualizações (POST /api/view/{id})
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
