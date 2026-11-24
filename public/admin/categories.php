<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Gerenciador de Categorias
 * Criação, edição, exclusão segura e expurgo automático de cache
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Core\CacheManager;
use LightPress\Core\ObjectCache;

$currentUser = Security::requireAuth(Authorization::ACTION_MANAGE_CATEGORIES);

$cacheMgr = new CacheManager($config);
$message = '';
$error = '';

$editCategory = null;
$editId = (int)($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $editCategory = Database::fetchOne("SELECT * FROM categories WHERE id = ? LIMIT 1", [$editId]);
}

// 1. Processar Criação ou Edição
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken(Security::getSafeString($_POST, '_csrf_token'))) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $action = Security::getSafeString($_POST, 'action', 'create');
        $name = Security::getSafeString($_POST, 'name');
        $slug = Security::getSafeString($_POST, 'slug');
        if (empty($slug) && !empty($name)) {
            $slug = Security::slugify($name);
        } else {
            $slug = Security::slugify($slug);
        }

        if (empty($name) || empty($slug)) {
            $error = "Nome e slug são obrigatórios.";
        } else {
            if ($action === 'edit') {
                $targetId = (int)($_POST['category_id'] ?? 0);
                $existing = Database::fetchOne("SELECT * FROM categories WHERE id = ? LIMIT 1", [$targetId]);
                
                if (!$existing) {
                    $error = "Categoria não encontrada.";
                } else {
                    // Validar se novo slug já existe em outra categoria
                    $duplicate = Database::fetchOne(
                        "SELECT id FROM categories WHERE slug = ? AND id != ? LIMIT 1",
                        [$slug, $targetId]
                    );

                    if ($duplicate) {
                        $error = "O slug '{$slug}' já está em uso por outra categoria.";
                    } else {
                        try {
                            $oldSlug = $existing['slug'];
                            Database::transaction(function (\PDO $pdo) use ($targetId, $name, $slug, $oldSlug): void {
                                Database::query(
                                    "UPDATE categories SET name = ?, slug = ? WHERE id = ?",
                                    [$name, $slug, $targetId]
                                );
                                
                                // Se o slug mudou, atualiza os posts vinculados
                                if ($oldSlug !== $slug) {
                                    Database::query("UPDATE posts SET category = ? WHERE category = ?", [$slug, $oldSlug]);
                                }
                            });

                            // Expurga cache da Home e das categorias antiga e nova
                            ObjectCache::delete('all_active_categories', 'categories');
                            ObjectCache::delete('api_categories_list', 'categories');
                            ObjectCache::delete('sitemap_main_categories', 'categories');
                            ObjectCache::flushGroup('categories');
                            if ($oldSlug !== $slug) {
                                ObjectCache::flushGroup('post_items');
                                ObjectCache::flushGroup('posts');
                            }
                            $purgeUrls = [
                                '/',
                                '/sitemap.xml',
                                '/sitemap-main.xml',
                                '/categoria/' . $oldSlug . '/',
                                '/categoria/' . $oldSlug . '/feed.xml',
                                '/categoria/' . $oldSlug . '/feed',
                                '/categoria/' . $oldSlug . '/feed/',
                                '/categoria/' . $slug . '/',
                                '/categoria/' . $slug . '/feed.xml',
                                '/categoria/' . $slug . '/feed',
                                '/categoria/' . $slug . '/feed/',
                            ];
                            $jobId = $cacheMgr->recordPurgeJob(null, 'category_update', $purgeUrls);
                            $purgeResults = $cacheMgr->purgeUrls($purgeUrls);
                            if ($jobId > 0 && (empty($purgeResults) || !in_array(false, $purgeResults, true))) {
                                $cacheMgr->completePurgeJob($jobId);
                            }

                            $message = "Categoria '{$name}' atualizada com sucesso!";
                            $editCategory = null;
                        } catch (\Throwable $e) {
                            $error = "Erro ao atualizar categoria: " . $e->getMessage();
                        }
                    }
                }
            } else {
                // Criação
                $duplicate = Database::fetchOne("SELECT id FROM categories WHERE slug = ? LIMIT 1", [$slug]);
                if ($duplicate) {
                    $error = "O slug '{$slug}' já está em uso.";
                } else {
                    try {
                        Database::query(
                            "INSERT INTO categories (name, slug, is_active) VALUES (?, ?, 1)",
                            [$name, $slug]
                        );
                        ObjectCache::delete('all_active_categories', 'categories');
                        ObjectCache::delete('api_categories_list', 'categories');
                        ObjectCache::delete('sitemap_main_categories', 'categories');
                        ObjectCache::flushGroup('categories');
                        $purgeUrls = ['/', '/sitemap.xml', '/sitemap-main.xml'];
                        $jobId = $cacheMgr->recordPurgeJob(null, 'category_create', $purgeUrls);
                        $purgeResults = $cacheMgr->purgeUrls($purgeUrls);
                        if ($jobId > 0 && (empty($purgeResults) || !in_array(false, $purgeResults, true))) {
                            $cacheMgr->completePurgeJob($jobId);
                        }
                        $message = "Categoria '{$name}' cadastrada com sucesso!";
                    } catch (\Throwable $e) {
                        $error = "Erro ao cadastrar categoria.";
                    }
                }
            }
        }
    }
}

