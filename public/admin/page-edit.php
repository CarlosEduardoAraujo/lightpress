<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Editor de Páginas Institucionais com Suporte a Rich Text / Docs / Word
 * e Modal de Seleção de Mídias Estilo WordPress
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
        $action = $_POST['form_action'] ?? 'publish';
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $contentHtml = trim($_POST['content_html'] ?? '');
        $featuredImage = !empty($_POST['featured_image']) ? BlockCompiler::sanitizeUrl(trim($_POST['featured_image'])) : null;
        $seoTitle = trim($_POST['seo_title'] ?? '');
        $seoDesc = trim($_POST['seo_description'] ?? '');
        
        $status = in_array($_POST['status'] ?? '', ['published', 'draft', 'archived'], true) ? $_POST['status'] : 'published';
        if ($action === 'save_draft') {
            $status = 'draft';
        } elseif ($action === 'publish' && $status === 'draft') {
            $status = 'published';
        }

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
    body { padding-bottom: 5rem; }

    [data-theme="dark"] .editor-toolbar { background: #1a233a; border-color: #1e293b; }
    [data-theme="dark"] .tool-btn { background: #131b2e; color: #f1f5f9; border-color: #1e293b; }
    [data-theme="dark"] .tool-btn:hover { background: #1e293b; }
    [data-theme="dark"] .editor-canvas { background: #131b2e; color: #f1f5f9; border-color: #1e293b; }
    [data-theme="dark"] .editor-canvas h2, [data-theme="dark"] .editor-canvas h3 { color: #f1f5f9; }
    [data-theme="dark"] .editor-canvas blockquote { background: #1a233a; color: #cbd5e1; }
    [data-theme="dark"] input, [data-theme="dark"] textarea, [data-theme="dark"] select { background: #131b2e; color: #f1f5f9; border-color: #1e293b; }
    [data-theme="dark"] label { color: #f1f5f9; }
    [data-theme="dark"] .btn-secondary { background: #1e293b; color: #f1f5f9; border: 1px solid #334155; }
    [data-theme="dark"] .btn-secondary:hover { background: #334155; }
    [data-theme="dark"] .media-modal-dialog { background: #131b2e; color: #f1f5f9; }
    [data-theme="dark"] .media-modal-header, [data-theme="dark"] .media-modal-footer { background: #090d16; border-color: #1e293b; }
    [data-theme="dark"] .media-modal-header h2 { color: #f1f5f9; }
    [data-theme="dark"] .modal-media-item { background: #131b2e; border-color: #1e293b; }
    [data-theme="dark"] .modal-media-title { color: #f1f5f9; }

    .editor-grid { display: grid; grid-template-columns: 2.2fr 1fr; gap: 2rem; }
    @media (max-width: 1024px) {
        .editor-grid { grid-template-columns: 1fr; }
    }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; transition: background 0.2s, border-color 0.2s; }
    .form-group { margin-bottom: 1.25rem; }
    label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #334155; }
    input[type="text"], textarea, select { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; font-family: inherit; }
    input:focus, textarea:focus, select:focus { outline: none; border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(225, 6, 0, 0.15); }
    .title-input { font-size: 1.4rem; font-weight: 800; }

    .btn-primary { background: var(--primary-accent); color: white; }
    .btn-primary:hover { background: var(--primary-hover); }
    .btn-secondary { background: #e2e8f0; color: #334155; }
    .btn-secondary:hover { background: #cbd5e1; }
    .alert-success { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
    .alert-error { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }

    /* Barra de Ferramentas Rica */
    .editor-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        padding: 0.5rem;
        background: #f1f5f9;
        border: 1px solid var(--border);
        border-bottom: none;
        border-radius: var(--radius) var(--radius) 0 0;
        position: sticky;
        top: 0px;
        z-index: 40;
    }
    .tool-btn {
        background: white;
        border: 1px solid var(--border);
        padding: 0.4rem 0.65rem;
        border-radius: 4px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #334155;
        cursor: pointer;
        line-height: 1;
    }
    .tool-btn:hover { background: #e2e8f0; color: var(--primary); }
    .tool-separator { width: 1px; background: var(--border); margin: 0 0.2rem; }

    /* Canvas de Escrita Visual */
    .editor-canvas {
        min-height: 480px;
        padding: 1.5rem;
        background: white;
        border: 1px solid var(--border);
        border-radius: 0 0 var(--radius) var(--radius);
        font-family: inherit;
        font-size: 1.05rem;
        line-height: 1.7;
        color: #1e293b;
        outline: none;
    }
    .editor-canvas:focus {
        border-color: var(--primary-accent);
        box-shadow: 0 0 0 3px rgba(225, 6, 0, 0.1);
    }
    .editor-canvas p { margin-bottom: 1.25rem; }
    .editor-canvas h2 { font-size: 1.5rem; font-weight: 800; margin: 1.75rem 0 0.75rem; color: #0f172a; }
    .editor-canvas h3 { font-size: 1.25rem; font-weight: 700; margin: 1.5rem 0 0.5rem; color: #1e293b; }
    .editor-canvas blockquote { border-left: 4px solid var(--primary-accent); padding: 0.5rem 0 0.5rem 1rem; margin: 1.25rem 0; color: #475569; font-style: italic; background: #f8fafc; }
    .editor-canvas ul, .editor-canvas ol { margin: 1rem 0 1.25rem 1.75rem; }
    .editor-canvas li { margin-bottom: 0.4rem; }
    .editor-canvas img { max-width: 100%; height: auto; border-radius: var(--radius); margin: 1rem 0; display: block; }
    .editor-canvas figure { margin: 1.5rem 0; }
    .editor-canvas figcaption { font-size: 0.85rem; color: var(--text-muted); text-align: center; margin-top: 0.4rem; font-style: italic; }

    /* Modal de Mídias (WordPress-Style) */
    .media-modal { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; }
    .media-modal-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(2px); }
    .media-modal-dialog { position: relative; width: 92%; max-width: 950px; max-height: 88vh; background: white; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }
    .media-modal-header { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
    .media-modal-header h2 { font-size: 1.25rem; font-weight: 800; color: #0f172a; }
    .media-modal-close { background: transparent; border: none; font-size: 1.5rem; font-weight: 700; color: var(--text-muted); cursor: pointer; line-height: 1; }
    .media-modal-close:hover { color: #ef4444; }
    .media-modal-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex-grow: 1; min-height: 350px; }
    .media-modal-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.25rem; }
    .media-modal-toolbar input { width: 60%; }
    .media-modal-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 1rem; }
    .modal-media-item { border: 2px solid transparent; border-radius: var(--radius); overflow: hidden; cursor: pointer; background: #f8fafc; display: flex; flex-direction: column; transition: all 0.15s ease; position: relative; }
    .modal-media-item:hover { border-color: #fca5a5; transform: translateY(-2px); }
    .modal-media-item.selected { border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(225, 6, 0, 0.25); }
    .modal-media-thumb { width: 100%; aspect-ratio: 16/9; object-fit: cover; background: #e2e8f0; }
    .modal-media-title { padding: 0.5rem; font-size: 0.72rem; font-weight: 600; line-height: 1.3; word-break: break-all; color: #334155; }
    .media-modal-footer { padding: 0.85rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
</style>

    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= Security::e($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="editorForm" onsubmit="return handleFormSubmit()">
            <input type="hidden" name="_csrf_token" id="csrfToken" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="content_html" id="contentHtmlInput" value="">

            <div class="editor-grid">
                <!-- Coluna Principal (Texto e Títulos) -->
                <div>
                    <div class="card">
                        <div class="form-group">
                            <label for="title">Título da Página *</label>
                            <input type="text" id="title" name="title" value="<?= Security::e($page['title'] ?? '') ?>" required style="font-size: 1.35rem; font-weight: 800;" placeholder="Ex: Quem Somos, Termos de Uso, Política de Privacidade...">
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.35rem;">
                                <label for="slug" style="margin-bottom: 0;">Slug / URL Permanente *</label>
                                <span style="font-size: 0.78rem; color: var(--text-muted);">
                                    Link: <strong>/<span id="slugPreviewText"><?= Security::e($page['slug'] ?? 'url-da-pagina') ?></span>/</strong>
                                </span>
                            </div>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span style="font-size: 0.85rem; color: var(--text-muted); font-family: monospace; white-space: nowrap;">/</span>
                                <input type="text" id="slug" name="slug" value="<?= Security::e($page['slug'] ?? '') ?>" placeholder="ex: quem-somos" style="font-family: monospace; font-size: 0.9rem;" required>
                                <button type="button" class="btn btn-secondary" onclick="generateSlugFromTitle(true)" title="Gerar automaticamente com base no título" style="padding: 0.65rem 0.9rem; font-size: 0.82rem; white-space: nowrap;">
                                    Auto 🪄
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="card" style="padding: 0; overflow: hidden;">
                        <!-- Barra de Ferramentas Rica -->
                        <div class="editor-toolbar">
                            <button type="button" class="tool-btn" onclick="execCmd('formatBlock', '<h2>')" title="Título de Seção (H2)">H2</button>
                            <button type="button" class="tool-btn" onclick="execCmd('formatBlock', '<h3>')" title="Intertítulo (H3)">H3</button>
                            <button type="button" class="tool-btn" onclick="execCmd('formatBlock', '<p>')" title="Parágrafo Normal">P</button>
                            <div class="tool-separator"></div>
                            <button type="button" class="tool-btn" onclick="execCmd('bold')" title="Negrito (Ctrl+B)"><b>B</b></button>
                            <button type="button" class="tool-btn" onclick="execCmd('italic')" title="Itálico (Ctrl+I)"><i>I</i></button>
                            <button type="button" class="tool-btn" onclick="execCmd('underline')" title="Sublinhado"><u>U</u></button>
                            <div class="tool-separator"></div>
                            <button type="button" class="tool-btn" onclick="execCmd('insertUnorderedList')" title="Lista com Marcadores">• Lista</button>
                            <button type="button" class="tool-btn" onclick="execCmd('insertOrderedList')" title="Lista Numerada">1. Lista</button>
                            <button type="button" class="tool-btn" onclick="execCmd('formatBlock', '<blockquote>')" title="Citação em Destaque">❝ Citação</button>
                            <div class="tool-separator"></div>
                            <button type="button" class="tool-btn" onclick="promptLink()" title="Inserir Link">🔗 Link</button>
                            <button type="button" class="tool-btn" onclick="openMediaModal('content')" style="background: #e0f2fe; color: #0369a1;" title="Inserir Foto da Biblioteca">📷 Inserir Foto</button>
                            <div class="tool-separator"></div>
                            <button type="button" class="tool-btn" onclick="execCmd('removeFormat')" title="Limpar Formatação">✕ Limpar</button>
                            <button type="button" class="tool-btn" onclick="toggleHtmlSource()" id="btnToggleSource" style="margin-left: auto;" title="Alternar entre Visual e Código HTML">&lt;/&gt; HTML</button>
                        </div>

                        <!-- Canvas Visual com Suporte a Colar Direto do Docs / Word -->
                        <div class="editor-canvas" id="editorCanvas" contenteditable="true" spellcheck="true" placeholder="Cole ou digite aqui o conteúdo institucional da página (suporta Ctrl+V direto do Google Docs, Word ou web)..."><?= $page['content_html'] ?? '' ?></div>
                        
                        <!-- Textarea de Fallback / Código HTML Direto -->
                        <textarea id="rawHtmlArea" style="display: none; min-height: 480px; font-family: monospace; font-size: 0.9rem; line-height: 1.5; padding: 1.25rem; border: none; border-radius: 0; outline: none; resize: vertical;"><?= Security::e($page['content_html'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Coluna Lateral (Metadados, Publicação e SEO) -->
                <div>
                    <div class="card">
                        <?php if ($page): ?>
                            <?php if ($page['status'] === 'published'): ?>
                                <div style="background: #dcfce7; color: #15803d; padding: 0.5rem 0.75rem; border-radius: var(--radius); font-weight: 700; font-size: 0.82rem; margin-bottom: 1rem; text-align: center; border: 1px solid #bbf7d0;">
                                    <span>✓ Publicada no Ar</span>
                                    <a href="/<?= Security::e($page['slug']) ?>/" target="_blank" style="color: #15803d; font-weight: 800; text-decoration: underline; margin-left: 0.5rem;">Ver ↗</a>
                                </div>
                            <?php elseif ($page['status'] === 'archived'): ?>
                                <div style="background: #fee2e2; color: #991b1b; padding: 0.5rem 0.75rem; border-radius: var(--radius); font-weight: 700; font-size: 0.82rem; margin-bottom: 1rem; text-align: center; border: 1px solid #f87171;">
                                    📦 Arquivada
                                </div>
                            <?php else: ?>
                                <div style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted); padding: 0.5rem 0.75rem; border-radius: var(--radius); font-weight: 700; font-size: 0.82rem; margin-bottom: 1rem; text-align: center; border: 1px solid var(--border);">
                                    📝 Modo Rascunho
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div style="margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.5rem;">
                            <button type="submit" name="form_action" value="publish" id="btnPublishAction" class="btn btn-primary" style="width: 100%; font-size: 1rem; padding: 0.85rem;">
                                <?= ($page && $page['status'] === 'published') ? 'Atualizar Página' : '🚀 Publicar Página' ?>
                            </button>
                            <button type="submit" name="form_action" value="save_draft" class="btn btn-secondary" style="width: 100%;">
                                💾 Salvar como Rascunho
                            </button>
                        </div>

                        <div class="form-group">
                            <label for="status">Status da Página</label>
                            <select id="status" name="status">
                                <option value="published" <?= ($page['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Publicada (Visível ao público)</option>
                                <option value="draft" <?= ($page['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Rascunho (Privada)</option>
                                <option value="archived" <?= ($page['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Arquivada</option>
                            </select>
                        </div>

                        <?php if ($page): ?>
                            <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0; line-height: 1.6; border-top: 1px solid var(--border); padding-top: 1rem;">
                                <div><strong>ID:</strong> #<?= (int)$page['id'] ?></div>
                                <div><strong>Visualizações:</strong> <?= number_format((int)$page['views'], 0, ',', '.') ?></div>
                                <div><strong>Criada em:</strong> <?= date('d/m/Y H:i', strtotime($page['created_at'])) ?></div>
                                <?php if (!empty($page['updated_at'])): ?>
                                    <div><strong>Atualizada em:</strong> <?= date('d/m/Y H:i', strtotime($page['updated_at'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Imagem de Destaque com Modal Seletor Estilo WordPress -->
                    <div class="card">
                        <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 1rem; color: #334155;">Imagem de Destaque</h3>
                        <input type="hidden" id="featured_image" name="featured_image" value="<?= Security::e($page['featured_image'] ?? '') ?>">
                        
                        <div id="featuredPreviewBox" style="<?= empty($page['featured_image']) ? 'display: none;' : '' ?> margin-bottom: 0.75rem;">
                            <div style="position: relative; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); background: #f1f5f9;">
                                <img id="featuredPreviewImg" src="<?= Security::e($page['featured_image'] ?? '') ?>" style="width: 100%; aspect-ratio: 16/9; object-fit: cover; display: block;">
                                <button type="button" onclick="removeFeaturedImage()" style="position: absolute; top: 8px; right: 8px; background: rgba(220,38,38,0.9); color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                    ✕ Remover Imagem
                                </button>
                            </div>
                        </div>

                        <button type="button" class="btn btn-secondary" onclick="openMediaModal('featured')" style="width: 100%;">
                            <span>📷</span> Selecionar da Biblioteca
                        </button>
                    </div>

                    <!-- Bloco de SEO Completo -->
                    <div class="card">
                        <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 1rem; color: #334155;">SEO Nativo</h3>
                        
                        <div class="form-group">
                            <label for="seo_title">Título SEO (Tag Title)</label>
                            <input type="text" id="seo_title" name="seo_title" value="<?= Security::e($page['seo_title'] ?? '') ?>" placeholder="Título nos motores de busca (Google)">
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="seo_description">Meta Description</label>
                            <textarea id="seo_description" name="seo_description" rows="3" placeholder="Resumo de até 160 caracteres exibido na página de resultados..."><?= Security::e($page['seo_description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Modal de Biblioteca de Mídias (WordPress-Style) -->
    <div id="mediaModal" class="media-modal" style="display: none;">
        <div class="media-modal-backdrop" onclick="closeMediaModal()"></div>
        <div class="media-modal-dialog">
            <div class="media-modal-header">
                <h2 id="modalHeaderTitle">Biblioteca de Mídias</h2>
                <button type="button" class="media-modal-close" onclick="closeMediaModal()">&times;</button>
            </div>
            
            <div class="media-modal-body">
                <div class="media-modal-toolbar">
                    <input type="text" id="modalSearchInput" placeholder="🔍 Filtrar fotos por nome..." oninput="filterMediaItems(this.value)">
                    <label class="btn btn-secondary" style="margin: 0; cursor: pointer; white-space: nowrap;">
                        + Enviar Nova Foto
                        <input type="file" id="modalUploadInput" accept="image/jpeg,image/png,image/webp,image/avif,.jpg,.jpeg,.png,.webp,.avif" style="display: none;" onchange="uploadFromModal(this)">
                    </label>
                </div>

                <div id="modalLoading" style="display: none; text-align: center; padding: 3rem; color: var(--text-muted);">
                    Carregando fotos da biblioteca...
                </div>

                <div class="media-modal-grid" id="modalMediaGrid"></div>
            </div>

            <div class="media-modal-footer">
                <div id="modalSelectionDetails" style="font-size: 0.85rem; color: #475569;">
                    Nenhuma imagem selecionada.
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeMediaModal()">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnApplySelection" disabled onclick="applyMediaSelection()">
                        Confirmar Seleção
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        var editorCanvas = document.getElementById('editorCanvas');
        var rawHtmlArea = document.getElementById('rawHtmlArea');
        var isSourceMode = false;

        function execCmd(command, val) {
            if (isSourceMode) return;
            document.execCommand(command, false, val || null);
            editorCanvas.focus();
        }

        function promptLink() {
            if (isSourceMode) return;
            var url = prompt('Digite a URL do link (ex: https://site.com.br):', 'https://');
            if (url && url !== 'https://') {
                execCmd('createLink', url);
            }
        }

        function toggleHtmlSource() {
            var btn = document.getElementById('btnToggleSource');
            if (!isSourceMode) {
                rawHtmlArea.value = editorCanvas.innerHTML;
                editorCanvas.style.display = 'none';
                rawHtmlArea.style.display = 'block';
                btn.innerText = 'Visual';
                btn.style.background = '#0f172a';
                btn.style.color = '#ffffff';
                isSourceMode = true;
            } else {
                editorCanvas.innerHTML = rawHtmlArea.value;
                rawHtmlArea.style.display = 'none';
                editorCanvas.style.display = 'block';
                btn.innerText = '</> HTML';
                btn.style.background = 'white';
                btn.style.color = '#334155';
                isSourceMode = false;
            }
        }

        // -------------------------------------------------------------
        // Limpador Inteligente de Ctrl+V (Google Docs / MS Word)
        // -------------------------------------------------------------
        editorCanvas.addEventListener('paste', function(e) {
            e.preventDefault();
            var clipboardData = e.clipboardData || window.clipboardData;
            if (!clipboardData) return;

            var html = clipboardData.getData('text/html');
            var text = clipboardData.getData('text/plain');

            if (html && html.trim() !== '') {
                try {
                    var cleanHtml = cleanWordOrDocsHtml(html);
                    if (cleanHtml && cleanHtml.trim() !== '') {
                        document.execCommand('insertHTML', false, cleanHtml);
                        return;
                    }
                } catch (err) {
                    console.error('Erro ao processar HTML colado:', err);
                }
            }
            
            if (text && text.trim() !== '') {
                var paragraphs = text.split(/\r?\n\r?\n/).map(function(p) {
                    var trimmed = p.trim();
                    return trimmed ? '<p>' + escapeHtml(trimmed) + '</p>' : '';
                }).join('');
                document.execCommand('insertHTML', false, paragraphs || escapeHtml(text));
            }
        });

        function cleanWordOrDocsHtml(html) {
            if (!html || typeof html !== 'string') return '';

            // 1. Pré-limpeza de comentários condicionais e tags proprietárias de XML/Office
            html = html.replace(/<!--[\s\S]*?-->/g, '');
            html = html.replace(/<xml[\s\S]*?<\/xml>/gi, '');
            html = html.replace(/<style[\s\S]*?<\/style>/gi, '');
            html = html.replace(/<script[\s\S]*?<\/script>/gi, '');

            var doc = new DOMParser().parseFromString(html, 'text/html');
            if (!doc || !doc.body) return '';

            // 2. Remover tags indesejadas remanescentes
            var badTags = doc.querySelectorAll('script, style, meta, link, o\\:p, xml, font');
            badTags.forEach(function(el) { el.remove(); });

            var allowedTags = ['P', 'H2', 'H3', 'H4', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'A', 'IMG', 'FIGURE', 'FIGCAPTION', 'BR', 'HR'];

            function cleanNode(node) {
                if (!node) return;

                if (node.nodeType === Node.COMMENT_NODE) {
                    if (node.parentNode) node.parentNode.removeChild(node);
                    return;
                }

                if (node.nodeType === Node.ELEMENT_NODE) {
                    var tag = node.tagName.toUpperCase();

                    // Nunca desestruturar ou remover o BODY ou HTML
                    if (tag === 'BODY' || tag === 'HTML') {
                        var bodyChildren = Array.from(node.childNodes);
                        bodyChildren.forEach(cleanNode);
                        return;
                    }

                    // Se for H1, converte para H2 (hierarquia de página)
                    if (tag === 'H1') {
                        var h2 = doc.createElement('h2');
                        while (node.firstChild) h2.appendChild(node.firstChild);
                        if (node.parentNode) node.parentNode.replaceChild(h2, node);
                        node = h2;
                        tag = 'H2';
                    } else if (tag === 'H5' || tag === 'H6') {
                        var h3 = doc.createElement('h3');
                        while (node.firstChild) h3.appendChild(node.firstChild);
                        if (node.parentNode) node.parentNode.replaceChild(h3, node);
                        node = h3;
                        tag = 'H3';
                    }

                    // Se for DIV, SECTION, ARTICLE ou MAIN
                    if (tag === 'DIV' || tag === 'SECTION' || tag === 'ARTICLE' || tag === 'MAIN') {
                        var hasBlock = node.querySelector('p, h1, h2, h3, h4, h5, h6, ul, ol, blockquote, div');
                        if (hasBlock) {
                            var parentDiv = node.parentNode;
                            if (parentDiv) {
                                while (node.firstChild) {
                                    var childDiv = node.firstChild;
                                    parentDiv.insertBefore(childDiv, node);
                                    cleanNode(childDiv);
                                }
                                parentDiv.removeChild(node);
                            }
                            return;
                        } else {
                            var p = doc.createElement('p');
                            while (node.firstChild) p.appendChild(node.firstChild);
                            if (node.parentNode) node.parentNode.replaceChild(p, node);
                            node = p;
                            tag = 'P';
                        }
                    }

                    // Wrapper padrão do Google Docs (<b id="docs-internal-guid-...">)
                    if ((tag === 'B' || tag === 'STRONG') && (node.querySelector('p, h1, h2, h3, h4, ul, ol, div') || (node.id && node.id.indexOf('docs-internal-guid') !== -1))) {
                        var parentB = node.parentNode;
                        if (parentB) {
                            while (node.firstChild) {
                                var childB = node.firstChild;
                                parentB.insertBefore(childB, node);
                                cleanNode(childB);
                            }
                            parentB.removeChild(node);
                        }
                        return;
                    }

                    // Mapeamento semântico
                    if (tag === 'B') {
                        var strong = doc.createElement('strong');
                        while (node.firstChild) strong.appendChild(node.firstChild);
                        if (node.parentNode) node.parentNode.replaceChild(strong, node);
                        node = strong;
                        tag = 'STRONG';
                    } else if (tag === 'I') {
                        var em = doc.createElement('em');
                        while (node.firstChild) em.appendChild(node.firstChild);
                        if (node.parentNode) node.parentNode.replaceChild(em, node);
                        node = em;
                        tag = 'EM';
                    }

                    if (!allowedTags.includes(tag)) {
                        var parent = node.parentNode;
                        if (parent) {
                            while (node.firstChild) {
                                var child = node.firstChild;
                                parent.insertBefore(child, node);
                                cleanNode(child);
                            }
                            parent.removeChild(node);
                        }
                        return;
                    }

                    // Remover estilos sujos, classes de Word, IDs e eventos on*
                    var attrs = Array.from(node.attributes);
                    attrs.forEach(function(attr) {
                        var name = attr.name.toLowerCase();
                        if (name.startsWith('on') || name === 'style' || name === 'class' || name === 'id' || name === 'dir' || name === 'lang') {
                            node.removeAttribute(attr.name);
                        }
                    });

                    // Limpar e blindar links
                    if (tag === 'A') {
                        node.setAttribute('target', '_blank');
                        node.setAttribute('rel', 'noopener noreferrer');
                    }
                }

                var children = Array.from(node.childNodes);
                children.forEach(cleanNode);
            }

            cleanNode(doc.body);
            return doc.body ? doc.body.innerHTML : '';
        }

        function escapeHtml(text) {
            return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // -------------------------------------------------------------
        // Gerenciamento e Sincronização do Slug
        // -------------------------------------------------------------
        var titleInput = document.getElementById('title');
        var slugInput = document.getElementById('slug');
        var slugPreviewText = document.getElementById('slugPreviewText');
        var userEditedSlug = <?= (!empty($page['slug'])) ? 'true' : 'false' ?>;

        function slugifyJs(text) {
            return text.toString().toLowerCase().trim()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/[\s_]+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        if (slugInput) {
            slugInput.addEventListener('input', function() {
                userEditedSlug = true;
                if (slugPreviewText) {
                    slugPreviewText.innerText = slugifyJs(slugInput.value) || 'url-da-pagina';
                }
            });

            slugInput.addEventListener('blur', function() {
                slugInput.value = slugifyJs(slugInput.value);
                if (slugPreviewText) {
                    slugPreviewText.innerText = slugInput.value || 'url-da-pagina';
                }
            });
        }

        if (titleInput) {
            titleInput.addEventListener('input', function() {
                if (!userEditedSlug || !slugInput.value.trim()) {
                    var auto = slugifyJs(titleInput.value);
                    slugInput.value = auto;
                    if (slugPreviewText) {
                        slugPreviewText.innerText = auto || 'url-da-pagina';
                    }
                }
            });
        }

        function generateSlugFromTitle(force) {
            var auto = slugifyJs(titleInput.value);
            slugInput.value = auto;
            if (slugPreviewText) {
                slugPreviewText.innerText = auto || 'url-da-pagina';
            }
            if (force) {
                userEditedSlug = false;
            }
        }

        function handleFormSubmit() {
            var html = isSourceMode ? rawHtmlArea.value : editorCanvas.innerHTML;
            document.getElementById('contentHtmlInput').value = html;
            return true;
        }

        // -------------------------------------------------------------
        // Modal de Seleção de Mídias Estilo WordPress
        // -------------------------------------------------------------
        var currentModalTarget = 'featured'; // 'featured' ou 'content'
        var modalMediaList = [];
        var selectedMediaItem = null;

        function openMediaModal(target) {
            currentModalTarget = target;
            selectedMediaItem = null;
            document.getElementById('modalSelectionDetails').innerText = 'Nenhuma imagem selecionada.';
            document.getElementById('btnApplySelection').disabled = true;
            document.getElementById('modalSearchInput').value = '';

            document.getElementById('modalHeaderTitle').innerText = (target === 'featured')
                ? 'Selecionar Imagem de Destaque'
                : 'Inserir Imagem no Conteúdo da Página';

            document.getElementById('mediaModal').style.display = 'flex';
            loadMediaCatalog();
        }

        function closeMediaModal() {
            document.getElementById('mediaModal').style.display = 'none';
        }

        function loadMediaCatalog(query) {
            var grid = document.getElementById('modalMediaGrid');
            var loading = document.getElementById('modalLoading');
            grid.innerHTML = '';
            loading.style.display = 'block';

            var url = '/admin/api/media.php' + (query ? '?q=' + encodeURIComponent(query) : '');
            fetch(url)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    loading.style.display = 'none';
                    if (data.ok && Array.isArray(data.media)) {
                        modalMediaList = data.media;
                        renderModalGrid(modalMediaList);
                    } else {
                        grid.innerHTML = '<p style="padding: 2rem; color: #dc2626;">Erro ao carregar mídias.</p>';
                    }
                })
                .catch(function(err) {
                    loading.style.display = 'none';
                    grid.innerHTML = '<p style="padding: 2rem; color: #dc2626;">Falha de comunicação: ' + err.message + '</p>';
                });
        }

        function renderModalGrid(items) {
            var grid = document.getElementById('modalMediaGrid');
            grid.innerHTML = '';

            if (items.length === 0) {
                grid.innerHTML = '<p style="padding: 3rem; text-align: center; color: var(--text-muted); grid-column: 1 / -1;">Nenhuma imagem encontrada.</p>';
                return;
            }

            items.forEach(function(item) {
                var card = document.createElement('div');
                card.className = 'modal-media-item';
                if (selectedMediaItem && selectedMediaItem.id === item.id) {
                    card.classList.add('selected');
                }

                var img = document.createElement('img');
                img.className = 'modal-media-thumb';
                img.loading = 'lazy';
                var safeThumb = (typeof item.thumb_url === 'string' && (item.thumb_url.startsWith('/') || item.thumb_url.startsWith('http://') || item.thumb_url.startsWith('https://')))
                    ? item.thumb_url : '';
                img.src = safeThumb;
                img.alt = item.original_name || 'Mídia';

                var titleEl = document.createElement('div');
                titleEl.className = 'modal-media-title';
                titleEl.title = item.original_name || '';
                titleEl.textContent = item.original_name || '';

                card.appendChild(img);
                card.appendChild(titleEl);

                card.onclick = function() {
                    document.querySelectorAll('.modal-media-item').forEach(function(el) { el.classList.remove('selected'); });
                    card.classList.add('selected');
                    selectedMediaItem = item;

                    var detailsEl = document.getElementById('modalSelectionDetails');
                    detailsEl.textContent = '';
                    var strong = document.createElement('strong');
                    strong.textContent = 'Selecionada: ';
                    detailsEl.appendChild(strong);
                    var textNode = document.createTextNode((item.original_name || '') + ' (' + Math.round(item.file_size / 1024) + ' KiB • ' + item.width + 'x' + item.height + 'px)');
                    detailsEl.appendChild(textNode);

                    document.getElementById('btnApplySelection').disabled = false;
                };

                card.ondblclick = function() {
                    selectedMediaItem = item;
                    applyMediaSelection();
                };

                grid.appendChild(card);
            });
        }

        var searchDebounceTimer = null;
        function filterMediaItems(query) {
            query = query.trim();
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(function() {
                loadMediaCatalog(query);
            }, 300);
        }

        function uploadFromModal(input) {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            var csrf = document.getElementById('csrfToken').value;

            var formData = new FormData();
            formData.append('media_file', file);
            formData.append('_csrf_token', csrf);

            document.getElementById('modalLoading').style.display = 'block';
            document.getElementById('modalLoading').innerText = 'Enviando ' + file.name + ' e gerando WebP / AVIF...';

            fetch('/admin/media.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                input.value = '';
                document.getElementById('modalLoading').innerText = 'Carregando fotos...';
                if (data.ok && data.media) {
                    loadMediaCatalog();
                } else {
                    alert('Erro no upload: ' + (data.error || 'Falha ao processar'));
                    document.getElementById('modalLoading').style.display = 'none';
                }
            })
            .catch(function(err) {
                input.value = '';
                alert('Erro de conexão ao enviar foto: ' + err.message);
                document.getElementById('modalLoading').style.display = 'none';
            });
        }

        function applyMediaSelection() {
            if (!selectedMediaItem) return;

            if (currentModalTarget === 'featured') {
                var url = selectedMediaItem.full_url;
                document.getElementById('featured_image').value = url;
                document.getElementById('featuredPreviewImg').src = url;
                document.getElementById('featuredPreviewBox').style.display = 'block';
            } else if (currentModalTarget === 'content') {
                var imgHtml = '<figure><img src="' + selectedMediaItem.full_url + '" alt="' + escapeHtml(selectedMediaItem.original_name) + '"><figcaption>' + escapeHtml(selectedMediaItem.original_name) + '</figcaption></figure><p></p>';
                if (isSourceMode) {
                    rawHtmlArea.value += '\n' + imgHtml;
                } else {
                    editorCanvas.focus();
                    document.execCommand('insertHTML', false, imgHtml);
                }
            }

            closeMediaModal();
        }

        function removeFeaturedImage() {
            document.getElementById('featured_image').value = '';
            document.getElementById('featuredPreviewImg').src = '';
            document.getElementById('featuredPreviewBox').style.display = 'none';
        }
    </script>
<?php require __DIR__ . '/footer.php'; ?>
