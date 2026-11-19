<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress — Suíte de Testes Automatizados PERF-03
 *
 * Validação rigorosa em subprocessos isolados para o Fast-Path do endpoint de visualizações:
 * 1. POST /api/view/{id} (Artigos): incremento atômico e deduplicação de 30s
 * 2. POST /api/view/page/{id} (Páginas institucionais): incremento atômico e deduplicação
 * 3. Validação rigorosa de origem (Sec-Fetch-Site, Origin / Referer -> HTTP 403)
 * 4. Validação de alvos em rascunho e inexistentes -> HTTP 404
 * 5. Prova inequívoca de zero plugins e zero overhead de temas na memória do processo
 * 6. Preservação de conexão PDO sem reinicialização em ambientes já inicializados (fallback)
 */

namespace LightPress\Tests;

use PDO;

echo "========================================================\n";
echo "   LightPress — Suíte de Testes PERF-03 (View Fast-Path)\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck(string $description, bool $condition, string $extra = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$description}" . ($extra ? " ({$extra})" : "") . "\n";
        $failCount++;
    }
}

$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$testDbFile = str_replace('\\', '/', sys_get_temp_dir() . '/lp_perf03_' . bin2hex(random_bytes(6)) . '.sqlite');
$tempRunnerScript = str_replace('\\', '/', sys_get_temp_dir() . '/lp_perf03_runner_' . bin2hex(random_bytes(6)) . '.php');

// Fazer backup defensivo de config.local.php se existir
$localConfigFile = $projectRoot . '/config.local.php';
$localConfigBackup = file_exists($localConfigFile) ? file_get_contents($localConfigFile) : null;

try {
    // 1. Criar banco SQLite e esquema isolado
    $pdo = new PDO('sqlite:' . $testDbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');

    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'author',
            is_active INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            summary TEXT NULL,
            category TEXT NOT NULL DEFAULT 'geral',
            tags TEXT NULL,
            featured_image TEXT NULL,
            content_json TEXT NOT NULL DEFAULT '[]',
            content_html TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'draft',
            is_featured INTEGER NOT NULL DEFAULT 0,
            version INTEGER NOT NULL DEFAULT 1,
            published_at TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT (DATETIME('now'))
        );

        CREATE TABLE post_stats (
            post_id INTEGER PRIMARY KEY,
            views INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
        );

        CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            content_html TEXT NOT NULL DEFAULT '',
            featured_image TEXT NULL,
            status TEXT NOT NULL DEFAULT 'published',
            views INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (DATETIME('now')),
            updated_at TEXT NOT NULL DEFAULT (DATETIME('now'))
        );

        CREATE TABLE view_dedupe (
            post_id INTEGER NOT NULL,
            ip_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            PRIMARY KEY (post_id, ip_hash)
        );
        CREATE INDEX idx_view_expiry ON view_dedupe (expires_at);

        CREATE TABLE settings (
            key TEXT PRIMARY KEY,
            value TEXT NULL,
            created_at TEXT,
            updated_at TEXT
        );
    ");

    // Inserir dados de teste (posts e páginas)
    $nowUtc = gmdate('Y-m-d H:i:s');
    $pastUtc = gmdate('Y-m-d H:i:s', time() - 3600);

    // Usuário e Categoria
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES (1, 'Admin', 'admin@local.test', 'hash', 'administrator');");
    $pdo->exec("INSERT INTO categories (id, slug, name) VALUES (1, 'geral', 'Geral');");

    // Post 1: Publicado
    $pdo->exec("INSERT INTO posts (id, user_id, slug, title, status, published_at) VALUES (1, 1, 'post-publicado', 'Artigo 1', 'published', '{$pastUtc}');");
    $pdo->exec("INSERT INTO post_stats (post_id, views) VALUES (1, 0);");

    // Post 2: Rascunho
    $pdo->exec("INSERT INTO posts (id, user_id, slug, title, status, published_at) VALUES (2, 1, 'post-rascunho', 'Artigo 2', 'draft', NULL);");
    $pdo->exec("INSERT INTO post_stats (post_id, views) VALUES (2, 0);");

    // Página 10: Publicada
    $pdo->exec("INSERT INTO pages (id, slug, title, status, views) VALUES (10, 'sobre', 'Sobre Nós', 'published', 0);");

    // Página 20: Rascunho
    $pdo->exec("INSERT INTO pages (id, slug, title, status, views) VALUES (20, 'politica-rascunho', 'Política Privada', 'draft', 0);");

    // Fechar conexão com SQLite antes de delegar subprocessos para desimpedir locks no Linux/Windows
    $pdo = null;

    // 2. Criar config.local.php temporário apontando para o banco isolado de teste
    $tempConfigContent = <<<PHP
