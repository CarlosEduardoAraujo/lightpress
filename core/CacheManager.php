<?php
declare(strict_types=1);

namespace LightPress\Core;

use Throwable;

/**
 * Gerenciador de Invalidação Nativa de Disco para Nginx FastCGI Cache.
 * 
 * Calcula o hash MD5 da URL canônica e remove o arquivo estático na árvore de cache.
 * Gerencia filas persistentes de purga em `cache_purge_jobs` com tolerância a falhas.
 */
class CacheManager
{
    private string $cachePath;
    private string $canonicalOrigin;
    private bool $enabled;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $globalConfig = require dirname(__DIR__) . '/config.php';
            $config = $globalConfig;
        }

        $this->cachePath = rtrim($config['cache']['cache_path'] ?? '/var/cache/nginx/lightpress', '/');
        $this->canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');
        $this->enabled = (bool)($config['cache']['enabled'] ?? true);
    }

    /**
     * Calcula o caminho exato do arquivo de cache no disco para o Nginx com levels=1:2.
     * 
     * Conforme seção 7.2:
     * Para $hash = md5($key), o caminho é:
     * {cache_path}/{substr(hash, -1)}/{substr(hash, -3, 2)}/{hash}
     */
    public function getCacheFilePath(string $uri, string $scheme = '', string $host = ''): string
    {
        $uri = '/' . ltrim($uri, '/');
        
        // Se esquema ou host não forem passados, extrai da origem canônica configurada
        if (empty($scheme) || empty($host)) {
            $parts = parse_url($this->canonicalOrigin);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? 'localhost';
            if (!empty($parts['port'])) {
                $host .= ':' . $parts['port'];
            }
        }

        // Chave canônica unificada: $scheme$lp_cache_method$host$request_uri
        // HEAD compartilha o mesmo cache de GET
        $cacheKey = "{$scheme}GET{$host}{$uri}";
        $hash = md5($cacheKey);

        $level1 = substr($hash, -1);       // Último caractere
        $level2 = substr($hash, -3, 2);    // Penúltimo e antepenúltimo

        return "{$this->cachePath}/{$level1}/{$level2}/{$hash}";
    }

    /**
     * Tenta expurgar imediatamente uma lista de URIs do cache do Nginx.
     * Retorna array com status de cada URI: ['/uri' => true/false].
     * 
     * @param string[] $uris
     * @return array<string, bool>
     */
    public function purgeUrls(array $uris): array
    {
        if (!$this->enabled) {
            return array_fill_keys($uris, true);
        }

        $results = [];
        $parts = parse_url($this->canonicalOrigin);
        $configuredScheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'localhost';
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        // Esquemas a expurgar (tanto https quanto http para blindar proxies reversos como Cloudflare)
        $schemes = array_unique([$configuredScheme, 'https', 'http']);

        foreach ($uris as $uri) {
            $cleanUri = '/' . ltrim($uri, '/');
            $purged = true;

            foreach ($schemes as $scheme) {
                // Chave unificada conforme Nginx: $scheme$lp_cache_method$host$request_uri
                $cacheKey = "{$scheme}GET{$host}{$cleanUri}";
                $hash = md5($cacheKey);

                $level1 = substr($hash, -1);
                $level2 = substr($hash, -3, 2);
                $filePath = "{$this->cachePath}/{$level1}/{$level2}/{$hash}";

                if (file_exists($filePath)) {
                    try {
                        if (!@unlink($filePath)) {
                            error_log("[CacheManager] Falha de permissão ao remover arquivo de cache: {$filePath}");
                            $purged = false;
                        }
                    } catch (Throwable $e) {
                        error_log("[CacheManager] Erro ao remover {$filePath}: " . $e->getMessage());
                        $purged = false;
                    }
                }
            }

            $results[$uri] = $purged;
        }

        return $results;
    }

    /**
     * Registra um job de purga na tabela `cache_purge_jobs` dentro da transação editorial.
     *
     * @param int|null $postId
     * @param string $event Ex: 'publish', 'update', 'archive', 'delete'
     * @param string[] $urls Ex: ['/', '/noticia/slug/', '/categoria/geral/']
     * @return int ID do job inserido
     */
    public function recordPurgeJob(?int $postId, string $event, array $urls): int
    {
        $payload = json_encode([
            'version' => 1,
            'urls' => array_values(array_unique($urls)),
            'event' => $event,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR);

        Database::query(
            "INSERT INTO cache_purge_jobs (post_id, event, payload_json, status, attempts, next_run_at)
             VALUES (?, ?, ?, 'pending', 0, CURRENT_TIMESTAMP)",
            [$postId, $event, $payload]
        );

        return (int)Database::lastInsertId();
    }
}
