<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Editor de Blocos Leve em Vanilla JS (< 50 KiB)
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\PostService;
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

$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post = null;
$message = '';
$error = '';

if ($postId > 0) {
    $post = Database::fetchOne("SELECT * FROM posts WHERE id = ? LIMIT 1", [$postId]);
    if (!$post) {
        die("Artigo não encontrado.");
    }
    // Verificar permissão
    if (!Authorization::can($currentUser, Authorization::ACTION_EDIT_POST, $post)) {
        die("Você não possui permissão para editar este artigo.");
    }
}

$postService = new PostService();

// Processamento do formulário de salvamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!Security::verifyCsrfToken($token)) {
        $error = "Token de segurança expirado. Recarregue a página.";
    } else {
        $action = $_POST['form_action'] ?? 'save_draft';
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'geral');
        $summary = trim($_POST['summary'] ?? '');
        $featuredImage = trim($_POST['featured_image'] ?? '');
        $contentJson = $_POST['content_json'] ?? '[]';
        $seoTitle = trim($_POST['seo_title'] ?? '');
        $seoDesc = trim($_POST['seo_description'] ?? '');
        $isFeatured = !empty($_POST['is_featured']) ? 1 : 0;
        $knownVersion = (int)($_POST['version'] ?? 1);

        $payload = [
            'title' => $title,
            'summary' => $summary,
            'category' => $category,
            'featured_image' => $featuredImage,
            'content_json' => $contentJson,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDesc,
            'is_featured' => $isFeatured,
        ];

        try {
            if ($postId === 0) {
                // Criar novo post
                $newId = $postService->createPost($payload, (int)$currentUser['id']);
                if ($action === 'publish' && Authorization::can($currentUser, Authorization::ACTION_PUBLISH_POST)) {
                    $postService->publishOrUpdate($newId, $payload, (int)$currentUser['id'], 1);
                }
                header("Location: /admin/editor.php?id={$newId}&saved=1");
                exit;
            } else {
                // Atualizar post existente
                if ($action === 'publish') {
                    if (!Authorization::can($currentUser, Authorization::ACTION_PUBLISH_POST)) {
                        throw new RuntimeException("Apenas Editores e Administradores podem publicar.");
                    }
                    $postService->publishOrUpdate($postId, $payload, (int)$currentUser['id'], $knownVersion);
                    $message = "Publicação atualizada e cache invalidado com sucesso!";
                } else {
                    // Salvar rascunho
                    $contentHtml = \LightPress\Core\BlockCompiler::compile($contentJson);
                    Database::query(
                        "UPDATE posts SET title = ?, summary = ?, category = ?, featured_image = ?, content_json = ?, content_html = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                        [$title, $summary, $category, $featuredImage, $contentJson, $contentHtml, $postId]
                    );
                    $message = "Rascunho salvo com sucesso!";
                }
                // Recarregar dados do post
                $post = Database::fetchOne("SELECT * FROM posts WHERE id = ? LIMIT 1", [$postId]);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $message = "Publicação criada com sucesso!";
}

$categories = Database::fetchAll("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY name ASC");
$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $post ? 'Editar: ' . Security::e($post['title']) : 'Nova Publicação' ?> - SpeedPress Admin</title>
    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #cbd5e1;
            --text: #0f172a;
            --text-muted: #64748b;
            --radius: 8px;
            --font: system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); padding-bottom: 5rem; }
        header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.75rem 1.5rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 50; }
        .brand { font-size: 1.25rem; font-weight: 800; color: var(--primary); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1.5rem; }
        .editor-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #334155; }
        input[type="text"], textarea, select { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; font-family: inherit; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .btn { padding: 0.65rem 1.25rem; border-radius: var(--radius); font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--primary-accent); color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-secondary:hover { background: #cbd5e1; }
        .alert-success { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        
        /* Blocos do Editor */
        .blocks-container { border: 1px solid var(--border); border-radius: var(--radius); background: #f8fafc; padding: 1rem; min-height: 250px; }
        .block-item { background: white; border: 1px solid var(--border); border-radius: 6px; padding: 1rem; margin-bottom: 0.75rem; position: relative; }
        .block-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .block-remove { color: #ef4444; cursor: pointer; border: none; background: transparent; font-weight: bold; }
        .toolbar { display: flex; gap: 0.5rem; margin-top: 1rem; }
    </style>
</head>
<body>
    <header>
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span></a>
            <span style="font-size: 0.9rem; color: var(--text-muted);">&larr; <a href="/admin/dashboard.php" style="color: inherit; text-decoration: none;">Voltar ao Dashboard</a></span>
        </div>
        <div>
            <?php if ($post): ?>
                <a href="/admin/revisions.php?post_id=<?= (int)$post['id'] ?>" class="btn btn-secondary" style="margin-right: 0.5rem;">📜 Histórico (#<?= (int)$post['version'] ?>)</a>
                <a href="/admin/preview.php?id=<?= (int)$post['id'] ?>" target="_blank" class="btn btn-secondary" style="margin-right: 0.5rem;">👁️ Prévia Privada</a>
            <?php endif; ?>
            <?php if ($post && $post['status'] === 'published'): ?>
                <a href="/noticia/<?= Security::e($post['slug']) ?>/" target="_blank" class="btn btn-secondary" style="margin-right: 0.5rem;">Ver Artigo no Ar &rarr;</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= Security::e($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="editorForm">
            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="version" value="<?= $post ? (int)$post['version'] : 1 ?>">
            <input type="hidden" name="content_json" id="contentJsonInput" value="<?= Security::e($post['content_json'] ?? '[]') ?>">

            <div class="editor-grid">
                <!-- Coluna Principal -->
                <div>
                    <div class="card">
                        <div class="form-group">
                            <label for="title">Título da Notícia *</label>
                            <input type="text" id="title" name="title" value="<?= Security::e($post['title'] ?? '') ?>" required style="font-size: 1.3rem; font-weight: 700;">
                        </div>

                        <div class="form-group">
                            <label for="summary">Resumo / Subtítulo</label>
                            <textarea id="summary" name="summary" rows="3"><?= Security::e($post['summary'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="card">
                        <label style="margin-bottom: 0.75rem;">Conteúdo da Notícia (Blocos)</label>
                        <div class="blocks-container" id="blocksArea"></div>

                        <div class="toolbar">
                            <button type="button" class="btn btn-secondary" onclick="addBlock('paragraph')">+ Parágrafo</button>
                            <button type="button" class="btn btn-secondary" onclick="addBlock('heading')">+ Subtítulo (H2)</button>
                            <button type="button" class="btn btn-secondary" onclick="addBlock('image')">+ Imagem (URL)</button>
                            <button type="button" class="btn btn-secondary" onclick="addBlock('quote')">+ Citação</button>
                        </div>
                    </div>
                </div>

                <!-- Coluna Lateral -->
                <div>
                    <div class="card">
                        <div style="margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.5rem;">
                            <button type="submit" name="form_action" value="publish" class="btn btn-primary" style="width: 100%;">
                                <?= ($post && $post['status'] === 'published') ? 'Atualizar Publicação' : 'Publicar Agora' ?>
                            </button>
                            <button type="submit" name="form_action" value="save_draft" class="btn btn-secondary" style="width: 100%;">
                                Salvar como Rascunho
                            </button>
                        </div>

                        <div class="form-group">
                            <label for="category">Categoria</label>
                            <select id="category" name="category">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= Security::e($cat['slug']) ?>" <?= ($post && $post['category'] === $cat['slug']) ? 'selected' : '' ?>>
                                        <?= Security::e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="featured_image">Imagem de Destaque (URL)</label>
                            <input type="text" id="featured_image" name="featured_image" value="<?= Security::e($post['featured_image'] ?? '') ?>" placeholder="/uploads/2026/11/hash-1024.webp">
                        </div>

                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_featured" value="1" <?= (!empty($post['is_featured'])) ? 'checked' : '' ?>>
                                Destacar na Página Inicial
                            </label>
                        </div>
                    </div>

                    <div class="card">
                        <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 1rem; color: #334155;">SEO Nativo</h3>
                        <div class="form-group">
                            <label for="seo_title">Título SEO</label>
                            <input type="text" id="seo_title" name="seo_title" value="<?= Security::e($post['seo_title'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="seo_description">Meta Description</label>
                            <textarea id="seo_description" name="seo_description" rows="3"><?= Security::e($post['seo_description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Script do Editor em Vanilla JS Puro (< 50 KiB) -->
    <script>
        var blocks = [];
        var rawJson = document.getElementById('contentJsonInput').value;
        try {
            var parsed = JSON.parse(rawJson);
            blocks = Array.isArray(parsed) ? parsed : (parsed.blocks || []);
        } catch (e) {
            blocks = [];
        }

        if (blocks.length === 0) {
            blocks.push({ type: 'paragraph', content: '' });
        }

        function renderBlocks() {
            var container = document.getElementById('blocksArea');
            container.innerHTML = '';

            blocks.forEach(function(block, idx) {
                var el = document.createElement('div');
                el.className = 'block-item';

                var header = document.createElement('div');
                header.className = 'block-header';
                header.innerHTML = '<span>' + block.type + '</span>';
                
                var delBtn = document.createElement('button');
                delBtn.className = 'block-remove';
                delBtn.innerText = 'Remover';
                delBtn.onclick = function() { removeBlock(idx); };
                header.appendChild(delBtn);
                el.appendChild(header);

                if (block.type === 'heading') {
                    var input = document.createElement('input');
                    input.type = 'text';
                    input.value = block.content || '';
                    input.placeholder = 'Digite o subtítulo...';
                    input.oninput = function(e) { block.content = e.target.value; syncJson(); };
                    el.appendChild(input);
                } else if (block.type === 'paragraph') {
                    var textarea = document.createElement('textarea');
                    textarea.rows = 3;
                    textarea.value = block.content || '';
                    textarea.placeholder = 'Digite o texto do parágrafo...';
                    textarea.oninput = function(e) { block.content = e.target.value; syncJson(); };
                    el.appendChild(textarea);
                } else if (block.type === 'image') {
                    var imgUrl = document.createElement('input');
                    imgUrl.type = 'text';
                    imgUrl.value = block.url || '';
                    imgUrl.placeholder = 'URL da imagem (/uploads/...)';
                    imgUrl.style.marginBottom = '0.5rem';
                    imgUrl.oninput = function(e) { block.url = e.target.value; syncJson(); };
                    el.appendChild(imgUrl);

                    var imgCaption = document.createElement('input');
                    imgCaption.type = 'text';
                    imgCaption.value = block.caption || '';
                    imgCaption.placeholder = 'Legenda da imagem';
                    imgCaption.oninput = function(e) { block.caption = e.target.value; syncJson(); };
                    el.appendChild(imgCaption);
                } else if (block.type === 'quote') {
                    var quoteText = document.createElement('textarea');
                    quoteText.rows = 2;
                    quoteText.value = block.content || '';
                    quoteText.placeholder = 'Texto da citação...';
                    quoteText.oninput = function(e) { block.content = e.target.value; syncJson(); };
                    el.appendChild(quoteText);
                }

                container.appendChild(el);
            });

            syncJson();
        }

        function addBlock(type) {
            blocks.push({ type: type, content: '' });
            renderBlocks();
        }

        function removeBlock(idx) {
            if (blocks.length > 1) {
                blocks.splice(idx, 1);
                renderBlocks();
            }
        }

        function syncJson() {
            document.getElementById('contentJsonInput').value = JSON.stringify(blocks);
        }

        renderBlocks();
    </script>
</body>
</html>
