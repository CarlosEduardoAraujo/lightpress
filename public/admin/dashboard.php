<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Dashboard de Publicações
 * Filtros por status, categoria, autor, busca textual, paginação e métricas
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\CacheManager;
use LightPress\Core\ObjectCache;
use LightPress\Core\Hook;

$currentUser = Security::requireAuth();

// Auto-promoção de matérias agendadas cujo horário já foi atingido
\LightPress\Core\PostService::promoteScheduledPosts(true);

$cacheMgr = new CacheManager($config);

// Logout seguro (estritamente via POST com CSRF)
$isLogout = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout';
if ($isLogout) {
    $tokenLogout = $_POST['_csrf_token'] ?? '';
    if (Security::verifyCsrfToken($tokenLogout)) {
        Security::destroySession();
        header('Location: /admin/login.php?logged_out=1');
        exit;
    }
}

// Alternar destaque do artigo na Home (estritamente via POST com CSRF; suporta AJAX + fallback)
$toggleFeaturedId = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int)($_POST['toggle_featured_id'] ?? 0) : 0;
$tokenToggle = $_POST['_csrf_token'] ?? '';
if ($toggleFeaturedId > 0 && Security::verifyCsrfToken($tokenToggle)) {
    if (Authorization::can($currentUser, Authorization::ACTION_SET_FEATURED)) {
        Database::query("UPDATE posts SET is_featured = CASE WHEN is_featured = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$toggleFeaturedId]);
        $newFeatured = (int)Database::fetchValue("SELECT is_featured FROM posts WHERE id = ?", [$toggleFeaturedId]);
        ObjectCache::flushGroup('posts');
        $cacheMgr->purgeUrls(['/']);

        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'is_featured' => $newFeatured === 1]);
            exit;
        }

        $queryParams = $_GET;
        unset($queryParams['toggle_featured_id'], $queryParams['_csrf_token']);
        $qs = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
        header('Location: /admin/dashboard.php' . $qs);
        exit;
    }
}

// Excluir publicação (Estritamente via POST com CSRF)
$delPostId = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int)($_POST['delete_id'] ?? 0) : 0;
$tokenDel = $_POST['_csrf_token'] ?? '';
if ($delPostId > 0 && Security::verifyCsrfToken($tokenDel)) {
    $targetPost = Database::fetchOne("SELECT id, slug, category, tags, user_id FROM posts WHERE id = ? LIMIT 1", [$delPostId]);
    
    if ($targetPost) {
        if (Authorization::can($currentUser, Authorization::ACTION_DELETE_POST, $targetPost)) {
            $totalPublished = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE status = 'published' AND published_at <= CURRENT_TIMESTAMP");
            $maxParts = max(1, (int)ceil(($totalPublished + 1) / 5000));
            $sitemapPartUrls = [];
            for ($pIdx = 1; $pIdx <= $maxParts; $pIdx++) {
                $sitemapPartUrls[] = "/sitemap-posts-{$pIdx}.xml";
            }

            $affectedUrls = array_merge([
                '/',
                '/blog/',
                '/' . $targetPost['slug'] . '/',
                '/noticia/' . $targetPost['slug'] . '/',
                '/categoria/' . $targetPost['category'] . '/',
                '/categoria/' . $targetPost['category'] . '/page/2/',
                '/categoria/' . $targetPost['category'] . '/feed.xml',
                '/categoria/' . $targetPost['category'] . '/feed',
                '/categoria/' . $targetPost['category'] . '/feed/',
                '/feed.xml',
                '/feed',
                '/feed/',
                '/sitemap.xml',
                '/sitemap-news.xml',
                '/sitemap-main.xml',
                '/page/2/',
                '/blog/page/2/',
            ], $sitemapPartUrls);

            if (!empty($targetPost['tags'])) {
                $tagItems = explode(',', (string)$targetPost['tags']);
                foreach ($tagItems as $t) {
                    $tSlug = Security::slugify(trim($t));
                    if (!empty($tSlug)) {
                        $affectedUrls[] = '/tag/' . $tSlug . '/';
                    }
                }
            }

            $jobId = 0;
            Database::transaction(function (\PDO $pdo) use ($delPostId, $affectedUrls, $cacheMgr, &$jobId): void {
                Database::query("DELETE FROM post_stats WHERE post_id = ?", [$delPostId]);
                Database::query("DELETE FROM post_revisions WHERE post_id = ?", [$delPostId]);
                Database::query("DELETE FROM posts WHERE id = ?", [$delPostId]);
                
                $jobId = $cacheMgr->recordPurgeJob($delPostId, 'post_deletion', $affectedUrls);
            });

            // Invalidação imediata do cache de objetos Redis (CH-02)
            ObjectCache::delete("post_slug:{$targetPost['slug']}", 'post_items');
            ObjectCache::flushGroup('posts');

            // Disparar hook de exclusão de post
            Hook::doAction('post_deleted', $delPostId, $targetPost);

            try {
                $purgeResults = $cacheMgr->purgeUrls($affectedUrls);
                if ($jobId > 0 && (empty($purgeResults) || !in_array(false, $purgeResults, true))) {
                    $cacheMgr->completePurgeJob($jobId);
                }
            } catch (\Throwable $e) {
                error_log("[Dashboard Post Delete Purge Error] " . $e->getMessage());
            }

            $queryParams = $_GET;
            unset($queryParams['delete_id'], $queryParams['_csrf_token']);
            $qs = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
            header('Location: /admin/dashboard.php' . $qs);
            exit;
        }
    }
}

