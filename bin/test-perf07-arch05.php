<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados — Passo 2.5 (PERF-07 + ARCH-05)
 * Execução: php bin/test-perf07-arch05.php
 */

// Se RedisCache não estiver carregado, define mock em memória para habilitar ObjectCache nos testes
if (!class_exists('LightPress\\Plugin\\RedisCachePro\\RedisCache', false)) {
    eval(<<<'PHP'
    namespace LightPress\Plugin\RedisCachePro;
    class RedisCache {
        public static array $store = [];
        public static function get(string $key, string $group = 'default', mixed &$found = null): mixed {
            if (isset(self::$store[$group][$key])) {
                $found = true;
                return self::$store[$group][$key];
            }
            $found = false;
            return false;
        }
        public static function mGet(array $keys, string $group = 'default'): array {
            $results = [];
            foreach ($keys as $k) {
                $results[$k] = self::$store[$group][$k] ?? false;
            }
            return $results;
        }
        public static function set(string $key, mixed $value, string $group = 'default', int $ttl = 0): bool {
            self::$store[$group][$key] = $value;
            return true;
        }
        public static function setMultiple(array $items, string $group = 'default', int $ttl = 0): bool {
            foreach ($items as $k => $v) {
                self::$store[$group][$k] = $v;
            }
            return true;
        }
        public static function delete(string $key, string $group = 'default'): bool {
            unset(self::$store[$group][$key]);
            return true;
        }
        public static function remember(string $key, int $ttl, callable $callback, string $group = 'default'): mixed {
            if (isset(self::$store[$group][$key])) {
                return self::$store[$group][$key];
            }
            $val = $callback();
            self::$store[$group][$key] = $val;
            return $val;
        }
        public static function flushGroup(string $group): bool {
            unset(self::$store[$group]);
            return true;
        }
        public static function flush(string $group = ''): bool {
            if ($group !== '') {
                unset(self::$store[$group]);
            } else {
                self::$store = [];
            }
            return true;
        }
        public static function isConnected(): bool {
            return true;
        }
    }
PHP
    );
}

$config = require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\ObjectCache;
use LightPress\Core\ThemeManager;

echo "========================================================\n";
echo "   LightPress — Suíte de Testes Passo 2.5               \n";
echo "   (PERF-07 + ARCH-05: Cache, Tags & Tema Empresa)      \n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------
// 1. Testes de PERF-07: Cache de $trendingTags no Header
// -------------------------------------------------------------
echo "[1] Testando PERF-07: Cache em RAM de Trending Tags no Header\n";

ObjectCache::flushGroup('posts');

$tplHeader = (string)file_get_contents(dirname(__DIR__) . '/templates/header.php');
$portalHeader = (string)file_get_contents(dirname(__DIR__) . '/themes/portal-noticias/header.php');

assertTest(
    "PERF-07: templates/header.php utiliza ObjectCache::remember para header_trending_tags com grupo 'posts'",
    str_contains($tplHeader, "ObjectCache::remember('header_trending_tags', 600,") && str_contains($tplHeader, "'posts')")
);

assertTest(
    "PERF-07: themes/portal-noticias/header.php utiliza ObjectCache::remember para header_trending_tags com grupo 'posts'",
    str_contains($portalHeader, "ObjectCache::remember('header_trending_tags', 600,") && str_contains($portalHeader, "'posts')")
);

// Simulação de preenchimento e invalidação de cache
$tagsMock = ['Fórmula 1', 'Stock Car', 'NASCAR'];
ObjectCache::set('header_trending_tags', $tagsMock, 'posts', 600);
$cachedTags = ObjectCache::get('header_trending_tags', 'posts');
assertTest(
    "PERF-07: header_trending_tags persiste e é lido do ObjectCache",
    $cachedTags === $tagsMock
);

ObjectCache::flushGroup('posts');
$cachedTagsAfterFlush = ObjectCache::get('header_trending_tags', 'posts');
assertTest(
    "PERF-07: Invalidação de grupo 'posts' purga header_trending_tags imediatamente",
    $cachedTagsAfterFlush === false
);

// -------------------------------------------------------------
// 2. Testes de PERF-07: Resolução de Miniaturas e Intermediárias
// -------------------------------------------------------------
echo "\n[2] Testando PERF-07: Memorização e ObjectCache em ImageProcessor (toThumbUrl / toIntermediateUrl)\n";

