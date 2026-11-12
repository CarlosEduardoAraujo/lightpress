<?php
declare(strict_types=1);
namespace LightPress\Core {
    final class CloudflareCache {
        public static bool $success = true;
        public static bool $throws = false;
        public static function purgeUrls(array $urls): bool {
            if (self::$throws) { throw new \RuntimeException('Falha simulada'); }
            return self::$success;
        }
    }
}
namespace {
    require __DIR__ . '/../core/CacheManager.php';
    $manager = new \LightPress\Core\CacheManager(['cache' => ['enabled' => false], 'cloudflare' => ['enabled' => true]]);
    foreach (['success', 'failure', 'exception'] as $scenario) {
        \LightPress\Core\CloudflareCache::$success = $scenario === 'success';
        \LightPress\Core\CloudflareCache::$throws = $scenario === 'exception';
        $actual = $manager->purgeUrls(['/', '/noticia/']);
        $expected = array_fill_keys(['/', '/noticia/'], $scenario === 'success');
        if ($actual !== $expected) { throw new \RuntimeException($scenario); }
        echo "PASS {$scenario}\n";
    }
}
