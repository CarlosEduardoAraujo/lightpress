<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<section>
    <div style="margin-bottom: 2rem;">
        <span class="badge">Categoria</span>
        <h1 style="font-size: 2.2rem; font-weight: 900; letter-spacing: -0.025em; margin-top: 0.5rem; color: var(--primary);">
            <?= Security::e($category['name']) ?>
        </h1>
    </div>

    <?php if (empty($posts)): ?>
        <p style="padding: 3rem; text-align: center; color: var(--text-muted);">Nenhum artigo encontrado nesta categoria no momento.</p>
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
            <nav class="pagination" aria-label="Navegação de páginas da categoria">
                <?php if ($currentPage > 1): ?>
                    <a href="<?= ($currentPage === 2) ? '/categoria/' . Security::e($category['slug']) . '/' : '/categoria/' . Security::e($category['slug']) . '/page/' . ($currentPage - 1) . '/' ?>" class="page-btn">&larr; Anterior</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === 1): ?>
                        <a href="/categoria/<?= Security::e($category['slug']) ?>/" class="page-btn <?= ($currentPage === 1) ? 'active' : '' ?>">1</a>
                    <?php else: ?>
                        <a href="/categoria/<?= Security::e($category['slug']) ?>/page/<?= $p ?>/" class="page-btn <?= ($currentPage === $p) ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="/categoria/<?= Security::e($category['slug']) ?>/page/<?= $currentPage + 1 ?>/" class="page-btn">Próxima &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
