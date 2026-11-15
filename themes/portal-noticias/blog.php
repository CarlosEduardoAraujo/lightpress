<?php
declare(strict_types=1);

use LightPress\Core\Icon;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\ThemeManager;

require __DIR__ . '/header.php';

// Formatador de data em português
$ptMonths = [
    1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
    5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto',
    9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'
];
$formatPtDate = function(?string $dateStr) use ($ptMonths, $config): string {
    if (empty($dateStr)) return '';
    try {
        $tz = new DateTimeZone($config['app']['display_timezone'] ?? 'America/Sao_Paulo');
        $dt = new DateTimeImmutable($dateStr, new DateTimeZone('UTC'));
        $dt = $dt->setTimezone($tz);
        $d = $dt->format('j');
        $m = (int)$dt->format('n');
        $y = $dt->format('Y');
        $h = $dt->format('H:i');
        $monthName = $ptMonths[$m] ?? '';
        return "{$d} de {$monthName} de {$y} às {$h}";
    } catch (\Throwable) {
        return '';
    }
};
?>

<div class="w-full">
    <div class="border-b border-subtle-border dark:border-[#2a303c] pb-space-sm mb-space-lg flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-racing-red inline-block"></span>
                <h1 class="font-headline-md text-headline-md font-bold text-carbon-black dark:text-pure-white">Todas as Matérias &bull; Blog</h1>
            </div>
            <p class="font-body-sm text-body-sm text-secondary dark:text-secondary-fixed-dim mt-1">
                Arquivo cronológico completo de publicações, análises técnicas e bastidores.
            </p>
        </div>
    </div>

    <?php if (empty($posts)): ?>
        <div class="py-16 text-center text-secondary dark:text-secondary-fixed-dim">
            <p class="font-headline-sm text-headline-sm font-semibold">Nenhuma publicação encontrada.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-space-lg">
            <?php foreach ($posts as $p): ?>
                <article class="flex flex-col bg-pure-white dark:bg-carbon-surface rounded-xl overflow-hidden shadow-xs border border-subtle-border dark:border-[#2a303c] hover:shadow-md transition-shadow group">
                    <a href="/<?= Security::e($p['slug']) ?>/" class="block overflow-hidden relative aspect-video bg-surface-container-high dark:bg-[#1a202c]">
                        <?php if (!empty($p['featured_image'])): ?>
                            <img src="<?= Security::e(ImageProcessor::toAvifUrl($p['featured_image'])) ?>" alt="<?= Security::e($p['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-secondary dark:text-secondary-fixed-dim">
                                <?= Icon::svg('article', 'w-10 h-10', 40) ?>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div class="p-space-md flex flex-col flex-grow">
                        <span class="font-label-category text-label-category uppercase tracking-wider text-racing-red-text dark:text-racing-red-text-dark font-bold mb-1">
                            <?= Security::e($p['category']) ?>
                        </span>
                        <h2 class="font-headline-sm text-base md:text-lg font-bold text-carbon-black dark:text-pure-white line-clamp-2 group-hover:text-racing-red transition-colors mb-2">
                            <a href="/<?= Security::e($p['slug']) ?>/">
                                <?= Security::e($p['title']) ?>
                            </a>
                        </h2>
                        <?php if (!empty($p['summary'])): ?>
                            <p class="font-body-sm text-body-sm text-secondary dark:text-[#a0a5b1] line-clamp-2 mb-4 flex-grow">
                                <?= Security::e($p['summary']) ?>
                            </p>
                        <?php endif; ?>
                        <div class="pt-3 border-t border-subtle-border/60 dark:border-[#2a303c] text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim flex items-center justify-between">
                            <span><?= Security::e($p['author_name'] ?? 'Redação') ?></span>
                            <span><?= Security::e($formatPtDate($p['published_at'])) ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Paginação -->
        <?php if (!empty($totalPages) && $totalPages > 1): ?>
            <div class="flex items-center justify-center gap-2 mt-space-xl pt-space-lg">
                <?php if ($currentPage > 1): ?>
                    <a href="<?= ($currentPage === 2) ? '/blog/' : '/blog/page/' . ($currentPage - 1) . '/' ?>" class="px-4 py-2 rounded-lg bg-pure-white dark:bg-carbon-surface text-carbon-black dark:text-pure-white border border-subtle-border dark:border-[#2a303c] font-label-meta hover:bg-surface-container dark:hover:bg-[#252b36] transition-colors">
                        &larr; Anterior
                    </a>
                <?php endif; ?>

                <span class="px-4 py-2 text-label-meta font-label-meta text-secondary dark:text-secondary-fixed-dim">
                    Página <?= (int)$currentPage ?> de <?= (int)$totalPages ?>
                </span>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="/blog/page/<?= (int)($currentPage + 1) ?>/" class="px-4 py-2 rounded-lg bg-pure-white dark:bg-carbon-surface text-carbon-black dark:text-pure-white border border-subtle-border dark:border-[#2a303c] font-label-meta hover:bg-surface-container dark:hover:bg-[#252b36] transition-colors">
                        Próxima &rarr;
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
