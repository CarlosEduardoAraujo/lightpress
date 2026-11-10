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

    public static function getInstance(?array $customConfig = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($customConfig);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function __construct(?array $customConfig = null)
    {
        $this->initConfig($customConfig);
        $this->connect();
    }

    /**
     * Inicializa a configuração mesclando valores padrão com Settings e overrides.
     */
    private function initConfig(?array $customConfig = null): void
    {
        $savedSettings = [];
        try {
            $raw = Settings::get('redis_cache_pro_settings', '[]');
            $savedSettings = json_decode($raw, true) ?: [];
        } catch (Throwable) {
            $savedSettings = [];
        }

        $defaults = [
            'enabled'              => true,
            'host'                 => '127.0.0.1',
            'port'                 => 6379,
            'password'             => '',
            'database'             => 0,
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

            // Conexão via Unix Domain Socket ou TCP
            if (str_starts_with($host, '/')) {
                $connected = $redis->connect($host);
            } else {
                $connected = $redis->connect($host, $port, $timeout);
            }

            if (!$connected) {
                $this->tripCircuitBreaker("Falha de handshake com Redis em {$host}:{$port}");
                return false;
            }

            // Autenticação com senha
            $password = trim((string)$this->config['password']);
            if ($password !== '') {
                if (!$redis->auth($password)) {
                    $this->tripCircuitBreaker("Falha na autenticação do Redis.");
                    return false;
                }
            }

            // Seleção de Banco
            $db = (int)$this->config['database'];
            if ($db > 0) {
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

            if (str_starts_with($host, '/')) {
                $tRedis->connect($host);
            } else {
                $tRedis->connect($host, $port, $timeout);
            }

            $password = trim((string)$this->config['password']);
            if ($password !== '') {
                $tRedis->auth($password);
            }

            $tRedis->select($db);
            $this->redisTransients = $tRedis;
        } catch (Throwable) {
            $this->redisTransients = null;
        }
    }

    /**
     * Verifica se o Redis está operacional.
     */
    public function isConnected(): bool
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
            $this->tripCircuitBreaker('Ping falhou.');
            return false;
        }
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
        $hasLock = false;

        if (!empty($this->config['stampede_protection']) && $this->isConnected()) {
            try {
                $hasLock = (bool)$this->redis->set($lockKey, '1', ['nx', 'px' => (int)$this->config['stampede_lock_ms']]);
            } catch (Throwable) {
                $hasLock = true;
            }
        } else {
            $hasLock = true;
        }

        // Se não conseguiu o lock, espera brevemente (50ms) e tenta obter o valor recalculado
        if (!$hasLock) {
            usleep(50000); // 50ms
            $val = $this->get($key, $group, $found);
            if ($found) {
                return $val;
            }
        }

        try {
            $freshVal = $callback();
            $this->set($key, $freshVal, $group, $ttl);
            return $freshVal;
        } finally {
            if ($hasLock && $this->isConnected()) {
                try {
                    $this->redis->del($lockKey);
                } catch (Throwable) {}
            }
        }
    }

    /**
     * Registra métricas de telemetria em bucket de 1 hora e no acumulador agregado persistente.
     */
    private function recordTelemetry(int $hits = 0, int $misses = 0, float $latencyMs = 0.0, int $commands = 0): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $hourBucket = date('Y-m-d-H');
        $telemetryKey = "{$this->config['prefix']}telemetry:{$hourBucket}";
        $aggregateKey = "{$this->config['prefix']}telemetry:aggregate";

        try {
            if ($hits > 0) {
                $this->redis->hIncrBy($telemetryKey, 'hits', $hits);
                $this->redis->hIncrBy($aggregateKey, 'hits', $hits);
            }
            if ($misses > 0) {
                $this->redis->hIncrBy($telemetryKey, 'misses', $misses);
                $this->redis->hIncrBy($aggregateKey, 'misses', $misses);
            }
            if ($commands > 0) {
                $this->redis->hIncrBy($telemetryKey, 'commands', $commands);
                $this->redis->hIncrBy($aggregateKey, 'commands', $commands);
            }
            if ($latencyMs > 0) {
                $scaled = (int)($latencyMs * 100);
                $this->redis->hIncrBy($telemetryKey, 'latency_ms_total', $scaled);
                $this->redis->hIncrBy($aggregateKey, 'latency_ms_total', $scaled);
                $this->redis->hIncrBy($aggregateKey, 'latency_count', 1);
            }

            // Garante TTL de 48h no bucket horário
            if (method_exists($this->redis, 'expire')) {
                $this->redis->expire($telemetryKey, 172800);
            }
        } catch (Throwable) {}
    }

    /**
     * Retorna o histórico de telemetria das últimas 24 horas formatado para o gráfico SVG.
     *
     * @return array<int, array{hour: string, label: string, hits: int, misses: int, commands: int, hit_ratio: float, avg_latency_ms: float}>
     */
    public function get24HourTelemetry(): array
    {
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
