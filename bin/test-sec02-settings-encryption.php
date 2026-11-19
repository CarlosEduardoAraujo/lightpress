<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados - Validação da Correção SEC-02 (CWE-665)
 * Falha de ordem de inicialização e descriptografia de segredos em Settings.
 * Execução: php bin/test-sec02-settings-encryption.php
 */

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ObjectCache;
use LightPress\Core\PluginManager;
use LightPress\Core\Settings;
use LightPress\Plugin\RedisCachePro\RedisClient;

echo "========================================================\n";
echo "   LightPress - Validação de Segurança: SEC-02 (CWE-665)\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// 1. Injeção Explícita de Chave e Fallback (core/Settings.php)
// -------------------------------------------------------------
echo "[1] Testando Injeção Explícita de Chave e Fallback Resiliente\n";

// Salvar estado original de $GLOBALS['config']
$originalGlobalConfig = $GLOBALS['config'] ?? [];

// Resetar Settings para estado limpo
Settings::reset();
$GLOBALS['config'] = [];

assertTest(
    "Sem chave em Settings e sem globals, getEncryptionKey() retorna null",
    Settings::getEncryptionKey() === null
);

// Injeção via string direta
$rawKey1 = 'minha-chave-secreta-32-bytes-alpha';
Settings::init($rawKey1);
$expectedKey1 = hash('sha256', $rawKey1, true);
assertTest(
    "Settings::init(string) define chave binária de 32 bytes derivada via SHA-256",
    Settings::getEncryptionKey() === $expectedKey1 && strlen((string)Settings::getEncryptionKey()) === 32
);

// Injeção via array $config
$rawKey2 = 'outra-chave-secreta-via-array-config';
Settings::init(['security' => ['encryption_key' => $rawKey2]]);
$expectedKey2 = hash('sha256', $rawKey2, true);
assertTest(
    "Settings::init(array) extrai security.encryption_key e deriva chave de 32 bytes",
    Settings::getEncryptionKey() === $expectedKey2
);

// Fallback para $GLOBALS['config'] se init(null)
Settings::init(null);
$rawKey3 = 'chave-armazenada-apenas-em-globals';
$GLOBALS['config'] = ['security' => ['encryption_key' => $rawKey3]];
$expectedKey3 = hash('sha256', $rawKey3, true);
assertTest(
    "Quando não inicializado explicitamente, efetua fallback transparente para \$GLOBALS['config']",
    Settings::getEncryptionKey() === $expectedKey3
);

// Precedência da chave explícita sobre globals
Settings::init($rawKey1);
assertTest(
    "Chave explícita via Settings::init() tem precedência sobre \$GLOBALS['config']",
    Settings::getEncryptionKey() === $expectedKey1
);

// Guarda estrita contra string vazia e espaços em branco
$GLOBALS['config'] = [];

Settings::init('');
assertTest(
    "Settings::init('') atribui null e NÃO calcula hash de string vazia",
    Settings::getEncryptionKey() === null
);

Settings::init('   ');
assertTest(
    "Settings::init('   ') com espaços em branco atribui null",
    Settings::getEncryptionKey() === null
);

Settings::init(['security' => ['encryption_key' => '']]);
assertTest(
    "Settings::init(array) com chave vazia atribui null",
    Settings::getEncryptionKey() === null
);

Settings::init(['security' => ['encryption_key' => '   ']]);
assertTest(
    "Settings::init(array) com espaços em branco atribui null",
    Settings::getEncryptionKey() === null
);

Settings::init(null);
$GLOBALS['config'] = ['security' => ['encryption_key' => '']];
assertTest(
    "Fallback para \$GLOBALS['config'] com chave vazia retorna null",
    Settings::getEncryptionKey() === null
);

$GLOBALS['config'] = ['security' => ['encryption_key' => '   ']];
assertTest(
    "Fallback para \$GLOBALS['config'] com espaços em branco retorna null",
    Settings::getEncryptionKey() === null
);

// Restaurar globals
$GLOBALS['config'] = $originalGlobalConfig;
Settings::init($originalGlobalConfig['security']['encryption_key'] ?? null);

// -------------------------------------------------------------
// 2. Cifragem e Decifragem Transparente de Dados Sensíveis
// -------------------------------------------------------------
echo "\n[2] Testando Cifragem e Decifragem em Repouso\n";

$testKey = 'segredo-criptografico-de-alta-seguranca';
Settings::init($testKey);

$payloadOriginal = json_encode([
    'host'     => '10.0.0.50',
    'port'     => 6380,
    'password' => 'super_secret_redis_pass_2026',
    'database' => 2,
], JSON_UNESCAPED_SLASHES);

$encrypted = Settings::encryptValue($payloadOriginal);
assertTest(
    "encryptValue() gera payload cifrado com prefixo 'enc:'",
    str_starts_with($encrypted, 'enc:') && $encrypted !== $payloadOriginal
);

$decrypted = Settings::decryptValue($encrypted);
assertTest(
    "decryptValue() restaura com perfeição o texto original com a chave correta",
    $decrypted === $payloadOriginal
);

