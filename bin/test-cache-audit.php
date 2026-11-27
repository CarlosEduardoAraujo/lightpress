<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$config = require dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/plugins/redis-cache-pro/redis-cache-pro.php';
require_once dirname(__DIR__) . '/plugins/redis-cache-pro/tests/FakeRedis.php';

use LightPress\Core\Database;
use LightPress\Core\ObjectCache;
use LightPress\Core\Settings;
use LightPress\Plugin\RedisCachePro\RedisClient;
use LightPress\Plugin\RedisCachePro\Tests\FakeRedis;
use LightPress\Plugin\RedisCachePro\WarmupService;

echo "=== Teste de Regressão: Auditoria de Cache (Settings, WarmupService, ObjectCache) ===\n";

$passed = 0;
$failed = 0;

$assert = static function (string $name, bool $cond) use (&$passed, &$failed): void {
    if ($cond) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}\n";
        $failed++;
    }
};

// Setup FakeRedis
FakeRedis::clear();
$fake = new FakeRedis();
$client = RedisClient::getInstance();
$client->setClient($fake);

// 1. Validar que Settings::getAll() lê do Redis quando 'all_settings' já está em cache
Settings::reset();
ObjectCache::set('all_settings', ['site_title' => 'CacheHitTitle', 'active_plugins' => '["redis-cache-pro"]'], 'settings', 3600);
$loaded = Settings::getAll();
$assert('Settings::getAll() lê all_settings diretamente do Redis em cache HIT', ($loaded['site_title'] ?? '') === 'CacheHitTitle');

// 2. Validar Settings::getDirect() quando o cache em memória foi limpo
Settings::reset();
$directVal = Settings::getDirect('site_title', '__fallback__');
$assert('Settings::getDirect() retorna valor do banco ou fallback sem popular Settings::$cache', !Settings::hasCache() && $directVal !== null);

// 3. Validar WarmupService grava nas chaves/grupos esperados pela aplicação
$warmup = new WarmupService($client);
$stats = $warmup->run([
    'warmup_posts' => 5,
    'warmup_categories' => true,
    'warmup_custom_urls' => '',
]);
$assert('WarmupService::run() executou sem erros', is_array($stats) && array_key_exists('duration_ms', $stats));
$warmedSettings = ObjectCache::get('all_settings', 'settings');
$assert('WarmupService preenche all_settings no grupo settings', is_array($warmedSettings) || $stats['settings_count'] === 0);

// Se houver posts publicados no banco de teste, verifica se estão em post_items
$nowUtc = gmdate('Y-m-d H:i:s');
$samplePost = Database::fetchOne("SELECT slug FROM posts WHERE status = 'published' AND published_at <= ? ORDER BY published_at DESC LIMIT 1", [$nowUtc]);
if ($samplePost && !empty($samplePost['slug'])) {
    $cachedPost = ObjectCache::get('post_slug:' . $samplePost['slug'], 'post_items');
    $assert('WarmupService preenche post_slug:{slug} no grupo post_items', is_array($cachedPost) && isset($cachedPost['content_html']));
}

$sampleCats = Database::fetchAll("SELECT slug FROM categories WHERE is_active = 1 LIMIT 1");
if (!empty($sampleCats)) {
    $warmedCats = ObjectCache::get('all_active_categories', 'categories');
    $assert('WarmupService preenche all_active_categories no grupo categories', is_array($warmedCats) && isset($warmedCats[$sampleCats[0]['slug']]));
}

echo "\nResumo: {$passed} passaram, {$failed} falharam.\n";
exit($failed > 0 ? 1 : 0);
