<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';

// Formatador de data por extenso em português
$ptMonths = [
    1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
    5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
    9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'
];
$formatPtFullDate = function(?string $dateStr) use ($ptMonths): string {
    if (empty($dateStr)) return date('d/m/Y');
    $t = strtotime($dateStr);
    $d = date('j', $t);
    $m = $ptMonths[(int)date('n', $t)] ?? date('m', $t);
    $y = date('Y', $t);
    $h = date('H:i', $t);
    return sprintf('%02d de %s de %d às %s', $d, $m, $y, $h);
};

$canonicalBase = rtrim($config['app']['url'] ?? 'https://lightpress.suainternet.com', '/');
$postUrl = $canonicalBase . '/' . rawurlencode($post['slug']) . '/';
$postTitleEncoded = rawurlencode($post['title']);
$postUrlEncoded = rawurlencode($postUrl);

// Tags
$tagsList = !empty($post['tags']) ? array_filter(array_map('trim', explode(',', $post['tags']))) : [];
$primarySubtopic = !empty($tagsList[0]) ? $tagsList[0] : $post['category'];
?>

<style>
    /* Estilos semânticos para o corpo da matéria (.article-body) */
    .article-body {
        font-family: 'Work Sans', sans-serif;
        font-size: 1.125rem;
        line-height: 1.85;
        color: #141d23;
    }
    html.dark .article-body {
        color: #d1d5db;
    }
    .article-body > p:first-of-type::first-letter {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 3.5rem;
        float: left;
        margin-right: 0.75rem;
        line-height: 0.85;
        color: #E10600;
        font-weight: 700;
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
        margin-top: 2.5rem;
        margin-bottom: 1rem;
        color: #111418;
    }
    html.dark .article-body h2 {
        color: #FFFFFF;
    }
    .article-body h3 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.5rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        line-height: 1.3;
        margin-top: 2rem;
        margin-bottom: 0.75rem;
        color: #111418;
    }
    html.dark .article-body h3 {
        color: #FFFFFF;
    }
    .article-body h4 {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.25rem;
        font-weight: 600;
        margin-top: 1.75rem;
        margin-bottom: 0.5rem;
        color: #111418;
    }
    html.dark .article-body h4 {
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
        color: #af101a;
    }
    html.dark .article-body a:hover {
        color: #ffb4a8;
    }
    .article-body strong, .article-body b {
        font-weight: 700;
        color: #111418;
    }
    html.dark .article-body strong, html.dark .article-body b {
        color: #FFFFFF;
    }
    .article-body blockquote {
        margin: 2rem 0;
        padding: 1.25rem 1.75rem;
        border-left: 4px solid #E10600;
        background: #F9FAFB;
        border-radius: 0 0.75rem 0.75rem 0;
        font-style: italic;
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.25rem;
        line-height: 1.5;
        color: #111418;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    html.dark .article-body blockquote {
        background: #1A1E24;
        color: #FFFFFF;
        box-shadow: 0 1px 6px rgba(0,0,0,0.3);
    }
    .article-body figure {
        margin: 2.25rem 0;
    }
    .article-body img {
        max-width: 100%;
        height: auto;
        border-radius: 0.75rem;
        display: block;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        border: 1px solid #E5E7EB;
    }
    html.dark .article-body img {
        border-color: #2a303c;
    }
    .article-body figcaption {
        font-size: 0.875rem;
        color: #5c5e63;
        text-align: center;
        margin-top: 0.5rem;
        font-family: 'Work Sans', sans-serif;
    }
    html.dark .article-body figcaption {
        color: #c5c6cc;
    }
    .article-body ul {
        list-style-type: disc;
        margin: 1.5rem 0;
        padding-left: 1.75rem;
    }
    .article-body ol {
        list-style-type: decimal;
        margin: 1.5rem 0;
        padding-left: 1.75rem;
    }
    .article-body li {
        margin-bottom: 0.5rem;
        line-height: 1.75;
    }
    .article-body hr {
        margin: 2.5rem 0;
        border: none;
        border-top: 1px solid #E5E7EB;
    }
    html.dark .article-body hr {
        border-top: 1px solid #2a303c;
    }
