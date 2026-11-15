<?php
declare(strict_types=1);

use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<main class="corp-section">
    <div class="corp-container">
        <div class="corp-section-header" style="text-align: left; margin-bottom: 2.5rem;">
            <h1 class="corp-section-title" style="font-size: 2.4rem;">Blog & Artigos Corporativos</h1>
            <p class="corp-section-sub">Acompanhe nossas análises técnicas, atualizações de produtos e tendências de mercado.</p>
        </div>

        <?php if (empty($posts)): ?>
            <div style="text-align: center; padding: 4rem 1rem; color: var(--corp-text-muted);">
                <p style="font-size: 1.15rem; font-weight: 600;">Nenhum artigo publicado no momento.</p>
            </div>
        <?php else: ?>
            <div class="corp-grid-3">
                <?php foreach ($posts as $p): ?>
                    <a href="/<?= Security::e($p['slug']) ?>/" class="corp-blog-card">
                        <?php if (!empty($p['featured_image'])): ?>
                            <img src="<?= Security::e(ImageProcessor::toAvifUrl($p['featured_image'])) ?>" class="corp-blog-thumb" alt="<?= Security::e($p['title']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="corp-blog-thumb" style="display: flex; align-items: center; justify-content: center; font-size: 2rem;">📄</div>
                        <?php endif; ?>

                        <div class="corp-blog-body">
                            <div class="corp-blog-tag"><?= Security::e($p['category']) ?></div>
                            <h2 class="corp-blog-title"><?= Security::e($p['title']) ?></h2>
                            <p class="corp-blog-excerpt"><?= Security::e(mb_strimwidth($p['summary'] ?? '', 0, 120, '...')) ?></p>
                            <div class="corp-blog-meta">
                                Por <?= Security::e($p['author_name'] ?? 'Equipe') ?> &bull; <?= date('d/m/Y', strtotime($p['published_at'])) ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Paginação -->
            <?php if (!empty($totalPages) && $totalPages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 0.5rem; margin-top: 3.5rem; align-items: center;">
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= ($currentPage === 2) ? '/blog/' : '/blog/page/' . ($currentPage - 1) . '/' ?>" class="corp-btn corp-btn-secondary">
                            &larr; Anterior
                        </a>
                    <?php endif; ?>

                    <span style="font-size: 0.9rem; font-weight: 700; color: var(--corp-text-muted); padding: 0 1rem;">
                        Página <?= (int)$currentPage ?> de <?= (int)$totalPages ?>
                    </span>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="/blog/page/<?= (int)($currentPage + 1) ?>/" class="corp-btn corp-btn-secondary">
                            Próxima &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
