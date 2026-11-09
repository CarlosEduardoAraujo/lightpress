<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Editor de Notícias com Suporte a Rich Text / Docs / Word
 * e Modal de Seleção de Mídias Estilo WordPress
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\BlockCompiler;
use LightPress\Core\CacheManager;
use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\PostService;
use LightPress\Core\Security;

$currentUser = Security::requireAuth();

// Auto-promoção de matérias agendadas cujo horário já foi atingido
PostService::promoteScheduledPosts();

$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post = null;
$message = '';
$error = '';
$hasActiveDraftRevision = false;
$draftRevisionDate = null;
$activeRevisionId = 0;
$hasOutdatedRevision = false;
$outdatedRevisionDate = null;
$outdatedRevisionVersion = 0;

$applyDraftRevision = function (array &$post, int $postId) use (
    &$hasActiveDraftRevision, &$draftRevisionDate, &$activeRevisionId,
    &$hasOutdatedRevision, &$outdatedRevisionDate, &$outdatedRevisionVersion
): void {
    $latestRev = Database::fetchOne(
        "SELECT * FROM post_revisions 
         WHERE post_id = ? AND status IN ('draft', 'pending_review') 
         ORDER BY id DESC LIMIT 1",
        [$postId]
    );

    if ($latestRev) {
        $baseVersion = (int)($latestRev['base_version'] ?? 0);
        $currentPostVersion = (int)($post['version'] ?? 1);

        // Se a revisão foi baseada em uma versão anterior do post público, não podemos
        // sobrepô-la cegamente sobre o conteúdo mais recente publicado, pois causaria perda silenciosa de dados.
        if ($baseVersion !== $currentPostVersion) {
            $hasOutdatedRevision = true;
            $outdatedRevisionDate = $latestRev['created_at'];
            $outdatedRevisionVersion = $baseVersion;
            return;
        }

        $payload = json_decode((string)$latestRev['payload_json'], true);
        if (is_array($payload)) {
            foreach (['title', 'slug', 'summary', 'category', 'tags', 'featured_image', 'content_json', 'seo_title', 'seo_description', 'focus_keyword', 'is_featured'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $post[$key] = $payload[$key];
                }
            }
        }

        // Diferenciar explicitamente campo ausente de conteúdo vazio (string vazia intencional)
        if (array_key_exists('content_html', $latestRev) && $latestRev['content_html'] !== null) {
            $post['content_html'] = (string)$latestRev['content_html'];
            if (trim($post['content_html']) === '') {
                $post['content_json'] = '[]';
            }
        } elseif (is_array($payload) && array_key_exists('content_html', $payload) && $payload['content_html'] !== null) {
            $post['content_html'] = (string)$payload['content_html'];
            if (trim($post['content_html']) === '') {
                $post['content_json'] = '[]';
            }
        }

        $activeRevisionId = (int)$latestRev['id'];
        $hasActiveDraftRevision = true;
        $draftRevisionDate = $latestRev['created_at'];
    }
};

