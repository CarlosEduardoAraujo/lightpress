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

// 2. Exclusão de Mídia Segura (Verifica permissão e se está vinculada a artigos/páginas)
if (isset($_GET['delete_id']) && Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
    $mediaId = trim($_GET['delete_id']);
    $mediaItem = Database::fetchOne("SELECT * FROM media WHERE id = ? LIMIT 1", [$mediaId]);

    if (!$mediaItem) {
        $error = "Imagem não encontrada.";
    } else {
        $canDelete = in_array($currentUser['role'], ['admin', 'editor'], true) || ((int)($mediaItem['user_id'] ?? 0) === (int)$currentUser['id']);
        if (!$canDelete) {
            $error = "Você não tem permissão para excluir esta imagem da biblioteca.";
        } else {
            // Verificar se alguma das variantes está em uso no banco
            $isInUse = false;
            $variants = json_decode($mediaItem['variants_manifest'], true) ?? [];
            foreach ($variants as $res => $formats) {
                if (!is_array($formats)) continue;
                foreach ($formats as $fmt => $info) {
                    $url = $info['url'] ?? '';
                    if (!empty($url)) {
                        $inPostsFeatured = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE featured_image = ?", [$url]);
                        if ($inPostsFeatured > 0) { $isInUse = true; break 2; }
                        $inPostsContent = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE content_html LIKE ?", ['%' . $url . '%']);
                        if ($inPostsContent > 0) { $isInUse = true; break 2; }
                        $inPages = (int)Database::fetchValue("SELECT COUNT(*) FROM pages WHERE featured_image = ? OR content_html LIKE ?", [$url, '%' . $url . '%']);
                        if ($inPages > 0) { $isInUse = true; break 2; }
                        // Verificar também nas revisões históricas de matérias (post_revisions)
                        $inRevisions = (int)Database::fetchValue("SELECT COUNT(*) FROM post_revisions WHERE content_html LIKE ? OR payload_json LIKE ?", ['%' . $url . '%', '%' . $url . '%']);
                        if ($inRevisions > 0) { $isInUse = true; break 2; }
                    }
                }
            }

            if ($isInUse) {
                $error = "Esta imagem não pode ser excluída pois está em uso como destaque ou no conteúdo de matérias, páginas ou revisões históricas do acervo.";
            } else {
                $publicPath = $config['paths']['public'] ?? (dirname(__DIR__, 2) . '/public');
                foreach ($variants as $res => $formats) {
                    if (!is_array($formats)) continue;
                    foreach ($formats as $fmt => $info) {
                        $localPath = $publicPath . ($info['url'] ?? '');
                        if (file_exists($localPath)) {
                            @unlink($localPath);
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

// 3. Listar mídias cadastradas (mais recentes primeiro)
$mediaList = Database::fetchAll(
    "SELECT m.*, u.name as uploader_name 
     FROM media m
     JOIN users u ON u.id = m.user_id
     ORDER BY m.created_at DESC 
     LIMIT 60"
);

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biblioteca de Mídias - SpeedPress Admin</title>

    <!-- Detecção Instantânea de Tema Claro/Escuro sem Flash -->
    <script>
        (function() {
            var saved = localStorage.getItem('lp_theme');
            if (saved === 'dark' || (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --text-muted: #64748b;
            --radius: 8px;
            --font: system-ui, -apple-system, sans-serif;
        }
        [data-theme="dark"] {
            --primary: #f8fafc;
            --primary-accent: #3b82f6;
            --bg: #090d16;
            --surface: #131b2e;
            --border: #1e293b;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.5; transition: background 0.2s, color 0.2s; }
        
        [data-theme="dark"] .btn-copy { background: #1e293b; color: #f1f5f9; border-color: #334155; }
        [data-theme="dark"] .btn-copy:hover { background: #334155; }
        [data-theme="dark"] .card-upload { background: #131b2e; border-color: #1e293b; }
        [data-theme="dark"] .card-upload p { color: #f1f5f9 !important; }

        .site-header { 
            background: var(--surface); 
            border-bottom: 1px solid var(--border); 
            padding: 0.85rem 1.75rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            position: sticky;
            top: 0;
            z-index: 100;
            transition: background 0.2s, border-color 0.2s;
        }
        .brand { font-size: 1.3rem; font-weight: 800; color: var(--primary); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .container { max-width: 1350px; margin: 2rem auto; padding: 0 1.5rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-upload { background: var(--surface); border: 2px dashed var(--border); border-radius: var(--radius); padding: 2rem; text-align: center; margin-bottom: 2rem; }
        .grid-media { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1.5rem; }
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

        .theme-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .theme-btn:hover { background: var(--border); }
    </style>
</head>
<body>
    <header class="site-header">
        <div style="display: flex; align-items: center; gap: 2.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Redação</a>
            <nav style="display: flex; gap: 1.25rem; font-size: 0.92rem; align-items: center;">
                <a href="/admin/dashboard.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Notícias</a>
                <a href="/admin/pages.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Páginas</a>
                <a href="/admin/media.php" style="color: var(--primary-accent); font-weight: 700; text-decoration: none;">Mídias</a>
                <a href="/admin/categories.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Categorias</a>
                <a href="/admin/stats.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Mais Lidas 📈</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/users.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Usuários & Autores</a>
                    <a href="/admin/settings.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Configurações</a>
                <?php endif; ?>
                <a href="/admin/system.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Diagnóstico</a>
                <a href="/" target="_blank" style="color: var(--primary-accent); text-decoration: none; font-weight: 600;">Ver Site &rarr;</a>
            </nav>
        </div>
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span>Olá, <strong><?= Security::e($currentUser['name']) ?></strong></span>
            <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" id="btnThemeToggle">🌙</button>
            <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: #dc2626; text-decoration: none; font-size: 0.9rem; font-weight: 600;">Sair</a>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1 style="font-size: 1.6rem; font-weight: 800;">Biblioteca de Mídias e Imagens</h1>
        </div>

        <div id="dynamicAlert"></div>
        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <!-- Formulário de Upload Rápido -->
        <div class="card-upload">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="_csrf_token" id="csrfToken" value="<?= Security::e($csrfToken) ?>">
                <p style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: #334155;">
                    Envie fotos para conversão automática em WebP / AVIF
                </p>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Formatos aceitos: JPG, PNG, WebP e AVIF (até 8 MiB). Três resoluções são geradas e nomes amigáveis para SEO são aplicados.
                </p>
                <input type="file" name="media_file" id="fileInput" accept="image/jpeg,image/png,image/webp,image/avif,.jpg,.jpeg,.png,.webp,.avif" required style="display: none;">
                <button type="button" id="btnSelect" onclick="document.getElementById('fileInput').click()" style="padding: 0.65rem 1.5rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer;">
                    Selecionar Imagem do Computador
                </button>
                <div id="uploadStatus" style="display: none; margin-top: 1rem; padding: 0.75rem 1rem; background: #e0f2fe; color: #0369a1; border-radius: var(--radius); font-size: 0.9rem; font-weight: 600;">
                    <span style="display: inline-block; animation: spin 1s linear infinite; margin-right: 0.5rem;">⏳</span>
                    <span id="uploadStatusText">Enviando e processando imagem em 3 resoluções...</span>
                </div>
            </form>
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
                                    <div style="color: #1d4ed8; margin-bottom: 0.35rem;">
                                        <strong><?= round($webpSize / 1024) ?> KiB</strong> &bull; <?= $origW ?>x<?= $origH ?>px - <span style="font-weight: 700;">WebP</span>
                                        <?php if ($webpReduction > 0): ?>
                                            - <span style="font-weight: 700; color: #2563eb;"><?= $webpReduction ?>% (redução do tamanho)</span>
                                        <?php else: ?>
                                            - <span style="font-weight: 700; color: #2563eb;">Formato original otimizado</span>
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

                            <?php 
                            $canDeleteItem = in_array($currentUser['role'], ['admin', 'editor'], true) || ((int)($item['user_id'] ?? 0) === (int)$currentUser['id']);
                            if ($canDeleteItem): 
                            ?>
                                <a href="/admin/media.php?delete_id=<?= urlencode($item['id']) ?>&_csrf_token=<?= Security::e($csrfToken) ?>" 
                                   class="btn-delete"
                                   onclick="return confirm('Deseja excluir esta mídia? Esta ação removerá os arquivos em disco se a imagem não estiver vinculada a nenhum artigo.');">
                                    Excluir Imagem
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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

        const fileInput = document.getElementById('fileInput');
        const btnSelect = document.getElementById('btnSelect');
        const uploadStatus = document.getElementById('uploadStatus');
        const uploadStatusText = document.getElementById('uploadStatusText');
        const dynamicAlert = document.getElementById('dynamicAlert');
        const csrfToken = document.getElementById('csrfToken').value;

        function showAlert(msg, isError) {
            dynamicAlert.innerHTML = '<div class="' + (isError ? 'alert-error' : 'alert-success') + '">' + msg + '</div>';
        }

        fileInput.addEventListener('change', function() {
            if (!this.files || !this.files[0]) return;
            const file = this.files[0];

            // Validação de tamanho no cliente (8 MiB)
            if (file.size > 8 * 1024 * 1024) {
                showAlert('O arquivo selecionado (' + (file.size / (1024 * 1024)).toFixed(1) + ' MiB) excede o limite máximo permitido de 8 MiB.', true);
                this.value = '';
                return;
            }

            uploadStatus.style.display = 'block';
            btnSelect.disabled = true;
            btnSelect.style.opacity = '0.6';
            uploadStatusText.innerText = 'Enviando ' + file.name + ' (' + Math.round(file.size / 1024) + ' KiB) e gerando variantes WebP / AVIF...';
            dynamicAlert.innerHTML = '';

            const formData = new FormData();
            formData.append('media_file', file);
            formData.append('_csrf_token', csrfToken);

            fetch('/admin/media.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function(res) {
                return res.json().then(function(data) {
                    return { ok: res.ok, status: res.status, data: data };
                }).catch(function() {
                    return { ok: res.ok, status: res.status, data: null };
                });
            })
            .then(function(result) {
                uploadStatus.style.display = 'none';
                btnSelect.disabled = false;
                btnSelect.style.opacity = '1';
                fileInput.value = '';

                if (result.ok && result.data && result.data.ok) {
                    showAlert('Imagem enviada e convertida com sucesso!', false);
                    setTimeout(function() {
                        window.location.reload();
                    }, 800);
                } else {
                    const errorMsg = (result.data && result.data.error) ? result.data.error : ('Erro durante o envio da imagem (HTTP ' + result.status + '). Verifique os limites do PHP.');
                    showAlert(errorMsg, true);
                }
            })
            .catch(function(err) {
                uploadStatus.style.display = 'none';
                btnSelect.disabled = false;
                btnSelect.style.opacity = '1';
                fileInput.value = '';
                showAlert('Erro de conexão ou timeout ao enviar arquivo: ' + err.message, true);
            });
        });

        // Alternância de Tema Claro / Escuro
        function updateThemeIcon() {
            var current = document.documentElement.getAttribute('data-theme');
            var btn = document.getElementById('btnThemeToggle');
            if (btn) btn.innerText = (current === 'dark') ? '☀️' : '🌙';
        }
        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next = (current === 'dark') ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('lp_theme', next);
            updateThemeIcon();
        }
        updateThemeIcon();
    </script>
</body>
</html>
