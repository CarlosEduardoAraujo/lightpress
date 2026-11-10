<?php
declare(strict_types=1);

namespace LightPress\Plugin\RedisCachePro;

use Throwable;
use RuntimeException;

/**
 * Manutenção incremental de memória e scanner de chaves pesadas (Big Key Hunter).
 * Utiliza exclusivamente SCAN e UNLINK com orçamento de tempo estrito; nunca roda KEYS ou FLUSHDB.
 */
final class CacheMaintenance
{
    private RedisClient $client;
    private ?object $redis;
    private array $config;

    public function __construct(?RedisClient $client = null)
    {
        $this->client = $client ?? RedisClient::getInstance();
        $this->redis = $this->client->getRawClient();
        $this->config = $this->client->getConfig();
    }

    /**
     * Localiza as maiores chaves armazenadas no Redis (Big Key Hunter) de forma não-bloqueante.
     *
     * @return array<int, array{key: string, type: string, size_bytes: int, size_human: string, ttl: int}>
     */
    public function scanBigKeys(int $limit = 15, int $maxScanKeys = 1000): array
    {
        if ($this->redis === null || !$this->client->isConnected()) {
            return [];
        }

        $prefix = (string)($this->config['prefix'] ?? 'lp_cache:');
        $pattern = $prefix . '*';
        $cursor = '0';
        $examined = 0;
        $items = [];

        do {
            try {
                // Suporte a rawCommand SCAN ou método scan do PhpRedis
                if (method_exists($this->redis, 'rawCommand')) {
                    $page = $this->redis->rawCommand('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 50);
                    if (!is_array($page) || count($page) < 2) {
                        break;
                    }
                    $cursor = (string)$page[0];
                    $keys = (array)$page[1];
                } elseif (method_exists($this->redis, 'scan')) {
                    $c = (int)$cursor;
                    $keys = $this->redis->scan($c, $pattern, 50) ?: [];
                    $cursor = (string)$c;
                } else {
                    break;
                }
            } catch (Throwable) {
                break;
            }

            foreach ($keys as $key) {
                $examined++;
                $bytes = $this->measureKeyBytes($key);
                $type = $this->getKeyType($key);
                $ttl = $this->getKeyTtl($key);

                $items[] = [
                    'key'        => $key,
                    'type'       => $type,
                    'size_bytes' => $bytes,
                    'size_human' => $this->formatBytes($bytes),
                    'ttl'        => $ttl,
                ];

                if ($examined >= $maxScanKeys) {
                    break 2;
                }
            }

        } while ($cursor !== '0' && $cursor !== 0 && $cursor !== '');

        // Ordenar decrescente por tamanho em bytes
        usort($items, fn($a, $b) => $b['size_bytes'] <=> $a['size_bytes']);

        return array_slice($items, 0, $limit);
    }

    /**
     * Executa um passo da coleta de gerações obsoletas ou análise diagnóstica com limite de tempo (budget_ms).
     *
     * @param array<string, mixed> $state Estado acumulado anterior
     * @param bool $cleanup Se true, executa UNLINK nas chaves obsoletas
     * @param int $batchLimit Máximo de chaves por passo
     * @param int $budgetMs Tempo máximo em milissegundos
     * @return array<string, mixed> Novo estado com estatísticas e cursor
     */
    public function step(array $state, bool $cleanup = false, int $batchLimit = 100, int $budgetMs = 150): array
    {
        if ($this->redis === null || !$this->client->isConnected()) {
            throw new RuntimeException('Redis indisponível para manutenção.');
        }

        $prefix = (string)($this->config['prefix'] ?? 'lp_cache:');

        if (empty($state) || !isset($state['cursor'])) {
            $state = [
                'cursor'                 => '0',
                'complete'               => false,
                'cleanup'                => $cleanup,
                'examined'               => 0,
                'deleted'                => 0,
                'measured_bytes'         => 0,
                'deleted_bytes_estimate' => 0,
                'categories'             => [
                    'current'         => ['keys' => 0, 'bytes' => 0],
                    'obsolete_global' => ['keys' => 0, 'bytes' => 0],
                    'obsolete_group'  => ['keys' => 0, 'bytes' => 0],
                    'metadata'        => ['keys' => 0, 'bytes' => 0],
                    'unknown'         => ['keys' => 0, 'bytes' => 0],
                ],
                'started_at'             => time(),
                'updated_at'             => time(),
            ];
        }

        if ($state['complete']) {
            return $state;
        }

        $deadline = microtime(true) + (max(20, min(500, $budgetMs)) / 1000);
        $pattern = $prefix . '*';
        $processed = 0;

        // Recuperar versão global ativa para classificação segura
        $activeGlobalVersion = '';
        try {
            $vKey = $prefix . 'global:version';
            $vVal = $this->redis->get($vKey);
            if (is_string($vVal)) {
                $activeGlobalVersion = $vVal;
            }
        } catch (Throwable) {}

        while ($processed < $batchLimit && microtime(true) < $deadline) {
            $keys = [];
            try {
                if (method_exists($this->redis, 'rawCommand')) {
                    $page = $this->redis->rawCommand('SCAN', $state['cursor'], 'MATCH', $pattern, 'COUNT', 50);
                    if (!is_array($page) || count($page) < 2) {
                        $state['complete'] = true;
                        break;
                    }
                    $state['cursor'] = (string)$page[0];
                    $keys = (array)$page[1];
                } elseif (method_exists($this->redis, 'scan')) {
                    $c = (int)$state['cursor'];
                    $keys = $this->redis->scan($c, $pattern, 50) ?: [];
                    $state['cursor'] = (string)$c;
                } else {
                    $state['complete'] = true;
                    break;
                }
            } catch (Throwable) {
                $state['complete'] = true;
                break;
            }

            foreach ($keys as $key) {
                $processed++;
                $state['examined']++;

                $bytes = $this->measureKeyBytes($key);
                $state['measured_bytes'] += $bytes;

                $classification = $this->classifyKey($key, $prefix, $activeGlobalVersion);
                $category = $classification['status'];

                if (!isset($state['categories'][$category])) {
                    $state['categories'][$category] = ['keys' => 0, 'bytes' => 0];
                }
                $state['categories'][$category]['keys']++;
                $state['categories'][$category]['bytes'] += $bytes;

                // Se cleanup estiver ativado e a chave for de geração obsoleta, desaloque com UNLINK
                if ($cleanup && in_array($category, ['obsolete_global', 'obsolete_group'], true)) {
                    $deleted = false;
                    try {
                        if (method_exists($this->redis, 'unlink')) {
                            $deleted = (bool)$this->redis->unlink($key);
                        } else {
                            $deleted = (bool)$this->redis->del($key);
                        }
                    } catch (Throwable) {}

                    if ($deleted) {
                        $state['deleted']++;
                        $state['deleted_bytes_estimate'] += $bytes;
                    }
                }

                if (microtime(true) >= $deadline) {
                    break;
                }
            }

            if ($state['cursor'] === '0' || $state['cursor'] === 0 || $state['cursor'] === '') {
                $state['complete'] = true;
                break;
            }
        }

        $state['updated_at'] = time();
        return $state;
    }

    /**
     * Remove uma chave específica via UNLINK se ela pertencer ao prefixo do site.
     */
    public function deleteSingleKey(string $key): bool
    {
        if ($this->redis === null || !$this->client->isConnected()) {
            return false;
        }

        $prefix = (string)($this->config['prefix'] ?? 'lp_cache:');
        if (!str_starts_with($key, $prefix)) {
            return false; // Proteção contra apagar chaves fora do escopo do LightPress
        }

        try {
            if (method_exists($this->redis, 'unlink')) {
                return (bool)$this->redis->unlink($key);
            }
            return (bool)$this->redis->del($key);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Classifica o status de uma chave encontrada no scan.
     *
     * @return array{status: 'current'|'obsolete_global'|'obsolete_group'|'metadata'|'unknown', version?: string}
     */
    private function classifyKey(string $key, string $prefix, string $activeGlobalVersion): array
    {
        // Chaves de metadados e telemetria
        if (str_contains($key, ':version') || str_contains($key, ':telemetry:')) {
            return ['status' => 'metadata'];
        }

        // Padrão canônico: {prefix}v{global_ver}:g{group_ver}:{group}:{key}
        $escapedPrefix = preg_quote($prefix, '/');
        if (preg_match('/^' . $escapedPrefix . 'v([a-f0-9]{16,32}):g([a-f0-9]{8,32}):([^:]+):/D', $key, $match)) {
            $keyGlobalVer = $match[1];
            $keyGroupVer = $match[2];
            $groupName = $match[3];

            if ($activeGlobalVersion !== '' && $keyGlobalVer !== $activeGlobalVersion) {
                return ['status' => 'obsolete_global', 'version' => $keyGlobalVer];
            }

            // Checar versão do grupo
            try {
                $gKey = $prefix . "group:{$groupName}:version";
                $currGVer = $this->redis->get($gKey);
                if (is_string($currGVer) && $currGVer !== '' && $currGVer !== $keyGroupVer) {
                    return ['status' => 'obsolete_group', 'version' => $keyGlobalVer];
                }
            } catch (Throwable) {}

            return ['status' => 'current', 'version' => $keyGlobalVer];
        }

        return ['status' => 'unknown'];
    }

    /**
     * Mede o uso de memória em bytes de uma chave no Redis.
     */
    private function measureKeyBytes(string $key): int
    {
        try {
            if (method_exists($this->redis, 'rawCommand')) {
                $usage = $this->redis->rawCommand('MEMORY', 'USAGE', $key);
                if (is_int($usage) && $usage >= 0) {
                    return $usage;
                }
            }
        } catch (Throwable) {}

        // Fallback aproximado baseado no tipo de dado
        try {
            $type = $this->getKeyType($key);
            if ($type === 'string' && method_exists($this->redis, 'strlen')) {
                return (int)$this->redis->strlen($key) + 64;
            } elseif ($type === 'hash' && method_exists($this->redis, 'hlen')) {
                return ((int)$this->redis->hlen($key) * 128) + 128;
            }
        } catch (Throwable) {}

        return 256; // Estimativa padrão conservadora
    }

    private function getKeyType(string $key): string
    {
        try {
            if (method_exists($this->redis, 'type')) {
                $type = $this->redis->type($key);
                return match ($type) {
                    1, 'string' => 'string',
                    2, 'set'    => 'set',
                    3, 'list'   => 'list',
                    4, 'zset'   => 'zset',
                    5, 'hash'   => 'hash',
                    default     => 'other',
                };
            }
        } catch (Throwable) {}
        return 'string';
    }

    private function getKeyTtl(string $key): int
    {
        try {
            if (method_exists($this->redis, 'ttl')) {
                return (int)$this->redis->ttl($key);
            }
        } catch (Throwable) {}
        return -1;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