// 2. Alternar Status Ativo / Inativo (Estritamente via POST com CSRF)
$toggleCatId = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int)($_POST['toggle_id'] ?? 0) : 0;
$csrfToggle = Security::getSafeString($_POST, '_csrf_token');
if ($toggleCatId > 0 && Security::verifyCsrfToken($csrfToggle)) {
    $cat = Database::fetchOne("SELECT slug, is_active FROM categories WHERE id = ? LIMIT 1", [$toggleCatId]);
    if ($cat && $cat['slug'] !== 'geral') {
        Database::query("UPDATE categories SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?", [$toggleCatId]);
        ObjectCache::delete('all_active_categories', 'categories');
        ObjectCache::delete('api_categories_list', 'categories');
        ObjectCache::delete('sitemap_main_categories', 'categories');
        ObjectCache::flushGroup('categories');
        ObjectCache::flushGroup('posts');
        $purgeUrls = [
            '/',
            '/sitemap.xml',
            '/sitemap-main.xml',
            '/categoria/' . $cat['slug'] . '/',
            '/categoria/' . $cat['slug'] . '/feed.xml',
            '/categoria/' . $cat['slug'] . '/feed',
            '/categoria/' . $cat['slug'] . '/feed/',
        ];
        $jobId = $cacheMgr->recordPurgeJob(null, 'category_toggle', $purgeUrls);
        $purgeResults = $cacheMgr->purgeUrls($purgeUrls);
        if ($jobId > 0 && (empty($purgeResults) || !in_array(false, $purgeResults, true))) {
            $cacheMgr->completePurgeJob($jobId);
        }
    }
    header("Location: /admin/categories.php");
    exit;
}

// 3. Exclusão Segura de Categoria (Estritamente via POST com CSRF)
$delCatId = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int)($_POST['delete_id'] ?? 0) : 0;
$csrfDel = Security::getSafeString($_POST, '_csrf_token');
if ($delCatId > 0 && Security::verifyCsrfToken($csrfDel)) {
    $cat = Database::fetchOne("SELECT slug FROM categories WHERE id = ? LIMIT 1", [$delCatId]);
    
    if ($cat && $cat['slug'] !== 'geral') {
        // Mover posts vinculados para a categoria padrão 'geral' antes de excluir
        Database::transaction(function (\PDO $pdo) use ($delCatId, $cat): void {
            Database::query("UPDATE posts SET category = 'geral' WHERE category = ?", [$cat['slug']]);
            Database::query("DELETE FROM categories WHERE id = ?", [$delCatId]);
        });
        
        ObjectCache::delete('all_active_categories', 'categories');
        ObjectCache::delete('api_categories_list', 'categories');
        ObjectCache::delete('sitemap_main_categories', 'categories');
        ObjectCache::flushGroup('categories');
        ObjectCache::flushGroup('post_items');
        ObjectCache::flushGroup('posts');
        $purgeUrls = [
            '/',
            '/sitemap.xml',
            '/sitemap-main.xml',
            '/categoria/' . $cat['slug'] . '/',
            '/categoria/' . $cat['slug'] . '/feed.xml',
            '/categoria/' . $cat['slug'] . '/feed',
            '/categoria/' . $cat['slug'] . '/feed/',
            '/categoria/geral/',
            '/categoria/geral/feed.xml',
            '/categoria/geral/feed',
            '/categoria/geral/feed/',
        ];
        $jobId = $cacheMgr->recordPurgeJob(null, 'category_delete', $purgeUrls);
        $purgeResults = $cacheMgr->purgeUrls($purgeUrls);
        if ($jobId > 0 && (empty($purgeResults) || !in_array(false, $purgeResults, true))) {
            $cacheMgr->completePurgeJob($jobId);
        }
        header("Location: /admin/categories.php");
        exit;
    }
}

