<?php
declare(strict_types=1);

/**
 * Utilitário CLI de Catalogação em Lote de Mídias Existentes no SpeedPress
 * 
 * Escaneia o acervo existente em /public/uploads/ (arquivos WebP e AVIF),
 * agrupa as variantes (-1920, -1024, -thumb e recortes legados) em entidades
 * únicas de mídia, gera o variants_manifest JSON compatível e cadastra tudo
 * nas tabelas `media` e `media_refs` sem recodificar ou alterar os arquivos.
 * 
 * Uso:
 *   php bin/catalog-media.php --dry-run
 *   php bin/catalog-media.php --dry-run --limit=50
 *   php bin/catalog-media.php --limit=100 --verbose
 *   php bin/catalog-media.php --chunk=500
 *   php bin/catalog-media.php --update-posts
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

// Garantir limites confortáveis para operação CLI
@ini_set('memory_limit', '512M');
@set_time_limit(0);

echo "========================================================\n";
echo "   SpeedPress - Catalogador de Mídias em Produção CLI   \n";
echo "========================================================\n\n";

// Analisar opções da linha de comando
$options = getopt('', [
    'dry-run',
    'limit::',
    'offset::',
    'chunk::',
    'uploads-dir::',
    'user-id::',
    'skip-refs',
    'update-posts',
    'verbose',
]);

$isDryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 0;
$offset = isset($options['offset']) ? (int)$options['offset'] : 0;
$chunkSize = isset($options['chunk']) ? (int)$options['chunk'] : 500;
if ($chunkSize < 50) $chunkSize = 50;
if ($chunkSize > 2000) $chunkSize = 2000;

$customUploads = $options['uploads-dir'] ?? null;
$userId = isset($options['user-id']) ? (int)$options['user-id'] : 0;
if ($userId <= 0) {
    try {
        $firstAdmin = Database::fetchValue("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1");
        if ($firstAdmin) {
            $userId = (int)$firstAdmin;
        } else {
            $firstUser = Database::fetchValue("SELECT id FROM users ORDER BY id ASC LIMIT 1");
            $userId = $firstUser ? (int)$firstUser : 1;
        }
    } catch (\Throwable $e) {
        $userId = 1;
    }
}
$skipRefs = isset($options['skip-refs']);
$updatePosts = isset($options['update-posts']);
$isVerbose = isset($options['verbose']);

$baseUploadDir = $customUploads 
    ? rtrim($customUploads, '/\\') 
    : (dirname(__DIR__) . '/public/uploads');

if (!is_dir($baseUploadDir)) {
    echo "[ERRO FATAL] O diretório de uploads não foi encontrado em:\n  {$baseUploadDir}\n";
    exit(1);
}

echo "[Configurações da Execução]\n";
echo "  Modo: " . ($isDryRun ? "DRY-RUN (Simulação - Nenhuma alteração será gravada)" : "PRODUÇÃO / GRAVAÇÃO REAL") . "\n";
echo "  Diretório de Mídias: {$baseUploadDir}\n";
echo "  Lote de Gravação: {$chunkSize} itens por transação\n";
if ($limit > 0) {
    echo "  Limite Específico: {$limit} entidades de mídia (Offset: {$offset})\n";
} else {
    echo "  Abrangência: TODO o acervo de uploads\n";
}
echo "  Vínculos Relacionais (media_refs): " . ($skipRefs ? "DESATIVADO (--skip-refs)" : "ATIVADO (Mapeamento automático com posts)") . "\n";
echo "  Sincronização de URLs em Posts: " . ($updatePosts ? "ATIVADA (--update-posts)" : "Desativada (Preserva URLs originais no DB)") . "\n";
echo "  Modo Verboso: " . ($isVerbose ? "ATIVADO (Exibição linha a linha)" : "Normal (Barra de progresso e sumário consolidado)") . "\n\n";

$pdo = Database::getConnection();
$driver = Database::getDriver();

// 1. Otimização de Índice na Tabela media se necessário
if (!$isDryRun && $driver === 'mysql') {
    try {
        $indexExists = false;
        $indices = Database::fetchAll("SHOW INDEX FROM media WHERE Key_name = 'idx_media_created'");
        if (empty($indices)) {
            echo "[Otimização DB] Adicionando índice idx_media_created na tabela 'media' para listagens instantâneas...\n";
            $pdo->exec("ALTER TABLE media ADD INDEX idx_media_created (created_at DESC)");
            echo "[Otimização DB] Índice idx_media_created criado com sucesso!\n\n";
        }
    } catch (\Throwable $e) {
        // Ignora caso índice já exista ou permissão limitada
    }
}

// 2. Pré-carregar Mídias já catalogadas no Banco (Hashmap O(1))
echo "[Passo 1/4] Mapeando mídias já cadastradas na tabela 'media'...\n";
$existingUrls = [];
try {
    $rows = Database::fetchAll("SELECT id, variants_manifest FROM media");
    foreach ($rows as $r) {
        $mid = $r['id'];
        if (preg_match_all('~/uploads/[^\s",]+~', $r['variants_manifest'], $matches)) {
            foreach ($matches[0] as $url) {
                $existingUrls[$url] = $mid;
            }
        }
    }
    echo "  -> " . count($rows) . " mídias já existentes registradas (" . count($existingUrls) . " URLs indexadas).\n\n";
} catch (\Throwable $e) {
    echo "  [Aviso] Falha ao consultar mídias existentes: " . $e->getMessage() . "\n\n";
}

// 3. Pré-carregar Referências de Posts para Mapeamento Instantâneo O(1)
$postsLookup = []; // [base_key => [post_ids]]
if (!$skipRefs) {
    echo "[Passo 2/4] Mapeando artigos e imagens de destaque na tabela 'posts'...\n";
    try {
        $postRows = Database::fetchAll("SELECT id, featured_image FROM posts WHERE featured_image IS NOT NULL AND featured_image != ''");
        foreach ($postRows as $pr) {
            $postId = (int)$pr['id'];
            $fImg = trim($pr['featured_image']);
            
            // Extrair chave base do caminho: ex '/uploads/2024/05/dodge-viper-1024.webp' -> '2024/05/dodge-viper'
            $cleanPath = preg_replace('~^https?://[^/]+~', '', $fImg);
            $cleanPath = preg_replace('~^/wp-content/uploads/~', '/uploads/', $cleanPath);
            if (str_starts_with($cleanPath, '/uploads/')) {
                $sub = substr($cleanPath, 9); // remove '/uploads/'
                $dir = dirname($sub);
                $fileNoExt = pathinfo($sub, PATHINFO_FILENAME);
                $baseName = normalizeBaseName($fileNoExt);
                $key = ($dir === '.' || $dir === '') ? $baseName : ($dir . '/' . $baseName);
                $postsLookup[$key][] = $postId;
            }
        }
        echo "  -> " . count($postRows) . " artigos com imagem mapeados em " . count($postsLookup) . " chaves únicas.\n\n";
    } catch (\Throwable $e) {
        echo "  [Aviso] Falha ao pré-carregar posts: " . $e->getMessage() . "\n\n";
    }
}

// 4. Varredura do Diretório /public/uploads/ e Agrupamento de Variantes
echo "[Passo 3/4] Escaneando arquivos físicos em /public/uploads/...\n";
$groups = [];
$totalFilesScanned = 0;
$totalBytesScanned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseUploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $fileInfo) {
    /** @var \SplFileInfo $fileInfo */
    if (!$fileInfo->isFile()) continue;

    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, ['webp', 'avif', 'jpg', 'jpeg', 'png', 'gif', 'svg'], true)) {
        continue;
    }

    $filePath = $fileInfo->getPathname();
    $fileSize = $fileInfo->getSize();
    $mtime = $fileInfo->getMTime();

    $totalFilesScanned++;
    $totalBytesScanned += $fileSize;

    // Calcular caminho relativo a /uploads
    $relFromUploads = ltrim(str_replace('\\', '/', substr($filePath, strlen($baseUploadDir))), '/');
    $subDir = dirname($relFromUploads);
    if ($subDir === '.') $subDir = '';

    $filenameNoExt = pathinfo($relFromUploads, PATHINFO_FILENAME);
    
    // Classificar variante e extrair nome base
    [$baseName, $variantType] = classifyVariant($filenameNoExt);

    $groupKey = ($subDir === '') ? $baseName : ($subDir . '/' . $baseName);

    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
            'dir' => $subDir,
            'base' => $baseName,
            'mtime' => $mtime,
            'files' => [],
        ];
    }

    // Armazenar variante
    $groups[$groupKey]['files'][] = [
        'full_path' => $filePath,
        'rel_url' => '/uploads/' . $relFromUploads,
        'variant_type' => $variantType,
        'format' => $ext,
        'size' => $fileSize,
        'mtime' => $mtime,
    ];

    if ($mtime > $groups[$groupKey]['mtime']) {
        $groups[$groupKey]['mtime'] = $mtime;
    }
}

