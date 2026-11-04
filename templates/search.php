<?php
declare(strict_types=1);
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<section>
    <div style="margin-bottom: 2rem;">
        <span class="badge">Busca</span>
        <h1 style="font-size: 2rem; font-weight: 800; margin-top: 0.5rem; color: var(--primary);">
            Resultados para: &ldquo;<?= Security::e($query) ?>&rdquo;
        </h1>
        <p style="color: var(--text-muted); font-size: 0.95rem;">
            <?= count($posts) ?> <?= count($posts) === 1 ? 'publicação encontrada' : 'publicações encontradas' ?>
        </p>
    </div>

    <?php if (empty($posts)): ?>
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 3rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 1rem;">Nenhum artigo encontrado com os termos pesquisados.</p>
            <p style="font-size: 0.9rem; color: var(--text-muted);">Tente palavras-chave mais genéricas ou navegue pelas categorias principais.</p>
        </div>
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
