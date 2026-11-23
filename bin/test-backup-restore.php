<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados Isolados para Backup e Restauração (AUD-ARCH-03)
 * Executa testes em banco SQLite isolado e validação de memória constante (< 32 MiB).
 */

require_once __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Schema;
use LightPress\Core\Settings;

echo "========================================================\n";
echo "   Testes: Backup & Restore em Streaming (AUD-ARCH-03)  \n";
echo "========================================================\n\n";

$tempDir = dirname(__DIR__) . '/storage/staging';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}
$tempDb = $tempDir . '/test_backup_' . time() . '.sqlite';
if (file_exists($tempDb)) {
    @unlink($tempDb);
}

// 1. Inicializa banco SQLite isolado
Database::init(['driver' => 'sqlite', 'sqlite_path' => $tempDb]);
$pdo = Database::getConnection();

// 2. Cria as 15 tabelas principais e as 3 efêmeras
Schema::install($pdo, 'sqlite');
Schema::ensureMediaVariantsSchema($pdo, 'sqlite');
Settings::ensureTable($pdo, 'sqlite');

// Tabelas de plugins
$pdo->exec("CREATE TABLE IF NOT EXISTS plugin_app_passwords (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    key_prefix TEXT NOT NULL,
    secret_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
);");

$pdo->exec("CREATE TABLE IF NOT EXISTS plugin_api_ingest_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    external_key TEXT NOT NULL UNIQUE,
    post_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (DATETIME('now'))
);");

$pdo->exec("CREATE TABLE IF NOT EXISTS plugin_webhook_sender_logs (
    post_id INTEGER PRIMARY KEY,
    sent_at TEXT NOT NULL DEFAULT (DATETIME('now')),
    servers_count INTEGER NOT NULL DEFAULT 1,
    is_success INTEGER NOT NULL DEFAULT 1,
    last_response_codes TEXT NULL
);");

echo "  [OK] Esquema de 15 tabelas e 3 efêmeras criado no banco de teste.\n";

// 3. População com dados volumosos para testar limites de página (> 1.200 linhas)
echo "  [INFO] Semeando dados de teste (> 1.200 registros em posts, post_stats e media)...\n";

$pdo->beginTransaction();

// Usuários
for ($i = 1; $i <= 5; $i++) {
    $pdo->prepare("INSERT INTO users (id, name, username, email, password_hash) VALUES (?, ?, ?, ?, ?)")
        ->execute([$i, "User {$i}", "user{$i}", "user{$i}@test.com", 'dummy_hash']);
}

// Categorias (limpamos propositalmente para ter zero categorias no backup e testar esvaziamento correto no restore!)
$pdo->exec("DELETE FROM categories;");

// 1.250 Posts e Post Stats (testa 3 lotes: 500, 500, 250)
$stmtPost = $pdo->prepare("INSERT INTO posts (id, user_id, slug, title, content_json, content_html, status, is_featured, published_at) VALUES (?, 1, ?, ?, '[]', ?, 'published', 0, DATETIME('now'))");
$stmtStats = $pdo->prepare("INSERT INTO post_stats (post_id, views) VALUES (?, ?)");

for ($i = 1; $i <= 1250; $i++) {
    $stmtPost->execute([$i, "post-slug-{$i}", "Título da Matéria {$i}", "<p>Conteúdo {$i}</p>"]);
    $stmtStats->execute([$i, $i * 10]);
}

// 1.250 Mídias com hash hexadecimal de 32 chars (testa keyset string)
$stmtMedia = $pdo->prepare("INSERT INTO media (id, user_id, original_name, mime_type, file_size, variants_manifest) VALUES (?, 1, ?, 'image/jpeg', 1024, '{}')");
$mediaHexIds = [];
for ($i = 1; $i <= 1250; $i++) {
    $hexId = md5("media_seed_{$i}");
    $mediaHexIds[] = $hexId;
    $stmtMedia->execute([$hexId, "foto_{$i}.jpg"]);
}

// Páginas (limpamos para inserir dados controlados de teste)
$pdo->exec("DELETE FROM pages;");

