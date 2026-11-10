<?php
declare(strict_types=1);

namespace LightPress\Core;

use Throwable;

/**
 * Camada Unificada e Defensiva de Cache de Objetos em Memória (LightPress ObjectCache).
 * 
 * Atua como um Null Object / Adapter seguro:
 * - Se o plugin `redis-cache-pro` estiver ativo e operacional, direciona para o Redis em RAM.
 * - Se o plugin estiver inativo ou o Redis indisponível, faz fallback transparente e gracioso
 *   para computação direta sem impactar a estabilidade do sistema em produção.
 */
final class ObjectCache
{
    /**
     * Verifica se o motor Redis Cache Pro está carregado e habilitado.
     * Reavalia dinamicamente sem congelar falso positivo durante o ciclo de inicialização (bootstrap).
     */
    public static function isAvailable(): bool
    {
        // 1. Se a classe Facade já está disponível na memória, o plugin está ativo e operacional
        if (class_exists('LightPress\\Plugin\\RedisCachePro\\RedisCache', false)) {
            return true;
        }

        // 2. Proteção contra recursão circular durante o bootstrap de configurações
        static $bootstrapping = false;
        if ($bootstrapping) {
            return false;
        }

        $bootstrapping = true;
        try {
            // Tenta carregar o plugin Redis antecipadamente se ele estiver marcado como ativo
            if (PluginManager::isActive('redis-cache-pro')) {
                PluginManager::loadPlugin('redis-cache-pro');
                return class_exists('LightPress\\Plugin\\RedisCachePro\\RedisCache', false);
            }
        } catch (Throwable) {
            return false;
        } finally {
            $bootstrapping = false;
        }

        return false;
    }

    /**
     * Recupera um valor do cache ou retorna false se não encontrado/inativo.
     */
    public static function get(string $key, string $group = 'default'): mixed
    {
        if (!self::isAvailable()) {
            return false;
        }

        try {
            $found = false;
            $val = \LightPress\Plugin\RedisCachePro\RedisCache::get($key, $group, $found);
            return $found ? $val : false;
        } catch (Throwable $e) {
            error_log('[ObjectCache Get Fallback] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Armazena um valor no cache.
     */
    public static function set(string $key, mixed $value, string $group = 'default', int $ttl = 0): bool
    {
        if (!self::isAvailable()) {
            return true;
        }

        try {
            return \LightPress\Plugin\RedisCachePro\RedisCache::set($key, $value, $group, $ttl);
        } catch (Throwable $e) {
            error_log('[ObjectCache Set Fallback] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove uma chave do cache.
     */
    public static function delete(string $key, string $group = 'default'): bool
    {
        if (!self::isAvailable()) {
            return true;
        }

        try {
            return \LightPress\Plugin\RedisCachePro\RedisCache::delete($key, $group);
        } catch (Throwable $e) {
            error_log('[ObjectCache Delete Fallback] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Método Remember: busca no cache ou executa o callback protegido contra Cache Stampede.
     * Em caso de ausência do Redis, executa o callback diretamente sem falha.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $callback, string $group = 'default'): mixed
    {
        if (self::isAvailable()) {
            try {
                return \LightPress\Plugin\RedisCachePro\RedisCache::remember($key, $ttl, $callback, $group);
            } catch (Throwable $e) {
                error_log('[ObjectCache Remember Fallback] ' . $e->getMessage());
            }
        }

        return $callback();
    }

    /**
     * Invalida atomicamente em O(1) um grupo específico de cache.
     */
    public static function flushGroup(string $group): bool
    {
        if (!self::isAvailable()) {
            return true;
        }

        try {
            return \LightPress\Plugin\RedisCachePro\RedisCache::flushGroup($group);
        } catch (Throwable $e) {
            error_log('[ObjectCache FlushGroup Fallback] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Invalida atomicamente em O(1) todo o cache da aplicação.
     */
    public static function flush(): bool
    {
        if (!self::isAvailable()) {
            return true;
        }

        try {
            return \LightPress\Plugin\RedisCachePro\RedisCache::flush();
        } catch (Throwable $e) {
            error_log('[ObjectCache Flush Fallback] ' . $e->getMessage());
            return false;
        }
    }
}
