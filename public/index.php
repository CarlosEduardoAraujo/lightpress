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
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; script-src 'self' 'unsafe-inline' https://static.cloudflareinsights.com; connect-src 'self' https://cloudflareinsights.com; style-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-ancestors 'self';");

// Inicialização do Core
$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Router;
use LightPress\Core\Security;

$router = new Router();

// Rota 1: Home Grid (Últimas notícias e 4 destaques principais)
$router->get('/', function () use ($config): void {
    $query = trim($_GET['q'] ?? '');
    if (!empty($query)) {
        header('Location: /busca?q=' . urlencode($query));
        exit;
    }

    $nowUtc = gmdate('Y-m-d H:i:s');
    $perPage = 12;
    
    // Buscar até 4 artigos em destaque principal
    $featuredPosts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE status = 'published' AND is_featured = 1 AND published_at <= ?
         ORDER BY published_at DESC LIMIT 4",
        [$nowUtc]
    );

    // Se houver menos de 4 marcados com is_featured, completa com as matérias publicadas mais recentes
    $featuredIds = array_map('intval', array_column($featuredPosts, 'id'));
    if (count($featuredPosts) < 4) {
        $needed = 4 - count($featuredPosts);
        $excludeSql = !empty($featuredIds) ? "AND id NOT IN (" . implode(',', $featuredIds) . ")" : "";
        $extraPosts = Database::fetchAll(
            "SELECT id, slug, title, summary, category, featured_image, published_at 
             FROM posts 
             WHERE status = 'published' AND published_at <= ? {$excludeSql}
             ORDER BY published_at DESC LIMIT {$needed}",
            [$nowUtc]
        );
        $featuredPosts = array_merge($featuredPosts, $extraPosts);
        $featuredIds = array_map('intval', array_column($featuredPosts, 'id'));
    }

    // Total de posts para cálculo de paginação
    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at <= ?",
        [$nowUtc]
    );
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = 1;

    // Buscar publicações para o grid principal excluindo os 4 destaques do topo
    $excludeSql = !empty($featuredIds) ? "AND id NOT IN (" . implode(',', $featuredIds) . ")" : "";
    $posts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE status = 'published' AND published_at <= ? {$excludeSql}
         ORDER BY published_at DESC LIMIT ?",
        [$nowUtc, $perPage]
    );

    $pageTitle = $config['app']['name'] . ' - Portal de Notícias';
    require $config['paths']['templates'] . '/home.php';
});

// Rota 2: Home Paginada (/page/{page}/)
$router->get('/page/{page}/', function (array $params) use ($config): void {
    $page = max(2, (int)($params['page'] ?? 2));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    $nowUtc = gmdate('Y-m-d H:i:s');

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at <= ?",
        [$nowUtc]
    );
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = $page;

    $posts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE status = 'published' AND published_at <= ?
         ORDER BY published_at DESC LIMIT ? OFFSET ?",
        [$nowUtc, $perPage, $offset]
    );

    $featured = null; // Apenas na primeira página
    $pageTitle = "Página {$page} - " . $config['app']['name'];
    require $config['paths']['templates'] . '/home.php';
});

// Rota 3: Listagem por Categoria (/categoria/{slug}/)
$router->get('/categoria/{slug}/', function (array $params) use ($config): void {
    $categorySlug = $params['slug'] ?? '';
    $nowUtc = gmdate('Y-m-d H:i:s');
    $perPage = 12;

    $category = Database::fetchOne(
        "SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1",
        [$categorySlug]
    );

    if (!$category) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts WHERE category = ? AND status = 'published' AND published_at <= ?",
        [$categorySlug, $nowUtc]
    );
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = 1;

    $posts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE category = ? AND status = 'published' AND published_at <= ?
         ORDER BY published_at DESC LIMIT ?",
        [$categorySlug, $nowUtc, $perPage]
    );

    $pageTitle = $category['name'] . ' - ' . $config['app']['name'];
    require $config['paths']['templates'] . '/category.php';
});

// Rota 4: Listagem Paginada de Categoria (/categoria/{slug}/page/{page}/)
$router->get('/categoria/{slug}/page/{page}/', function (array $params) use ($config): void {
    $categorySlug = $params['slug'] ?? '';
    $page = max(2, (int)($params['page'] ?? 2));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
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

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts WHERE category = ? AND status = 'published' AND published_at <= ?",
        [$categorySlug, $nowUtc]
    );
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = $page;

    $posts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE category = ? AND status = 'published' AND published_at <= ?
         ORDER BY published_at DESC LIMIT ? OFFSET ?",
        [$categorySlug, $nowUtc, $perPage, $offset]
    );

    $pageTitle = $category['name'] . " - Página {$page} - " . $config['app']['name'];
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

