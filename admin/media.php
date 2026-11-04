<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Biblioteca e Galeria de Mídias
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

Security::startSecureSession($config);

if (empty($_SESSION['user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = Database::fetchOne(
    "SELECT id, name, username, role, is_active, session_version 
     FROM users WHERE id = ? LIMIT 1",
    [(int)$_SESSION['user_id']]
);

if (!$currentUser || empty($currentUser['is_active'])) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

$message = '';
$error = '';

// 1. Processar Upload Direto na Galeria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['media_file'])) {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } elseif (!is_uploaded_file($_FILES['media_file']['tmp_name'])) {
        $error = "Nenhum arquivo enviado.";
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

            $message = "Imagem processada e adicionada à biblioteca com sucesso!";
        } catch (\Throwable $e) {
            $error = "Erro no upload: " . $e->getMessage();
        }
    }
}

// 2. Exclusão de Mídia Segura (Verifica se está vinculada a artigos)
if (isset($_GET['delete_id']) && Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
    $mediaId = trim($_GET['delete_id']);
    
    // Verificar se há referências ativas em media_refs
    $refsCount = (int)(Database::fetchOne("SELECT COUNT(*) as c FROM media_refs WHERE media_id = ?", [$mediaId])['c'] ?? 0);

    if ($refsCount > 0) {
        $error = "Esta imagem não pode ser excluída pois está vinculada a artigos publicados ou revisões.";
    } else {
        $mediaItem = Database::fetchOne("SELECT * FROM media WHERE id = ?", [$mediaId]);
        if ($mediaItem) {
            // Remover arquivos físicos
            $variants = json_decode($mediaItem['variants_manifest'], true) ?? [];
            foreach ($variants as $res => $formats) {
                foreach ($formats as $fmt => $info) {
                    $localPath = $config['paths']['public'] . ($info['url'] ?? '');
                    if (file_exists($localPath)) {
                        @unlink($localPath);
                    }
                }
            }

            Database::query("DELETE FROM media WHERE id = ?", [$mediaId]);
            $message = "Mídia excluída com sucesso!";
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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); }
        header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.75rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 1.25rem; font-weight: 800; color: var(--primary); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; }
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
    </style>
</head>
<body>
    <header>
        <div style="display: flex; align-items: center; gap: 2rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Admin</a>
            <nav style="display: flex; gap: 1rem; font-size: 0.9rem;">
                <a href="/admin/dashboard.php" style="color: var(--text-muted); text-decoration: none;">Notícias</a>
                <a href="/admin/media.php" style="color: var(--primary); font-weight: 700; text-decoration: none;">Mídias</a>
                <a href="/admin/categories.php" style="color: var(--text-muted); text-decoration: none;">Categorias</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/users.php" style="color: var(--text-muted); text-decoration: none;">Usuários</a>
                <?php endif; ?>
                <a href="/admin/system.php" style="color: var(--text-muted); text-decoration: none;">Diagnóstico</a>
                <a href="/" target="_blank" style="color: var(--text-muted); text-decoration: none;">Ver Site &rarr;</a>
            </nav>
        </div>
        <div>
            <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: #dc2626; text-decoration: none; font-size: 0.9rem; font-weight: 600;">Sair</a>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1 style="font-size: 1.6rem; font-weight: 800;">Biblioteca de Mídias e Imagens</h1>
        </div>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <!-- Formulário de Upload Rápido -->
        <div class="card-upload">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                <p style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; color: #334155;">
                    Envie fotos para conversão automática em WebP / AVIF
                </p>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                    Formatos aceitos: JPG, PNG, WebP (até 8 MiB). Imagens originais são descartadas e 3 resoluções são geradas.
                </p>
                <input type="file" name="media_file" id="fileInput" accept="image/jpeg,image/png,image/webp" required style="display: none;" onchange="document.getElementById('uploadForm').submit()">
                <button type="button" onclick="document.getElementById('fileInput').click()" style="padding: 0.65rem 1.5rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer;">
                    Selecionar Imagem do Computador
                </button>
            </form>
        </div>

        <!-- Grade de Fotos -->
        <?php if (empty($mediaList)): ?>
            <p style="text-align: center; color: var(--text-muted); padding: 3rem;">Nenhuma mídia cadastrada ainda. Utilize o botão acima para enviar fotos.</p>
        <?php else: ?>
            <div class="grid-media">
                <?php foreach ($mediaList as $item): ?>
                    <?php
                        $variants = json_decode($item['variants_manifest'], true) ?? [];
                        $thumbUrl = $variants['thumb']['webp']['url'] ?? ($variants['intermediate']['webp']['url'] ?? '');
                        $fullUrl = $variants['intermediate']['webp']['url'] ?? ($variants['full']['webp']['url'] ?? '');
                        $origW = $variants['full']['webp']['width'] ?? 0;
                        $origH = $variants['full']['webp']['height'] ?? 0;
                    ?>
                    <div class="media-card">
                        <?php if (!empty($thumbUrl)): ?>
                            <img src="<?= Security::e($thumbUrl) ?>" alt="<?= Security::e($item['original_name']) ?>" class="media-thumb" loading="lazy">
                        <?php else: ?>
                            <div class="media-thumb" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;">Sem miniatura</div>
                        <?php endif; ?>
                        <div class="media-info">
                            <div class="media-name" title="<?= Security::e($item['original_name']) ?>">
                                <?= Security::e($item['original_name']) ?>
                            </div>
                            <div class="media-meta">
                                <span><?= round((int)$item['file_size'] / 1024) ?> KiB</span> &bull; 
                                <span><?= $origW ?>x<?= $origH ?>px</span><br>
                                <span>Por <?= Security::e($item['uploader_name']) ?></span>
                            </div>

                            <?php if (!empty($fullUrl)): ?>
                                <button type="button" class="btn-copy" onclick="copyUrl('<?= Security::e($fullUrl) ?>', this)">
                                    Copiar URL (1024px)
                                </button>
                            <?php endif; ?>

                            <?php if (!empty($variants['full']['webp']['url'])): ?>
                                <button type="button" class="btn-copy" onclick="copyUrl('<?= Security::e($variants['full']['webp']['url']) ?>', this)">
                                    Copiar URL (Full 1920px)
                                </button>
                            <?php endif; ?>

                            <a href="/admin/media.php?delete_id=<?= urlencode($item['id']) ?>&_csrf_token=<?= Security::e($csrfToken) ?>" 
                               class="btn-delete"
                               onclick="return confirm('Deseja excluir esta mídia? Esta ação removerá os arquivos em disco se a imagem não estiver vinculada a nenhum artigo.');">
                                Excluir Imagem
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
    </script>
</body>
</html>
