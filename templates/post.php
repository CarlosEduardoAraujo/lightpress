<?php
declare(strict_types=1);
use LightPress\Core\Security;

require __DIR__ . '/header.php';
?>

<article style="max-width: 820px; margin: 0 auto; background: var(--surface); padding: 2.5rem; border-radius: var(--radius); border: 1px solid var(--border);">
    <header style="margin-bottom: 2rem;">
        <div>
            <a href="/categoria/<?= Security::e($post['category']) ?>/" class="badge" style="text-decoration: none;">
                <?= Security::e($post['category']) ?>
            </a>
        </div>
        <h1 style="font-size: 2.2rem; font-weight: 800; line-height: 1.25; margin: 1rem 0;">
            <?= Security::e($post['title']) ?>
        </h1>
        <?php if (!empty($post['summary'])): ?>
            <p style="font-size: 1.15rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 1.5rem;">
                <?= Security::e($post['summary']) ?>
            </p>
        <?php endif; ?>
        <div style="display: flex; align-items: center; gap: 0.75rem; color: var(--text-muted); font-size: 0.9rem; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 0.75rem 0;">
            <span>Por <strong><?= Security::e($post['author_name'] ?? 'Redação') ?></strong></span>
            <span>&bull;</span>
            <span>Publicado em <?= date('d/m/Y \à\s H:i', strtotime($post['published_at'])) ?></span>
        </div>
    </header>

    <?php if (!empty($post['featured_image'])): ?>
        <figure style="margin-bottom: 2rem;">
            <img src="<?= Security::e($post['featured_image']) ?>" alt="<?= Security::e($post['title']) ?>" style="width: 100%; border-radius: var(--radius); height: auto;">
        </figure>
    <?php endif; ?>

    <!-- Conteúdo HTML Compilado e Sanitizado pelo BlockCompiler -->
    <div class="content-body" style="font-size: 1.1rem; line-height: 1.8; color: #334155;">
        <?= $post['content_html'] ?>
    </div>
</article>

<!-- Disparo Assíncrono e Leve de Visualizações (Views) -->
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

<?php require __DIR__ . '/footer.php'; ?>
