<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<?php if (!empty($featuredPosts)): ?>
    <section>
        <div class="hero-quad-grid">
            <?php foreach ($featuredPosts as $feat): ?>
                <a href="/<?= Security::e($feat['slug']) ?>/" class="hero-quad-card" title="<?= Security::e($feat['title']) ?>">
                    <?php if (!empty($feat['featured_image'])): ?>
                        <img src="<?= Security::e(ImageProcessor::toAvifUrl($feat['featured_image'])) ?>" alt="<?= Security::e($feat['title']) ?>" class="hero-quad-img">
                    <?php else: ?>
                        <div class="hero-quad-img" style="display: flex; align-items: center; justify-content: center; background: #1e293b; color: #94a3b8; font-size: 0.85rem;">Sem imagem</div>
                    <?php endif; ?>
                    <div class="hero-quad-overlay">
                        <span class="hero-quad-badge"><?= Security::e($feat['category']) ?></span>
                        <h2 class="hero-quad-title"><?= Security::e($feat['title']) ?></h2>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section>
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem;">
        <h2 style="font-size: 1.5rem; font-weight: 900; letter-spacing: -0.02em; border-left: 4px solid var(--primary-accent); padding-left: 0.85rem; color: var(--primary);">
            <?= (!empty($featuredPosts)) ? 'Mais Notícias' : 'Últimas Publicações' ?>
        </h2>
    </div>

    <?php if (empty($posts)): ?>
        <p style="padding: 3rem; text-align: center; color: var(--text-muted);">Nenhuma publicação encontrada no momento.</p>
    <?php else: ?>
        <div class="grid-posts">
            <?php foreach ($posts as $item): ?>
                <article class="card">
                    <?php if (!empty($item['featured_image'])): ?>
                        <a href="/<?= Security::e($item['slug']) ?>/">
                            <img src="<?= Security::e(ImageProcessor::toAvifUrl($item['featured_image'])) ?>" alt="<?= Security::e($item['title']) ?>" class="card-img" loading="lazy">
                        </a>
                    <?php else: ?>
                        <div class="card-img" style="display: flex; align-items: center; justify-content: center; color: var(--text-muted);">Sem imagem</div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div>
                            <a href="/categoria/<?= Security::e($item['category']) ?>/" class="badge" style="text-decoration: none;">
                                <?= Security::e($item['category']) ?>
                            </a>
                        </div>
                        <h3 class="card-title">
                            <a href="/<?= Security::e($item['slug']) ?>/">
                                <?= Security::e($item['title']) ?>
                            </a>
                        </h3>
                        <p class="card-summary"><?= Security::e($item['summary'] ?? '') ?></p>
                        <div class="card-meta">
                            <span><?= date('d/m/Y', strtotime($item['published_at'])) ?></span>
                            <a href="/<?= Security::e($item['slug']) ?>/" style="color: var(--primary-accent); text-decoration: none; font-weight: 700;">Ler matéria &rarr;</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($totalPages) && $totalPages > 1): ?>
            <nav class="pagination" aria-label="Navegação de páginas">
                <?php if ($currentPage > 1): ?>
                    <a href="<?= ($currentPage === 2) ? '/' : '/page/' . ($currentPage - 1) . '/' ?>" class="page-btn">&larr; Anterior</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === 1): ?>
                        <a href="/" class="page-btn <?= ($currentPage === 1) ? 'active' : '' ?>">1</a>
                    <?php else: ?>
                        <a href="/page/<?= $p ?>/" class="page-btn <?= ($currentPage === $p) ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="/page/<?= $currentPage + 1 ?>/" class="page-btn">Próxima &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