</style>

<div class="flex flex-col w-full">
    <!-- Breadcrumbs & Live Pill Strip -->
    <nav aria-label="Navegação Estrutural" class="flex flex-wrap items-center justify-between gap-space-sm pb-space-md">
        <div class="flex items-center gap-space-xs font-label-meta text-label-meta text-secondary dark:text-secondary-fixed-dim">
            <a class="hover:text-racing-red transition-colors flex items-center gap-1" href="/">
                <span class="material-symbols-outlined text-[16px]">home</span>
                Home
            </a>
            <span class="material-symbols-outlined text-[14px] text-secondary-fixed-dim">chevron_right</span>
            <a class="hover:text-racing-red transition-colors capitalize" href="/categoria/<?= Security::e($post['category']) ?>/">
                <?= Security::e($post['category']) ?>
            </a>
            <span class="material-symbols-outlined text-[14px] text-secondary-fixed-dim">chevron_right</span>
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
        <article class="lg:col-span-8 flex flex-col gap-space-lg">
            
            <!-- 1. Header Section -->
            <header class="flex flex-col gap-space-sm">
                <div class="flex items-center gap-space-xs flex-wrap">
                    <a href="/categoria/<?= Security::e($post['category']) ?>/" class="bg-carbon-black text-pure-white text-label-category font-label-category px-space-sm py-1 uppercase rounded-sm hover:bg-racing-red transition-colors">
                        <?= Security::e($post['category']) ?>
                    </a>
                    <span class="bg-surface-container dark:bg-carbon-surface text-racing-red text-label-category font-label-category px-space-sm py-1 uppercase rounded-sm font-bold border border-subtle-border dark:border-[#2a303c]">
                        <?= Security::e($primarySubtopic) ?>
                    </span>
                    <span class="bg-surface-container-high dark:bg-[#252b36] text-on-surface-variant dark:text-secondary-fixed text-label-category font-label-category px-space-sm py-1 uppercase rounded-sm flex items-center gap-1">
                        <span class="material-symbols-outlined text-[13px]">military_tech</span>
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
                                <img alt="<?= Security::e($post['author_name'] ?? 'Autor') ?>" class="w-full h-full object-cover" src="<?= Security::e(ImageProcessor::toAvifUrl($post['author_avatar'])) ?>">
                            <?php else: ?>
                                <span class="text-racing-red font-headline-sm"><?= mb_strtoupper(mb_substr($post['author_name'] ?? 'S', 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col">
                            <div class="flex items-center gap-1">
                                <span class="font-headline-sm text-body-md font-bold text-carbon-black dark:text-pure-white">
                                    <?= Security::e($post['author_name'] ?? 'Redação SpeedRacing') ?>
                                </span>
                                <span class="material-symbols-outlined text-flag-green text-[15px]" title="Jornalista Verificado SpeedRacing">verified</span>
                            </div>
                            <div class="flex items-center gap-2 text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim">
                                <time datetime="<?= $post['published_at'] ?>"><?= $formatPtFullDate($post['published_at']) ?></time>
                                <span>•</span>
                                <span class="flex items-center gap-1 text-carbon-black dark:text-pure-white">
                                    <span class="material-symbols-outlined text-[14px]">schedule</span> <?= (int)($readingTime ?? 3) ?> min de leitura
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
                            <span class="material-symbols-outlined text-[18px]">chat</span>
                        </a>
                        <a href="https://t.me/share/url?url=<?= $postUrlEncoded ?>&text=<?= $postTitleEncoded ?>" target="_blank" rel="noopener noreferrer" class="w-8 h-8 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-racing-red flex items-center justify-center transition-colors border border-subtle-border dark:border-[#2a303c]" title="Compartilhar no Telegram">
                            <span class="material-symbols-outlined text-[18px]">send</span>
                        </a>
                        <button type="button" class="w-8 h-8 rounded-lg bg-surface-container dark:bg-[#252b36] hover:bg-surface-container-high dark:hover:bg-[#2f3744] text-carbon-black dark:text-pure-white flex items-center justify-center transition-colors border border-subtle-border dark:border-[#2a303c]" id="copy-btn" onclick="copyPostLink(this, '<?= Security::e($postUrl) ?>')" title="Copiar link da matéria">
                            <span class="material-symbols-outlined text-[18px]">link</span>
                        </button>
                        <div class="h-4 w-px bg-surface-variant dark:bg-[#2a303c] mx-1"></div>
                        <a class="flex items-center gap-1 px-space-xs py-1 rounded-lg text-label-meta font-label-meta text-secondary dark:text-secondary-fixed hover:text-carbon-black dark:hover:text-pure-white" href="#comments-anchor">
                            <span class="material-symbols-outlined text-[16px]">mode_comment</span>
                            <span class="font-semibold text-carbon-black dark:text-pure-white">Debater</span>
                        </a>
                    </div>
                </div>
            </header>

            <!-- 2. Feature Hero Image Block -->
            <?php if (!empty($post['featured_image'])): ?>
                <figure class="flex flex-col gap-space-xs -mx-margin-mobile sm:mx-0">
                    <div class="relative w-full aspect-[16/9] sm:rounded-xl overflow-hidden shadow-md group bg-carbon-black border border-subtle-border dark:border-[#2a303c]">
                        <img alt="<?= Security::e($post['title']) ?>" 
                             class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-[1.01]" 
                             src="<?= Security::e(ImageProcessor::toAvifUrl($post['featured_image'])) ?>">
                        <div class="absolute top-space-sm left-space-sm bg-carbon-black/85 backdrop-blur-md px-2.5 py-1 rounded text-label-category font-data-telemetry text-pure-white flex items-center gap-1.5 shadow-sm">
                            <span class="w-2 h-2 rounded-full bg-flag-green"></span>
                            <span>SPEEDRACING • <?= Security::e(strtoupper($post['category'])) ?></span>
                        </div>
                        <div class="absolute bottom-0 inset-x-0 bg-gradient-to-t from-carbon-black/90 via-carbon-black/40 to-transparent p-space-md text-pure-white flex items-end justify-between">
                            <div class="text-label-meta font-label-meta text-surface-container-lowest/90 truncate max-w-sm sm:max-w-md">
                                <span><?= Security::e($post['title']) ?></span>
                            </div>
                            <span class="font-label-category text-[10px] text-surface-variant uppercase tracking-widest bg-carbon-surface/70 px-2 py-0.5 rounded shrink-0">
                                Foto: Acervo SpeedRacing
                            </span>
                        </div>
                    </div>
                    <figcaption class="px-space-xs text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim flex items-center justify-between">
                        <span class="truncate"><?= Security::e($post['title']) ?></span>
                        <span class="shrink-0 text-carbon-black dark:text-pure-white font-semibold">SpeedRacing Press</span>
                    </figcaption>
                </figure>
            <?php endif; ?>

            <!-- 3. Main Narrative Body (HTML Compilado & Sanitizado) -->
            <section class="article-body flex flex-col gap-space-md">
                <?= $post['content_html'] ?>
            </section>

            <!-- 4. Tags & Social Engagement Strip -->
            <?php if (!empty($tagsList)): ?>
                <div class="flex flex-wrap items-center justify-between gap-space-sm py-space-md border-t border-b border-subtle-border dark:border-[#2a303c]">
                    <div class="flex items-center gap-space-xs flex-wrap">
                        <span class="font-label-category text-label-category text-secondary dark:text-secondary-fixed uppercase">Tags:</span>
                        <?php foreach ($tagsList as $tItem): ?>
                            <a class="px-space-sm py-1 bg-surface-container dark:bg-carbon-surface rounded text-label-meta font-label-meta text-carbon-black dark:text-pure-white hover:bg-racing-red hover:text-pure-white dark:hover:bg-racing-red transition-colors border border-subtle-border dark:border-[#2a303c]" href="/tag/<?= urlencode(Security::slugify($tItem)) ?>/">
                                #<?= Security::e($tItem) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <button class="flex items-center gap-2 px-space-md py-2 bg-canvas-cream dark:bg-carbon-surface hover:bg-surface-container dark:hover:bg-[#252b36] rounded-lg text-carbon-black dark:text-pure-white font-label-category text-label-category transition-colors border border-subtle-border dark:border-[#2a303c]" id="like-btn" onclick="toggleLike(<?= (int)$post['id'] ?>)">
                        <span class="material-symbols-outlined text-racing-red text-[18px]" id="like-icon">favorite</span>
                        <span>CURTIR ANÁLISE (<span id="like-count">184</span>)</span>
                    </button>
                </div>
            <?php endif; ?>

            <!-- 5. Author Profile Dossier Box -->
            <aside class="p-space-lg bg-canvas-cream dark:bg-carbon-surface rounded-xl shadow-xs border border-subtle-border dark:border-[#2a303c] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-space-md">
                <div class="flex items-center gap-space-md">
                    <div class="w-16 h-16 rounded-full overflow-hidden shrink-0 shadow-md bg-surface-container dark:bg-[#252b36] flex items-center justify-center font-bold text-carbon-black dark:text-pure-white text-xl">
                        <?php if (!empty($post['author_avatar'])): ?>
                            <img alt="<?= Security::e($post['author_name'] ?? 'Autor') ?>" class="w-full h-full object-cover" src="<?= Security::e(ImageProcessor::toAvifUrl($post['author_avatar'])) ?>">
                        <?php else: ?>
                            <span class="text-racing-red"><?= mb_strtoupper(mb_substr($post['author_name'] ?? 'S', 0, 1)) ?></span>
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
                        <p class="font-body-sm text-body-sm text-secondary dark:text-secondary-fixed-dim mt-1 max-w-lg">
                            <?= Security::e($post['author_bio'] ?? 'Cobertura técnica in loco, telemetria de precisão e bastidores do automobilismo mundial pelo SpeedRacing desde 2002.') ?>
                        </p>
                    </div>
                </div>
                <a href="#newsletter" class="shrink-0 px-space-md py-2 bg-carbon-black hover:bg-racing-red dark:bg-[#252b36] dark:hover:bg-racing-red text-pure-white rounded font-label-category text-label-category uppercase tracking-wider transition-colors shadow-xs">
                    + Seguir Colunista
                </a>
            </aside>

            <!-- 6. Debate no Pit Lane (Comments & Discussion Anchor) -->
            <section class="flex flex-col gap-space-md mt-space-sm" id="comments-anchor">
                <div class="flex items-center justify-between">
                    <h3 class="font-headline-sm text-headline-sm text-carbon-black dark:text-pure-white font-bold flex items-center gap-2">
                        <span class="material-symbols-outlined text-racing-red">forum</span>
                        Debate no Pit Lane
                    </h3>
                    <span class="text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim">Moderação ativa SpeedRacing</span>
                </div>

                <!-- Formulário de Comentário -->
                <div class="flex flex-col gap-space-xs p-space-md bg-canvas-cream dark:bg-carbon-surface rounded-xl border border-subtle-border dark:border-[#2a303c]">
                    <textarea id="comment-input" class="w-full p-space-sm rounded-lg bg-pure-white dark:bg-[#151920] text-carbon-black dark:text-pure-white font-body-sm text-body-sm focus:outline-none focus:ring-1 focus:ring-racing-red border border-subtle-border dark:border-[#2a303c] resize-none shadow-xs placeholder:text-secondary dark:placeholder:text-secondary-fixed-dim" placeholder="O que você achou desta análise técnica? Participe da discussão no paddock..." rows="3"></textarea>
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 pt-1">
                        <span class="text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim">Respeite as diretrizes da comunidade motorsport.</span>
                        <button type="button" onclick="submitComment()" class="px-space-md py-2 bg-racing-red hover:bg-tertiary-container text-pure-white font-label-category text-label-category uppercase rounded font-bold transition-colors shadow-xs">
                            Publicar Comentário
                        </button>
                    </div>
                </div>

                <!-- Comentário em Destaque -->
                <div class="p-space-md bg-canvas-cream dark:bg-carbon-surface rounded-xl flex flex-col gap-space-xs shadow-xs border border-subtle-border dark:border-[#2a303c]" id="comments-list">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-surface-container-high dark:bg-[#252b36] flex items-center justify-center font-bold text-carbon-black dark:text-pure-white text-body-sm">
                                MT
                            </div>
                            <div>
                                <span class="font-headline-sm text-body-md text-carbon-black dark:text-pure-white font-bold">Marcelo Toledo</span>
                                <span class="text-label-meta text-[11px] text-secondary dark:text-secondary-fixed-dim ml-2">Há 45 minutos</span>
                            </div>
                        </div>
                        <span class="px-2 py-0.5 bg-surface-container dark:bg-[#252b36] rounded text-label-category text-[10px] text-secondary dark:text-secondary-fixed uppercase font-semibold">Engenheiro Mecânico</span>
                    </div>
                    <p class="font-body-md text-body-md text-carbon-black dark:text-pure-white mt-1">
                        A análise técnica está impecável. As configurações de asa e carga aerodinâmica são determinantes para o ritmo de corrida nos trechos de alta velocidade. Parabéns à redação SpeedRacing pela cobertura com rigor técnico!
                    </p>
                    <div class="flex items-center gap-space-md mt-1 text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim">
                        <button class="flex items-center gap-1 hover:text-racing-red"><span class="material-symbols-outlined text-[15px]">thumb_up</span> 18</button>
                        <button class="flex items-center gap-1 hover:text-racing-red"><span class="material-symbols-outlined text-[15px]">reply</span> Responder</button>
                    </div>
                </div>
            </section>
        </article>

        <!-- Right Column (Trackside Technical Sidebar - 4 Cols) -->
        <aside class="lg:col-span-4 flex flex-col gap-space-lg">
            
            <!-- Widget 1: Track Weather Radar & Paddock Telemetry -->
            <div class="bg-carbon-black text-pure-white rounded-xl p-space-md shadow-md flex flex-col gap-space-sm border border-subtle-border/30 dark:border-[#2a303c]">
                <div class="flex items-center justify-between pb-2 border-b border-track-asphalt">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-flag-green animate-pulse"></span>
                        <span class="font-label-category text-label-category uppercase tracking-wider text-pure-white font-bold">Paddock Radar • Ao Vivo</span>
                    </div>
                    <span class="font-data-telemetry text-data-telemetry text-flag-yellow">INTERLAGOS <?= date('H:i') ?></span>
                </div>
                <div class="grid grid-cols-2 gap-space-xs pt-1">
                    <div class="p-space-sm bg-carbon-surface rounded-lg flex flex-col border border-[#2a303c]/60">
                        <span class="text-[11px] font-label-category uppercase text-secondary-fixed">Temperatura da Pista</span>
                        <span class="font-data-telemetry text-[22px] font-bold text-racing-red mt-1">42.8° C</span>
                        <span class="text-[10px] font-label-meta text-surface-container-high/60">Asfalto aquecido</span>
                    </div>
                    <div class="p-space-sm bg-carbon-surface rounded-lg flex flex-col border border-[#2a303c]/60">
                        <span class="text-[11px] font-label-category uppercase text-secondary-fixed">Temperatura do Ar</span>
                        <span class="font-data-telemetry text-[22px] font-bold text-pure-white mt-1">26.2° C</span>
                        <span class="text-[10px] font-label-meta text-surface-container-high/60">Umidade: 58%</span>
                    </div>
                    <div class="p-space-sm bg-carbon-surface rounded-lg flex flex-col border border-[#2a303c]/60">
                        <span class="text-[11px] font-label-category uppercase text-secondary-fixed">Vento no Circuito</span>
                        <span class="font-data-telemetry text-[22px] font-bold text-pure-white mt-1">14.1 <span class="text-xs">km/h</span></span>
                        <span class="text-[10px] font-label-meta text-surface-container-high/60">Direção: Noroeste</span>
                    </div>
                    <div class="p-space-sm bg-carbon-surface rounded-lg flex flex-col border border-[#2a303c]/60">
                        <span class="text-[11px] font-label-category uppercase text-secondary-fixed">Risco de Chuva</span>
                        <span class="font-data-telemetry text-[22px] font-bold text-flag-yellow mt-1">20%</span>
                        <span class="text-[10px] font-label-meta text-surface-container-high/60">Nuvens sobre o vale</span>
                    </div>
                </div>
                <div class="mt-1 p-2 bg-carbon-surface/60 rounded flex items-center gap-2 text-label-meta text-[11px] text-surface-container-high">
                    <span class="material-symbols-outlined text-[16px] text-flag-green">sensors</span>
                    <span>Sensores oficiais FIA / CBA sincronizados em tempo real.</span>
                </div>
            </div>

            <!-- Widget 2: World Championship Standings 2026 -->
            <div class="bg-pure-white dark:bg-carbon-surface rounded-xl p-space-md shadow-xs border border-subtle-border dark:border-[#2a303c] flex flex-col gap-space-sm">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-racing-red text-[20px]">emoji_events</span>
                        <h4 class="font-headline-sm text-headline-sm text-carbon-black dark:text-pure-white font-bold">Mundial de Construtores</h4>
                    </div>
                    <span class="text-label-category text-[10px] bg-surface-container dark:bg-[#252b36] px-2 py-0.5 rounded font-bold text-secondary dark:text-secondary-fixed uppercase">Temporada <?= date('Y') ?></span>
                </div>
                <p class="font-body-sm text-body-sm text-secondary dark:text-secondary-fixed-dim">
                    Disputa acirrada pelo topo da tabela nas principais categorias do automobilismo mundial.
                </p>
                <!-- Tabela de Classificação -->
                <div class="flex flex-col gap-1.5 mt-1">
                    <!-- Row 1 (Mercedes) -->
                    <div class="flex items-center justify-between p-2.5 bg-canvas-cream dark:bg-[#151920] rounded-lg shadow-2xs border-l-4 border-racing-red border border-subtle-border/60 dark:border-[#2a303c]">
                        <div class="flex items-center gap-2">
                            <span class="font-data-telemetry text-body-md font-bold text-carbon-black dark:text-pure-white w-4">1</span>
                            <div class="flex flex-col">
                                <span class="font-headline-sm text-body-md text-carbon-black dark:text-pure-white font-bold">Mercedes-AMG</span>
                                <span class="text-[11px] text-secondary dark:text-secondary-fixed-dim font-label-meta">Russell / Antonelli</span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="font-data-telemetry text-body-md font-bold text-carbon-black dark:text-pure-white">512 pts</span>
                            <span class="text-[10px] text-flag-green font-semibold">Líder</span>
                        </div>
                    </div>
                    <!-- Row 2 (Ferrari) -->
                    <div class="flex items-center justify-between p-2.5 bg-canvas-cream dark:bg-[#151920] rounded-lg shadow-2xs border border-subtle-border/60 dark:border-[#2a303c]">
                        <div class="flex items-center gap-2">
                            <span class="font-data-telemetry text-body-md font-bold text-secondary dark:text-secondary-fixed w-4">2</span>
                            <div class="flex flex-col">
                                <span class="font-headline-sm text-body-md text-carbon-black dark:text-pure-white font-bold">Scuderia Ferrari</span>
                                <span class="text-[11px] text-secondary dark:text-secondary-fixed-dim font-label-meta">Leclerc / Hamilton</span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="font-data-telemetry text-body-md font-bold text-carbon-black dark:text-pure-white">489 pts</span>
                            <span class="text-[10px] text-racing-red font-semibold">-23 pts</span>
                        </div>
                    </div>
                    <!-- Row 3 (McLaren) -->
                    <div class="flex items-center justify-between p-2.5 bg-canvas-cream dark:bg-[#151920] rounded-lg shadow-2xs border border-subtle-border/60 dark:border-[#2a303c]">
                        <div class="flex items-center gap-2">
                            <span class="font-data-telemetry text-body-md font-bold text-secondary dark:text-secondary-fixed w-4">3</span>
                            <div class="flex flex-col">
                                <span class="font-headline-sm text-body-md text-carbon-black dark:text-pure-white font-bold">McLaren F1 Team</span>
                                <span class="text-[11px] text-secondary dark:text-secondary-fixed-dim font-label-meta">Norris / Piastri</span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="font-data-telemetry text-body-md font-bold text-carbon-black dark:text-pure-white">462 pts</span>
                            <span class="text-[10px] text-secondary dark:text-secondary-fixed-dim font-semibold">-50 pts</span>
                        </div>
                    </div>
                    <!-- Row 4 (Red Bull) -->
                    <div class="flex items-center justify-between p-2.5 bg-canvas-cream dark:bg-[#151920] rounded-lg shadow-2xs border border-subtle-border/60 dark:border-[#2a303c]">
                        <div class="flex items-center gap-2">
                            <span class="font-data-telemetry text-body-md font-bold text-secondary dark:text-secondary-fixed w-4">4</span>
                            <div class="flex flex-col">
                                <span class="font-headline-sm text-body-md text-carbon-black dark:text-pure-white font-bold">Red Bull Racing</span>
                                <span class="text-[11px] text-secondary dark:text-secondary-fixed-dim font-label-meta">Verstappen / Lawson</span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="font-data-telemetry text-body-md font-bold text-carbon-black dark:text-pure-white">398 pts</span>
                            <span class="text-[10px] text-secondary dark:text-secondary-fixed-dim font-semibold">-114 pts</span>
                        </div>
                    </div>
                </div>
                <a class="text-center pt-2 text-label-category font-label-category text-racing-red hover:underline uppercase tracking-wider text-[11px] font-bold" href="/categoria/formula-1/">
                    Ver Calendário e Notícias de F1 →
                </a>
            </div>

            <!-- Widget 3: Matérias Relacionadas do Banco de Dados -->
            <?php if (!empty($relatedPosts)): ?>
                <div class="bg-pure-white dark:bg-carbon-surface rounded-xl p-space-md shadow-xs border border-subtle-border dark:border-[#2a303c] flex flex-col gap-space-sm">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-racing-red text-[20px]">recommend</span>
                            <h4 class="font-headline-sm text-headline-sm text-carbon-black dark:text-pure-white font-bold">Notícias Relacionadas</h4>
                        </div>
                        <span class="material-symbols-outlined text-secondary text-[18px]">trending_up</span>
                    </div>
                    <div class="flex flex-col gap-space-sm mt-1">
                        <?php foreach ($relatedPosts as $rel): ?>
                            <a class="group flex gap-space-sm items-start p-space-xs rounded-lg hover:bg-canvas-cream dark:hover:bg-[#252b36] transition-colors border border-transparent hover:border-subtle-border dark:hover:border-[#2a303c]" href="/<?= Security::e($rel['slug']) ?>/">
                                <div class="w-20 h-20 rounded-md overflow-hidden shrink-0 shadow-xs relative bg-surface-container dark:bg-[#151920]">
                                    <?php if (!empty($rel['featured_image'])): ?>
                                        <img alt="<?= Security::e($rel['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" src="<?= Security::e(ImageProcessor::toThumbUrl($rel['featured_image'], 'avif')) ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-[10px] text-secondary">Sem foto</div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col gap-1 min-w-0 flex-1">
                                    <span class="text-[10px] font-label-category uppercase text-racing-red font-bold">
                                        <?= Security::e($rel['category']) ?>
                                    </span>
                                    <h5 class="font-headline-sm text-body-sm font-semibold text-carbon-black dark:text-pure-white group-hover:text-racing-red dark:group-hover:text-racing-red line-clamp-2 transition-colors leading-snug">
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

            <!-- Widget 4: High Performance Newsletter Box -->
            <div id="newsletter" class="bg-carbon-black text-pure-white rounded-xl p-space-lg shadow-md relative overflow-hidden flex flex-col gap-space-sm border border-subtle-border/30 dark:border-[#2a303c]">
                <div class="w-24 h-24 rounded-full bg-racing-red/20 blur-2xl absolute -top-8 -right-8 pointer-events-none"></div>
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-racing-red text-[24px]">mark_email_unread</span>
                    <span class="font-label-category text-label-category uppercase tracking-wider text-pure-white font-bold">Grid SpeedRacing</span>
                </div>
                <h4 class="font-headline-sm text-headline-sm text-pure-white leading-tight font-bold">
                    Receba as análises técnicas e telemetria antes de todo mundo
                </h4>
                <p class="font-body-sm text-body-sm text-surface-container">
                    Toda manhã direto na sua caixa de entrada, com rigor editorial e sem enrolação.
                </p>
                <form class="flex flex-col gap-space-xs mt-1" onsubmit="event.preventDefault(); alert('Inscrição confirmada no Grid SpeedRacing!');">
                    <input class="w-full px-space-sm py-2.5 rounded-lg bg-carbon-surface text-pure-white text-body-sm placeholder:text-secondary-fixed focus:outline-none focus:ring-1 focus:ring-racing-red border border-[#2a303c]" placeholder="Seu melhor e-mail" required="" type="email"/>
                    <button class="w-full py-2.5 bg-racing-red hover:bg-tertiary text-pure-white font-label-category text-label-category uppercase font-bold rounded-lg transition-colors flex items-center justify-center gap-1 shadow-sm" type="submit">
                        <span>Garantir Vaga no Grid</span>
                        <span class="material-symbols-outlined text-[16px]">chevron_right</span>
                    </button>
                </form>
                <span class="text-[10px] font-label-meta text-surface-container-high/60 text-center">
                    Zero spam. Desinscreva-se a qualquer momento com 1 clique.
                </span>
            </div>

        </aside>
    </div>
</div>

<!-- Scripts de Interação da Matéria -->
<script>
function copyPostLink(btn, url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            var icon = btn.querySelector('.material-symbols-outlined');
            if (icon) {
                icon.textContent = 'check';
                btn.classList.add('text-flag-green');
                setTimeout(function() {
                    icon.textContent = 'link';
                    btn.classList.remove('text-flag-green');
                }, 2000);
            }
        }).catch(function() {
            prompt('Copie o link:', url);
        });
    } else {
        prompt('Copie o link:', url);
    }
}