$totalGroups = count($groups);
$mbScanned = round($totalBytesScanned / (1024 * 1024), 2);
echo "  -> Varredura concluída: {$totalFilesScanned} arquivos escaneados ({$mbScanned} MiB).\n";
echo "  -> {$totalGroups} entidades de mídia agrupadas por nome base.\n\n";

// Aplicar Offset e Limit se definidos
$groupKeys = array_keys($groups);
if ($offset > 0) {
    $groupKeys = array_slice($groupKeys, $offset);
}
if ($limit > 0 && count($groupKeys) > $limit) {
    $groupKeys = array_slice($groupKeys, 0, $limit);
}

$processCount = count($groupKeys);
echo "[Passo 4/4] Processando e Catalogando {$processCount} entidades de mídia...\n";

// Preparar queries do banco
$insertMediaSql = "INSERT INTO media (id, user_id, original_name, mime_type, file_size, variants_manifest, is_finalized, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, 1, ?)";
$insertMediaStmt = !$isDryRun ? $pdo->prepare($insertMediaSql) : null;

$insertRefSql = ($driver === 'mysql')
    ? "INSERT IGNORE INTO media_refs (media_id, post_id, revision_id, created_at) VALUES (?, ?, NULL, ?)"
    : "INSERT OR IGNORE INTO media_refs (media_id, post_id, revision_id, created_at) VALUES (?, ?, NULL, ?)";
