<?php
declare(strict_types=1);

// Isolado: SQLite em memória, cache e purga simulados. Nunca carrega config.local.php.
namespace LightPress\Core {
    final class ObjectCache {
        public static array $data = [];
        public static function get(string $key, string $group = 'default'): mixed { return self::$data[$group][$key] ?? false; }
        public static function set(string $key, mixed $value, string $group = 'default', int $ttl = 0): bool { self::$data[$group][$key] = $value; return true; }
        public static function flushGroup(string $group): bool { unset(self::$data[$group]); return true; }
    }
    final class CacheManager {
        public static array $urls = [];
        public function recordPurgeJob(?int $id, string $event, array $urls): int { self::$urls = $urls; return 1; }
        public function purgeUrls(array $urls): array { return array_fill_keys($urls, true); }
    }
}
namespace {
    use LightPress\Core\Database;
    use LightPress\Core\ImageProcessor;
    use LightPress\Core\ObjectCache;
    use LightPress\Core\CacheManager;
    require __DIR__ . '/../core/Database.php';
    require __DIR__ . '/../core/Security.php';
    require __DIR__ . '/../core/ImageProcessor.php';
    require __DIR__ . '/../plugins/ai-copilot/src/MediaVision.php';
    Database::init(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
    $pdo = Database::getConnection();
    $pdo->exec('CREATE TABLE media (id TEXT PRIMARY KEY, original_name TEXT, alt_text TEXT, caption TEXT, ai_tags TEXT, variants_manifest TEXT)');
    $pdo->exec('CREATE TABLE media_variants (url_path TEXT UNIQUE, media_id TEXT)');
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, slug TEXT, category TEXT, tags TEXT, featured_image TEXT, featured_media_id TEXT, status TEXT, published_at TEXT)');
    $pdo->exec('CREATE TABLE media_refs (media_id TEXT, post_id INTEGER)');
    $pdo->exec('CREATE TABLE pages (slug TEXT, featured_image TEXT)');
    $checks = 0;
    function check(bool $ok, string $label): void {
        global $checks;
        if (!$ok) { throw new RuntimeException($label); }
        $checks++;
        echo "PASS {$label}\n";
    }
    function resetCache(): void { ImageProcessor::clearMemoryCache(); ObjectCache::$data = []; }
    $base = 'felipe-fraga-campeao-stock-car-2026-velopark-corrida-do-milhao';
    $url = '/uploads/2026/11/' . $base . '-1024.avif';
    $alt = 'Mitsubishi Eclipse Cross amarelo de Felipe Fraga lidera corrida de Stock Car';
    $manifest = json_encode(['intermediate' => ['avif' => ['url' => $url]]]);
    Database::query('INSERT INTO media VALUES (?, ?, ?, ?, ?, ?)', ['a', $base . '.jpg', $alt, 'Legenda editorial', '', $manifest]);
    check(ImageProcessor::getImageDescription($url) === $alt, 'Recupera JPG por variante AVIF sem vínculo');
    resetCache();
    ImageProcessor::preloadDescriptions([$url]);
    check(ImageProcessor::getImageDescription($url) === $alt, 'Home usa a mesma recuperação');
    resetCache();
    check(ImageProcessor::getImageDescription('https://site.test' . $url . '?v=1') === $alt, 'Normaliza host e query');
    Database::query('INSERT INTO media_variants VALUES (?, ?)', [ltrim($url, '/'), 'a']);
    resetCache();
    check(ImageProcessor::getImageDescription($url) === $alt, 'Vínculo exato');
    ObjectCache::$data['media']['media_desc:' . md5(ltrim($url, '/'))] = 'Cache antigo';
    ImageProcessor::clearMemoryCache();
    check(ImageProcessor::getImageDescription($url) === $alt, 'Ignora namespace antigo');
    Database::query("UPDATE media SET alt_text = '   ' WHERE id = 'a'"); resetCache();
    check(ImageProcessor::getImageDescription($url) === 'Legenda editorial', 'Espaços no alt permitem legenda');
    Database::query("UPDATE media SET caption = '' WHERE id = 'a'"); resetCache();
    check(ImageProcessor::getImageDescription($url) === 'Felipe Fraga Campeao Stock Car 2026 Velopark Corrida Do Milhao', 'Fallback amigável');
    Database::query('UPDATE media SET alt_text = ? WHERE id = ?', [$alt, 'a']);
    Database::query('DELETE FROM media_variants');
    Database::query('INSERT INTO media VALUES (?, ?, ?, ?, ?, ?)', ['b', $base . '.jpg', 'Outro registro', '', '', $manifest]); resetCache();
    check(ImageProcessor::getImageDescription($url) !== $alt, 'Não escolhe homônimo ambíguo');
    resetCache();
    check(ImageProcessor::getImageDescription($url, null, 'a') === $alt, 'ID válido resolve ambiguidade');
    resetCache();
    check(ImageProcessor::getImageDescription($url, null, 'inexistente') !== $alt, 'ID inválido não força associação');
    Database::query("UPDATE media SET variants_manifest = ? WHERE id = 'b'", [json_encode(['intermediate' => ['avif' => ['url' => str_replace('2026/11', '2026/10', $url)]]])]); resetCache();
    check(ImageProcessor::getImageDescription($url) === $alt, 'Homônimo em outra pasta não interfere');
    resetCache();
    ImageProcessor::getImageDescription('/uploads/2026/11/ausente.avif');
    check(empty(ObjectCache::$data), 'Vínculo ausente não persiste fallback');
    Database::query("INSERT INTO posts VALUES (1, 'noticia', 'stock-car', 'Stock Car', ?, 'a', 'published', '2026-01-01')", [$url]);
    Database::query("INSERT INTO posts VALUES (2, 'relacionada', 'stock-car', '', '', '', 'published', '2026-01-01')");
    Database::query("INSERT INTO pages VALUES ('institucional', ?)", [$url]);
    ObjectCache::$data['posts']['post_slug:noticia'] = ['image_alt' => 'Antigo'];
    \LightPress\Plugin\AiCopilot\MediaVision::saveMediaMeta('a', 'Alt atualizado', 'Nova legenda', '');
    check(ImageProcessor::getImageDescription($url) === 'Alt atualizado', 'Editar mídia atualiza cache e memória');
    check(empty(ObjectCache::$data['posts']), 'Edição invalida posts antigos');
    foreach (['/', '/noticia/', '/relacionada/', '/categoria/stock-car/', '/tag/stock-car/', '/institucional/'] as $uri) {
        check(in_array($uri, CacheManager::$urls, true), 'Purga ' . $uri);
    }
    $pdo->exec('DROP TABLE media_variants'); resetCache();
    check(ImageProcessor::getImageDescription('/uploads/foto-teste.avif') === 'Foto Teste', 'Falha de banco mantém renderização');
    check(empty(ObjectCache::$data), 'Falha de banco não persiste fallback');
    check(ImageProcessor::getImageDescription('') === '', 'URL vazia');
    check(ImageProcessor::getImageDescription($url, 'Texto explícito') === 'Texto explícito', 'Compatibilidade do segundo argumento');
    echo "{$checks} verificações concluídas.\n";
}
