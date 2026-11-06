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
use LightPress\Core\BlockCompiler;
use LightPress\Core\CacheManager;

$currentUser = Security::requireAuth(null, ['admin', 'editor'], $config);

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
        $featuredImage = !empty($_POST['featured_image']) ? BlockCompiler::sanitizeUrl(trim($_POST['featured_image'])) : null;
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
                    $contentHtml = BlockCompiler::sanitizeHtml($contentHtml);

                    if ($pageId === 0) {
                        // Criar nova página
                        Database::query(
                            "INSERT INTO pages (user_id, slug, title, content_html, featured_image, seo_title, seo_description, status) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [(int)$currentUser['id'], $slug, $title, $contentHtml, $featuredImage ?: null, $seoTitle ?: null, $seoDesc ?: null, $status]
                        );
                        $newId = (int)Database::lastInsertId();

                        // Purga de Cache no Nginx
                        try {
                            $cacheMgr = new CacheManager($config);
                            $cacheMgr->purgeUrls(["/{$slug}/", "/sitemap.xml"]);
                        } catch (\Throwable) {}

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

                        // Purga de Cache no Nginx
                        try {
                            $cacheMgr = new CacheManager($config);
                            $purgeUrls = ["/{$slug}/", "/sitemap.xml"];
                            if (!empty($page['slug']) && $page['slug'] !== $slug) {
                                $purgeUrls[] = "/{$page['slug']}/";
                            }
                            $cacheMgr->purgeUrls($purgeUrls);
                        } catch (\Throwable) {}

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
$pageTitle = !empty($page['id']) ? 'Editar Página' : 'Nova Página';
$activePage = 'pages';
require __DIR__ . '/header.php';
?>
<style>
    body { padding-bottom: 4rem; }
    .editor-grid {
        display: grid;
        grid-template-columns: 2.2fr 1fr;
        gap: 2rem;
    }
    @media (max-width: 900px) {
        .editor-grid { grid-template-columns: 1fr; }
    }
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.75rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        margin-bottom: 1.5rem;
    }
    .form-group { margin-bottom: 1.25rem; }
    .form-group:last-child { margin-bottom: 0; }
    .form-label {
        display: block;
        font-weight: 700;
        font-size: 0.88rem;
        margin-bottom: 0.4rem;
        color: var(--text);
    }
    .form-hint {
        font-size: 0.78rem;
        color: var(--text-muted);
        margin-top: 0.35rem;
    }
    .form-input, .form-textarea, .form-select {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--bg);
        color: var(--text);
        font-size: 0.9rem;
        font-family: inherit;
    }
    .form-input:focus, .form-textarea:focus, .form-select:focus {
        outline: none;
        border-color: var(--primary-accent);
    }
    .title-input {
        font-size: 1.4rem;
        font-weight: 800;
    }
    .content-textarea {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 0.95rem;
        line-height: 1.6;
        min-height: 480px;
        resize: vertical;
    }
</style>

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
            box.replaceChildren();
            if (url && url.trim() !== '') {
                var img = document.createElement('img');
                img.src = url;
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'cover';
                box.appendChild(img);
            } else {
                var span = document.createElement('span');
                span.textContent = 'Sem imagem selecionada';
                box.appendChild(span);
            }
        }
    </script>
<?php require __DIR__ . '/footer.php'; ?>
