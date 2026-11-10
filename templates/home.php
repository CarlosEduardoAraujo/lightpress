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

$formatTime = function(?string $dateStr) use ($config): string {
    if (empty($dateStr)) return '';
    try {
        $tz = new \DateTimeZone($config['app']['display_timezone'] ?? 'America/Sao_Paulo');
        $dt = new \DateTime($dateStr, new \DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        return $dt->format('H:i');
    } catch (\Throwable) {
        return date('H:i', strtotime($dateStr));
    }
};

$calcReadingTime = function($val): string {
    if (is_numeric($val)) {
        $min = max(2, (int)ceil((int)$val / 1100));
    } else {
        $chars = mb_strlen(strip_tags((string)($val ?? '')));
        $min = max(2, (int)ceil($chars / 1100));
    }
    return "{$min} min de leitura";
};

$getSubtopic = function(?string $tagsStr, string $fallback = 'Velocidade Máxima'): string {
    if (empty($tagsStr)) return $fallback;
    $parts = array_map('trim', explode(',', $tagsStr));
    return !empty($parts[0]) ? $parts[0] : $fallback;
};

// Separar o grande destaque principal dos 3 secundários (apenas na página 1)
$featuredPosts = is_array($featuredPosts ?? null) ? $featuredPosts : [];
$mainLead = $featuredPosts[0] ?? null;
$secondaryLeads = !empty($featuredPosts) ? array_slice($featuredPosts, 1, 3) : [];
?>

<?php if (!empty($mainLead) && ($currentPage ?? 1) === 1): ?>
<!-- 1. HERO EDITORIAL & ASYMMETRIC NEWS MOSAIC -->
<section class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-stretch">
    <!-- Master Lead Story (8 Cols) -->
    <article class="lg:col-span-8 group relative flex flex-col justify-end min-h-[460px] lg:min-h-[540px] rounded-xl overflow-hidden bg-carbon-black shadow-md cursor-pointer border border-subtle-border dark:border-[#2a303c]">
        <a href="/<?= Security::e($mainLead['slug']) ?>/" class="absolute inset-0 z-0 overflow-hidden block">
            <?php if (!empty($mainLead['featured_image'])): ?>
                <figure class="w-full h-full m-0 p-0 overflow-hidden">
                    <img class="w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-700 ease-out opacity-85" 
                         src="<?= Security::e(ImageProcessor::toAvifUrl($mainLead['featured_image'])) ?>" 
                         alt="<?= Security::e($mainLead['title']) ?>"
                         title="<?= Security::e($mainLead['title']) ?>">
                    <figcaption class="sr-only"><?= Security::e($mainLead['title']) ?></figcaption>
                </figure>
            <?php else: ?>
                <div class="w-full h-full bg-carbon-surface flex items-center justify-center text-secondary">Sem imagem</div>
            <?php endif; ?>
            <div class="absolute inset-0 bg-gradient-to-t from-carbon-black via-carbon-black/60 to-transparent"></div>
        </a>

        <div class="relative z-10 p-space-md md:p-space-xl flex flex-col gap-space-sm text-pure-white pointer-events-none">
            <div class="flex flex-wrap items-center gap-space-xs">
                <span class="px-space-sm py-1 bg-racing-red text-pure-white font-label-category text-label-category uppercase tracking-wider rounded">
                    <?= Security::e($mainLead['category']) ?>
                </span>
                <span class="px-space-sm py-1 bg-carbon-surface/80 backdrop-blur-md text-surface-container font-data-telemetry text-data-telemetry rounded flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-flag-green"></span>
                    <?= Security::e($getSubtopic($mainLead['tags'] ?? null, 'GP de Velocidade')) ?>
                </span>
                <span class="text-secondary-fixed font-label-meta text-label-meta ml-auto hidden sm:inline">
                    <?= $calcReadingTime($mainLead['content_len'] ?? $mainLead['content_html'] ?? null) ?>
                </span>
            </div>

            <h1 class="font-headline-lg text-headline-lg-mobile md:text-headline-lg text-pure-white group-hover:text-primary-fixed-dim transition-colors leading-tight drop-shadow-sm max-w-3xl">
                <a href="/<?= Security::e($mainLead['slug']) ?>/" class="pointer-events-auto text-pure-white hover:text-primary-fixed-dim">
                    <?= Security::e($mainLead['title']) ?>
                </a>
            </h1>

            <div class="flex items-center gap-space-md pt-space-xs text-secondary-fixed font-label-meta text-label-meta">
                <span class="flex items-center gap-1.5 text-pure-white font-semibold">
                    <?= Icon::svg('edit_note', 'text-racing-red', 16) ?>
                    Por <?= Security::e($mainLead['author_name'] ?? 'Redação SpeedRacing') ?>
                </span>
                <span>•</span>
                <time datetime="<?= $mainLead['published_at'] ?>"><?= $formatPtDate($mainLead['published_at']) ?></time>
            </div>
        </div>
    </article>

    <!-- Secondary Trio Column (4 Cols) -->
    <div class="lg:col-span-4 flex flex-col gap-space-md justify-between">
        <?php foreach ($secondaryLeads as $sec): ?>
            <article class="group flex-1 flex flex-row items-center justify-between gap-space-sm p-space-md rounded-xl bg-pure-white dark:bg-carbon-surface shadow-xs border border-subtle-border dark:border-[#2a303c] hover:shadow-md dark:hover:border-racing-red/50 transition-all">
                <!-- Coluna de Informações e Texto -->
                <div class="flex-1 min-w-0 flex flex-col justify-between h-full">
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-space-xs">
                            <span class="px-2 py-0.5 bg-surface-container dark:bg-[#252b36] font-label-category text-label-category text-carbon-black dark:text-pure-white uppercase rounded">
                                <?= Security::e($sec['category']) ?>
                            </span>
                            <span class="font-label-meta text-label-meta text-secondary dark:text-secondary-fixed-dim">
                                <?= $formatTime($sec['published_at']) ?>
                            </span>
                        </div>
                        <h2 class="font-headline-sm text-body-sm sm:text-headline-sm text-carbon-black dark:text-pure-white group-hover:text-racing-red dark:group-hover:text-racing-red transition-colors line-clamp-2 sm:line-clamp-3 font-bold leading-snug">
                            <a href="/<?= Security::e($sec['slug']) ?>/">
                                <?= Security::e($sec['title']) ?>
                            </a>
                        </h2>
                    </div>
                    <div class="flex items-center justify-between text-secondary dark:text-secondary-fixed-dim font-label-meta text-label-meta mt-space-xs pt-space-xs">
                        <span class="text-tertiary dark:text-racing-red font-medium"></span>
                        <a href="/<?= Security::e($sec['slug']) ?>/" class="text-secondary dark:text-secondary-fixed-dim group-hover:text-racing-red dark:group-hover:text-racing-red transition-transform group-hover:translate-x-1 inline-flex items-center" title="Ler matéria">
                            <?= Icon::svg('arrow_forward', '', 18) ?>
                        </a>
                    </div>
                </div>

                <!-- Thumbnail Compacta à Direita -->
                <a href="/<?= Security::e($sec['slug']) ?>/" class="w-24 h-24 sm:w-28 sm:h-24 shrink-0 rounded-lg overflow-hidden relative bg-surface-container dark:bg-[#151920] block group-hover:opacity-95 transition-opacity" title="<?= Security::e($sec['title']) ?>">
                    <?php if (!empty($sec['featured_image'])): ?>
                        <figure class="w-full h-full m-0 p-0 overflow-hidden">
                            <img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                                 src="<?= Security::e(ImageProcessor::toThumbUrl($sec['featured_image'], 'avif')) ?>" 
                                 alt="<?= Security::e($sec['title']) ?>" 
                                 title="<?= Security::e($sec['title']) ?>" 
                                 loading="lazy">
                            <figcaption class="sr-only"><?= Security::e($sec['title']) ?></figcaption>
                        </figure>
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-secondary text-xs">Sem foto</div>
                    <?php endif; ?>
                </a>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- 3. MAIN TWO-COLUMN BODY LAYOUT -->
<div id="feed" class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-start">
    <!-- LEFT: MAIN NEWS FEED (8 COLS) -->
    <section class="lg:col-span-8 flex flex-col gap-space-lg">
        <div class="flex items-center justify-between pb-space-xs">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-6 bg-racing-red rounded-sm"></span>
                <h2 class="font-headline-lg text-headline-md md:text-headline-lg text-carbon-black dark:text-pure-white font-bold">
                    <?= ($currentPage ?? 1) > 1 ? "Página {$currentPage} - Notícias" : "Últimas Notícias" ?>
                </h2>
            </div>
            <span class="font-label-meta text-label-meta text-secondary dark:text-secondary-fixed-dim">Atualizado em tempo real</span>
        </div>

        <!-- Editorial Articles List -->
        <div class="flex flex-col gap-space-md">
            <?php foreach ($posts as $item): ?>
                <article class="group bg-pure-white dark:bg-carbon-surface p-space-md rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c] hover:shadow-md dark:hover:border-racing-red/50 transition-all flex flex-col sm:flex-row gap-space-md items-start">
                    <a href="/<?= Security::e($item['slug']) ?>/" class="w-full sm:w-48 h-32 rounded-lg overflow-hidden shrink-0 relative bg-surface-container dark:bg-[#151920] block">
                        <?php if (!empty($item['featured_image'])): ?>
                            <figure class="w-full h-full m-0 p-0 overflow-hidden">
                                <img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                                     src="<?= Security::e(ImageProcessor::toThumbUrl($item['featured_image'], 'avif')) ?>" 
                                     alt="<?= Security::e($item['title']) ?>" 
                                     title="<?= Security::e($item['title']) ?>" 
                                     loading="lazy">
                                <figcaption class="sr-only"><?= Security::e($item['title']) ?></figcaption>
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
                            <h3 class="font-headline-md text-headline-sm md:text-headline-md text-carbon-black dark:text-pure-white group-hover:text-racing-red dark:group-hover:text-racing-red transition-colors font-bold leading-snug">
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
                        <div class="pt-space-sm flex items-center text-racing-red font-label-category text-label-category uppercase tracking-wider gap-1">
                            <a href="/<?= Security::e($item['slug']) ?>/" class="flex items-center gap-1 hover:underline">
                                Ler matéria completa <?= Icon::svg('arrow_forward', '', 14) ?>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- REFINED MINIMAL PAGINATION (Reflete a Imagem 3 do Usuário) -->
        <?php if (!empty($totalPages) && $totalPages > 1): ?>
            <nav aria-label="Navegação de páginas" class="w-full bg-pure-white dark:bg-carbon-surface rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c] p-space-md flex flex-wrap items-center justify-between gap-space-sm mt-space-sm">
                <div class="flex items-center gap-1.5 font-headline-sm text-data-telemetry">
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= $currentPage === 2 ? '/' : '/page/' . ($currentPage - 1) . '/' ?>" class="px-space-sm h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center gap-1 text-label-category font-label-category uppercase tracking-wider transition-colors mr-1" title="Página Anterior">
                            <?= Icon::svg('chevron_right', 'rotate-180', 14) ?> <span class="hidden sm:inline">Anterior</span>
                        </a>
                    <?php endif; ?>

                    <?php if ($currentPage === 1): ?>
                        <span class="w-9 h-9 rounded-lg bg-racing-red text-pure-white flex items-center justify-center font-bold">1</span>
                    <?php else: ?>
                        <a href="/" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors">1</a>
                    <?php endif; ?>

                    <?php if ($currentPage > 3): ?>
                        <span class="w-8 h-9 flex items-center justify-center text-secondary dark:text-secondary-fixed-dim">…</span>
                    <?php endif; ?>

                    <?php for ($p = max(2, $currentPage - 1); $p <= min($totalPages - 1, $currentPage + 1); $p++): ?>
                        <?php if ($p === $currentPage): ?>
                            <span class="w-9 h-9 rounded-lg bg-racing-red text-pure-white flex items-center justify-center font-bold"><?= $p ?></span>
                        <?php else: ?>
                            <a href="/page/<?= $p ?>/" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages - 2): ?>
                        <span class="w-8 h-9 flex items-center justify-center text-secondary dark:text-secondary-fixed-dim">…</span>
                    <?php endif; ?>

                    <?php if ($totalPages > 1): ?>
                        <?php if ($currentPage === $totalPages): ?>
                            <span class="px-2.5 h-9 rounded-lg bg-racing-red text-pure-white flex items-center justify-center font-bold"><?= number_format($totalPages, 0, ',', '.') ?></span>
                        <?php else: ?>
                            <a href="/page/<?= $totalPages ?>/" class="px-2.5 h-9 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors"><?= number_format($totalPages, 0, ',', '.') ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($currentPage < $totalPages): ?>
                    <a class="px-space-md py-2 rounded-lg bg-carbon-black hover:bg-racing-red text-pure-white font-label-category text-label-category uppercase tracking-wider flex items-center gap-1.5 transition-colors shadow-xs border border-transparent dark:border-[#2a303c] dark:bg-[#252b36] dark:hover:bg-racing-red" href="/page/<?= $currentPage + 1 ?>/">
                        Próxima Página <?= Icon::svg('arrow_forward', 'text-pure-white', 16) ?>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <!-- RIGHT: RACE WEEKEND & OPINION SIDEBAR (4 COLS) -->
    <aside class="lg:col-span-4 flex flex-col gap-space-lg">
        <!-- Banner Parceiro Oficial (Substitui Próximas Etapas) -->
        <div class="rounded-xl overflow-hidden border border-subtle-border dark:border-[#2a303c] bg-pure-white dark:bg-carbon-surface shadow-xs p-space-sm flex flex-col items-center">
            <div class="w-full flex items-center justify-between pb-2 px-1 text-[10px] font-label-category uppercase tracking-widest text-secondary dark:text-secondary-fixed-dim">
                <span>Publicidade</span>
                <span class="text-racing-red font-bold">Parceiro Oficial</span>
            </div>
            <a href="/contato/" class="w-full block rounded-lg overflow-hidden group shadow-2xs hover:opacity-95 transition-opacity" title="Parceiro Oficial SpeedRacing">
                <picture>
                    <source srcset="/uploads/2026/10/banner-vertical-qr-site.avif" type="image/avif">
                    <source srcset="/uploads/2026/10/banner-vertical-qr-site.webp" type="image/webp">
                    <img src="/uploads/2026/10/banner-vertical-qr-site.avif" alt="Parceiro Oficial SpeedRacing" class="w-full h-auto object-cover rounded-lg group-hover:scale-[1.02] transition-transform duration-300" loading="lazy">
                </picture>
            </a>
        </div>

        <!-- WIDGET 3: INSTAGRAM COMMUNITY CARD -->
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

        <!-- WIDGET 4: FAST NEWSLETTER -->
        <div id="newsletter" class="bg-surface-container-low dark:bg-carbon-surface rounded-xl p-space-md space-y-space-sm border border-subtle-border dark:border-[#2a303c]">
            <div class="flex items-center gap-2 text-carbon-black dark:text-pure-white font-headline-sm text-headline-sm font-bold">
                <?= Icon::svg('mail', 'text-racing-red', 20) ?>
                <span>Grid SpeedRacing</span>
            </div>
            <p class="font-body-sm text-body-sm text-secondary dark:text-[#a0a5b1]">
                O resumo técnico e os bastidores das principais categorias do automobilismo todo dia às 7h no seu e-mail.
            </p>
            <form class="flex flex-col gap-2" onsubmit="event.preventDefault(); alert('Obrigado por se inscrever no Grid SpeedRacing!');">
                <input class="w-full px-space-sm py-2 rounded-lg bg-pure-white dark:bg-[#151920] text-carbon-black dark:text-pure-white placeholder:text-secondary dark:placeholder:text-secondary-fixed-dim font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-racing-red border border-subtle-border dark:border-[#2a303c]" placeholder="Seu melhor e-mail" type="email" required/>
                <button class="w-full py-2 rounded-lg bg-racing-red hover:bg-tertiary text-pure-white font-label-category text-label-category uppercase tracking-wider font-bold transition-colors" type="submit">
                    Inscrever-se Grátis
                </button>
            </form>
            <span class="block text-[11px] text-secondary dark:text-secondary-fixed-dim font-label-meta text-center">Sem spam. Desinscreva-se a qualquer momento.</span>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/footer.php'; ?>
