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
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; script-src 'self' https://static.cloudflareinsights.com; connect-src 'self' https://cloudflareinsights.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; frame-ancestors 'self';");

// Inicialização do Core
$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\ObjectCache;
use LightPress\Core\PostService;
use LightPress\Core\Router;
use LightPress\Core\Security;

// Auto-promoção de matérias agendadas cujo horário já foi atingido
PostService::promoteScheduledPosts();

$basePath = $config['app']['base_path'] ?? (parse_url($config['app']['canonical_origin'] ?? '', PHP_URL_PATH) ?: '');
$router = new Router($basePath);

// Rota 1: Home Grid (Últimas notícias e 4 destaques principais)
$router->get('/', function () use ($config, $router): void {
    header('X-Accel-Expires: 300'); // TTL reduzido de 5m no FastCGI Cache para listagens dinâmicas (LP-09)
    $query = Security::getSafeString($_GET, 'q');
    if (!empty($query)) {
        $router->redirect('/busca/?q=' . urlencode($query), 302);
        return;
    }

    $nowUtc = gmdate('Y-m-d H:i:s');
    $perPage = 12;
    
    // Buscar até 4 artigos em destaque principal com cache seguro em memória (Redis/ObjectCache)
    $featuredPosts = ObjectCache::remember('home_featured_posts', 300, function () use ($nowUtc): array {
        $featured = Database::fetchAll(
            "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, CHAR_LENGTH(p.content_html) AS content_len, u.name AS author_name
             FROM posts p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.status IN ('published', 'scheduled') AND p.is_featured = 1 AND p.published_at <= ?
             ORDER BY p.published_at DESC LIMIT 4",
            [$nowUtc]
        );

        // Se houver menos de 4 marcados com is_featured, completa com as matérias publicadas mais recentes
        $featuredIds = array_map('intval', array_column($featured, 'id'));
        if (count($featured) < 4) {
            $needed = 4 - count($featured);
            $excludeSql = !empty($featuredIds) ? "AND p.id NOT IN (" . implode(',', $featuredIds) . ")" : "";
            $extraPosts = Database::fetchAll(
                "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, CHAR_LENGTH(p.content_html) AS content_len, u.name AS author_name
                 FROM posts p
                 LEFT JOIN users u ON u.id = p.user_id
                 WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? {$excludeSql}
                 ORDER BY p.published_at DESC LIMIT {$needed}",
                [$nowUtc]
            );
            $featured = array_merge($featured, $extraPosts);
        }
        return $featured;
    }, 'posts');

    // Total de posts para cálculo de paginação
    $totalPosts = (int)ObjectCache::remember('home_total_posts_count', 300, function () use ($nowUtc): int {
        return (int)Database::fetchValue(
            "SELECT COUNT(*) FROM posts WHERE status IN ('published', 'scheduled') AND published_at <= ?",
            [$nowUtc]
        );
    }, 'posts');
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = 1;

    // Buscar publicações para o feed principal da página 1 (sem MEDIUMTEXT content_html)
    $posts = ObjectCache::remember('home_feed_posts_p1', 300, function () use ($nowUtc, $perPage): array {
        return Database::fetchAll(
            "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
             FROM posts p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ?
             ORDER BY p.published_at DESC LIMIT ?",
            [$nowUtc, $perPage]
        );
    }, 'posts');

    // Pré-carregamento em lote de metadados das imagens da home (Batch Loading O(1))
    $allImages = array_filter(array_merge(
        array_column($featuredPosts, 'featured_image'),
        array_column($posts, 'featured_image')
    ));
    if (!empty($allImages)) {
        ImageProcessor::preloadDescriptions($allImages);
    }

    $pageTitle = $config['app']['name'] . ' - ' . (!empty($config['app']['description']) ? $config['app']['description'] : 'Portal de Notícias');
    require $config['paths']['templates'] . '/home.php';
});

