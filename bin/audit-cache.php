<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Acesso proibido via web.\n";
    exit(1);
}

/**
 * LightPress / SpeedPress - Auditoria Runtime de OPcache e Redis Cache Pro
 *
 * Uso:
 *   php bin/audit-cache.php
 *   php bin/audit-cache.php --http
 *   php bin/audit-cache.php --http --url=https://seudominio.com.br
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\ObjectCache;
use LightPress\Core\PluginManager;
use LightPress\Core\Settings;
use LightPress\Plugin\RedisCachePro\RedisCache;
use LightPress\Plugin\RedisCachePro\RedisClient;

$args = array_slice($argv, 1);
$runHttp = in_array('--http', $args, true);
$targetOrigin = rtrim((string)($config['app']['canonical_origin'] ?? 'http://localhost:8080'), '/');
foreach ($args as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $targetOrigin = rtrim(substr($arg, 6), '/');
    }
}

echo "================================================================\n";
echo "   LightPress - Auditoria de Cache (OPcache & Redis Cache Pro)  \n";
echo "================================================================\n\n";

$errors = [];
$warnings = [];

// 1. OPcache
echo "[1] OPcache & Path Cache (SAPI: " . PHP_SAPI . ")\n";
$opLoaded = extension_loaded('Zend OPcache') || function_exists('opcache_get_status');
echo "  Extensão OPcache       : " . ($opLoaded ? 'Carregada' : 'AUSENTE') . "\n";
echo "  opcache.enable         : " . (ini_get('opcache.enable') ?: '0') . "\n";
echo "  opcache.enable_cli     : " . (ini_get('opcache.enable_cli') ?: '0') . "\n";
echo "  memory_consumption     : " . (ini_get('opcache.memory_consumption') ?: 'N/A') . " MB\n";
echo "  max_accelerated_files  : " . (ini_get('opcache.max_accelerated_files') ?: 'N/A') . "\n";
echo "  validate_timestamps    : " . (ini_get('opcache.validate_timestamps') ?: 'N/A') . " (revalidate_freq=" . (ini_get('opcache.revalidate_freq') ?: 'N/A') . "s)\n";
if (function_exists('realpath_cache_size')) {
    echo "  realpath_cache_size    : " . round(realpath_cache_size() / 1024, 1) . " KB\n";
}

$opStatus = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
if (is_array($opStatus) && !empty($opStatus['opcache_enabled'])) {
    $stats = $opStatus['opcache_statistics'] ?? [];
    $mem = $opStatus['memory_usage'] ?? [];
    $hitRate = round((float)($stats['opcache_hit_rate'] ?? 0.0), 2);
    $usedMb = round(((float)($mem['used_memory'] ?? 0)) / 1048576, 2);
    $freeMb = round(((float)($mem['free_memory'] ?? 0)) / 1048576, 2);
    $oom = (int)($stats['oom_restarts'] ?? 0);
    $full = !empty($opStatus['cache_full']);
    echo "  Status Runtime         : ATIVO (scripts=" . (int)($stats['num_cached_scripts'] ?? 0) . ", hit_rate={$hitRate}%, used={$usedMb}MB, free={$freeMb}MB)\n";
    if ($full || $oom > 0) {
        $warnings[] = "OPcache apresentou cache_full=" . ($full ? 'true' : 'false') . " ou oom_restarts={$oom}.";
    }
} else {
    echo "  Status Runtime         : Inativo no CLI (valide o pool FPM em /admin/system.php)\n";
}

// 2. Redis Cache Pro
echo "\n[2] Redis Cache Pro & ObjectCache\n";
$phpRedis = extension_loaded('redis');
$pluginActive = PluginManager::isActive('redis-cache-pro');
$objAvailable = ObjectCache::isAvailable();
echo "  Extensão phpredis      : " . ($phpRedis ? 'Sim (v' . (phpversion('redis') ?: '?') . ')' : 'NÃO') . "\n";
echo "  Plugin ativo (DB)      : " . ($pluginActive ? 'Sim' : 'Não') . "\n";
echo "  ObjectCache disponível : " . ($objAvailable ? 'Sim' : 'Não') . "\n";

if ($pluginActive && !$phpRedis) {
    $errors[] = "Plugin redis-cache-pro está ativo, mas a extensão phpredis não está instalada.";
}

if ($objAvailable && class_exists(RedisCache::class, false)) {
    $diag = RedisClient::getInstance()->getDiagnosticInfo();
    echo "  Status Conexão         : " . strtoupper((string)$diag['status']) . " (Circuit Breaker: {$diag['circuit_breaker']})\n";
    echo "  Hit Ratio / Latência   : {$diag['hit_ratio']}% (hits={$diag['hits']}, misses={$diag['misses']}, rtt={$diag['ping_rtt_ms']}ms)\n";
    echo "  Memória / Chaves       : {$diag['used_memory']} / {$diag['max_memory']} ({$diag['keys_count']} chaves)\n";

    if ($diag['status'] !== 'connected') {
        $errors[] = "Redis Cache Pro ativo, mas status atual é '{$diag['status']}' (breaker={$diag['circuit_breaker']}).";
    } else {
        // Teste de roundtrip real
        $probeKey = 'audit_probe_' . bin2hex(random_bytes(4));
        $setOk = ObjectCache::set($probeKey, 'ok', 'default', 30);
        $getVal = ObjectCache::get($probeKey, 'default');
        ObjectCache::delete($probeKey, 'default');
        echo "  Roundtrip SET/GET/DEL  : " . ($setOk && $getVal === 'ok' ? 'OK' : 'FALHOU') . "\n";
        if (!$setOk || $getVal !== 'ok') {
            $errors[] = "Falha no roundtrip SET/GET/DEL do ObjectCache no Redis.";
        }

        // Checar presença de chaves críticas
        $criticalKeys = [
            ['settings', 'all_settings'],
            ['categories', 'all_active_categories'],
            ['posts', 'home_featured_posts'],
            ['posts', 'home_feed_posts_p1'],
        ];
        echo "\n[3] Auditoria de Chaves Críticas no Redis\n";
        foreach ($criticalKeys as [$grp, $k]) {
            $val = ObjectCache::get($k, $grp);
            $present = $val !== false;
            echo "  [{$grp}] {$k} : " . ($present ? 'HIT (em cache)' : 'MISS (será populado no próximo acesso ou warmup)') . "\n";
        }
    }
}

// 4. Sonda HTTP opcional (Nginx FastCGI Cache + rotas PHP)
if ($runHttp) {
    echo "\n[4] Sonda HTTP de Rotas ({$targetOrigin})\n";
    if (!function_exists('curl_init')) {
        $warnings[] = "Extensão cURL indisponível para sonda HTTP.";
    } else {
        $routes = [
            '/',
            '/api/categories.php',
            '/api/posts.php?limit=5&page=1',
            '/api/search.php?q=teste',
            '/busca/?q=teste',
        ];
        foreach ($routes as $route) {
            $url = $targetOrigin . $route;
            $statuses = [];
            $cacheHeaders = [];
            $times = [];
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $ch = curl_init($url);
                $headers = [];
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                        $parts = explode(':', $line, 2);
                        if (count($parts) === 2) {
                            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                        }
                        return strlen($line);
                    },
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                $t0 = microtime(true);
                curl_exec($ch);
                $times[] = round((microtime(true) - $t0) * 1000, 1);
                $statuses[] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cacheHeaders[] = $headers['x-fastcgi-cache'] ?? 'N/A';
                curl_close($ch);
            }
            echo sprintf(
                "  %-32s | HTTP %d -> %d | X-FastCGI-Cache: %s -> %s | %.1fms -> %.1fms\n",
                $route,
                $statuses[0],
                $statuses[1],
                $cacheHeaders[0],
                $cacheHeaders[1],
                $times[0],
                $times[1]
            );
        }
    }
}

echo "\n================================================================\n";
if (empty($errors) && empty($warnings)) {
    echo "Resultado: APROVADO (sem erros ou avisos).\n";
    exit(0);
}
foreach ($warnings as $w) {
    echo "[AVISO] {$w}\n";
}
foreach ($errors as $e) {
    echo "[ERRO]  {$e}\n";
}
exit(empty($errors) ? 0 : 1);
