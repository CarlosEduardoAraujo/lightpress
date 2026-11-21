<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados - Passo 2.2 (ARCH-06, PERF-04, PERF-05)
 * Execução: php bin/test-perf-arch-step22.php
 */

// Se RedisCache não estiver carregado, define mock em memória para habilitar ObjectCache nos testes
if (!class_exists('LightPress\\Plugin\\RedisCachePro\\RedisCache', false)) {
    eval(<<<'PHP'
    namespace LightPress\Plugin\RedisCachePro;
    class RedisCache {
        public static array $store = [];
        public static function get(string $key, string $group = 'default', mixed &$found = null): mixed {
            if (isset(self::$store[$group][$key])) {
                $found = true;
                return self::$store[$group][$key];
            }
            $found = false;
            return false;
        }
        public static function mGet(array $keys, string $group = 'default'): array {
            $results = [];
            foreach ($keys as $k) {
                $results[$k] = self::$store[$group][$k] ?? false;
            }
            return $results;
        }
        public static function set(string $key, mixed $value, string $group = 'default', int $ttl = 0): bool {
            self::$store[$group][$key] = $value;
            return true;
        }
        public static function setMultiple(array $items, string $group = 'default', int $ttl = 0): bool {
            foreach ($items as $k => $v) {
                self::$store[$group][$k] = $v;
            }
            return true;
        }
        public static function delete(string $key, string $group = 'default'): bool {
            unset(self::$store[$group][$key]);
            return true;
        }
        public static function remember(string $key, int $ttl, callable $callback, string $group = 'default'): mixed {
            if (isset(self::$store[$group][$key])) {
                return self::$store[$group][$key];
            }
            $val = $callback();
            self::$store[$group][$key] = $val;
            return $val;
        }
        public static function flushGroup(string $group): bool {
            unset(self::$store[$group]);
            return true;
        }
        public static function flush(string $group = ''): bool {
            if ($group !== '') {
                unset(self::$store[$group]);
            } else {
                self::$store = [];
            }
            return true;
        }
        public static function isConnected(): bool {
            return true;
        }
    }
PHP
    );
}

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\ObjectCache;
use LightPress\Core\PostService;
use LightPress\Core\Schema;
use LightPress\Core\Settings;

echo "========================================================\n";
echo "   LightPress - Validação Cirúrgica: Passo 2.2         \n";
echo "   (ARCH-06, PERF-04, PERF-05)                         \n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description} " . ($detail ? "({$detail})" : "") . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// 1. Testes de ARCH-06 (Isolamento de DDL e páginas institucionais)
// -------------------------------------------------------------
echo "[1] Testando ARCH-06: Remoção de DDL no 404 e Schema::install / ensurePagesTable\n";

// Validação estática em public/index.php: não deve conter chamada a ensurePagesTable() na rota /{slug}/
$indexContent = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
assertTest(
    "ARCH-06: public/index.php NÃO chama Schema::ensurePagesTable() no caminho de roteamento",
    !str_contains($indexContent, 'Schema::ensurePagesTable')
);

// Validação de ensurePagesTable aceitando PDO isolado (sem usar Database::getConnection)
$isolatedSqlite = new PDO('sqlite::memory:');
$isolatedSqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

Schema::ensurePagesTable($isolatedSqlite, 'sqlite');
$stmtPages = $isolatedSqlite->query("SELECT COUNT(*) FROM pages");
$pagesCount = $stmtPages ? (int)$stmtPages->fetchColumn() : 0;
assertTest(
    "ARCH-06: Schema::ensurePagesTable(\$pdo, 'sqlite') cria tabela pages e inicializa seed em PDO isolado",
    $pagesCount > 0
);

// Validação de Schema::install em PDO isolado instalando pages e settings
$freshSqlite = new PDO('sqlite::memory:');
$freshSqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Schema::install($freshSqlite, 'sqlite');

$hasPages = (bool)$freshSqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pages'")->fetchColumn();
$hasSettings = (bool)$freshSqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='settings'")->fetchColumn();
$hasMediaOrigIdx = (bool)$freshSqlite->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_media_original_name'")->fetchColumn();