// Rota 6: Artigo Individual na Raiz (/{slug}/)
$router->get('/{slug}/', function (array $params) use ($config): void {
    $slug = $params['slug'] ?? '';
    $nowUtc = gmdate('Y-m-d H:i:s');

    // Palavras reservadas que não devem ser tratadas como slug de notícia
    $reserved = ['admin', 'api', 'uploads', 'assets', 'storage', 'busca', 'feed', 'sitemap', 'page', 'categoria'];
    if (in_array(strtolower($slug), $reserved, true)) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $post = Database::fetchOne(
        "SELECT p.*, u.name as author_name, u.avatar as author_avatar, u.bio as author_bio
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

    // Buscar até 4 notícias relacionadas da mesma categoria para retenção de audiência
    $relatedPosts = Database::fetchAll(
        "SELECT id, slug, title, summary, category, featured_image, published_at 
         FROM posts 
         WHERE category = ? AND status = 'published' AND id != ? AND published_at <= ?
         ORDER BY published_at DESC LIMIT 4",
        [$post['category'], (int)$post['id'], $nowUtc]
    );

    // Calcular tempo estimado de leitura (média de 200 palavras/minuto)
    $cleanText = strip_tags($post['content_html']);
    $wordCount = str_word_count($cleanText);
    $readingTime = max(1, (int)ceil($wordCount / 200));

    $pageTitle = (!empty($post['seo_title']) ? $post['seo_title'] : $post['title']) . ' - ' . $config['app']['name'];
    require $config['paths']['templates'] . '/post.php';
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
        <link><?= Security::e($canonicalOrigin) ?>/<?= Security::e($item['slug']) ?>/</link>
        <description><?= Security::e($item['summary'] ?? '') ?></description>
        <pubDate><?= date(DATE_RSS, strtotime($item['published_at'])) ?></pubDate>
        <guid isPermaLink="true"><?= Security::e($canonicalOrigin) ?>/<?= Security::e($item['slug']) ?>/</guid>
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
      <loc><?= Security::e($canonicalOrigin) ?>/<?= Security::e($p['slug']) ?>/</loc>
      <lastmod><?= date('c', strtotime($p['updated_at'])) ?></lastmod>
      <changefreq>monthly</changefreq>
      <priority>0.6</priority>
    </url>
  <?php endforeach; ?>
</urlset>
    <?php
});

// Rota 7: Contador de Visualizações (POST /api/view/{id} com ou sem barra final)
$viewHandler = function (array $params): void {
    $_GET['id'] = $params['id'] ?? 0;
    require __DIR__ . '/api/view.php';
};
$router->post('/api/view/{id}', $viewHandler);
$router->post('/api/view/{id}/', $viewHandler);

// Rota 8: Redirecionamento conveniente para o painel administrativo
$router->get('/admin', function (): void {
    header('Location: /admin/login.php');
    exit;
});
$router->get('/admin/', function (): void {
    header('Location: /admin/login.php');
    exit;
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

// Despachar requisição com captura e log defensivo de exceções
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

try {
    $router->dispatch($requestMethod, $requestUri);
} catch (\Throwable $e) {
    error_log("[LightPress HTTP 500] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    if (!empty($config['app']['debug'])) {
        echo "<!DOCTYPE html><html lang='pt-BR'><head><title>Erro 500 - Debug</title><meta charset='utf-8'></head><body style='font-family: monospace; padding: 2rem; background: #fff1f2; color: #9f1239;'>";
        echo "<h1>Erro 500: " . htmlspecialchars($e->getMessage()) . "</h1>";
        echo "<p><strong>Arquivo:</strong> " . htmlspecialchars($e->getFile()) . " (Linha " . $e->getLine() . ")</p>";
        echo "<h3>Stack Trace:</h3><pre style='background: white; padding: 1.5rem; border: 1px solid #fecdd3; border-radius: 8px; overflow-x: auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</body></html>";
    } else {
        if (file_exists($config['paths']['templates'] . '/500.php')) {
            require $config['paths']['templates'] . '/500.php';
        } else {
            echo "500 - Erro interno do servidor. Os detalhes foram registrados nos logs.";
        }
    }
}