function toggleLike(postId) {
    var key = 'sr_liked_' + postId;
    var countEl = document.getElementById('like-count');
    var iconEl = document.getElementById('like-icon');
    var btn = document.getElementById('like-btn');
    if (!countEl) return;

    var cur = parseInt(countEl.textContent, 10) || 184;
    var isLiked = localStorage.getItem(key) === '1';

    if (isLiked) {
        localStorage.removeItem(key);
        countEl.textContent = cur - 1;
        if (iconEl) iconEl.textContent = 'favorite_border';
        if (btn) btn.classList.remove('border-racing-red');
    } else {
        localStorage.setItem(key, '1');
        countEl.textContent = cur + 1;
        if (iconEl) iconEl.textContent = 'favorite';
        if (btn) btn.classList.add('border-racing-red');
    }
}

function submitComment() {
    var input = document.getElementById('comment-input');
    var text = input ? input.value.trim() : '';
    if (!text) {
        alert('Por favor, escreva um comentário antes de publicar.');
        return;
    }
    alert('Comentário enviado com sucesso para a moderação da redação SpeedRacing!');
    if (input) input.value = '';
}

// Inicializar estado de curtida
(function() {
    var postId = <?= (int)$post['id'] ?>;
    var isLiked = localStorage.getItem('sr_liked_' + postId) === '1';
    var iconEl = document.getElementById('like-icon');
    var btn = document.getElementById('like-btn');
    if (isLiked) {
        if (iconEl) iconEl.textContent = 'favorite';
        if (btn) btn.classList.add('border-racing-red');
    }
})();
</script>

<!-- Disparo Assíncrono e Leve de Visualizações (Views) - Apenas em Leitura Pública Real -->
<?php if (empty($isPreview)): ?>
<script>
(function() {
    var postId = <?= (int)$post['id'] ?>;
    var endpoint = '/api/view/' + postId;
    try {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint);
        } else {
            fetch(endpoint, { method: 'POST', keepalive: true }).catch(function() {});
        }
    } catch (e) {}
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
