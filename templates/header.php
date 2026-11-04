<?php
declare(strict_types=1);
use LightPress\Core\Security;
use LightPress\Core\Seo;
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

    <!-- Open Graph & Twitter Cards -->
    <?= Seo::renderMetaTags($post ?? null, $config) ?>

    <!-- Schema.org JSON-LD para Google Notícias -->
    <?= Seo::renderJsonLd($post ?? null, $config) ?>

    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --bg: #f8fafc;
            --surface: #ffffff;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --radius: 8px;
            --badge-bg: #eff6ff;
            --badge-color: #2563eb;
            --font: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --primary: #f8fafc;
                --primary-accent: #3b82f6;
                --bg: #090d16;
                --surface: #131b2e;
                --text: #e2e8f0;
                --text-muted: #94a3b8;
                --border: #1e293b;
                --badge-bg: #1e293b;
                --badge-color: #60a5fa;
            }
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.6; }
        header { background: var(--surface); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 50; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
        .nav-inner { display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .brand { font-size: 1.4rem; font-weight: 800; color: var(--primary); text-decoration: none; display: flex; align-items: center; gap: 0.5rem; }
        .brand span { color: var(--primary-accent); }
        .nav-links { display: flex; gap: 1.5rem; list-style: none; align-items: center; }
        .nav-links a { color: var(--text-muted); text-decoration: none; font-size: 0.95rem; font-weight: 500; transition: color 0.15s; }
        .nav-links a:hover { color: var(--primary-accent); }
        main { padding: 2.5rem 0; }
        .badge { display: inline-block; padding: 0.2rem 0.6rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; background: var(--badge-bg); color: var(--badge-color); border-radius: 4px; }
        .grid-posts { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; margin-top: 1.5rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; display: flex; flex-direction: column; transition: transform 0.15s, box-shadow 0.15s; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .card-img { width: 100%; aspect-ratio: 16/9; object-fit: cover; background: var(--border); }
        .card-body { padding: 1.25rem; display: flex; flex-direction: column; flex-grow: 1; }
        .card-title { font-size: 1.15rem; font-weight: 700; margin: 0.5rem 0; line-height: 1.35; }
        .card-title a { color: var(--primary); text-decoration: none; }
        .card-title a:hover { color: var(--primary-accent); }
        .card-summary { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; flex-grow: 1; }
        .card-meta { font-size: 0.8rem; color: var(--text-muted); display: flex; justify-content: space-between; border-top: 1px solid var(--border); padding-top: 0.75rem; }
        footer { background: var(--surface); border-top: 1px solid var(--border); padding: 2.5rem 0; color: var(--text-muted); font-size: 0.9rem; text-align: center; margin-top: 4rem; }
        
        /* Tipografia de Artigos (BlockCompiler) */
        .content-body h2 { font-size: 1.6rem; font-weight: 800; margin: 2rem 0 0.75rem; color: var(--primary); }
        .content-body h3 { font-size: 1.3rem; font-weight: 700; margin: 1.5rem 0 0.5rem; color: var(--primary); }
        .content-body p { margin-bottom: 1.25rem; font-size: 1.1rem; line-height: 1.75; }
        .content-body blockquote { border-left: 4px solid var(--primary-accent); padding: 0.75rem 1.25rem; background: var(--badge-bg); border-radius: 0 var(--radius) var(--radius) 0; margin: 1.5rem 0; font-style: italic; }
        .content-body blockquote cite { display: block; font-size: 0.85rem; color: var(--text-muted); font-style: normal; margin-top: 0.5rem; font-weight: 600; }
        .content-body figure { margin: 2rem 0; }
        .content-body figure img { width: 100%; border-radius: var(--radius); height: auto; }
        .content-body figcaption { text-align: center; font-size: 0.85rem; color: var(--text-muted); margin-top: 0.5rem; }
        .content-body ul, .content-body ol { margin: 1rem 0 1.5rem 1.5rem; }
        .content-body li { margin-bottom: 0.5rem; }
        .content-body a { color: var(--primary-accent); text-decoration: underline; }
    </style>
</head>
<body>
    <header>
        <div class="container nav-inner">
            <a href="/" class="brand">Speed<span>Press</span></a>
            <nav>
                <ul class="nav-links">
                    <li><a href="/">Início</a></li>
                    <li><a href="/categoria/geral/">Geral</a></li>
                    <li><a href="/feed.xml" target="_blank">Feed RSS</a></li>
                    <li><a href="/admin/login.php" style="background: var(--primary-accent); color: white; padding: 0.35rem 0.8rem; border-radius: var(--radius);">Painel</a></li>
                </ul>
            </nav>
        </div>
    </header>
    <main class="container">