// Rota 2: Home Paginada (/page/{page}/)
$router->get('/page/{page}/', function (array $params) use ($config): void {
    header('X-Accel-Expires: 300'); // TTL 5m FastCGI Cache (LP-09)
    $page = max(2, (int)($params['page'] ?? 2));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    $nowUtc = gmdate('Y-m-d H:i:s');

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts WHERE status IN ('published', 'scheduled') AND published_at <= ?",
        [$nowUtc]
    );
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = $page;

    $posts = Database::fetchAll(
        "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
         FROM posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ?
         ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
        [$nowUtc, (int)$perPage, (int)$offset]
    );

    $pageImages = array_filter(array_column($posts, 'featured_image'));
    if (!empty($pageImages)) {
        ImageProcessor::preloadDescriptions($pageImages);
    }

    $featuredPosts = []; // Apenas na primeira página há o mosaico de 4 destaques
    $pageTitle = "Página {$page} - " . $config['app']['name'];
    require $config['paths']['templates'] . '/home.php';
});

// Rota 3: Listagem por Categoria (/categoria/{slug}/)
$router->get('/categoria/{slug}/', function (array $params) use ($config): void {
    header('X-Accel-Expires: 300'); // TTL 5m FastCGI Cache (LP-09)
    $categorySlug = $params['slug'] ?? '';
    $nowUtc = gmdate('Y-m-d H:i:s');
    $perPage = 12;

    $category = ObjectCache::remember("category:{$categorySlug}", 86400, function () use ($categorySlug): ?array {
        $cat = Database::fetchOne(
            "SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1",
            [$categorySlug]
        );
        return $cat ?: null;
    }, 'categories');

    if (!$category) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts WHERE category = ? AND status IN ('published', 'scheduled') AND published_at <= ?",
        [$categorySlug, $nowUtc]
    );
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = 1;

    $posts = Database::fetchAll(
        "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name 
         FROM posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.category = ? AND p.status IN ('published', 'scheduled') AND p.published_at <= ?
         ORDER BY p.published_at DESC LIMIT ?",
        [$categorySlug, $nowUtc, (int)$perPage]
    );

    $catImages = array_filter(array_column($posts, 'featured_image'));
    if (!empty($catImages)) {
        ImageProcessor::preloadDescriptions($catImages);
    }

    $pageTitle = $category['name'] . ' - ' . $config['app']['name'];
    require $config['paths']['templates'] . '/category.php';
});

// Rota 4: Listagem Paginada de Categoria (/categoria/{slug}/page/{page}/)
$router->get('/categoria/{slug}/page/{page}/', function (array $params) use ($config): void {
    header('X-Accel-Expires: 300'); // TTL 5m FastCGI Cache (LP-09)
    $categorySlug = $params['slug'] ?? '';
    $page = max(2, (int)($params['page'] ?? 2));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    $nowUtc = gmdate('Y-m-d H:i:s');

    $category = ObjectCache::remember("category:{$categorySlug}", 86400, function () use ($categorySlug): ?array {
        $cat = Database::fetchOne(
            "SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1",
            [$categorySlug]
        );
        return $cat ?: null;
    }, 'categories');

    if (!$category) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts WHERE category = ? AND status IN ('published', 'scheduled') AND published_at <= ?",
        [$categorySlug, $nowUtc]
    );
    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = $page;

    $posts = Database::fetchAll(
        "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name 
         FROM posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.category = ? AND p.status IN ('published', 'scheduled') AND p.published_at <= ?
         ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
        [$categorySlug, $nowUtc, (int)$perPage, (int)$offset]
    );

    $catImages = array_filter(array_column($posts, 'featured_image'));
    if (!empty($catImages)) {
        ImageProcessor::preloadDescriptions($catImages);
    }

    $featuredPosts = []; // Apenas na primeira página há o mosaico de destaques
    $pageTitle = $category['name'] . " - Página {$page} - " . $config['app']['name'];
    require $config['paths']['templates'] . '/category.php';
});

