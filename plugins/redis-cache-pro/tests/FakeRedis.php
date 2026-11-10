<?php
declare(strict_types=1);

namespace LightPress\Plugin\RedisCachePro\Tests;

use RuntimeException;

/**
 * Dublê determinístico do PhpRedis em memória para testes automatizados.
 */
class FakeRedis
{
    public static array $databases = [];
    public int $db = 0;
    public ?array $queue = null;
    public array $watched = [];
    public bool $failExec = false;
    public bool $shouldFailPing = false;

    public function select(int $db): bool
    {
        $this->db = $db;
        return true;
    }

    private function &store(): array
    {
        if (!isset(self::$databases[$this->db])) {
            self::$databases[$this->db] = [];
        }
        return self::$databases[$this->db];
    }

    private function entry(string $key): ?array
    {
        $store = &$this->store();
        if (isset($store[$key]) && $store[$key]['expires'] > 0 && $store[$key]['expires'] <= microtime(true) * 1000) {
            unset($store[$key]);
        }
        return $store[$key] ?? null;
    }

    public function ping(): string
    {
        if ($this->shouldFailPing) {
            throw new RuntimeException('Connection refused');
        }
        return '+PONG';
    }

    public function get(string $key): mixed
    {
        $entry = $this->entry($key);
        return $entry === null ? false : $entry['value'];
    }

    public function exists(string $key): int
    {
        return $this->entry($key) !== null ? 1 : 0;
    }

    public function set(string $key, mixed $value, array $options = []): bool
    {
        $entry = $this->entry($key);
        if ((in_array('nx', $options, true) && $entry !== null) || (in_array('xx', $options, true) && $entry === null)) {
            return false;
        }

        $ttl = isset($options['ex']) ? $options['ex'] * 1000 : ($options['px'] ?? 0);
        $store = &$this->store();
        $store[$key] = [
            'value'    => $value,
            'expires'  => $ttl ? microtime(true) * 1000 + $ttl : 0,
            'revision' => bin2hex(random_bytes(8)),
            'type'     => is_array($value) ? 'hash' : 'string',
        ];
        return true;
    }

    public function setex(string $key, int $ttl, mixed $value): bool
    {
        return $this->set($key, $value, ['ex' => $ttl]);
    }

    public function del(string $key): int
    {
        $exists = $this->exists($key);
        $store = &$this->store();
        unset($store[$key]);
        return $exists;
    }

    public function unlink(string $key): int
    {
        return $this->del($key);
    }

    public function ttl(string $key): int
    {
        $entry = $this->entry($key);
        if ($entry === null) return -2;
        if ($entry['expires'] === 0) return -1;
        return max(0, (int)ceil(($entry['expires'] - (microtime(true) * 1000)) / 1000));
    }

    public function expire(string $key, int $seconds): bool
    {
        $store = &$this->store();
        if (!isset($store[$key])) return false;
        $store[$key]['expires'] = microtime(true) * 1000 + ($seconds * 1000);
        return true;
    }

    public function hIncrBy(string $key, string $field, int $offset): int
    {
        $val = $this->get($key);
        $val = is_array($val) ? $val : [];
        $val[$field] = ($val[$field] ?? 0) + $offset;
        $this->set($key, $val);
        return $val[$field];
    }

    public function hGetAll(string $key): array
    {
        $val = $this->get($key);
        return is_array($val) ? $val : [];
    }

    public function strlen(string $key): int
    {
        $val = $this->get($key);
        return is_string($val) ? strlen($val) : 0;
    }

    public function hlen(string $key): int
    {
        $val = $this->get($key);
        return is_array($val) ? count($val) : 0;
    }

    public function type(string $key): string
    {
        $entry = $this->entry($key);
        return $entry['type'] ?? 'string';
    }

    public function info(): array
    {
        return [
            'redis_version'       => '7.2.4',
            'used_memory'         => 14200000,
            'used_memory_human'   => '13.54 MB',
            'used_memory_peak'    => 18500000,
            'used_memory_peak_human' => '17.64 MB',
            'maxmemory'           => 134217728, // 128 MB
            'db0'                 => 'keys=' . count($this->store()) . ',expires=0,avg_ttl=0',
        ];
    }

    public function lPush(string $key, mixed $value): int
    {
        $val = $this->get($key);
        $list = is_array($val) ? $val : [];
        array_unshift($list, $value);
        $this->set($key, $list);
        return count($list);
    }

    public function lTrim(string $key, int $start, int $stop): bool
    {
        $val = $this->get($key);
        if (!is_array($val)) return true;
        $length = ($stop - $start) + 1;
        $this->set($key, array_slice($val, $start, $length));
        return true;
    }

    public function lRange(string $key, int $start, int $stop): array
    {
        $val = $this->get($key);
        if (!is_array($val)) return [];
        $length = $stop < 0 ? count($val) : (($stop - $start) + 1);
        return array_slice($val, $start, $length);
    }

    public function rawCommand(string $command, ...$args): mixed
    {
        switch (strtoupper($command)) {
            case 'SCAN':
                $cursor = (int)$args[0];
                $pattern = $args[2] ?? '*';
                $prefix = rtrim($pattern, '*');
                
                $store = $this->store();
                $matched = [];
                foreach (array_keys($store) as $k) {
                    if (str_starts_with($k, $prefix)) {
                        $matched[] = $k;
                    }
                }
                return ['0', $matched];

            case 'MEMORY':
                if (strtoupper($args[0] ?? '') === 'USAGE') {
                    $key = $args[1] ?? '';
                    $entry = $this->entry($key);
                    if ($entry === null) return null;
                    return strlen(serialize($entry['value'])) + 64;
                }
                return null;

            case 'GET':
                return $this->get($args[0]);

            case 'SET':
                return $this->set($args[0], $args[1]);

            case 'TTL':
                return $this->ttl($args[0]);
        }

        return null;
    }

    public static function clear(): void
    {
        self::$databases = [];
    }
}