// Métricas do Topo (inclui agendadas cujo horário já foi atingido como publicadas)
$nowUtc = gmdate('Y-m-d H:i:s');
$totalPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts");
$scheduledPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE (status = 'scheduled' AND published_at > ?) OR (status = 'published' AND published_at > ?)", [$nowUtc, $nowUtc]);
$publishedPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE (status = 'published' AND (published_at IS NULL OR published_at <= ?)) OR (status = 'scheduled' AND published_at <= ?)", [$nowUtc, $nowUtc]);
$draftPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE status IN ('draft', 'pending_review')");
$totalViews = (int)Database::fetchValue("SELECT COALESCE(SUM(views), 0) FROM post_stats");

// Carregar Categorias e Autores para os filtros
$categories = Database::fetchAll("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY name ASC");
$authors = Database::fetchAll("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name ASC");

// Parâmetros de Filtro
$filterStatus = Security::getSafeString($_GET, 'status');
$filterCategory = Security::getSafeString($_GET, 'category');
$filterAuthor = (int)($_GET['author_id'] ?? 0);
$filterSearch = Security::getSafeString($_GET, 'q');
$filterFeatured = !empty($_GET['featured']) ? 1 : 0;

$whereClauses = ["1=1"];
$params = [];

if ($filterStatus === 'scheduled') {
    $whereClauses[] = "((p.status = 'scheduled' AND p.published_at > ?) OR (p.status = 'published' AND p.published_at > ?))";
    $params[] = $nowUtc;
    $params[] = $nowUtc;
} elseif ($filterStatus === 'published') {
    $whereClauses[] = "((p.status = 'published' AND (p.published_at IS NULL OR p.published_at <= ?)) OR (p.status = 'scheduled' AND p.published_at <= ?))";
    $params[] = $nowUtc;
    $params[] = $nowUtc;
} elseif (!empty($filterStatus)) {
    $whereClauses[] = "p.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterCategory)) {
    $whereClauses[] = "p.category = ?";
    $params[] = $filterCategory;
}

if ($filterAuthor > 0) {
    $whereClauses[] = "p.user_id = ?";
    $params[] = $filterAuthor;
}

if (!empty($filterSearch)) {
    // Busca FULLTEXT booleana de alta performance (InnoDB) com fallback para LIKE
    $driver = $config['database']['driver'] ?? 'mysql';
    $words = array_filter(preg_split('/\s+/', $filterSearch));
    $boolQuery = '';
    foreach ($words as $w) {
        $cleanW = preg_replace('/[+\-><()~*\"@]+/', '', $w);
        if (strlen($cleanW) >= 2) {
            $boolQuery .= "+{$cleanW}* ";
        }
    }
    $boolQuery = trim($boolQuery);

    if ($driver === 'mysql' && !empty($boolQuery)) {
        $whereClauses[] = "MATCH(p.title, p.summary, p.tags) AGAINST(? IN BOOLEAN MODE)";
        $params[] = $boolQuery;
    } else {
        $safeSearch = Security::escapeLike($filterSearch);
        $whereClauses[] = "(p.title LIKE ? OR p.summary LIKE ? OR p.tags LIKE ?)";
        $params[] = '%' . $safeSearch . '%';
        $params[] = '%' . $safeSearch . '%';
        $params[] = '%' . $safeSearch . '%';
    }
}