<?php
declare(strict_types=1);

return [
    'app' => [
        'env' => 'development',
        'debug' => false,
        'canonical_origin' => 'http://localhost:8080',
    ],
    'database' => [
        'driver' => 'sqlite',
        'sqlite_path' => '{$testDbFile}',
    ],
    'security' => [
        'hmac_secret' => 'teste_hmac_secret_de_alta_entropia_com_mais_de_32_caracteres_123456789',
        'rate_limit' => [
            'view_throttle_seconds' => 30,
        ],
    ],
];
PHP;
    file_put_contents($localConfigFile, $tempConfigContent);

    // 3. Criar script runner do subprocesso que simula a requisição HTTP completa
    $runnerCode = <<<PHP
<?php
declare(strict_types=1);

\$serverData = json_decode(\$argv[1] ?? '{}', true) ?: [];
foreach (\$serverData as \$k => \$v) {
    \$_SERVER[\$k] = \$v;
}

\$projectRoot = '{$projectRoot}';
chdir(\$projectRoot);

// Captura no término do processo (mesmo disparado via exit;)
register_shutdown_function(function () {
    \$result = [
        'http_code' => http_response_code(),
        'has_settings' => class_exists('LightPress\\\\Core\\\\Settings', false),
        'has_theme_mgr' => class_exists('LightPress\\\\Core\\\\ThemeManager', false),
        'has_plugin_mgr' => class_exists('LightPress\\\\Core\\\\PluginManager', false),
        'has_redis' => class_exists('LightPress\\\\Plugin\\\\RedisCachePro\\\\RedisClient', false),
    ];
    echo "\n__FASTPATH_RESULT__:" . json_encode(\$result) . "\n";
});