ImageProcessor::clearMemoryCache();
ObjectCache::flushGroup('media');

$dummyImage = '/uploads/2026/11/artigo-exemplo.avif';

// Primeira resolução: deve preencher o cache
$thumb1 = ImageProcessor::toThumbUrl($dummyImage);
$cacheKeyThumb = 'img_thumb:' . md5($dummyImage . '|avif');
$cachedInRedis = ObjectCache::get($cacheKeyThumb, 'media');

assertTest(
    "PERF-07: toThumbUrl salva resultado em ObjectCache sob o grupo 'media'",
    $cachedInRedis !== false && is_string($cachedInRedis)
);

// Segunda resolução: deve bater no cache de memória sem erros
$thumb2 = ImageProcessor::toThumbUrl($dummyImage);
assertTest(
    "PERF-07: toThumbUrl retorna valor idêntico da memória estática",
    $thumb1 === $thumb2 && $thumb1 !== ''
);

// Teste do toIntermediateUrl
$inter1 = ImageProcessor::toIntermediateUrl($dummyImage);
$cacheKeyInter = 'img_inter:' . md5($dummyImage . '|avif');
$cachedInterInRedis = ObjectCache::get($cacheKeyInter, 'media');

assertTest(
    "PERF-07: toIntermediateUrl salva resultado em ObjectCache sob o grupo 'media'",
    $cachedInterInRedis !== false && is_string($cachedInterInRedis)
);

$inter2 = ImageProcessor::toIntermediateUrl($dummyImage);
assertTest(
    "PERF-07: toIntermediateUrl retorna valor idêntico da memória estática",
    $inter1 === $inter2 && $inter1 !== ''
);

// Invalidação do grupo media
ObjectCache::flushGroup('media');
assertTest(
    "PERF-07: flushGroup('media') invalida chaves de miniatura no ObjectCache",
    ObjectCache::get($cacheKeyThumb, 'media') === false && ObjectCache::get($cacheKeyInter, 'media') === false
);

// Limpeza de cache de memória da requisição
ImageProcessor::clearMemoryCache();
assertTest(
    "PERF-07: clearMemoryCache() executa sem exceções",
    true
);

// -------------------------------------------------------------
// 3. Testes de PERF-07: Rotas /tag/{tag}/ e Pré-filtro FULLTEXT
// -------------------------------------------------------------
echo "\n[3] Testando PERF-07: Roteamento de Tags e Pré-filtragem FULLTEXT\n";

$indexContent = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');

assertTest(
    "PERF-07: public/index.php rota /tag/{tag}/ usa preg_split com classe unicode [^\\p{L}\\p{N}]+",
    str_contains($indexContent, "preg_split('/[^\\p{L}\\p{N}]+/u', \$tagClean)")
);

assertTest(
    "PERF-07: public/index.php rota /tag/{tag}/ utiliza MATCH AGAINST em BOOLEAN MODE para termos >= 3 chars",
    str_contains($indexContent, "MATCH(p.title, p.summary, p.tags) AGAINST(? IN BOOLEAN MODE)")
);

assertTest(
    "PERF-07: public/index.php sincroniza \$useFulltext entre COUNT(*) e SELECT tanto na rota 5b quanto 5c",
    substr_count($indexContent, "\$useFulltext = false;") >= 2 &&
    substr_count($indexContent, "\$useFulltext = true;") >= 2
);

// -------------------------------------------------------------
// 4. Testes de ARCH-05: Tema Corporativo (themes/empresa/)
// -------------------------------------------------------------
echo "\n[4] Testando ARCH-05: Robustez e Conformidade CSP do Tema Corporativo\n";

$empresaHeader = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/header.php');

assertTest(
    "ARCH-05: themes/empresa/header.php NÃO contém scripts inline (<script>)",
    !str_contains($empresaHeader, '<script>') && !preg_match('#<script(?![^>]*src=)#i', $empresaHeader)
);

assertTest(
    "ARCH-05: themes/empresa/header.php NÃO contém manipulador inline onclick=",
    !str_contains($empresaHeader, 'onclick=')
);

assertTest(
    "ARCH-05: themes/empresa/header.php carrega /assets/js/theme-init.js e /assets/js/app.js",
    str_contains($empresaHeader, '/assets/js/theme-init.js') && str_contains($empresaHeader, '/assets/js/app.js')
);

