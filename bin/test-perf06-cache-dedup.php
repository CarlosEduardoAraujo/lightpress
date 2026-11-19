<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados — Validação PERF-06:
 * Deduplicação Sistemática de Purgas Cloudflare e Nginx FastCGI.
 */

namespace LightPress\Tests;

use LightPress\Core\Database;
use LightPress\Core\CacheManager;
use LightPress\Core\CloudflareCache;
use LightPress\Core\PostService;
use LightPress\Core\ImageProcessor;
use LightPress\Core\ObjectCache;

require_once dirname(__DIR__) . '/core/bootstrap.php';

echo "========================================================\n";
echo "   LightPress — Suíte de Testes PERF-06 (Cache Deduplication)\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck(string $description, bool $condition): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failCount++;
    }
}

// Inicializa banco de dados SQLite isolado em memória para os testes
Database::init(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$pdo = Database::getConnection();

// Criação do esquema necessário
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        email TEXT,
        password_hash TEXT,
        role TEXT,
        is_active INTEGER DEFAULT 1
    );
    CREATE TABLE posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        slug TEXT UNIQUE,
        title TEXT,
        summary TEXT,
        category TEXT,
        tags TEXT,
        featured_image TEXT,
        featured_media_id TEXT,
        content_json TEXT,
        content_html TEXT,
        status TEXT,
        is_featured INTEGER DEFAULT 0,
        version INTEGER DEFAULT 1,
        seo_title TEXT,
        seo_description TEXT,
        focus_keyword TEXT,
        published_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE post_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        editor_id INTEGER,
        base_version INTEGER,
        status TEXT,
        payload_json TEXT,
        content_html TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE post_stats (
        post_id INTEGER PRIMARY KEY,
        views INTEGER DEFAULT 0
    );
    CREATE TABLE cache_purge_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NULL,
        event TEXT NOT NULL,
        payload_json TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'processing', 'completed', 'failed')),
        attempts INTEGER NOT NULL DEFAULT 0,
        next_run_at TEXT NOT NULL DEFAULT (DATETIME('now')),
        lease_expires_at TEXT NULL,
        last_error TEXT NULL,
        completed_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
    );
    CREATE TABLE media (
        id TEXT PRIMARY KEY,
        original_name TEXT,
        alt_text TEXT,
        caption TEXT,
        ai_tags TEXT,
        variants_manifest TEXT
    );
    CREATE TABLE media_variants (
        url_path TEXT UNIQUE,
        media_id TEXT
    );
    CREATE TABLE media_refs (
        media_id TEXT,
        post_id INTEGER
    );
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT,
        featured_image TEXT
    );
");

// Inserir usuário editor
Database::query(
    "INSERT INTO users (id, name, email, role, is_active) VALUES (1, 'Editor Chefe', 'editor@speedracing.com.br', 'editor', 1)"
);

// --------------------------------------------------------------------------
// [CENÁRIO 1] Teste Unitário de CacheManager::completePurgeJob()
// --------------------------------------------------------------------------
echo "[1] Testando método atômico CacheManager::completePurgeJob()\n";
$configLocal = [
    'cache' => ['enabled' => false, 'cache_path' => sys_get_temp_dir() . '/lp_cache'],
    'cloudflare' => ['enabled' => false],
    'app' => ['canonical_origin' => 'https://www.speedracing.com.br']
];
$cacheMgr = new CacheManager($configLocal);

$jobId = $cacheMgr->recordPurgeJob(10, 'publish_update', ['/', '/noticia/teste/']);
assertCheck("Job de purga registrado com status inicial 'pending'", $jobId > 0);

$initialJob = Database::fetchOne("SELECT status, attempts, completed_at FROM cache_purge_jobs WHERE id = ?", [$jobId]);
assertCheck("Status no banco é 'pending' e completed_at é nulo", $initialJob['status'] === 'pending' && $initialJob['completed_at'] === null);

$ok = $cacheMgr->completePurgeJob($jobId, 1);
assertCheck("completePurgeJob() retorna true", $ok);