if ($filterFeatured === 1) {
    $whereClauses[] = "p.is_featured = 1";
}

$whereSql = implode(" AND ", $whereClauses);

// Paginação
$page = min(10000, max(1, (int)($_GET['page'] ?? 1)));
$perPage = 25;

$filteredCount = (int)Database::fetchValue(
    "SELECT COUNT(*) FROM posts p WHERE {$whereSql}",
    $params
);
$totalPages = max(1, (int)ceil($filteredCount / $perPage));
if ($page > $totalPages && $filteredCount > 0) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$querySql = "SELECT p.id, p.user_id, p.title, p.slug, p.category, p.tags, p.featured_image, p.status, p.is_featured, p.updated_at, p.published_at,
                    u.name as author_name, COALESCE(s.views, 0) as views
             FROM posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN post_stats s ON s.post_id = p.id
             WHERE {$whereSql}
             ORDER BY COALESCE(p.published_at, p.updated_at) DESC, p.id DESC
             LIMIT ? OFFSET ?";

$queryParams = array_merge($params, [$perPage, $offset]);
$posts = Database::fetchAll($querySql, $queryParams);

$csrfToken = Security::generateCsrfToken();

$displayTz = new \DateTimeZone($config['app']['display_timezone'] ?? 'America/Sao_Paulo');
$formatDisplayDate = function(?string $dateStr, string $format = 'd/m/Y \à\s H:i') use ($displayTz): string {
    if (empty($dateStr)) return '';
    try {
        $dt = new \DateTime($dateStr, new \DateTimeZone('UTC'));
        $dt->setTimezone($displayTz);
        return $dt->format($format);
    } catch (\Throwable) {
        return date($format, strtotime($dateStr));
    }
};

// Função auxiliar para montar links de paginação e filtros
function buildFilterUrl(array $overrides = []): string {
    $current = $_GET;
    unset($current['delete_id'], $current['toggle_featured_id'], $current['_csrf_token']);
    $merged = array_merge($current, $overrides);
    // Remove chaves vazias
    $cleaned = array_filter($merged, fn($v) => $v !== '' && $v !== null && $v !== 0);
    return '/admin/dashboard.php' . (!empty($cleaned) ? '?' . http_build_query($cleaned) : '');
}

