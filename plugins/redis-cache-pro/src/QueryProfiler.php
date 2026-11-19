<?php
declare(strict_types=1);

namespace LightPress\Plugin\RedisCachePro;

/**
 * Query Profiler em memória para telemetria de operações de cache da requisição atual.
 * Opt-in para tráfego anônimo (0 overhead de CPU/rede); buffer em memória e pipeline no shutdown.
 */
final class QueryProfiler
{
    /**
     * @var array<int, array{command: string, key: string, caller: string, duration: float, status: string, time: float}>
     */
    private static array $logs = [];

    /**
     * @var array<int, array{command: string, key: string, caller: string, duration: float, status: string, time: float}> Buffer pendente para descarga em pipeline
     */
    private static array $pendingLogs = [];

    private static int $maxEntries = 30;

    /** @var bool|null Override explícito para testes unitários ou ferramentas de diagnóstico */
    private static ?bool $forceEnabled = null;

    /**
     * Define forçadamente se o profiler está ativo (útil para suíte de testes).
     */
    public static function setEnabled(?bool $enabled): void
    {
        self::$forceEnabled = $enabled;
    }

    /**
     * Avalia se o profiler está ativo na requisição corrente.
     * Retorna falso para tráfego anônimo público (evitando debug_backtrace e chamadas ao Redis).
     */
    public static function isEnabled(): bool
    {
        if (self::$forceEnabled !== null) {
            return self::$forceEnabled;
        }

        try {
            $instance = RedisClient::getInstance();
            $config = $instance->getConfig();

            if (!empty($config['profiler_enabled'])) {
                return true;
            }

            $sampleRate = (float)($config['profiler_sample_rate'] ?? 0.0);
            if ($sampleRate > 0.0 && (mt_rand(1, 10000) / 10000) <= $sampleRate) {
                return true;
            }
        } catch (\Throwable) {}

        // Se estiver em rota administrativa
        if (isset($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], '/admin')) {
            return true;
        }

        // Se houver sessão ativa já iniciada de administrador autenticado (sem acionar session_start())
        if (session_status() === PHP_SESSION_ACTIVE) {
            $role = $_SESSION['user']['role'] ?? ($_SESSION['user_role'] ?? '');
            if ($role === 'admin') {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra a execução de um comando no profiler em memória.
     * Não dispara comandos de rede ao Redis de forma síncrona; acumula em lote para o shutdown.
     */
    public static function log(string $command, string $key, float $durationMs, string $status = 'OK', ?string $caller = null): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if ($caller === null) {
            $caller = self::detectCaller();
        }

        $entry = [
            'command'  => strtoupper($command),
            'key'      => $key,
            'caller'   => $caller,
            'duration' => round($durationMs, 3),
            'status'   => $status,
            'time'     => microtime(true),
        ];

        self::$logs[] = $entry;

        if (count(self::$logs) > self::$maxEntries) {
            array_shift(self::$logs);
        }

        self::$pendingLogs[] = $entry;
        if (count(self::$pendingLogs) > self::$maxEntries) {
            array_shift(self::$pendingLogs);
        }
    }

    /**
     * Descarrega os logs pendentes no Redis em uma única ida e volta de rede (Redis::PIPELINE).
     */
    public static function flush(): void
    {
        if (empty(self::$pendingLogs)) {
            return;
        }

        $entries = self::$pendingLogs;
        self::$pendingLogs = [];

        try {
            $instance = RedisClient::getInstance();
            if (!$instance->isConnected()) {
                return;
            }
            $client = $instance->getRawClient();
            if ($client === null) {
                return;
            }

            $config = $instance->getConfig();
            $listKey = ($config['prefix'] ?? 'lp_cache:') . 'profiler:recent_logs';

            $pipe = null;
            if (is_object($client)) {
                if (method_exists($client, 'pipeline')) {
                    $pipe = $client->pipeline();
                } elseif (method_exists($client, 'multi')) {
                    $pipe = $client->multi(\Redis::PIPELINE);
                }
            }

            if (is_object($pipe) && method_exists($pipe, 'lPush')) {
                foreach ($entries as $entry) {
                    $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $pipe->lPush($listKey, $payload);
                }
                if (method_exists($pipe, 'lTrim')) {
                    $pipe->lTrim($listKey, 0, self::$maxEntries - 1);
                }
                if (method_exists($pipe, 'expire')) {
                    $pipe->expire($listKey, 7200);
                }
                if (method_exists($pipe, 'exec')) {
                    $pipe->exec();
                }
            } elseif (is_object($client) && method_exists($client, 'lPush')) {
                foreach ($entries as $entry) {
                    $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $client->lPush($listKey, $payload);
                }
                if (method_exists($client, 'lTrim')) {
                    $client->lTrim($listKey, 0, self::$maxEntries - 1);
                }
                if (method_exists($client, 'expire')) {
                    $client->expire($listKey, 7200);
                }
            }
        } catch (\Throwable) {
            // Silencioso em caso de inicialização ou indisponibilidade transitória
        }
    }

    /**
     * Retorna a lista dos comandos perfilados (lidos do Redis persistente com fallback local).
     *
     * @return array<int, array{command: string, key: string, caller: string, duration: float, status: string, time: float}>
     */
    public static function getLogs(): array
    {
        self::flush();

        try {
            $instance = RedisClient::getInstance();
            $client = $instance->getRawClient();
            if ($client !== null && method_exists($client, 'lRange')) {
                $config = $instance->getConfig();
                $listKey = ($config['prefix'] ?? 'lp_cache:') . 'profiler:recent_logs';
                $rawItems = $client->lRange($listKey, 0, self::$maxEntries - 1);
                if (is_array($rawItems) && !empty($rawItems)) {
                    $parsed = [];
                    foreach ($rawItems as $item) {
                        $decoded = json_decode((string)$item, true);
                        if (is_array($decoded)) {
                            $parsed[] = $decoded;
                        }
                    }
                    if (!empty($parsed)) {
                        return $parsed;
                    }
                }
            }
        } catch (\Throwable) {}

        return array_reverse(self::$logs);
    }

    /**
     * Limpa os logs em memória e no buffer circular do Redis.
     */
    public static function reset(): void
    {
        self::$logs = [];
        self::$pendingLogs = [];

        try {
            $instance = RedisClient::getInstance();
            $client = $instance->getRawClient();
            if ($client !== null && method_exists($client, 'del')) {
                $config = $instance->getConfig();
                $listKey = ($config['prefix'] ?? 'lp_cache:') . 'profiler:recent_logs';
                $client->del($listKey);
            }
        } catch (\Throwable) {}
    }

    /**
     * Identifica a classe/função chamadora relevante excluindo os métodos internos do cache.
     */
    private static function detectCaller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            if (empty($class) || str_contains($class, 'RedisCachePro') || str_contains($class, 'QueryProfiler')) {
                continue;
            }
            $function = $frame['function'] ?? '';
            return basename(str_replace('\\', '/', $class)) . '::' . $function;
        }

        return 'core';
    }
}
