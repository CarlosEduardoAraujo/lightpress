<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

/**
 * LightPress - Suíte de Validação de Planos de Execução e Otimização de Índices (AUD-PERF-01)
 *
 * Valida empiricamente a eliminação de 'Using filesort' / 'USE TEMP B-TREE FOR ORDER BY'
 * em todas as consultas públicas de listagem (Home, Blog, Categorias, Tags, Feeds, Sitemaps)
 * e testa o ciclo atômico de auto-promoção de matérias agendadas (PostService::promoteScheduledPosts).
 *
 * Execução: php bin/test-query-indexes.php
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'LightPress\\Core\\';
    if (strpos($class, $prefix) === 0) {
        $file = dirname(__DIR__) . '/core/' . substr($class, strlen($prefix)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use LightPress\Core\Database;
use LightPress\Core\Schema;
use LightPress\Core\PostService;

echo "========================================================\n";
echo "   LightPress - Validação de Índices B-Tree & Planos    \n";
echo "   (AUD-PERF-01: Eliminação de Filesort em Listagens)   \n";
echo "========================================================\n\n";

Database::init(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$pdo = Database::getConnection();
Schema::install($pdo, 'sqlite');

// Seed inicial isolado em memória
$pdo->exec("INSERT INTO users (id, name, username, email, password_hash, role) 
            VALUES (1, 'Editor Chefe', 'editor', 'editor@test.com', 'hash', 'editor')");

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $label, bool $condition, string $details = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$label}" . ($details !== '' ? " -> {$details}" : '') . "\n";
        $testsFailed++;
    }
}

function assertPlanWithoutFilesort(string $label, string $sql, array $params, string $expectedIndex): void {
    $plan = Database::fetchAll("EXPLAIN QUERY PLAN {$sql}", $params);
    $fullDetail = implode(' | ', array_column($plan, 'detail'));

    $hasIndex = str_contains($fullDetail, $expectedIndex);
    $hasFilesort = str_contains($fullDetail, 'TEMP B-TREE') || str_contains($fullDetail, 'filesort');

    assertTest(
        "{$label} usa índice {$expectedIndex} sem ordenação temporária",
        $hasIndex && !$hasFilesort,
        "Plano: {$fullDetail}"
    );
}

$nowUtc = gmdate('Y-m-d H:i:s');

echo "[1] Testando Consultas da Página Inicial (Home & Blog)\n";

// 1.1 Home Featured (idx_featured)
assertPlanWithoutFilesort(
    "Destaques da Home (4 matérias)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, LENGTH(p.content_html) AS content_len, u.name AS author_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.is_featured = 1 AND p.published_at <= ?
     ORDER BY p.published_at DESC LIMIT 4",
    [$nowUtc],
    'idx_featured'
);

// 1.2 Home Feed p1 (idx_home)
assertPlanWithoutFilesort(
    "Feed Principal da Home (página 1)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.published_at <= ?
     ORDER BY p.published_at DESC LIMIT ?",
    [$nowUtc, 12],
    'idx_home'
);

// 1.3 Home Paginada (idx_home)
assertPlanWithoutFilesort(
    "Home Paginada (/page/2/)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.published_at <= ?
     ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
    [$nowUtc, 12, 12],
    'idx_home'
);

// 1.4 Blog Feed p1 (idx_home)
assertPlanWithoutFilesort(
    "Feed de Artigos do Blog (/blog/)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.published_at <= ?
     ORDER BY p.published_at DESC LIMIT ?",
    [$nowUtc, 12],
    'idx_home'
);

// 1.5 Blog Paginado (idx_home)
assertPlanWithoutFilesort(
    "Blog Paginado (/blog/page/2/)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.published_at <= ?
     ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
    [$nowUtc, 12, 12],
    'idx_home'
);

echo "\n[2] Testando Consultas Editoriais de Categoria e Relacionados\n";

// 2.1 Categoria Feed p1 (idx_category)
assertPlanWithoutFilesort(
    "Listagem de Categoria (/categoria/geral/)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name 
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.category = ? AND p.status = 'published' AND p.published_at <= ?
     ORDER BY p.published_at DESC LIMIT ?",
    ['geral', $nowUtc, 12],
    'idx_category'
);

// 2.2 Categoria Paginada (idx_category)
assertPlanWithoutFilesort(
    "Categoria Paginada (/categoria/geral/page/2/)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.tags, p.featured_image, p.published_at, u.name AS author_name 
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.category = ? AND p.status = 'published' AND p.published_at <= ?
     ORDER BY p.published_at DESC LIMIT ? OFFSET ?",
    ['geral', $nowUtc, 12, 12],
    'idx_category'
);

// 2.3 Matérias Relacionadas (idx_category)
assertPlanWithoutFilesort(
    "Notícias Relacionadas da Mesma Categoria",
    "SELECT id, slug, title, summary, category, featured_image, published_at 
     FROM posts 
     WHERE category = ? AND status = 'published' AND id != ? AND published_at <= ?
     ORDER BY published_at DESC LIMIT 4",
    ['geral', 1, $nowUtc],
    'idx_category'
);

echo "\n[3] Testando Consultas de Sitemaps XML e Feeds RSS\n";

// 3.1 Sitemap Posts Partition (idx_home)
assertPlanWithoutFilesort(
    "Partição de Sitemap XML (/sitemap-posts-1.xml)",
    "SELECT slug, updated_at 
     FROM posts 
     WHERE status = 'published' AND published_at <= ? 
     ORDER BY published_at DESC 
     LIMIT ? OFFSET ?",
    [$nowUtc, 5000, 0],
    'idx_home'
);

// 3.2 Feed RSS Geral (idx_home)
assertPlanWithoutFilesort(
    "Feed RSS Geral (/feed/)",
    "SELECT p.slug, p.title, p.summary, p.content_html, p.featured_image, p.category, p.published_at, u.name AS author_name 
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.published_at <= ? 
     ORDER BY p.published_at DESC LIMIT 50",
    [$nowUtc],
    'idx_home'
);

// 3.3 Feed RSS Categoria (idx_category)
assertPlanWithoutFilesort(
    "Feed RSS por Categoria (/categoria/geral/feed/)",
    "SELECT p.slug, p.title, p.summary, p.content_html, p.featured_image, p.category, p.published_at, u.name AS author_name 
     FROM posts p
     LEFT JOIN users u ON u.id = p.user_id
     WHERE p.category = ? AND p.status = 'published' AND p.published_at <= ? 
     ORDER BY p.published_at DESC LIMIT 50",
    ['geral', $nowUtc],
    'idx_category'
);

// 3.4 Micro-API de Posts (idx_home)
assertPlanWithoutFilesort(
    "Micro-API Pública de Artigos (/api/posts.php)",
    "SELECT p.id, p.slug, p.title, p.summary, p.category, p.featured_image, p.published_at,
            u.name as author_name, COALESCE(s.views, 0) as views
     FROM posts p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN post_stats s ON s.post_id = p.id
     WHERE p.status = 'published' AND p.published_at <= ?
     ORDER BY p.published_at DESC
     LIMIT ? OFFSET ?",
    [$nowUtc, 10, 0],
    'idx_home'
);

echo "\n[4] Testando Auto-Promoção Atômica de Agendados (PostService::promoteScheduledPosts)\n";

// Inserir matéria agendada com horário vencido (1 hora atrás)
$pastUtc = gmdate('Y-m-d H:i:s', time() - 3600);
$pdo->exec("INSERT INTO posts (user_id, slug, title, summary, category, content_json, content_html, status, published_at)
            VALUES (1, 'artigo-agendado-vencido', 'Artigo Agendado Vencido', 'Resumo', 'geral', '[]', '<p>Texto</p>', 'scheduled', '{$pastUtc}')");

$initialStatus = Database::fetchValue("SELECT status FROM posts WHERE slug = 'artigo-agendado-vencido'");
assertTest("Matéria de teste inserida inicialmente como 'scheduled'", $initialStatus === 'scheduled');

// Executar promoção com force = true (como no cron retry-cache-purge.php)
$promotedCount = PostService::promoteScheduledPosts(true);
assertTest("promoteScheduledPosts(true) promove exatamente 1 artigo vencido", $promotedCount === 1);

$promotedStatus = Database::fetchValue("SELECT status FROM posts WHERE slug = 'artigo-agendado-vencido'");
assertTest("Status do artigo promovido foi atualizado para 'published'", $promotedStatus === 'published');

// Garantir que a matéria promovida agora é retornada pela consulta da home com index scan
$homePost = Database::fetchOne(
    "SELECT p.slug, p.status 
     FROM posts p 
     WHERE p.slug = 'artigo-agendado-vencido' AND p.status = 'published' AND p.published_at <= ?",
    [$nowUtc]
);
assertTest("Artigo promovido é imediatamente visível na consulta com status = 'published'", !empty($homePost) && $homePost['slug'] === 'artigo-agendado-vencido');

// Inserir matéria agendada com horário futuro (não deve ser promovida)
$futureUtc = gmdate('Y-m-d H:i:s', time() + 7200);
$pdo->exec("INSERT INTO posts (user_id, slug, title, summary, category, content_json, content_html, status, published_at)
            VALUES (1, 'artigo-agendado-futuro', 'Artigo Agendado Futuro', 'Resumo', 'geral', '[]', '<p>Texto</p>', 'scheduled', '{$futureUtc}')");

$futurePromoted = PostService::promoteScheduledPosts(true);
assertTest("promoteScheduledPosts(true) NÃO promove matéria com data futura", $futurePromoted === 0);

$futureStatus = Database::fetchValue("SELECT status FROM posts WHERE slug = 'artigo-agendado-futuro'");
assertTest("Artigo futuro permanece com status = 'scheduled'", $futureStatus === 'scheduled');

echo "\n========================================================\n";
echo "   Resultado Final dos Testes de Índices e Otimização   \n";
echo "========================================================\n";
echo "Total de Testes: " . ($testsPassed + $testsFailed) . "\n";
echo "Aprovados:       {$testsPassed}\n";
echo "Falhas:          {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo ">>> SUCESSO: TODAS AS CONSULTAS UTILIZAM INDEX SCAN SEM FILESORT! <<<\n\n";
    exit(0);
} else {
    echo ">>> ERRO: FORAM DETECTADAS FALHAS NOS PLANOS DE CONSULTA! <<<\n\n";
    exit(1);
}
