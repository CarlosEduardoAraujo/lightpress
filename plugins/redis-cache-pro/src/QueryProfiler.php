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
     * Registra a execução de um comando no profiler.
     */
    public static function log(string $command, string $key, float $durationMs, string $status = 'OK', ?string $caller = null): void
    {
        if ($caller === null) {
            $caller = self::detectCaller();
        }

        self::$logs[] = [
            'command'  => strtoupper($command),
            'key'      => $key,
            'caller'   => $caller,
            'duration' => round($durationMs, 3),
            'status'   => $status,
            'time'     => microtime(true),
        ];

        if (count(self::$logs) > self::$maxEntries) {
            array_shift(self::$logs);
        }
    }

    /**
     * Retorna a lista dos comandos perfilados na requisição.
     *
     * @return array<int, array{command: string, key: string, caller: string, duration: float, status: string, time: float}>
     */
    public static function getLogs(): array
    {
        return self::$logs;
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
