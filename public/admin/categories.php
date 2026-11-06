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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Categorias - SpeedPress Admin</title>

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
        
        [data-theme="dark"] th { background: #0f172a; }
        [data-theme="dark"] tr:hover td { background: #162036; }
        [data-theme="dark"] input[type="text"] { background: #0b1120; color: #f1f5f9; border-color: #1e293b; }
        [data-theme="dark"] .btn-secondary { background: #1e293b; color: #f1f5f9; }
        [data-theme="dark"] .btn-secondary:hover { background: #334155; }

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
        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; align-items: center; }
        .nav-links a { color: var(--text-muted); text-decoration: none; font-weight: 600; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary-accent); }

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
        
        .container { max-width: 1200px; margin: 2.5rem auto; padding: 0 1.5rem; }
        .grid { display: grid; grid-template-columns: 1fr 1.8fr; gap: 2rem; align-items: flex-start; }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
        }
        
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--primary); }
        input[type="text"] { width: 100%; padding: 0.7rem 0.85rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; background: var(--bg); color: var(--text); outline: none; }
        input[type="text"]:focus { border-color: var(--primary-accent); }
        
        .btn { padding: 0.7rem 1.2rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: background 0.15s; text-decoration: none; display: inline-block; text-align: center; }
        .btn:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: var(--text); }
        .btn-secondary:hover { background: #cbd5e1; }

        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.8rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; }
        td { padding: 0.9rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    </style>
</head>
<body>
    <header class="site-header">
        <div style="display: flex; align-items: center; gap: 2.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Redação</a>
            <nav class="nav-links">
                <a href="/admin/dashboard.php">Notícias</a>
                <a href="/admin/pages.php">Páginas</a>
                <a href="/admin/media.php">Mídias</a>
                <a href="/admin/categories.php" class="active">Categorias</a>
                <a href="/admin/stats.php">Mais Lidas 📈</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/users.php">Usuários & Autores</a>
                    <a href="/admin/settings.php">Configurações</a>
                    <a href="/admin/system.php">Diagnóstico</a>
                <?php endif; ?>
                <a href="/" target="_blank" style="color: var(--primary-accent);">Ver Site &rarr;</a>
            </nav>
        </div>
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span>Olá, <strong><?= Security::e($currentUser['name']) ?></strong></span>
            <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" id="btnThemeToggle">🌙</button>
            <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: #dc2626; text-decoration: none; font-weight: 600;">Sair</a>
        </div>
    </header>

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
    <script>
        function updateThemeIcon() {
            var current = document.documentElement.getAttribute('data-theme');
            var btn = document.getElementById('btnThemeToggle');
            if (btn) {
                btn.innerText = (current === 'dark') ? '☀️' : '🌙';
            }
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