// Rota 5: Busca Pública de Notícias (/busca/)
$router->get('/busca/', function () use ($config): void {
    header('X-Accel-Expires: 60'); // TTL 1m FastCGI Cache para buscas (LP-09)
    $query = Security::getSafeString($_GET, 'q');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    $nowUtc = gmdate('Y-m-d H:i:s');
    $safeQuery = Security::escapeLike($query);
    $searchTerm = "%{$safeQuery}%";

    $posts = [];
    $totalPosts = 0;

    if (strlen($query) >= 2) {
        // 1. Tenta busca FULLTEXT booleana de alta performance (InnoDB MySQL/MariaDB)
        $driver = $config['database']['driver'] ?? 'mysql';
        if ($driver === 'mysql') {
            try {
                $words = array_filter(preg_split('/\s+/', $query));
                $boolQuery = '';
                foreach ($words as $w) {
                    $cleanW = preg_replace('/[+\-><()~*\"@]+/', '', $w);
                    if (strlen($cleanW) >= 2) {
                        $boolQuery .= "+{$cleanW}* ";
                    }
                }
                $boolQuery = trim($boolQuery);

                if (!empty($boolQuery)) {
                    $totalPosts = (int)Database::fetchValue(
                        "SELECT COUNT(*)
                         FROM posts p
                         WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ?
                           AND MATCH(p.title, p.summary, p.tags) AGAINST(? IN BOOLEAN MODE)",
                        [$nowUtc, $boolQuery]
                    );

                    if ($totalPosts > 0) {
                        $posts = Database::fetchAll(
                            "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
                             FROM posts p
                             LEFT JOIN users u ON u.id = p.user_id
                             WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ?
                               AND MATCH(p.title, p.summary, p.tags) AGAINST(? IN BOOLEAN MODE)
                             ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
                            [$nowUtc, $boolQuery, (int)$perPage, (int)$offset]
                        );
                    }
                }
            } catch (\Throwable) {
                $posts = [];
                $totalPosts = 0;
            }
        }

        // 2. Fallback via LIKE caso o FULLTEXT não retorne resultados ou o driver seja SQLite
        if (empty($posts) && $totalPosts === 0) {
            $totalPosts = (int)Database::fetchValue(
                "SELECT COUNT(*)
                 FROM posts p
                 WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? 
                   AND (p.title LIKE ? OR p.summary LIKE ? OR p.tags LIKE ?)",
                [$nowUtc, $searchTerm, $searchTerm, $searchTerm]
            );

            if ($totalPosts > 0) {
                $posts = Database::fetchAll(
                    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
                     FROM posts p
                     LEFT JOIN users u ON u.id = p.user_id
                     WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? 
                       AND (p.title LIKE ? OR p.summary LIKE ? OR p.tags LIKE ?)
                     ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
                    [$nowUtc, $searchTerm, $searchTerm, $searchTerm, (int)$perPage, (int)$offset]
                );
            }
        }
    }

    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = $page;
    $pageTitle = $page > 1
        ? "Busca: {$query} - Página {$page} - " . $config['app']['name']
        : "Busca: {$query} - " . $config['app']['name'];

    $searchImages = array_filter(array_column($posts, 'featured_image'));
    if (!empty($searchImages)) {
        ImageProcessor::preloadDescriptions($searchImages);
    }

    require $config['paths']['templates'] . '/search.php';
});

// Rota 5-Paginada: Redirecionamento canônico de compatibilidade para /busca/page/{page}/
$router->get('/busca/page/{page}/', function (array $params) use ($config, $router): void {
    $page = max(1, (int)($params['page'] ?? 1));
    $query = Security::getSafeString($_GET, 'q');
    $target = '/busca/?q=' . urlencode($query) . ($page > 1 ? '&page=' . $page : '');
    $router->redirect($target, 301);
    return;
});

// Rota 5b: Listagem por Tag (/tag/{tag}/)
$router->get('/tag/{tag}/', function (array $params) use ($config): void {
    header('X-Accel-Expires: 300'); // TTL 5m FastCGI Cache (LP-09)
    $tagRaw = $params['tag'] ?? '';
    $nowUtc = gmdate('Y-m-d H:i:s');
    $perPage = 12;

    $tagClean = trim(str_replace('-', ' ', $tagRaw));
    $isSqlite = Database::getDriver() === 'sqlite';
    if ($isSqlite) {
        $tagCondition = "((',' || REPLACE(REPLACE(COALESCE(p.tags, ''), ', ', ','), ' ,', ',') || ',') LIKE ? OR (',' || REPLACE(REPLACE(COALESCE(p.tags, ''), ', ', ','), ' ,', ',') || ',') LIKE ?)";
        $tagCriteria = ['%,' . Security::escapeLike($tagClean) . ',%', '%,' . Security::escapeLike($tagRaw) . ',%'];
    } else {
        $tagCondition = "(FIND_IN_SET(?, REPLACE(COALESCE(p.tags, ''), ', ', ',')) > 0 OR FIND_IN_SET(?, REPLACE(COALESCE(p.tags, ''), ', ', ',')) > 0)";
        $tagCriteria = [$tagClean, $tagRaw];
    }

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts p WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? AND {$tagCondition}",
        array_merge([$nowUtc], $tagCriteria)
    );

    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = 1;

    $posts = Database::fetchAll(
        "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name 
         FROM posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? AND {$tagCondition}
         ORDER BY p.published_at DESC LIMIT ?",
        array_merge([$nowUtc], $tagCriteria, [(int)$perPage])
    );

    $tagName = ucwords($tagClean);
    $pageTitle = "Tópico: #{$tagName} - " . $config['app']['name'];

    $tagImages = array_filter(array_column($posts, 'featured_image'));
    if (!empty($tagImages)) {
        ImageProcessor::preloadDescriptions($tagImages);
    }

    require $config['paths']['templates'] . '/tag.php';
});