// Decifragem sem chave (falha graciosa)
Settings::init(null);
$previousGlobals = $GLOBALS['config'];
$GLOBALS['config'] = [];

assertTest(
    "decryptValue() sem chave retorna ciphertext bruto sem lançar exceção",
    Settings::decryptValue($encrypted) === $encrypted
);

// Restaurar estado
$GLOBALS['config'] = $previousGlobals;
Settings::init($originalGlobalConfig['security']['encryption_key'] ?? null);

// -------------------------------------------------------------
// 3. Persistência de SENSITIVE_KEYS no Banco e Recuperação
// -------------------------------------------------------------
echo "\n[3] Testando Persistência de Chaves Sensíveis (redis_cache_pro_settings)\n";

$origRedisSettings = Database::fetchValue("SELECT value FROM settings WHERE key = 'redis_cache_pro_settings'");

try {
    Settings::init('chave-teste-banco-dados-123456');
    Settings::set('redis_cache_pro_settings', $payloadOriginal);

    // Consulta direta no SQLite para verificar se gravou CIFRADO no banco
    $rawDbValue = (string)Database::fetchValue("SELECT value FROM settings WHERE key = 'redis_cache_pro_settings'");
    assertTest(
        "Valor de 'redis_cache_pro_settings' está fisicamente cifrado no banco (enc:...)",
        str_starts_with($rawDbValue, 'enc:')
    );

    // Leitura via Settings::get() com descriptografia automática
    $fetchedDecrypted = Settings::get('redis_cache_pro_settings');
    assertTest(
        "Settings::get('redis_cache_pro_settings') retorna payload JSON decifrado",
        $fetchedDecrypted === $payloadOriginal
    );

    $decodedData = json_decode((string)$fetchedDecrypted, true);
    assertTest(
        "json_decode() do valor decifrado recupera host e senha corretos",
        is_array($decodedData)
        && ($decodedData['host'] ?? '') === '10.0.0.50'
        && ($decodedData['password'] ?? '') === 'super_secret_redis_pass_2026'
    );
} finally {
    if ($origRedisSettings !== null && $origRedisSettings !== false) {
        Database::query("UPDATE settings SET value = ? WHERE key = 'redis_cache_pro_settings'", [(string)$origRedisSettings]);
    } else {
        Database::query("DELETE FROM settings WHERE key = 'redis_cache_pro_settings'");
    }
    Settings::flushCache();
    Settings::init($originalGlobalConfig['security']['encryption_key'] ?? null);
}

// -------------------------------------------------------------
// 4. Teste Isolado em Subprocesso: Ordem de Boot Real (core/bootstrap.php)
// -------------------------------------------------------------
echo "\n[4] Testando Subprocesso Isolado: Boot Limpo com Segredo Cifrado\n";

// Criar script PHP temporário para simular um processo do zero
$tempScript = sys_get_temp_dir() . '/lp_sec02_boot_test_' . bin2hex(random_bytes(6)) . '.php';
$testDbFile = str_replace('\\', '/', sys_get_temp_dir() . '/lp_sec02_db_' . bin2hex(random_bytes(6)) . '.sqlite');
$projectRoot = str_replace('\\', '/', dirname(__DIR__));

$testScriptContent = <<<PHP
<?php
declare(strict_types=1);

\$rootDir = '{$projectRoot}';
chdir(\$rootDir);
\$_SERVER['DOCUMENT_ROOT'] = \$rootDir;

\$config = require \$rootDir . '/core/bootstrap.php';

// Validar se \$GLOBALS['config'] já estava disponível e Settings decifrou com sucesso
\$retrieved = \\LightPress\\Core\\Settings::get('redis_cache_pro_settings');
\$parsed = json_decode((string)(\$retrieved ?? ''), true);

// Validar se o plugin redis-cache-pro foi carregado e o RedisClient obteve as credenciais decifradas
\$redisClientLoaded = class_exists(\\LightPress\\Plugin\\RedisCachePro\\RedisClient::class, false);
\$redisConfig = \$redisClientLoaded ? \\LightPress\\Plugin\\RedisCachePro\\RedisClient::getInstance()->getConfig() : [];

\$redisDecryptedOk = (\$redisConfig['host'] ?? '') === 'redis-cluster.internal'
    && (\$redisConfig['password'] ?? '') === 'P@sswordEnterprise2026!';

\$success = is_array(\$parsed)
    && (\$parsed['host'] ?? '') === 'redis-cluster.internal'
    && (\$parsed['password'] ?? '') === 'P@sswordEnterprise2026!'
    && \$redisClientLoaded
    && \$redisDecryptedOk;

echo json_encode([
    'ok' => \$success,
    'raw' => \$retrieved,
    'parsed' => \$parsed,
    'has_globals' => !empty(\$GLOBALS['config']),
    'has_enc_key' => \\LightPress\\Core\\Settings::getEncryptionKey() !== null,
    'redis_client_loaded' => \$redisClientLoaded,
    'redis_decrypted_ok' => \$redisDecryptedOk,
    'redis_host' => \$redisConfig['host'] ?? null,
]);
PHP;