require \$projectRoot . '/public/index.php';
PHP;
    file_put_contents($tempRunnerScript, $runnerCode);

    // Helper para executar requisição em subprocesso PHP fresco e isolado
    $runSubprocess = function (array $serverParams) use ($tempRunnerScript): array {
        $encoded = json_encode($serverParams);
        $process = proc_open(
            [PHP_BINARY, $tempRunnerScript, $encoded],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException("Falha ao abrir subprocesso PHP.");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $parsed = [];
        if (preg_match('/__FASTPATH_RESULT__:(.*)$/m', $stdout, $matches)) {
            $parsed = json_decode(trim($matches[1]), true) ?: [];
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'result' => $parsed,
        ];
    };

    // Helper para consultar contador atual no banco de dados
    $fetchViews = function (string $table, int $id, string $col = 'views', string $idCol = 'id') use ($testDbFile): int {
        $db = new PDO('sqlite:' . $testDbFile);
        $stmt = $db->prepare("SELECT {$col} FROM {$table} WHERE {$idCol} = ?");
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        $db = null;
        return (int)$val;
    };

    // -------------------------------------------------------------
    // [1] Testando POST /api/view/{id} (Artigo Publicado)
    // -------------------------------------------------------------
    echo "[1] Testando POST /api/view/{id} (Artigos Publicados)\n";

    $res1 = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/1',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '192.168.1.10',
    ]);

    assertCheck(
        "POST /api/view/1 retorna status HTTP 204 No Content",
        ($res1['result']['http_code'] ?? 0) === 204
    );

    $views1 = $fetchViews('post_stats', 1, 'views', 'post_id');
    assertCheck(
        "post_stats.views incrementado com precisão de 0 para 1",
        $views1 === 1,
        "views: {$views1}"
    );

    assertCheck(
        "Zero classes de Settings carregadas no processo (!Settings)",
        empty($res1['result']['has_settings'])
    );

    assertCheck(
        "Zero classes de ThemeManager carregadas no processo (!ThemeManager)",
        empty($res1['result']['has_theme_mgr'])
    );

    assertCheck(
        "Zero classes de PluginManager carregadas no processo (!PluginManager)",
        empty($res1['result']['has_plugin_mgr'])
    );

    assertCheck(
        "Zero classes de RedisClient carregadas no processo (!RedisClient)",
        empty($res1['result']['has_redis'])
    );

    // -------------------------------------------------------------
    // [2] Testando Deduplicação de 30s por IP/Item em POST /api/view/{id}
    // -------------------------------------------------------------
    echo "\n[2] Testando Deduplicação (< 30s) em POST /api/view/{id}\n";

    // 2ª requisição imediata com o mesmo IP e ID
    $res2 = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/1',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '192.168.1.10',
    ]);

    assertCheck(
        "Segunda chamada imediata (< 30s) retorna HTTP 204",
        ($res2['result']['http_code'] ?? 0) === 204
    );

    $views2 = $fetchViews('post_stats', 1, 'views', 'post_id');
    assertCheck(
        "post_stats.views NÃO foi incrementado novamente (deduplicação ativa)",
        $views2 === 1,
        "views: {$views2}"
    );

    // 3ª requisição com IP diferente: deve incrementar
    $res3 = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/1',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '192.168.1.11',
    ]);

    assertCheck(
        "Chamada com novo IP retorna HTTP 204",
        ($res3['result']['http_code'] ?? 0) === 204
    );

    $views3 = $fetchViews('post_stats', 1, 'views', 'post_id');
    assertCheck(
        "post_stats.views incrementado para 2 para novo IP",
        $views3 === 2,
        "views: {$views3}"
    );

    // -------------------------------------------------------------
    // [3] Testando POST /api/view/page/{id} (Páginas Institucionais)
    // -------------------------------------------------------------
    echo "\n[3] Testando POST /api/view/page/{id} (Páginas Institucionais)\n";

    $resPage1 = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/page/10',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '10.0.0.1',
    ]);

    assertCheck(
        "POST /api/view/page/10 retorna HTTP 204 No Content",
        ($resPage1['result']['http_code'] ?? 0) === 204
    );

    $pageViews1 = $fetchViews('pages', 10, 'views', 'id');
    assertCheck(
        "pages.views incrementado com precisão de 0 para 1",
        $pageViews1 === 1,
        "pageViews: {$pageViews1}"
    );

    assertCheck(
        "Zero classes carregadas para páginas (!ThemeManager, !PluginManager)",
        empty($resPage1['result']['has_theme_mgr']) && empty($resPage1['result']['has_plugin_mgr'])
    );

    // Deduplicação de páginas
    $resPage2 = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/page/10',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '10.0.0.1',
    ]);

    $pageViews2 = $fetchViews('pages', 10, 'views', 'id');
    assertCheck(
        "Deduplicação de páginas ativa: pages.views permanece em 1",
        $pageViews2 === 1,
        "pageViews: {$pageViews2}"
    );

    // -------------------------------------------------------------
    // [4] Testando Defesas de Origem (SEC)
    // -------------------------------------------------------------
    echo "\n[4] Testando Validações Rigorosas de Origem (HTTP 403)\n";

    // Sem origem / referer
    $resOriginFail = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/1',
        'REMOTE_ADDR' => '10.0.0.2',
    ]);

    assertCheck(
        "Requisição sem cabeçalhos de origem é rejeitada com HTTP 403",
        ($resOriginFail['result']['http_code'] ?? 0) === 403
    );

    // Origem externa / forjada
    $resOriginCross = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/1',
        'HTTP_ORIGIN' => 'https://site-atacante.com',
        'REMOTE_ADDR' => '10.0.0.3',
    ]);

    assertCheck(
        "Requisição com HTTP_ORIGIN externo é rejeitada com HTTP 403",
        ($resOriginCross['result']['http_code'] ?? 0) === 403
    );

    // -------------------------------------------------------------
    // [5] Testando Posts/Páginas Inexistentes e Rascunhos (HTTP 404)
    // -------------------------------------------------------------
    echo "\n[5] Testando Alvos Inexistentes e Rascunhos (HTTP 404)\n";

    // Post em rascunho
    $resDraftPost = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/2',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '10.0.0.4',
    ]);

    assertCheck(
        "Post com status 'draft' retorna HTTP 404",
        ($resDraftPost['result']['http_code'] ?? 0) === 404
    );

    // Post inexistente
    $resNotFoundPost = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/99999',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '10.0.0.5',
    ]);

    assertCheck(
        "Post inexistente (ID 99999) retorna HTTP 404",
        ($resNotFoundPost['result']['http_code'] ?? 0) === 404
    );

    // Página em rascunho
    $resDraftPage = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/page/20',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '10.0.0.6',
    ]);

    assertCheck(
        "Página institucional com status 'draft' retorna HTTP 404",
        ($resDraftPage['result']['http_code'] ?? 0) === 404
    );

    // Página inexistente
    $resNotFoundPage = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/page/99999',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '10.0.0.7',
    ]);

    assertCheck(
        "Página institucional inexistente retorna HTTP 404",
        ($resNotFoundPage['result']['http_code'] ?? 0) === 404
    );

    // -------------------------------------------------------------
    // [6] Testando Variações de Trailing Slash (/api/view/{id}/)
    // -------------------------------------------------------------
    echo "\n[6] Testando Rotas com Barra Final (Trailing Slash)\n";

    $resSlash1 = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/1/',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '172.16.0.1',
    ]);

    assertCheck(
        "POST /api/view/1/ (com barra final) retorna HTTP 204",
        ($resSlash1['result']['http_code'] ?? 0) === 204
    );

    $resSlash2 = $runSubprocess([
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/view/page/10/',
        'HTTP_ORIGIN' => 'http://localhost:8080',
        'HTTP_SEC_FETCH_SITE' => 'same-origin',
        'REMOTE_ADDR' => '172.16.0.2',
    ]);

    assertCheck(
        "POST /api/view/page/10/ (com barra final) retorna HTTP 204",
        ($resSlash2['result']['http_code'] ?? 0) === 204
    );

    // -------------------------------------------------------------
    // [7] Testando Preservação de Conexão PDO em Ambiente já Inicializado
    // -------------------------------------------------------------
    echo "\n[7] Testando Preservação de Conexão PDO (Reaproveitamento Defensivo)\n";

    // Criar subprocesso que carrega bootstrap primeiro e depois executa api/view.php
    $fallbackTestScript = str_replace('\\', '/', sys_get_temp_dir() . '/lp_perf03_fallback_' . bin2hex(random_bytes(6)) . '.php');
    $fallbackCode = <<<PHP