$categories = Database::fetchAll(
    "SELECT c.*, COUNT(p.id) as total_posts 
     FROM categories c
     LEFT JOIN posts p ON p.category = c.slug
     GROUP BY c.id
     ORDER BY c.name ASC"
);

$csrfToken = Security::generateCsrfToken();
$pageTitle = 'Gerenciador de Categorias';
$activePage = 'categories';
require __DIR__ . '/header.php';
?>
<style>
    .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; align-items: flex-start; }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    .card { padding: 1.5rem; }
    .form-group { margin-bottom: 1.15rem; }
    .form-label { display: block; font-size: 0.84rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--primary); }
</style>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Gerenciador de Categorias</h1>
                <p class="page-subtitle">Organize as editorias e URLs de seção do portal</p>
            </div>
        </div>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="grid">
            <!-- Formulário de Criação / Edição -->
            <div class="card">
                <h2 style="font-size: 1.12rem; font-weight: 800; margin-bottom: 1.15rem; color: var(--primary);">
                    <?= $editCategory ? 'Editar Categoria' : 'Nova Categoria' ?>
                </h2>
                <form method="POST" action="/admin/categories.php">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $editCategory ? 'edit' : 'create' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="category_id" value="<?= (int)$editCategory['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label" for="name">Nome da Categoria</label>
                        <input type="text" id="name" name="name" class="form-input" style="width:100%;" required value="<?= Security::e($editCategory['name'] ?? '') ?>" placeholder="Ex: Fórmula 1">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="slug">Slug Permanente (URL)</label>
                        <input type="text" id="slug" name="slug" class="form-input" style="width:100%;" value="<?= Security::e($editCategory['slug'] ?? '') ?>" placeholder="formula-1">
                        <span style="font-size: 0.76rem; color: var(--text-muted); display: block; margin-top: 0.35rem;">
                            URL pública: <code>/categoria/<?= $editCategory ? Security::e($editCategory['slug']) : '{slug}' ?>/</code>
                        </span>
                    </div>

                    <div style="display: flex; gap: 0.5rem;">
                        <button type="submit" class="btn" style="flex: 1;">
                            <?= $editCategory ? 'Salvar Alterações' : 'Cadastrar Categoria' ?>
                        </button>
                        <?php if ($editCategory): ?>
                            <a href="/admin/categories.php" class="btn btn-secondary">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Listagem de Categorias -->
            <div class="table-card">
                <div style="padding: 1.15rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 1.05rem; font-weight: 800; color: var(--primary);">Categorias do Portal</h2>
                    <span class="badge badge-neutral tabular-nums"><?= count($categories) ?></span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Slug</th>
                                <th style="text-align: center;">Artigos</th>
                                <th>Status</th>
                                <th style="text-align: right;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <strong><?= Security::e($cat['name']) ?></strong>
                                        <?php if ($cat['slug'] === 'geral'): ?>
                                            <span style="font-size: 0.72rem; color: var(--text-muted); margin-left: 0.3rem;">(Padrão)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= Security::e($cat['slug']) ?></code></td>
                                    <td class="tabular-nums" style="text-align: center; font-weight: 700;">
                                        <?= (int)$cat['total_posts'] ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $cat['is_active'] ? 'badge-ok' : 'badge-err' ?>">
                                            <?= $cat['is_active'] ? 'Ativa' : 'Inativa' ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                            <a href="/admin/categories.php?edit_id=<?= (int)$cat['id'] ?>" class="btn btn-sm" style="background: var(--primary-light); color: var(--primary-accent);">
                                                <?= \LightPress\Core\Icon::svg('edit', '', 13) ?>
                                                Editar
                                            </a>

                                            <?php if ($cat['slug'] !== 'geral'): ?>
                                                <form method="POST" action="/admin/categories.php" style="display:inline;">
                                                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                    <input type="hidden" name="toggle_id" value="<?= (int)$cat['id'] ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm">
                                                        <?= $cat['is_active'] ? 'Pausar' : 'Ativar' ?>
                                                    </button>
                                                </form>

                                                <form method="POST" action="/admin/categories.php" style="display:inline;" data-confirm="Excluir esta categoria? As matérias vinculadas a ela serão movidas com segurança para a categoria Geral." data-confirm-title="Excluir Categoria" data-confirm-btn="Excluir">
                                                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                    <input type="hidden" name="delete_id" value="<?= (int)$cat['id'] ?>">
                                                    <button type="submit" class="btn btn-danger-soft btn-sm btn-icon" title="Excluir Categoria">
                                                        <?= \LightPress\Core\Icon::svg('trash', '', 14) ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/footer.php'; ?>
