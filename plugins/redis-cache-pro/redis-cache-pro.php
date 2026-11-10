<?php
declare(strict_types=1);

/**
 * Plugin Name: Redis Cache Pro
 * Description: Enterprise-grade persistent object cache engine com PhpRedis, non-blocking UNLINK, circuit breaker, telemetria 24h, big key hunter e monitoramento em tempo real.
 * Version: 1.0.0
 * Author: LightPress / Clean-Room Engineering
 */

namespace LightPress\Plugin\RedisCachePro;

use LightPress\Core\PluginManager;
use LightPress\Core\Hook;
use LightPress\Core\Settings;
use Throwable;

// Carregar dependências do plugin PSR-4 leve
spl_autoload_register(function (string $class): void {
    $prefix = 'LightPress\\Plugin\\RedisCachePro\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Registrar Hooks de Ciclo de Vida do Plugin
PluginManager::registerActivationHook('redis-cache-pro', function (): void {
    // Inicialização e verificação de configurações padrão
    $existing = Settings::get('redis_cache_pro_settings', '');
    if (empty($existing)) {
        $defaults = [
            'enabled'                => true,
            'host'                   => '127.0.0.1',
            'port'                   => 6379,
            'password'               => '',
            'database'               => 0,
            'split_transients_db'    => null,
            'timeout'                => 1.0,
            'prefix'                 => 'lp_cache:',
            'maxttl'                 => 86400,
            'stampede_protection'    => true,
            'serializer'             => 'php',
            'compression'            => 'none',
            'warmup_posts'           => 10,
            'warmup_categories'      => true,
            'warmup_custom_urls'     => '',
            'webhook_url'            => '',
            'alert_email'            => '',
            'memory_threshold'       => 90,
            'notify_circuit_breaker' => true,
            'notify_memory_spike'    => true,
        ];
        Settings::set('redis_cache_pro_settings', json_encode($defaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
});

PluginManager::registerDeactivationHook('redis-cache-pro', function (): void {
    // Limpeza de estado se necessário
});

/**
 * Facade Pública para Acesso Rápido ao Cache do LightPress
 */
final class RedisCache
{
    public static function get(string $key, string $group = 'default', mixed &$found = null): mixed
    {
        return RedisClient::getInstance()->get($key, $group, $found);
    }

    public static function set(string $key, mixed $value, string $group = 'default', int $ttl = 0): bool
    {
        return RedisClient::getInstance()->set($key, $value, $group, $ttl);
    }

    public static function delete(string $key, string $group = 'default'): bool
    {
        return RedisClient::getInstance()->delete($key, $group);
    }

    public static function remember(string $key, int $ttl, callable $callback, string $group = 'default'): mixed
    {
        return RedisClient::getInstance()->remember($key, $ttl, $callback, $group);
    }

    public static function flushGroup(string $group): bool
    {
        return RedisClient::getInstance()->flushGroup($group);
    }

    public static function flush(): bool
    {
        return RedisClient::getInstance()->flush();
    }

    public static function isConnected(): bool
    {
        return RedisClient::getInstance()->isConnected();
    }

    public static function client(): RedisClient
    {
        return RedisClient::getInstance();
    }
}

// Invalidação inteligente automática vinculada a eventos do CMS
Hook::addAction('post_saved', function (int $postId, array $postData = []): void {
    try {
        RedisCache::flushGroup('posts');
        if (!empty($postData['slug'])) {
            RedisCache::delete("post_slug:{$postData['slug']}", 'posts');
        }
        RedisCache::delete("post:{$postId}", 'posts');
    } catch (Throwable) {}
});

Hook::addAction('post_deleted', function (int $postId): void {
    try {
        RedisCache::flushGroup('posts');
        RedisCache::delete("post:{$postId}", 'posts');
    } catch (Throwable) {}
});

Hook::addAction('settings_updated', function (): void {
    try {
        RedisCache::flushGroup('settings');
    } catch (Throwable) {}
});