// Pages, Settings, Media Variants, Media Refs, Redirects, Migration Map, Plugins
for ($i = 1; $i <= 5; $i++) {
    $pdo->prepare("INSERT INTO pages (id, user_id, slug, title, content_html) VALUES (?, 1, ?, ?, ?)")
        ->execute([$i, "page-{$i}", "Página {$i}", "<p>Texto {$i}</p>"]);
    $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)")
        ->execute(["setting_key_{$i}", "setting_val_{$i}"]);
    $pdo->prepare("INSERT INTO media_variants (id, media_id, url_path) VALUES (?, ?, ?)")
        ->execute([$i, $mediaHexIds[$i - 1], "uploads/var_{$i}.webp"]);
    $pdo->prepare("INSERT INTO media_refs (id, media_id, post_id) VALUES (?, ?, ?)")
        ->execute([$i, $mediaHexIds[$i - 1], $i]);
    $pdo->prepare("INSERT INTO redirects (id, old_path, path_hash, target_path) VALUES (?, ?, ?, ?)")
        ->execute([$i, "/antigo-{$i}", md5("/antigo-{$i}"), "/novo-{$i}"]);
    $pdo->prepare("INSERT INTO migration_map (source, entity_type, source_id, target_id) VALUES ('wp', 'post', ?, ?)")
        ->execute([(string)$i, (string)$i]);
    $pdo->prepare("INSERT INTO plugin_app_passwords (id, user_id, name, key_prefix, secret_hash) VALUES (?, 1, ?, ?, ?)")
        ->execute([$i, "App Key {$i}", "lp_app_{$i}", "hash_{$i}"]);
    $pdo->prepare("INSERT INTO plugin_api_ingest_log (id, external_key, post_id) VALUES (?, ?, ?)")
        ->execute([$i, "ext_key_{$i}", $i]);
    $pdo->prepare("INSERT INTO plugin_webhook_sender_logs (post_id, servers_count, is_success) VALUES (?, 1, 1)")
        ->execute([$i]);
}

// Tabelas efêmeras com dados no momento pré-backup
for ($i = 1; $i <= 5; $i++) {
    $pdo->prepare("INSERT INTO user_reset_tokens (id, user_id, token_hash, purpose, expires_at) VALUES (?, 1, ?, 'password_reset', DATETIME('now', '+1 hour'))")
        ->execute([$i, md5("token_{$i}")]);
    $pdo->prepare("INSERT INTO view_dedupe (post_id, ip_hash, expires_at) VALUES (?, ?, DATETIME('now', '+1 hour'))")
        ->execute([$i, md5("ip_{$i}")]);
    $pdo->prepare("INSERT INTO cache_purge_jobs (id, post_id, event, payload_json) VALUES (?, ?, 'purge', '[]')")
        ->execute([$i, $i]);
}

$pdo->commit();
echo "  [OK] Dados semeados com sucesso.\n";

// 4. Teste de Exportação Executando bin/backup.php diretamente via CLI
$backupTestFile = $tempDir . '/test_backup_' . time() . '.ndjson.gz';
$startBackup = microtime(true);

$cmdBackup = "php bin/backup.php --sqlite=" . escapeshellarg($tempDb) . " --file=" . escapeshellarg($backupTestFile);
exec($cmdBackup, $outBackup, $codeBackup);

if ($codeBackup !== 0 || !file_exists($backupTestFile)) {
    echo "  [FAIL] Falha ao executar bin/backup.php:\n" . implode("\n", $outBackup) . "\n";
    exit(1);
}

// Extrair pico de memória relatado pelo backup
$peakMemBackupStr = '';
foreach ($outBackup as $line) {
    if (str_contains($line, 'Pico de memória:')) {
        $peakMemBackupStr = trim(explode(':', $line)[1] ?? '');
    }
}

echo "  [PASS] bin/backup.php concluído com sucesso. {$peakMemBackupStr} Tamanho: " . round(filesize($backupTestFile)/1024, 2) . " KiB.\n";

// 5. Simular alterações no banco de destino antes da restauração:
// - Adicionar 3 categorias (que devem ser apagadas, pois categorias estava vazia no backup!)
// - Adicionar tokens efêmeros novos (que devem ser expurgados)
// - Deletar metade dos posts
$pdo->exec("INSERT INTO categories (id, slug, name) VALUES (1, 'cat-1', 'Cat 1'), (2, 'cat-2', 'Cat 2'), (3, 'cat-3', 'Cat 3');");
$pdo->exec("INSERT INTO user_reset_tokens (id, user_id, token_hash, purpose, expires_at) VALUES (99, 1, 'token_extra', 'activation', DATETIME('now'));");
$pdo->exec("DELETE FROM posts WHERE id > 600;");

echo "  [INFO] Banco de teste modificado para validar restauração.\n";

// 6. Teste de Restauração Executando bin/restore.php diretamente via CLI
$startRestore = microtime(true);
$cmdRestore = "php bin/restore.php --file=" . escapeshellarg($backupTestFile) . " --sqlite=" . escapeshellarg($tempDb);
exec($cmdRestore, $outRestore, $codeRestore);

if ($codeRestore !== 0) {
    echo "  [FAIL] Falha ao executar bin/restore.php:\n" . implode("\n", $outRestore) . "\n";
    exit(1);
}

$peakMemRestoreStr = '';
foreach ($outRestore as $line) {
    if (str_contains($line, 'Pico de memória:')) {
        $peakMemRestoreStr = trim(explode(':', $line)[1] ?? '');
    }
}

echo "  [PASS] bin/restore.php concluído com sucesso. {$peakMemRestoreStr}\n";

