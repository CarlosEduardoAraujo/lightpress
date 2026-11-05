<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<section>
    <div style="margin-bottom: 2rem;">
        <span class="badge" style="background: rgba(37, 99, 235, 0.1); color: var(--primary-accent);">Tópico / Tag</span>
        <h1 style="font-size: 2.2rem; font-weight: 900; letter-spacing: -0.025em; margin-top: 0.5rem; color: var(--primary);">
            #<?= Security::e($tagName ?? 'Tópico') ?>
        </h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.35rem;">
            <?= number_format($totalPosts ?? 0, 0, ',', '.') ?> <?= ($totalPosts ?? 0) === 1 ? 'matéria encontrada' : 'matérias encontradas' ?> com esta tag
        </p>
    </div>

    <?php if (empty($posts)): ?>
        <div style="padding: 4rem 1.5rem; text-align: center; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);">
            <p style="font-size: 1.1rem; color: var(--text-muted); margin-bottom: 1rem;">Nenhum artigo publicado com este tópico ainda.</p>
            <a href="/" style="display: inline-block; padding: 0.6rem 1.25rem; background: var(--primary-accent); color: white; border-radius: var(--radius); text-decoration: none; font-weight: 700;">&larr; Voltar para a Página Inicial</a>
        </div>
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
                        <span class="badge" style="align-self: flex-start; margin-bottom: 0.5rem;"><?= Security::e($item['category']) ?></span>
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
            <nav class="pagination" aria-label="Navegação de páginas do tópico">
                <?php if ($currentPage > 1): ?>
                    <a href="<?= ($currentPage === 2) ? '/tag/' . Security::e($tagRaw) . '/' : '/tag/' . Security::e($tagRaw) . '/page/' . ($currentPage - 1) . '/' ?>" class="page-btn">&larr; Anterior</a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p === 1): ?>
                        <a href="/tag/<?= Security::e($tagRaw) ?>/" class="page-btn <?= ($currentPage === 1) ? 'active' : '' ?>">1</a>
                    <?php else: ?>
                        <a href="/tag/<?= Security::e($tagRaw) ?>/page/<?= $p ?>/" class="page-btn <?= ($currentPage === $p) ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="/tag/<?= Security::e($tagRaw) ?>/page/<?= $currentPage + 1 ?>/" class="page-btn">Próxima &rarr;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
