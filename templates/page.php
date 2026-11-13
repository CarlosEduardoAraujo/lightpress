<?php
declare(strict_types=1);
use LightPress\Core\Icon;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';

// Formatador de data por extenso em português
$ptMonths = [
    1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
    5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
    9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'
];
$formatPtDate = function(?string $dateStr) use ($ptMonths, $config): string {
    if (empty($dateStr)) return date('d/m/Y');
    try {
        $tz = new \DateTimeZone($config['app']['display_timezone'] ?? 'America/Sao_Paulo');
        $dt = new \DateTime($dateStr, new \DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        $d = $dt->format('j');
        $m = $ptMonths[(int)$dt->format('n')] ?? $dt->format('m');
        $y = $dt->format('Y');
        return "{$d} de {$m} de {$y}";
    } catch (\Throwable) {
        $t = strtotime($dateStr);
        $d = date('j', $t);
        $m = $ptMonths[(int)date('n', $t)] ?? date('m', $t);
        $y = date('Y', $t);
        return "{$d} de {$m} de {$y}";
    }
};
?>

<style>
    /* Estilos semânticos para o corpo de páginas institucionais */
    .article-body {
        font-family: 'Work Sans', sans-serif;
        font-size: 1.125rem;
        line-height: 1.85;
        color: #141d23;
    }
    html.dark .article-body {
        color: #d1d5db;
    }
    .article-body p {
        margin-bottom: 1.5rem;
    }
    .article-body h2 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.875rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        line-height: 1.25;
        margin-top: 2.25rem;
        margin-bottom: 1rem;
        color: #111418;
        border-left: 4px solid #E10600;
        padding-left: 0.75rem;
    }
    html.dark .article-body h2 {
        color: #FFFFFF;
    }
    .article-body h3 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.35rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        line-height: 1.3;
        margin-top: 1.75rem;
        margin-bottom: 0.75rem;
        color: #111418;
    }
    html.dark .article-body h3 {
        color: #FFFFFF;
    }
    .article-body a {
        color: #E10600;
        text-decoration: underline;
        text-underline-offset: 3px;
        font-weight: 600;
        transition: color 0.15s ease;
    }
    .article-body a:hover {
        color: #b90500;
    }
    .article-body ul, .article-body ol {
        margin-bottom: 1.5rem;
        padding-left: 1.5rem;
    }
    .article-body ul {
        list-style-type: disc;
    }
    .article-body ol {
        list-style-type: decimal;
    }
    .article-body li {
        margin-bottom: 0.5rem;
    }
    .article-body blockquote {
        margin: 1.75rem 0;
        padding: 1rem 1.5rem;
        background: #f8fafc;
        border-left: 4px solid #E10600;
        border-radius: 0.375rem;
        font-style: italic;
        color: #334155;
    }
    html.dark .article-body blockquote {
        background: #1c222c;
        color: #cbd5e1;
    }
</style>