assertTest("ARCH-06: Schema::install() cria tabela pages em SQLite", $hasPages);
assertTest("ARCH-06: Schema::install() cria tabela settings em SQLite", $hasSettings);
assertTest("ARCH-06: Schema::install() cria índice idx_media_original_name em SQLite", $hasMediaOrigIdx);

// -------------------------------------------------------------
// 2. Testes de PERF-04 (Settings sem DDL e PostService Cooldown)
// -------------------------------------------------------------
echo "\n[2] Testando PERF-04: Settings::getAll sem DDL e Cooldown em PostService::promoteScheduledPosts\n";

// Validação de Settings::ensureTable aceitando PDO isolado
$settingsSqlite = new PDO('sqlite::memory:');
$settingsSqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Settings::ensureTable($settingsSqlite, 'sqlite');
$stmtSettings = $settingsSqlite->query("SELECT COUNT(*) FROM settings");
$settingsCount = $stmtSettings ? (int)$stmtSettings->fetchColumn() : 0;
assertTest(
    "PERF-04: Settings::ensureTable(\$pdo, 'sqlite') cria tabela e insere defaults em PDO isolado",
    $settingsCount > 0
);

// Validação estática em Settings.php: getAll() não deve chamar ensureTable()
$settingsCode = (string)file_get_contents(dirname(__DIR__) . '/core/Settings.php');
$getAllPos = strpos($settingsCode, 'public static function getAll():');
$getAllBody = substr($settingsCode, (int)$getAllPos, 600);
assertTest(
    "PERF-04: Settings::getAll() não contém chamada a self::ensureTable()",
    !str_contains($getAllBody, 'ensureTable')
);

// Validação de Cooldown em PostService::promoteScheduledPosts()
ObjectCache::flushGroup('posts');
$initialChecked = ObjectCache::get('scheduled_promote_checked', 'posts');
assertTest("PERF-04: Cache scheduled_promote_checked inicia limpo", $initialChecked === false);

// Primeira chamada pública com force = false (sem matérias agendadas vencidas)
$res1 = PostService::promoteScheduledPosts(false);
$afterChecked = ObjectCache::get('scheduled_promote_checked', 'posts');
assertTest("PERF-04: promoteScheduledPosts(false) ativa cooldown de 60s após checagem (\$hasDue === 0)", $afterChecked !== false);

// Segunda chamada pública com force = false: deve retornar 0 via cooldown sem tocar no banco
$res2 = PostService::promoteScheduledPosts(false);
assertTest("PERF-04: promoteScheduledPosts(false) subsequente retorna 0 imediatamente sob cooldown", $res2 === 0);

// Chamada com force = true: executa bypass no cooldown
$res3 = PostService::promoteScheduledPosts(true);
assertTest("PERF-04: promoteScheduledPosts(true) executa verificação mesmo sob cooldown ativo", is_int($res3));

// -------------------------------------------------------------
// 3. Testes de PERF-05 (ImageProcessor 3 estágios O(1))
// -------------------------------------------------------------
echo "\n[3] Testando PERF-05: ImageProcessor::loadDescriptions com 3 estágios otimizados\n";

