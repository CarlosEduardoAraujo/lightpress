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

$cacheMgr = new CacheManager($config);

// Alternar destaque do artigo na Home
if (isset($_GET['toggle_featured_id']) && Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
    if (Authorization::can($currentUser, Authorization::ACTION_SET_FEATURED)) {
        $toggleId = (int)$_GET['toggle_featured_id'];
        Database::query("UPDATE posts SET is_featured = IF(is_featured = 1, 0, 1), updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$toggleId]);
        $cacheMgr->purgeUrls(['/']);
        
        // Redireciona mantendo os filtros
        $queryParams = $_GET;
        unset($queryParams['toggle_featured_id'], $queryParams['_csrf_token']);
        $qs = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
        header('Location: /admin/dashboard.php' . $qs);
        exit;
    }
}

// Excluir publicação
if (isset($_GET['delete_id']) && Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
    $deleteId = (int)$_GET['delete_id'];
    $targetPost = Database::fetchOne("SELECT id, slug, category, user_id FROM posts WHERE id = ? LIMIT 1", [$deleteId]);
    
    if ($targetPost) {
        $isAuthor = ((int)$targetPost['user_id'] === (int)$currentUser['id']);
        $isAdminOrEditor = in_array($currentUser['role'], ['admin', 'editor'], true);
        
        if ($isAdminOrEditor || $isAuthor) {
            Database::transaction(function (\PDO $pdo) use ($deleteId, $targetPost, $cacheMgr): void {
                Database::query("DELETE FROM post_stats WHERE post_id = ?", [$deleteId]);
                Database::query("DELETE FROM post_revisions WHERE post_id = ?", [$deleteId]);
                Database::query("DELETE FROM posts WHERE id = ?", [$deleteId]);
                
                // Expurga cache da Home, da categoria e da matéria
                $cacheMgr->purgeUrls([
                    '/',
                    '/' . $targetPost['slug'] . '/',
                    '/categoria/' . $targetPost['category'] . '/'
                ]);
            });

            $queryParams = $_GET;
            unset($queryParams['delete_id'], $queryParams['_csrf_token']);
            $qs = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
            header('Location: /admin/dashboard.php' . $qs);
            exit;
        }
    }
}

// Métricas do Topo
$totalPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts");
$scheduledPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE status = 'scheduled' OR (status = 'published' AND published_at > CURRENT_TIMESTAMP)");
$publishedPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE status = 'published' AND (published_at IS NULL OR published_at <= CURRENT_TIMESTAMP)");
$draftPosts = (int)Database::fetchValue("SELECT COUNT(*) FROM posts WHERE status IN ('draft', 'pending_review')");
$totalViews = (int)Database::fetchValue("SELECT COALESCE(SUM(views), 0) FROM post_stats");

// Carregar Categorias e Autores para os filtros
$categories = Database::fetchAll("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY name ASC");
$authors = Database::fetchAll("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name ASC");

// Parâmetros de Filtro
$filterStatus = trim($_GET['status'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterAuthor = (int)($_GET['author_id'] ?? 0);
$filterSearch = trim($_GET['q'] ?? '');
$filterFeatured = !empty($_GET['featured']) ? 1 : 0;

$whereClauses = ["1=1"];
$params = [];

if ($filterStatus === 'scheduled') {
    $whereClauses[] = "(p.status = 'scheduled' OR (p.status = 'published' AND p.published_at > CURRENT_TIMESTAMP))";
} elseif ($filterStatus === 'published') {
    $whereClauses[] = "(p.status = 'published' AND (p.published_at IS NULL OR p.published_at <= CURRENT_TIMESTAMP))";
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
    $whereClauses[] = "(p.title LIKE ? OR p.summary LIKE ? OR p.tags LIKE ?)";
    $params[] = '%' . $filterSearch . '%';
    $params[] = '%' . $filterSearch . '%';
    $params[] = '%' . $filterSearch . '%';
}

if ($filterFeatured === 1) {
    $whereClauses[] = "p.is_featured = 1";
}

$whereSql = implode(" AND ", $whereClauses);

// Paginação
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$filteredCount = (int)Database::fetchValue(
    "SELECT COUNT(*) FROM posts p WHERE {$whereSql}",
    $params
);
$totalPages = max(1, (int)ceil($filteredCount / $perPage));

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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Editorial - SpeedPress</title>
    <?php if (!empty($config['app']['favicon'])): ?>
        <link rel="icon" href="<?= Security::e($config['app']['favicon']) ?>">
    <?php endif; ?>

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
        [data-theme="dark"] .btn-filter { background: #1e293b; color: #f1f5f9; }
        [data-theme="dark"] .btn-filter:hover { background: #334155; }
        [data-theme="dark"] .form-select, [data-theme="dark"] .form-input { background: #0b1120; color: #f1f5f9; border-color: #1e293b; }
        [data-theme="dark"] .page-num { background: #131b2e; color: #f1f5f9; border-color: #1e293b; }
        [data-theme="dark"] .page-num:hover:not(.active) { background: #1e293b; }
        [data-theme="dark"] .star-inactive { background: #1e293b; color: #64748b; border-color: #334155; }
        
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
        .brand { font-size: 1.3rem; font-weight: 800; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 0.35rem; }
        .brand span { color: var(--primary-accent); }
        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; align-items: center; }
        .nav-links a { color: var(--text-muted); text-decoration: none; font-weight: 600; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary-accent); }
        
        .user-menu { display: flex; align-items: center; gap: 1rem; font-size: 0.9rem; }
        .user-menu a.logout { color: #dc2626; text-decoration: none; font-weight: 600; }

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
        
        .container { max-width: 1550px; margin: 2rem auto; padding: 0 1.5rem; }
        
        /* Cards de Métricas */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        @media (max-width: 1024px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            .metrics-grid { grid-template-columns: 1fr; }
        }
        .metric-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .metric-label { font-size: 0.82rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .metric-value { font-size: 1.85rem; font-weight: 900; color: var(--primary); }
        .metric-sub { font-size: 0.8rem; color: var(--text-muted); }

        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .page-title { font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; }
        
        .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.65rem 1.2rem; background: var(--primary-accent); color: white; text-decoration: none; border-radius: var(--radius); font-weight: 600; font-size: 0.9rem; border: none; cursor: pointer; transition: background 0.15s; }
        .btn:hover { background: #1d4ed8; }
        
        /* Barra de Filtros */
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            align-items: center;
            justify-content: space-between;
        }
        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }
        .form-select, .form-input {
            padding: 0.55rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.88rem;
            background: var(--bg);
            color: var(--text);
            outline: none;
        }
        .form-select:focus, .form-input:focus { border-color: var(--primary-accent); }
        .form-input { min-width: 220px; }
        
        .btn-filter {
            padding: 0.55rem 1rem;
            background: #e2e8f0;
            color: var(--text);
            border: none;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-filter:hover { background: #cbd5e1; }
        .btn-filter.btn-primary { background: var(--primary-accent); color: white; }
        .btn-filter.btn-primary:hover { background: #1d4ed8; }

        /* Tabela */
        .table-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.85rem 1.15rem; font-weight: 700; color: var(--text-muted); border-bottom: 1px solid var(--border); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em; }
        td { padding: 1rem 1.15rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }
        
        .status-badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
        .status-published { background: #dcfce7; color: #15803d; }
        .status-draft { background: #f1f5f9; color: #475569; }
        .status-pending_review { background: #fef3c7; color: #b45309; }
        .status-archived { background: #fee2e2; color: #b91c1c; }

        .star-featured {
            font-size: 0.8rem;
            padding: 0.2rem 0.55rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-left: 0.5rem;
        }
        .star-active { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .star-inactive { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }

        /* Paginação */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            background: var(--surface);
            border-top: 1px solid var(--border);
            font-size: 0.88rem;
            color: var(--text-muted);
        }
        .page-links { display: flex; gap: 0.4rem; }
        .page-num {
            padding: 0.4rem 0.75rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
            font-size: 0.85rem;
        }
        .page-num.active { background: var(--primary-accent); color: white; border-color: var(--primary-accent); }
        .page-num:hover:not(.active) { background: #f1f5f9; }
    </style>
</head>
<body>
    <header class="site-header">
        <div style="display: flex; align-items: center; gap: 2.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Redação</a>
            <nav class="nav-links">
                <a href="/admin/dashboard.php" class="active">Notícias</a>
                <a href="/admin/pages.php">Páginas</a>
                <a href="/admin/media.php">Mídias</a>
                <a href="/admin/categories.php">Categorias</a>
                <a href="/admin/stats.php">Mais Lidas 📈</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/users.php">Usuários & Autores</a>
                    <a href="/admin/settings.php">Configurações</a>
                    <a href="/admin/system.php">Diagnóstico</a>
                <?php endif; ?>
                <a href="/" target="_blank" style="color: var(--primary-accent);">Ver Site &rarr;</a>
            </nav>
        </div>
        <div class="user-menu">
            <span>Olá, <strong><?= Security::e($currentUser['name']) ?></strong> (<?= Security::e($currentUser['role']) ?>)</span>
            <a href="/admin/profile.php" style="color: var(--primary-accent); text-decoration: none; font-weight: 600;">Meu Perfil</a>
            <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" id="btnThemeToggle">🌙</button>
            <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>" class="logout">Sair</a>
        </div>
    </header>

    <div class="container">
        <!-- Cards de Métricas em Tempo Real -->
        <div class="metrics-grid">
            <div class="metric-card">
                <span class="metric-label">Total de Artigos</span>
                <span class="metric-value"><?= number_format($totalPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Acervo completo no banco</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Matérias Publicadas</span>
                <span class="metric-value" style="color: #16a34a;"><?= number_format($publishedPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Disponíveis ao público</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Agendadas</span>
                <span class="metric-value" style="color: #b45309;"><?= number_format($scheduledPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Publicação futura automática</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Rascunhos / Revisões</span>
                <span class="metric-value" style="color: #d97706;"><?= number_format($draftPosts, 0, ',', '.') ?></span>
                <span class="metric-sub">Em preparação editorial</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Visualizações Totais</span>
                <span class="metric-value" style="color: var(--primary-accent);"><?= number_format($totalViews, 0, ',', '.') ?></span>
                <span class="metric-sub">Leituras reais registradas</span>
            </div>
        </div>

        <div class="page-header">
            <div>
                <h1 class="page-title">Gerenciador de Notícias</h1>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.2rem;">
                    Exibindo <?= number_format($filteredCount, 0, ',', '.') ?> <?= $filteredCount === 1 ? 'matéria' : 'matérias' ?> encontradas
                </p>
            </div>
            <a href="/admin/editor.php" class="btn">
                <svg viewBox="0 0 24 24" style="width: 18px; height: 18px; fill: currentColor;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                Nova Publicação
            </a>
        </div>

        <!-- Barra de Filtros e Busca -->
        <form method="GET" action="/admin/dashboard.php" class="filter-bar">
            <div class="filter-group">
                <input type="text" name="q" value="<?= Security::e($filterSearch) ?>" placeholder="Buscar por título, resumo ou tag..." class="form-input">

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

                <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.88rem; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="featured" value="1" <?= $filterFeatured ? 'checked' : '' ?>>
                    ★ Apenas Destaques
                </label>
            </div>

            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" class="btn-filter btn-primary">Filtrar</button>
                <?php if (!empty($filterStatus) || !empty($filterCategory) || !empty($filterAuthor) || !empty($filterSearch) || !empty($filterFeatured)): ?>
                    <a href="/admin/dashboard.php" class="btn-filter" title="Limpar todos os filtros">Limpar</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Tabela de Publicações -->
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40%;">Título / Manchete</th>
                        <th>Autor</th>
                        <th>Categoria</th>
                        <th>Status</th>
                        <th style="text-align: right;">Views</th>
                        <th>Data de Criação</th>
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
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="flex-shrink: 0; display: block;" title="Editar publicação">
                                            <?php if (!empty($p['featured_image'])): ?>
                                                <img src="<?= Security::e(ImageProcessor::toThumbUrl($p['featured_image'], 'avif')) ?>" 
                                                     alt="" 
                                                     loading="lazy" 
                                                     style="width: 76px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border); display: block; background: #e2e8f0;">
                                            <?php else: ?>
                                                <div style="width: 76px; height: 48px; border-radius: 6px; border: 1px dashed var(--border); background: #f8fafc; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 0.68rem; font-weight: 700; text-transform: uppercase;">
                                                    Sem foto
                                                </div>
                                            <?php endif; ?>
                                        </a>

                                        <div style="flex-grow: 1; min-width: 0;">
                                            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem;">
                                                <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" style="color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.95rem; line-height: 1.35;">
                                                    <?= Security::e($p['title']) ?>
                                                </a>

                                                <?php if (Authorization::can($currentUser, Authorization::ACTION_SET_FEATURED)): ?>
                                                    <a href="<?= buildFilterUrl(['toggle_featured_id' => (int)$p['id'], '_csrf_token' => $csrfToken]) ?>" 
                                                       class="star-featured <?= !empty($p['is_featured']) ? 'star-active' : 'star-inactive' ?>"
                                                       title="Clique para alternar presença no Hero Quad Grid da Home">
                                                        <?= !empty($p['is_featured']) ? '★ Destaque Home' : '☆ Destacar' ?>
                                                    </a>
                                                <?php elseif (!empty($p['is_featured'])): ?>
                                                    <span class="star-featured star-active">★ Destaque</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.3rem;">
                                                Slug: <code>/<?= Security::e($p['slug']) ?>/</code>
                                            </div>

                                            <?php if (!empty($p['tags'])): ?>
                                                <div style="display: flex; flex-wrap: wrap; gap: 0.3rem; margin-top: 0.35rem;">
                                                    <?php foreach (array_slice(array_map('trim', explode(',', $p['tags'])), 0, 4) as $tg): ?>
                                                        <?php if (!empty($tg)): ?>
                                                            <span style="font-size: 0.7rem; font-weight: 600; background: rgba(37,99,235,0.08); color: var(--primary-accent); padding: 0.1rem 0.45rem; border-radius: 4px;">
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
                                    <span style="font-weight: 600;"><?= Security::e($p['author_name']) ?></span>
                                </td>
                                <td>
                                    <span style="background: #eff6ff; color: #2563eb; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.78rem; font-weight: 700;">
                                        <?= Security::e($p['category']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                        $isSched = ($p['status'] === 'scheduled') || ($p['status'] === 'published' && !empty($p['published_at']) && strtotime($p['published_at']) > time());
                                    ?>
                                    <?php if ($isSched): ?>
                                        <span class="status-badge" style="background: #fef3c7; color: #b45309;" title="Agendado para <?= $formatDisplayDate($p['published_at']) ?>">
                                            📅 Agendado (<?= $formatDisplayDate($p['published_at'], 'd/m H:i') ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-<?= Security::e($p['status']) ?>">
                                            <?= match($p['status']) {
                                                'published' => 'Publicado',
                                                'draft' => 'Rascunho',
                                                'pending_review' => 'Em Revisão',
                                                'archived' => 'Arquivado',
                                                default => $p['status']
                                            } ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; font-weight: 800; color: var(--primary);">
                                    <?= number_format((int)$p['views'], 0, ',', '.') ?>
                                </td>
                                <td style="font-size: 0.82rem; color: var(--text-muted);">
                                    <?= $formatDisplayDate($p['published_at'] ?? $p['updated_at']) ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="/admin/editor.php?id=<?= (int)$p['id'] ?>" class="btn-filter" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; background: #eff6ff; color: #2563eb;">
                                        Editar
                                    </a>
                                    
                                    <?php if ($p['status'] === 'published'): ?>
                                        <a href="/<?= Security::e($p['slug']) ?>/" target="_blank" class="btn-filter" style="padding: 0.35rem 0.7rem; font-size: 0.8rem;">
                                            Ver ↗
                                        </a>
                                    <?php endif; ?>

                                    <?php 
                                    $canDelete = in_array($currentUser['role'], ['admin', 'editor'], true) || ((int)$currentUser['id'] === (int)$p['user_id']); 
                                    if ($canDelete):
                                    ?>
                                        <a href="<?= buildFilterUrl(['delete_id' => (int)$p['id'], '_csrf_token' => $csrfToken]) ?>" 
                                           onclick="return confirm('Tem certeza que deseja excluir esta publicação? Essa ação removerá o artigo e seu cache no Nginx.')"
                                           class="btn-filter" 
                                           style="padding: 0.35rem 0.6rem; font-size: 0.8rem; color: #dc2626; background: #fee2e2;"
                                           title="Excluir Artigo">
                                            ✕
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Rodapé com Paginação -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <div>
                        Página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong>
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
                            if ($startPage > 2) echo '<span style="padding: 0.4rem;">...</span>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                            <a href="<?= buildFilterUrl(['page' => $i]) ?>" class="page-num <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php 
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span style="padding: 0.4rem;">...</span>';
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
