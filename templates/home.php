<?php
declare(strict_types=1);
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<?php if (!empty($featured)): ?>
    <section style="margin-bottom: 2.5rem;">
        <article class="card" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
            <?php if (!empty($featured['featured_image'])): ?>
                <img src="<?= Security::e($featured['featured_image']) ?>" alt="<?= Security::e($featured['title']) ?>" class="card-img" style="aspect-ratio: auto; height: 100%; min-height: 250px;">
            <?php else: ?>
                <div class="card-img" style="aspect-ratio: auto; min-height: 250px; display: flex; align-items: center; justify-content: center; color: #94a3b8;">Sem imagem</div>
            <?php endif; ?>
            <div class="card-body" style="padding: 2rem;">
                <div>
                    <span class="badge" style="background: #fef3c7; color: #b45309;">Destaque</span>
                    <span class="badge"><?= Security::e($featured['category']) ?></span>
                </div>
                <h1 style="font-size: 1.8rem; font-weight: 800; margin: 0.75rem 0;">
                    <a href="/noticia/<?= Security::e($featured['slug']) ?>/" style="color: var(--primary); text-decoration: none;">
                        <?= Security::e($featured['title']) ?>
                    </a>
                </h1>
                <p class="card-summary" style="font-size: 1rem;"><?= Security::e($featured['summary'] ?? '') ?></p>
                <div class="card-meta">
                    <span>Publicado em <?= date('d/m/Y H:i', strtotime($featured['published_at'])) ?></span>
                </div>
            </div>
        </article>
    </section>
<?php endif; ?>

<section>
    <h2 style="font-size: 1.4rem; font-weight: 700; border-left: 4px solid var(--primary-accent); padding-left: 0.75rem;">
        Últimas Publicações
    </h2>

    <?php if (empty($posts)): ?>
        <p style="margin-top: 1.5rem; color: var(--text-muted);">Nenhuma publicação encontrada no momento.</p>
    <?php else: ?>
        <div class="grid-posts">
            <?php foreach ($posts as $item): ?>
                <article class="card">
                    <?php if (!empty($item['featured_image'])): ?>
                        <img src="<?= Security::e($item['featured_image']) ?>" alt="<?= Security::e($item['title']) ?>" class="card-img" loading="lazy">
                    <?php else: ?>
                        <div class="card-img" style="display: flex; align-items: center; justify-content: center; color: #94a3b8;">Sem imagem</div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div>
                            <span class="badge"><?= Security::e($item['category']) ?></span>
                        </div>
                        <h3 class="card-title">
                            <a href="/noticia/<?= Security::e($item['slug']) ?>/">
                                <?= Security::e($item['title']) ?>
                            </a>
                        </h3>
                        <p class="card-summary"><?= Security::e($item['summary'] ?? '') ?></p>
                        <div class="card-meta">
                            <span><?= date('d/m/Y', strtotime($item['published_at'])) ?></span>
                            <a href="/noticia/<?= Security::e($item['slug']) ?>/" style="color: var(--primary-accent); text-decoration: none; font-weight: 600;">Ler artigo &rarr;</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/footer.php'; ?>
