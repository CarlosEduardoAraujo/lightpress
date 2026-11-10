<?php
declare(strict_types=1);

/**
 * Script de Migração e Backfill: media_variants e posts.featured_media_id
 * 
 * Cria e popula a tabela de correspondência direta `media_variants` a partir
 * dos manifestos JSON da tabela `media` e atualiza a coluna `featured_media_id`
 * em todos os posts existentes.
 * 
 * Uso:
 *   php bin/migrate-media-variants.php
 */

if (php_sapi_name() !== 'cli') {
    die("Este script deve ser executado exclusivamente via linha de comando (CLI).\n");
}

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ObjectCache;
use LightPress\Core\Schema;

echo "====================================================================\n";
echo " LightPress - Migração de Variantes de Mídia & Otimização do Banco\n";
echo "====================================================================\n\n";

$startTime = microtime(true);
$pdo = Database::getConnection();
$driver = Database::getDriver();

// 1. Garantir que as tabelas e colunas existam no banco
echo "[1/4] Verificando e aplicando estrutura do banco de dados...\n";
Schema::ensureMediaVariantsSchema($pdo, $driver);
echo "  -> Estrutura verificada com sucesso.\n\n";

// 2. Extrair variantes de todos os manifestos existentes em media
echo "[2/4] Extraindo variantes de mídia cadastradas...\n";
$mediaRows = Database::fetchAll("SELECT id, variants_manifest, original_name FROM media");
$totalMedia = count($mediaRows);
echo "  -> {$totalMedia} registros de mídia encontrados.\n";

$variantPairs = []; // [url_path => media_id]

foreach ($mediaRows as $m) {
    $mediaId = (string)$m['id'];
    $manifestJson = (string)($m['variants_manifest'] ?? '');

    // Extrair caminhos de upload via regex segura
    if (preg_match_all('~/?uploads/[^\s",]+~', $manifestJson, $matches)) {
        foreach ($matches[0] as $rawUrl) {
            $clean = ltrim(parse_url($rawUrl, PHP_URL_PATH) ?? $rawUrl, '/');
            if (!empty($clean) && !isset($variantPairs[$clean])) {
                $variantPairs[$clean] = $mediaId;
            }
        }
    }

    // Tentar também decodificar o JSON estruturado se houver
    $manifest = json_decode($manifestJson, true);
    if (is_array($manifest)) {
        array_walk_recursive($manifest, function ($val) use (&$variantPairs, $mediaId) {
            if (is_string($val) && str_contains($val, 'uploads/')) {
                $clean = ltrim(parse_url($val, PHP_URL_PATH) ?? $val, '/');
                if (!empty($clean) && !isset($variantPairs[$clean])) {
                    $variantPairs[$clean] = $mediaId;
                }
            }
        });
    }
}

$totalVariants = count($variantPairs);
echo "  -> {$totalVariants} caminhos únicos de variantes identificados.\n\n";

// 3. Inserir variantes em lote na tabela media_variants
echo "[3/4] Populando tabela 'media_variants' em lotes...\n";
$insertSql = ($driver === 'sqlite')
    ? "INSERT OR IGNORE INTO media_variants (media_id, url_path) VALUES (?, ?)"
    : "INSERT IGNORE INTO media_variants (media_id, url_path) VALUES (?, ?)";

$stmt = $pdo->prepare($insertSql);
$insertedCount = 0;
$chunkSize = 250;
$chunks = array_chunk(array_keys($variantPairs), $chunkSize);

$pdo->beginTransaction();
try {
    foreach ($chunks as $chunkUrls) {
        foreach ($chunkUrls as $urlPath) {
            $stmt->execute([$variantPairs[$urlPath], $urlPath]);
            $insertedCount++;
        }
    }
    $pdo->commit();
    echo "  -> {$insertedCount} registros sincronizados na tabela media_variants.\n\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [ERRO] Falha ao inserir variantes: " . $e->getMessage() . "\n";
    exit(1);
}

// 4. Atualizar posts.featured_media_id com base na featured_image
echo "[4/4] Vinculando featured_media_id nos artigos da tabela 'posts'...\n";
$posts = Database::fetchAll("SELECT id, featured_image FROM posts WHERE featured_image IS NOT NULL AND featured_image != ''");
$totalPosts = count($posts);
$updatedPosts = 0;

$updateStmt = $pdo->prepare("UPDATE posts SET featured_media_id = ? WHERE id = ?");

$pdo->beginTransaction();
try {
    foreach ($posts as $post) {
        $pId = (int)$post['id'];
        $fImg = (string)$post['featured_image'];
        $clean = ltrim(parse_url($fImg, PHP_URL_PATH) ?? $fImg, '/');

        // Busca o media_id pelo mapa em memória
        $matchedMediaId = $variantPairs[$clean] ?? null;

        // Se não achou na primeira tentativa, tenta variações de sufixos
        if (!$matchedMediaId) {
            $baseName = pathinfo($clean, PATHINFO_FILENAME);
            $cleanBase = preg_replace('~-(1024|1920|thumb|\d+x\d+)$~i', '', $baseName);
            foreach ($variantPairs as $vPath => $vMid) {
                if (str_contains($vPath, $cleanBase)) {
                    $matchedMediaId = $vMid;
                    break;
                }
            }
        }

        if ($matchedMediaId) {
            $updateStmt->execute([$matchedMediaId, $pId]);
            $updatedPosts++;
        }
    }
    $pdo->commit();
    echo "  -> {$updatedPosts} de {$totalPosts} artigos vinculados diretamente a uma mídia.\n\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "  [ERRO] Falha ao atualizar artigos: " . $e->getMessage() . "\n";
    exit(1);
}

// 5. Invalidação de Cache
if (class_exists(ObjectCache::class) && ObjectCache::isAvailable()) {
    ObjectCache::flushGroup('media');
    ObjectCache::flushGroup('posts');
    echo "  -> Cache Redis do grupo 'media' e 'posts' invalidado com sucesso.\n";
}

$elapsed = round(microtime(true) - $startTime, 3);
echo "====================================================================\n";
echo " Concluído com sucesso em {$elapsed}s!\n";
echo "====================================================================\n";
