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

$currentUser = Security::requireAuth(null, null, $config);

$nowUtc = gmdate('Y-m-d H:i:s');
$period = Security::getSafeString($_GET, 'period', 'all');
if (!in_array($period, ['all', '30d', '7d', '24h'], true)) {
    $period = 'all';
}

$sinceUtc = match ($period) {
    '24h' => gmdate('Y-m-d H:i:s', time() - 86400),
    '7d'  => gmdate('Y-m-d H:i:s', time() - 7 * 86400),
    '30d' => gmdate('Y-m-d H:i:s', time() - 30 * 86400),
    default => null,
};

$dateCondition = $sinceUtc !== null ? " AND p.published_at >= ?" : "";
$topParams = $sinceUtc !== null ? [$nowUtc, $sinceUtc] : [$nowUtc];

// Top 20 Notícias Mais Lidas
$topPosts = Database::fetchAll(
    "SELECT p.id, p.title, p.slug, p.category, p.featured_image, p.published_at, u.name as author_name, s.views
     FROM posts p
     JOIN post_stats s ON s.post_id = p.id
     JOIN users u ON u.id = p.user_id
     WHERE p.status = 'published' AND p.published_at <= ?{$dateCondition} AND s.views > 0
     ORDER BY s.views DESC
     LIMIT 20",
    $topParams
);

// Exportação CSV do ranking filtrado
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audiencia-' . $period . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM para Excel
        fputcsv($out, ['#', 'ID', 'Título', 'Slug', 'Categoria', 'Autor', 'Data Publicação', 'Visualizações'], ';');
        foreach ($topPosts as $idx => $p) {
            fputcsv($out, [
                $idx + 1,
                (int)$p['id'],
                $p['title'],
                '/' . $p['slug'] . '/',
                $p['category'],
                $p['author_name'],
                $p['published_at'],
                (int)$p['views'],
            ], ';');
        }
        fclose($out);
    }
    exit;
}

// Desempenho por Categoria
$catParams = $sinceUtc !== null ? [$nowUtc, $sinceUtc] : [$nowUtc];
$categoryStats = Database::fetchAll(
    "SELECT c.name, c.slug, 
            COUNT(p.id) as total_posts, 
            COALESCE(SUM(s.views), 0) as total_views,
            ROUND(COALESCE(AVG(s.views), 0)) as avg_views
     FROM categories c
     LEFT JOIN posts p ON p.category = c.slug AND p.status = 'published' AND p.published_at <= ?{$dateCondition}
     LEFT JOIN post_stats s ON s.post_id = p.id
     WHERE c.is_active = 1
     GROUP BY c.id
     ORDER BY total_views DESC",
    $catParams
);

// Desempenho da Equipe de Redação (Autores)
$authParams = $sinceUtc !== null ? [$nowUtc, $sinceUtc] : [$nowUtc];
$authorStats = Database::fetchAll(
    "SELECT u.name, u.role, u.avatar,
            COUNT(p.id) as total_posts,
            COALESCE(SUM(s.views), 0) as total_views,
            ROUND(COALESCE(AVG(s.views), 0)) as avg_views
     FROM users u
     LEFT JOIN posts p ON p.user_id = u.id AND p.status = 'published' AND p.published_at <= ?{$dateCondition}
     LEFT JOIN post_stats s ON s.post_id = p.id
     WHERE u.is_active = 1
     GROUP BY u.id
     ORDER BY total_views DESC",
    $authParams
);

$csrfToken = Security::generateCsrfToken();
$pageTitle = 'Audiência & Mais Lidas';
$activePage = 'stats';
require __DIR__ . '/header.php';
?>
<style>
    .card { overflow: hidden; margin-bottom: 2rem; }
    .card-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); background: var(--surface-subtle); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; }
    .card-title { font-size: 1.05rem; font-weight: 800; color: var(--primary); display: inline-flex; align-items: center; gap: 0.5rem; }

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
    .rank-1 { background: var(--warning-bg); color: var(--warning-text); }
    .rank-2 { background: var(--surface-hover); color: var(--text); border: 1px solid var(--border-strong); }
    .rank-3 { background: var(--primary-light); color: var(--primary-accent); }
    .rank-other { background: transparent; color: var(--text-muted); }

    .grid-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    @media (max-width: 960px) { .grid-stats { grid-template-columns: 1fr; } }
