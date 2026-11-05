<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Editor de Páginas Institucionais
 * Criação e edição com controle de slug, imagem de destaque, SEO e formatação.
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Core\Schema;

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

if (!in_array($currentUser['role'], ['admin', 'editor'], true)) {
    die("Acesso restrito a Administradores e Editores.");
}

Schema::ensurePagesTable();

$pageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = null;
$message = '';
$error = '';

if ($pageId > 0) {
    $page = Database::fetchOne("SELECT * FROM pages WHERE id = ? LIMIT 1", [$pageId]);
    if (!$page) {
        die("Página não encontrada.");
    }
}

// Processamento do formulário de salvamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_csrf_token'] ?? '';
    if (!Security::verifyCsrfToken($csrf)) {
        $error = "Token CSRF inválido ou expirado. Recarregue e tente novamente.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $contentHtml = trim($_POST['content_html'] ?? '');
        $featuredImage = trim($_POST['featured_image'] ?? '');
        $seoTitle = trim($_POST['seo_title'] ?? '');
        $seoDesc = trim($_POST['seo_description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['published', 'draft', 'archived'], true) ? $_POST['status'] : 'published';

        if (empty($title)) {
            $error = "O título da página é obrigatório.";
        } else {
            if (empty($slug)) {
                $slug = Security::slugify($title);
            } else {
                $slug = Security::slugify($slug);
            }

            // Verificar slug duplicado em pages
            $dupCheckSql = "SELECT id FROM pages WHERE slug = ?";
            $dupParams = [$slug];
            if ($pageId > 0) {
                $dupCheckSql .= " AND id != ?";
                $dupParams[] = $pageId;
            }
            $duplicate = Database::fetchOne($dupCheckSql, $dupParams);

            if ($duplicate) {
                $error = "O slug '{$slug}' já está em uso por outra página.";
            } else {
                // Verificar palavras reservadas do sistema
                $reserved = ['admin', 'api', 'uploads', 'assets', 'storage', 'busca', 'feed', 'sitemap', 'page', 'categoria', 'tag'];
                if (in_array(strtolower($slug), $reserved, true)) {
                    $error = "O slug '{$slug}' é reservado pelo sistema. Por favor, escolha outro.";
                } else {
                    if ($pageId === 0) {
                        // Criar nova página
                        Database::query(
                            "INSERT INTO pages (user_id, slug, title, content_html, featured_image, seo_title, seo_description, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [(int)$currentUser['id'], $slug, $title, $contentHtml, $featuredImage ?: null, $seoTitle ?: null, $seoDesc ?: null, $status]
                        );
                        $newId = (int)Database::lastInsertId();
                        header("Location: /admin/page-edit.php?id={$newId}&saved=1");
                        exit;
                    } else {
                        // Atualizar página existente
                        Database::query(
                            "UPDATE pages 
                             SET slug = ?, title = ?, content_html = ?, featured_image = ?, seo_title = ?, seo_description = ?, status = ? 
                             WHERE id = ?",
                            [$slug, $title, $contentHtml, $featuredImage ?: null, $seoTitle ?: null, $seoDesc ?: null, $status, $pageId]
                        );
                        header("Location: /admin/page-edit.php?id={$pageId}&saved=1");
                        exit;
                    }
                }
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $message = "Página salva com sucesso!";
}

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page ? 'Editar Página: ' . Security::e($page['title']) : 'Nova Página' ?> - SpeedPress Admin</title>

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
            --primary-accent: #E10600;
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
            --primary-accent: #E10600;
            --bg: #090d16;
            --surface: #111827;
            --border: #1f2937;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); padding-bottom: 4rem; }
        .site-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0.85rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand { font-size: 1.15rem; font-weight: 800; color: var(--text); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; font-weight: 600; }
        .nav-links a { color: var(--text-muted); text-decoration: none; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--text); }
        .user-menu { display: flex; align-items: center; gap: 1.25rem; font-size: 0.88rem; }
        .theme-btn {
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 0.35rem 0.65rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.95rem;
        }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; }

        .editor-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .editor-grid { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .form-group { margin-bottom: 1.25rem; }
        .form-label { display: block; font-weight: 700; font-size: 0.85rem; margin-bottom: 0.4rem; color: var(--text); }
        .form-input, .form-textarea, .form-select {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
            font-size: 0.92rem;
            font-family: inherit;
        }
        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-accent);
        }
        .title-input {
            font-size: 1.5rem;
            font-weight: 800;
            padding: 0.75rem 1rem;
        }

        /* Toolbar de Edição Rica */
        .editor-toolbar {
            display: flex;
            gap: 0.35rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-bottom: none;
            border-top-left-radius: var(--radius);
            border-top-right-radius: var(--radius);
            padding: 0.5rem;
            flex-wrap: wrap;
        }
        .tb-btn {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 0.35rem 0.65rem;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            color: var(--text);
        }
        .tb-btn:hover { background: var(--border); }
        .content-textarea {
            width: 100%;
            min-height: 420px;
            padding: 1rem;
            border: 1px solid var(--border);
            border-bottom-left-radius: var(--radius);
            border-bottom-right-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.95rem;
            line-height: 1.6;
            resize: vertical;
        }

        .btn-primary {
            width: 100%;
            background: var(--primary-accent);
            color: white;
            border: none;
            padding: 0.85rem;
            border-radius: var(--radius);
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: opacity 0.15s;
        }
        .btn-primary:hover { opacity: 0.9; }

        .btn-secondary {
            display: inline-block;
            text-align: center;
            width: 100%;
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
            padding: 0.65rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.88rem;
            text-decoration: none;
            margin-top: 0.5rem;
        }
        .btn-secondary:hover { background: var(--bg); }

        .alert-success { background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <header class="site-header">
        <div style="display: flex; align-items: center; gap: 2.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Redação</a>
            <nav class="nav-links">
                <a href="/admin/dashboard.php">Notícias</a>
                <a href="/admin/pages.php" class="active">Páginas</a>
                <a href="/admin/media.php">Mídias</a>
                <a href="/admin/categories.php">Categorias</a>
                <a href="/admin/stats.php">Mais Lidas 📈</a>
                <a href="/" target="_blank" style="color: var(--primary-accent);">Ver Site &rarr;</a>
            </nav>
        </div>
        <div class="user-menu">
            <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" id="btnThemeToggle">🌙</button>
            <a href="/admin/pages.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">&larr; Voltar às Páginas</a>
        </div>
    </header>

    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= Security::e($message) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/page-edit.php<?= $pageId > 0 ? '?id=' . $pageId : '' ?>">
            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">

            <div class="editor-grid">
                <!-- Coluna Principal (Conteúdo) -->
                <div>
                    <div class="card">
                        <div class="form-group">
                            <label class="form-label" for="page_title">Título da Página</label>
                            <input type="text" id="page_title" name="title" value="<?= Security::e($page['title'] ?? '') ?>" placeholder="Ex: Quem Somos, Nossa Equipe, Contato..." class="form-input title-input" required oninput="autoSlug(this.value)">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="page_slug">Link Permanente (Slug)</label>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="color: var(--text-muted); font-size: 0.88rem; font-family: monospace;">https://dominio.com/</span>
                                <input type="text" id="page_slug" name="slug" value="<?= Security::e($page['slug'] ?? '') ?>" placeholder="equipe" class="form-input" style="font-family: monospace;">
                                <span style="color: var(--text-muted); font-size: 0.88rem; font-family: monospace;">/</span>
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Conteúdo da Página (HTML / Texto Formatado)</label>
                            <div class="editor-toolbar">
                                <button type="button" class="tb-btn" onclick="insertTag('<h2>', '</h2>')">H2</button>
                                <button type="button" class="tb-btn" onclick="insertTag('<h3>', '</h3>')">H3</button>
                                <button type="button" class="tb-btn" onclick="insertTag('<strong>', '</strong>')">Negrito</button>
                                <button type="button" class="tb-btn" onclick="insertTag('<em>', '</em>')">Itálico</button>
                                <button type="button" class="tb-btn" onclick="insertTag('<p>', '</p>')">Parágrafo</button>
                                <button type="button" class="tb-btn" onclick="insertTag('<ul>\n  <li>', '</li>\n</ul>')">Lista</button>
                                <button type="button" class="tb-btn" onclick="insertTag('<blockquote>', '</blockquote>')">Citação</button>
                                <button type="button" class="tb-btn" onclick="insertLink()">Link</button>
                            </div>
                            <textarea id="content_html" name="content_html" class="content-textarea" placeholder="Digite ou cole aqui o conteúdo da página..."><?= htmlspecialchars($page['content_html'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>

                    <!-- Bloco de SEO -->
                    <div class="card">
                        <h3 style="font-size: 1.1rem; margin-bottom: 1rem;">Otimização para Mecanismos de Busca (SEO)</h3>
                        <div class="form-group">
                            <label class="form-label" for="seo_title">Título SEO (Meta Title)</label>
                            <input type="text" id="seo_title" name="seo_title" value="<?= Security::e($page['seo_title'] ?? '') ?>" placeholder="Título exibido no Google e no topo da aba" class="form-input">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="seo_description">Descrição SEO (Meta Description)</label>
                            <textarea id="seo_description" name="seo_description" rows="3" placeholder="Resumo atrativo de até 160 caracteres para os buscadores..." class="form-textarea"><?= Security::e($page['seo_description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Coluna Lateral (Configurações & Publicação) -->
                <div>
                    <div class="card">
                        <h3 style="font-size: 1.1rem; margin-bottom: 1.25rem;">Publicação</h3>
                        
                        <div class="form-group">
                            <label class="form-label" for="page_status">Status da Página</label>
                            <select id="page_status" name="status" class="form-select">
                                <option value="published" <?= ($page['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Publicada (Visível ao público)</option>
                                <option value="draft" <?= ($page['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Rascunho (Privada)</option>
                                <option value="archived" <?= ($page['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Arquivada</option>
                            </select>
                        </div>

                        <?php if ($page): ?>
                            <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.25rem; line-height: 1.5;">
                                <div><strong>ID:</strong> #<?= (int)$page['id'] ?></div>
                                <div><strong>Visualizações:</strong> <?= number_format((int)$page['views'], 0, ',', '.') ?></div>
                                <div><strong>Criada em:</strong> <?= date('d/m/Y H:i', strtotime($page['created_at'])) ?></div>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="btn-primary">
                            <?= $pageId > 0 ? 'Salvar Alterações' : 'Salvar e Publicar' ?>
                        </button>

                        <?php if ($page && !empty($page['slug'])): ?>
                            <a href="/<?= Security::e($page['slug']) ?>/" target="_blank" class="btn-secondary">
                                Ver Página no Site &rarr;
                            </a>
                        <?php endif; ?>
                        <a href="/admin/pages.php" class="btn-secondary" style="border: none; color: var(--text-muted);">
                            Cancelar
                        </a>
                    </div>

                    <!-- Imagem de Destaque -->
                    <div class="card">
                        <h3 style="font-size: 1.1rem; margin-bottom: 1rem;">Imagem de Destaque</h3>
                        <div class="form-group">
                            <label class="form-label" for="featured_image">URL da Imagem</label>
                            <input type="text" id="featured_image" name="featured_image" value="<?= Security::e($page['featured_image'] ?? '') ?>" placeholder="/uploads/2026/11/imagem.avif" class="form-input" onchange="previewImage(this.value)">
                        </div>

                        <div id="image_preview" style="width: 100%; aspect-ratio: 16/9; background: var(--bg); border: 1px dashed var(--border); border-radius: var(--radius); overflow: hidden; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 0.85rem;">
                            <?php if (!empty($page['featured_image'])): ?>
                                <img src="<?= Security::e($page['featured_image']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <span>Sem imagem selecionada</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('lp_theme', next);
            updateThemeButton(next);
        }
        function updateThemeButton(theme) {
            var btn = document.getElementById('btnThemeToggle');
            if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
        }
        updateThemeButton(document.documentElement.getAttribute('data-theme'));

        var slugManuallyEdited = false;
        document.getElementById('page_slug').addEventListener('input', function() {
            slugManuallyEdited = true;
        });

        function autoSlug(val) {
            if (slugManuallyEdited) return;
            var slug = val.toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            document.getElementById('page_slug').value = slug;
        }

        function insertTag(openTag, closeTag) {
            var txt = document.getElementById('content_html');
            var start = txt.selectionStart;
            var end = txt.selectionEnd;
            var selected = txt.value.substring(start, end);
            var replacement = openTag + selected + closeTag;
            txt.value = txt.value.substring(0, start) + replacement + txt.value.substring(end);
            txt.focus();
            txt.selectionStart = start + openTag.length;
            txt.selectionEnd = start + openTag.length + selected.length;
        }

        function insertLink() {
            var url = prompt('Digite a URL do link (ex: https://...):', 'https://');
            if (url) {
                insertTag('<a href="' + url + '">', '</a>');
            }
        }

        function previewImage(url) {
            var box = document.getElementById('image_preview');
            if (url && url.trim() !== '') {
                box.innerHTML = '<img src="' + url + '" style="width: 100%; height: 100%; object-fit: cover;">';
            } else {
                box.innerHTML = '<span>Sem imagem selecionada</span>';
            }
        }
    </script>
</body>
</html>