// Preparar banco temporário com tabela settings e valor cifrado
$derivedKey = hash('sha256', 'teste-chave-boot-isolado-32-chars-ok', true);
$testPayload = json_encode([
    'host' => 'redis-cluster.internal',
    'port' => 6379,
    'password' => 'P@sswordEnterprise2026!',
]);

// Cifrar usando sodium ou openssl
$ciphertext = '';
if (function_exists('openssl_encrypt')) {
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($testPayload, 'aes-256-gcm', $derivedKey, OPENSSL_RAW_DATA, $iv, $tag);
    $ciphertext = 'enc:gcm:' . base64_encode($iv . $tag . $ct);
} else {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct = sodium_crypto_secretbox($testPayload, $nonce, $derivedKey);
    $ciphertext = 'enc:' . base64_encode($nonce . $ct);
}

// Criar banco temporário e inserir registro
$tempPdo = new PDO('sqlite:' . $testDbFile);
$tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tempPdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NULL, created_at TEXT, updated_at TEXT);");
$stmt = $tempPdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
$stmt->execute(['redis_cache_pro_settings', $ciphertext]);
$stmt->execute(['active_plugins', json_encode(['redis-cache-pro'])]);

// Fechar explicitamente statement e conexão PDO antes de invocar o subprocesso
// para desimpedir locks POSIX no Linux antes da transição para journal_mode = WAL
$stmt->closeCursor();
$stmt = null;
$tempPdo = null;

// Criar config.local.php temporário para forçar o subprocesso a usar o banco temporário e a chave
$localConfigBackup = file_exists(__DIR__ . '/../config.local.php') ? file_get_contents(__DIR__ . '/../config.local.php') : null;
$tempLocalConfig = <<<PHP
<?php
declare(strict_types=1);

return [
    'app' => [
        'env' => 'development',
        'debug' => false,
        'log_deprecations' => true,
    ],
    'database' => [
        'driver' => 'sqlite',
        'sqlite_path' => '{$testDbFile}',
    ],
    'security' => [
        'hmac_secret' => 'c01d4a0f441b4b609e25d25e83ecf3424d1e860953a9254c7b80a2c918341604',
        'encryption_key' => 'teste-chave-boot-isolado-32-chars-ok',
    ],
];
PHP;

file_put_contents(__DIR__ . '/../config.local.php', $tempLocalConfig);
file_put_contents($tempScript, $testScriptContent);

try {
    $process = proc_open(
        [PHP_BINARY, $tempScript, $testDbFile],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    $stdout = '';
    $stderr = '';
    $exitCode = -1;

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
    }

    $result = json_decode((string)$stdout, true);
    $detail = "ExitCode: {$exitCode}"
        . ($stderr !== '' ? " | Stderr: " . trim($stderr) : '')
        . ($stdout !== '' ? " | Stdout: " . trim($stdout) : '');

    assertTest(
        "Subprocesso isolado executou bootstrap limpo sem erros",
        is_array($result) && $exitCode === 0,
        $detail
    );

    assertTest(
        "Subprocesso reporta \$GLOBALS['config'] e chave de criptografia inicializadas no boot",
        !empty($result['has_globals']) && !empty($result['has_enc_key'])
    );

    assertTest(
        "Settings::get('redis_cache_pro_settings') decifrou com sucesso durante o boot inicial",
        !empty($result['ok']) && ($result['parsed']['host'] ?? '') === 'redis-cluster.internal'
    );

    assertTest(
        "Subprocesso carregou antecipadamente o plugin redis-cache-pro via ObjectCache::isAvailable()",
        !empty($result['redis_client_loaded'])
    );

    assertTest(
        "RedisClient::getInstance() obteve host e senha decifrados do banco (sem fallback para defaults)",
        !empty($result['redis_decrypted_ok']) && ($result['redis_host'] ?? '') === 'redis-cluster.internal'
    );
} finally {
    // Limpeza de arquivos temporários e restauração do config.local.php original
    @unlink($tempScript);
    @unlink($testDbFile);
    @unlink($testDbFile . '-wal');
    @unlink($testDbFile . '-shm');
    if ($localConfigBackup !== null) {
        file_put_contents(__DIR__ . '/../config.local.php', $localConfigBackup);
    } else {
        @unlink(__DIR__ . '/../config.local.php');
    }
}

// -------------------------------------------------------------
// Relatório Final
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   Resultado Final dos Testes (SEC-02)\n";
echo "========================================================\n";
echo "Total de Testes: " . ($passed + $failed) . "\n";
echo "Aprovados:       {$passed}\n";
echo "Falhas:          {$failed}\n\n";

if ($failed === 0) {
    echo ">>> SUCESSO: TODAS AS VERIFICAÇÕES DE SEC-02 FORAM APROVADAS! <<<\n\n";
    exit(0);
} else {
    echo ">>> ERRO: FALHAS DETECTADAS NA VERIFICAÇÃO DE SEC-02! <<<\n\n";
    exit(1);
}
