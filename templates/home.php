<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<?php if (!empty($featured)): ?>
    <section>
        <article class="hero-card">
            <?php if (!empty($featured['featured_image'])): ?>
                <a href="/<?= Security::e($featured['slug']) ?>/" style="display: block; overflow: hidden;">
                    <img src="<?= Security::e(ImageProcessor::toAvifUrl($featured['featured_image'])) ?>" alt="<?= Security::e($featured['title']) ?>" class="hero-img">
                </a>
            <?php else: ?>
                <div class="hero-img" style="display: flex; align-items: center; justify-content: center; color: var(--text-muted); background: var(--border);">Sem imagem</div>
            <?php endif; ?>
            <div class="hero-body">
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span class="badge" style="background: #fef3c7; color: #b45309;">Destaque</span>
                    <a href="/categoria/<?= Security::e($featured['category']) ?>/" class="badge" style="text-decoration: none;">
                        <?= Security::e($featured['category']) ?>
                    </a>
                </div>
                <h1 class="hero-title">
                    <a href="/<?= Security::e($featured['slug']) ?>/">
                        <?= Security::e($featured['title']) ?>
                    </a>
                </h1>
                <p class="card-summary" style="font-size: 1.05rem;"><?= Security::e($featured['summary'] ?? '') ?></p>
                <div class="card-meta">
                    <span>Publicado em <?= date('d/m/Y \à\s H:i', strtotime($featured['published_at'])) ?></span>
                    <a href="/<?= Security::e($featured['slug']) ?>/" style="color: var(--primary-accent); text-decoration: none; font-weight: 700;">Ler matéria &rarr;</a>
                </div>
            </div>
        </article>
    </section>
<?php endif; ?>

<section>
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem;">
        <h2 style="font-size: 1.5rem; font-weight: 900; letter-spacing: -0.02em; border-left: 4px solid var(--primary-accent); padding-left: 0.85rem; color: var(--primary);">
            <?= (!empty($featured)) ? 'Mais Notícias' : 'Últimas Publicações' ?>
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
