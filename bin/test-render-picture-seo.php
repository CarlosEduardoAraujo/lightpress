<?php
declare(strict_types=1);

/**
 * Suíte de Testes Automatizados - Validação do Passo 4 (AUD-ARCH-01 & Seo.php)
 * Execução: php bin/test-render-picture-seo.php
 */

require __DIR__ . '/../core/bootstrap.php';

use LightPress\Core\ImageProcessor;
use LightPress\Core\ObjectCache;
use LightPress\Core\Seo;

echo "========================================================\n";
echo "   Testes: Picture AVIF Seguro & SEO (AUD-ARCH-01)      \n";
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

// 1. Limpar cache de memória e inicializar
ImageProcessor::clearMemoryCache();

// 2. Imagem cujo arquivo .avif NÃO existe
$nonExistentImg = '/uploads/test-no-avif.jpg';
$hasAvif = ImageProcessor::hasAvifVariant($nonExistentImg);
assertTest("hasAvifVariant() retorna false para imagem sem .avif físico/manifesto", $hasAvif === false);

$renderedNoAvif = ImageProcessor::renderPicture($nonExistentImg, 'Foto de Teste', ['class' => 'hero-img', 'loading' => 'eager']);
assertTest(
    "renderPicture() renderiza estritamente <img src='...webp'> quando AVIF não existe",
    !str_contains($renderedNoAvif, '<picture') && !str_contains($renderedNoAvif, '<source') && str_contains($renderedNoAvif, '<img ') && str_contains($renderedNoAvif, '/uploads/test-no-avif.webp')
);
assertTest(
    "renderPicture() inclui atributos class, alt e loading no <img>",
    str_contains($renderedNoAvif, 'class="hero-img"') && str_contains($renderedNoAvif, 'alt="Foto de Teste"') && str_contains($renderedNoAvif, 'loading="eager"')
);

// 3. Imagem cujo arquivo físico .avif EXISTE em disco
$tempDir = dirname(__DIR__) . '/public/uploads';
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0755, true);
}
$dummyAvifFile = $tempDir . '/dummy-exist-avif.avif';
@file_put_contents($dummyAvifFile, 'RIFFdummyAVIF');
$dummyUrl = '/uploads/dummy-exist-avif.webp';

ImageProcessor::clearMemoryCache();
$hasAvifExisting = ImageProcessor::hasAvifVariant($dummyUrl);
assertTest("hasAvifVariant() retorna true para arquivo .avif comprovado em disco", $hasAvifExisting === true);

$renderedWithAvif = ImageProcessor::renderPicture($dummyUrl, 'Foto Existente', ['class' => 'hero-img', 'picture_class' => 'w-full block']);
assertTest(
    "renderPicture() renderiza <picture> com <source type='image/avif'> e fallback <img> em .webp quando AVIF existe",
    str_contains($renderedWithAvif, '<picture class="w-full block">') &&
    str_contains($renderedWithAvif, '<source srcset="/uploads/dummy-exist-avif.avif" type="image/avif">') &&
    str_contains($renderedWithAvif, '<img class="hero-img" src="/uploads/dummy-exist-avif.webp" alt="Foto Existente">')
);

// Limpar arquivo de teste
@unlink($dummyAvifFile);

// 4. Testes de Blindagem em core/Seo.php contra TypeError com datas vazias ou nulas
$dummyConfig = [
    'app' => [
        'name' => 'SpeedRacing',
        'canonical_origin' => 'https://speedracing.com.br',
    ]
];

$postEmptyDate = [
    'id' => 1,
    'slug' => 'noticia-sem-data',
    'title' => 'Notícia com Data Vazia',
    'summary' => 'Resumo da matéria',
    'published_at' => '',
    'updated_at' => '',
    'featured_image' => '',
];

$jsonLdEmpty = Seo::renderJsonLd($postEmptyDate, $dummyConfig);
assertTest("Seo::renderJsonLd() não lança TypeError com published_at='' (string vazia)", is_string($jsonLdEmpty) && str_contains($jsonLdEmpty, 'datePublished'));

$postNullDate = [
    'id' => 2,
    'slug' => 'noticia-com-null',
    'title' => 'Notícia com Data Null',
    'summary' => 'Resumo da matéria',
    'published_at' => null,
    'updated_at' => null,
    'featured_image' => '',
];

$jsonLdNull = Seo::renderJsonLd($postNullDate, $dummyConfig);
assertTest("Seo::renderJsonLd() não lança TypeError com published_at=null", is_string($jsonLdNull) && str_contains($jsonLdNull, 'datePublished'));

$metaTagsEmpty = Seo::renderMetaTags($postEmptyDate, $dummyConfig);
assertTest("Seo::renderMetaTags() não lança TypeError com published_at=''", is_string($metaTagsEmpty) && str_contains($metaTagsEmpty, '<meta property="og:title"'));

// 5. Testes de fallback em <meta name="thumbnail"> no Seo::renderMetaTags()
$postWithImgNoAvif = [
    'id' => 3,
    'slug' => 'noticia-img-sem-avif',
    'title' => 'Matéria Sem AVIF',
    'summary' => 'Resumo',
    'published_at' => '2026-11-22 10:00:00',
    'featured_image' => '/uploads/test-no-avif.jpg',
];

ImageProcessor::clearMemoryCache();
$metaTagsImgNoAvif = Seo::renderMetaTags($postWithImgNoAvif, $dummyConfig);
assertTest(
    "Seo::renderMetaTags() usa fallback para .webp na thumbnail quando .avif não existe",
    str_contains($metaTagsImgNoAvif, '<meta name="thumbnail" content="https://speedracing.com.br/uploads/test-no-avif.webp">')
);

echo "\n========================================================\n";
echo "Total de Testes: " . ($passed + $failed) . "\n";
echo "Aprovados:       {$passed}\n";
echo "Falhas:          {$failed}\n\n";

if ($failed === 0) {
    echo ">>> TODOS OS TESTES DO PASSO 4 FORAM APROVADOS! <<<\n";
    exit(0);
} else {
    echo ">>> HOUVE FALHAS NA VALIDAÇÃO DO PASSO 4! <<<\n";
    exit(1);
}
