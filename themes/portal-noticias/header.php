<?php
declare(strict_types=1);
use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\Icon;
use LightPress\Core\ImageProcessor;
use LightPress\Core\ObjectCache;
use LightPress\Core\Security;
use LightPress\Core\Seo;
use LightPress\Core\ThemeManager;

// Categorias do menu (dinâmicas com cache e ordenação personalizada do tema)
$menuCategoriesRaw = ThemeManager::option('menu_categories', 'formula-1, formula-2, formula-e, indycar, stockcar, nascar, endurance, motovelocidade');
$menuCategories = ObjectCache::remember('portal_noticias_menu_categories:' . md5((string)$menuCategoriesRaw), 86400, function () use ($menuCategoriesRaw): array {
    try {
        $activeCats = Database::fetchAll("SELECT slug, name FROM categories WHERE is_active = 1");
        $catsBySlug = [];
        foreach ($activeCats as $c) {
            $catsBySlug[$c['slug']] = $c;
        }

        $orderedSlugs = array_filter(array_map('trim', explode(',', (string)$menuCategoriesRaw)));
        $result = [];
        foreach ($orderedSlugs as $slug) {
            if (isset($catsBySlug[$slug])) {
                $result[] = $catsBySlug[$slug];
                unset($catsBySlug[$slug]);
            }
        }
        foreach ($catsBySlug as $c) {
            $result[] = $c;
        }

        if (!empty($result)) {
            return $result;
        }
    } catch (\Throwable) {
    }

    return [
        ['slug' => 'formula-1', 'name' => 'Fórmula 1'],
        ['slug' => 'formula-2', 'name' => 'Fórmula 2'],
        ['slug' => 'formula-e', 'name' => 'Fórmula E'],
        ['slug' => 'indycar', 'name' => 'IndyCar'],
        ['slug' => 'stockcar', 'name' => 'Stock Car'],
        ['slug' => 'nascar', 'name' => 'NASCAR'],
        ['slug' => 'endurance', 'name' => 'Le Mans / WEC'],
        ['slug' => 'motovelocidade', 'name' => 'Motovelocidade'],
    ];
}, 'categories');

// Tags em alta no dia atual (dinâmicas a partir do banco de dados com cache em RAM)
$trendingTags = ObjectCache::remember('header_trending_tags', 600, function (): array {
    $tags = [];
    try {
        // 1. Buscar tags das notícias publicadas nas últimas 24 horas
        $twentyFourHoursAgo = gmdate('Y-m-d H:i:s', time() - 86400);
        $recentTagsRaw = Database::fetchAll(
            "SELECT tags FROM posts 
             WHERE status = 'published' 
               AND published_at >= ? 
               AND tags IS NOT NULL AND tags != '' 
             LIMIT 50",
            [$twentyFourHoursAgo]
        );

        // Se hoje tiver poucas matérias com tags, busca das últimas 50 matérias publicadas
        if (count($recentTagsRaw) < 3) {
            $recentTagsRaw = Database::fetchAll(
                "SELECT tags FROM posts 
                 WHERE status = 'published' 
                   AND tags IS NOT NULL AND tags != '' 
                 ORDER BY published_at DESC LIMIT 50"
            );
        }

        $tagCounts = [];
        foreach ($recentTagsRaw as $row) {
            $parts = array_map('trim', explode(',', $row['tags']));
            foreach ($parts as $p) {
                if ($p === '' || mb_strlen($p) < 2) continue;
                $key = mb_strtolower($p);
                if (!isset($tagCounts[$key])) {
                    $tagCounts[$key] = ['name' => $p, 'count' => 0];
                }
                $tagCounts[$key]['count']++;
            }
        }

        // Ordenar pelas tags mais frequentes
        uasort($tagCounts, fn($a, $b) => $b['count'] <=> $a['count']);
        $topTags = array_slice($tagCounts, 0, 6);
        foreach ($topTags as $t) {
            $tags[] = $t['name'];
        }
    } catch (\Throwable) {
        // Fallback silencioso
    }

    return !empty($tags) ? $tags : ['Fórmula 1', 'Stock Car', 'NASCAR', 'IndyCar', 'Le Mans'];
}, 'posts');

