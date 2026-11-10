<?php
declare(strict_types=1);

namespace LightPress\Plugin\RedisCachePro;

/**
 * Query Profiler em memória para telemetria de operações de cache da requisição atual.
 */
final class QueryProfiler
{
    /**
     * @var array<int, array{command: string, key: string, caller: string, duration: float, status: string, time: float}>
     */
    private static array $logs = [];
    private static int $maxEntries = 30;

    /**
     * Registra a execução de um comando no profiler e persiste em buffer circular no Redis.
     */
    public static function log(string $command, string $key, float $durationMs, string $status = 'OK', ?string $caller = null): void
    {
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

        // Persistir no Redis em ring buffer (últimas 30 operações persistentes do site)
        static $inLogPush = false;
        if (!$inLogPush) {
            $inLogPush = true;
            try {
                $instance = RedisClient::getInstance();
                $client = $instance->getRawClient();
                if ($client !== null && method_exists($client, 'lPush')) {
                    $config = $instance->getConfig();
                    $listKey = ($config['prefix'] ?? 'lp_cache:') . 'profiler:recent_logs';
                    $payload = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $client->lPush($listKey, $payload);
                    $client->lTrim($listKey, 0, self::$maxEntries - 1);
                    if (method_exists($client, 'expire')) {
                        $client->expire($listKey, 7200);
                    }
                }
            } catch (\Throwable) {
                // Silencioso em caso de inicialização ou indisponibilidade transitória
            } finally {
                $inLogPush = false;
            }
        }
    }

    /**
     * Retorna a lista dos comandos perfilados (lidos do Redis persistente com fallback local).
     *
     * @return array<int, array{command: string, key: string, caller: string, duration: float, status: string, time: float}>
     */
    public static function getLogs(): array
    {
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
     * Limpa os logs em memória.
     */
    public static function reset(): void
    {
        self::$logs = [];
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