</style>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Audiência Editorial & Ranking de Notícias</h1>
                <p class="page-subtitle">Contabilização atômica sem duplicidade por IP</p>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: center;">
                <?php
                    $periods = ['all' => 'Todo o período', '30d' => '30 dias', '7d' => '7 dias', '24h' => '24 horas'];
                    foreach ($periods as $pKey => $pLabel):
                        $isActivePeriod = ($period === $pKey);
                ?>
                    <a href="/admin/stats.php?period=<?= $pKey ?>"
                       class="btn btn-sm <?= $isActivePeriod ? '' : 'btn-secondary' ?>"
                       style="border-radius: var(--radius-pill);">
                        <?= $pLabel ?>
                    </a>
                <?php endforeach; ?>
                <a href="/admin/stats.php?period=<?= urlencode($period) ?>&export=csv" class="btn btn-secondary btn-sm" title="Baixar ranking em CSV">
                    <?= \LightPress\Core\Icon::svg('download', '', 14) ?> Exportar CSV
                </a>
            </div>
        </div>

        <!-- Top 20 Notícias Mais Lidas -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?= \LightPress\Core\Icon::svg('trending_up', '', 18) ?> Top 20 Notícias Mais Lidas</h2>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align: center;">#</th>
                            <th>Manchete / Notícia</th>
                            <th>Categoria</th>
                            <th>Autor</th>
                            <th>Data</th>
                            <th style="text-align: right; min-width: 140px;">Visualizações</th>
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
                            <?php
                                $maxViews = max(1, (int)($topPosts[0]['views'] ?? 1));
                                foreach ($topPosts as $idx => $p):
                                    $rank = $idx + 1;
                                    $rankClass = match($rank) {
                                        1 => 'rank-1',
                                        2 => 'rank-2',
                                        3 => 'rank-3',
                                        default => 'rank-other'
                                    };
                                    $pct = min(100, round(((int)$p['views'] / $maxViews) * 100));
                            ?>
                                <tr>
                                    <td class="tabular-nums" style="text-align: center;">
                                        <span class="rank-badge <?= $rankClass ?>"><?= $rank ?></span>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.85rem;">
                                            <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="flex-shrink: 0; display: block;" title="Editar publicação">
                                                <?php if (!empty($p['featured_image'])): ?>
                                                    <img src="<?= Security::e(ImageProcessor::toThumbUrl($p['featured_image'], 'avif')) ?>"
                                                         alt=""
                                                         loading="lazy"
                                                         style="width: 64px; height: 40px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border); display: block; background: var(--surface-hover);">
                                                <?php else: ?>
                                                    <div style="width: 64px; height: 40px; border-radius: var(--radius-sm); border: 1px dashed var(--border-strong); background: var(--surface-subtle); display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                                                        <?= \LightPress\Core\Icon::svg('image', '', 16) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </a>

                                            <div>
                                                <a href="/<?= Security::e($p['slug']) ?>/" target="_blank" style="color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.94rem;">
                                                    <?= Security::e($p['title']) ?>
                                                </a>
                                                <span style="margin-left: 0.4rem; font-size: 0.78rem;">
                                                    <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="color: var(--primary-accent); text-decoration: none; font-weight: 600;">[Editar]</a>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: var(--primary-light); color: var(--primary-accent);">
                                            <?= Security::e($p['category']) ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 0.86rem;"><?= Security::e($p['author_name']) ?></td>
                                    <td class="tabular-nums" style="font-size: 0.82rem; color: var(--text-muted); white-space: nowrap;">
                                        <?= date('d/m/Y', strtotime($p['published_at'])) ?>
                                    </td>
                                    <td class="tabular-nums" style="text-align: right;">
                                        <div style="font-weight: 900; font-size: 1rem; color: var(--primary-accent);">
                                            <?= number_format((int)$p['views'], 0, ',', '.') ?>
                                        </div>
                                        <div class="lp-progress" style="max-width: 110px; margin-left: auto;">
                                            <div class="lp-progress-bar" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
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
    <?php require __DIR__ . '/footer.php'; ?>