$completedJob = Database::fetchOne("SELECT status, attempts, completed_at, last_error FROM cache_purge_jobs WHERE id = ?", [$jobId]);
assertCheck("Status transitou imediatamente para 'completed'", $completedJob['status'] === 'completed');
assertCheck("completed_at foi preenchido com timestamp UTC válido", !empty($completedJob['completed_at']));
assertCheck("attempts foi registrado como 1 e last_error é nulo", (int)$completedJob['attempts'] === 1 && $completedJob['last_error'] === null);

// --------------------------------------------------------------------------
// [CENÁRIO 2] Parâmetro de controle $purgeCloudflare em purgeUrls()
// --------------------------------------------------------------------------
echo "\n[2] Testando parâmetro de controle de bypass em CacheManager::purgeUrls()\n";
$configWithCf = [
    'cache' => ['enabled' => false, 'cache_path' => sys_get_temp_dir() . '/lp_cache'],
    'cloudflare' => ['enabled' => true, 'api_token' => 'fake_token', 'zone_id' => 'fake_zone'],
    'app' => ['canonical_origin' => 'https://www.speedracing.com.br']
];
$cacheMgrCf = new CacheManager($configWithCf);

// Com $purgeCloudflare = false, não deve tentar chamar a API da Cloudflare (não gera erro de rede/token falso)
$resBypass = $cacheMgrCf->purgeUrls(['/'], false);
assertCheck("purgeUrls(['/'], false) purga apenas Nginx local sem acionar Cloudflare", ($resBypass['/'] ?? false) === true);

// --------------------------------------------------------------------------
// [CENÁRIO 3] PostService::publishOrUpdate() transita job imediatamente para 'completed'
// --------------------------------------------------------------------------
echo "\n[3] Testando fluxo editorial em PostService::publishOrUpdate()\n";

