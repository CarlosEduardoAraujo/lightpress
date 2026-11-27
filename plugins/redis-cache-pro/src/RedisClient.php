<?php
declare(strict_types=1);

namespace LightPress\Plugin\RedisCachePro;

use LightPress\Core\Settings;
use Redis;
use RedisException;
use Throwable;

/**
 * Cliente PhpRedis de alta performance para o LightPress.
 * Incorpora Circuit Breaker, Versionamento Atômico O(1), Proteção Dogpile Lock e Telemetria 24h.
 */
class RedisClient
{
    private static ?self $instance = null;

    /** @var Redis|object|null Instância primária do PhpRedis ou dublê */
    private $redis = null;

    /** @var Redis|object|null Instância secundária para Split DB (transientes) */
    private $redisTransients = null;

    /** @var array<string, array<string, mixed>> Cache efêmero em memória da requisição */
    private array $localCache = [];

    /** @var array<string, mixed> Configurações ativas */
    private array $config = [];

    /** @var string Estado do Circuit Breaker: 'CLOSED', 'OPEN', 'HALF_OPEN' */
    private string $breakerState = 'CLOSED';
    private float $breakerLastFailure = 0.0;
    private int $breakerFailures = 0;
    private const BREAKER_FAILURE_THRESHOLD = 3;
    private const BREAKER_COOLDOWN_SECONDS = 30.0;

    /** @var string Versão global do namespace para invalidação O(1) */
    private ?string $globalVersion = null;

    /** @var array<string, string> Versões de grupos de cache */
    private array $groupVersions = [];

    /** @var array{hits: int, misses: int, memory_hits: int, commands: int, latency_sum_ms: float} */
    private array $runtimeStats = [
        'hits'           => 0,
        'misses'         => 0,
        'memory_hits'    => 0,
        'commands'       => 0,
        'latency_sum_ms' => 0.0,
    ];

    /** @var array{hits: int, misses: int, commands: int, latency_ms_total: float, latency_count: int} Buffer de telemetria pendente para pipeline */
    private array $pendingTelemetry = [
        'hits'             => 0,
        'misses'           => 0,
        'commands'         => 0,
        'latency_ms_total' => 0.0,
        'latency_count'    => 0,
    ];

    private static bool $shutdownRegistered = false;

