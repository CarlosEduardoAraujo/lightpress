<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Biblioteca e Galeria de Mídias
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

$currentUser = Security::requireAuth();

$message = '';
$error = '';

// 1. Processar Upload Direto na Galeria
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'));

// Detectar se o arquivo excedeu o post_max_size do PHP (quando $_POST e $_FILES chegam vazios mas houve payload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = "O arquivo enviado excede o limite máximo permitido pelo servidor (post_max_size no php.ini). Limite recomendado: 8 MiB.";
    if ($isAjax) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['media_file'])) {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado. Recarregue a página e tente novamente.";
    } else {
        $uploadErr = $_FILES['media_file']['error'] ?? UPLOAD_ERR_OK;
        if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
            $error = "O arquivo excede o limite de tamanho permitido pelo servidor. Limite: 8 MiB.";
        } elseif ($uploadErr === UPLOAD_ERR_PARTIAL) {
            $error = "O envio da imagem foi interrompido antes de ser concluído. Tente novamente.";
        } elseif ($uploadErr === UPLOAD_ERR_NO_FILE) {
            $error = "Nenhum arquivo de imagem foi selecionado.";
        } elseif ($uploadErr !== UPLOAD_ERR_OK) {
            $error = "Falha no envio do arquivo (código de erro PHP: {$uploadErr}).";
        } elseif (!is_uploaded_file($_FILES['media_file']['tmp_name'])) {
            $error = "Arquivo temporário não reconhecido ou inválido.";
        } else {
            $processor = new ImageProcessor($config);
            try {
                $manifest = $processor->processUpload(
                    $_FILES['media_file']['tmp_name'],
                    $_FILES['media_file']['name']
                );

                $mediaId = $manifest['media_id'];
                $userId = (int)$currentUser['id'];
                $origName = $manifest['original_name'];
                $fileSize = (int)$_FILES['media_file']['size'];
                $variantsJson = json_encode($manifest['variants'], JSON_THROW_ON_ERROR);

                Database::query(
                    "INSERT INTO media (id, user_id, original_name, mime_type, file_size, variants_manifest, is_finalized)
                     VALUES (?, ?, ?, 'image/webp', ?, ?, 1)",
                    [$mediaId, $userId, $origName, $fileSize, $variantsJson]
                );

                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'ok' => true,
                        'message' => 'Imagem processada com sucesso!',
                        'media' => [
                            'id' => $mediaId,
                            'original_name' => $origName,
                            'file_size' => $fileSize,
                            'uploader_name' => $currentUser['name'],
                            'variants' => $manifest['variants'],
                        ],
                    ]);
                    exit;
                }

                $message = "Imagem processada e adicionada à biblioteca com sucesso!";
            } catch (\Throwable $e) {
                $error = "Erro no upload: " . $e->getMessage();
            }
        }
    }

    if ($isAjax && !empty($error)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}