// Rota 5c: Listagem Paginada de Tag (/tag/{tag}/page/{page}/)
$router->get('/tag/{tag}/page/{page}/', function (array $params) use ($config): void {
    header('X-Accel-Expires: 300'); // TTL 5m FastCGI Cache (LP-09)
    $tagRaw = $params['tag'] ?? '';
    $page = max(2, (int)($params['page'] ?? 2));
    $perPage = 12;
    $offset = ($page - 1) * $perPage;
    $nowUtc = gmdate('Y-m-d H:i:s');

    $tagClean = trim(str_replace('-', ' ', $tagRaw));
    $isSqlite = Database::getDriver() === 'sqlite';
    if ($isSqlite) {
        $tagCondition = "((',' || REPLACE(REPLACE(COALESCE(p.tags, ''), ', ', ','), ' ,', ',') || ',') LIKE ? OR (',' || REPLACE(REPLACE(COALESCE(p.tags, ''), ', ', ','), ' ,', ',') || ',') LIKE ?)";
        $tagCriteria = ['%,' . Security::escapeLike($tagClean) . ',%', '%,' . Security::escapeLike($tagRaw) . ',%'];
    } else {
        $tagCondition = "(FIND_IN_SET(?, REPLACE(COALESCE(p.tags, ''), ', ', ',')) > 0 OR FIND_IN_SET(?, REPLACE(COALESCE(p.tags, ''), ', ', ',')) > 0)";
        $tagCriteria = [$tagClean, $tagRaw];
    }

    $totalPosts = (int)Database::fetchValue(
        "SELECT COUNT(*) FROM posts p WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? AND {$tagCondition}",
        array_merge([$nowUtc], $tagCriteria)
    );

    $totalPages = (int)ceil($totalPosts / $perPage);
    $currentPage = $page;

    $posts = Database::fetchAll(
        "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name 
         FROM posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? AND {$tagCondition}
         ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
        array_merge([$nowUtc], $tagCriteria, [(int)$perPage, (int)$offset])
    );

    $tagImages = array_filter(array_column($posts, 'featured_image'));
    if (!empty($tagImages)) {
        ImageProcessor::preloadDescriptions($tagImages);
    }

    $featuredPosts = []; // Apenas na primeira página há o mosaico de destaques
    $tagName = ucwords($tagClean);
    $pageTitle = "Tópico: #{$tagName} - Página {$page} - " . $config['app']['name'];
    require $config['paths']['templates'] . '/tag.php';
});