    public static function getInstance(?array $customConfig = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($customConfig);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            self::$instance->flushBuffers();
        }
        self::$instance = null;
    }

    public function __construct(?array $customConfig = null)
    {
        $this->initConfig($customConfig);
        $this->connect();
        $this->registerShutdown();
    }

    private function registerShutdown(): void
    {
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                self::$instance?->flushBuffers();
            });
        }
    }

    /**
     * Descarrega buffers em lote no Redis (telemetria e profiler).
     */
    public function flushBuffers(): void
    {
        $this->flushTelemetry();
        QueryProfiler::flush();
    }

    /**
     * Inicializa a configuração mesclando valores padrão com Settings e overrides.
     */
    private function initConfig(?array $customConfig = null): void
    {
        $savedSettings = [];
        try {
            $raw = Settings::hasCache()
                ? Settings::get('redis_cache_pro_settings', '[]')
                : Settings::getDirect('redis_cache_pro_settings', '[]');
            $savedSettings = json_decode((string)($raw ?? '[]'), true) ?: [];
        } catch (Throwable) {
            $savedSettings = [];
        }

        $defaults = [
            'enabled'              => true,
            'host'                 => '127.0.0.1',
            'port'                 => 6379,
            'password'             => '',
            'database'             => 0,
            'persistent'           => false, // Suporte a conexões persistentes via pconnect
            'split_transients_db'  => null, // Ex: 1 para transients
            'timeout'              => 1.0,  // Segundos
            'read_timeout'         => 1.0,
            'prefix'               => 'lp_cache:',
            'maxttl'               => 86400, // 24 horas padrão
            'stampede_protection'  => true,
            'stampede_lock_ms'     => 5000,
            'serializer'           => 'php', // 'igbinary' ou 'php'
            'compression'          => 'none', // 'zstd', 'lz4', 'none'
            'async_flush'          => true,  // Usa UNLINK em vez de DEL
            'profiler_enabled'     => false, // Opt-in para admins ou diagnóstico
            'profiler_sample_rate' => 0.0,   // Taxa de amostragem pública (0.0 = desativado)
        ];

        $constantConfig = [];
        if (defined('WP_REDIS_CONFIG') && is_array(WP_REDIS_CONFIG)) {
            $constantConfig = WP_REDIS_CONFIG;
        }

        $this->config = array_merge($defaults, $savedSettings, $constantConfig, $customConfig ?? []);
    }

    /**
     * Permite injetar um cliente mock/dublê de testes (ex: FakeRedis).
     */
    public function setClient(object $client, ?object $transientsClient = null): void
    {
        $this->redis = $client;
        $this->redisTransients = $transientsClient;
        $this->breakerState = 'CLOSED';
        $this->breakerFailures = 0;
        $this->loadGlobalVersion();
    }

    /**
     * Estabelece conexão com o servidor Redis via extensão PhpRedis nativa.
     */
    private function connect(): bool
    {
        if (!$this->config['enabled']) {
            return false;
        }

        if (!extension_loaded('redis')) {
            $this->tripCircuitBreaker('Extensão phpredis não instalada no PHP.');
            return false;
        }

        if ($this->redis !== null && $this->isConnected()) {
            return true;
        }

        $startTime = microtime(true);

        try {
            $redis = new Redis();
            $host = (string)$this->config['host'];
            $port = (int)$this->config['port'];
            $timeout = (float)$this->config['timeout'];

            $isPersistent = (bool)($this->config['persistent'] ?? false);
            $db = (int)$this->config['database'];
            $persistentId = "lp_redis_db_{$db}";

            // Conexão via Unix Domain Socket ou TCP (com suporte a conexões persistentes)
            if (str_starts_with($host, '/')) {
                $connected = $isPersistent
                    ? @$redis->pconnect($host, null, $timeout, $persistentId)
                    : @$redis->connect($host);
            } else {
                $connected = $isPersistent
                    ? @$redis->pconnect($host, $port, $timeout, $persistentId)
                    : @$redis->connect($host, $port, $timeout);
            }

            if (!$connected) {
                $this->tripCircuitBreaker("Falha de handshake com Redis em {$host}:{$port}");
                return false;
            }

            // Autenticação com senha
            $password = trim((string)$this->config['password']);
            if ($password !== '') {
                if (!@$redis->auth($password)) {
                    $this->tripCircuitBreaker("Falha na autenticação do Redis.");
                    return false;
                }
            }

            // Seleção de Banco (garantida mesmo em conexão persistente reutilizada)
            if ($db >= 0) {
                $redis->select($db);
            }

            $redis->setOption(Redis::OPT_READ_TIMEOUT, (string)$this->config['read_timeout']);

            // Configurar Serializador
            if ($this->config['serializer'] === 'igbinary' && defined('Redis::SERIALIZER_IGBINARY')) {
                $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
            } else {
                $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
            }

            // Configurar Compressão se suportada
            if ($this->config['compression'] === 'zstd' && defined('Redis::COMPRESSION_ZSTD')) {
                $redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
            } elseif ($this->config['compression'] === 'lz4' && defined('Redis::COMPRESSION_LZ4')) {
                $redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
            }

            $this->redis = $redis;
            $this->breakerState = 'CLOSED';
            $this->breakerFailures = 0;

            // Inicializar Split DB para transientes se configurado
            $splitDb = $this->config['split_transients_db'];
            if ($splitDb !== null && (int)$splitDb !== $db) {
                $this->connectTransientsClient((int)$splitDb);
            }

            $this->loadGlobalVersion();

            $duration = (microtime(true) - $startTime) * 1000;
            QueryProfiler::log('CONNECT', "{$host}:{$port}/db{$db}", $duration, 'CONNECTED');

            return true;

        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Exceção ao conectar: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Conexão secundária para banco de transientes (Split DB).
     */
    private function connectTransientsClient(int $db): void
    {
        try {
            $tRedis = new Redis();
            $host = (string)$this->config['host'];
            $port = (int)$this->config['port'];
            $timeout = (float)$this->config['timeout'];

            $isPersistent = (bool)($this->config['persistent'] ?? false);
            $persistentId = "lp_redis_db_{$db}_trans";

            if (str_starts_with($host, '/')) {
                $connected = $isPersistent
                    ? @$tRedis->pconnect($host, null, $timeout, $persistentId)
                    : @$tRedis->connect($host);
            } else {
                $connected = $isPersistent
                    ? @$tRedis->pconnect($host, $port, $timeout, $persistentId)
                    : @$tRedis->connect($host, $port, $timeout);
            }

            if (!$connected) {
                $this->redisTransients = null;
                return;
            }

            $password = trim((string)$this->config['password']);
            if ($password !== '') {
                @$tRedis->auth($password);
            }

            $tRedis->select($db);
            $this->redisTransients = $tRedis;
        } catch (Throwable) {
            $this->redisTransients = null;
        }
    }

    /**
     * Verifica se o Redis está operacional.
     * Em estado CLOSED normal, confia na conexão estabelecida (0 RTTs de rede).
     * Dispara PING apenas se em HALF_OPEN (teste de reabilitação) ou se $forceProbe for true.
     */
    public function isConnected(bool $forceProbe = false): bool
    {
        if ($this->redis === null) {
            return false;
        }

        if ($this->breakerState === 'OPEN') {
            if (microtime(true) - $this->breakerLastFailure > self::BREAKER_COOLDOWN_SECONDS) {
                $this->breakerState = 'HALF_OPEN';
            } else {
                return false;
            }
        }

        if ($this->breakerState === 'HALF_OPEN' || $forceProbe) {
            try {
                $ping = $this->redis->ping();
                if ($ping === true || $ping === '+PONG' || $ping === 'PONG') {
                    if ($this->breakerState === 'HALF_OPEN') {
                        $this->breakerState = 'CLOSED';
                        $this->breakerFailures = 0;
                    }
                    return true;
                }
                return false;
            } catch (Throwable) {
                $this->tripCircuitBreaker('Ping de verificação falhou.');
                return false;
            }
        }

        return true;
    }

    /**
     * Dispara o Circuit Breaker ao acumular falhas consecutivas.
     */
    private function tripCircuitBreaker(string $reason): void
    {
        $this->breakerFailures++;
        $this->breakerLastFailure = microtime(true);

        if ($this->breakerFailures >= self::BREAKER_FAILURE_THRESHOLD) {
            $this->breakerState = 'OPEN';
            
            // Disparar Alerta assíncrono para os administradores
            AlertService::notifyCircuitBreaker($reason, $this->config);
        }
    }

    /**
     * Recupera o token de versão global para invalidação atômica em O(1).
     */
    private function loadGlobalVersion(): void
    {
        if ($this->redis === null) {
            return;
        }

        $versionKey = $this->config['prefix'] . 'global:version';
        try {
            $version = $this->redis->get($versionKey);
            if (is_string($version) && preg_match('/^[a-f0-9]{32}$/', $version)) {
                $this->globalVersion = $version;
            } else {
                $this->globalVersion = bin2hex(random_bytes(16));
                $this->redis->set($versionKey, $this->globalVersion);
            }
        } catch (Throwable) {
            $this->globalVersion = 'fallback000000000000000000000000';
        }
    }

    /**
     * Recupera o token de versão do grupo para invalidação granular.
     */
    private function getGroupVersion(string $group): string
    {
        if (isset($this->groupVersions[$group])) {
            return $this->groupVersions[$group];
        }

        if ($this->redis === null || !$this->isConnected()) {
            return 'g0';
        }

        $groupKey = "{$this->config['prefix']}group:{$group}:version";
        try {
            $version = $this->redis->get($groupKey);
            if (is_string($version) && $version !== '') {
                $this->groupVersions[$group] = $version;
                return $version;
            }
            $newVer = bin2hex(random_bytes(8));
            $this->redis->set($groupKey, $newVer);
            $this->groupVersions[$group] = $newVer;
            return $newVer;
        } catch (Throwable) {
            return 'g0';
        }
    }

    /**
     * Constrói a chave canônica prefixada e versionada no Redis.
     * Formato: {prefix}v{global_version}:g{group_version}:{group}:{key}
     */
    public function buildKey(string $key, string $group = 'default'): string
    {
        if ($this->globalVersion === null) {
            $this->loadGlobalVersion();
        }
        $gVer = $this->getGroupVersion($group);
        return "{$this->config['prefix']}v{$this->globalVersion}:g{$gVer}:{$group}:{$key}";
    }

    /**
     * Seleciona o cliente Redis apropriado (split DB ou principal).
     */
    private function getTargetClient(string $group): ?object
    {
        if ($group === 'transient' && $this->redisTransients !== null) {
            return $this->redisTransients;
        }
        return $this->redis;
    }

    /**
     * Recupera um valor do cache de objetos.
     */
    public function get(string $key, string $group = 'default', mixed &$found = null): mixed
    {
        $startTime = microtime(true);

        // 1. Verificação no cache de memória local da requisição
        if (isset($this->localCache[$group][$key])) {
            $this->runtimeStats['hits']++;
            $this->runtimeStats['memory_hits']++;
            $found = true;
            $duration = (microtime(true) - $startTime) * 1000;
            QueryProfiler::log('GET (MEMORY)', "{$group}:{$key}", $duration, 'HIT');
            return $this->localCache[$group][$key];
        }

        // 2. Se o Redis estiver indisponível ou Circuit Breaker aberto, fallback
        if (!$this->isConnected()) {
            $this->runtimeStats['misses']++;
            $found = false;
            return false;
        }

        $client = $this->getTargetClient($group);
        $redisKey = $this->buildKey($key, $group);

        try {
            $value = $client->get($redisKey);
            $duration = (microtime(true) - $startTime) * 1000;
            $this->runtimeStats['commands']++;
            $this->runtimeStats['latency_sum_ms'] += $duration;

            if ($value !== false) {
                $this->runtimeStats['hits']++;
                $this->localCache[$group][$key] = $value;
                $this->recordTelemetry(1, 0, $duration);
                $found = true;
                QueryProfiler::log('GET', $redisKey, $duration, 'HIT');
                return $value;
            }

            $this->runtimeStats['misses']++;
            $this->recordTelemetry(0, 1, $duration);
            $found = false;
            QueryProfiler::log('GET', $redisKey, $duration, 'MISS');
            return false;

        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro em GET: ' . $e->getMessage());
            $this->runtimeStats['misses']++;
            $found = false;
            return false;
        }
    }

    /**
     * Grava um valor no cache de objetos.
     */
    public function set(string $key, mixed $value, string $group = 'default', int $ttl = 0): bool
    {
        $startTime = microtime(true);
        $this->localCache[$group][$key] = $value;

        if (!$this->isConnected()) {
            return true; // Salvo em memória local
        }

        $client = $this->getTargetClient($group);
        $redisKey = $this->buildKey($key, $group);
        $effectiveTtl = $ttl > 0 ? $ttl : (int)$this->config['maxttl'];

        try {
            if ($effectiveTtl > 0) {
                $res = $client->setex($redisKey, $effectiveTtl, $value);
            } else {
                $res = $client->set($redisKey, $value);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->runtimeStats['commands']++;
            $this->runtimeStats['latency_sum_ms'] += $duration;
            $this->recordTelemetry(0, 0, $duration, 1);

            QueryProfiler::log('SET', $redisKey, $duration, $res ? 'OK' : 'FAIL');
            return (bool)$res;

        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro em SET: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera múltiplos valores em uma única operação atômica de rede.
     *
     * @param array<int, string> $keys
     * @param string $group
     * @return array<string, mixed> Mapa chave => valor (ou false)
     */
    public function mGet(array $keys, string $group = 'default'): array
    {
        if (empty($keys)) {
            return [];
        }

        $startTime = microtime(true);
        $results = [];
        $missingKeys = [];
        $keyMap = []; // redisKey => logicalKey

        // 1. Verifica cache local em memória
        foreach ($keys as $key) {
            if (isset($this->localCache[$group][$key])) {
                $results[$key] = $this->localCache[$group][$key];
                $this->runtimeStats['hits']++;
                $this->runtimeStats['memory_hits']++;
            } else {
                $missingKeys[] = $key;
            }
        }

        if (empty($missingKeys)) {
            $duration = (microtime(true) - $startTime) * 1000;
            QueryProfiler::log('MGET (MEMORY)', "{$group}:" . count($keys) . ' keys', $duration, 'HIT');
            return $results;
        }

        // 2. Se desconectado / disjuntor aberto, completa com false
        if (!$this->isConnected()) {
            foreach ($missingKeys as $key) {
                $results[$key] = false;
                $this->runtimeStats['misses']++;
            }
            return $results;
        }

        // 3. Monta mapeamento de chaves
        foreach ($missingKeys as $key) {
            $redisKey = $this->buildKey($key, $group);
            $keyMap[$redisKey] = $key;
        }

        $client = $this->getTargetClient($group);
        if ($client === null) {
            foreach ($missingKeys as $key) {
                $results[$key] = false;
                $this->runtimeStats['misses']++;
            }
            return $results;
        }
        $redisKeys = array_keys($keyMap);

        try {
            $rawValues = $client->mGet($redisKeys);
            $duration = (microtime(true) - $startTime) * 1000;
            $this->runtimeStats['commands']++;
            $this->runtimeStats['latency_sum_ms'] += $duration;

            $hits = 0;
            $misses = 0;

            if (is_array($rawValues)) {
                $idx = 0;
                foreach ($keyMap as $rKey => $logicalKey) {
                    $val = $rawValues[$idx] ?? ($rawValues[$rKey] ?? false);
                    $idx++;

                    if ($val !== false) {
                        $results[$logicalKey] = $val;
                        $this->localCache[$group][$logicalKey] = $val;
                        $this->runtimeStats['hits']++;
                        $hits++;
                    } else {
                        $results[$logicalKey] = false;
                        $this->runtimeStats['misses']++;
                        $misses++;
                    }
                }
            } else {
                foreach ($missingKeys as $key) {
                    $results[$key] = false;
                    $this->runtimeStats['misses']++;
                    $misses++;
                }
            }

            $this->recordTelemetry($hits, $misses, $duration, 1);
            QueryProfiler::log('MGET', "{$group}:" . count($missingKeys) . ' keys', $duration, "H:{$hits}/M:{$misses}");
            return $results;

        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro em MGET: ' . $e->getMessage());
            foreach ($missingKeys as $key) {
                $results[$key] = false;
                $this->runtimeStats['misses']++;
            }
            return $results;
        }
    }

    /**
     * Grava múltiplos valores no cache de uma só vez (via Pipeline).
     *
     * @param array<string, mixed> $items Mapa chave => valor
     * @param string $group
     * @param int $ttl
     * @return bool
     */
    public function setMultiple(array $items, string $group = 'default', int $ttl = 0): bool
    {
        if (empty($items)) {
            return true;
        }

        $startTime = microtime(true);
        foreach ($items as $key => $val) {
            $this->localCache[$group][$key] = $val;
        }

        if (!$this->isConnected()) {
            return true; // Salvo em memória local
        }

        $client = $this->getTargetClient($group);
        if ($client === null) {
            return true;
        }
        $effectiveTtl = $ttl > 0 ? $ttl : (int)$this->config['maxttl'];

        try {
            $pipe = null;
            if (is_object($client)) {
                if (method_exists($client, 'pipeline')) {
                    $pipe = $client->pipeline();
                } elseif (method_exists($client, 'multi')) {
                    $pipe = $client->multi(Redis::PIPELINE);
                }
            }

            if (is_object($pipe)) {
                foreach ($items as $key => $val) {
                    $redisKey = $this->buildKey($key, $group);
                    if ($effectiveTtl > 0) {
                        $pipe->setex($redisKey, $effectiveTtl, $val);
                    } else {
                        $pipe->set($redisKey, $val);
                    }
                }
                if (method_exists($pipe, 'exec')) {
                    $pipe->exec();
                }
            } else {
                foreach ($items as $key => $val) {
                    $redisKey = $this->buildKey($key, $group);
                    if ($effectiveTtl > 0) {
                        $client->setex($redisKey, $effectiveTtl, $val);
                    } else {
                        $client->set($redisKey, $val);
                    }
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->runtimeStats['commands']++;
            $this->runtimeStats['latency_sum_ms'] += $duration;
            $this->recordTelemetry(0, 0, $duration, count($items));

            QueryProfiler::log('MSET/PIPELINE', "{$group}:" . count($items) . ' keys', $duration, 'OK');
            return true;

        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro em setMultiple: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove uma chave do cache.
     */
    public function delete(string $key, string $group = 'default'): bool
    {
        $startTime = microtime(true);
        unset($this->localCache[$group][$key]);

        if (!$this->isConnected()) {
            return true;
        }

        $client = $this->getTargetClient($group);
        $redisKey = $this->buildKey($key, $group);

        try {
            // UNLINK não bloqueante se suportado
            if (!empty($this->config['async_flush']) && method_exists($client, 'unlink')) {
                $res = $client->unlink($redisKey);
            } else {
                $res = $client->del($redisKey);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->runtimeStats['commands']++;
            QueryProfiler::log('UNLINK/DEL', $redisKey, $duration, 'OK');
            return (bool)$res;

        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro em DELETE: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invalidação Atômica O(1) de um grupo específico de cache.
     */
    public function flushGroup(string $group): bool
    {
        $startTime = microtime(true);
        unset($this->localCache[$group]);

        if (!$this->isConnected()) {
            return true;
        }

        $newVer = bin2hex(random_bytes(8));
        $this->groupVersions[$group] = $newVer;
        $groupKey = "{$this->config['prefix']}group:{$group}:version";

        try {
            $this->redis->set($groupKey, $newVer);
            $duration = (microtime(true) - $startTime) * 1000;
            QueryProfiler::log('FLUSH_GROUP', "group:{$group} -> v{$newVer}", $duration, 'OK');
            return true;
        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro em FLUSH_GROUP: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invalidação Global Atômica em O(1) de todo o cache deste site/namespace.
     * Elimina a necessidade de FLUSHDB bloqueante.
     */
    public function flush(): bool
    {
        $startTime = microtime(true);
        $this->localCache = [];
        $this->groupVersions = [];

        if (!$this->isConnected()) {
            return true;
        }

        $versionKey = $this->config['prefix'] . 'global:version';
        $newGlobalVer = bin2hex(random_bytes(16));
        $this->globalVersion = $newGlobalVer;

        try {
            $this->redis->set($versionKey, $newGlobalVer);
            $duration = (microtime(true) - $startTime) * 1000;
            QueryProfiler::log('FLUSH_GLOBAL', "namespace:v{$newGlobalVer}", $duration, 'OK');
            return true;
        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro em FLUSH_GLOBAL: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Empacota o token de lock garantindo compatibilidade binária exata no Lua.
     */
    private function packLockToken(string $token): string
    {
        if ($this->redis !== null && method_exists($this->redis, '_pack')) {
            return (string)$this->redis->_pack($token);
        }
        return $token;
    }

    /**
     * Liberação atômica condicional do lock via script Lua.
     */
    private function releaseLock(string $lockKey, string $lockToken): bool
    {
        if ($this->redis === null || !$this->isConnected()) {
            return false;
        }
        try {
            $lua = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
            $packed = $this->packLockToken($lockToken);
            return (bool)$this->redis->eval($lua, [$lockKey, $packed], 1);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Método Remember: busca a chave ou computa o callback protegendo contra Cache Stampede.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, int $ttl, callable $callback, string $group = 'default'): mixed
    {
        $found = false;
        $val = $this->get($key, $group, $found);
        if ($found) {
            return $val;
        }

        // Se Proteção contra Stampede estiver ativa, adquire lock atômico
        $lockKey = 'lock:' . $this->buildKey($key, $group);
        $lockToken = bin2hex(random_bytes(16));
        $lockTtlMs = (int)($this->config['stampede_lock_ms'] ?? 5000);
        $hasLock = false;

        if (!empty($this->config['stampede_protection']) && $this->isConnected()) {
            try {
                $hasLock = (bool)$this->redis->set($lockKey, $lockToken, ['nx', 'px' => $lockTtlMs]);
            } catch (Throwable) {
                $hasLock = true;
            }
        } else {
            $hasLock = true;
        }

        // Se não conseguiu o lock, entra no laço de polling com relógio monotônico hrtime(true)
        if (!$hasLock) {
            $deadline = hrtime(true) + 500_000_000; // Orçamento monotônico de 500ms
            $sleepUs = 25000;
            while (hrtime(true) < $deadline) {
                usleep($sleepUs);
                $sleepUs = min(75000, $sleepUs + 15000);
                try {
                    $val = $this->get($key, $group, $found);
                    if ($found) {
                        return $val;
                    }
                    if (!$this->redis->exists($lockKey)) {
                        $hasLock = (bool)$this->redis->set($lockKey, $lockToken, ['nx', 'px' => $lockTtlMs]);
                        if ($hasLock) {
                            $val = $this->get($key, $group, $found);
                            if ($found) {
                                $this->releaseLock($lockKey, $lockToken);
                                return $val;
                            }
                            break;
                        }
                    }
                } catch (Throwable) {
                    break;
                }
            }
            if ($found) {
                return $val;
            }
            if (!$hasLock && $this->isConnected()) {
                try {
                    $hasLock = (bool)$this->redis->set($lockKey, $lockToken, ['nx', 'px' => $lockTtlMs]);
                    if ($hasLock) {
                        $val = $this->get($key, $group, $found);
                        if ($found) {
                            $this->releaseLock($lockKey, $lockToken);
                            return $val;
                        }
                    }
                } catch (Throwable) {}
            }
        }

        try {
            $freshVal = $callback();
            $this->set($key, $freshVal, $group, $ttl);
            return $freshVal;
        } finally {
            if ($hasLock) {
                $this->releaseLock($lockKey, $lockToken);
            }
        }
    }

    /**
     * Registra deltas de telemetria em memória durante a requisição (0 RTTs).
     * O envio consolidado ocorre no shutdown via Pipeline atômico.
     */
    private function recordTelemetry(int $hits = 0, int $misses = 0, float $latencyMs = 0.0, int $commands = 0): void
    {
        if ($hits > 0) {
            $this->pendingTelemetry['hits'] += $hits;
        }
        if ($misses > 0) {
            $this->pendingTelemetry['misses'] += $misses;
        }
        if ($commands > 0) {
            $this->pendingTelemetry['commands'] += $commands;
        }
        if ($latencyMs > 0) {
            $this->pendingTelemetry['latency_ms_total'] += $latencyMs;
            $this->pendingTelemetry['latency_count'] += 1;
        }
    }

    /**
     * Descarrega os deltas de telemetria acumulados em memória em um único Redis::PIPELINE.
     */
    public function flushTelemetry(): void
    {
        if ($this->redis === null || !$this->isConnected()) {
            return;
        }

        if ($this->pendingTelemetry['hits'] === 0 &&
            $this->pendingTelemetry['misses'] === 0 &&
            $this->pendingTelemetry['commands'] === 0 &&
            $this->pendingTelemetry['latency_ms_total'] === 0.0) {
            return;
        }

        $hourBucket = date('Y-m-d-H');
        $telemetryKey = "{$this->config['prefix']}telemetry:{$hourBucket}";
        $aggregateKey = "{$this->config['prefix']}telemetry:aggregate";

        $hits = $this->pendingTelemetry['hits'];
        $misses = $this->pendingTelemetry['misses'];
        $commands = $this->pendingTelemetry['commands'];
        $latencyMs = $this->pendingTelemetry['latency_ms_total'];
        $latencyCount = $this->pendingTelemetry['latency_count'];

        // Reseta o acumulador imediatamente
        $this->pendingTelemetry = [
            'hits'             => 0,
            'misses'           => 0,
            'commands'         => 0,
            'latency_ms_total' => 0.0,
            'latency_count'    => 0,
        ];

        try {
            $client = $this->redis;
            $pipe = null;
            if (is_object($client)) {
                if (method_exists($client, 'pipeline')) {
                    $pipe = $client->pipeline();
                } elseif (method_exists($client, 'multi')) {
                    $pipe = $client->multi(Redis::PIPELINE);
                }
            }

            if (is_object($pipe)) {
                if ($hits > 0) {
                    $pipe->hIncrBy($telemetryKey, 'hits', $hits);
                    $pipe->hIncrBy($aggregateKey, 'hits', $hits);
                }
                if ($misses > 0) {
                    $pipe->hIncrBy($telemetryKey, 'misses', $misses);
                    $pipe->hIncrBy($aggregateKey, 'misses', $misses);
                }
                if ($commands > 0) {
                    $pipe->hIncrBy($telemetryKey, 'commands', $commands);
                    $pipe->hIncrBy($aggregateKey, 'commands', $commands);
                }
                if ($latencyMs > 0) {
                    $scaled = (int)($latencyMs * 100);
                    $pipe->hIncrBy($telemetryKey, 'latency_ms_total', $scaled);
                    $pipe->hIncrBy($aggregateKey, 'latency_ms_total', $scaled);
                    $pipe->hIncrBy($aggregateKey, 'latency_count', $latencyCount);
                }
                if (method_exists($pipe, 'expire')) {
                    $pipe->expire($telemetryKey, 172800);
                }
                if (method_exists($pipe, 'exec')) {
                    $pipe->exec();
                }
            } else {
                if ($hits > 0) {
                    $client->hIncrBy($telemetryKey, 'hits', $hits);
                    $client->hIncrBy($aggregateKey, 'hits', $hits);
                }
                if ($misses > 0) {
                    $client->hIncrBy($telemetryKey, 'misses', $misses);
                    $client->hIncrBy($aggregateKey, 'misses', $misses);
                }
                if ($commands > 0) {
                    $client->hIncrBy($telemetryKey, 'commands', $commands);
                    $client->hIncrBy($aggregateKey, 'commands', $commands);
                }
                if ($latencyMs > 0) {
                    $scaled = (int)($latencyMs * 100);
                    $client->hIncrBy($telemetryKey, 'latency_ms_total', $scaled);
                    $client->hIncrBy($aggregateKey, 'latency_ms_total', $scaled);
                    $client->hIncrBy($aggregateKey, 'latency_count', $latencyCount);
                }
                if (method_exists($client, 'expire')) {
                    $client->expire($telemetryKey, 172800);
                }
            }
        } catch (Throwable $e) {
            $this->tripCircuitBreaker('Erro ao descarregar telemetria: ' . $e->getMessage());
        }
    }

    /**
     * Retorna o histórico de telemetria das últimas 24 horas formatado para o gráfico SVG.
     *
     * @return array<int, array{hour: string, label: string, hits: int, misses: int, commands: int, hit_ratio: float, avg_latency_ms: float}>
     */
    public function get24HourTelemetry(): array
    {
        $this->flushTelemetry();
        $data = [];
        $now = time();

        for ($i = 23; $i >= 0; $i--) {
            $timestamp = $now - ($i * 3600);
            $bucket = date('Y-m-d-H', $timestamp);
            $label = date('H:00', $timestamp);

            $hits = 0;
            $misses = 0;
            $commands = 0;
            $latencyTotalMs = 0;

            if ($this->isConnected()) {
                $key = "{$this->config['prefix']}telemetry:{$bucket}";
                try {
                    $stats = $this->redis->hGetAll($key) ?: [];
                    $hits = (int)($stats['hits'] ?? 0);
                    $misses = (int)($stats['misses'] ?? 0);
                    $commands = (int)($stats['commands'] ?? 0) + $hits + $misses;
                    $latencyTotalMs = (int)($stats['latency_ms_total'] ?? 0) / 100;
                } catch (Throwable) {}
            }

            $totalOps = $hits + $misses;
            $hitRatio = $totalOps > 0 ? round(($hits / $totalOps) * 100, 1) : 0.0;
            $avgLatency = $totalOps > 0 ? round($latencyTotalMs / $totalOps, 2) : 0.0;

            $data[] = [
                'hour'           => $bucket,
                'label'          => $label,
                'hits'           => $hits,
                'misses'         => $misses,
                'commands'       => $commands,
                'hit_ratio'      => $hitRatio,
                'avg_latency_ms' => $avgLatency,
            ];
        }

        return $data;
    }

    /**
     * Retorna diagnóstico geral de status, métricas persistentes, RTT real e memória do Redis.
     *
     * @return array<string, mixed>
     */
    public function getDiagnosticInfo(): array
    {
        $this->flushTelemetry();
        $connected = $this->isConnected();

        // 1. Ler agregador persistente de telemetria no Redis
        $aggHits = 0;
        $aggMisses = 0;
        $aggCommands = 0;
        $aggLatencyMs = 0.0;
        $aggLatencyCount = 0;

        if ($connected) {
            try {
                $agg = $this->redis->hGetAll("{$this->config['prefix']}telemetry:aggregate");
                if (is_array($agg) && !empty($agg)) {
                    $aggHits = (int)($agg['hits'] ?? 0);
                    $aggMisses = (int)($agg['misses'] ?? 0);
                    $aggCommands = (int)($agg['commands'] ?? 0);
                    $aggLatencyMs = ((int)($agg['latency_ms_total'] ?? 0)) / 100;
                    $aggLatencyCount = (int)($agg['latency_count'] ?? 0);
                }
            } catch (Throwable) {}
        }

        // 2. Medição Real de RTT através de PING direto ao daemon
        $realRtt = 0.0;
        if ($connected) {
            try {
                $pingStart = microtime(true);
                $this->redis->ping();
                $realRtt = round((microtime(true) - $pingStart) * 1000, 2);
            } catch (Throwable) {
                $realRtt = 0.0;
            }
        }

        // 3. Consolidar métricas persistentes + requisição corrente
        $totalHits = $aggHits + $this->runtimeStats['hits'];
        $totalMisses = $aggMisses + $this->runtimeStats['misses'];
        $totalCommands = $aggCommands + $this->runtimeStats['commands'];
        $totalOps = $totalHits + $totalMisses;
        $hitRatio = $totalOps > 0 ? round(($totalHits / $totalOps) * 100, 1) : 0.0;

        $totalLatencySamples = $aggLatencyCount + ($this->runtimeStats['hits'] + $this->runtimeStats['misses']);
        $totalLatencyMs = $aggLatencyMs + $this->runtimeStats['latency_sum_ms'];
        $avgLatency = $totalLatencySamples > 0
            ? round($totalLatencyMs / $totalLatencySamples, 2)
            : $realRtt;

        $usedMemory = '0 MB';
        $peakMemory = '0 MB';
        $redisVersion = 'Indisponível';
        $maxMemory = 'Sem limite';
        $keysCount = 0;

        if ($connected && method_exists($this->redis, 'info')) {
            try {
                $info = $this->redis->info();
                $usedMemory = $info['used_memory_human'] ?? ($info['used_memory'] ? round($info['used_memory'] / 1048576, 2) . ' MB' : '0 MB');
                $peakMemory = $info['used_memory_peak_human'] ?? ($info['used_memory_peak'] ? round($info['used_memory_peak'] / 1048576, 2) . ' MB' : '0 MB');
                $redisVersion = $info['redis_version'] ?? '7.x';
                $maxMemory = !empty($info['maxmemory']) ? round($info['maxmemory'] / 1048576, 2) . ' MB' : 'Sem limite';
                $dbKey = 'db' . (int)$this->config['database'];
                if (isset($info[$dbKey]) && preg_match('/keys=(\d+)/', (string)$info[$dbKey], $matches)) {
                    $keysCount = (int)$matches[1];
                }
            } catch (Throwable) {}
        }

        return [
            'status'            => $connected ? 'connected' : ($this->breakerState === 'OPEN' ? 'degraded' : 'disconnected'),
            'circuit_breaker'   => $this->breakerState,
            'hit_ratio'         => $hitRatio,
            'hits'              => $totalHits,
            'misses'            => $totalMisses,
            'commands_count'    => $totalCommands,
            'runtime_commands'  => $this->runtimeStats['commands'],
            'avg_latency_ms'    => $avgLatency,
            'ping_rtt_ms'       => $realRtt,
            'used_memory'       => $usedMemory,
            'peak_memory'       => $peakMemory,
            'max_memory'        => $maxMemory,
            'keys_count'        => $keysCount,
            'redis_version'     => $redisVersion,
            'phpredis_loaded'   => extension_loaded('redis'),
            'phpredis_version'  => phpversion('redis') ?: 'N/A',
            'zstd_support'      => defined('Redis::COMPRESSION_ZSTD'),
            'lz4_support'       => defined('Redis::COMPRESSION_LZ4'),
            'igbinary_support'  => defined('Redis::SERIALIZER_IGBINARY'),
            'split_db_active'   => !empty($this->config['split_transients_db']),
            'stampede_active'   => !empty($this->config['stampede_protection']),
            'config'            => $this->config,
        ];
    }

    public function getRawClient(): ?object
    {
        return $this->redis;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