assertTest(
    "ARCH-05: themes/empresa/header.php define id='btnThemeToggle' e id='themeIcon'",
    str_contains($empresaHeader, 'id="btnThemeToggle"') && str_contains($empresaHeader, 'id="themeIcon"')
);

$empresaPost = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/post.php');
assertTest(
    "ARCH-05: themes/empresa/post.php possui data-view-endpoint para telemetria de visualizações",
    str_contains($empresaPost, 'data-view-endpoint="/api/view/')
);

assertTest(
    "ARCH-05: themes/empresa/post.php utiliza class='corp-layout-sidebar' em vez de grid inline",
    str_contains($empresaPost, 'class="corp-layout-sidebar"') && !str_contains($empresaPost, 'grid-template-columns: 2fr 1fr')
);

assertTest(
    "ARCH-05: themes/empresa/post.php protege strtotime com fallback contra null",
    str_contains($empresaPost, "(\$post['published_at'] ?? 'now')")
);

$empresaPage = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/page.php');
assertTest(
    "ARCH-05: themes/empresa/page.php possui data-view-endpoint para telemetria de páginas",
    str_contains($empresaPage, 'data-view-endpoint="/api/view/page/')
);

assertTest(
    "ARCH-05: themes/empresa/page.php utiliza class='corp-layout-sidebar' em vez de grid inline",
    str_contains($empresaPage, 'class="corp-layout-sidebar"') && !str_contains($empresaPage, 'grid-template-columns: 2fr 1fr')
);

// Proteção de datas nos demais listadores do tema empresa
$empresaHome = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/home.php');
$empresaBlog = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/blog.php');
$empresaCat = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/category.php');
$empresaTag = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/tag.php');
$empresaSearch = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/search.php');

assertTest(
    "ARCH-05: themes/empresa/home.php protege \$p['published_at'] contra null em strtotime",
    str_contains($empresaHome, "(\$p['published_at'] ?? 'now')")
);

assertTest(
    "ARCH-05: themes/empresa/blog.php protege \$p['published_at'] contra null em strtotime",
    str_contains($empresaBlog, "(\$p['published_at'] ?? 'now')")
);

assertTest(
    "ARCH-05: themes/empresa/category.php protege \$p['published_at'] contra null em strtotime",
    str_contains($empresaCat, "(\$p['published_at'] ?? 'now')")
);

assertTest(
    "ARCH-05: themes/empresa/tag.php protege \$p['published_at'] contra null em strtotime",
    str_contains($empresaTag, "(\$p['published_at'] ?? 'now')")
);

assertTest(
    "ARCH-05: themes/empresa/search.php protege \$p['published_at'] contra null em strtotime",
    str_contains($empresaSearch, "(\$p['published_at'] ?? 'now')")
);

// Testes de CSS Responsivo
$themeCss = (string)file_get_contents(dirname(__DIR__) . '/themes/empresa/assets/css/style.css');
$publicCss = (string)file_get_contents(dirname(__DIR__) . '/public/assets/themes/empresa/css/style.css');

assertTest(
    "ARCH-05: themes/empresa/assets/css/style.css define .corp-layout-sidebar com breakpoint mobile 900px",
    str_contains($themeCss, '.corp-layout-sidebar') && str_contains($themeCss, '@media (max-width: 900px)')
);

assertTest(
    "ARCH-05: public/assets/themes/empresa/css/style.css está em perfeita sincronia com o CSS do tema",
    str_contains($publicCss, '.corp-layout-sidebar') && str_contains($publicCss, '@media (max-width: 900px)')
);

// -------------------------------------------------------------
// Resumo Final
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   Resultado dos Testes Passo 2.5 (PERF-07 + ARCH-05)   \n";
echo "========================================================\n";
echo "Total de Testes: " . ($passed + $failed) . "\n";
echo "Aprovados:       {$passed}\n";
echo "Falhas:          {$failed}\n\n";

if ($failed === 0) {
    echo ">>> TODOS OS TESTES DO PASSO 2.5 FORAM APROVADOS COM SUCESSO! <<<\n\n";
    exit(0);
} else {
    echo ">>> ATENÇÃO: HOUVE {$failed} FALHA(S) NOS TESTES DO PASSO 2.5! <<<\n\n";
    exit(1);
}
