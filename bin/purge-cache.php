<?php
declare(strict_types=1);

/**
 * Utilitário CLI para Invalidação e Teste de Cache (Cloudflare API v4 & Nginx FastCGI)
 * 
 * Uso:
 *   php bin/purge-cache.php --test
 *   php bin/purge-cache.php --home
 *   php bin/purge-cache.php --url="https://www.speedracing.com.br/alan-permane-racing-bulls-2031/"
 *   php bin/purge-cache.php --slug="alan-permane-racing-bulls-2031"
 *   php bin/purge-cache.php --all
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\CacheManager;
use LightPress\Core\CloudflareCache;

$options = getopt('', ['test', 'home', 'url:', 'slug:', 'category:', 'all', 'help']);

if (isset($options['help']) || empty($options)) {
    echo "====================================================================\n";
    echo " LightPress - Utilitário de Purga de Cache (Cloudflare & Nginx)\n";
    echo "====================================================================\n\n";
    echo "Opções disponíveis:\n";
    echo "  --test                 Testa a conexão, API Token e Zone ID da Cloudflare\n";
    echo "  --home                 Purga a Home (/), os feeds e o sitemap\n";
    echo "  --url=\"<URL>\"          Purga uma URL específica (ex: https://www.speedracing.com.br/slug/)\n";
    echo "  --slug=\"<SLUG>\"        Purga matéria pelo slug, home, feeds e sitemap\n";
    echo "  --category=\"<SLUG>\"    Purga também a URL da categoria (usado com --url ou --slug)\n";
    echo "  --all                  Purga TODO o cache (Cloudflare Purge Everything + Nginx local)\n";
    echo "  --help                 Exibe esta ajuda\n\n";
    exit(0);
}

$cacheManager = new CacheManager($config);
$canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'https://www.speedracing.com.br', '/');
$cfEnabled = !empty($config['cloudflare']['enabled']);

echo "Ambiente: " . ($config['app']['env'] ?? 'production') . "\n";
echo "Origem Canônica: {$canonicalOrigin}\n";
echo "Cache Nginx Local: " . ($config['cache']['cache_path'] ?? 'desconhecido') . "\n";
echo "Cloudflare Habilitada: " . ($cfEnabled ? 'SIM' : 'NÃO (defina enabled => true em config.local.php)') . "\n\n";

// 1. Teste de Conexão
if (isset($options['test'])) {
    echo "[TESTE] Verificando credenciais da Cloudflare...\n";
    $test = CloudflareCache::testConnection();
    if ($test['success']) {
        echo "[SUCESSO] {$test['message']}\n";
        exit(0);
    } else {
        echo "[FALHA] Código HTTP: {$test['http_code']}\n";
        echo "Mensagem: {$test['message']}\n";
        exit(1);
    }
}

// 2. Purga Total (All)
if (isset($options['all'])) {
    echo "[PURGA TOTAL] Iniciando purga completa...\n";
    
    // Limpeza de disco
    echo "- Limpando cache FastCGI local em disco... ";
    $localOk = $cacheManager->clearLocalCacheFolder();
    echo $localOk ? "OK\n" : "FALHOU ou pasta vazia\n";

    // Purga Cloudflare
    if ($cfEnabled) {
        echo "- Executando Purge Everything na Cloudflare... ";
        $cfOk = CloudflareCache::purgeEverything();
        echo $cfOk ? "OK\n" : "FALHOU (verifique error_log)\n";
    } else {
        echo "- Cloudflare desabilitada em config.local.php (ignorado)\n";
    }

    echo "\nConcluído.\n";
    exit(0);
}

// 3. Purga da Home e Feeds
if (isset($options['home'])) {
    $urls = [
        $canonicalOrigin . '/',
        $canonicalOrigin . '/feed.xml',
        $canonicalOrigin . '/feed/',
        $canonicalOrigin . '/sitemap.xml',
    ];
    echo "[PURGA HOME] Purgando Home, Feeds e Sitemap:\n";
    foreach ($urls as $u) {
        echo "  - {$u}\n";
    }

    $results = $cacheManager->purgeUrls($urls);
    echo "Status Nginx Local: " . count(array_filter($results)) . "/" . count($results) . " purgados.\n";
    if ($cfEnabled) {
        $cfOk = CloudflareCache::purgeUrls($urls);
        echo "Status Cloudflare: " . ($cfOk ? "OK" : "FALHOU (verifique logs)") . "\n";
    }
    echo "\nConcluído.\n";
    exit(0);
}

// 4. Purga por Slug
if (isset($options['slug'])) {
    $slug = trim((string)$options['slug'], '/');
    $postUrl = "{$canonicalOrigin}/{$slug}/";
    $catUrl = !empty($options['category']) ? "{$canonicalOrigin}/categoria/" . trim((string)$options['category'], '/') . '/' : null;

    echo "[PURGA MATÉRIA] Purgando publicação com slug: '{$slug}'\n";
    echo "  - Matéria: {$postUrl}\n";
    if ($catUrl) {
        echo "  - Categoria: {$catUrl}\n";
    }
    echo "  - Home: {$canonicalOrigin}/\n";
    echo "  - Feeds: {$canonicalOrigin}/feed.xml e /feed/\n";
    echo "  - Sitemap: {$canonicalOrigin}/sitemap.xml\n";

    $ok = CloudflareCache::purgeOnPostPublish($postUrl, $catUrl);
    echo "\nResultado: " . ($ok ? "SUCESSO" : "FALHOU ou Cloudflare desabilitada") . "\n";
    exit($ok ? 0 : 1);
}

// 5. Purga por URL direta
if (isset($options['url'])) {
    $url = trim((string)$options['url']);
    $catUrl = !empty($options['category']) ? trim((string)$options['category']) : null;

    echo "[PURGA URL] Purgando URL informada: {$url}\n";
    $ok = CloudflareCache::purgeOnPostPublish($url, $catUrl);
    echo "\nResultado: " . ($ok ? "SUCESSO" : "FALHOU ou Cloudflare desabilitada") . "\n";
    exit($ok ? 0 : 1);
}
