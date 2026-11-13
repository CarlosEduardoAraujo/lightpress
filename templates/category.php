<?php
declare(strict_types=1);
use LightPress\Core\Icon;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';

// Formatador de data em português
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
        $h = $dt->format('H:i');
        return "{$d} de {$m} de {$y}, {$h}";
    } catch (\Throwable) {
        $t = strtotime($dateStr);
        $d = date('j', $t);
        $m = $ptMonths[(int)date('n', $t)] ?? date('m', $t);
        $y = date('Y', $t);
        $h = date('H:i', $t);
        return "{$d} de {$m} de {$y}, {$h}";
    }
};

$calcReadingTime = function(?string $html): string {
    $chars = mb_strlen(strip_tags($html ?? ''));
    $min = max(2, (int)ceil($chars / 1100));
    return "{$min} min de leitura";
};

$getSubtopic = function(?string $tagsStr, string $fallback = 'Velocidade Máxima'): string {
    if (empty($tagsStr)) return $fallback;
    $parts = array_map('trim', explode(',', $tagsStr));
    return !empty($parts[0]) ? $parts[0] : $fallback;
};

$categorySlug = Security::e($category['slug'] ?? 'geral');
$categoryName = Security::e($category['name'] ?? 'Categoria');
?>

<div class="flex flex-col gap-space-lg w-full">

    <!-- 1. BREADCRUMBS & CATEGORY HERO HEADER -->
    <header class="flex flex-col gap-space-sm pb-space-sm border-b border-subtle-border dark:border-[#2a303c]">
        <!-- Breadcrumb -->
        <nav aria-label="Navegação Estrutural" class="flex items-center gap-space-xs font-label-meta text-label-meta text-secondary dark:text-secondary-fixed-dim">
            <a class="hover:text-racing-red-text dark:hover:text-racing-red-text-dark transition-colors flex items-center gap-1" href="/">
                <?= Icon::svg('home', '', 15) ?>
                Home
            </a>
            <?= Icon::svg('chevron_right', 'text-secondary-fixed-dim', 13) ?>
            <span class="text-secondary dark:text-secondary-fixed-dim">Categorias</span>
            <?= Icon::svg('chevron_right', 'text-secondary-fixed-dim', 13) ?>
            <span class="text-carbon-black dark:text-pure-white font-semibold capitalize">
                <?= $categoryName ?>
            </span>
        </nav>

        <!-- Title Strip -->
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-space-md pt-space-xs">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2.5">
                    <span class="w-3 h-8 bg-racing-red rounded-sm"></span>
                    <h1 class="font-display-hero text-headline-lg md:text-display-hero text-carbon-black dark:text-pure-white font-bold tracking-tight">
                        <?= $categoryName ?>
                    </h1>
                </div>
                <p class="text-secondary dark:text-secondary-fixed-dim text-body-sm md:text-body-md pl-5 max-w-3xl">
                    <?= !empty($category['description']) ? Security::e($category['description']) : 'Cobertura completa, telemetria, bastidores e análises técnicas em tempo real.' ?>
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 pl-5 md:pl-0">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-surface-container dark:bg-carbon-surface rounded-full text-label-category font-label-category text-carbon-black dark:text-pure-white uppercase border border-subtle-border dark:border-[#2a303c]">
                    <span class="w-2 h-2 rounded-full bg-racing-red animate-pulse"></span>
                    <span><?= number_format($totalPosts ?? count($posts), 0, ',', '.') ?> <?= ($totalPosts ?? count($posts)) === 1 ? 'matéria' : 'matérias' ?></span>
                </span>
            </div>
        </div>
    </header>

    <!-- 2. MAIN TWO-COLUMN BODY LAYOUT (8 COLS FEED / 4 COLS SIDEBAR) -->
    <div id="feed" class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-start">
        
        <!-- LEFT: MAIN NEWS FEED (8 COLS) -->
        <section class="lg:col-span-8 flex flex-col gap-space-lg">
            <div class="flex items-center justify-between pb-space-xs">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-6 bg-racing-red rounded-sm"></span>
                    <h2 class="font-headline-lg text-headline-md md:text-headline-lg text-carbon-black dark:text-pure-white font-bold">
                        <?= ($currentPage ?? 1) > 1 ? "Página {$currentPage} • " . $categoryName : "Feed de Notícias • " . $categoryName ?>
                    </h2>
                </div>
                <span class="font-label-meta text-label-meta text-secondary dark:text-secondary-fixed-dim">Atualizado em tempo real</span>
            </div>

            <?php if (empty($posts)): ?>
                <div class="bg-pure-white dark:bg-carbon-surface rounded-xl p-space-xl border border-subtle-border dark:border-[#2a303c] text-center shadow-xs flex flex-col items-center gap-3">
                    <p class="font-headline-sm text-body-lg font-bold text-carbon-black dark:text-pure-white">Nenhum artigo encontrado nesta categoria no momento.</p>
                    <p class="text-body-sm text-secondary dark:text-secondary-fixed-dim">Fique ligado na nossa cobertura diária para novas análises e telemetrias.</p>
                    <a href="/" class="mt-2 px-space-md py-2 bg-racing-red hover:bg-tertiary text-pure-white font-label-category text-label-category uppercase font-bold rounded-lg transition-colors shadow-xs">
                        Voltar para a Página Inicial
                    </a>
                </div>
            <?php else: ?>
                <!-- Editorial Articles List -->
                <div class="flex flex-col gap-space-md">
                    <?php foreach ($posts as $item): ?>
                        <article class="group bg-pure-white dark:bg-carbon-surface p-space-md rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c] hover:shadow-md dark:hover:border-racing-red/50 transition-all flex flex-col sm:flex-row gap-space-md items-start">
                            <a href="/<?= Security::e($item['slug']) ?>/" class="w-full sm:w-48 h-32 rounded-lg overflow-hidden shrink-0 relative bg-surface-container dark:bg-[#151920] block">
                                <?php if (!empty($item['featured_image'])): 
                                    $catImgDesc = ImageProcessor::getImageDescription($item['featured_image']);
                                ?>
                                    <figure class="w-full h-full m-0 p-0 overflow-hidden">
                                        <img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                                             src="<?= Security::e(ImageProcessor::toThumbUrl($item['featured_image'], 'avif')) ?>" 
                                             alt="<?= Security::e($catImgDesc) ?>" 
                                             title="<?= Security::e($catImgDesc) ?>" 
                                             loading="lazy">
                                    </figure>
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center text-secondary dark:text-secondary-fixed-dim text-xs">Sem foto</div>
                                <?php endif; ?>
                                <span class="absolute top-2 left-2 bg-carbon-black/85 backdrop-blur-sm text-pure-white font-label-category text-label-category px-1.5 py-0.5 rounded uppercase">
                                    <?= Security::e($item['category']) ?>
                                </span>
                            </a>
                            <div class="flex-1 flex flex-col justify-between h-full w-full">
                                <div class="space-y-space-xs">
                                    <div class="flex items-center gap-space-xs text-secondary dark:text-secondary-fixed-dim font-label-meta text-label-meta">
                                        <?= Icon::svg('calendar_today', '', 14) ?>
                                        <time datetime="<?= $item['published_at'] ?>"><?= $formatPtDate($item['published_at']) ?></time>
                                        <span>•</span>
                                        <span class="capitalize"><?= Security::e($getSubtopic($item['tags'] ?? null, $item['category'])) ?></span>
                                    </div>
                                    <h3 class="font-headline-md text-headline-sm md:text-headline-md text-carbon-black dark:text-pure-white group-hover:text-racing-red-text dark:group-hover:text-racing-red-text-dark transition-colors font-bold leading-snug">
                                        <a href="/<?= Security::e($item['slug']) ?>/">
                                            <?= Security::e($item['title']) ?>
                                        </a>
                                    </h3>
                                    <?php if (!empty($item['summary'])): ?>
                                        <p class="font-body-md text-body-sm md:text-body-md text-secondary dark:text-[#a0a5b1] line-clamp-2">
                                            <?= Security::e($item['summary']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <div class="pt-space-sm flex items-center text-racing-red-text dark:text-racing-red-text-dark font-label-category text-label-category uppercase tracking-wider gap-1">
                                    <a href="/<?= Security::e($item['slug']) ?>/" class="flex items-center gap-1 hover:underline">
                                        Ler matéria completa <?= Icon::svg('arrow_forward', '', 14) ?>
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <!-- REFINED MINIMAL PAGINATION -->
                <?php if (!empty($totalPages) && $totalPages > 1): ?>
                    <nav aria-label="Navegação de páginas da categoria" class="w-full bg-pure-white dark:bg-carbon-surface rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c] p-space-md flex flex-wrap items-center justify-between gap-space-sm mt-space-sm">
                        <div class="flex items-center gap-1.5 font-headline-sm text-data-telemetry">
                            <?php if ($currentPage > 1): ?>
                                <a href="<?= $currentPage === 2 ? '/categoria/' . $categorySlug . '/' : '/categoria/' . $categorySlug . '/page/' . ($currentPage - 1) . '/' ?>" class="px-space-sm h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center gap-1 text-label-category font-label-category uppercase tracking-wider transition-colors mr-1" title="Página Anterior">
                                    <?= Icon::svg('chevron_right', 'rotate-180', 14) ?> <span class="hidden sm:inline">Anterior</span>
                                </a>
                            <?php endif; ?>

                            <?php if ($currentPage === 1): ?>
                                <span class="w-9 h-9 rounded-lg bg-racing-red text-pure-white flex items-center justify-center font-bold">1</span>
                            <?php else: ?>
                                <a href="/categoria/<?= $categorySlug ?>/" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors">1</a>
                            <?php endif; ?>

                            <?php if ($currentPage > 3): ?>
                                <span class="w-8 h-9 flex items-center justify-center text-secondary dark:text-secondary-fixed-dim">…</span>
                            <?php endif; ?>

                            <?php for ($p = max(2, $currentPage - 1); $p <= min($totalPages - 1, $currentPage + 1); $p++): ?>
                                <?php if ($p === $currentPage): ?>
                                    <span class="w-9 h-9 rounded-lg bg-racing-red text-pure-white flex items-center justify-center font-bold"><?= $p ?></span>
                                <?php else: ?>
                                    <a href="/categoria/<?= $categorySlug ?>/page/<?= $p ?>/" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors"><?= $p ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($currentPage < $totalPages - 2): ?>
                                <span class="w-8 h-9 flex items-center justify-center text-secondary dark:text-secondary-fixed-dim">…</span>
                            <?php endif; ?>

                            <?php if ($totalPages > 1): ?>
                                <?php if ($currentPage === $totalPages): ?>
                                    <span class="px-2.5 h-9 rounded-lg bg-racing-red text-pure-white flex items-center justify-center font-bold"><?= number_format($totalPages, 0, ',', '.') ?></span>
                                <?php else: ?>
                                    <a href="/categoria/<?= $categorySlug ?>/page/<?= $totalPages ?>/" class="px-2.5 h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors"><?= number_format($totalPages, 0, ',', '.') ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($currentPage < $totalPages): ?>
                            <a class="px-space-md py-2 rounded-lg bg-carbon-black hover:bg-racing-red text-pure-white font-label-category text-label-category uppercase tracking-wider flex items-center gap-1.5 transition-colors shadow-xs border border-transparent dark:border-[#2a303c] dark:bg-[#252b36] dark:hover:bg-racing-red" href="/categoria/<?= $categorySlug ?>/page/<?= $currentPage + 1 ?>/">
                                Próxima Página <?= Icon::svg('arrow_forward', 'text-pure-white', 16) ?>
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- RIGHT: TRACKSIDE SIDEBAR (4 COLS) -->
        <aside class="lg:col-span-4 flex flex-col gap-space-lg">
            <!-- Banner Parceiro Oficial -->
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

            <!-- FAST NEWSLETTER -->
            <div id="newsletter" class="bg-surface-container-low dark:bg-carbon-surface rounded-xl p-space-md space-y-space-sm border border-subtle-border dark:border-[#2a303c]">
                <div class="flex items-center gap-2 text-carbon-black dark:text-pure-white font-headline-sm text-headline-sm font-bold">
                    <?= Icon::svg('mail', 'text-racing-red-text dark:text-racing-red-text-dark', 20) ?>
                    <span>Grid SpeedRacing</span>
                </div>
                <p class="font-body-sm text-body-sm text-secondary dark:text-[#a0a5b1]">
                    O resumo técnico e os bastidores das principais categorias do automobilismo todo dia às 7h no seu e-mail.
                </p>
                <form class="form-newsletter flex flex-col gap-2">
                    <input class="w-full px-space-sm py-2 rounded-lg bg-pure-white dark:bg-[#151920] text-carbon-black dark:text-pure-white placeholder:text-secondary dark:placeholder:text-secondary-fixed-dim font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-racing-red border border-subtle-border dark:border-[#2a303c]" placeholder="Seu melhor e-mail" type="email" required/>
                    <button class="w-full py-2 rounded-lg bg-racing-red hover:bg-tertiary text-pure-white font-label-category text-label-category uppercase tracking-wider font-bold transition-colors" type="submit">
                        Inscrever-se Grátis
                    </button>
                </form>
                <span class="block text-[11px] text-secondary dark:text-secondary-fixed-dim font-label-meta text-center">Sem spam. Desinscreva-se a qualquer momento.</span>
            </div>
        </aside>
    </div>

</div>

<?php require __DIR__ . '/footer.php'; ?>