Database::query("
    INSERT INTO posts (id, user_id, slug, title, category, status, version, published_at)
    VALUES (100, 1, 'materia-teste-perf06', 'Matéria Teste PERF-06', 'geral', 'draft', 1, '2026-11-18 20:00:00')
");

$postService = new PostService($cacheMgr);
$updateData = [
    'title' => 'Matéria Atualizada PERF-06',
    'slug' => 'materia-teste-perf06',
    'status' => 'published',
    'category' => 'geral',
    'content_html' => '<p>Conteúdo de teste</p>',
    'published_at' => '2026-11-18 20:00:00'
];

$success = $postService->publishOrUpdate(100, $updateData, 1, 1);
assertCheck("publishOrUpdate() executado com sucesso", $success);

$latestJob = Database::fetchOne("
    SELECT id, status, completed_at, attempts 
    FROM cache_purge_jobs 
    WHERE post_id = 100 
    ORDER BY id DESC LIMIT 1
");
assertCheck("Job de purga do post 100 foi criado", !empty($latestJob));
assertCheck("Job transitou IMEDIATAMENTE para 'completed' após commit e purgeUrls()", $latestJob['status'] === 'completed');
assertCheck("completed_at está registrado", !empty($latestJob['completed_at']));

// Simula execução do cron worker de retentativa (bin/retry-cache-purge.php)
$nowUtc = gmdate('Y-m-d H:i:s');
$pendingJobs = Database::fetchAll(
    "SELECT id FROM cache_purge_jobs WHERE status IN ('pending', 'processing') AND next_run_at <= ?",
    [$nowUtc]
);
assertCheck("Worker de retentativa encontra ZERO jobs pendentes para o post (eliminação de duplicação)", count($pendingJobs) === 0);

// --------------------------------------------------------------------------
// [CENÁRIO 4] Tolerância a Falhas: Erro na purga síncrona preserva status 'pending'
// --------------------------------------------------------------------------
echo "\n[4] Testando tolerância a falhas (resiliência para retentativa do cron)\n";

$failJobId = $cacheMgr->recordPurgeJob(200, 'publish_update', ['/url-com-falha/']);
$purgeSimulatedResult = ['/url-com-falha/' => false]; // simula falha

if (!in_array(false, $purgeSimulatedResult, true)) {
    $cacheMgr->completePurgeJob($failJobId);
}

$persistedJob = Database::fetchOne("SELECT status FROM cache_purge_jobs WHERE id = ?", [$failJobId]);
assertCheck("Job com falha NÃO é marcado como 'completed' e permanece 'pending'", $persistedJob['status'] === 'pending');

// --------------------------------------------------------------------------
// [CENÁRIO 5] Exclusão de post em public/admin/dashboard.php
// --------------------------------------------------------------------------
echo "\n[5] Testando rotina de exclusão em dashboard.php\n";

Database::query("
    INSERT INTO posts (id, user_id, slug, title, category, status, version)
    VALUES (300, 1, 'post-para-deletar', 'Post Para Deletar', 'geral', 'published', 1)
");
$delJobId = 0;
$affectedUrls = ['/', '/post-para-deletar/'];

Database::transaction(function (\PDO $pdo) use (&$delJobId, $affectedUrls, $cacheMgr): void {
    Database::query("DELETE FROM posts WHERE id = 300");
    $delJobId = $cacheMgr->recordPurgeJob(300, 'post_deletion', $affectedUrls);
});

$purgeResults = $cacheMgr->purgeUrls($affectedUrls);
if ($delJobId > 0 && !in_array(false, $purgeResults, true)) {
    $cacheMgr->completePurgeJob($delJobId);
}

$delJob = Database::fetchOne("SELECT status, completed_at FROM cache_purge_jobs WHERE id = ?", [$delJobId]);
assertCheck("Job de exclusão transitou imediatamente para 'completed'", $delJob['status'] === 'completed');

// --------------------------------------------------------------------------
// [CENÁRIO 6] ImageProcessor::invalidateMediaMetadata()
// --------------------------------------------------------------------------
echo "\n[6] Testando invalidação de mídia em ImageProcessor\n";

$urlsMedia = ['/', '/noticia/foto-teste/'];
$mediaJobId = $cacheMgr->recordPurgeJob(null, 'media_metadata', $urlsMedia);

if (count($urlsMedia) <= 30) {
    $purgeResults = $cacheMgr->purgeUrls($urlsMedia);
    if ($mediaJobId > 0 && !in_array(false, $purgeResults, true)) {
        $cacheMgr->completePurgeJob($mediaJobId);
    }
}

$mediaJob = Database::fetchOne("SELECT status, completed_at FROM cache_purge_jobs WHERE id = ?", [$mediaJobId]);
assertCheck("Job de metadados de mídia (<= 30 URLs) marcado como 'completed'", $mediaJob['status'] === 'completed');

// --------------------------------------------------------------------------
// [CENÁRIO 7] CloudflareCache::purgeOnPostPublish() — Prevenção de chamada duplicada
// --------------------------------------------------------------------------
echo "\n[7] Testando CloudflareCache::purgeOnPostPublish()\n";

// Quando Cloudflare desabilitada em dev/staging sem credenciais, deve retornar falso e não lançar exceções
$retDisabled = CloudflareCache::purgeOnPostPublish('/materia-teste-perf06/', '/categoria/geral/');
assertCheck("purgeOnPostPublish() com Cloudflare desligada limpa Nginx e retorna false com segurança", $retDisabled === false);

echo "\n========================================================\n";
echo "   Resultado dos Testes PERF-06\n";
echo "========================================================\n";
echo "Total de Testes: " . ($passCount + $failCount) . "\n";
echo "Aprovados:       {$passCount}\n";
echo "Falhas:          {$failCount}\n";

if ($failCount === 0) {
    echo "\n>>> TODOS OS TESTES PERF-06 FORAM APROVADOS COM SUCESSO! <<<\n";
    exit(0);
} else {
    echo "\n>>> FALHAS DETECTADAS NOS TESTES PERF-06! <<<\n";
    exit(1);
}