$pageTitle = 'Painel Editorial';
$activePage = 'dashboard';
require __DIR__ . '/header.php';
?>
<style>
    /* Cards de Métricas */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 1rem;
        margin-bottom: 1.75rem;
    }
    @media (max-width: 1400px) { .metrics-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 900px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 560px) { .metrics-grid { grid-template-columns: 1fr; } }

    .metric-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.15rem 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        box-shadow: var(--shadow-sm);
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
        border-color: var(--border-strong);
    }
    .metric-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
    }
    .metric-icon {
        width: 32px;
        height: 32px;
        border-radius: var(--radius-sm);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .metric-label { font-size: 0.76rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
    .metric-value { font-size: 1.75rem; font-weight: 900; color: var(--primary); font-variant-numeric: tabular-nums; line-height: 1.15; }
    .metric-sub { font-size: 0.78rem; color: var(--text-muted); }

    /* Barra de Filtros */
    .filter-bar {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 0.9rem 1.15rem;
        margin-bottom: 1.35rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        align-items: center;
        justify-content: space-between;
        box-shadow: var(--shadow-sm);
    }
    .filter-group {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
        align-items: center;
        flex: 1;
    }
    .filter-bar .form-input { min-width: 220px; flex: 1; max-width: 320px; }

    .star-featured {
        font-size: 0.75rem;
        padding: 0.2rem 0.55rem;
        border-radius: var(--radius-pill);
        text-decoration: none;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 0.28rem;
        transition: all 0.15s ease;
    }
    .star-active { background: var(--warning-bg); color: var(--warning-text); border: 1px solid rgba(217, 119, 6, 0.25); }
    .star-inactive { background: var(--surface-hover); color: var(--text-muted); border: 1px solid var(--border); }
    .star-inactive:hover { color: var(--warning-text); border-color: rgba(217, 119, 6, 0.35); }

    /* Paginação */
    .pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        padding: 1rem 1.25rem;
        background: var(--surface);
        border-top: 1px solid var(--border);
        font-size: 0.85rem;
        color: var(--text-muted);
    }
    .page-links { display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; }
    .page-num {
        padding: 0.38rem 0.72rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        text-decoration: none;
        color: var(--text);
        font-weight: 600;
        font-size: 0.83rem;
        background: var(--surface);
        transition: background 0.15s, border-color 0.15s;
    }
    .page-num.active { background: var(--primary-accent); color: #fff; border-color: var(--primary-accent); }
    .page-num:hover:not(.active) { background: var(--surface-hover); }
</style>

    <div class="container">
        <!-- Cards de Métricas em Tempo Real -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-top">
                    <span class="metric-label">Total de Artigos</span>
                    <span class="metric-icon" style="background: var(--surface-hover); color: var(--primary);"><?= \LightPress\Core\Icon::svg('newspaper', '', 17) ?></span>
                </div>
                <span class="metric-value"><?= number_format($totalPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Acervo completo no banco</span>
            </div>
            <div class="metric-card">
                <div class="metric-top">
                    <span class="metric-label">Publicadas</span>
                    <span class="metric-icon" style="background: var(--success-bg); color: var(--success-text);"><?= \LightPress\Core\Icon::svg('check', '', 17) ?></span>
                </div>
                <span class="metric-value" style="color: var(--success-text);"><?= number_format($publishedPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Disponíveis ao público</span>
            </div>
            <div class="metric-card">
                <div class="metric-top">
                    <span class="metric-label">Agendadas</span>
                    <span class="metric-icon" style="background: var(--warning-bg); color: var(--warning-text);"><?= \LightPress\Core\Icon::svg('calendar_today', '', 16) ?></span>
                </div>
                <span class="metric-value" style="color: var(--warning-text);"><?= number_format($scheduledPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Publicação futura automática</span>
            </div>
            <div class="metric-card">
                <div class="metric-top">
                    <span class="metric-label">Rascunhos / Revisão</span>
                    <span class="metric-icon" style="background: var(--info-bg); color: var(--info-text);"><?= \LightPress\Core\Icon::svg('edit_note', '', 17) ?></span>
                </div>
                <span class="metric-value" style="color: var(--info-text);"><?= number_format($draftPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Em preparação editorial</span>
            </div>
            <div class="metric-card">
                <div class="metric-top">
                    <span class="metric-label">Visualizações</span>
                    <span class="metric-icon" style="background: var(--primary-light); color: var(--primary-accent);"><?= \LightPress\Core\Icon::svg('trending_up', '', 17) ?></span>
                </div>
                <span class="metric-value" style="color: var(--primary-accent);"><?= number_format($totalViews, 0, ',', '.') ?></span>
                <span class="metric-sub">Leituras reais registradas</span>
            </div>
        </div>

        <div class="page-header">
            <div>
                <h1 class="page-title">Gerenciador de Notícias</h1>
                <p class="page-subtitle">
                    Exibindo <strong class="tabular-nums"><?= number_format($filteredCount, 0, ',', '.') ?></strong> <?= $filteredCount === 1 ? 'matéria' : 'matérias' ?> encontradas
                </p>
            </div>
            <a href="/admin/editor.php" class="btn">
                <?= \LightPress\Core\Icon::svg('plus', '', 17) ?>
                Nova Publicação
            </a>
        </div>

        <!-- Barra de Filtros e Busca -->
        <form method="GET" action="/admin/dashboard.php" class="filter-bar">
            <div class="filter-group">
                <input type="text" name="q" value="<?= Security::e($filterSearch) ?>" placeholder="Buscar por título, resumo ou tag... (Ctrl+K)" class="form-input">

                <select name="status" class="form-select">
                    <option value="">Todos os Status</option>
                    <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Publicados</option>
                    <option value="scheduled" <?= $filterStatus === 'scheduled' ? 'selected' : '' ?>>Agendados</option>
                    <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Rascunhos</option>
                    <option value="pending_review" <?= $filterStatus === 'pending_review' ? 'selected' : '' ?>>Em Revisão</option>
                    <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Arquivados</option>
                </select>

                <select name="category" class="form-select">
                    <option value="">Todas as Categorias</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= Security::e($cat['slug']) ?>" <?= $filterCategory === $cat['slug'] ? 'selected' : '' ?>>
                            <?= Security::e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if (in_array($currentUser['role'], ['admin', 'editor'], true)): ?>
                    <select name="author_id" class="form-select">
                        <option value="">Todos os Autores</option>
                        <?php foreach ($authors as $auth): ?>
                            <option value="<?= (int)$auth['id'] ?>" <?= $filterAuthor === (int)$auth['id'] ? 'selected' : '' ?>>
                                <?= Security::e($auth['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <label style="display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; font-weight: 600; cursor: pointer; white-space: nowrap;">
                    <input type="checkbox" name="featured" value="1" <?= $filterFeatured ? 'checked' : '' ?>>
                    Apenas Destaques
                </label>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn btn-sm">
                    <?= \LightPress\Core\Icon::svg('search', '', 14) ?>
                    Filtrar
                </button>
                <?php if (!empty($filterStatus) || !empty($filterCategory) || !empty($filterAuthor) || !empty($filterSearch) || !empty($filterFeatured)): ?>
                    <a href="/admin/dashboard.php" class="btn btn-secondary btn-sm" title="Limpar todos os filtros">Limpar</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Tabela de Publicações -->
        <div class="table-card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 42%;">Título / Manchete</th>
                            <th>Autor</th>
                            <th>Categoria</th>
                            <th>Status</th>
                            <th style="text-align: right;">Views</th>
                            <th>Data</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($posts)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem;">
                                    Nenhuma publicação encontrada com os filtros selecionados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($posts as $p): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.9rem;">
                                            <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="flex-shrink: 0; display: block;" title="Editar publicação">
                                                <?php if (!empty($p['featured_image'])): ?>
                                                    <img src="<?= Security::e(ImageProcessor::toThumbUrl($p['featured_image'], 'avif')) ?>"
                                                         alt=""
                                                         loading="lazy"
                                                         style="width: 76px; height: 48px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border); display: block; background: var(--surface-hover);">
                                                <?php else: ?>
                                                    <div style="width: 76px; height: 48px; border-radius: var(--radius-sm); border: 1px dashed var(--border-strong); background: var(--surface-subtle); display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                                                        <?= \LightPress\Core\Icon::svg('image', '', 18) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </a>

                                            <div style="flex-grow: 1; min-width: 0;">
                                                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 0.45rem;">
                                                    <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.94rem; line-height: 1.35;">
                                                        <?= Security::e($p['title']) ?>
                                                    </a>

                                                    <?php if (Authorization::can($currentUser, Authorization::ACTION_SET_FEATURED)): ?>
                                                        <form method="POST" action="<?= buildFilterUrl([]) ?>" class="js-toggle-featured" style="display:inline;">
                                                            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                            <input type="hidden" name="toggle_featured_id" value="<?= (int)$p['id'] ?>">
                                                            <button type="submit" class="star-featured <?= !empty($p['is_featured']) ? 'star-active' : 'star-inactive' ?>" style="cursor:pointer;" title="Clique para alternar presença no Hero Quad Grid da Home">
                                                                <?= !empty($p['is_featured']) ? \LightPress\Core\Icon::svg('star_filled', '', 12) . ' Destaque' : \LightPress\Core\Icon::svg('star', '', 12) . ' Destacar' ?>
                                                            </button>
                                                        </form>
                                                    <?php elseif (!empty($p['is_featured'])): ?>
                                                        <span class="star-featured star-active"><?= \LightPress\Core\Icon::svg('star_filled', '', 12) ?> Destaque</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div style="font-size: 0.77rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                    <code>/<?= Security::e($p['slug']) ?>/</code>
                                                </div>

                                                <?php if (!empty($p['tags'])): ?>
                                                    <div style="display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.3rem;">
                                                        <?php foreach (array_slice(array_map('trim', explode(',', $p['tags'])), 0, 4) as $tg): ?>
                                                            <?php if (!empty($tg)): ?>
                                                                <span style="font-size: 0.7rem; font-weight: 600; background: var(--primary-light); color: var(--primary-accent); padding: 0.1rem 0.45rem; border-radius: 4px;">
                                                                    #<?= Security::e($tg) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; font-size: 0.86rem;"><?= Security::e($p['author_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: var(--primary-light); color: var(--primary-accent);">
                                            <?= Security::e($p['category']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $pubTime = !empty($p['published_at']) ? strtotime($p['published_at']) : 0;
                                            $isSched = ($pubTime > time()) && ($p['status'] === 'scheduled' || $p['status'] === 'published');
                                            $isPub = ($p['status'] === 'published') || ($pubTime > 0 && $pubTime <= time() && $p['status'] === 'scheduled');
                                        ?>
                                        <?php if ($isSched): ?>
                                            <span class="status-badge status-scheduled" title="Agendado para <?= $formatDisplayDate($p['published_at']) ?>">
                                                <?= \LightPress\Core\Icon::svg('schedule', '', 12) ?>
                                                Agendado (<?= $formatDisplayDate($p['published_at'], 'd/m H:i') ?>)
                                            </span>
                                        <?php elseif ($isPub): ?>
                                            <span class="status-badge status-published">Publicado</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?= Security::e($p['status']) ?>">
                                                <?= match($p['status']) {
                                                    'draft' => 'Rascunho',
                                                    'pending_review' => 'Em Revisão',
                                                    'archived' => 'Arquivado',
                                                    default => $p['status']
                                                } ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tabular-nums" style="text-align: right; font-weight: 800; color: var(--primary);">
                                        <?= number_format((int)$p['views'], 0, ',', '.') ?>
                                    </td>
                                    <td class="tabular-nums" style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                                        <?= $formatDisplayDate($p['published_at'] ?? $p['updated_at']) ?>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                            <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-icon" style="background: var(--warning-bg); color: var(--warning-text);" title="Editar publicação" aria-label="Editar publicação">
                                                <?= \LightPress\Core\Icon::svg('edit', '', 14) ?>
                                            </a>

                                            <button type="button"
                                                    class="btn btn-secondary btn-sm btn-icon"
                                                    onclick="LPAdmin.copyText(window.location.origin + '/<?= Security::e($p['slug']) ?>/', 'URL da matéria copiada!')"
                                                    title="Copiar URL completa"
                                                    aria-label="Copiar URL completa">
                                                <?= \LightPress\Core\Icon::svg('copy', '', 14) ?>
                                            </button>

                                            <?php if ($isPub): ?>
                                                <a href="/<?= Security::e($p['slug']) ?>/" target="_blank" class="btn btn-secondary btn-sm btn-icon" title="Ver publicação no site" aria-label="Ver publicação no site">
                                                    <?= \LightPress\Core\Icon::svg('open_in_new', '', 14) ?>
                                                </a>
                                            <?php endif; ?>

                                            <?php
                                            $canDelete = Authorization::can($currentUser, Authorization::ACTION_DELETE_POST, $p);
                                            if ($canDelete):
                                            ?>
                                                <form method="POST" action="<?= buildFilterUrl([]) ?>" style="display:inline;" data-confirm="Tem certeza que deseja excluir esta publicação? Essa ação removerá o artigo e seu cache." data-confirm-title="Excluir Publicação" data-confirm-btn="Excluir">
                                                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                    <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
                                                    <button type="submit" class="btn btn-danger-soft btn-sm btn-icon" title="Excluir Artigo">
                                                        <?= \LightPress\Core\Icon::svg('trash', '', 14) ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Rodapé com Paginação -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <div>
                        Página <strong class="tabular-nums"><?= $page ?></strong> de <strong class="tabular-nums"><?= $totalPages ?></strong>
                    </div>
                    <div class="page-links">
                        <?php if ($page > 1): ?>
                            <a href="<?= buildFilterUrl(['page' => $page - 1]) ?>" class="page-num">&larr; Anterior</a>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) {
                            echo '<a href="' . buildFilterUrl(['page' => 1]) . '" class="page-num">1</a>';
                            if ($startPage > 2) echo '<span style="padding: 0.35rem;">...</span>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="<?= buildFilterUrl(['page' => $i]) ?>" class="page-num <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span style="padding: 0.35rem;">...</span>';
                            echo '<a href="' . buildFilterUrl(['page' => $totalPages]) . '" class="page-num">' . $totalPages . '</a>';
                        }
                        ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= buildFilterUrl(['page' => $page + 1]) ?>" class="page-num">Próxima &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.classList || !form.classList.contains('js-toggle-featured')) return;
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success && btn) {
                    var active = !!data.is_featured;
                    btn.className = 'star-featured ' + (active ? 'star-active' : 'star-inactive');
                    btn.innerHTML = active
                        ? '<?= \LightPress\Core\Icon::svg('star_filled', '', 12) ?> Destaque'
                        : '<?= \LightPress\Core\Icon::svg('star', '', 12) ?> Destacar';
                    if (window.LPAdmin) {
                        window.LPAdmin.toast(active ? 'Matéria adicionada aos destaques da Home.' : 'Matéria removida dos destaques.', 'success');
                    }
                } else {
                    form.submit();
                }
            })
            .catch(function () {
                form.classList.remove('js-toggle-featured');
                form.submit();
            })
            .finally(function () {
                if (btn) btn.disabled = false;
            });
        });
    </script>
<?php require __DIR__ . '/footer.php'; ?>
