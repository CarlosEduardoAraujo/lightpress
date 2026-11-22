<?php
declare(strict_types=1);

use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<main class="corp-section">
    <div class="corp-container">
        <div class="corp-section-header" style="text-align: left; margin-bottom: 2.5rem;">
            <h1 class="corp-section-title" style="font-size: 2.2rem;">
                Resultado da busca: "<?= Security::e($query ?? '') ?>"
            </h1>
            <p class="corp-section-sub">
                <?= (int)($totalPosts ?? 0) ?> resultado(s) encontrado(s).
            </p>
        </div>

        <?php if (empty($posts)): ?>
            <div style="text-align: center; padding: 4rem 1rem; color: var(--corp-text-muted);">
                <p style="font-size: 1.15rem; font-weight: 600;">Nenhum conteúdo localizado para esta pesquisa.</p>
                <a href="/" class="corp-btn corp-btn-primary" style="margin-top: 1rem;">Voltar para o Início</a>
            </div>
        <?php else: ?>
            <div class="corp-grid-3">
                <?php 
                ImageProcessor::preloadDescriptions(array_filter(array_column($posts, 'featured_image')));
                foreach ($posts as $idx => $p): 
                ?>
                    <a href="/<?= Security::e($p['slug']) ?>/" class="corp-blog-card">
                        <?php if (!empty($p['featured_image'])): 
                            $cardImg = ImageProcessor::getResponsiveCardData($p['featured_image'], $p['title'], !empty($p['featured_media_id']) ? (string)$p['featured_media_id'] : null);
                            $loadingAttr = ($idx === 0) ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"';
                        ?>
                            <img src="<?= Security::e($cardImg['src']) ?>"<?= !empty($cardImg['srcset']) ? ' srcset="' . Security::e($cardImg['srcset']) . '" sizes="' . Security::e($cardImg['sizes']) . '"' : '' ?> class="corp-blog-thumb" alt="<?= Security::e($cardImg['alt']) ?>" title="<?= Security::e($cardImg['title']) ?>" <?= $loadingAttr ?>>
                        <?php else: ?>
                            <div class="corp-blog-thumb" style="display: flex; align-items: center; justify-content: center; font-size: 2rem;">📄</div>
                        <?php endif; ?>

                        <div class="corp-blog-body">
                            <div class="corp-blog-tag"><?= Security::e($p['category']) ?></div>
                            <h2 class="corp-blog-title"><?= Security::e($p['title']) ?></h2>
                            <p class="corp-blog-excerpt"><?= Security::e(mb_strimwidth($p['summary'] ?? '', 0, 120, '...')) ?></p>
                            <div class="corp-blog-meta">
                                Por <?= Security::e($p['author_name'] ?? 'Equipe') ?> &bull; <?= date('d/m/Y', strtotime((string)($p['published_at'] ?? 'now')) ?: time()) ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($totalPages) && $totalPages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 3.5rem; align-items: center;">
                    <?php if ($currentPage > 1): ?>
                        <a href="/busca/?q=<?= urlencode((string)$query) ?>&page=<?= (int)($currentPage - 1) ?>" class="corp-btn corp-btn-secondary">
                            &larr; Anterior
                        </a>
                    <?php endif; ?>

                    <span style="font-size: 0.9rem; font-weight: 700; color: var(--corp-text-muted); padding: 0 1rem;">
                        Página <?= (int)$currentPage ?> de <?= (int)$totalPages ?>
                    </span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="/busca/?q=<?= urlencode((string)$query) ?>&page=<?= (int)($currentPage + 1) ?>" class="corp-btn corp-btn-secondary">
                            Próxima &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