// Rota 6: Artigo Individual na Raiz (/{slug}/)
$router->get('/{slug}/', function (array $params) use ($config): void {
    $slug = $params['slug'] ?? '';
    $nowUtc = gmdate('Y-m-d H:i:s');

    // Palavras reservadas que não devem ser tratadas como slug de notícia
    $reserved = ['admin', 'api', 'uploads', 'assets', 'storage', 'busca', 'feed', 'sitemap', 'page', 'categoria', 'tag'];
    if (in_array(strtolower($slug), $reserved, true)) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    // Consulta atômica do artigo com cache em memória RAM (Redis/ObjectCache)
    $post = ObjectCache::get("post_slug:{$slug}", 'posts');
    if ($post === false || !is_array($post)) {
        $p = Database::fetchOne(
            "SELECT p.*, u.name as author_name, u.avatar as author_avatar, u.bio as author_bio
             FROM posts p
             JOIN users u ON u.id = p.user_id
             WHERE p.slug = ? AND p.status IN ('published', 'scheduled') AND p.published_at <= ?
             LIMIT 1",
            [$slug, $nowUtc]
        );

        if ($p) {
            // TTL Estratificado Inteligente no Redis:
            // Até 1 dia da publicação ou da última atualização: 1 dia (86.400s)
            // Mais de 1 dia da publicação/atualização: 10 dias (864.000s)
            $lastTime = 0;
            if (!empty($p['updated_at'])) {
                $lastTime = (int)strtotime((string)$p['updated_at']);
            }
            if ($lastTime <= 0 && !empty($p['published_at'])) {
                $lastTime = (int)strtotime((string)$p['published_at']);
            }
            if ($lastTime <= 0) {
                $lastTime = time();
            }

            $postTtl = (time() - $lastTime <= 86400) ? 86400 : 864000;
            ObjectCache::set("post_slug:{$slug}", $p, 'posts', $postTtl);
            $post = $p;
        } else {
            $post = null;
        }
    }

    if (!$post) {
        // Tentar encontrar uma Página Institucional ativa (Equipe, Contato, Termos, Privacidade, etc.)
        \LightPress\Core\Schema::ensurePagesTable();
        $page = ObjectCache::remember("page_slug:{$slug}", 86400, function () use ($slug): ?array {
            $pg = Database::fetchOne(
                "SELECT * FROM pages WHERE slug = ? AND status = 'published' LIMIT 1",
                [$slug]
            );
            return $pg ?: null;
        }, 'pages');

        if ($page) {
            header('X-Accel-Expires: 3600'); // 60 minutos de cache para página institucional
            $pageTitle = (!empty($page['seo_title']) ? $page['seo_title'] : $page['title']) . ' - ' . $config['app']['name'];
            require $config['paths']['templates'] . '/page.php';
            return;
        }

        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    // Buscar até 4 notícias relacionadas da mesma categoria para retenção de audiência (com cache de 30m)
    $relatedPosts = ObjectCache::remember("related:{$post['category']}:{$post['id']}", 1800, function () use ($post, $nowUtc): array {
        return Database::fetchAll(
            "SELECT id, slug, title, summary, category, featured_image, published_at 
             FROM posts 
             WHERE category = ? AND status IN ('published', 'scheduled') AND id != ? AND published_at <= ?
             ORDER BY published_at DESC LIMIT 4",
            [$post['category'], (int)$post['id'], $nowUtc]
        );
    }, 'posts');

    // Calcular tempo estimado de leitura (média de 200 palavras/minuto)
    $cleanText = strip_tags($post['content_html']);
    $wordCount = str_word_count($cleanText);
    $readingTime = max(1, (int)ceil($wordCount / 200));

    // Pré-carregamento em lote: imagem principal e imagens das matérias relacionadas
    $postImages = array_filter(array_merge(
        [!empty($post['featured_image']) ? (string)$post['featured_image'] : null],
        array_column($relatedPosts, 'featured_image')
    ));
    if (!empty($postImages)) {
        ImageProcessor::preloadDescriptions($postImages);
    }

    // TTL Estratificado Inteligente para o FastCGI Cache do Nginx:
    // Se a matéria foi publicada ou atualizada nas últimas 24h: 1 dia (86.400s)
    // Se tem mais de 24h sem nenhuma modificação: 10 dias (864.000s)
    $lastActionTime = 0;
    if (!empty($post['updated_at'])) {
        $lastActionTime = (int)strtotime((string)$post['updated_at']);
    }
    if ($lastActionTime <= 0 && !empty($post['published_at'])) {
        $lastActionTime = (int)strtotime((string)$post['published_at']);
    }
    if ($lastActionTime <= 0) {
        $lastActionTime = time();
    }

    $cacheTtl = (time() - $lastActionTime <= 86400) ? 86400 : 864000;
    header("X-Accel-Expires: {$cacheTtl}");
    $pageTitle = (!empty($post['seo_title']) ? $post['seo_title'] : $post['title']) . ' - ' . $config['app']['name'];
    require $config['paths']['templates'] . '/post.php';
});

// Helper para renderização padronizada de Feeds RSS 2.0 com suporte nativo ao Google News
$renderRssFeed = function (
    string $title,
    string $channelLink,
    string $feedSelfUrl,
    string $description,
    array $posts
) use ($config): void {
    header('X-Accel-Expires: 300'); // 5 minutos de cache FastCGI para Feed RSS (LP-09)
    header('Content-Type: application/rss+xml; charset=utf-8');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $escapeCdata = function (string $text): string {
        return str_replace(']]>', ']]]]><![CDATA[>', $text);
    };

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<rss version="2.0" 
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:media="http://search.yahoo.com/mrss/"
     xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
    <title><?= Security::e($title) ?></title>
    <link><?= Security::e($channelLink) ?></link>
    <description><?= Security::e($description) ?></description>
    <language>pt-BR</language>
    <?php if (!empty($config['app']['favicon'])): ?>
    <image>
      <url><?= Security::e(str_starts_with($config['app']['favicon'], 'http') ? $config['app']['favicon'] : "{$canonicalOrigin}{$config['app']['favicon']}") ?></url>
      <title><?= Security::e($title) ?></title>
      <link><?= Security::e($channelLink) ?></link>
    </image>
    <?php endif; ?>
    <atom:link href="<?= Security::e($feedSelfUrl) ?>" rel="self" type="application/rss+xml" />
    <?php foreach ($posts as $item): 
        $itemUrl = "{$canonicalOrigin}/" . Security::e($item['slug']) . "/";
        $imgUrl = '';
        if (!empty($item['featured_image'])) {
            $rawImg = ImageProcessor::toWebpUrl($item['featured_image']);
            $imgUrl = str_starts_with($rawImg, 'http') ? $rawImg : "{$canonicalOrigin}{$rawImg}";
        }
        $authorName = !empty($item['author_name']) ? $item['author_name'] : $config['app']['name'];
    ?>
      <item>
        <title><![CDATA[<?= $escapeCdata($item['title']) ?>]]></title>
        <link><?= $itemUrl ?></link>
        <guid isPermaLink="true"><?= $itemUrl ?></guid>
        <pubDate><?= date(DATE_RSS, strtotime($item['published_at'])) ?></pubDate>
        <dc:creator><![CDATA[<?= $escapeCdata($authorName) ?>]]></dc:creator>
        <?php if (!empty($item['category'])): ?>
        <category><![CDATA[<?= $escapeCdata($item['category']) ?>]]></category>
        <?php endif; ?>
        <description><![CDATA[<?= $escapeCdata($item['summary'] ?? '') ?>]]></description>
        <?php if (!empty($imgUrl)): ?>
        <enclosure url="<?= Security::e($imgUrl) ?>" length="0" type="image/webp" />
        <media:content url="<?= Security::e($imgUrl) ?>" medium="image" type="image/webp">
          <media:title><![CDATA[<?= $escapeCdata($item['title']) ?>]]></media:title>
        </media:content>
        <?php endif; ?>
        <?php if (!empty($item['content_html'])): ?>
        <content:encoded><![CDATA[<?= $escapeCdata($item['content_html']) ?>]]></content:encoded>
        <?php endif; ?>
      </item>
    <?php endforeach; ?>
  </channel>
</rss>
    <?php
};

// Rota 6: RSS Feed Geral (/feed.xml e /feed/)
$generalFeedHandler = function () use ($config, $renderRssFeed): void {
    $nowUtc = gmdate('Y-m-d H:i:s');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $posts = Database::fetchAll(
        "SELECT p.slug, p.title, p.summary, p.content_html, p.featured_image, p.category, p.published_at, u.name AS author_name 
         FROM posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.status IN ('published', 'scheduled') AND p.published_at <= ? 
         ORDER BY p.published_at DESC LIMIT 50",
        [$nowUtc]
    );

    $desc = !empty($config['app']['description']) ? $config['app']['description'] : 'Feed de notícias do ' . $config['app']['name'];
    $renderRssFeed($config['app']['name'], "{$canonicalOrigin}/", "{$canonicalOrigin}/feed.xml", $desc, $posts);
};

$router->get('/feed.xml', $generalFeedHandler);
$router->get('/feed', $generalFeedHandler);
$router->get('/feed/', $generalFeedHandler);

// Rota 6b: RSS Feed Específico por Categoria (/categoria/{slug}/feed.xml e /categoria/{slug}/feed/)
$categoryFeedHandler = function (array $params) use ($config, $renderRssFeed): void {
    $slug = Security::getSafeString($params, 'slug');
    if ($slug === '') {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $category = Database::fetchOne(
        "SELECT id, name, slug FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1",
        [$slug]
    );

    if (!$category) {
        http_response_code(404);
        require $config['paths']['templates'] . '/404.php';
        return;
    }

    $nowUtc = gmdate('Y-m-d H:i:s');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $posts = Database::fetchAll(
        "SELECT p.slug, p.title, p.summary, p.content_html, p.featured_image, p.category, p.published_at, u.name AS author_name 
         FROM posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.category = ? AND p.status IN ('published', 'scheduled') AND p.published_at <= ? 
         ORDER BY p.published_at DESC LIMIT 50",
        [$slug, $nowUtc]
    );

    $title = "{$category['name']} - {$config['app']['name']}";
    $channelLink = "{$canonicalOrigin}/categoria/{$category['slug']}/";
    $feedSelfUrl = "{$canonicalOrigin}/categoria/{$category['slug']}/feed.xml";
    $desc = "Últimas notícias de {$category['name']} no " . $config['app']['name'];

    $renderRssFeed($title, $channelLink, $feedSelfUrl, $desc, $posts);
};

$router->get('/categoria/{slug}/feed.xml', $categoryFeedHandler);
$router->get('/categoria/{slug}/feed', $categoryFeedHandler);
$router->get('/categoria/{slug}/feed/', $categoryFeedHandler);

// Rota 6c: Google News XML Sitemap (/sitemap-news.xml - Janela estrita de 48 horas)
$router->get('/sitemap-news.xml', function () use ($config): void {
    header('X-Accel-Expires: 300'); // 5 minutos de cache FastCGI
    header('Content-Type: application/xml; charset=utf-8');
    $nowUtc = gmdate('Y-m-d H:i:s');
    $twoDaysAgoUtc = gmdate('Y-m-d H:i:s', time() - 172800); // 48 horas obrigatórias pelo Googlebot-News
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $posts = Database::fetchAll(
        "SELECT slug, title, featured_image, published_at 
         FROM posts 
         WHERE status IN ('published', 'scheduled') 
           AND published_at <= ? 
           AND published_at >= ? 
         ORDER BY published_at DESC LIMIT 1000",
        [$nowUtc, $twoDaysAgoUtc]
    );

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
  <?php foreach ($posts as $p): 
      $postUrl = "{$canonicalOrigin}/" . Security::e($p['slug']) . "/";
      $imgUrl = '';
      if (!empty($p['featured_image'])) {
          $rawImg = ImageProcessor::toWebpUrl($p['featured_image']);
          $imgUrl = str_starts_with($rawImg, 'http') ? $rawImg : "{$canonicalOrigin}{$rawImg}";
      }
  ?>
  <url>
    <loc><?= $postUrl ?></loc>
    <news:news>
      <news:publication>
        <news:name><?= Security::e($config['app']['name']) ?></news:name>
        <news:language>pt-br</news:language>
      </news:publication>
      <news:publication_date><?= date('c', strtotime($p['published_at'])) ?></news:publication_date>
      <news:title><?= Security::e($p['title']) ?></news:title>
    </news:news>
    <?php if (!empty($imgUrl)): ?>
    <image:image>
      <image:loc><?= Security::e($imgUrl) ?></image:loc>
      <image:title><?= Security::e($p['title']) ?></image:title>
    </image:image>
    <?php endif; ?>
  </url>
  <?php endforeach; ?>
</urlset>
    <?php
});

// Rota 6d: Sitemap XML Principal (Sitemap Index com inclusão prioritária do News Sitemap)
$router->get('/sitemap.xml', function () use ($config): void {
    header('X-Accel-Expires: 300'); // 5 minutos de cache FastCGI para Sitemap XML (LP-09)
    header('Content-Type: application/xml; charset=utf-8');
    $nowUtc = gmdate('Y-m-d H:i:s');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $countRow = Database::fetchOne(
        "SELECT COUNT(*) AS total, MAX(updated_at) AS latest_mod 
         FROM posts 
         WHERE status IN ('published', 'scheduled') AND published_at <= ?",
        [$nowUtc]
    );

    $totalPosts = (int)($countRow['total'] ?? 0);
    $latestMod = $countRow['latest_mod'] ?? gmdate('Y-m-d H:i:s');
    $latestIso = date('c', strtotime($latestMod));

    $postsPerPage = 5000;
    $totalPages = (int)ceil($totalPosts / $postsPerPage);
    if ($totalPages < 1) {
        $totalPages = 1;
    }

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap>
    <loc><?= Security::e($canonicalOrigin) ?>/sitemap-news.xml</loc>
    <lastmod><?= $latestIso ?></lastmod>
  </sitemap>
  <sitemap>
    <loc><?= Security::e($canonicalOrigin) ?>/sitemap-main.xml</loc>
    <lastmod><?= $latestIso ?></lastmod>
  </sitemap>
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
  <sitemap>
    <loc><?= Security::e($canonicalOrigin) ?>/sitemap-posts-<?= $i ?>.xml</loc>
    <lastmod><?= $latestIso ?></lastmod>
  </sitemap>
  <?php endfor; ?>
</sitemapindex>
    <?php
});

// Sub-sitemap: Páginas Principais (Home, Categorias e Páginas Institucionais)
$router->get('/sitemap-main.xml', function () use ($config): void {
    header('X-Accel-Expires: 300');
    header('Content-Type: application/xml; charset=utf-8');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $categories = Database::fetchAll("SELECT slug FROM categories WHERE is_active = 1 ORDER BY name ASC");
    $pages = Database::fetchAll("SELECT slug, updated_at FROM pages WHERE status = 'published' ORDER BY id ASC");

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
  <?php foreach ($pages as $p): ?>
    <url>
      <loc><?= Security::e($canonicalOrigin) ?>/<?= Security::e($p['slug']) ?>/</loc>
      <lastmod><?= date('c', strtotime($p['updated_at'])) ?></lastmod>
      <changefreq>monthly</changefreq>
      <priority>0.5</priority>
    </url>
  <?php endforeach; ?>
</urlset>
    <?php
});

// Sub-sitemap: Partições de Artigos (/sitemap-posts-{part}.xml)
$router->get('/sitemap-posts-{part}.xml', function (array $params) use ($config): void {
    header('X-Accel-Expires: 300');
    header('Content-Type: application/xml; charset=utf-8');
    $nowUtc = gmdate('Y-m-d H:i:s');
    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');

    $part = max(1, (int)($params['part'] ?? 1));
    $limit = 5000;
    $offset = ($part - 1) * $limit;

    $posts = Database::fetchAll(
        "SELECT slug, updated_at 
         FROM posts 
         WHERE status IN ('published', 'scheduled') AND published_at <= ? 
         ORDER BY published_at DESC 
         LIMIT ? OFFSET ?",
        [$nowUtc, $limit, $offset]
    );

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <?php foreach ($posts as $p): ?>
    <url>
      <loc><?= Security::e($canonicalOrigin) ?>/<?= Security::e($p['slug']) ?>/</loc>
      <lastmod><?= date('c', strtotime($p['updated_at'])) ?></lastmod>
      <changefreq>monthly</changefreq>
      <priority>0.7</priority>
    </url>
  <?php endforeach; ?>
</urlset>
    <?php
});

// Rota 7: Contador de Visualizações de Páginas Institucionais (POST /api/view/page/{id} com ou sem barra final)
$pageViewHandler = function (array $params): void {
    $_GET['type'] = 'page';
    $_GET['id'] = $params['id'] ?? 0;
    require __DIR__ . '/api/view.php';
};
$router->post('/api/view/page/{id}', $pageViewHandler);
$router->post('/api/view/page/{id}/', $pageViewHandler);

// Rota 7b: Contador de Visualizações de Notícias e Artigos (POST /api/view/{id} com ou sem barra final)
$viewHandler = function (array $params): void {
    $_GET['id'] = $params['id'] ?? 0;
    require __DIR__ . '/api/view.php';
};
$router->post('/api/view/{id}', $viewHandler);
$router->post('/api/view/{id}/', $viewHandler);

// Rota 8: Redirecionamento conveniente para o painel administrativo
$router->get('/admin', function () use ($router): void {
    $router->redirect('/admin/login.php', 302);
    return;
});
$router->get('/admin/', function () use ($router): void {
    $router->redirect('/admin/login.php', 302);
    return;
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