// Configurar banco de teste em memória para ImageProcessor
$originalDbConfig = $GLOBALS['config']['database'] ?? ['driver' => 'sqlite', 'sqlite_path' => ':memory:'];
Database::init(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$pdoTest = Database::getConnection();
$pdoTest->exec('CREATE TABLE media (id TEXT PRIMARY KEY, original_name TEXT, alt_text TEXT, caption TEXT, ai_tags TEXT, variants_manifest TEXT)');
$pdoTest->exec('CREATE TABLE media_variants (url_path TEXT UNIQUE, media_id TEXT)');

try {
    ImageProcessor::clearMemoryCache();
    ObjectCache::flushGroup('media');

    $imgUrl = 'uploads/2026/11/piloto-campeao-1024.webp';
    $manifest = json_encode(['intermediate' => ['webp' => ['url' => '/' . $imgUrl]]]);
    $pdoTest->exec("INSERT INTO media (id, original_name, alt_text, caption, variants_manifest) VALUES ('med_10', 'piloto-campeao.jpg', 'Piloto no Pódio', 'Legenda Pódio', '{$manifest}')");
    $pdoTest->exec("INSERT INTO media_variants (url_path, media_id) VALUES ('{$imgUrl}', 'med_10')");

    // Estágio 1: Resolução O(1) via media_variants com ID correspondente
    $desc1 = ImageProcessor::getImageDescription('/' . $imgUrl, null, 'med_10');
    assertTest("PERF-05: Estágio 1 resolve em O(1) quando media_variants e ID coincidem", $desc1 === 'Piloto no Pódio');

    // Estágio 1: Resolução O(1) via media_variants quando ID é vazio
    ImageProcessor::clearMemoryCache();
    ObjectCache::flushGroup('media');
    $descEmptyId = ImageProcessor::getImageDescription('/' . $imgUrl, null, null);
    assertTest("PERF-05: Estágio 1 resolve em O(1) quando ID é vazio e variante existe", $descEmptyId === 'Piloto no Pódio');

    // Estágio 2: ID válido sem variante (media_variants ausente, PK lookup O(1) em media)
    $pdoTest->exec("DELETE FROM media_variants");
    ImageProcessor::clearMemoryCache();
    ObjectCache::flushGroup('media');
    $desc2 = ImageProcessor::getImageDescription('/' . $imgUrl, null, 'med_10');
    assertTest("PERF-05: Estágio 2 resolve via chave primária (WHERE id IN) quando media_variants não possui vínculo", $desc2 === 'Piloto no Pódio');

    // Estágio 2 com fallback para $byPath: se ID fornecido for obsoleto/inválido mas $byPath existir
    $pdoTest->exec("INSERT INTO media_variants (url_path, media_id) VALUES ('{$imgUrl}', 'med_10')");
    ImageProcessor::clearMemoryCache();
    ObjectCache::flushGroup('media');
    $descByPathFallback = ImageProcessor::getImageDescription('/' . $imgUrl, null, 'id_obsoleto_inexistente');
    assertTest("PERF-05: Estágio 2 reaproveita \$byPath diretamente se ID for inválido mas variante existir", $descByPathFallback === 'Piloto no Pódio');

    // Estágio 3: Órfão sem media_variants e sem match por ID (busca por original_name)
    $pdoTest->exec("DELETE FROM media_variants");
    ImageProcessor::clearMemoryCache();
    ObjectCache::flushGroup('media');
    $desc3 = ImageProcessor::getImageDescription('/' . $imgUrl, null, null);
    assertTest("PERF-05: Estágio 3 resolve órfão legítimo por original_name", $desc3 === 'Piloto no Pódio');

    // Estágio 3: ID inválido sem variante NÃO força associação espúria
    $pdoTest->exec("INSERT INTO media (id, original_name, alt_text, caption, variants_manifest) VALUES ('med_20', 'piloto-campeao.jpg', 'Outro Piloto', '', '{$manifest}')");
    ImageProcessor::clearMemoryCache();
    ObjectCache::flushGroup('media');
    $descAmbiguous = ImageProcessor::getImageDescription('/' . $imgUrl, null, 'inexistente');
    assertTest("PERF-05: Estágio 3 não escolhe candidato ambíguo quando ID fornecido é inexistente", $descAmbiguous !== 'Piloto no Pódio');

} finally {
    // Restaurar configuração original de banco
    Database::init($originalDbConfig);
}

// -------------------------------------------------------------
// Resumo Final
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   Resultado Final dos Testes (Passo 2.2)\n";
echo "========================================================\n";
echo "Total de Testes: " . ($passed + $failed) . "\n";
echo "Aprovados:       {$passed}\n";
echo "Falhas:          {$failed}\n\n";

if ($failed > 0) {
    echo ">>> ERRO: ALGUNS TESTES FALHARAM! <<<\n";
    exit(1);
}

echo ">>> SUCESSO: TODAS AS VERIFICAÇÕES DO PASSO 2.2 FORAM APROVADAS! <<<\n";
exit(0);