// 2. Exclusão de Mídia Segura (Estritamente via POST com CSRF)
$delMediaId = $_SERVER['REQUEST_METHOD'] === 'POST' ? trim((string)($_POST['delete_id'] ?? '')) : '';
$csrfMed = $_POST['_csrf_token'] ?? '';
if (!empty($delMediaId) && Security::verifyCsrfToken($csrfMed)) {
    $mediaId = $delMediaId;
    $mediaItem = Database::fetchOne("SELECT * FROM media WHERE id = ? LIMIT 1", [$mediaId]);

    if (!$mediaItem) {
        $error = "Imagem não encontrada.";
    } else {
        $canDelete = in_array($currentUser['role'], ['admin', 'editor'], true) || ((int)($mediaItem['user_id'] ?? 0) === (int)$currentUser['id']);
        if (!$canDelete) {
            $error = "Você não tem permissão para excluir esta imagem da biblioteca.";
        } else {
            // Verificar se alguma das variantes ou o media_id está em uso no banco (P2 / Item 6)
            $isInUse = false;

            // 1. Verificar em vínculos normalizados na tabela media_refs
            $inMediaRefs = (int)Database::fetchValue("SELECT COUNT(*) FROM media_refs WHERE media_id = ?", [$mediaId]);
            if ($inMediaRefs > 0) {
                $isInUse = true;
            }

            // 2. Verificar variantes em posts, pages e post_revisions
            if (!$isInUse) {
                $variants = json_decode($mediaItem['variants_manifest'], true) ?? [];
                foreach ($variants as $res => $formats) {
                    if (!is_array($formats)) continue;
                    foreach ($formats as $fmt => $info) {
                        $url = $info['url'] ?? '';
                        if (!empty($url)) {
                            // Suporte a busca direta e com barras escapadas (\/uploads\/...) em serializações JSON legadas
                            $escapedUrl = str_replace('/', '\/', $url);
                            $likeUrl = '%' . Security::escapeLike($url) . '%';
                            $likeEscapedUrl = '%' . Security::escapeLike($escapedUrl) . '%';

                            $inPostsFeatured = (int)Database::fetchValue(
                                "SELECT COUNT(*) FROM posts WHERE featured_image = ? OR content_json LIKE ? OR content_json LIKE ?",
                                [$url, $likeUrl, $likeEscapedUrl]
                            );
                            if ($inPostsFeatured > 0) { $isInUse = true; break 2; }

                            $inPostsContent = (int)Database::fetchValue(
                                "SELECT COUNT(*) FROM posts WHERE content_html LIKE ?",
                                [$likeUrl]
                            );
                            if ($inPostsContent > 0) { $isInUse = true; break 2; }

                            $inPages = (int)Database::fetchValue(
                                "SELECT COUNT(*) FROM pages WHERE featured_image = ? OR content_html LIKE ?",
                                [$url, $likeUrl]
                            );
                            if ($inPages > 0) { $isInUse = true; break 2; }

                            // Verificar nas revisões históricas de matérias (post_revisions), cobrindo serializações com e sem escape de barra
                            $inRevisions = (int)Database::fetchValue(
                                "SELECT COUNT(*) FROM post_revisions 
                                 WHERE content_html LIKE ? 
                                    OR payload_json LIKE ? 
                                    OR payload_json LIKE ?",
                                [$likeUrl, $likeUrl, $likeEscapedUrl]
                            );
                            if ($inRevisions > 0) { $isInUse = true; break 2; }
                        }
                    }
                }
            }

            if ($isInUse) {
                $error = "Esta imagem não pode ser excluída pois está em uso como destaque ou no conteúdo de matérias, páginas ou revisões históricas do acervo.";
            } else {
                $publicPath = $config['paths']['public'] ?? (dirname(__DIR__, 2) . '/public');
                $uploadDir = $config['media']['upload_dir'] ?? ($publicPath . '/uploads');
                $resolvedUploadDir = realpath($uploadDir);

                if ($resolvedUploadDir !== false) {
                    $baseUploadDir = rtrim(str_replace('\\', '/', $resolvedUploadDir), '/') . '/';

                    foreach ($variants as $res => $formats) {
                        if (!is_array($formats)) continue;
                        foreach ($formats as $fmt => $info) {
                            $localPath = $publicPath . ($info['url'] ?? '');
                            if (file_exists($localPath)) {
                                $resolvedLocalPath = realpath($localPath);
                                if ($resolvedLocalPath !== false) {
                                    $targetFile = str_replace('\\', '/', $resolvedLocalPath);
                                    $cmpBase = DIRECTORY_SEPARATOR === '\\' ? strtolower($baseUploadDir) : $baseUploadDir;
                                    $cmpTarget = DIRECTORY_SEPARATOR === '\\' ? strtolower($targetFile) : $targetFile;
                                    if (str_starts_with($cmpTarget, $cmpBase) && is_file($resolvedLocalPath)) {
                                        @unlink($resolvedLocalPath);
                                    }
                                }
                            }
                        }
                    }
                }

                Database::query("DELETE FROM media_refs WHERE media_id = ?", [$mediaId]);
                Database::query("DELETE FROM media WHERE id = ?", [$mediaId]);
                $message = "Mídia excluída com sucesso!";
            }
        }
    }
}

// 3. Filtro de Busca e Paginação de Mídias
$filterSearch = trim($_GET['q'] ?? '');
$whereClauses = [];
$params = [];