$insertRefStmt = (!$isDryRun && !$skipRefs) ? $pdo->prepare($insertRefSql) : null;

$insertVariantSql = ($driver === 'mysql')
    ? "INSERT IGNORE INTO media_variants (media_id, url_path) VALUES (?, ?)"
    : "INSERT OR IGNORE INTO media_variants (media_id, url_path) VALUES (?, ?)";
$insertVariantStmt = !$isDryRun ? $pdo->prepare($insertVariantSql) : null;

$updatePostSql = "UPDATE posts SET featured_image = ?, featured_media_id = ? WHERE id = ?";
$updatePostStmt = (!$isDryRun && $updatePosts) ? $pdo->prepare($updatePostSql) : null;

$insertedCount = 0;
$skippedCount = 0;
$refsCreated = 0;
$postsUpdated = 0;
$chunkBuffer = 0;

$startTime = microtime(true);

if (!$isDryRun) {
    $pdo->beginTransaction();
}

foreach ($groupKeys as $idx => $groupKey) {
    $group = $groups[$groupKey];
    $subDir = $group['dir'];
    $baseName = $group['base'];
    $mtime = $group['mtime'];
    $createdAt = gmdate('Y-m-d H:i:s', $mtime);

    // Verificar se alguma URL deste grupo já está catalogada
    $alreadyCatalogedId = null;
    foreach ($group['files'] as $f) {
        if (isset($existingUrls[$f['rel_url']])) {
            $alreadyCatalogedId = $existingUrls[$f['rel_url']];
            break;
        }
    }

    if ($alreadyCatalogedId !== null) {
        $skippedCount++;
        // Se já está catalogada, ainda assim pode verificar vínculos com posts
        if (!$skipRefs && isset($postsLookup[$groupKey])) {
            foreach ($postsLookup[$groupKey] as $pId) {
                if (!$isDryRun && $insertRefStmt) {
                    try {
                        $insertRefStmt->execute([$alreadyCatalogedId, $pId, $createdAt]);
                        if ($insertRefStmt->rowCount() > 0) {
                            $refsCreated++;
                        }
                    } catch (\Throwable $e) {}
                } else {
                    $refsCreated++;
                }
            }
        }
        continue;
    }

    // Construir o Manifesto de Variantes (variants_manifest)
    $variantsManifest = [
        'full' => [],
        'intermediate' => [],
        'thumb' => [],
    ];

    $primaryFullFile = null;
    $maxFileSize = 0;
    $primaryMime = 'image/webp';

    foreach ($group['files'] as $f) {
        $vType = $f['variant_type'];
        $fmt = $f['format'];
        $p = $f['full_path'];

        // Se for outro recorte legado, pode mapear como thumb ou intermediate se estiver vazio
        if ($vType === 'other') {
            if (empty($variantsManifest['thumb'][$fmt])) {
                $vType = 'thumb';
            } elseif (empty($variantsManifest['intermediate'][$fmt])) {
                $vType = 'intermediate';
            } else {
                continue; // descarta recortes intermediários excedentes do manifesto
            }
        }

        // Leitura rápida de dimensões dos cabeçalhos
        $imgInfo = @getimagesize($p);
        $w = $imgInfo ? (int)$imgInfo[0] : 0;
        $h = $imgInfo ? (int)$imgInfo[1] : 0;
        $mime = $imgInfo['mime'] ?? ($fmt === 'avif' ? 'image/avif' : 'image/webp');

        if ($vType === 'full' && ($primaryFullFile === null || $f['size'] > $maxFileSize)) {
            $primaryFullFile = $f;
            $maxFileSize = $f['size'];
            $primaryMime = $mime;
        }

        $variantsManifest[$vType][$fmt] = [
            'url' => $f['rel_url'],
            'width' => $w,
            'height' => $h,
            'mime' => $mime,
            'size' => $f['size'],
        ];
    }

    // Se não encontrou 'full', usa o maior arquivo encontrado como full
    if (empty($variantsManifest['full'])) {
        foreach (['intermediate', 'thumb'] as $fallbackType) {
            if (!empty($variantsManifest[$fallbackType])) {
                $variantsManifest['full'] = $variantsManifest[$fallbackType];
                break;
            }
        }
    }

    // Se o thumbnail estiver vazio, faz fallback para full
    if (empty($variantsManifest['thumb']) && !empty($variantsManifest['full'])) {
        $variantsManifest['thumb'] = $variantsManifest['full'];
    }

    // Remover seções vazias
    foreach (['full', 'intermediate', 'thumb'] as $k) {
        if (empty($variantsManifest[$k])) {
            unset($variantsManifest[$k]);
        }
    }

    $mediaId = bin2hex(random_bytes(16));
    $originalName = cleanDisplayTitle($baseName) . '.' . ($group['files'][0]['format'] ?? 'webp');
    $manifestJson = json_encode($variantsManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!$isDryRun && $insertMediaStmt) {
        $insertMediaStmt->execute([
            $mediaId,
            $userId,
            $originalName,
            $primaryMime,
            $maxFileSize,
            $manifestJson,
            $createdAt
        ]);
    }

    // Registrar URLs nos mídias existentes locais para evitar duplicatas em runtime
    foreach ($group['files'] as $f) {
        $existingUrls[$f['rel_url']] = $mediaId;
        if (!$isDryRun && $insertVariantStmt) {
            try {
                $cleanPath = ltrim(parse_url($f['rel_url'], PHP_URL_PATH) ?? $f['rel_url'], '/');
                $insertVariantStmt->execute([$mediaId, $cleanPath]);
            } catch (\Throwable) {}
        }
    }

    $insertedCount++;
    $chunkBuffer++;

    // Vincular com posts mapeados
    if (!$skipRefs && isset($postsLookup[$groupKey])) {
        foreach ($postsLookup[$groupKey] as $pId) {
            if (!$isDryRun && $insertRefStmt) {
                try {
                    $insertRefStmt->execute([$mediaId, $pId, $createdAt]);
                    if ($insertRefStmt->rowCount() > 0) {
                        $refsCreated++;
                    }
                } catch (\Throwable $e) {}
            } else {
                $refsCreated++;
            }

            // Atualizar posts com a URL final WebP/AVIF se solicitado
            if ($updatePosts && !$isDryRun && $updatePostStmt) {
                $bestUrl = $variantsManifest['full']['avif']['url'] 
                    ?? ($variantsManifest['full']['webp']['url'] 
                    ?? ($group['files'][0]['rel_url'] ?? ''));
                if (!empty($bestUrl)) {
                    try {
                        $updatePostStmt->execute([$bestUrl, $mediaId, $pId]);
                        $postsUpdated++;
                    } catch (\Throwable $e) {}
                }
            }
        }
    }

    // Feedback visual
    if ($isVerbose) {
        echo "  [+] Mídia: {$baseName} ({$mediaId})\n";
        echo "      Variantes: " . implode(', ', array_keys($variantsManifest)) . " | Data: {$createdAt}\n";
    } elseif ($insertedCount % 200 === 0) {
        $elapsed = round(microtime(true) - $startTime, 1);
        $percent = round((($idx + 1) / $processCount) * 100);
        echo "  -> Progresso: {$percent}% ({$insertedCount} catalogadas, {$skippedCount} ignoradas, {$elapsed}s decorridos)...\n";
    }

    // Commit por Lote (Chunk) para manter transações curtas e ACID
    if (!$isDryRun && $chunkBuffer >= $chunkSize) {
        $pdo->commit();
        $pdo->beginTransaction();
        $chunkBuffer = 0;
    }
}