<?php
declare(strict_types=1);

\$projectRoot = '{$projectRoot}';
chdir(\$projectRoot);

// Simular que o bootstrap já foi carregado
require \$projectRoot . '/core/bootstrap.php';

use LightPress\Core\Database;

// Capturar a conexão PDO ativa criada no bootstrap
\$firstPdo = Database::getConnection();

// Configurar parâmetros de requisição para api/view.php
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['REQUEST_URI'] = '/api/view/1';
\$_SERVER['HTTP_ORIGIN'] = 'http://localhost:8080';
\$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
\$_SERVER['REMOTE_ADDR'] = '192.168.99.1';
\$_GET['id'] = 1;

// Executar view.php dentro de shutdown function para interceptar exit;
register_shutdown_function(function () use (\$firstPdo) {
    \$afterPdo = Database::getConnection();
    \$sameInstance = (\$firstPdo === \$afterPdo);
    echo "\n__FALLBACK_RESULT__:" . json_encode([
        'code' => http_response_code(),
        'same_pdo' => \$sameInstance,
    ]) . "\n";
});

require \$projectRoot . '/public/api/view.php';
PHP;
    file_put_contents($fallbackTestScript, $fallbackCode);

    $processFb = proc_open(
        [PHP_BINARY, $fallbackTestScript],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipesFb
    );

    $stdoutFb = '';
    if (is_resource($processFb)) {
        fclose($pipesFb[0]);
        $stdoutFb = stream_get_contents($pipesFb[1]);
        fclose($pipesFb[1]);
        fclose($pipesFb[2]);
        proc_close($processFb);
    }
    @unlink($fallbackTestScript);

    $fbResult = [];
    if (preg_match('/__FALLBACK_RESULT__:(.*)$/m', $stdoutFb, $mFb)) {
        $fbResult = json_decode(trim($mFb[1]), true) ?: [];
    }

    assertCheck(
        "Fallback / ambiente pré-inicializado retorna HTTP 204",
        ($fbResult['code'] ?? 0) === 204
    );

    assertCheck(
        "Database NÃO recria conexão (mesma instância PDO preservada intacta)",
        !empty($fbResult['same_pdo'])
    );

} finally {
    // Limpeza rigorosa de recursos temporários
    @unlink($tempRunnerScript);
    @unlink($testDbFile);
    @unlink($testDbFile . '-wal');
    @unlink($testDbFile . '-shm');

    // Restaurar config.local.php original
    if ($localConfigBackup !== null) {
        file_put_contents($localConfigFile, $localConfigBackup);
    } else {
        @unlink($localConfigFile);
    }
}

// -------------------------------------------------------------
// Resultado Final
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   Resultado Final dos Testes PERF-03\n";
echo "========================================================\n";
echo "Total de Testes: " . ($passCount + $failCount) . "\n";
echo "Aprovados:       {$passCount}\n";
echo "Falhas:          {$failCount}\n\n";

if ($failCount > 0) {
    echo ">>> FALHA: ALGUNS TESTES NÃO PASSARAM! <<<\n";
    exit(1);
}

echo ">>> TODOS OS TESTES PERF-03 FORAM APROVADOS COM SUCESSO! <<<\n";
exit(0);
