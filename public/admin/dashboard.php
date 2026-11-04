<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Dashboard de Publicações
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

Security::startSecureSession($config);

// 1. Verificação de Autenticação
if (empty($_SESSION['user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

// 2. Verificação de Sessão Revogável (is_active e session_version)
$currentUser = Database::fetchOne(
    "SELECT id, name, username, role, is_active, session_version 
     FROM users WHERE id = ? LIMIT 1",
    [(int)$_SESSION['user_id']]
);

if (!$currentUser || empty($currentUser['is_active']) || (int)$currentUser['session_version'] !== (int)$_SESSION['session_version']) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

// Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
        session_destroy();
        header('Location: /admin/login.php');
        exit;
    }
}

// Alternar destaque do artigo na Home
if (isset($_GET['toggle_featured_id']) && Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
    if (\LightPress\Core\Authorization::can($currentUser, \LightPress\Core\Authorization::ACTION_SET_FEATURED)) {
        $toggleId = (int)$_GET['toggle_featured_id'];
        Database::query("UPDATE posts SET is_featured = IF(is_featured = 1, 0, 1), updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$toggleId]);
        $cacheMgr = new \LightPress\Core\CacheManager($config);
        $cacheMgr->purgeUrls(['/']);
        header('Location: /admin/dashboard.php');
        exit;
    }
}

// Listar artigos recentes com estatísticas
$posts = Database::fetchAll(
    "SELECT p.id, p.title, p.slug, p.category, p.status, p.is_featured, p.updated_at, p.published_at,
            u.name as author_name, COALESCE(s.views, 0) as views
     FROM posts p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN post_stats s ON s.post_id = p.id
     ORDER BY p.updated_at DESC
     LIMIT 50"
);

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Editorial - SpeedPress</title>
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
        .user-menu { display: flex; align-items: center; gap: 1rem; font-size: 0.9rem; }
        .user-menu a { color: #dc2626; text-decoration: none; font-weight: 600; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; }
        .btn { display: inline-block; padding: 0.6rem 1.2rem; background: var(--primary-accent); color: white; text-decoration: none; border-radius: var(--radius); font-weight: 600; font-size: 0.9rem; border: none; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.75rem 1rem; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid var(--border); }
        td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--border); }
        tr:last-child td { border-bottom: none; }
        .status-badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .status-published { background: #dcfce7; color: #15803d; }
        .status-draft { background: #f1f5f9; color: #475569; }
        .status-pending_review { background: #fef3c7; color: #b45309; }
        .status-archived { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <header>
        <div style="display: flex; align-items: center; gap: 2rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Admin</a>
            <nav style="display: flex; gap: 1rem; font-size: 0.9rem;">
                <a href="/admin/dashboard.php" style="color: var(--primary); font-weight: 700; text-decoration: none;">Notícias</a>
                <a href="/admin/media.php" style="color: var(--text-muted); text-decoration: none;">Mídias</a>
                <a href="/admin/categories.php" style="color: var(--text-muted); text-decoration: none;">Categorias</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/users.php" style="color: var(--text-muted); text-decoration: none;">Usuários</a>
                <?php endif; ?>
                <a href="/admin/system.php" style="color: var(--text-muted); text-decoration: none;">Diagnóstico</a>
                <a href="/" target="_blank" style="color: var(--text-muted); text-decoration: none;">Ver Site &rarr;</a>
            </nav>
        </div>
        <div class="user-menu">
            <span>Olá, <strong><?= Security::e($currentUser['name']) ?></strong> (<?= Security::e($currentUser['role']) ?>)</span>
            <a href="/admin/profile.php" style="color: var(--primary-accent); text-decoration: none; font-weight: 600;">Meu Perfil</a>
            <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>">Sair</a>
        </div>
    </header>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Gerenciador de Notícias</h1>
            <a href="/admin/editor.php" class="btn">+ Nova Publicação</a>
        </div>

        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Autor</th>
                        <th>Categoria</th>
                        <th>Status</th>
                        <th>Visualizações</th>
                        <th>Última Alteração</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($posts)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                                Nenhuma publicação cadastrada. Clique em "+ Nova Publicação" para começar.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($posts as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= Security::e($p['title']) ?></strong>
                                    <?php if (\LightPress\Core\Authorization::can($currentUser, \LightPress\Core\Authorization::ACTION_SET_FEATURED)): ?>
                                        <a href="/admin/dashboard.php?toggle_featured_id=<?= (int)$p['id'] ?>&_csrf_token=<?= Security::e($csrfToken) ?>" 
                                           title="Clique para alternar destaque na Home" 
                                           style="text-decoration: none; font-size: 0.8rem; margin-left: 0.5rem; color: <?= !empty($p['is_featured']) ? '#d97706' : '#94a3b8' ?>; font-weight: 600;">
                                            <?= !empty($p['is_featured']) ? '★ Destaque' : '☆ Destacar' ?>
                                        </a>
                                    <?php elseif (!empty($p['is_featured'])): ?>
                                        <span style="font-size: 0.75rem; color: #d97706; margin-left: 0.4rem;">★ Destaque</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= Security::e($p['author_name']) ?></td>
                                <td><?= Security::e($p['category']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= Security::e($p['status']) ?>">
                                        <?= Security::e($p['status']) ?>
                                    </span>
                                </td>
                                <td><strong><?= number_format((int)$p['views'], 0, ',', '.') ?></strong></td>
                                <td><?= date('d/m/Y H:i', strtotime($p['updated_at'])) ?></td>
                                <td style="text-align: right;">
                                    <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="color: var(--primary-accent); text-decoration: none; font-weight: 600;">Editar</a>
                                    <?php if ($p['status'] === 'published'): ?>
                                        &bull; <a href="/<?= Security::e($p['slug']) ?>/" target="_blank" style="color: var(--text-muted); text-decoration: none;">Ver</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
