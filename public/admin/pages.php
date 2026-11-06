<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Gerenciador de Páginas Institucionais
 * Listagem, filtragem, visualização de status e exclusão com segurança CSRF.
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Core\Schema;
use LightPress\Core\CacheManager;

$currentUser = Security::requireAuth(null, ['admin', 'editor'], $config);

// Garantir que a tabela pages exista e tenha páginas iniciais
Schema::ensurePagesTable();

$message = '';
$error = '';

// Exclusão de Página (suporta POST prioritário e GET para compatibilidade)
$isDelPage = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete')
    || (isset($_GET['action']) && $_GET['action'] === 'delete');
if ($isDelPage) {
    $deleteId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $csrf = $_POST['_csrf_token'] ?? $_GET['_csrf_token'] ?? '';
    
    if (!Security::verifyCsrfToken($csrf)) {
        $error = "Token de segurança CSRF inválido ou expirado.";
    } else {
        $target = Database::fetchOne("SELECT id, title, slug FROM pages WHERE id = ? LIMIT 1", [$deleteId]);
        if ($target) {
            Database::query("DELETE FROM pages WHERE id = ?", [$deleteId]);
            try {
                $cacheMgr = new CacheManager($config);
                $cacheMgr->purgeUrls(["/{$target['slug']}/", "/sitemap.xml"]);
            } catch (\Throwable) {}
            header("Location: /admin/pages.php?deleted=1");
            exit;
        } else {
            $error = "Página não encontrada.";
        }
    }
}

if (isset($_GET['deleted'])) {
    $message = "Página excluída com sucesso.";
}
if (isset($_GET['saved'])) {
    $message = "Página salva com sucesso!";
}

