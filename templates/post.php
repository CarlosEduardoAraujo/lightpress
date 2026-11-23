<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\Icon;

require __DIR__ . '/header.php';

// Formatador de data por extenso em português
$ptMonths = [
    1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
    5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
    9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'
];
$formatPtFullDate = function(?string $dateStr) use ($ptMonths, $config): string {
    if (empty($dateStr)) return date('d/m/Y');
    try {
        $tz = new \DateTimeZone($config['app']['display_timezone'] ?? 'America/Sao_Paulo');
        $dt = new \DateTime($dateStr, new \DateTimeZone('UTC'));
        $dt->setTimezone($tz);
        $d = (int)$dt->format('j');
        $m = $ptMonths[(int)$dt->format('n')] ?? $dt->format('m');
        $y = (int)$dt->format('Y');
        $h = $dt->format('H:i');
        return sprintf('%02d de %s de %d às %s', $d, $m, $y, $h);
    } catch (\Throwable) {
        $t = strtotime($dateStr);
        $d = date('j', $t);
        $m = $ptMonths[(int)date('n', $t)] ?? date('m', $t);
        $y = date('Y', $t);
        $h = date('H:i', $t);
        return sprintf('%02d de %s de %d às %s', $d, $m, $y, $h);
    }
};

$canonicalBase = rtrim($config['app']['canonical_origin'] ?? 'https://www.speedracing.com.br', '/');
$postUrl = $canonicalBase . '/' . rawurlencode($post['slug']) . '/';
$postTitleEncoded = rawurlencode($post['title']);
$postUrlEncoded = rawurlencode($postUrl);

// Tags
$tagsList = !empty($post['tags']) ? array_filter(array_map('trim', explode(',', $post['tags']))) : [];
$primarySubtopic = !empty($tagsList[0]) ? $tagsList[0] : $post['category'];
?>

