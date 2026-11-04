<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<article style="max-width: 860px; margin: 0 auto; background: var(--surface); padding: 2.75rem; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--card-shadow);">
    <header style="margin-bottom: 2rem;">
        <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1rem;">
            <a href="/categoria/<?= Security::e($post['category']) ?>/" class="badge" style="text-decoration: none;">
                <?= Security::e($post['category']) ?>
            </a>
            <?php if (!empty($readingTime)): ?>
                <span style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600;">
                    ⏱️ <?= (int)$readingTime ?> min de leitura
                </span>
            <?php endif; ?>
        </div>

        <h1 style="font-size: 2.35rem; font-weight: 900; line-height: 1.25; letter-spacing: -0.025em; margin: 0.75rem 0 1rem; color: var(--primary);">
            <?= Security::e($post['title']) ?>
        </h1>

        <?php if (!empty($post['summary'])): ?>
            <p style="font-size: 1.18rem; color: var(--text-muted); line-height: 1.6; margin-bottom: 1.75rem;">
                <?= Security::e($post['summary']) ?>
            </p>
        <?php endif; ?>

        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; color: var(--text-muted); font-size: 0.88rem; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 0.85rem 0;">
            <div>
                Por <strong><?= Security::e($post['author_name'] ?? 'Redação') ?></strong>
                <span style="margin: 0 0.4rem;">&bull;</span>
                <span>Publicado em <?= date('d/m/Y \à\s H:i', strtotime($post['published_at'])) ?></span>
            </div>
            <?php if (!empty($post['updated_at']) && $post['updated_at'] !== $post['published_at']): ?>
                <span style="font-size: 0.8rem;">Atualizado em <?= date('d/m/Y H:i', strtotime($post['updated_at'])) ?></span>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($post['featured_image'])): ?>
        <figure style="margin-bottom: 2.5rem; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border);">
            <img src="<?= Security::e(ImageProcessor::toAvifUrl($post['featured_image'])) ?>" alt="<?= Security::e($post['title']) ?>" style="width: 100%; aspect-ratio: 16/9; object-fit: cover; display: block;">
        </figure>
    <?php endif; ?>

    <!-- Conteúdo HTML Compilado e Sanitizado pelo BlockCompiler -->
    <div class="content-body">
        <?= $post['content_html'] ?>
    </div>

    <!-- Caixa de Autor -->
    <div class="author-box">
        <?php if (!empty($post['author_avatar'])): ?>
            <img src="<?= Security::e(ImageProcessor::toAvifUrl($post['author_avatar'])) ?>" alt="<?= Security::e($post['author_name']) ?>" class="author-avatar">
        <?php else: ?>
            <div class="author-avatar" style="display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.4rem; color: var(--primary-accent); background: var(--badge-bg);">
                <?= mb_strtoupper(mb_substr($post['author_name'] ?? 'R', 0, 1)) ?>
            </div>
        <?php endif; ?>
        <div class="author-info">
            <h4><?= Security::e($post['author_name'] ?? 'Redação SpeedPress') ?></h4>
            <p><?= Security::e($post['author_bio'] ?? 'Jornalista e redator focado em coberturas aprofundadas e notícias de alta velocidade.') ?></p>
        </div>
    </div>
</article>

<!-- Bloco de Notícias Relacionadas (Mesma Categoria) -->
<?php if (!empty($relatedPosts)): ?>
    <section style="max-width: 860px; margin: 3.5rem auto 0;">
        <h3 style="font-size: 1.4rem; font-weight: 900; letter-spacing: -0.02em; border-left: 4px solid var(--primary-accent); padding-left: 0.75rem; color: var(--primary); margin-bottom: 1.5rem;">
            Notícias Relacionadas
        </h3>
        <div class="grid-posts" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-top: 0;">
            <?php foreach ($relatedPosts as $rel): ?>
                <article class="card">
                    <?php if (!empty($rel['featured_image'])): ?>
                        <a href="/<?= Security::e($rel['slug']) ?>/">
                            <img src="<?= Security::e(ImageProcessor::toAvifUrl($rel['featured_image'])) ?>" alt="<?= Security::e($rel['title']) ?>" class="card-img" loading="lazy">
                        </a>
                    <?php else: ?>
                        <div class="card-img" style="display: flex; align-items: center; justify-content: center; color: var(--text-muted);">Sem imagem</div>
                    <?php endif; ?>
                    <div class="card-body" style="padding: 1.25rem;">
                        <div>
                            <span class="badge" style="font-size: 0.7rem; padding: 0.2rem 0.5rem;"><?= Security::e($rel['category']) ?></span>
                        </div>
                        <h4 class="card-title" style="font-size: 1.05rem; margin: 0.5rem 0;">
                            <a href="/<?= Security::e($rel['slug']) ?>/">
                                <?= Security::e($rel['title']) ?>
                            </a>
                        </h4>
                        <div class="card-meta" style="padding-top: 0.65rem;">
                            <span><?= date('d/m/Y', strtotime($rel['published_at'])) ?></span>
                            <a href="/<?= Security::e($rel['slug']) ?>/" style="color: var(--primary-accent); text-decoration: none; font-weight: 700; font-size: 0.8rem;">Ler &rarr;</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- Disparo Assíncrono e Leve de Visualizações (Views) - Apenas em Leitura Pública Real -->
<?php if (empty($isPreview)): ?>
<script>
(function() {
    var postId = <?= (int)$post['id'] ?>;
    var endpoint = '/api/view/' + postId;
    try {
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint);
        } else {
            fetch(endpoint, { method: 'POST', keepalive: true }).catch(function() {});
        }
    } catch (e) {}
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
