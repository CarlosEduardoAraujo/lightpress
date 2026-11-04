<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Gerenciador de Categorias
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
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

if (!$currentUser || !Authorization::can($currentUser, Authorization::ACTION_MANAGE_CATEGORIES)) {
    die("Acesso não autorizado.");
}

$message = '';
$error = '';

// Processar criação de nova categoria
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
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
            try {
                Database::query(
                    "INSERT INTO categories (name, slug, is_active) VALUES (?, ?, 1)",
                    [$name, $slug]
                );
                $message = "Categoria '{$name}' cadastrada com sucesso!";
            } catch (\Throwable $e) {
                $error = "Erro ao cadastrar categoria (slug já em uso?).";
            }
        }
    }
}

// Alternar status da categoria
if (isset($_GET['toggle_id']) && Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
    $toggleId = (int)$_GET['toggle_id'];
    Database::query("UPDATE categories SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?", [$toggleId]);
    header("Location: /admin/categories.php");
    exit;
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
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1.5rem; }
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; }
        input[type="text"] { width: 100%; padding: 0.7rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; }
        .btn { padding: 0.65rem 1.2rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.75rem; border-bottom: 1px solid var(--border); color: var(--text-muted); }
        td { padding: 0.75rem; border-bottom: 1px solid var(--border); }
        .alert-success { background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 0.75rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <header>
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span></a>
            <span style="font-size: 0.9rem; color: var(--text-muted);">&larr; <a href="/admin/dashboard.php" style="color: inherit; text-decoration: none;">Voltar ao Dashboard</a></span>
        </div>
    </header>

    <div class="container">
        <h1 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 1.5rem;">Gerenciador de Categorias</h1>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="grid">
            <div class="card">
                <h2 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem;">Nova Categoria</h2>
                <form method="POST" action="/admin/categories.php">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <div class="form-group">
                        <label for="name">Nome da Categoria</label>
                        <input type="text" id="name" name="name" required placeholder="Ex: Esportes a Motor">
                    </div>
                    <div class="form-group">
                        <label for="slug">Slug (Opcional)</label>
                        <input type="text" id="slug" name="slug" placeholder="esportes-a-motor">
                    </div>
                    <button type="submit" class="btn" style="width: 100%;">Salvar Categoria</button>
                </form>
            </div>

            <div class="card">
                <h2 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem;">Categorias Cadastradas</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Slug</th>
                            <th>Artigos</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><strong><?= Security::e($cat['name']) ?></strong></td>
                                <td><code><?= Security::e($cat['slug']) ?></code></td>
                                <td><?= (int)$cat['total_posts'] ?></td>
                                <td>
                                    <span style="color: <?= $cat['is_active'] ? '#16a34a' : '#dc2626' ?>; font-weight: 600;">
                                        <?= $cat['is_active'] ? 'Ativa' : 'Inativa' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($cat['slug'] !== 'geral'): ?>
                                        <a href="/admin/categories.php?toggle_id=<?= (int)$cat['id'] ?>&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: var(--primary-accent); text-decoration: none; font-size: 0.85rem;">
                                            <?= $cat['is_active'] ? 'Desativar' : 'Ativar' ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">Padrão</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
