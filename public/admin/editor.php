<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Editor de Notícias com Suporte a Rich Text / Docs / Word
 * e Modal de Seleção de Mídias Estilo WordPress
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\BlockCompiler;
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
        $contentHtml = trim($_POST['content_html'] ?? '');
        $contentJson = $_POST['content_json'] ?? '';
        $seoTitle = trim($_POST['seo_title'] ?? '');
        $seoDesc = trim($_POST['seo_description'] ?? '');
        $focusKeyword = trim($_POST['focus_keyword'] ?? '');
        $isFeatured = !empty($_POST['is_featured']) ? 1 : 0;
        $knownVersion = (int)($_POST['version'] ?? 1);

        $payload = [
            'title' => $title,
            'summary' => $summary,
            'category' => $category,
            'featured_image' => $featuredImage,
            'content_html' => $contentHtml,
            'content_json' => $contentJson,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDesc,
            'focus_keyword' => $focusKeyword,
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
                    $cleanHtml = BlockCompiler::sanitizeHtml($contentHtml);
                    $cleanJson = !empty($contentJson) && $contentJson !== '[]'
                        ? $contentJson
                        : BlockCompiler::htmlToBlocks($cleanHtml);

                    Database::query(
                        "UPDATE posts SET 
                            title = ?, summary = ?, category = ?, featured_image = ?, 
                            content_json = ?, content_html = ?, seo_title = ?, 
                            seo_description = ?, focus_keyword = ?, is_featured = ?, 
                            updated_at = CURRENT_TIMESTAMP 
                         WHERE id = ?",
                        [
                            $title, $summary, $category, $featuredImage, 
                            $cleanJson, $cleanHtml, $seoTitle, 
                            $seoDesc, $focusKeyword, $isFeatured, $postId
                        ]
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

// Conteúdo inicial para o editor
$initialHtml = $post['content_html'] ?? '';
if (empty($initialHtml) && !empty($post['content_json'])) {
    $initialHtml = BlockCompiler::compile($post['content_json']);
}
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
        .container { max-width: 1100px; margin: 2rem auto; padding: 0 1.5rem; }
        .editor-grid { display: grid; grid-template-columns: 2.2fr 1fr; gap: 2rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #334155; }
        input[type="text"], textarea, select { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; font-family: inherit; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .btn { padding: 0.65rem 1.25rem; border-radius: var(--radius); font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; }
        .btn-primary { background: var(--primary-accent); color: white; }
        .btn-primary:hover { background: #1d4ed8; }
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
            top: 57px;
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
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
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
        .modal-media-item:hover { border-color: #93c5fd; transform: translateY(-2px); }
        .modal-media-item.selected { border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.25); }
        .modal-media-thumb { width: 100%; aspect-ratio: 16/9; object-fit: cover; background: #e2e8f0; }
        .modal-media-title { padding: 0.5rem; font-size: 0.72rem; font-weight: 600; line-height: 1.3; word-break: break-all; color: #334155; }
        .media-modal-footer { padding: 0.85rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
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

        <form method="POST" id="editorForm" onsubmit="return handleFormSubmit()">
            <input type="hidden" name="_csrf_token" id="csrfToken" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="version" value="<?= $post ? (int)$post['version'] : 1 ?>">
            <input type="hidden" name="content_html" id="contentHtmlInput" value="">
            <input type="hidden" name="content_json" id="contentJsonInput" value="<?= Security::e($post['content_json'] ?? '') ?>">

            <div class="editor-grid">
                <!-- Coluna Principal (Texto e Títulos) -->
                <div>
                    <div class="card">
                        <div class="form-group">
                            <label for="title">Título da Notícia *</label>
                            <input type="text" id="title" name="title" value="<?= Security::e($post['title'] ?? '') ?>" required style="font-size: 1.35rem; font-weight: 800;" placeholder="Ex: Ferrari confirma chegada de novos componentes para o GP de Interlagos">
                        </div>

                        <div class="form-group">
                            <label for="summary">Resumo / Subtítulo</label>
                            <textarea id="summary" name="summary" rows="2" placeholder="Linha fina ou resumo que aparece logo abaixo do título..."><?= Security::e($post['summary'] ?? '') ?></textarea>
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
                        <div class="editor-canvas" id="editorCanvas" contenteditable="true" spellcheck="true" placeholder="Cole ou digite aqui o texto da notícia (suporta Ctrl+V direto do Google Docs, Word ou web)..."><?= $initialHtml ?></div>
                        
                        <!-- Textarea de Fallback / Código HTML Direto -->
                        <textarea id="rawHtmlArea" style="display: none; min-height: 480px; font-family: monospace; font-size: 0.9rem; line-height: 1.5; padding: 1.25rem; border: none; border-radius: 0; outline: none; resize: vertical;"><?= Security::e($initialHtml) ?></textarea>
                    </div>
                </div>

                <!-- Coluna Lateral (Metadados, Destaques, Categoria e SEO) -->
                <div>
                    <div class="card">
                        <div style="margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.5rem;">
                            <button type="submit" name="form_action" value="publish" class="btn btn-primary" style="width: 100%; font-size: 1rem; padding: 0.85rem;">
                                <?= ($post && $post['status'] === 'published') ? 'Atualizar Publicação' : '🚀 Publicar Agora' ?>
                            </button>
                            <button type="submit" name="form_action" value="save_draft" class="btn btn-secondary" style="width: 100%;">
                                💾 Salvar como Rascunho
                            </button>
                        </div>

                        <div class="form-group">
                            <label for="category">Categoria *</label>
                            <select id="category" name="category" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= Security::e($cat['slug']) ?>" <?= ($post && $post['category'] === $cat['slug']) ? 'selected' : '' ?>>
                                        <?= Security::e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Imagem de Destaque com Modal Seletor Estilo WordPress -->
                        <div class="form-group">
                            <label>Imagem de Destaque</label>
                            <input type="hidden" id="featured_image" name="featured_image" value="<?= Security::e($post['featured_image'] ?? '') ?>">
                            
                            <div id="featuredPreviewBox" style="<?= empty($post['featured_image']) ? 'display: none;' : '' ?> margin-bottom: 0.75rem;">
                                <div style="position: relative; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border); background: #f1f5f9;">
                                    <img id="featuredPreviewImg" src="<?= Security::e($post['featured_image'] ?? '') ?>" style="width: 100%; aspect-ratio: 16/9; object-fit: cover; display: block;">
                                    <button type="button" onclick="removeFeaturedImage()" style="position: absolute; top: 8px; right: 8px; background: rgba(220,38,38,0.9); color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 0.75rem; font-weight: 700; cursor: pointer;">
                                        ✕ Remover Imagem
                                    </button>
                                </div>
                            </div>

                            <button type="button" class="btn btn-secondary" onclick="openMediaModal('featured')" style="width: 100%;">
                                <span>📷</span> Selecionar da Biblioteca
                            </button>
                        </div>

                        <div class="form-group" style="margin-top: 1rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; cursor: pointer;">
                                <input type="checkbox" name="is_featured" value="1" <?= (!empty($post['is_featured'])) ? 'checked' : '' ?> style="width: auto;">
                                <strong>Destacar na Página Inicial</strong>
                            </label>
                        </div>
                    </div>

                    <!-- Bloco de SEO Completo com Primary Focus Keyword -->
                    <div class="card">
                        <h3 style="font-size: 1rem; font-weight: 700; margin-bottom: 1rem; color: #334155;">SEO Nativo</h3>
                        
                        <div class="form-group">
                            <label for="focus_keyword">Palavra-chave Principal (Focus Keyword)</label>
                            <input type="text" id="focus_keyword" name="focus_keyword" value="<?= Security::e($post['focus_keyword'] ?? '') ?>" placeholder="ex: lewis hamilton ferrari 2026">
                            <span style="font-size: 0.72rem; color: var(--text-muted);">Termo central para ranqueamento no Google</span>
                        </div>

                        <div class="form-group">
                            <label for="seo_title">Título SEO (Tag Title)</label>
                            <input type="text" id="seo_title" name="seo_title" value="<?= Security::e($post['seo_title'] ?? '') ?>" placeholder="Título nos motores de busca (Google)">
                        </div>

                        <div class="form-group">
                            <label for="seo_description">Meta Description</label>
                            <textarea id="seo_description" name="seo_description" rows="3" placeholder="Resumo de até 160 caracteres exibido na página de resultados..."><?= Security::e($post['seo_description'] ?? '') ?></textarea>
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
            var html = clipboardData.getData('text/html');
            var text = clipboardData.getData('text/plain');

            if (html) {
                var cleanHtml = cleanWordOrDocsHtml(html);
                document.execCommand('insertHTML', false, cleanHtml);
            } else if (text) {
                var paragraphs = text.split(/\r?\n\r?\n/).map(function(p) {
                    var trimmed = p.trim();
                    return trimmed ? '<p>' + escapeHtml(trimmed) + '</p>' : '';
                }).join('');
                document.execCommand('insertHTML', false, paragraphs || escapeHtml(text));
            }
        });

        function cleanWordOrDocsHtml(html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            
            // Remover scripts, styles, meta, comentários e tags de office
            var badTags = doc.querySelectorAll('script, style, meta, link, o\\:p, xml');
            badTags.forEach(function(el) { el.remove(); });

            var allowedTags = ['P', 'H2', 'H3', 'H4', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'BLOCKQUOTE', 'UL', 'OL', 'LI', 'A', 'IMG', 'FIGURE', 'FIGCAPTION', 'BR', 'HR'];
            
            function cleanNode(node) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    var tag = node.tagName.toUpperCase();

                    // Mapeamento semântico
                    if (tag === 'DIV' || tag === 'SECTION' || tag === 'ARTICLE') {
                        var p = doc.createElement('p');
                        while (node.firstChild) p.appendChild(node.firstChild);
                        node.parentNode.replaceChild(p, node);
                        node = p;
                        tag = 'P';
                    } else if (tag === 'B') {
                        var strong = doc.createElement('strong');
                        while (node.firstChild) strong.appendChild(node.firstChild);
                        node.parentNode.replaceChild(strong, node);
                        node = strong;
                        tag = 'STRONG';
                    } else if (tag === 'I') {
                        var em = doc.createElement('em');
                        while (node.firstChild) em.appendChild(node.firstChild);
                        node.parentNode.replaceChild(em, node);
                        node = em;
                        tag = 'EM';
                    }

                    if (!allowedTags.includes(tag)) {
                        // Desembrulhar elemento não permitido preservando o conteúdo interno
                        var parent = node.parentNode;
                        while (node.firstChild) {
                            parent.insertBefore(node.firstChild, node);
                        }
                        parent.removeChild(node);
                        return;
                    }

                    // Remover estilos sujos, classes de Word (MsoNormal) e on*
                    var attrs = Array.from(node.attributes);
                    attrs.forEach(function(attr) {
                        var name = attr.name.toLowerCase();
                        if (name.startsWith('on') || name === 'style' || name === 'class' || name === 'id' || name === 'dir') {
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
            return doc.body.innerHTML;
        }

        function escapeHtml(text) {
            return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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
                : 'Inserir Imagem no Conteúdo da Notícia';

            document.getElementById('mediaModal').style.display = 'flex';
            loadMediaCatalog();
        }

        function closeMediaModal() {
            document.getElementById('mediaModal').style.display = 'none';
        }

        function loadMediaCatalog() {
            var grid = document.getElementById('modalMediaGrid');
            var loading = document.getElementById('modalLoading');
            grid.innerHTML = '';
            loading.style.display = 'block';

            fetch('/admin/api/media.php')
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

                card.innerHTML = 
                    '<img src="' + item.thumb_url + '" class="modal-media-thumb" loading="lazy">' +
                    '<div class="modal-media-title" title="' + item.original_name + '">' + item.original_name + '</div>';

                card.onclick = function() {
                    document.querySelectorAll('.modal-media-item').forEach(function(el) { el.classList.remove('selected'); });
                    card.classList.add('selected');
                    selectedMediaItem = item;
                    document.getElementById('modalSelectionDetails').innerHTML = 
                        '<strong>Selecionada:</strong> ' + item.original_name + ' (' + Math.round(item.file_size / 1024) + ' KiB • ' + item.width + 'x' + item.height + 'px)';
                    document.getElementById('btnApplySelection').disabled = false;
                };

                card.ondblclick = function() {
                    selectedMediaItem = item;
                    applyMediaSelection();
                };

                grid.appendChild(card);
            });
        }

        function filterMediaItems(query) {
            query = query.toLowerCase().trim();
            if (!query) {
                renderModalGrid(modalMediaList);
                return;
            }
            var filtered = modalMediaList.filter(function(item) {
                return item.original_name.toLowerCase().includes(query);
            });
            renderModalGrid(filtered);
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
</body>
</html>