if (!empty($filterSearch)) {
    $whereClauses[] = "(m.original_name LIKE ? OR m.alt_text LIKE ? OR m.ai_tags LIKE ? OR m.caption LIKE ? OR m.variants_manifest LIKE ? OR m.id = ?)";
    $escapedFilter = '%' . Security::escapeLike($filterSearch) . '%';
    $params[] = $escapedFilter;
    $params[] = $escapedFilter;
    $params[] = $escapedFilter;
    $params[] = $escapedFilter;
    $params[] = $escapedFilter;
    $params[] = $filterSearch;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Paginação
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 48; // Múltiplo amigável de 2, 3, 4 e 6 para grid responsivo

$totalCount = (int)Database::fetchValue(
    "SELECT COUNT(*) FROM media m {$whereSql}",
    $params
);

$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Ordenação (padrão 'desc' - recentes primeiro)
$filterOrder = strtolower($_GET['order'] ?? 'desc');
if (!in_array($filterOrder, ['asc', 'desc'], true)) {
    $filterOrder = 'desc';
}
$orderSql = ($filterOrder === 'asc') ? 'm.created_at ASC, m.id ASC' : 'm.created_at DESC, m.id DESC';

$querySql = "SELECT m.*, u.name as uploader_name 
             FROM media m
             JOIN users u ON u.id = m.user_id
             {$whereSql}
             ORDER BY {$orderSql} 
             LIMIT ? OFFSET ?";

$queryParams = array_merge($params, [$perPage, $offset]);
$mediaList = Database::fetchAll($querySql, $queryParams);

$csrfToken = Security::generateCsrfToken();
$isAiCopilotActive = \LightPress\Core\PluginManager::isActive('ai-copilot');
$isVisionEnabled = $isAiCopilotActive && (\LightPress\Core\Settings::get('ai_copilot_vision_enabled', '1') === '1');
$visionMode = (string)\LightPress\Core\Settings::get('ai_copilot_vision_mode', 'auto');

// Função auxiliar para montar URLs com parâmetros de busca e paginação
function buildMediaUrl(array $overrides = []): string {
    $current = $_GET;
    unset($current['_csrf_token'], $current['delete_id']);
    $merged = array_merge($current, $overrides);
    $cleaned = array_filter($merged, function($v) { return $v !== '' && $v !== null && $v !== 0; });
    return '/admin/media.php' . (!empty($cleaned) ? '?' . http_build_query($cleaned) : '');
}
$pageTitle = 'Biblioteca de Mídias';
$activePage = 'media';
require __DIR__ . '/header.php';
?>
<style>
    [data-theme="dark"] .btn-copy { background: #1e293b; color: #f1f5f9; border-color: #334155; }
    [data-theme="dark"] .btn-copy:hover { background: #334155; }
    [data-theme="dark"] .card-upload { background: #131b2e; border-color: #334155; }
    [data-theme="dark"] .card-upload:hover { border-color: var(--primary-accent); background: rgba(225, 6, 0, 0.05); }
    [data-theme="dark"] .card-upload.dragover { background: rgba(225, 6, 0, 0.15); border-color: var(--primary-accent); }
    [data-theme="dark"] .card-upload .upload-title { color: #f1f5f9 !important; }
    [data-theme="dark"] .card-upload .upload-subtitle { color: #cbd5e1 !important; }
    [data-theme="dark"] .card-upload .upload-hint { color: #94a3b8 !important; }

    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .card-upload {
        background: var(--surface);
        border: 2px dashed var(--border);
        border-radius: var(--radius);
        padding: 2.25rem 2rem;
        text-align: center;
        margin-bottom: 2rem;
        cursor: pointer;
        transition: all 0.2s ease-in-out;
        user-select: none;
    }
    .card-upload:hover {
        border-color: var(--primary-accent);
        background: rgba(225, 6, 0, 0.02);
    }
    .card-upload.dragover {
        border-color: var(--primary-accent);
        background: rgba(225, 6, 0, 0.08);
        border-style: solid;
        box-shadow: 0 0 0 4px rgba(225, 6, 0, 0.15);
        transform: scale(1.005);
    }
    .card-upload .upload-icon {
        font-size: 2.5rem;
        line-height: 1;
        margin-bottom: 0.6rem;
        display: inline-block;
    }
    .card-upload .upload-title {
        font-size: 1.15rem;
        font-weight: 700;
        margin-bottom: 0.4rem;
        color: #1e293b;
    }
    .card-upload .upload-subtitle {
        font-size: 0.95rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.4rem;
    }
    .card-upload .upload-hint {
        font-size: 0.82rem;
        color: var(--text-muted);
        margin-bottom: 1.25rem;
    }
    .filter-bar {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1rem 1.25rem;
        margin-bottom: 1.5rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem;
        align-items: center;
        justify-content: space-between;
    }
    .form-input {
        padding: 0.6rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        font-size: 0.9rem;
        background: var(--surface);
        color: var(--text);
        outline: none;
        transition: border-color 0.15s;
    }
    .form-input:focus { border-color: var(--primary-accent); }
    .btn-filter {
        padding: 0.6rem 1rem;
        background: #f1f5f9;
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        font-size: 0.88rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        transition: all 0.15s;
    }
    .btn-filter:hover { background: #e2e8f0; }
    .btn-filter.btn-primary { background: var(--primary-accent); color: white; border-color: var(--primary-accent); }
    .btn-filter.btn-primary:hover { background: var(--primary-hover); }

    .pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.25rem 1.5rem;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        font-size: 0.88rem;
        color: var(--text-muted);
        margin-top: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .page-links { display: flex; gap: 0.4rem; flex-wrap: wrap; }
    .page-num {
        padding: 0.4rem 0.75rem;
        border: 1px solid var(--border);
        border-radius: 6px;
        text-decoration: none;
        color: var(--text);
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.15s;
    }
    .page-num.active { background: var(--primary-accent); color: white; border-color: var(--primary-accent); }
    .page-num:hover:not(.active) { background: #f1f5f9; }

    [data-theme="dark"] .btn-filter { background: #1e293b; color: #f1f5f9; border-color: #334155; }
    [data-theme="dark"] .btn-filter:hover { background: #334155; }
    [data-theme="dark"] .form-input { background: #0b1120; color: #f1f5f9; border-color: #1e293b; }
    [data-theme="dark"] .page-num { background: #131b2e; color: #f1f5f9; border-color: #1e293b; }
    [data-theme="dark"] .page-num:hover:not(.active) { background: #1e293b; }

    .grid-media { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.5rem; }
    .media-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; display: flex; flex-direction: column; }
    .media-thumb { width: 100%; aspect-ratio: 16/9; object-fit: cover; background: #e2e8f0; }
    .media-info { padding: 1rem; flex-grow: 1; display: flex; flex-direction: column; }
    .media-name { font-size: 0.85rem; font-weight: 700; word-break: break-all; margin-bottom: 0.5rem; line-height: 1.3; }
    .media-meta { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.75rem; }
    .btn-copy { padding: 0.35rem 0.6rem; background: #f1f5f9; border: 1px solid var(--border); border-radius: 4px; font-size: 0.75rem; font-weight: 600; cursor: pointer; text-align: center; margin-bottom: 0.35rem; color: #334155; }
    .btn-copy:hover { background: #e2e8f0; }
    .btn-delete { color: #dc2626; font-size: 0.75rem; text-decoration: none; font-weight: 600; margin-top: auto; padding-top: 0.5rem; }
    .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
    .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
</style>

    <div class="container">
        <div class="page-header">
            <h1 style="font-size: 1.6rem; font-weight: 800;">Biblioteca de Mídias e Imagens</h1>
        </div>

        <div id="dynamicAlert"></div>
        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <!-- Formulário de Upload Rápido / Drag and Drop -->
        <div class="card-upload" id="dropZone" onclick="handleDropZoneClick(event)">
            <form method="POST" enctype="multipart/form-data" id="uploadForm" onsubmit="event.preventDefault();">
                <input type="hidden" name="_csrf_token" id="csrfToken" value="<?= Security::e($csrfToken) ?>">
                <div class="upload-icon">📁</div>
                <p class="upload-title">
                    Envie fotos para conversão automática em WebP / AVIF
                </p>
                <p class="upload-subtitle">
                    <strong>Arraste e solte imagens aqui</strong> ou clique no botão abaixo
                </p>
                <p class="upload-hint">
                    Formatos aceitos: JPG, PNG, WebP e AVIF (até 8 MiB). Três resoluções são geradas e nomes amigáveis para SEO são aplicados.
                </p>
                <input type="file" name="media_file" id="fileInput" accept="image/jpeg,image/png,image/webp,image/avif,.jpg,.jpeg,.png,.webp,.avif" multiple style="display: none;">
                <button type="button" id="btnSelect" onclick="event.stopPropagation(); document.getElementById('fileInput').click()" style="padding: 0.65rem 1.5rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer; transition: background 0.15s;">
                    Selecionar Imagem do Computador
                </button>
                <div id="uploadStatus" style="display: none; margin-top: 1.25rem; padding: 0.75rem 1rem; background: #e0f2fe; color: #0369a1; border-radius: var(--radius); font-size: 0.9rem; font-weight: 600;">
                    <span style="display: inline-block; animation: spin 1s linear infinite; margin-right: 0.5rem;">⏳</span>
                    <span id="uploadStatusText">Enviando e processando imagem em 3 resoluções...</span>
                </div>
            </form>
        </div>

        <!-- Barra de Busca de Mídias em Tempo Real e Ordenação -->
        <div class="filter-bar">
            <form id="mediaSearchForm" method="GET" action="/admin/media.php" style="display: flex; gap: 0.75rem; align-items: center; flex: 1; min-width: 320px; max-width: 750px;" onsubmit="event.preventDefault(); triggerLiveSearch(1);">
                <input type="text" id="searchInput" name="q" value="<?= Security::e($filterSearch) ?>" placeholder="🔍 Digite para buscar fotos em tempo real..." class="form-input" style="flex: 1;" autocomplete="off">
                <select name="order" id="orderSelect" class="form-input" style="cursor: pointer; padding-right: 1.25rem;" onchange="triggerLiveSearch(1)">
                    <option value="desc" <?= $filterOrder === 'desc' ? 'selected' : '' ?>>Recentes primeiro</option>
                    <option value="asc" <?= $filterOrder === 'asc' ? 'selected' : '' ?>>Antigas primeiro</option>
                </select>
                <button type="submit" class="btn-filter btn-primary">Buscar</button>
                <a href="/admin/media.php" id="btnClearSearch" class="btn-filter" style="<?= empty($filterSearch) ? 'display: none;' : '' ?>" onclick="clearSearch(event)">Limpar</a>
            </form>
            <div id="mediaCountText" style="font-size: 0.9rem; color: var(--text-muted); font-weight: 600;">
                <?php if (!empty($filterSearch)): ?>
                    Encontradas <strong><?= number_format($totalCount, 0, ',', '.') ?></strong> imagens para "<em><?= Security::e($filterSearch) ?></em>"
                <?php else: ?>
                    Total: <strong><?= number_format($totalCount, 0, ',', '.') ?></strong> imagens cadastradas
                <?php endif; ?>
            </div>
        </div>

        <div id="searchSpinner" style="display: none; text-align: center; padding: 0.75rem 1rem; margin-bottom: 1rem; color: var(--primary-accent); font-weight: 600; font-size: 0.9rem; background: #eff6ff; border-radius: var(--radius);">
            <span style="display: inline-block; animation: spin 1s linear infinite; margin-right: 0.5rem;">⏳</span>
            Buscando imagens em tempo real no acervo...
        </div>

        <!-- Grade de Fotos -->
        <div class="grid-media" id="mediaGrid">
            <?php if (empty($mediaList)): ?>
                <p id="emptyMsg" style="text-align: center; color: var(--text-muted); padding: 3rem; grid-column: 1 / -1;">
                    Nenhuma mídia cadastrada ainda. Utilize o botão acima para enviar fotos.
                </p>
            <?php else: ?>
                <?php foreach ($mediaList as $item): ?>
                    <?php
                        $variants = json_decode($item['variants_manifest'], true) ?? [];
                        
                        $rawFull = $variants['full']['avif']['url'] ?? ($variants['full']['webp']['url'] ?? '');
                        $rawIntermediate = $variants['intermediate']['avif']['url'] ?? ($variants['intermediate']['webp']['url'] ?? '');
                        $rawThumb = $variants['thumb']['avif']['url'] ?? ($variants['thumb']['webp']['url'] ?? '');

                        $fullAvifUrl = !empty($rawFull) ? ImageProcessor::toAvifUrl($rawFull) : '';
                        $mediumAvifUrl = !empty($rawIntermediate) ? ImageProcessor::toAvifUrl($rawIntermediate) : '';
                        $thumbAvifUrl = !empty($rawThumb) ? ImageProcessor::toAvifUrl($rawThumb) : '';

                        $thumbDisplayUrl = $thumbAvifUrl ?: ($mediumAvifUrl ?: $fullAvifUrl);
                        $origW = $variants['full']['avif']['width'] ?? ($variants['full']['webp']['width'] ?? 0);
                        $origH = $variants['full']['avif']['height'] ?? ($variants['full']['webp']['height'] ?? 0);

                        $origSize = (int)$item['file_size'];
                        $origExt = strtoupper(pathinfo($item['original_name'], PATHINFO_EXTENSION) ?: 'IMG');

                        // Medições da variante Full WebP
                        $webpSize = $variants['full']['webp']['size'] ?? 0;
                        if (!$webpSize && !empty($variants['full']['webp']['url'])) {
                            $localWebp = $config['paths']['public'] . $variants['full']['webp']['url'];
                            if (file_exists($localWebp)) {
                                $webpSize = filesize($localWebp);
                            }
                        }
                        $webpReduction = ($origSize > 0 && $webpSize > 0 && $webpSize < $origSize)
                            ? round((1 - ($webpSize / $origSize)) * 100)
                            : 0;

                        // Medições da variante Full AVIF
                        $avifSize = $variants['full']['avif']['size'] ?? 0;
                        if (!$avifSize && !empty($variants['full']['avif']['url'])) {
                            $localAvif = $config['paths']['public'] . $variants['full']['avif']['url'];
                            if (file_exists($localAvif)) {
                                $avifSize = filesize($localAvif);
                            }
                        }
                        $avifReduction = ($origSize > 0 && $avifSize > 0 && $avifSize < $origSize)
                            ? round((1 - ($avifSize / $origSize)) * 100)
                            : 0;
                    ?>
                    <div class="media-card" id="media-card-<?= Security::e($item['id']) ?>">
                        <?php if (!empty($thumbDisplayUrl)): ?>
                            <img src="<?= Security::e($thumbDisplayUrl) ?>" alt="<?= Security::e($item['original_name']) ?>" class="media-thumb" loading="lazy">
                        <?php else: ?>
                            <div class="media-thumb" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;">Sem miniatura</div>
                        <?php endif; ?>
                        <div class="media-info">
                            <div class="media-name" title="<?= Security::e($item['original_name']) ?>">
                                <?= Security::e($item['original_name']) ?>
                            </div>
                            <div class="media-meta" style="line-height: 1.5; font-size: 0.78rem;">
                                <div style="color: #475569; margin-bottom: 0.2rem;">
                                    <strong><?= round($origSize / 1024) ?> KiB</strong> &bull; <?= $origW ?>x<?= $origH ?>px - <span style="font-weight: 700;"><?= Security::e($origExt) ?></span>
                                </div>
                                <?php if ($avifSize > 0): ?>
                                    <div style="color: #047857; margin-bottom: 0.2rem;">
                                        <strong><?= round($avifSize / 1024) ?> KiB</strong> &bull; <?= $origW ?>x<?= $origH ?>px - <span style="font-weight: 700;">AVIF</span>
                                        <?php if ($avifReduction > 0): ?>
                                            - <span style="font-weight: 700; color: #059669;"><?= $avifReduction ?>% (redução do tamanho)</span>
                                        <?php else: ?>
                                            - <span style="font-weight: 700; color: #059669;">Formato original otimizado</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($webpSize > 0): ?>
                                    <div style="color: var(--primary-accent); margin-bottom: 0.35rem;">
                                        <strong><?= round($webpSize / 1024) ?> KiB</strong> &bull; <?= $origW ?>x<?= $origH ?>px - <span style="font-weight: 700;">WebP</span>
                                        <?php if ($webpReduction > 0): ?>
                                            - <span style="font-weight: 700; color: var(--primary-accent);"><?= $webpReduction ?>% (redução do tamanho)</span>
                                        <?php else: ?>
                                            - <span style="font-weight: 700; color: var(--primary-accent);">Formato original otimizado</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div style="color: var(--text-muted); font-size: 0.72rem;">
                                    Por <?= Security::e($item['uploader_name']) ?>
                                </div>
                            </div>

                            <?php if (!empty($fullAvifUrl)): ?>
                                <button type="button" class="btn-copy" onclick="copyUrl('<?= Security::e($fullAvifUrl) ?>', this)">
                                    Copiar URL (Original / Full .avif)
                                </button>
                            <?php endif; ?>

                            <?php if (!empty($mediumAvifUrl)): ?>
                                <button type="button" class="btn-copy" onclick="copyUrl('<?= Security::e($mediumAvifUrl) ?>', this)">
                                    Copiar URL (1024px .avif)
                                </button>
                            <?php endif; ?>

                            <?php if (!empty($thumbAvifUrl)): ?>
                                <button type="button" class="btn-copy" onclick="copyUrl('<?= Security::e($thumbAvifUrl) ?>', this)">
                                    Copiar URL (Thumb .avif)
                                </button>
                            <?php endif; ?>

                            <?php if (!empty($item['alt_text']) || !empty($item['ai_tags'])): ?>
                                <div class="media-ai-meta" id="ai-meta-<?= Security::e($item['id']) ?>" style="margin-top:0.4rem; padding:0.45rem; background:rgba(0,0,0,0.03); border-radius:4px; font-size:0.75rem; border:1px solid var(--border); text-align:left;">
                                    <?php if (!empty($item['alt_text'])): ?>
                                        <div style="margin-bottom:0.25rem;">
                                            <strong style="color:var(--text);">Alt:</strong> <span class="alt-val" style="color:var(--text-muted);"><?= Security::e($item['alt_text']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['caption'])): ?>
                                        <div style="margin-bottom:0.25rem;">
                                            <strong style="color:var(--text);">Legenda:</strong> <span class="caption-val" style="color:var(--text-muted);"><?= Security::e($item['caption']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['ai_tags'])): ?>
                                        <div style="display:flex; flex-wrap:wrap; gap:0.2rem; margin-top:0.25rem;">
                                            <?php foreach (explode(',', (string)$item['ai_tags']) as $t): $t = trim($t); if ($t === '') continue; ?>
                                                <span style="background:#e0f2fe; color:#0369a1; padding:0.1rem 0.35rem; border-radius:3px; font-size:0.68rem; font-weight:600;"><?= Security::e($t) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="media-ai-meta" id="ai-meta-<?= Security::e($item['id']) ?>" style="display:none; margin-top:0.4rem; padding:0.45rem; background:rgba(0,0,0,0.03); border-radius:4px; font-size:0.75rem; border:1px solid var(--border); text-align:left;"></div>
                            <?php endif; ?>

                            <?php if ($isVisionEnabled): ?>
                                <button type="button" class="btn-copy btn-describe-ai" 
                                        onclick="describeMediaAi('<?= Security::e($item['id']) ?>', this)" 
                                        style="background:#f0fdf4; color:#166534; border-color:#bbf7d0; font-weight:600; margin-top:0.35rem;" 
                                        title="Gera ou atualiza Alt Text, Legenda e Tags visuais usando IA">
                                    ✨ <?= !empty($item['alt_text']) ? 'Atualizar com IA' : 'Descrever com IA' ?>
                                </button>
                            <?php endif; ?>

                            <?php 
                            $canDeleteItem = in_array($currentUser['role'], ['admin', 'editor'], true) || ((int)($item['user_id'] ?? 0) === (int)$currentUser['id']);
                            if ($canDeleteItem): 
                            ?>
                                <form method="POST" action="/admin/media.php" style="display:inline; width:100%;" onsubmit="return confirm('Deseja excluir esta mídia? Esta ação removerá os arquivos em disco se a imagem não estiver vinculada a nenhum artigo.');">
                                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                    <input type="hidden" name="delete_id" value="<?= Security::e($item['id']) ?>">
                                    <button type="submit" class="btn-delete" style="width:100%; border:none; cursor:pointer;">
                                        Excluir Imagem
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Rodapé com Paginação -->
        <div id="mediaPagination">
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <div>
                        Página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong> (Exibindo <?= count($mediaList) ?> de <?= number_format($totalCount, 0, ',', '.') ?> imagens)
                    </div>
                    <div class="page-links">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildMediaUrl(['page' => $page - 1]) ?>" class="page-num">&larr; Anterior</a>
                        <?php endif; ?>

                        <?php 
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) {
                            echo '<a href="' . buildMediaUrl(['page' => 1]) . '" class="page-num">1</a>';
                            if ($startPage > 2) echo '<span class="page-num" style="border:none; cursor:default; background:transparent;">...</span>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++) {
                            $active = ($i === $page) ? ' active' : '';
                            echo '<a href="' . buildMediaUrl(['page' => $i]) . '" class="page-num' . $active . '">' . $i . '</a>';
                        }
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span class="page-num" style="border:none; cursor:default; background:transparent;">...</span>';
                            echo '<a href="' . buildMediaUrl(['page' => $totalPages]) . '" class="page-num">' . $totalPages . '</a>';
                        }
                        ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildMediaUrl(['page' => $page + 1]) ?>" class="page-num">Próxima &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function copyUrl(url, btn) {
            navigator.clipboard.writeText(url).then(function() {
                var oldText = btn.innerText;
                btn.innerText = 'Copiado!';
                btn.style.background = '#dcfce7';
                btn.style.color = '#166534';
                setTimeout(function() {
                    btn.innerText = oldText;
                    btn.style.background = '#f1f5f9';
                    btn.style.color = '#334155';
                }, 1500);
            });
        }

        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const btnSelect = document.getElementById('btnSelect');
        const uploadStatus = document.getElementById('uploadStatus');
        const uploadStatusText = document.getElementById('uploadStatusText');
        const dynamicAlert = document.getElementById('dynamicAlert');
        const csrfToken = document.getElementById('csrfToken').value;

        function showAlert(mainText, detailsArray, isError) {
            dynamicAlert.replaceChildren();
            const alertDiv = document.createElement('div');
            alertDiv.className = isError ? 'alert-error' : 'alert-success';

            if (mainText) {
                const titleNode = document.createElement('div');
                titleNode.textContent = mainText;
                alertDiv.appendChild(titleNode);
            }

            if (Array.isArray(detailsArray) && detailsArray.length > 0) {
                const listContainer = document.createElement('div');
                listContainer.style.fontSize = '0.85rem';
                listContainer.style.marginTop = mainText ? '0.35rem' : '0';
                for (let j = 0; j < detailsArray.length; j++) {
                    const item = document.createElement('div');
                    item.textContent = detailsArray[j];
                    listContainer.appendChild(item);
                }
                alertDiv.appendChild(listContainer);
            }

            dynamicAlert.appendChild(alertDiv);
        }

        function handleDropZoneClick(e) {
            if (e.target && (e.target.id === 'fileInput' || e.target.closest('#btnSelect'))) {
                return;
            }
            if (btnSelect && btnSelect.disabled) return;
            if (fileInput) fileInput.click();
        }

        const validImageRegex = /\.(jpe?g|png|webp|avif)$/i;

        async function processFiles(fileList) {
            if (!fileList || fileList.length === 0) return;
            const files = Array.from(fileList);

            uploadStatus.style.display = 'block';
            if (btnSelect) {
                btnSelect.disabled = true;
                btnSelect.style.opacity = '0.6';
            }
            if (dropZone) dropZone.style.pointerEvents = 'none';
            dynamicAlert.replaceChildren();

            let successCount = 0;
            let errorMessages = [];
            const uploadedMediaIds = [];

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const isImage = (file.type && file.type.startsWith('image/')) || validImageRegex.test(file.name);
                
                if (!isImage) {
                    errorMessages.push('"' + file.name + '" não é um formato suportado (JPG, PNG, WebP, AVIF).');
                    continue;
                }

                if (file.size > 8 * 1024 * 1024) {
                    errorMessages.push('"' + file.name + '" (' + (file.size / (1024 * 1024)).toFixed(1) + ' MiB) excede o limite máximo permitido de 8 MiB.');
                    continue;
                }

                const progressPrefix = files.length > 1 ? '[' + (i + 1) + '/' + files.length + '] ' : '';
                uploadStatusText.innerText = progressPrefix + 'Enviando ' + file.name + ' (' + Math.round(file.size / 1024) + ' KiB) e gerando WebP / AVIF...';

                const formData = new FormData();
                formData.append('media_file', file);
                formData.append('_csrf_token', csrfToken);

                try {
                    const res = await fetch('/admin/media.php', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    });

                    let data = null;
                    try {
                        data = await res.json();
                    } catch (_) {}

                    if (res.ok && data && data.ok) {
                        successCount++;
                        if (data.media && data.media.id) {
                            uploadedMediaIds.push(data.media.id);
                        }
                    } else {
                        const errMsg = (data && data.error) ? data.error : ('HTTP ' + res.status);
                        errorMessages.push('"' + file.name + '": ' + errMsg);
                    }
                } catch (err) {
                    errorMessages.push('"' + file.name + '": Erro de rede (' + err.message + ')');
                }
            }

            uploadStatus.style.display = 'none';
            if (btnSelect) {
                btnSelect.disabled = false;
                btnSelect.style.opacity = '1';
            }
            if (dropZone) dropZone.style.pointerEvents = 'auto';
            if (fileInput) fileInput.value = '';

            if (successCount > 0) {
                const msg = successCount === 1 
                    ? 'Imagem enviada e convertida com sucesso!' 
                    : successCount + ' imagens enviadas e convertidas com sucesso!';
                showAlert(msg, errorMessages, false);
                triggerLiveSearch(1);

                if (isVisionAuto && uploadedMediaIds.length > 0) {
                    uploadedMediaIds.forEach(function(mId) {
                        describeMediaAi(mId, null);
                    });
                }
            } else if (errorMessages.length > 0) {
                showAlert('', errorMessages, true);
            }
        }

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                processFiles(this.files);
            });
        }

        if (dropZone) {
            ['dragenter', 'dragover'].forEach(function(evt) {
                dropZone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropZone.classList.add('dragover');
                }, false);
            });

            dropZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (!dropZone.contains(e.relatedTarget)) {
                    dropZone.classList.remove('dragover');
                }
            }, false);

            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('dragover');
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    processFiles(e.dataTransfer.files);
                }
            }, false);
        }

        // Previne comportamento padrão de abrir o arquivo se solto na janela
        ['dragenter', 'dragover', 'drop'].forEach(function(evt) {
            window.addEventListener(evt, function(e) {
                if (e.target !== dropZone && !dropZone.contains(e.target)) {
                    e.preventDefault();
                }
            }, false);
        });
    
        // ========================================================
        // Busca em Tempo Real e Paginação Assíncrona Instantânea
        // ========================================================
        let liveSearchTimer = null;
        const searchInput = document.getElementById('searchInput');
        const orderSelect = document.getElementById('orderSelect');
        const btnClear = document.getElementById('btnClearSearch');
        const searchSpinner = document.getElementById('searchSpinner');

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(liveSearchTimer);
                const val = this.value.trim();
                if (btnClear) {
                    btnClear.style.display = val ? 'inline-flex' : 'none';
                }
                liveSearchTimer = setTimeout(function() {
                    triggerLiveSearch(1);
                }, 300);
            });
        }

        function clearSearch(e) {
            if (e) e.preventDefault();
            if (searchInput) searchInput.value = '';
            if (btnClear) btnClear.style.display = 'none';
            triggerLiveSearch(1);
        }

        function triggerLiveSearch(page) {
            const query = searchInput ? searchInput.value.trim() : '';
            const order = orderSelect ? orderSelect.value : 'desc';
            const pageNum = page || 1;

            const params = new URLSearchParams();
            if (query) params.set('q', query);
            if (order && order !== 'desc') params.set('order', order);
            if (pageNum > 1) params.set('page', pageNum);

            const targetUrl = '/admin/media.php' + (params.toString() ? '?' + params.toString() : '');
            
            // Atualiza URL no navegador sem recarregar a página para persistir histórico
            history.replaceState(null, '', targetUrl);

            const grid = document.getElementById('mediaGrid');
            const paginationContainer = document.getElementById('mediaPagination');
            
            if (searchSpinner) searchSpinner.style.display = 'block';
            if (grid) grid.style.opacity = '0.4';

            fetch(targetUrl)
                .then(function(res) { return res.text(); })
                .then(function(html) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    const newGrid = doc.getElementById('mediaGrid');
                    const newCount = doc.getElementById('mediaCountText');
                    const newPagination = doc.getElementById('mediaPagination');

                    if (newGrid && grid) {
                        grid.innerHTML = newGrid.innerHTML;
                        grid.style.opacity = '1';
                    }

                    if (newCount && document.getElementById('mediaCountText')) {
                        document.getElementById('mediaCountText').innerHTML = newCount.innerHTML;
                    }

                    if (paginationContainer) {
                        paginationContainer.innerHTML = newPagination ? newPagination.innerHTML : '';
                    }

                    if (searchSpinner) searchSpinner.style.display = 'none';
                    attachPaginationListeners();
                })
                .catch(function(err) {
                    if (grid) grid.style.opacity = '1';
                    if (searchSpinner) searchSpinner.style.display = 'none';
                });
        }

        const isVisionAuto = <?= ($isVisionEnabled && $visionMode === 'auto') ? 'true' : 'false' ?>;

        async function describeMediaAi(mediaId, btnElement) {
            let originalText = '';
            if (btnElement) {
                originalText = btnElement.innerText;
                btnElement.disabled = true;
                btnElement.innerText = '✨ Analisando com IA...';
            }

            try {
                const res = await fetch('/admin/api/ai.php?action=describe_media', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrfToken
                    },
                    body: new URLSearchParams({
                        '_csrf_token': csrfToken,
                        'media_id': mediaId
                    })
                });

                const data = await res.json();
                if (res.ok && data.ok) {
                    const cardMeta = document.getElementById('ai-meta-' + mediaId);
                    if (cardMeta) {
                        let tagsHtml = '';
                        if (data.ai_tags) {
                            const tags = data.ai_tags.split(',');
                            tagsHtml = '<div style="display:flex; flex-wrap:wrap; gap:0.2rem; margin-top:0.25rem;">' +
                                tags.map(t => '<span style="background:#e0f2fe; color:#0369a1; padding:0.1rem 0.35rem; border-radius:3px; font-size:0.68rem; font-weight:600;">' + escapeHtml(t.trim()) + '</span>').join('') +
                                '</div>';
                        }
                        cardMeta.innerHTML = 
                            '<div style="margin-bottom:0.25rem;"><strong style="color:var(--text);">Alt:</strong> <span class="alt-val" style="color:var(--text-muted);">' + escapeHtml(data.alt_text) + '</span></div>' +
                            '<div style="margin-bottom:0.25rem;"><strong style="color:var(--text);">Legenda:</strong> <span class="caption-val" style="color:var(--text-muted);">' + escapeHtml(data.caption) + '</span></div>' +
                            tagsHtml;
                        cardMeta.style.display = 'block';
                    }
                    if (btnElement) {
                        btnElement.innerText = '✨ Atualizar com IA';
                        btnElement.disabled = false;
                    }
                    showAlert('Descrição da imagem gerada com sucesso pela IA!', [], false);
                } else {
                    const err = (data && data.error) ? data.error : 'Falha ao descrever imagem';
                    showAlert('', [err], true);
                    if (btnElement) {
                        btnElement.innerText = originalText;
                        btnElement.disabled = false;
                    }
                }
            } catch (err) {
                showAlert('', ['Erro de rede ao conectar com a IA: ' + err.message], true);
                if (btnElement) {
                    btnElement.innerText = originalText;
                    btnElement.disabled = false;
                }
            }
        }

        function attachPaginationListeners() {
            const paginationContainer = document.getElementById('mediaPagination');
            if (!paginationContainer) return;

            const links = paginationContainer.querySelectorAll('a.page-num');
            links.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    if (href) {
                        const urlObj = new URL(href, window.location.origin);
                        const page = urlObj.searchParams.get('page') || 1;
                        triggerLiveSearch(page);
                        const filterBar = document.querySelector('.filter-bar');
                        if (filterBar) {
                            window.scrollTo({ top: filterBar.offsetTop - 20, behavior: 'smooth' });
                        }
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', attachPaginationListeners);

    </script>
<?php require __DIR__ . '/footer.php'; ?>