<div class="flex flex-col w-full">
    <!-- Breadcrumbs -->
    <nav aria-label="Navegação Estrutural" class="flex flex-wrap items-center justify-between gap-space-sm pb-space-md">
        <div class="flex items-center gap-space-xs font-label-meta text-label-meta text-secondary dark:text-secondary-fixed-dim">
            <a class="hover:text-racing-red-text dark:hover:text-racing-red-text-dark transition-colors flex items-center gap-1" href="/">
                <?= Icon::svg('home', '', 15) ?>
                Home
            </a>
            <?= Icon::svg('chevron_right', 'text-secondary-fixed-dim', 13) ?>
            <span class="text-carbon-black dark:text-pure-white font-semibold">
                <?= Security::e($page['title']) ?>
            </span>
        </div>
        <div class="inline-flex items-center gap-2 px-space-sm py-1 bg-surface-container dark:bg-carbon-surface rounded-full text-label-category font-label-category text-carbon-black dark:text-pure-white uppercase border border-subtle-border dark:border-[#2a303c]">
            <span class="w-2 h-2 rounded-full bg-racing-red animate-pulse"></span>
            <span>SpeedRacing Oficial</span>
        </div>
    </nav>

    <!-- Main Editorial Grid: 8 Cols Page Content / 4 Cols Trackside Sidebar -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-gutter items-start">
        
        <!-- Left Column (Page Core - 8 Cols) -->
        <!-- Endpoint canônico de visualização: var endpoint = '/api/view/page/' + pageId; -->
        <article class="lg:col-span-8 flex flex-col gap-space-md bg-pure-white dark:bg-carbon-surface p-space-md sm:p-space-xl rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c]" data-view-endpoint="/api/view/page/<?= (int)$page['id'] ?>">
            
            <!-- Header Section -->
            <header class="flex flex-col gap-space-xs pb-space-sm border-b border-subtle-border dark:border-[#2a303c]">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-8 bg-racing-red rounded-sm"></span>
                    <h1 class="font-display-hero text-headline-lg md:text-display-hero text-carbon-black dark:text-pure-white leading-tight font-bold tracking-tight">
                        <?= Security::e($page['title']) ?>
                    </h1>
                </div>
                <div class="flex items-center gap-2 text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim pl-5 pt-1">
                    <span>Atualizado em <?= $formatPtDate($page['updated_at'] ?? $page['created_at']) ?></span>
                </div>
            </header>

            <!-- Featured Image (se houver) -->
            <?php if (!empty($page['featured_image'])): 
                $pageImgDesc = ImageProcessor::getImageDescription($page['featured_image']);
            ?>
                <figure class="my-space-xs rounded-xl overflow-hidden border border-subtle-border dark:border-[#2a303c] shadow-xs">
                    <img src="<?= Security::e(ImageProcessor::toAvifUrl($page['featured_image'])) ?>" 
                         alt="<?= Security::e($pageImgDesc) ?>" 
                         title="<?= Security::e($pageImgDesc) ?>" 
                         class="w-full max-h-[420px] object-cover">
                </figure>
            <?php endif; ?>

            <!-- Page Body Content -->
            <div class="article-body mt-2">
                <?= \LightPress\Core\BlockCompiler::sanitizeHtml($page['content_html'] ?? '') ?>
            </div>

        </article>

        <!-- Right Column (Trackside Sidebar - 4 Cols) -->
        <aside class="lg:col-span-4 flex flex-col gap-space-lg">
            
            <!-- Banner Parceiro 1 -->
            <div class="rounded-xl overflow-hidden border border-subtle-border dark:border-[#2a303c] bg-pure-white dark:bg-carbon-surface shadow-xs p-space-sm flex flex-col items-center">
                <div class="w-full flex items-center justify-between pb-2 px-1 text-[10px] font-label-category uppercase tracking-widest text-secondary dark:text-secondary-fixed-dim">
                    <span>Publicidade</span>
                    <span class="text-racing-red-text dark:text-racing-red-text-dark font-bold">Parceiro Oficial</span>
                </div>
                <a href="/contato/" class="w-full block rounded-lg overflow-hidden group shadow-2xs hover:opacity-95 transition-opacity" title="Parceiro Oficial SpeedRacing">
                    <picture>
                        <source srcset="/uploads/2026/10/banner-vertical-qr-site.avif" type="image/avif">
                        <source srcset="/uploads/2026/10/banner-vertical-qr-site.webp" type="image/webp">
                        <img src="/uploads/2026/10/banner-vertical-qr-site.avif" alt="Parceiro Oficial SpeedRacing" width="400" height="716" class="w-full h-auto object-cover rounded-lg group-hover:scale-[1.02] transition-transform duration-300" loading="lazy">
                    </picture>
                </a>
            </div>

            <!-- INSTAGRAM COMMUNITY CARD -->
            <div class="bg-pure-white dark:bg-carbon-surface rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c] p-space-md flex flex-col gap-space-sm items-center text-center">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-flag-yellow via-racing-red to-primary flex items-center justify-center text-pure-white shadow-md">
                    <?= Icon::svg('photo_camera', 'text-pure-white', 30) ?>
                </div>
                <div>
                    <h4 class="font-headline-sm text-headline-sm text-carbon-black dark:text-pure-white font-bold">@speedracing.com.br</h4>
                    <p class="font-body-sm text-body-sm text-secondary dark:text-[#a0a5b1]">Bastidores, telemetria em tempo real e fotografias exclusivas direto do paddock.</p>
                </div>
                <a class="w-full py-2.5 rounded-lg bg-carbon-black hover:bg-racing-red text-pure-white font-label-category text-label-category uppercase tracking-wider flex items-center justify-center gap-2 transition-colors shadow-xs border border-transparent dark:border-[#2a303c] dark:bg-[#252b36] dark:hover:bg-racing-red" href="https://www.instagram.com/speedracing.com.br/" rel="noopener noreferrer" target="_blank">
                    <span>Seguir no Instagram</span>
                    <?= Icon::svg('open_in_new', 'text-pure-white', 16) ?>
                </a>
            </div>

            <!-- Grid SpeedRacing Newsletter -->
            <div id="newsletter" class="bg-carbon-black text-pure-white rounded-xl p-space-lg shadow-md relative overflow-hidden flex flex-col gap-space-sm border border-subtle-border/30 dark:border-[#2a303c]">
                <div class="w-24 h-24 rounded-full bg-racing-red/20 blur-2xl absolute -top-8 -right-8 pointer-events-none"></div>
                <div class="flex items-center gap-2">
                    <?= Icon::svg('mail', 'text-racing-red-text-dark', 24) ?>
                    <span class="font-label-category text-label-category uppercase tracking-wider text-pure-white font-bold">Grid SpeedRacing</span>
                </div>
                <h4 class="font-headline-sm text-headline-sm text-pure-white leading-tight font-bold">
                    Receba as análises técnicas e telemetria antes de todo mundo
                </h4>
                <p class="font-body-sm text-body-sm text-surface-container">
                    Toda manhã direto na sua caixa de entrada, com rigor editorial e sem enrolação.
                </p>
                <form class="form-newsletter flex flex-col gap-space-xs mt-1">
                    <input class="w-full px-space-sm py-2.5 rounded-lg bg-carbon-surface text-pure-white text-body-sm placeholder:text-secondary-fixed focus:outline-none focus:ring-1 focus:ring-racing-red border border-[#2a303c]" placeholder="Seu melhor e-mail" required="" type="email"/>
                    <button class="w-full py-2.5 bg-racing-red hover:bg-tertiary text-pure-white font-label-category text-label-category uppercase font-bold rounded-lg transition-colors flex items-center justify-center gap-1 shadow-sm" type="submit">
                        <span>Garantir Vaga no Grid</span>
                        <?= Icon::svg('chevron_right', 'text-pure-white', 16) ?>
                    </button>
                </form>
                <span class="text-[10px] font-label-meta text-surface-container-high/60 text-center">
                    Zero spam. Desinscreva-se a qualquer momento com 1 clique.
                </span>
            </div>

        </aside>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
