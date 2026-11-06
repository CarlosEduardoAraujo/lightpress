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
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $action = $_POST['action'] ?? 'create';
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
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
                            $cacheMgr->purgeUrls([
                                '/',
                                '/categoria/' . $oldSlug . '/',
                                '/categoria/' . $slug . '/'
                            ]);

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
                        $cacheMgr->purgeUrls(['/']);
                        $message = "Categoria '{$name}' cadastrada com sucesso!";
                    } catch (\Throwable $e) {
                        $error = "Erro ao cadastrar categoria.";
                    }
                }
            }
        }
    }
}

// 2. Alternar Status Ativo / Inativo (POST prioritário, GET fallback, ANSI CASE WHEN)
$toggleCatId = (int)($_POST['toggle_id'] ?? $_GET['toggle_id'] ?? 0);
$csrfToggle = $_POST['_csrf_token'] ?? $_GET['_csrf_token'] ?? '';
if ($toggleCatId > 0 && Security::verifyCsrfToken($csrfToggle)) {
    $cat = Database::fetchOne("SELECT slug, is_active FROM categories WHERE id = ? LIMIT 1", [$toggleCatId]);
    if ($cat && $cat['slug'] !== 'geral') {
        Database::query("UPDATE categories SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?", [$toggleCatId]);
        $cacheMgr->purgeUrls(['/', '/categoria/' . $cat['slug'] . '/']);
    }
    header("Location: /admin/categories.php");
    exit;
}

// 3. Exclusão Segura de Categoria (POST prioritário, GET fallback)
$delCatId = (int)($_POST['delete_id'] ?? $_GET['delete_id'] ?? 0);
$csrfDel = $_POST['_csrf_token'] ?? $_GET['_csrf_token'] ?? '';
if ($delCatId > 0 && Security::verifyCsrfToken($csrfDel)) {
    $cat = Database::fetchOne("SELECT slug FROM categories WHERE id = ? LIMIT 1", [$delCatId]);
    
    if ($cat && $cat['slug'] !== 'geral') {
        // Mover posts vinculados para a categoria padrão 'geral' antes de excluir
        Database::transaction(function (\PDO $pdo) use ($delCatId, $cat): void {
            Database::query("UPDATE posts SET category = 'geral' WHERE category = ?", [$cat['slug']]);
            Database::query("DELETE FROM categories WHERE id = ?", [$delCatId]);
        });
        
        $cacheMgr->purgeUrls(['/', '/categoria/' . $cat['slug'] . '/', '/categoria/geral/']);
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
    [data-theme="dark"] th { background: #0f172a; }
    [data-theme="dark"] tr:hover td { background: #162036; }
    [data-theme="dark"] input[type="text"] { background: #0b1120; color: #f1f5f9; border-color: #1e293b; }

    .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; align-items: flex-start; }
    @media (max-width: 900px) {
        .grid { grid-template-columns: 1fr; }
    }
    
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
    .form-group { margin-bottom: 1.25rem; }
    label { display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--primary); }
    input[type="text"] { width: 100%; padding: 0.7rem 0.85rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; background: var(--bg); color: var(--text); outline: none; }
    input[type="text"]:focus { border-color: var(--primary-accent); }

    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
    th { background: #f8fafc; padding: 0.8rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; }
    td { padding: 0.9rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }

    .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
</style>

    <div class="container">
        <h1 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 1.75rem;">
            Gerenciador de Categorias
        </h1>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="grid">
            <!-- Formulário de Criação / Edição -->
            <div class="card">
                <h2 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 1.25rem; color: var(--primary);">
                    <?= $editCategory ? 'Editar Categoria' : 'Nova Categoria' ?>
                </h2>
                <form method="POST" action="/admin/categories.php">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $editCategory ? 'edit' : 'create' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="category_id" value="<?= (int)$editCategory['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="name">Nome da Categoria</label>
                        <input type="text" id="name" name="name" required value="<?= Security::e($editCategory['name'] ?? '') ?>" placeholder="Ex: Fórmula 1">
                    </div>
                    <div class="form-group">
                        <label for="slug">Slug Permanente (URL)</label>
                        <input type="text" id="slug" name="slug" value="<?= Security::e($editCategory['slug'] ?? '') ?>" placeholder="formula-1">
                        <span style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.3rem;">
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
            <div class="card">
                <h2 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 1.25rem; color: var(--primary);">
                    Categorias do Portal
                </h2>
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
                                <td style="text-align: center; font-weight: 700;">
                                    <?= (int)$cat['total_posts'] ?>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; background: <?= $cat['is_active'] ? '#dcfce7' : '#fee2e2' ?>; color: <?= $cat['is_active'] ? '#166534' : '#991b1b' ?>;">
                                        <?= $cat['is_active'] ? 'Ativa' : 'Inativa' ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="/admin/categories.php?edit_id=<?= (int)$cat['id'] ?>" style="color: var(--primary-accent); text-decoration: none; font-weight: 700; font-size: 0.85rem; margin-right: 0.5rem;">
                                        Editar
                                    </a>

                                    <?php if ($cat['slug'] !== 'geral'): ?>
                                        <form method="POST" action="/admin/categories.php" style="display:inline;">
                                            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                            <input type="hidden" name="toggle_id" value="<?= (int)$cat['id'] ?>">
                                            <button type="submit" style="background:none; border:none; cursor:pointer; color: var(--text-muted); font-size: 0.85rem; margin-right: 0.5rem; padding:0;">
                                                <?= $cat['is_active'] ? 'Pausar' : 'Ativar' ?>
                                            </button>
                                        </form>

                                        <form method="POST" action="/admin/categories.php" style="display:inline;" onsubmit="return confirm('Excluir esta categoria? As matérias vinculadas a ela serão movidas com segurança para a categoria Geral.');">
                                            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                            <input type="hidden" name="delete_id" value="<?= (int)$cat['id'] ?>">
                                            <button type="submit" style="background:none; border:none; cursor:pointer; color: #dc2626; font-weight: 700; font-size: 0.85rem; padding:0;" title="Excluir Categoria">
                                                ✕
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/footer.php'; ?>
