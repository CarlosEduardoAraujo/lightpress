<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress - Testes Automatizados de Feeds RSS e Google News Sitemap
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/Router.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Schema.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../core/ImageProcessor.php';

use LightPress\Core\Database;
use LightPress\Core\Schema;

$pdo = Database::getConnection();
Schema::install($pdo, 'sqlite');

echo "========================================================\n";
echo "   LightPress - Testes de Feeds RSS & Google News       \n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $description, bool $condition): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failCount++;
    }
}

// Configuração de ambiente de teste em memória ou mock
$nowUtc = gmdate('Y-m-d H:i:s');
$recentPostTime = gmdate('Y-m-d H:i:s', time() - 3600); // 1h atrás
$oldPostTime = gmdate('Y-m-d H:i:s', time() - (50 * 3600)); // 50h atrás (fora da janela de 48h)

// Helper para disparar buffer e capturar output
function captureRoute(string $method, string $uri): array {
    global $config;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;

    $oldDisplay = ini_get('display_errors');
    ini_set('display_errors', '0');

    ob_start();
    require dirname(__DIR__) . '/public/index.php';
    $output = ob_get_clean();

    ini_set('display_errors', $oldDisplay);

    $is404 = http_response_code() === 404 || str_contains($output, 'Página Não Encontrada') || str_contains($output, '404');
    $status = $is404 ? 404 : 200;

    // Extrai o conteúdo XML puro a partir da declaração <?xml
    $xmlPos = strpos($output, '<?xml');
    if ($xmlPos !== false) {
        $output = substr($output, $xmlPos);
    }

    return ['status' => $status, 'body' => trim($output)];
}

// -------------------------------------------------------------
// 1. Inserir ou garantir dados de teste com segurança
// -------------------------------------------------------------
$testCatSlug = 'teste-feed-cat';
Database::query(
    Database::getDriver() === 'sqlite'
        ? "INSERT OR IGNORE INTO categories (name, slug, is_active) VALUES ('Categoria Teste', ?, 1)"
        : "INSERT IGNORE INTO categories (name, slug, is_active) VALUES ('Categoria Teste', ?, 1)",
    [$testCatSlug]
);

// Obter ID de usuário válido
$userId = (int)Database::fetchValue("SELECT id FROM users LIMIT 1");
if (!$userId) {
    Database::query(
        "INSERT INTO users (name, username, email, password_hash, role, is_active) 
         VALUES ('Repórter Teste', 'reporter_test', 'reporter@test.com', 'dummy_hash', 'author', 1)"
    );
    $userId = (int)Database::fetchValue("SELECT id FROM users WHERE username = 'reporter_test'");
}

// Matéria recente (dentro das 48h)
Database::query("DELETE FROM posts WHERE slug = 'materia-recente-teste'");
Database::query(
    "INSERT INTO posts (user_id, slug, title, summary, content_json, content_html, category, status, featured_image, published_at, updated_at)
     VALUES (?, 'materia-recente-teste', 'Matéria Recente Teste', 'Resumo da matéria recente', '[]', '<p>Texto da matéria recente</p>', ?, 'published', '/uploads/2026/11/foto-teste.webp', ?, ?)",
    [$userId, $testCatSlug, $recentPostTime, $recentPostTime]
);

// Matéria antiga (50h atrás - fora dos news sitemaps)
Database::query("DELETE FROM posts WHERE slug = 'materia-antiga-teste'");
Database::query(
    "INSERT INTO posts (user_id, slug, title, summary, content_json, content_html, category, status, featured_image, published_at, updated_at)
     VALUES (?, 'materia-antiga-teste', 'Matéria Antiga Teste', 'Resumo da matéria antiga', '[]', '<p>Texto da matéria antiga</p>', ?, 'published', '/uploads/2026/11/foto-antiga.webp', ?, ?)",
    [$userId, $testCatSlug, $oldPostTime, $oldPostTime]
);

// -------------------------------------------------------------
// 2. Testar Feed RSS Geral (/feed.xml)
// -------------------------------------------------------------
echo "[1] Testando Feed RSS Geral (/feed.xml)\n";
$resFeed = captureRoute('GET', '/feed.xml');
assertTest("Rota /feed.xml responde com status 200", $resFeed['status'] === 200);

$xmlFeed = @simplexml_load_string($resFeed['body']);
assertTest("/feed.xml retorna XML válido", $xmlFeed !== false);
assertTest("/feed.xml declara versão 2.0", (string)$xmlFeed['version'] === '2.0');