if ($postId > 0) {
    $post = Database::fetchOne("SELECT * FROM posts WHERE id = ? LIMIT 1", [$postId]);
    if (!$post) {
        die("Artigo não encontrado.");
    }
    if (!Authorization::can($currentUser, Authorization::ACTION_EDIT_POST, $post)) {
        die("Você não possui permissão para editar este artigo.");
    }
    // Se a matéria estiver publicada ou agendada, carrega a revisão pendente mais recente
    if (in_array($post['status'], ['published', 'scheduled'], true)) {
        $applyDraftRevision($post, $postId);
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
        $slug = trim($_POST['slug'] ?? '');
        $category = trim($_POST['category'] ?? 'geral');
        $tags = trim($_POST['tags'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $featuredImage = !empty($_POST['featured_image']) ? BlockCompiler::sanitizeImageUrl(trim($_POST['featured_image'])) : '';
        $contentHtml = trim($_POST['content_html'] ?? '');
        $contentJson = $_POST['content_json'] ?? '';
        $seoTitle = trim($_POST['seo_title'] ?? '');
        $seoDesc = trim($_POST['seo_description'] ?? '');
        $focusKeyword = trim($_POST['focus_keyword'] ?? '');
        $isFeatured = !empty($_POST['is_featured']) ? 1 : 0;
        $publishedAtRaw = trim($_POST['published_at'] ?? '');
        $displayTzName = $config['app']['display_timezone'] ?? 'America/Sao_Paulo';
        $publishedAt = null;
        if (!empty($publishedAtRaw)) {
            try {
                $dt = new \DateTime($publishedAtRaw, new \DateTimeZone($displayTzName));
                $dt->setTimezone(new \DateTimeZone('UTC'));
                $formatted = $dt->format('Y-m-d H:i:s');
                $ts = $dt->getTimestamp();
                $publishedAt = ($ts > 0 && (int)gmdate('Y', $ts) > 1970) ? $formatted : null;
            } catch (\Throwable $e) {
                $timestamp = strtotime($publishedAtRaw);
                $publishedAt = ($timestamp !== false && $timestamp > 0 && (int)gmdate('Y', $timestamp) > 1970)
                    ? gmdate('Y-m-d H:i:s', $timestamp)
                    : null;
            }
        }
        $knownVersion = (int)($_POST['version'] ?? 1);
        $knownRevisionId = (int)($_POST['known_revision_id'] ?? 0);

        $payload = [
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'category' => $category,
            'tags' => $tags,
            'featured_image' => $featuredImage,
            'content_html' => $contentHtml,
            'content_json' => $contentJson,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDesc,
            'focus_keyword' => $focusKeyword,
            'is_featured' => $isFeatured,
            'published_at' => $publishedAt,
        ];

        try {
            if ($postId === 0) {
                // Criar novo post (com resolução e prevenção de colisões/palavras reservadas)
                $payload['slug'] = $postService->resolveUniqueSlug(!empty($slug) ? Security::slugify($slug) : Security::slugify($title));
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
                    
                    $isFuture = (!empty($publishedAt) && strtotime($publishedAt) > time());
                    $schedFormatted = $publishedAt ?? '';
                    if (!empty($publishedAt)) {
                        try {
                            $dt = new \DateTime($publishedAt, new \DateTimeZone('UTC'));
                            $dt->setTimezone(new \DateTimeZone($displayTzName));
                            $schedFormatted = $dt->format('d/m/Y \à\s H:i');
                        } catch (\Throwable $e) {
                            $schedFormatted = date('d/m/Y \à\s H:i', strtotime($publishedAt));
                        }
                    }
                    $message = $isFuture 
                        ? "Matéria agendada com sucesso para {$schedFormatted}!"
                        : "Publicação atualizada e cache invalidado com sucesso!";
                } else {
                    // Salvar rascunho (executado atomicamente em transação com bloqueio FOR UPDATE)
                    $cleanHtml = BlockCompiler::sanitizeHtml($contentHtml);
                    if (trim($cleanHtml) === '') {
                        $cleanHtml = '';
                        $cleanJson = '[]';
                    } else {
                        $cleanJson = !empty($contentJson) && $contentJson !== '[]'
                            ? $contentJson
                            : BlockCompiler::htmlToBlocks($cleanHtml);
                    }

                    $wasPublished = false;
                    $prevSlug = '';
                    $prevCat = '';

                    Database::transaction(function (\PDO $pdo) use (
                        $postId, $currentUser, $knownVersion, $knownRevisionId, $title, $slug, $summary,
                        $category, $tags, $featuredImage, $cleanJson, $cleanHtml,
                        $seoTitle, $seoDesc, $focusKeyword, $publishedAt, &$message,
                        &$wasPublished, &$prevSlug, &$prevCat
                    ): void {
                        // 1. Bloqueia e relê a linha atualizada do banco
                        $current = Database::fetchOne("SELECT * FROM posts WHERE id = ?" . (Database::getDriver() === 'mysql' ? ' FOR UPDATE' : ''), [$postId]);
                        if (!$current) {
                            throw new RuntimeException("Publicação não encontrada.", 404);
                        }

                        // 2. Reavalia permissões sobre o registro atual
                        if (!Authorization::can($currentUser, Authorization::ACTION_EDIT_POST, $current)) {
                            throw new RuntimeException("Você não possui permissão para editar este artigo.", 403);
                        }

                        // 3. Validação de concorrência otimista da publicação base ANTES de qualquer gravação
                        if ((int)$current['version'] !== $knownVersion) {
                            throw new RuntimeException(
                                "Conflito de edição detectado! A matéria foi modificada por outro usuário (versão atual: {$current['version']}, versão base: {$knownVersion}). Recarregue a página para mesclar suas alterações.",
                                409
                            );
                        }

                        $canPublish = Authorization::can($currentUser, Authorization::ACTION_PUBLISH_POST);
                        $canFeature = Authorization::can($currentUser, Authorization::ACTION_SET_FEATURED);

                        $isFeaturedVal = $canFeature
                            ? (!empty($_POST['is_featured']) ? 1 : 0)
                            : (int)($current['is_featured'] ?? 0);

                        // 4. Se a matéria já estiver publicada ou agendada e a ação for salvar rascunho:
                        // Salva com segurança como uma REVISÃO PRIVADA, mantendo o post público intacto (SEC-04)
                        if (in_array($current['status'], ['published', 'scheduled'], true)) {
                            // Validação de concorrência entre rascunhos de revisão
                            $latestRev = Database::fetchOne(
                                "SELECT id, base_version FROM post_revisions 
                                 WHERE post_id = ? AND status IN ('draft', 'pending_review') 
                                 ORDER BY id DESC LIMIT 1",
                                [$postId]
                            );
                            $latestRevId = $latestRev ? (int)$latestRev['id'] : 0;
                            if ($latestRevId > 0) {
                                if ($knownRevisionId > 0 && $latestRevId !== $knownRevisionId) {
                                    throw new RuntimeException(
                                        "Conflito de rascunho detectado! Outra revisão deste artigo foi salva enquanto você editava. Recarregue a página para carregar as alterações mais recentes.",
                                        409
                                    );
                                } elseif ($knownRevisionId === 0) {
                                    throw new RuntimeException(
                                        "Conflito de rascunho detectado! Uma revisão privada recente foi criada para este artigo. Recarregue a página para visualizar a versão mais recente.",
                                        409
                                    );
                                }
                            }

                            $revStatus = $canPublish ? 'draft' : 'pending_review';
                            $revisionPayload = [
                                'title' => $title,
                                'slug' => !empty($slug) ? Security::slugify($slug) : $current['slug'],
                                'summary' => $summary,
                                'category' => $category,
                                'tags' => $tags,
                                'featured_image' => $featuredImage,
                                'content_json' => $cleanJson,
                                'content_html' => $cleanHtml,
                                'seo_title' => $seoTitle,
                                'seo_description' => $seoDesc,
                                'focus_keyword' => $focusKeyword,
                                'is_featured' => $isFeaturedVal,
                                'status' => $revStatus,
                            ];

                            Database::query(
                                "INSERT INTO post_revisions (
                                    post_id, editor_id, base_version, status, payload_json, content_html, created_at
                                 ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
                                [
                                    $postId,
                                    (int)$currentUser['id'],
                                    $knownVersion,
                                    $revStatus,
                                    json_encode($revisionPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                    $cleanHtml
                                ]
                            );

                            $revId = (int)Database::getConnection()->lastInsertId();
                            ImageProcessor::syncRefs($postId, $revId > 0 ? $revId : null, $cleanHtml, $featuredImage);

                            $message = $canPublish
                                ? "Esta matéria está publicada ou agendada. Suas alterações foram salvas como um RASCUNHO DE REVISÃO privada. A versão pública atual permaneceu inalterada. Clique em 'Publicar Alterações' quando desejar aplicar as mudanças no site."
                                : "Esta matéria está publicada ou agendada. Suas alterações foram salvas com segurança como uma REVISÃO PENDENTE de aprovação editorial. A versão pública atual permaneceu inalterada.";
                            return;
                        }

                        // 5. Edição normal de rascunho ou edição autorizada:
                        $cleanSlug = !empty($slug) ? Security::slugify($slug) : (!empty($current['slug']) ? $current['slug'] : Security::slugify($title));
                        $cleanSlug = (new PostService())->resolveUniqueSlug($cleanSlug, $postId);
                        // Se o editor limpou o campo de data (published_at vazio), respeita a remoção
                        $publishedAtVal = $canPublish
                            ? (!empty($publishedAt) ? $publishedAt : (empty($publishedAtRaw) ? null : ($current['published_at'] ?? null)))
                            : ($current['published_at'] ?? null);

                        $statusVal = $current['status'];
                        $previousStatus = $statusVal;

                        // Se a data de publicação for removida e a matéria estiver publicada ou agendada, reverte status para draft
                        if (empty($publishedAtVal) && in_array($statusVal, ['published', 'scheduled'], true)) {
                            $statusVal = 'draft';
                            $wasPublished = true;
                            $prevSlug = $current['slug'];
                            $prevCat = $current['category'];
                        }

                        $newVersion = (int)$current['version'] + 1;

                        $stmt = Database::query(
                            "UPDATE posts SET 
                                title = ?, slug = ?, summary = ?, category = ?, tags = ?, featured_image = ?, 
                                content_json = ?, content_html = ?, status = ?, seo_title = ?, 
                                seo_description = ?, focus_keyword = ?, is_featured = ?, published_at = ?,
                                version = ?, updated_at = CURRENT_TIMESTAMP 
                             WHERE id = ? AND version = ?",
                            [
                                $title, $cleanSlug, $summary, $category, $tags, $featuredImage, 
                                $cleanJson, $cleanHtml, $statusVal, $seoTitle, 
                                $seoDesc, $focusKeyword, $isFeaturedVal, $publishedAtVal, $newVersion, $postId, $knownVersion
                            ]
                        );

                        if ($stmt->rowCount() === 0) {
                            throw new RuntimeException("Conflito de concorrência detectado durante a gravação. Nenhuma linha foi alterada.", 409);
                        }

                        // Gravar snapshot de histórico
                        $snapshotPayload = [
                            'title' => $title,
                            'slug' => $cleanSlug,
                            'summary' => $summary,
                            'category' => $category,
                            'tags' => $tags,
                            'featured_image' => $featuredImage,
                            'content_json' => $cleanJson,
                            'content_html' => $cleanHtml,
                            'seo_title' => $seoTitle,
                            'seo_description' => $seoDesc,
                            'focus_keyword' => $focusKeyword,
                            'is_featured' => $isFeaturedVal,
                            'status' => $statusVal,
                        ];

                        Database::query(
                            "INSERT INTO post_revisions (
                                post_id, editor_id, base_version, status, payload_json, content_html, created_at
                             ) VALUES (?, ?, ?, 'draft', ?, ?, CURRENT_TIMESTAMP)",
                            [
                                $postId,
                                (int)$currentUser['id'],
                                $newVersion,
                                json_encode($snapshotPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                $cleanHtml
                            ]
                        );

                        $revId = (int)Database::getConnection()->lastInsertId();
                        ImageProcessor::syncRefs($postId, $revId > 0 ? $revId : null, $cleanHtml, $featuredImage);

                        $message = "Rascunho salvo com sucesso!";
                    });

                    if ($wasPublished) {
                        try {
                            $cacheManager = new CacheManager();
                            $cacheManager->purgeUrls([
                                '/',
                                "/{$prevSlug}/",
                                "/categoria/{$prevCat}/",
                                '/feed',
                                '/feed.xml'
                            ]);
                        } catch (\Throwable $e) {
                            error_log("[Cache Purge Error] Unpublishing draft {$postId}: " . $e->getMessage());
                        }
                    }
                }
                // Recarregar dados do post e sobrepor rascunho de revisão se aplicável
                $post = Database::fetchOne("SELECT * FROM posts WHERE id = ? LIMIT 1", [$postId]);
                if ($post && in_array($post['status'], ['published', 'scheduled'], true)) {
                    $applyDraftRevision($post, $postId);
                }
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

// Conteúdo inicial para o editor: diferencia campo ausente de conteúdo vazio (string vazia intencional)
$initialHtml = (array_key_exists('content_html', $post ?? []) && $post['content_html'] !== null)
    ? (string)$post['content_html']
    : (!empty($post['content_json']) && $post['content_json'] !== '[]' ? BlockCompiler::compile($post['content_json']) : '');
$pageTitle = !empty($post['id']) ? 'Editar Notícia' : 'Nova Notícia';
$activePage = 'dashboard';
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

    /* Área de Drop Zone no Modal de Mídias */
    .modal-drop-zone {
        border: 2px dashed #cbd5e1;
        border-radius: var(--radius);
        padding: 1.25rem 1rem;
        text-align: center;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-bottom: 1.25rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
    }
    .modal-drop-zone:hover {
        border-color: var(--primary-accent);
        background: rgba(225, 6, 0, 0.03);
    }
    .modal-drop-zone.dragover {
        border-color: var(--primary-accent);
        background: rgba(225, 6, 0, 0.08);
        border-style: solid;
        transform: scale(1.01);
        box-shadow: 0 0 0 4px rgba(225, 6, 0, 0.15);
    }
    .modal-drop-icon { font-size: 1.8rem; line-height: 1; }
    .modal-drop-text { font-size: 0.92rem; color: #334155; }
    .modal-drop-browse { color: var(--primary-accent); font-weight: 700; text-decoration: underline; }
    .modal-drop-hint { font-size: 0.75rem; color: var(--text-muted); }
    [data-theme="dark"] .modal-drop-zone { background: #0b1120; border-color: #334155; }
    [data-theme="dark"] .modal-drop-zone:hover { border-color: var(--primary-accent); background: rgba(225, 6, 0, 0.1); }
    [data-theme="dark"] .modal-drop-zone.dragover { background: rgba(225, 6, 0, 0.2); border-color: var(--primary-accent); }
    [data-theme="dark"] .modal-drop-text { color: #f1f5f9; }
</style>

    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= Security::e($message) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($hasOutdatedRevision)): ?>
            <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.35); border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.25rem;">⚠️</span>
                <div style="font-size: 0.9rem; line-height: 1.4;">
                    <strong style="color: #dc2626;">Atenção: Revisão desatualizada ignorada:</strong>
                    Existe uma revisão não publicada gravada em <strong><?= !empty($outdatedRevisionDate) ? date('d/m/Y \à\s H:i', strtotime($outdatedRevisionDate)) : '' ?></strong> baseada na versão <?= (int)$outdatedRevisionVersion ?> deste artigo, mas a versão pública atual já avançou para a versão <?= (int)($post['version'] ?? 1) ?>. Para preservar as alterações públicas mais recentes, o editor exibiu a publicação atual em vez de restaurar a revisão desatualizada.
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($hasActiveDraftRevision)): ?>
            <div style="background: rgba(234, 179, 8, 0.12); border: 1px solid rgba(234, 179, 8, 0.35); border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.25rem;">📝</span>
                <div style="font-size: 0.9rem; line-height: 1.4;">
                    <strong style="color: #ca8a04;">Rascunho de revisão carregado:</strong>
                    Você está editando a revisão privada salva em <strong><?= !empty($draftRevisionDate) ? date('d/m/Y \à\s H:i', strtotime($draftRevisionDate)) : 'revisão recente' ?></strong>. A versão pública atualmente no ar permanece inalterada até que você clique em <strong>Publicar Alterações</strong>.
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" id="editorForm" onsubmit="return handleFormSubmit()">
            <input type="hidden" name="_csrf_token" id="csrfToken" value="<?= Security::e($csrfToken) ?>">
            <input type="hidden" name="version" value="<?= $post ? (int)$post['version'] : 1 ?>">
            <input type="hidden" name="known_revision_id" value="<?= (int)($activeRevisionId ?? 0) ?>">
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
                            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.35rem;">
                                <label for="slug" style="margin-bottom: 0;">Slug / URL Permanente *</label>
                                <span style="font-size: 0.78rem; color: var(--text-muted);">
                                    Link: <strong>/<span id="slugPreviewText"><?= Security::e($post['slug'] ?? 'url-da-noticia') ?></span>/</strong>
                                </span>
                            </div>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span style="font-size: 0.85rem; color: var(--text-muted); font-family: monospace; white-space: nowrap;">/</span>
                                <input type="text" id="slug" name="slug" value="<?= Security::e($post['slug'] ?? '') ?>" placeholder="ex: ferrari-confirma-novos-componentes" style="font-family: monospace; font-size: 0.9rem;" required>
                                <button type="button" class="btn btn-secondary" onclick="generateSlugFromTitle(true)" title="Gerar automaticamente com base no título" style="padding: 0.65rem 0.9rem; font-size: 0.82rem; white-space: nowrap;">
                                    Auto 🪄
                                </button>
                            </div>
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
                        <?php if ($post): ?>
                            <?php 
                                $pubTime = !empty($post['published_at']) ? strtotime($post['published_at']) : 0;
                                $isSched = ($pubTime > time()) && ($post['status'] === 'scheduled' || $post['status'] === 'published');
                                $isPub = ($post['status'] === 'published') || ($pubTime > 0 && $pubTime <= time() && $post['status'] === 'scheduled');
                                $displayTzName = $config['app']['display_timezone'] ?? 'America/Sao_Paulo';
                                $postDisplayFormatted = '';
                                if (!empty($post['published_at'])) {
                                    try {
                                        $dt = new \DateTime($post['published_at'], new \DateTimeZone('UTC'));
                                        $dt->setTimezone(new \DateTimeZone($displayTzName));
                                        $postDisplayFormatted = $dt->format('d/m/Y \à\s H:i');
                                    } catch (\Throwable $e) {
                                        $postDisplayFormatted = date('d/m/Y \à\s H:i', strtotime($post['published_at']));
                                    }
                                }
                            ?>
                            <?php if ($isSched): ?>
                                <div style="background: #fef3c7; color: #b45309; padding: 0.5rem 0.75rem; border-radius: var(--radius); font-weight: 700; font-size: 0.82rem; margin-bottom: 1rem; text-align: center; border: 1px solid #fde68a;">
                                    📅 Matéria Agendada para <?= $postDisplayFormatted ?>
                                </div>
                            <?php elseif ($isPub): ?>
                                <div style="background: #dcfce7; color: #15803d; padding: 0.5rem 0.75rem; border-radius: var(--radius); font-weight: 700; font-size: 0.82rem; margin-bottom: 1rem; text-align: center; border: 1px solid #bbf7d0;">
                                    <span>✓ Publicada no Ar (<?= $postDisplayFormatted ?>)</span>
                                    <a href="/<?= Security::e($post['slug']) ?>/" target="_blank" style="color: #15803d; font-weight: 800; text-decoration: underline; margin-left: 0.5rem;">Ver ↗</a>
                                </div>
                            <?php else: ?>
                                <div style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted); padding: 0.5rem 0.75rem; border-radius: var(--radius); font-weight: 700; font-size: 0.82rem; margin-bottom: 1rem; text-align: center; border: 1px solid var(--border);">
                                    📝 Modo Rascunho
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Data e Hora de Publicação / Agendamento -->
                        <div class="form-group">
                            <label for="published_at">Data de Publicação / Agendamento</label>
                            <?php
                                $postInputVal = '';
                                if (!empty($post['published_at'])) {
                                    try {
                                        $dt = new \DateTime($post['published_at'], new \DateTimeZone('UTC'));
                                        $dt->setTimezone(new \DateTimeZone($config['app']['display_timezone'] ?? 'America/Sao_Paulo'));
                                        $postInputVal = $dt->format('Y-m-d\TH:i');
                                    } catch (\Throwable $e) {
                                        $postInputVal = date('Y-m-d\TH:i', strtotime($post['published_at']));
                                    }
                                }
                            ?>
                            <input type="datetime-local" id="published_at" name="published_at" 
                                   value="<?= $postInputVal ?>"
                                   onchange="updatePublishButtonLabel()" oninput="updatePublishButtonLabel()">
                            <span style="font-size: 0.72rem; color: var(--text-muted); display: block; margin-top: 0.25rem;">
                                Deixe vazio para publicar agora ou escolha data futura para <strong>agendamento automático</strong>.
                            </span>
                        </div>

                        <div style="margin-bottom: 1.5rem; display: flex; flex-direction: column; gap: 0.5rem;">
                            <button type="submit" name="form_action" value="publish" id="btnPublishAction" class="btn btn-primary" style="width: 100%; font-size: 1rem; padding: 0.85rem;">
                                <?php
                                    $pubTime = ($post && !empty($post['published_at'])) ? strtotime($post['published_at']) : 0;
                                    $isFutureDate = ($pubTime > time());
                                    if ($isFutureDate) {
                                        echo '📅 Salvar Agendamento';
                                    } elseif ($post && ($post['status'] === 'published' || ($pubTime > 0 && $pubTime <= time()))) {
                                        echo 'Atualizar Publicação';
                                    } else {
                                        echo '🚀 Publicar Agora';
                                    }
                                ?>
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

                        <!-- Tags Adicionais / Tópicos Relacionados -->
                        <div class="form-group">
                            <label for="tags">Tags Adicionais (Tópicos)</label>
                            <input type="text" id="tags" name="tags" value="<?= Security::e($post['tags'] ?? '') ?>" placeholder="ex: f1, hamilton, ferrari, gp brasil">
                            <span style="font-size: 0.72rem; color: var(--text-muted); display: block; margin-top: 0.25rem;">
                                Separe por vírgulas. Cria links clicáveis (#tag) no rodapé da notícia e gera páginas automáticas de arquivo (ex: <code>/tag/f1/</code>).
                            </span>
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
                <!-- Área de Arrastar e Soltar / Upload Rápido -->
                <div id="modalDropZone" class="modal-drop-zone">
                    <input type="file" id="modalUploadInput" accept="image/jpeg,image/png,image/webp,image/avif,.jpg,.jpeg,.png,.webp,.avif" style="display: none;" onchange="uploadFromModal(this)">
                    <div class="modal-drop-icon">☁️</div>
                    <div class="modal-drop-text">
                        <strong>Solte arquivos aqui para enviar</strong> ou <span class="modal-drop-browse">Enviar nova foto</span>
                    </div>
                    <div class="modal-drop-hint">Formatos suportados: WebP, AVIF, JPEG, PNG (otimização e geração de miniaturas automática)</div>
                </div>

                <div class="media-modal-toolbar">
                    <input type="text" id="modalSearchInput" placeholder="🔍 Filtrar fotos por nome..." oninput="filterMediaItems(this.value)">
                    <label class="btn btn-secondary" style="margin: 0; cursor: pointer; white-space: nowrap;">
                        + Enviar Nova Foto
                        <input type="file" accept="image/jpeg,image/png,image/webp,image/avif,.jpg,.jpeg,.png,.webp,.avif" style="display: none;" onchange="uploadFromModal(this)">
                    </label>
                </div>

                <div id="modalLoading" style="display: none; text-align: center; padding: 2rem; color: var(--text-muted); font-weight: 600;">
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

                    // Se for H1, converte para H2 (hierarquia de artigo)
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
                        // Desembrulhar elemento não permitido (ex: <span>, <font>) preservando conteúdo
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

                    // Remover estilos sujos, classes de Word (MsoNormal), IDs e eventos on*
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
            if (text === null || text === undefined) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // -------------------------------------------------------------
        // Gerenciamento e Sincronização do Slug
        // -------------------------------------------------------------
        var titleInput = document.getElementById('title');
        var slugInput = document.getElementById('slug');
        var slugPreviewText = document.getElementById('slugPreviewText');
        var userEditedSlug = <?= (!empty($post['slug'])) ? 'true' : 'false' ?>;

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
                    slugPreviewText.innerText = slugifyJs(slugInput.value) || 'url-da-noticia';
                }
            });

            slugInput.addEventListener('blur', function() {
                slugInput.value = slugifyJs(slugInput.value);
                if (slugPreviewText) {
                    slugPreviewText.innerText = slugInput.value || 'url-da-noticia';
                }
            });
        }

        if (titleInput) {
            titleInput.addEventListener('input', function() {
                if (!userEditedSlug || !slugInput.value.trim()) {
                    var auto = slugifyJs(titleInput.value);
                    slugInput.value = auto;
                    if (slugPreviewText) {
                        slugPreviewText.innerText = auto || 'url-da-noticia';
                    }
                }
            });
        }

        function generateSlugFromTitle(force) {
            var auto = slugifyJs(titleInput.value);
            slugInput.value = auto;
            if (slugPreviewText) {
                slugPreviewText.innerText = auto || 'url-da-noticia';
            }
            if (force) {
                userEditedSlug = false;
            }
        }

        function handleFormSubmit() {
            var html = isSourceMode ? rawHtmlArea.value : editorCanvas.innerHTML;
            var temp = document.createElement('div');
            temp.innerHTML = html;
            var textOnly = (temp.textContent || temp.innerText || '').trim();
            if (textOnly === '' && !temp.querySelector('img, iframe, video, audio, table')) {
                html = '';
                var jsonInput = document.getElementById('contentJsonInput');
                if (jsonInput) {
                    jsonInput.value = '[]';
                }
            }
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

        function selectModalItem(item) {
            selectedMediaItem = item;
            document.querySelectorAll('.modal-media-item').forEach(function(el) {
                if (el.dataset.id === String(item.id)) {
                    el.classList.add('selected');
                    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                } else {
                    el.classList.remove('selected');
                }
            });

            var detailsEl = document.getElementById('modalSelectionDetails');
            detailsEl.textContent = '';
            var strong = document.createElement('strong');
            strong.textContent = 'Selecionada: ';
            detailsEl.appendChild(strong);
            var textNode = document.createTextNode((item.original_name || '') + ' (' + Math.round(item.file_size / 1024) + ' KiB • ' + item.width + 'x' + item.height + 'px)');
            detailsEl.appendChild(textNode);

            document.getElementById('btnApplySelection').disabled = false;
        }

        function loadMediaCatalog(query, autoSelectId) {
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

                        if (autoSelectId) {
                            var targetItem = modalMediaList.find(function(m) { return String(m.id) === String(autoSelectId); });
                            if (targetItem) {
                                selectModalItem(targetItem);
                            }
                        }
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
                card.dataset.id = String(item.id);
                if (selectedMediaItem && String(selectedMediaItem.id) === String(item.id)) {
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
                    selectModalItem(item);
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

        function uploadMediaFile(file) {
            if (!file) return;

            var validExts = /\.(jpe?g|png|webp|avif)$/i;
            var isImage = (file.type && file.type.startsWith('image/')) || validExts.test(file.name);
            if (!isImage) {
                alert('Formato de arquivo não suportado. Por favor, envie uma imagem WebP, AVIF, JPEG ou PNG.');
                return;
            }

            var csrf = document.getElementById('csrfToken').value;
            var formData = new FormData();
            formData.append('media_file', file);
            formData.append('_csrf_token', csrf);

            var loadingEl = document.getElementById('modalLoading');
            loadingEl.style.display = 'block';
            loadingEl.innerText = 'Enviando "' + file.name + '" e gerando WebP / AVIF...';

            fetch('/admin/media.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                loadingEl.innerText = 'Carregando fotos...';
                if (data.ok && data.media) {
                    loadMediaCatalog('', data.media.id);
                } else {
                    alert('Erro no upload: ' + (data.error || 'Falha ao processar'));
                    loadingEl.style.display = 'none';
                }
            })
            .catch(function(err) {
                alert('Erro de conexão ao enviar foto: ' + err.message);
                loadingEl.style.display = 'none';
            });
        }

        function uploadFromModal(input) {
            if (!input.files || !input.files[0]) return;
            uploadMediaFile(input.files[0]);
            input.value = '';
        }

        // Configuração de Drag and Drop para o Modal de Mídias
        var dropZone = document.getElementById('modalDropZone');
        if (dropZone) {
            dropZone.addEventListener('click', function(e) {
                if (e.target && e.target.tagName !== 'INPUT') {
                    document.getElementById('modalUploadInput').click();
                }
            });

            ['dragenter', 'dragover'].forEach(function(evt) {
                dropZone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropZone.classList.add('dragover');
                }, false);
            });

            ['dragleave', 'drop'].forEach(function(evt) {
                dropZone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropZone.classList.remove('dragover');
                }, false);
            });

            dropZone.addEventListener('drop', function(e) {
                var dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length > 0) {
                    uploadMediaFile(dt.files[0]);
                }
            }, false);

            var modalDialog = document.querySelector('.media-modal-dialog');
            if (modalDialog) {
                ['dragenter', 'dragover'].forEach(function(evt) {
                    modalDialog.addEventListener(evt, function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropZone.classList.add('dragover');
                    }, false);
                });

                modalDialog.addEventListener('dragleave', function(e) {
                    if (e.target === modalDialog) {
                        dropZone.classList.remove('dragover');
                    }
                }, false);

                modalDialog.addEventListener('drop', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropZone.classList.remove('dragover');
                    var dt = e.dataTransfer;
                    if (dt && dt.files && dt.files.length > 0) {
                        uploadMediaFile(dt.files[0]);
                    }
                }, false);
            }
        }

        function applyMediaSelection() {
            if (!selectedMediaItem) return;

            if (currentModalTarget === 'featured') {
                var url = selectedMediaItem.full_url;
                document.getElementById('featured_image').value = url;
                document.getElementById('featuredPreviewImg').src = url;
                document.getElementById('featuredPreviewBox').style.display = 'block';
            } else if (currentModalTarget === 'content') {
                var safeUrl = escapeHtml(selectedMediaItem.full_url);
                var safeName = escapeHtml(selectedMediaItem.original_name);
                var imgHtml = '<figure><img src="' + safeUrl + '" alt="' + safeName + '"><figcaption>' + safeName + '</figcaption></figure><p></p>';
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

        // Atualização Dinâmica do Botão com base na Data de Publicação/Agendamento
        function updatePublishButtonLabel() {
            var input = document.getElementById('published_at');
            var btn = document.getElementById('btnPublishAction');
            if (!btn) return;
            if (input && input.value) {
                var selected = new Date(input.value);
                var now = new Date();
                if (selected > now) {
                    btn.innerHTML = '📅 Salvar Agendamento';
                    btn.style.background = '#d97706';
                    return;
                }
            }
            btn.style.background = 'var(--primary-accent)';
            btn.innerHTML = '<?= ($post && $post['status'] === 'published') ? 'Atualizar Publicação' : '🚀 Publicar Agora' ?>';
        }

        </script>
<?php require __DIR__ . '/footer.php'; ?>
