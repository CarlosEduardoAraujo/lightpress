<?php
declare(strict_types=1);

namespace LightPress\Core;

use Throwable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Gerenciador de Invalidação Nativa de Disco para Nginx FastCGI Cache e Borda Cloudflare.
 * 
 * Calcula o hash MD5 da URL canônica e remove o arquivo estático na árvore de cache.
 * Gerencia filas persistentes de purga em `cache_purge_jobs` com tolerância a falhas.
 * Dispara automaticamente a purga seletiva na Cloudflare via CloudflareCache quando habilitada.
 */
class CacheManager
{
    private string $cachePath;
    private string $canonicalOrigin;
    private bool $enabled;
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $globalConfig = require dirname(__DIR__) . '/config.php';
            $config = $globalConfig;
        }

        $this->config = $config;
        $this->cachePath = rtrim($config['cache']['cache_path'] ?? '/var/cache/nginx/lightpress', '/');
        $this->canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'https://www.speedracing.com.br', '/');
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
        // Se a URI for uma URL absoluta, extrai o caminho e host
        if (preg_match('#^https?://#i', $uri)) {
            $parsed = parse_url($uri);
            $cleanUri = $parsed['path'] ?? '/';
            if (!empty($parsed['query'])) {
                $cleanUri .= '?' . $parsed['query'];
            }
            if (empty($host) && !empty($parsed['host'])) {
                $host = $parsed['host'];
                if (!empty($parsed['port'])) {
                    $host .= ':' . $parsed['port'];
                }
            }
            if (empty($scheme) && !empty($parsed['scheme'])) {
                $scheme = $parsed['scheme'];
            }
        } else {
            $cleanUri = '/' . ltrim($uri, '/');
        }

        // Se esquema ou host não forem passados, extrai da origem canônica configurada
        if (empty($scheme) || empty($host)) {
            $parts = parse_url($this->canonicalOrigin);
            if (empty($scheme)) {
                $scheme = $parts['scheme'] ?? 'https';
            }
            if (empty($host)) {
                $host = $parts['host'] ?? 'localhost';
                if (!empty($parts['port'])) {
                    $host .= ':' . $parts['port'];
                }
            }
        }

        // Chave canônica unificada: $scheme$lp_cache_method$host$request_uri
        // HEAD compartilha o mesmo cache de GET
        $cacheKey = "{$scheme}GET{$host}{$cleanUri}";
        $hash = md5($cacheKey);

        $level1 = substr($hash, -1);       // Último caractere
        $level2 = substr($hash, -3, 2);    // Penúltimo e antepenúltimo

        return "{$this->cachePath}/{$level1}/{$level2}/{$hash}";
    }

    /**
     * Tenta expurgar imediatamente uma lista de URIs/URLs do cache do Nginx local e da Cloudflare.
     * Retorna array com status local de cada URI: ['/uri' => true/false].
     * 
     * @param string[] $uris
     * @return array<string, bool>
     */
    public function purgeUrls(array $uris): array
    {
        if (empty($uris)) {
            return [];
        }

        $results = [];

        // 1. Purga do FastCGI Cache local em disco (se habilitado)
        if ($this->enabled) {
            $parts = parse_url($this->canonicalOrigin);
            $defaultScheme = $parts['scheme'] ?? 'https';
            $defaultHost = $parts['host'] ?? 'localhost';
            if (!empty($parts['port'])) {
                $defaultHost .= ':' . $parts['port'];
            }

            foreach ($uris as $uri) {
                $u = trim((string)$uri);
                if ($u === '') {
                    continue;
                }

                $cleanUri = '/' . ltrim($u, '/');
                $host = $defaultHost;
                $schemes = array_unique([$defaultScheme, 'https', 'http']);

                if (preg_match('#^https?://#i', $u)) {
                    $parsed = parse_url($u);
                    $cleanUri = $parsed['path'] ?? '/';
                    if (!empty($parsed['query'])) {
                        $cleanUri .= '?' . $parsed['query'];
                    }
                    if (!empty($parsed['host'])) {
                        $host = $parsed['host'];
                        if (!empty($parsed['port'])) {
                            $host .= ':' . $parsed['port'];
                        }
                    }
                    if (!empty($parsed['scheme'])) {
                        $schemes = array_unique([$parsed['scheme'], 'https', 'http']);
                    }
                }

                $purged = true;

                foreach ($schemes as $scheme) {
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
        } else {
            $results = array_fill_keys($uris, true);
        }

        // 2. Invalidação Seletiva na Cloudflare (Edge API v4) se configurada
        if (!empty($this->config['cloudflare']['enabled'])) {
            try {
                CloudflareCache::purgeUrls($uris);
            } catch (Throwable $e) {
                error_log("[CacheManager] Erro não-bloqueante ao invocar CloudflareCache: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Limpa completamente o diretório local de cache do Nginx (FastCGI Cache).
     * Alternativa simplificada para manutenção geral quando www-data possui permissão de escrita.
     *
     * @return bool
     */
    public function clearLocalCacheFolder(): bool
    {
        if (!is_dir($this->cachePath)) {
            return false;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->cachePath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    @unlink($item->getPathname());
                }
            }
            return true;
        } catch (Throwable $e) {
            error_log("[CacheManager] Erro ao limpar pasta de cache local: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Registra um job de purga na tabela `cache_purge_jobs` dentro da transação editorial.
     *
     * @param int|null $postId
     * @param string $event Ex: 'publish', 'update', 'archive', 'delete'
     * @param string[] $urls Ex: ['/', '/slug/', '/categoria/geral/']
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