<div class="flex flex-col w-full">
    <!-- Breadcrumbs & Live Pill Strip -->
    <nav aria-label="Navegação Estrutural" class="flex flex-wrap items-center justify-between gap-space-sm pb-space-md">
        <div class="flex items-center gap-space-xs font-label-meta text-label-meta text-secondary dark:text-secondary-fixed-dim">
            <a class="hover:text-racing-red-text dark:hover:text-racing-red-text-dark transition-colors flex items-center gap-1" href="/">
                <?= Icon::svg('home', '', 15) ?>
                Home
            </a>
            <?= Icon::svg('chevron_right', 'text-secondary-fixed-dim', 13) ?>
            <a class="hover:text-racing-red-text dark:hover:text-racing-red-text-dark transition-colors capitalize" href="/categoria/<?= Security::e($post['category']) ?>/">
                <?= Security::e($post['category']) ?>
            </a>
            <?= Icon::svg('chevron_right', 'text-secondary-fixed-dim', 13) ?>
            <span class="text-carbon-black dark:text-pure-white font-semibold truncate max-w-[200px] sm:max-w-xs md:max-w-md">
                <?= Security::e($primarySubtopic) ?>
            </span>
        </div>
        <div class="inline-flex items-center gap-2 px-space-sm py-1 bg-surface-container dark:bg-carbon-surface rounded-full text-label-category font-label-category text-carbon-black dark:text-pure-white uppercase border border-subtle-border dark:border-[#2a303c]">
            <span class="w-2 h-2 rounded-full bg-racing-red animate-pulse"></span>
            <span>SpeedRacing Paddock • F1 &amp; Velocidade</span>
        </div>
    </nav>

    <!-- Main Editorial Grid: 8 Cols Article / 4 Cols Trackside Sidebar -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-gutter items-start">
        
        <!-- Left Column (Article Core - 8 Cols) -->
        <article class="lg:col-span-8 flex flex-col gap-space-lg" <?= empty($isPreview) ? 'data-view-endpoint="/api/view/' . (int)$post['id'] . '"' : '' ?>>
            
            <!-- 1. Header Section -->
            <header class="flex flex-col gap-space-sm">
                <div class="flex items-center gap-space-xs flex-wrap">
                    <a href="/categoria/<?= Security::e($post['category']) ?>/" class="bg-carbon-black text-pure-white text-label-category font-label-category px-space-sm py-1 uppercase rounded-sm hover:bg-racing-red transition-colors">
                        <?= Security::e($post['category']) ?>
                    </a>
                    <span class="bg-surface-container dark:bg-carbon-surface text-racing-red-text dark:text-racing-red-text-dark text-label-category font-label-category px-space-sm py-1 uppercase rounded-sm font-bold border border-subtle-border dark:border-[#2a303c]">
                        <?= Security::e($primarySubtopic) ?>
                    </span>
                    <span class="bg-surface-container-high dark:bg-[#252b36] text-on-surface-variant dark:text-secondary-fixed text-label-category font-label-category px-space-sm py-1 uppercase rounded-sm flex items-center gap-1">
                        <?= Icon::svg('military_tech', 'text-on-surface-variant dark:text-secondary-fixed', 13) ?>
                        Temporada <?= date('Y') ?>
                    </span>
                </div>

                <!-- Título H1 -->
                <h1 class="font-display-hero text-headline-lg md:text-display-hero text-carbon-black dark:text-pure-white leading-[1.12] tracking-tight font-bold">
                    <?= Security::e($post['title']) ?>
                </h1>

                <!-- Resumo / Subtítulo / Lead -->
                <?php if (!empty($post['summary'])): ?>
                    <p class="font-body-lg text-body-lg text-secondary dark:text-secondary-fixed-dim leading-relaxed">
                        <?= Security::e($post['summary']) ?>
                    </p>
                <?php endif; ?>

                <!-- Byline, Publication Time & Social Sharing Deck -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-md py-space-sm mt-space-xs bg-canvas-cream dark:bg-carbon-surface p-space-sm rounded-lg border border-subtle-border dark:border-[#2a303c]">
                    <div class="flex items-center gap-space-sm">
                        <div class="relative w-11 h-11 rounded-full overflow-hidden shrink-0 shadow-sm bg-surface-container dark:bg-[#252b36] flex items-center justify-center font-bold text-carbon-black dark:text-pure-white">
                            <?php if (!empty($post['author_avatar'])): ?>
                                <?= ImageProcessor::renderPicture($post['author_avatar'], (string)($post['author_name'] ?? 'Autor'), ['class' => 'w-full h-full object-cover', 'picture_class' => 'w-full h-full block']) ?>
                            <?php else: ?>
                                <span class="text-racing-red-text dark:text-racing-red-text-dark font-headline-sm"><?= mb_strtoupper(mb_substr($post['author_name'] ?? 'S', 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col">
                            <div class="flex items-center gap-1">
                                <span class="font-headline-sm text-body-md font-bold text-carbon-black dark:text-pure-white">
                                    <?= Security::e($post['author_name'] ?? 'Redação SpeedRacing') ?>
                                </span>
                                <span title="Jornalista Verificado SpeedRacing"><?= Icon::svg('verified', 'text-flag-green', 15) ?></span>
                            </div>
                            <div class="flex items-center gap-2 text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim">
                                <time datetime="<?= $post['published_at'] ?>"><?= $formatPtFullDate($post['published_at']) ?></time>
                                <span>•</span>
                                <span class="flex items-center gap-1 text-carbon-black dark:text-pure-white">
                                    <?= Icon::svg('schedule', 'text-carbon-black dark:text-pure-white', 14) ?> <?= (int)($readingTime ?? 3) ?> min de leitura
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Social Action Ribbon (Botões de Compartilhamento Leves) -->
                    <div class="flex items-center gap-1 shrink-0">
                        <a href="https://twitter.com/intent/tweet?text=<?= $postTitleEncoded ?>&url=<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="w-8 h-8 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors border border-subtle-border dark:border-[#2a303c]" title="Compartilhar no X">
                            <span class="font-headline-sm text-[12px] font-bold">𝕏</span>
                        </a>
                        <a href="https://api.whatsapp.com/send?text=<?= $postTitleEncoded ?>%20-%20<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="w-8 h-8 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-flag-green flex items-center justify-center transition-colors border border-subtle-border dark:border-[#2a303c]" title="Compartilhar no WhatsApp">
                            <?= Icon::svg('chat', 'text-flag-green', 18) ?>
                        </a>
                        <a href="https://t.me/share/url?url=<?= $postUrlEncoded ?>&text=<?= $postTitleEncoded ?>" target="_blank" rel="noopener noreferrer" class="w-8 h-8 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-racing-red-text dark:text-racing-red-text-dark flex items-center justify-center transition-colors border border-subtle-border dark:border-[#2a303c]" title="Compartilhar no Telegram">
                            <?= Icon::svg('send', 'text-racing-red-text dark:text-racing-red-text-dark', 18) ?>
                        </a>
                        <button type="button" class="w-8 h-8 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors border border-subtle-border dark:border-[#2a303c]" id="copy-btn" data-copy-url="<?= Security::e($postUrl) ?>" title="Copiar link da matéria">
                            <span id="copy-icon"><?= Icon::svg('link', 'text-carbon-black dark:text-pure-white', 18) ?></span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- 2. Feature Hero Image Block -->
            <?php if (!empty($post['featured_image'])): ?>
                <?php 
                    $heroImageDesc = ImageProcessor::getImageDescription($post['featured_image'], null, $post['featured_media_id'] ?? null);
                    $heroAltAttr = !empty($heroImageDesc) ? Security::e($heroImageDesc) : '';
                    $heroTitleAttr = !empty($heroImageDesc) ? ' title="' . Security::e($heroImageDesc) . '"' : '';
                ?>
                <figure class="-mx-margin-mobile sm:mx-0 my-space-xs">
                    <div class="relative w-full aspect-[16/9] sm:rounded-xl overflow-hidden shadow-md group bg-carbon-black border border-subtle-border dark:border-[#2a303c]">
                        <?= ImageProcessor::renderPicture(
                            $post['featured_image'],
                            $heroImageDesc,
                            [
                                'class' => 'w-full h-full object-cover transition-transform duration-500 group-hover:scale-[1.01]',
                                'title' => !empty($heroImageDesc) ? $heroImageDesc : null,
                                'loading' => 'eager',
                                'fetchpriority' => 'high',
                                'decoding' => 'async',
                                'picture_class' => 'w-full h-full block',
                            ],
                            $post['featured_media_id'] ?? null
                        ) ?>
                        <div class="absolute top-space-sm left-space-sm bg-carbon-black/85 backdrop-blur-md px-2.5 py-1 rounded text-label-category font-data-telemetry text-pure-white flex items-center gap-1.5 shadow-sm">
                            <span class="w-2 h-2 rounded-full bg-flag-green"></span>
                            <span>SPEEDRACING • <?= Security::e(strtoupper((string)($post['category'] ?? ''))) ?></span>
                        </div>
                    </div>
                </figure>
            <?php endif; ?>

            <!-- 3. Main Narrative Body (HTML Compilado & Sanitizado) -->
            <section class="article-body article-body-post flex flex-col gap-space-md">
                <?= $post['content_html'] ?>
            </section>

            <!-- 4. Tags Strip -->
            <?php if (!empty($tagsList)): ?>
                <div class="flex items-center gap-space-xs flex-wrap py-space-md border-t border-b border-subtle-border dark:border-[#2a303c]">
                    <span class="font-label-category text-label-category text-secondary dark:text-secondary-fixed uppercase">Tags:</span>
                    <?php foreach ($tagsList as $tItem): ?>
                        <a class="px-space-sm py-1 bg-surface-container dark:bg-carbon-surface rounded text-label-meta font-label-meta text-carbon-black dark:text-pure-white hover:bg-racing-red hover:text-pure-white dark:hover:bg-racing-red transition-colors border border-subtle-border dark:border-[#2a303c]" href="/tag/<?= urlencode(Security::slugify($tItem)) ?>/">
                            #<?= Security::e($tItem) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- 5. Author Profile Dossier Box -->
            <aside class="p-space-lg bg-canvas-cream dark:bg-carbon-surface rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c] flex flex-col sm:flex-row items-start sm:items-center gap-space-md">
                <div class="flex items-center gap-space-md">
                    <div class="w-16 h-16 rounded-full overflow-hidden shrink-0 shadow-md bg-surface-container dark:bg-[#252b36] flex items-center justify-center font-bold text-carbon-black dark:text-pure-white text-xl">
                        <?php if (!empty($post['author_avatar'])): ?>
                            <?= ImageProcessor::renderPicture($post['author_avatar'], (string)($post['author_name'] ?? 'Autor'), ['class' => 'w-full h-full object-cover', 'picture_class' => 'w-full h-full block']) ?>
                        <?php else: ?>
                            <span class="text-racing-red-text dark:text-racing-red-text-dark"><?= mb_strtoupper(mb_substr($post['author_name'] ?? 'S', 0, 1)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <h4 class="font-headline-sm text-headline-sm text-carbon-black dark:text-pure-white font-bold">
                                <?= Security::e($post['author_name'] ?? 'Redação SpeedRacing') ?>
                            </h4>
                            <span class="px-1.5 py-0.5 bg-carbon-black dark:bg-[#252b36] text-pure-white text-[10px] font-label-category rounded uppercase">
                                Redação Oficial
                            </span>
                        </div>
                        <p class="font-body-sm text-body-sm text-secondary dark:text-secondary-fixed-dim mt-1 max-w-xl">
                            <?= Security::e($post['author_bio'] ?? 'Cobertura técnica in loco, telemetria de precisão e bastidores do automobilismo mundial pelo SpeedRacing desde 2002.') ?>
                        </p>
                    </div>
                </div>
            </aside>
        </article>

        <!-- Right Column (Trackside Technical Sidebar - 4 Cols) -->
        <aside class="lg:col-span-4 flex flex-col gap-space-lg">
            
            <!-- Banner Parceiro 1 (Substitui Paddock Radar) -->
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

            <!-- Banner Parceiro 2 (Substitui Mundial de Construtores) -->
            <div class="rounded-xl overflow-hidden border border-subtle-border dark:border-[#2a303c] bg-pure-white dark:bg-carbon-surface shadow-xs p-space-sm flex flex-col items-center">
                <div class="w-full flex items-center justify-between pb-2 px-1 text-[10px] font-label-category uppercase tracking-widest text-secondary dark:text-secondary-fixed-dim">
                    <span>Publicidade</span>
                    <span class="text-racing-red-text dark:text-racing-red-text-dark font-bold">Parceiro Oficial</span>
                </div>
                <div class="w-full aspect-[300/250] bg-surface-container-low dark:bg-[#151920] rounded-lg border border-dashed border-subtle-border dark:border-[#2a303c] flex flex-col items-center justify-center p-4 text-center group hover:border-racing-red transition-colors">
                    <?= Icon::svg('verified', 'text-racing-red-text dark:text-racing-red-text-dark mb-2 group-hover:scale-110 transition-transform', 42) ?>
                    <span class="font-headline-sm text-body-md font-bold text-carbon-black dark:text-pure-white">Espaço Parceiro SpeedRacing</span>
                    <span class="text-label-meta text-[11px] text-secondary dark:text-secondary-fixed-dim mt-1">300x250 • Banner Publicitário</span>
                    <a href="/contato/" class="mt-3 px-space-md py-1.5 rounded bg-racing-red hover:bg-tertiary text-pure-white font-label-category text-[11px] uppercase font-bold transition-colors shadow-xs">
                        Anuncie Aqui
                    </a>
                </div>
            </div>

            <!-- Widget 3: Matérias Relacionadas do Banco de Dados -->
            <?php if (!empty($relatedPosts)): ?>
                <div class="bg-pure-white dark:bg-carbon-surface rounded-xl p-space-md shadow-xs border border-subtle-border dark:border-[#2a303c] flex flex-col gap-space-sm">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <?= Icon::svg('recommend', 'text-racing-red-text dark:text-racing-red-text-dark', 20) ?>
                            <h4 class="font-headline-sm text-headline-sm text-carbon-black dark:text-pure-white font-bold">Notícias Relacionadas</h4>
                        </div>
                        <?= Icon::svg('trending_up', 'text-secondary dark:text-secondary-fixed-dim', 18) ?>
                    </div>
                    <div class="flex flex-col gap-space-sm mt-1">
                        <?php foreach ($relatedPosts as $rel): ?>
                            <a class="group flex gap-space-sm items-start p-space-xs rounded-lg hover:bg-canvas-cream dark:hover:bg-[#252b36] transition-colors border border-transparent hover:border-subtle-border dark:hover:border-[#2a303c]" href="/<?= Security::e($rel['slug']) ?>/">
                                <div class="w-20 h-20 rounded-md overflow-hidden shrink-0 shadow-xs relative bg-surface-container dark:bg-[#151920]">
                                    <?php if (!empty($rel['featured_image'])): ?>
                                        <?php 
                                            $relDesc = ImageProcessor::getImageDescription($rel['featured_image']);
                                            $relAltAttr = !empty($relDesc) ? Security::e($relDesc) : '';
                                            $relTitleAttr = !empty($relDesc) ? ' title="' . Security::e($relDesc) . '"' : '';
                                        ?>
                                        <figure class="w-full h-full m-0 p-0 overflow-hidden">
                                            <img alt="<?= $relAltAttr ?>"<?= $relTitleAttr ?> class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" src="<?= Security::e(ImageProcessor::toThumbUrl($rel['featured_image'], 'avif')) ?>" loading="lazy">
                                        </figure>
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-[10px] text-secondary">Sem foto</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col gap-1 min-w-0 flex-1">
                                    <span class="text-[10px] font-label-category uppercase text-racing-red-text dark:text-racing-red-text-dark font-bold">
                                        <?= Security::e($rel['category']) ?>
                                    </span>
                                    <h5 class="font-headline-sm text-body-sm font-semibold text-carbon-black dark:text-pure-white group-hover:text-racing-red-text dark:group-hover:text-racing-red-text-dark line-clamp-2 transition-colors leading-snug">
                                        <?= Security::e($rel['title']) ?>
                                    </h5>
                                    <span class="text-[11px] font-label-meta text-secondary dark:text-secondary-fixed-dim">
                                        <?= date('d/m/Y', strtotime($rel['published_at'])) ?>
                                    </span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- CANAL OFICIAL WHATSAPP -->
            <div id="newsletter" class="bg-carbon-black text-pure-white rounded-xl p-space-lg shadow-md relative overflow-hidden flex flex-col gap-space-sm border border-subtle-border/30 dark:border-[#2a303c]">
                <div class="w-24 h-24 rounded-full bg-racing-red/20 blur-2xl absolute -top-8 -right-8 pointer-events-none"></div>
                <div class="flex items-center gap-2">
                    <?= Icon::svg('chat', 'text-flag-green', 24) ?>
                    <span class="font-label-category text-label-category uppercase tracking-wider text-pure-white font-bold">Canal Oficial</span>
                </div>
                <h4 class="font-headline-sm text-headline-sm text-pure-white leading-tight font-bold">
                    Receba notícias e resultados direto no WhatsApp
                </h4>
                <p class="font-body-sm text-body-sm text-surface-container">
                    Siga o canal oficial do SpeedRacing para acompanhar bastidores, classificações e matérias em tempo real.
                </p>
                <a href="https://whatsapp.com/channel/0029VbDiks83GJP7Lbzm2d3c"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="w-full py-2.5 px-3 bg-whatsapp-green hover:opacity-90 text-pure-white font-label-category text-label-category uppercase font-bold rounded-lg transition-opacity flex items-center justify-center gap-2 shadow-sm mt-1">
                    <span>Entrar no Canal do WhatsApp</span>
                    <?= Icon::svg('open_in_new', 'text-pure-white', 16) ?>
                </a>
                <span class="text-[10px] font-label-meta text-surface-container-high/60 text-center">
                    Sem spam. Seu número permanece oculto para todos.
                </span>
            </div>

        </aside>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