// Verificar namespaces
$namespaces = $xmlFeed ? $xmlFeed->getNamespaces(true) : [];
assertTest("/feed.xml declara namespace atom", isset($namespaces['atom']));
assertTest("/feed.xml declara namespace content", isset($namespaces['content']));
assertTest("/feed.xml declara namespace media", isset($namespaces['media']));
assertTest("/feed.xml declara namespace dc", isset($namespaces['dc']));

// Verificar presença de matérias no feed
$body = $resFeed['body'];
assertTest("/feed.xml contém título da matéria recente", str_contains($body, 'Matéria Recente Teste'));
assertTest("/feed.xml contém enclosure com type='image/webp'", str_contains($body, 'type="image/webp"'));
assertTest("/feed.xml contém <dc:creator>", str_contains($body, '<dc:creator>'));
assertTest("/feed.xml contém <content:encoded>", str_contains($body, '<content:encoded>'));

// -------------------------------------------------------------
// 3. Testar Feed RSS por Categoria (/categoria/{slug}/feed.xml)
// -------------------------------------------------------------
echo "\n[2] Testando Feed RSS Específico por Categoria\n";
$resCatFeed = captureRoute('GET', "/categoria/{$testCatSlug}/feed.xml");
assertTest("Rota /categoria/{slug}/feed.xml responde com 200", $resCatFeed['status'] === 200);

$xmlCatFeed = @simplexml_load_string($resCatFeed['body']);
assertTest("Feed de categoria retorna XML válido", $xmlCatFeed !== false);
assertTest("Feed de categoria inclui nome da categoria no canal", str_contains((string)$xmlCatFeed->channel->title, 'Categoria Teste'));
assertTest("Feed de categoria inclui matérias daquela categoria", str_contains($resCatFeed['body'], 'Matéria Recente Teste'));

$resCat404 = captureRoute('GET', "/categoria/categoria-inexistente-xyz/feed.xml");
assertTest("Feed de categoria inexistente retorna HTTP 404", $resCat404['status'] === 404);

// -------------------------------------------------------------
// 4. Testar Google News XML Sitemap (/sitemap-news.xml)
// -------------------------------------------------------------
echo "\n[3] Testando Google News XML Sitemap (/sitemap-news.xml - Regra das 48 Horas)\n";
$resNewsSitemap = captureRoute('GET', '/sitemap-news.xml');
assertTest("Rota /sitemap-news.xml responde com status 200", $resNewsSitemap['status'] === 200);

$xmlNews = @simplexml_load_string($resNewsSitemap['body']);
assertTest("/sitemap-news.xml retorna XML válido", $xmlNews !== false);

$newsNamespaces = $xmlNews ? $xmlNews->getNamespaces(true) : [];
assertTest("/sitemap-news.xml declara namespace news", isset($newsNamespaces['news']));
assertTest("/sitemap-news.xml declara namespace image", isset($newsNamespaces['image']));

$newsBody = $resNewsSitemap['body'];
assertTest("Artigo publicado há 1h (<= 48h) ESTÁ PRESENTE no news sitemap", str_contains($newsBody, 'materia-recente-teste'));
assertTest("Artigo publicado há 50h (> 48h) NÃO ESTÁ PRESENTE no news sitemap", !str_contains($newsBody, 'materia-antiga-teste'));
assertTest("/sitemap-news.xml contém tag <news:publication>", str_contains($newsBody, '<news:publication>'));
assertTest("/sitemap-news.xml contém tag <news:publication_date>", str_contains($newsBody, '<news:publication_date>'));
assertTest("/sitemap-news.xml contém tag <image:image>", str_contains($newsBody, '<image:image>'));

// -------------------------------------------------------------
// 5. Testar Sitemap Índice (/sitemap.xml)
// -------------------------------------------------------------
echo "\n[4] Testando Inclusão no Índice Mestre (/sitemap.xml)\n";
$resSitemap = captureRoute('GET', '/sitemap.xml');
assertTest("/sitemap.xml inclui referência para /sitemap-news.xml", str_contains($resSitemap['body'], '/sitemap-news.xml'));

// Limpeza de fixtures
Database::query("DELETE FROM posts WHERE slug IN ('materia-recente-teste', 'materia-antiga-teste')");
Database::query("DELETE FROM categories WHERE slug = ?", [$testCatSlug]);

echo "\n========================================================\n";
echo "Total de Testes: " . ($passCount + $failCount) . "\n";
echo "Aprovados:       {$passCount}\n";
echo "Falhas:          {$failCount}\n";
echo "\n";

if ($failCount === 0) {
    echo ">>> TODOS OS TESTES DE FEEDS E GOOGLE NEWS PASSARAM COM SUCESSO! <<<\n";
    exit(0);
} else {
    echo ">>> ALGUNS TESTES FALHARAM! <<<\n";
    exit(1);
}