if (empty($trendingTags)) {
    $trendingTags = ['Fórmula 1', 'Stock Car', 'NASCAR', 'IndyCar', 'Le Mans'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark" data-theme="dark">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= Security::e($pageTitle ?? ($config['app']['name'] ?? 'SpeedRacing') . ' - Automobilismo e Velocidade') ?></title>
    <?php if (empty($post)): ?>
        <?php if (!empty($config['app']['description'])): ?>
            <meta name="description" content="<?= Security::e($config['app']['description']) ?>">
        <?php endif; ?>
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="Feed RSS - <?= Security::e($config['app']['name'] ?? 'SpeedRacing') ?>" href="/feed.xml">
    <?php if (!empty($category['slug'])): ?>
    <link rel="alternate" type="application/rss+xml" title="Feed RSS - <?= Security::e($category['name']) ?> - <?= Security::e($config['app']['name'] ?? 'SpeedRacing') ?>" href="/categoria/<?= Security::e($category['slug']) ?>/feed.xml">
    <?php endif; ?>

    <!-- Favicon do Site Dinâmico -->
    <?php 
    $siteFavicon = $config['app']['favicon'] ?? '';
    if (!empty($siteFavicon)): 
    ?>
        <link rel="icon" href="<?= Security::e($siteFavicon) ?>">
        <link rel="apple-touch-icon" href="<?= Security::e($siteFavicon) ?>">
    <?php else: ?>
        <link rel="icon" href="/favicon.ico">
    <?php endif; ?>

    <!-- Open Graph & Twitter Cards -->
    <?= Seo::renderMetaTags($post ?? null, $config) ?>

    <!-- Schema.org JSON-LD para Google Notícias -->
    <?= Seo::renderJsonLd($post ?? null, $config) ?>

    <!-- Pré-carregamento Prioritário da Imagem LCP (Elimina atraso de descoberta) -->
    <?php if (!empty($post['featured_image'])): ?>
        <link rel="preload" as="image" href="<?= Security::e(ImageProcessor::toAvifUrl($post['featured_image'])) ?>" type="image/avif" fetchpriority="high">
    <?php elseif (!empty($featuredPosts[0]['featured_image']) && ($currentPage ?? 1) === 1): ?>
        <link rel="preload" as="image" href="<?= Security::e(ImageProcessor::toAvifUrl($featuredPosts[0]['featured_image'])) ?>" type="image/avif" fetchpriority="high">
    <?php endif; ?>

    <!-- Script Anti-Flash de Tema (deferido para eliminar bloqueio de renderização) -->
    <script src="<?= Security::e(ThemeManager::asset('js/theme-init.js')) ?>" defer></script>

    <!-- Tipografia do Design System SpeedRacing (Kinetic Apex Editorial) - Carregamento Não-Bloqueante -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&display=swap">
    <link id="gf-async" rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&display=swap" media="print">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&display=swap">
    </noscript>

    <!-- CSS do Portal: Estático Compilado para Máxima Performance (100/100 PageSpeed) com Fallback Seguro -->
    <link rel="stylesheet" href="<?= Security::e(ThemeManager::asset('css/style.css')) ?>">
    <?php \LightPress\Core\Hook::doAction('frontend_head'); ?>
</head>
<body class="bg-surface dark:bg-carbon-black font-body-md text-on-surface dark:text-pure-white antialiased transition-colors duration-200">
    <header class="fixed top-0 left-0 right-0 z-50 bg-pure-white/95 dark:bg-carbon-black/95 backdrop-blur-md shadow-[0_1px_8px_rgba(0,0,0,0.06)] dark:shadow-[0_1px_8px_rgba(0,0,0,0.5)] border-b border-subtle-border dark:border-[#222]">
        <!-- 1. Top Strip -->
        <div class="w-full bg-carbon-black text-on-tertiary">
            <div class="max-w-[1900px] mx-auto px-margin-mobile md:px-margin flex items-center justify-between min-h-[38px] text-label-meta font-label-meta py-0.5">
                <div class="flex items-center gap-space-md">
                    <span class="text-secondary-fixed-dim"><?= Security::e((string)ThemeManager::option('top_strip_text', 'Credibilidade no automobilismo desde 2002')) ?></span>
                </div>
                <div class="flex items-center gap-space-sm">
                    <a class="text-surface-container-lowest hover:text-primary-fixed-dim transition-colors flex items-center justify-center min-w-[44px] min-h-[36px] px-2 py-1 gap-1.5" href="<?= Security::e(BlockCompiler::sanitizeUrl((string)ThemeManager::option('whatsapp_url', 'https://whatsapp.com/channel/0029VbDiks83GJP7Lbzm2d3c')) ?: '#') ?>" target="_blank" rel="noopener noreferrer"  aria-label="Receba notícias e atualizações pelo WhatsApp">
                        <?= Icon::svg('chat', 'text-surface-container-lowest', 16) ?><span class="hidden lg:inline">Canal WhatsApp</span>
                    </a>
                    <a class="text-surface-container-lowest hover:text-primary-fixed-dim transition-colors flex items-center justify-center min-w-[44px] min-h-[36px] px-2 py-1 gap-1.5" href="<?= Security::e(BlockCompiler::sanitizeUrl((string)ThemeManager::option('instagram_url', 'https://www.instagram.com/speedracing.com.br/')) ?: '#') ?>" target="_blank" rel="noopener noreferrer" aria-label="Siga no Instagram">
                        <?= Icon::svg('share', 'text-surface-container-lowest', 16) ?><span class="hidden lg:inline">Social</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Main Navigation Bar -->
        <div class="h-20 max-w-[1900px] mx-auto px-margin-mobile md:px-margin flex items-center justify-between gap-space-md">
            <div class="flex items-center gap-space-lg">
                <a class="flex items-center gap-space-sm shrink-0" href="/">
                    <div class="flex flex-col">
                        <?php 
                        $siteName = $config['app']['name'] ?? 'SpeedRacing';
                        if (strcasecmp($siteName, 'SpeedRacing') === 0): 
                        ?>
                            <span class="font-headline-sm text-headline-sm uppercase tracking-tighter text-carbon-black dark:text-pure-white leading-none">SPEED<span class="text-racing-red-text dark:text-racing-red-text-dark">RACING</span></span>
                        <?php else: ?>
                            <span class="font-headline-sm text-headline-sm uppercase tracking-tighter text-carbon-black dark:text-pure-white leading-none"><?= Security::e($siteName) ?></span>
                        <?php endif; ?>
                        <span class="font-label-category text-[9px] tracking-widest text-secondary dark:text-secondary-fixed uppercase">Desde 2002</span>
                    </div>
                </a>

                <nav class="hidden xl:flex items-center gap-space-md font-body-sm text-body-sm">
                    <a aria-current="page" class="transition-colors whitespace-nowrap text-racing-red-text dark:text-racing-red-text-dark font-semibold" href="/">Home</a>
                    <?php foreach ($menuCategories as $cat): ?>
                        <a class="text-on-surface-variant dark:text-secondary-fixed hover:text-racing-red-text dark:hover:text-racing-red-text-dark transition-colors whitespace-nowrap" href="/categoria/<?= Security::e($cat['slug']) ?>/" aria-label="Categoria <?= Security::e($cat['name']) ?>"><?= Security::e($cat['name']) ?></a>
                    <?php endforeach; ?>
                    <a class="text-on-surface-variant dark:text-secondary-fixed hover:text-racing-red-text dark:hover:text-racing-red-text-dark transition-colors whitespace-nowrap" href="/nossa-equipe/">Nossa Equipe</a>
                </nav>
            </div>

            <div class="flex items-center gap-space-sm">
                <!-- Busca Integrada -->
                <form action="/busca/" method="GET" class="relative flex items-center">
                    <input type="text" name="q" placeholder="Buscar no portal..." value="<?= Security::e(Security::getSafeString($_GET, 'q')) ?>" class="hidden md:block w-36 focus:w-52 transition-all duration-200 text-body-sm py-1.5 px-3 rounded-lg bg-surface-container dark:bg-carbon-surface text-carbon-black dark:text-pure-white placeholder:text-secondary dark:placeholder:text-secondary-fixed-dim focus:outline-none focus:ring-1 focus:ring-racing-red border border-subtle-border dark:border-[#2a303c]">
                    <button type="submit" aria-label="Buscar no portal" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-carbon-surface flex items-center justify-center text-on-surface dark:text-pure-white hover:bg-surface-container-high dark:hover:bg-[#252b35] transition-colors md:absolute md:right-0">
                        <?= Icon::svg('search', 'w-4 h-4', 18) ?>
                    </button>
                </form>

                <!-- Botão de Alternância de Tema Claro / Escuro -->
                <button type="button" id="btnThemeToggle" aria-label="Alternar modo claro/escuro" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-carbon-surface dark:text-pure-white flex items-center justify-center text-on-surface hover:bg-surface-container-high dark:hover:bg-[#252b35] transition-colors border border-subtle-border dark:border-[#2a303c]" title="Alternar Modo Claro/Escuro">
                    <span id="themeIcon"><?= Icon::svg('light_mode', 'w-4 h-4', 18) ?></span>
                </button>
                
                <div class="hidden sm:flex items-center px-space-sm py-1 bg-surface-container dark:bg-carbon-surface rounded-lg text-label-category font-label-category text-carbon-black dark:text-pure-white uppercase tracking-wider gap-1.5 border border-subtle-border dark:border-[#2a303c]">
                    <?= Icon::svg('language', 'text-racing-red-text dark:text-racing-red-text-dark', 14) ?>
                    <span>BR</span>
                </div>

                <a href="/admin/login.php" class="w-8 h-8 rounded-full bg-primary flex items-center justify-center shrink-0 text-pure-white hover:opacity-90 transition-opacity" title="Acessar Painel">
                    <?= Icon::svg('person', 'text-pure-white', 16) ?>
                </a>
            </div>
        </div>

        <!-- 3. Em Alta Ticker Strip -->
        <div class="bg-surface-container-low dark:bg-[#0d121c] border-t border-subtle-border/60 dark:border-[#1e2533]">
            <div class="max-w-[1900px] mx-auto px-margin-mobile md:px-margin h-11 flex items-center gap-space-sm overflow-x-auto">
                <span class="font-label-category text-label-category uppercase tracking-wider text-racing-red-text dark:text-racing-red-text-dark shrink-0 flex items-center gap-1.5">
                    <?= Icon::svg('trending_up', 'text-racing-red-text dark:text-racing-red-text-dark', 14) ?>Em Alta:
                </span>
                <div class="flex items-center gap-space-xs font-label-meta text-label-meta">
                    <?php foreach ($trendingTags as $tTag): ?>
                        <a class="px-space-sm py-1 rounded-full bg-pure-white dark:bg-carbon-surface hover:bg-surface-container-high dark:hover:bg-[#242b38] text-carbon-black dark:text-pure-white whitespace-nowrap transition-colors shadow-xs border border-subtle-border/60 dark:border-[#2a303c]" href="/tag/<?= urlencode(Security::slugify($tTag)) ?>/" aria-label="Tópico em alta: <?= Security::e($tTag) ?>">#<?= Security::e($tTag) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </header>

    <main class="w-full pt-40 bg-surface dark:bg-carbon-black min-h-[calc(100vh-280px)] transition-colors duration-200">
        <div class="max-w-[1900px] w-full mx-auto px-margin-mobile md:px-margin space-y-space-xl pb-space-xl">

    <!-- Aplicação JavaScript do Portal (Desacoplado para conformidade estrita com CSP) -->
    <script src="<?= Security::e(ThemeManager::asset('js/app.js')) ?>" defer></script>