// 7. Validação Rigorosa das Asserções
// A. Contagem exata de posts e post_stats (> 1200 sem perda na fronteira de página)
$postCount = (int)Database::fetchValue("SELECT COUNT(*) FROM posts");
$postStatsCount = (int)Database::fetchValue("SELECT COUNT(*) FROM post_stats");
if ($postCount === 1250 && $postStatsCount === 1250) {
    echo "  [PASS] Posts (1.250) e Post Stats (1.250) restaurados com precisão nas fronteiras de lote.\n";
} else {
    echo "  [FAIL] Perda de registros! Posts: {$postCount}, PostStats: {$postStatsCount}\n";
    exit(1);
}

// B. Contagem exata de media hex (> 1200 com keyset string)
$mediaCount = (int)Database::fetchValue("SELECT COUNT(*) FROM media");
if ($mediaCount === 1250) {
    echo "  [PASS] Media com ID hexadecimal de 32 chars (1.250) restaurada sem perda na fronteira de lote.\n";
} else {
    echo "  [FAIL] Perda de registros em media! Total: {$mediaCount}\n";
    exit(1);
}

// C. Tabela vazia no backup (categories) deve estar rigorosamente VAZIA no destino após restore
$catCount = (int)Database::fetchValue("SELECT COUNT(*) FROM categories");
if ($catCount === 0) {
    echo "  [PASS] Tabela vazia no backup (categories) foi corretamente esvaziada no destino.\n";
} else {
    echo "  [FAIL] Tabela vazia no backup não foi esvaziada no destino! Total: {$catCount}\n";
    exit(1);
}

// D. Tabelas efêmeras devem estar com 0 registros após restore
$ephemTokens = (int)Database::fetchValue("SELECT COUNT(*) FROM user_reset_tokens");
$ephemDedupe = (int)Database::fetchValue("SELECT COUNT(*) FROM view_dedupe");
$ephemJobs = (int)Database::fetchValue("SELECT COUNT(*) FROM cache_purge_jobs");
if ($ephemTokens === 0 && $ephemDedupe === 0 && $ephemJobs === 0) {
    echo "  [PASS] Tabelas efêmeras (user_reset_tokens, view_dedupe, cache_purge_jobs) foram expurgadas.\n";
} else {
    echo "  [FAIL] Tabelas efêmeras não foram expurgadas no restore!\n";
    exit(1);
}

// E. Pico de memória < 32 MiB
$backupMemVal = (float)filter_var($peakMemBackupStr, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$restoreMemVal = (float)filter_var($peakMemRestoreStr, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$maxPeak = max($backupMemVal, $restoreMemVal);

if ($maxPeak < 32) {
    echo "  [PASS] Pico de memória sob carga (> 1.250 linhas volumosas): {$maxPeak} MiB (< 32 MiB).\n";
} else {
    echo "  [FAIL] Pico de memória excedeu 32 MiB: {$maxPeak} MiB\n";
    exit(1);
}

// 8. Teste de Retrocompatibilidade com Backup Legado .json.gz
echo "\n  [INFO] Testando retrocompatibilidade com formato legado .json.gz...\n";

$legacyBackupFile = $tempDir . '/test_legacy_' . time() . '.json.gz';
$legacyData = [
    'app' => 'LightPress',
    'version' => '1.0.0',
    'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'tables' => [
        'users' => [
            ['id' => 1, 'name' => 'Legacy Admin', 'username' => 'legadmin', 'email' => 'leg@test.com', 'password_hash' => 'hash1']
        ],
        'categories' => [], // Vazia
        'posts' => [
            ['id' => 1, 'user_id' => 1, 'slug' => 'artigo-legado', 'title' => 'Artigo Legado', 'content_json' => '[]', 'content_html' => '<p>Legado</p>', 'status' => 'published', 'is_featured' => 0, 'published_at' => gmdate('Y-m-d H:i:s')]
        ],
    ]
];
file_put_contents($legacyBackupFile, gzencode(json_encode($legacyData), 9));

// Restaura arquivo legado usando bin/restore.php via CLI
$cmdLegacy = "php bin/restore.php --file=" . escapeshellarg($legacyBackupFile) . " --sqlite=" . escapeshellarg($tempDb);
exec($cmdLegacy, $outLegacy, $codeLegacy);

if ($codeLegacy === 0) {
    $legPostTitle = Database::fetchValue("SELECT title FROM posts WHERE id = 1");
    if ($legPostTitle === 'Artigo Legado') {
        echo "  [PASS] Backup legado .json.gz restaurado com sucesso e retrocompatibilidade preservada.\n";
    } else {
        echo "  [FAIL] Dados do backup legado não correspondem: " . var_export($legPostTitle, true) . "\n";
        exit(1);
    }
} else {
    echo "  [FAIL] Restauração do backup legado falhou com exit code {$codeLegacy}.\n";
    echo implode("\n", $outLegacy) . "\n";
    exit(1);
}

// Limpeza de arquivos temporários do teste
@unlink($tempDb);
@unlink($backupTestFile);
@unlink($legacyBackupFile);

echo "\n>>> TODOS OS CRITÉRIOS DE VALIDAÇÃO DO PASSO 2 FORAM APROVADOS! <<<\n";
