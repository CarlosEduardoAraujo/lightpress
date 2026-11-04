<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<section>
    <div style="margin-bottom: 2rem;">
        <span class="badge">Busca</span>
        <h1 style="font-size: 2.2rem; font-weight: 900; letter-spacing: -0.025em; margin-top: 0.5rem; color: var(--primary);">
            Resultados para: &ldquo;<?= Security::e($query) ?>&rdquo;
        </h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.25rem;">
            <?= count($posts) ?> <?= count($posts) === 1 ? 'publicação encontrada' : 'publicações encontradas' ?>
        </p>
    </div>

    <?php if (empty($posts)): ?>
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 3.5rem; text-align: center; box-shadow: var(--card-shadow);">
            <p style="color: var(--text); font-size: 1.15rem; font-weight: 700; margin-bottom: 0.5rem;">Nenhum artigo encontrado com os termos pesquisados.</p>
            <p style="font-size: 0.92rem; color: var(--text-muted);">Tente palavras-chave mais genéricas ou confira as categorias no menu superior.</p>
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
    <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