if (!$isDryRun) {
    $pdo->commit();
}

$totalElapsed = round(microtime(true) - $startTime, 2);

echo "\n========================================================\n";
echo "   SpeedPress - Relatório Final de Catalogação CLI      \n";
echo "========================================================\n";
echo "  Total de Grupos Analisados:     " . number_format($processCount, 0, ',', '.') . "\n";
echo "  Novas Mídias Catalogadas:       " . number_format($insertedCount, 0, ',', '.') . "\n";
echo "  Mídias Já Existentes (Puladas): " . number_format($skippedCount, 0, ',', '.') . "\n";
if (!$skipRefs) {
    echo "  Vínculos com Posts (media_refs): " . number_format($refsCreated, 0, ',', '.') . "\n";
}
if ($updatePosts) {
    echo "  URLs de Posts Atualizadas:      " . number_format($postsUpdated, 0, ',', '.') . "\n";
}
echo "  Tempo Total de Execução:        {$totalElapsed} segundos\n";
echo "  Status: " . ($isDryRun ? "[SIMULAÇÃO CONCLUÍDA COM SUCESSO - NADA FOI GRAVADO]" : "[CATALOGAÇÃO REAL FINALIZADA COM SUCESSO!]") . "\n";
echo "========================================================\n\n";

if ($isDryRun) {
    echo "Dica: Para executar a catalogação real gravando no banco de dados, execute:\n";
    echo "  php bin/catalog-media.php --chunk=500\n\n";
}


