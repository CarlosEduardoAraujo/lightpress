<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Estatísticas e Matérias Mais Lidas
 * Ranking de audiência, desempenho por categoria e produção por autor
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

Security::startSecureSession($config);

// 1. Verificação de Autenticação
if (empty($_SESSION['user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

// 2. Verificação de Sessão Revogável
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

// Top 20 Notícias Mais Lidas
$topPosts = Database::fetchAll(
    "SELECT p.id, p.title, p.slug, p.category, p.featured_image, p.published_at, u.name as author_name, s.views
     FROM posts p
     JOIN post_stats s ON s.post_id = p.id
     JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND s.views > 0
     ORDER BY s.views DESC
     LIMIT 20"
);

// Desempenho por Categoria
$categoryStats = Database::fetchAll(
    "SELECT c.name, c.slug, 
            COUNT(p.id) as total_posts, 
            COALESCE(SUM(s.views), 0) as total_views,
            ROUND(COALESCE(AVG(s.views), 0)) as avg_views
     FROM categories c
     LEFT JOIN posts p ON p.category = c.slug AND p.status = 'published'
     LEFT JOIN post_stats s ON s.post_id = p.id
     WHERE c.is_active = 1
     GROUP BY c.id
     ORDER BY total_views DESC"
);

// Desempenho da Equipe de Redação (Autores)
$authorStats = Database::fetchAll(
    "SELECT u.name, u.role, u.avatar,
            COUNT(p.id) as total_posts,
            COALESCE(SUM(s.views), 0) as total_views,
            ROUND(COALESCE(AVG(s.views), 0)) as avg_views
     FROM users u
     LEFT JOIN posts p ON p.user_id = u.id AND p.status = 'published'
     LEFT JOIN post_stats s ON s.post_id = p.id
     WHERE u.is_active = 1
     GROUP BY u.id
     ORDER BY total_views DESC"
);

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas & Mais Lidas - SpeedPress Admin</title>
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
        body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.5; }
        
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
        }
        .brand { font-size: 1.3rem; font-weight: 800; color: var(--primary); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; align-items: center; }
        .nav-links a { color: var(--text-muted); text-decoration: none; font-weight: 600; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary-accent); }
        
        .user-menu { display: flex; align-items: center; gap: 1rem; font-size: 0.9rem; }
        
        .container { max-width: 1450px; margin: 2.5rem auto; padding: 0 1.5rem; }
        .page-title { font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 1.75rem; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 2.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .card-header { padding: 1.15rem 1.5rem; border-bottom: 1px solid var(--border); background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 1.15rem; font-weight: 800; color: var(--primary); }

        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.8rem 1.25rem; font-weight: 700; color: var(--text-muted); border-bottom: 1px solid var(--border); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; }
        td { padding: 0.95rem 1.25rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-weight: 900;
            font-size: 0.82rem;
        }
        .rank-1 { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .rank-2 { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .rank-3 { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
        .rank-other { background: transparent; color: var(--text-muted); }

        .grid-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        @media (max-width: 900px) {
            .grid-stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div style="display: flex; align-items: center; gap: 2.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Redação</a>
            <nav class="nav-links">
                <a href="/admin/dashboard.php">Notícias</a>
                <a href="/admin/media.php">Mídias</a>
                <a href="/admin/categories.php">Categorias</a>
                <a href="/admin/stats.php" class="active">Mais Lidas 📈</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/users.php">Usuários & Autores</a>
                    <a href="/admin/system.php">Diagnóstico</a>
                <?php endif; ?>
                <a href="/" target="_blank" style="color: var(--primary-accent);">Ver Site &rarr;</a>
            </nav>
        </div>
        <div class="user-menu">
            <span>Olá, <strong><?= Security::e($currentUser['name']) ?></strong></span>
            <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: #dc2626; text-decoration: none; font-weight: 600;">Sair</a>
        </div>
    </header>

    <div class="container">
        <h1 class="page-title">Audiência Editorial & Ranking de Notícias</h1>

        <!-- Top 20 Notícias Mais Lidas -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🏆 Top 20 Notícias Mais Lidas</h2>
                <span style="font-size: 0.82rem; color: var(--text-muted);">Contabilização atômica sem duplicidade por IP</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">#</th>
                        <th>Manchete / Notícia</th>
                        <th>Categoria</th>
                        <th>Autor</th>
                        <th>Data</th>
                        <th style="text-align: right;">Visualizações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topPosts)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                                Nenhuma visualização registrada ainda. As leituras públicas aparecerão aqui automaticamente.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topPosts as $idx => $p): 
                            $rank = $idx + 1;
                            $rankClass = match($rank) {
                                1 => 'rank-1',
                                2 => 'rank-2',
                                3 => 'rank-3',
                                default => 'rank-other'
                            };
                        ?>
                            <tr>
                                <td style="text-align: center;">
                                    <span class="rank-badge <?= $rankClass ?>"><?= $rank ?></span>
                                </td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.85rem;">
                                        <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="flex-shrink: 0; display: block;" title="Editar publicação">
                                            <?php if (!empty($p['featured_image'])): ?>
                                                <img src="<?= Security::e(ImageProcessor::toThumbUrl($p['featured_image'], 'webp')) ?>" 
                                                     alt="" 
                                                     loading="lazy" 
                                                     style="width: 64px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border); display: block; background: #e2e8f0;">
                                            <?php else: ?>
                                                <div style="width: 64px; height: 40px; border-radius: 6px; border: 1px dashed var(--border); background: #f8fafc; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 0.65rem; font-weight: 700; text-transform: uppercase;">
                                                    Sem foto
                                                </div>
                                            <?php endif; ?>
                                        </a>

                                        <div>
                                            <a href="/<?= Security::e($p['slug']) ?>/" target="_blank" style="color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.95rem;">
                                                <?= Security::e($p['title']) ?>
                                            </a>
                                            <span style="margin-left: 0.4rem; font-size: 0.8rem;">
                                                <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="color: var(--primary-accent); text-decoration: none;">[Editar]</a>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span style="background: #eff6ff; color: #2563eb; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.78rem; font-weight: 700;">
                                        <?= Security::e($p['category']) ?>
                                    </span>
                                </td>
                                <td><?= Security::e($p['author_name']) ?></td>
                                <td style="font-size: 0.82rem; color: var(--text-muted);">
                                    <?= date('d/m/Y', strtotime($p['published_at'])) ?>
                                </td>
                                <td style="text-align: right; font-weight: 900; font-size: 1.05rem; color: var(--primary-accent);">
                                    <?= number_format((int)$p['views'], 0, ',', '.') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="grid-stats">
            <!-- Desempenho por Categoria -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📁 Audiência por Categoria</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th style="text-align: center;">Artigos</th>
                            <th style="text-align: right;">Total Views</th>
                            <th style="text-align: right;">Média/Post</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryStats as $c): ?>
                            <tr>
                                <td><strong><?= Security::e($c['name']) ?></strong></td>
                                <td style="text-align: center;"><?= number_format((int)$c['total_posts'], 0, ',', '.') ?></td>
                                <td style="text-align: right; font-weight: 700; color: var(--primary);">
                                    <?= number_format((int)$c['total_views'], 0, ',', '.') ?>
                                </td>
                                <td style="text-align: right; color: var(--text-muted);">
                                    <?= number_format((int)$c['avg_views'], 0, ',', '.') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Desempenho por Autor (Redação) -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">✍️ Desempenho da Redação</h2>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Autor</th>
                            <th>Perfil</th>
                            <th style="text-align: center;">Artigos</th>
                            <th style="text-align: right;">Total Views</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($authorStats as $a): ?>
                            <tr>
                                <td><strong><?= Security::e($a['name']) ?></strong></td>
                                <td>
                                    <span style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">
                                        <?= Security::e($a['role']) ?>
                                    </span>
                                </td>
                                <td style="text-align: center;"><?= number_format((int)$a['total_posts'], 0, ',', '.') ?></td>
                                <td style="text-align: right; font-weight: 700; color: var(--primary-accent);">
                                    <?= number_format((int)$a['total_views'], 0, ',', '.') ?>
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