// Filtros
$filterSearch = trim($_GET['q'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$sql = "SELECT p.*, u.name as author_name FROM pages p LEFT JOIN users u ON u.id = p.user_id WHERE 1=1";
$params = [];

if ($filterSearch !== '') {
    $sql .= " AND (p.title LIKE ? OR p.slug LIKE ? OR p.content_html LIKE ?)";
    $term = "%{$filterSearch}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($filterStatus !== '') {
    $sql .= " AND p.status = ?";
    $params[] = $filterStatus;
}

$sql .= " ORDER BY p.updated_at DESC";
$pages = Database::fetchAll($sql, $params);

// Contadores de Métricas
$totalCount = (int)Database::fetchValue("SELECT COUNT(*) FROM pages");
$publishedCount = (int)Database::fetchValue("SELECT COUNT(*) FROM pages WHERE status = 'published'");
$draftCount = (int)Database::fetchValue("SELECT COUNT(*) FROM pages WHERE status = 'draft'");

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Páginas Institucionais - SpeedPress Admin</title>
    <?php if (!empty($config['app']['favicon'])): ?>
        <link rel="icon" href="<?= Security::e($config['app']['favicon']) ?>">
    <?php endif; ?>

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
            --primary-accent: #E10600;
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
            --primary-accent: #E10600;
            --bg: #090d16;
            --surface: #111827;
            --border: #1f2937;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); padding-bottom: 4rem; }
        .site-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0.85rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand { font-size: 1.15rem; font-weight: 800; color: var(--text); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; font-weight: 600; }
        .nav-links a { color: var(--text-muted); text-decoration: none; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--text); }
        .user-menu { display: flex; align-items: center; gap: 1.25rem; font-size: 0.88rem; }
        .theme-btn {
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 0.35rem 0.65rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-size: 0.95rem;
        }
        .logout { color: #ef4444; text-decoration: none; font-weight: 600; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .metric-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .metric-label { font-size: 0.82rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); }
        .metric-value { font-size: 1.85rem; font-weight: 800; color: var(--text); }
        .metric-sub { font-size: 0.78rem; color: var(--text-muted); }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-title { font-size: 1.6rem; font-weight: 800; }
        .btn {
            background: var(--primary-accent);
            color: #ffffff;
            padding: 0.65rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 700;
            font-size: 0.88rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
            transition: opacity 0.15s;
        }
        .btn:hover { opacity: 0.9; }

        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .form-input, .form-select {
            padding: 0.55rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
            font-size: 0.88rem;
        }
        .form-input:focus, .form-select:focus { outline: none; border-color: var(--primary-accent); }

        .table-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th {
            background: var(--bg);
            padding: 0.85rem 1.25rem;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(0, 0, 0, 0.015); }
        [data-theme="dark"] tr:hover td { background: rgba(255, 255, 255, 0.02); }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-published { background: #dcfce7; color: #15803d; }
        .badge-draft { background: #fef3c7; color: #b45309; }
        .badge-archived { background: #f1f5f9; color: #64748b; }
        [data-theme="dark"] .badge-published { background: #052e16; color: #86efac; }
        [data-theme="dark"] .badge-draft { background: #451a03; color: #fde68a; }
        [data-theme="dark"] .badge-archived { background: #1e293b; color: #94a3b8; }

        .action-link {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            margin-right: 0.85rem;
            transition: color 0.15s;
        }
        .action-link:hover { color: var(--primary-accent); }
        .action-delete:hover { color: #ef4444; }

        .alert-success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 0.85rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 0.85rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?= \LightPress\Core\AdminNav::renderHeader('pages', $currentUser, $csrfToken) ?>

    <div class="container">
        <!-- Métricas Rápidas -->
        <div class="metrics-grid">
            <div class="metric-card">
                <span class="metric-label">Total de Páginas</span>
                <span class="metric-value"><?= number_format($totalCount, 0, ',', '.') ?></span>
                <span class="metric-sub">Institucionais & Estáticas</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Páginas Publicadas</span>
                <span class="metric-value" style="color: #16a34a;"><?= number_format($publishedCount, 0, ',', '.') ?></span>
                <span class="metric-sub">Acessíveis ao público</span>
            </div>
            <div class="metric-card">
                <span class="metric-label">Rascunhos</span>
                <span class="metric-value" style="color: #b45309;"><?= number_format($draftCount, 0, ',', '.') ?></span>
                <span class="metric-sub">Em elaboração</span>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert-success"><?= Security::e($message) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">Gerenciador de Páginas</h1>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.2rem;">
                    Páginas institucionais isoladas do feed diário de notícias (ex: Equipe, Contato, Termos, Privacidade)
                </p>
            </div>
            <a href="/admin/page-edit.php" class="btn">
                <svg viewBox="0 0 24 24" style="width: 18px; height: 18px; fill: currentColor;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                Nova Página
            </a>
        </div>

        <!-- Filtros e Busca -->
        <form method="GET" action="/admin/pages.php" class="filter-bar">
            <input type="text" name="q" value="<?= Security::e($filterSearch) ?>" placeholder="Buscar por título ou slug..." class="form-input" style="flex: 1; min-width: 200px;">
            <select name="status" class="form-select">
                <option value="">Todos os Status</option>
                <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Publicadas</option>
                <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Rascunhos</option>
                <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Arquivadas</option>
            </select>
            <button type="submit" class="btn" style="padding: 0.55rem 1rem;">Filtrar</button>
            <?php if ($filterSearch !== '' || $filterStatus !== ''): ?>
                <a href="/admin/pages.php" class="btn" style="background: transparent; color: var(--text-muted); border: 1px solid var(--border);">Limpar</a>
            <?php endif; ?>
        </form>

        <!-- Tabela de Páginas -->
        <div class="table-card">
            <?php if (empty($pages)): ?>
                <div style="padding: 3rem; text-align: center; color: var(--text-muted);">
                    Nenhuma página encontrada. Clique em "Nova Página" para criar.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Título & Informações</th>
                            <th>Link Permanente (URL)</th>
                            <th>Status</th>
                            <th>Visualizações</th>
                            <th>Última Atualização</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $p): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700; font-size: 0.98rem; margin-bottom: 0.2rem;">
                                        <a href="/admin/page-edit.php?id=<?= (int)$p['id'] ?>" style="color: var(--text); text-decoration: none;">
                                            <?= Security::e($p['title']) ?>
                                        </a>
                                    </div>
                                    <div style="font-size: 0.78rem; color: var(--text-muted);">
                                        ID #<?= (int)$p['id'] ?>
                                    </div>
                                </td>
                                <td>
                                    <a href="/<?= Security::e($p['slug']) ?>/" target="_blank" style="color: var(--primary-accent); text-decoration: none; font-family: monospace; font-size: 0.85rem;" title="Abrir página no site">
                                        /<?= Security::e($p['slug']) ?>/
                                    </a>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $p['status'] ?>">
                                        <?= match($p['status']) {
                                            'published' => 'Publicada',
                                            'draft' => 'Rascunho',
                                            'archived' => 'Arquivada',
                                            default => $p['status']
                                        } ?>
                                    </span>
                                </td>
                                <td style="font-weight: 700;">
                                    <?= number_format((int)$p['views'], 0, ',', '.') ?>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.85rem;">
                                    <?= date('d/m/Y H:i', strtotime($p['updated_at'] ?? $p['created_at'])) ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="/admin/page-edit.php?id=<?= (int)$p['id'] ?>" class="action-link">Editar</a>
                                    <a href="/<?= Security::e($p['slug']) ?>/" target="_blank" class="action-link">Visualizar &rarr;</a>
                                    <a href="/admin/pages.php?action=delete&id=<?= (int)$p['id'] ?>&_csrf_token=<?= Security::e($csrfToken) ?>" 
                                       onclick="return confirm('Tem certeza que deseja excluir a página \'<?= Security::e(addslashes($p['title'])) ?>\'? Esta ação não pode ser desfeita.');" 
                                       class="action-link action-delete">Excluir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('lp_theme', next);
            updateThemeButton(next);
        }
        function updateThemeButton(theme) {
            var btn = document.getElementById('btnThemeToggle');
            if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
        }
        updateThemeButton(document.documentElement.getAttribute('data-theme'));
    </script>
</body>
</html>