/**
 * Classifica a variante da imagem com base no sufixo do arquivo
 * e retorna [$baseName, $variantType]
 */
function classifyVariant(string $filename): array
{
    // 1. Sufixo -thumb
    if (str_ends_with($filename, '-thumb')) {
        return [substr($filename, 0, -6), 'thumb'];
    }

    // 2. Sufixo -1024
    if (str_ends_with($filename, '-1024')) {
        return [substr($filename, 0, -5), 'intermediate'];
    }

    // 3. Sufixo -1920
    if (str_ends_with($filename, '-1920')) {
        return [substr($filename, 0, -5), 'full'];
    }

    // 4. Recortes dimensionais do WordPress (ex: -300x169, -1024x768, -299x299, -150x150)
    if (preg_match('/^(.*)-(\d+)x(\d+)$/', $filename, $matches)) {
        $base = $matches[1];
        $w = (int)$matches[2];
        $h = (int)$matches[3];

        if ($w <= 350) {
            return [$base, 'thumb'];
        }
        if ($w >= 700 && $w <= 1200) {
            return [$base, 'intermediate'];
        }
        return [$base, 'other'];
    }

    // 5. Imagem padrão / original
    return [$filename, 'full'];
}

/**
 * Normaliza o nome do arquivo para encontrar sua chave base
 */
function normalizeBaseName(string $filename): string
{
    [$base, $v] = classifyVariant($filename);
    return $base;
}

/**
 * Converte slug em título legível para exibição
 */
function cleanDisplayTitle(string $slug): string
{
    $clean = str_replace(['-', '_'], ' ', $slug);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim(ucwords($clean));
}
