<?php
declare(strict_types=1);
use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\Seo;

$navCategories = Database::fetchAll("SELECT slug, name FROM categories WHERE is_active = 1 ORDER BY id ASC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Security::e($pageTitle ?? 'SpeedPress') ?></title>
    <?php if (!empty($post['seo_description'])): ?>
        <meta name="description" content="<?= Security::e($post['seo_description']) ?>">
    <?php elseif (!empty($post['summary'])): ?>
        <meta name="description" content="<?= Security::e($post['summary']) ?>">
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="Feed RSS - <?= Security::e($config['app']['name'] ?? 'SpeedPress') ?>" href="/feed.xml">

    <!-- Open Graph & Twitter Cards (Sempre WebP para máxima compatibilidade com redes sociais) -->
    <?= Seo::renderMetaTags($post ?? null, $config) ?>

    <!-- Schema.org JSON-LD para Google Notícias -->
    <?= Seo::renderJsonLd($post ?? null, $config) ?>

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
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 10px;
            --badge-bg: #eff6ff;
            --badge-color: #2563eb;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --card-hover-shadow: 0 14px 28px -4px rgba(0, 0, 0, 0.08);
            --font: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        [data-theme="dark"] {
            --primary: #f8fafc;
            --primary-accent: #3b82f6;
            --bg: #090d16;
            --surface: #131b2e;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
            --border: #1e293b;
            --badge-bg: #1e293b;
            --badge-color: #60a5fa;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
            --card-hover-shadow: 0 14px 28px -4px rgba(0, 0, 0, 0.4);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.6; transition: background 0.2s, color 0.2s; }
        
        header { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 50; transition: background 0.2s, border-color 0.2s; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
        .nav-inner { display: flex; align-items: center; justify-content: space-between; height: 68px; gap: 1rem; }
        
        .brand { font-size: 1.45rem; font-weight: 900; letter-spacing: -0.03em; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 0.35rem; }
        .brand span { color: var(--primary-accent); }

        .nav-links { display: flex; gap: 1.25rem; list-style: none; align-items: center; }
        .nav-links a { color: var(--text-muted); text-decoration: none; font-size: 0.92rem; font-weight: 600; transition: color 0.15s; }
        .nav-links a:hover { color: var(--primary-accent); }

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

        main { padding: 2.5rem 0; }
        .badge { display: inline-block; padding: 0.25rem 0.65rem; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; background: var(--badge-bg); color: var(--badge-color); border-radius: 6px; }
        
        /* Grid de Cards */
        .grid-posts { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 1.75rem; margin-top: 1.5rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--card-shadow); transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
        .card:hover { transform: translateY(-4px); box-shadow: var(--card-hover-shadow); }
        .card-img { width: 100%; aspect-ratio: 16/9; object-fit: cover; background: var(--border); display: block; }
        .card-body { padding: 1.5rem; display: flex; flex-direction: column; flex-grow: 1; }
        .card-title { font-size: 1.22rem; font-weight: 800; margin: 0.65rem 0; line-height: 1.35; letter-spacing: -0.015em; }
        .card-title a { color: var(--primary); text-decoration: none; transition: color 0.15s; }
        .card-title a:hover { color: var(--primary-accent); }
        .card-summary { font-size: 0.92rem; color: var(--text-muted); margin-bottom: 1.25rem; flex-grow: 1; line-height: 1.6; }
        .card-meta { font-size: 0.8rem; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border); padding-top: 0.85rem; }

        /* Super Destaque Hero */
        .hero-card { display: grid; grid-template-columns: 1.2fr 1fr; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: var(--card-shadow); margin-bottom: 3rem; }
        @media (max-width: 768px) {
            .hero-card { grid-template-columns: 1fr; }
            .nav-links { display: none; }
        }
        .hero-img { width: 100%; height: 100%; aspect-ratio: 16/9; object-fit: cover; display: block; }
        .hero-body { padding: 2.5rem; display: flex; flex-direction: column; justify-content: center; }
        .hero-title { font-size: 1.95rem; font-weight: 900; line-height: 1.25; letter-spacing: -0.025em; margin: 0.85rem 0; }
        .hero-title a { color: var(--primary); text-decoration: none; }
        .hero-title a:hover { color: var(--primary-accent); }

        /* Tipografia de Artigos (BlockCompiler) */
        .content-body { font-size: 1.15rem; line-height: 1.85; color: var(--text); }
        .content-body h2 { font-size: 1.65rem; font-weight: 800; margin: 2.25rem 0 0.85rem; color: var(--primary); letter-spacing: -0.02em; }
        .content-body h3 { font-size: 1.35rem; font-weight: 700; margin: 1.75rem 0 0.65rem; color: var(--primary); letter-spacing: -0.015em; }
        .content-body p { margin-bottom: 1.4rem; }
        .content-body blockquote { border-left: 4px solid var(--primary-accent); padding: 0.85rem 1.4rem; background: var(--badge-bg); border-radius: 0 var(--radius) var(--radius) 0; margin: 1.75rem 0; font-style: italic; color: var(--text); }
        .content-body blockquote cite { display: block; font-size: 0.85rem; color: var(--text-muted); font-style: normal; margin-top: 0.5rem; font-weight: 600; }
        .content-body figure { margin: 2rem 0; }
        .content-body figure img { width: 100%; border-radius: var(--radius); height: auto; display: block; }
        .content-body figcaption { text-align: center; font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem; font-style: italic; }
        .content-body ul, .content-body ol { margin: 1rem 0 1.5rem 1.75rem; }
        .content-body li { margin-bottom: 0.5rem; }
        .content-body a { color: var(--primary-accent); text-decoration: underline; text-underline-offset: 3px; }
        
        /* Campo de Busca no Cabeçalho */
        .nav-search-input {
            padding: 0.4rem 0.9rem;
            border: 1px solid var(--border);
            border-radius: 20px;
            font-size: 0.85rem;
            background: var(--bg);
            color: var(--text);
            width: 160px;
            outline: none;
            transition: width 0.2s ease, border-color 0.2s ease;
        }
        .nav-search-input:focus {
            width: 220px;
            border-color: var(--primary-accent);
        }

        /* Paginação */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 0.5rem; margin-top: 3.5rem; }
        .page-btn { padding: 0.6rem 1rem; border: 1px solid var(--border); background: var(--surface); color: var(--text); border-radius: var(--radius); text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: all 0.15s; }
        .page-btn:hover { border-color: var(--primary-accent); color: var(--primary-accent); }
        .page-btn.active { background: var(--primary-accent); color: white; border-color: var(--primary-accent); }

        /* Caixa de Autor */
        .author-box { display: flex; align-items: center; gap: 1.25rem; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-top: 3rem; }
        .author-avatar { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; background: var(--border); }
        .author-info h4 { font-size: 1.1rem; font-weight: 800; color: var(--primary); margin-bottom: 0.25rem; }
        .author-info p { font-size: 0.88rem; color: var(--text-muted); line-height: 1.5; margin: 0; }

        footer { background: var(--surface); border-top: 1px solid var(--border); padding: 3rem 0; color: var(--text-muted); font-size: 0.9rem; text-align: center; margin-top: 5rem; }
    </style>
</head>
<body>
    <header>
        <div class="container nav-inner">
            <a href="/" class="brand">Speed<span>Press</span></a>
            <nav style="display: flex; align-items: center; gap: 1.25rem;">
                <form action="/busca" method="GET" style="display: flex; align-items: center;">
                    <input type="text" name="q" placeholder="Buscar notícias..." value="<?= Security::e($_GET['q'] ?? '') ?>" class="nav-search-input" aria-label="Buscar notícias">
                </form>
                <ul class="nav-links">
                    <li><a href="/">Início</a></li>
                    <?php foreach ($navCategories as $navCat): ?>
                        <li><a href="/categoria/<?= Security::e($navCat['slug']) ?>/"><?= Security::e($navCat['name']) ?></a></li>
                    <?php endforeach; ?>
                    <li><a href="/feed.xml" target="_blank">RSS</a></li>
                    <li><a href="/admin/login.php" style="background: var(--primary-accent); color: white; padding: 0.35rem 0.8rem; border-radius: var(--radius); text-decoration: none;">Painel</a></li>
                </ul>
                <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" id="btnThemeToggle">🌙</button>
            </nav>
        </div>
    </header>
    <main class="container">
