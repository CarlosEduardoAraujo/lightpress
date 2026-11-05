<?php
declare(strict_types=1);
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;

require __DIR__ . '/header.php';

$postUrl = rtrim($config['app']['url'] ?? 'https://lightpress.suainternet.com', '/') . '/' . rawurlencode($post['slug']) . '/';
$postTitleEncoded = rawurlencode($post['title']);
$postUrlEncoded = rawurlencode($postUrl);
?>

<article style="max-width: 1750px; margin: 0 auto; background: var(--surface); padding: 3rem 3.5rem; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--card-shadow);">
    <header class="post-header" style="margin-bottom: 2.5rem; position: static;">
        <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1.25rem;">
            <a href="/categoria/<?= Security::e($post['category']) ?>/" class="badge" style="text-decoration: none;">
                <?= Security::e($post['category']) ?>
            </a>
            <?php if (!empty($readingTime)): ?>
                <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">
                    ⏱️ <?= (int)$readingTime ?> min de leitura
                </span>
            <?php endif; ?>
        </div>

        <h1 style="font-size: clamp(2rem, 3.8vw, 3rem); font-weight: 900; line-height: 1.2; letter-spacing: -0.03em; margin: 0.75rem 0 1.25rem; color: var(--primary);">
            <?= Security::e($post['title']) ?>
        </h1>

        <?php if (!empty($post['summary'])): ?>
            <p style="font-size: clamp(1.12rem, 1.6vw, 1.3rem); color: var(--text-muted); line-height: 1.6; margin-bottom: 2rem;">
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

        <!-- Botões Rápidos de Compartilhamento Social (100% Leves e Sem Rastreadores) -->
        <div class="share-bar">
            <span class="share-label">
                <svg viewBox="0 0 24 24" style="width: 16px; height: 16px; fill: currentColor;"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92c0-1.61-1.31-2.92-2.92-2.92z"/></svg>
                Compartilhar:
            </span>
            <a href="https://api.whatsapp.com/send?text=<?= $postTitleEncoded ?>%20-%20<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-wa" title="Compartilhar no WhatsApp">
                <svg viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                WhatsApp
            </a>
            <a href="https://twitter.com/intent/tweet?text=<?= $postTitleEncoded ?>&url=<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-x" title="Compartilhar no X (Twitter)">
                <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 24.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                X / Twitter
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-fb" title="Compartilhar no Facebook">
                <svg viewBox="0 0 24 24"><path d="M9 8H6v4h3v12h5V12h3.642L18 8h-4V6.333C14 5.374 14.356 5 15.567 5H18V0h-3.808C10.597 0 9 1.583 9 4.615V8z"/></svg>
                Facebook
            </a>
            <a href="https://t.me/share/url?url=<?= $postUrlEncoded ?>&text=<?= $postTitleEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-tg" title="Compartilhar no Telegram">
                <svg viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.14.18-.357.3-.616.3l.204-3.13 5.69-5.144c.248-.22-.054-.343-.385-.121l-7.032 4.428-3.036-.95c-.66-.206-.673-.66.138-.977l11.87-4.575c.55-.2 1.03.132.867.871z"/></svg>
                Telegram
            </a>
            <button type="button" class="share-btn share-copy" onclick="copyPostLink(this, '<?= Security::e($postUrl) ?>')" title="Copiar link da publicação">
                <svg viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
                <span>Copiar Link</span>
            </button>
        </div>
    </header>

    <?php if (!empty($post['featured_image'])): ?>
        <figure style="margin-bottom: 3rem; border-radius: var(--radius); overflow: hidden; border: 1px solid var(--border);">
            <img src="<?= Security::e(ImageProcessor::toAvifUrl($post['featured_image'])) ?>" alt="<?= Security::e($post['title']) ?>" style="width: 100%; aspect-ratio: 16/9; max-height: 760px; object-fit: cover; display: block;">
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

    <!-- Compartilhamento ao final do artigo -->
    <div class="share-bar" style="margin-top: 2rem; margin-bottom: 0;">
        <span class="share-label">
            <svg viewBox="0 0 24 24" style="width: 16px; height: 16px; fill: currentColor;"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92c0-1.61-1.31-2.92-2.92-2.92z"/></svg>
            Gostou? Compartilhe:
        </span>
        <a href="https://api.whatsapp.com/send?text=<?= $postTitleEncoded ?>%20-%20<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-wa" title="Compartilhar no WhatsApp">
            <svg viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
            WhatsApp
        </a>
        <a href="https://twitter.com/intent/tweet?text=<?= $postTitleEncoded ?>&url=<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-x" title="Compartilhar no X (Twitter)">
            <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 24.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
            X / Twitter
        </a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $postUrlEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-fb" title="Compartilhar no Facebook">
            <svg viewBox="0 0 24 24"><path d="M9 8H6v4h3v12h5V12h3.642L18 8h-4V6.333C14 5.374 14.356 5 15.567 5H18V0h-3.808C10.597 0 9 1.583 9 4.615V8z"/></svg>
            Facebook
        </a>
        <a href="https://t.me/share/url?url=<?= $postUrlEncoded ?>&text=<?= $postTitleEncoded ?>" target="_blank" rel="noopener noreferrer" class="share-btn share-tg" title="Compartilhar no Telegram">
            <svg viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.14.18-.357.3-.616.3l.204-3.13 5.69-5.144c.248-.22-.054-.343-.385-.121l-7.032 4.428-3.036-.95c-.66-.206-.673-.66.138-.977l11.87-4.575c.55-.2 1.03.132.867.871z"/></svg>
            Telegram
        </a>
        <button type="button" class="share-btn share-copy" onclick="copyPostLink(this, '<?= Security::e($postUrl) ?>')" title="Copiar link da publicação">
            <svg viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
            <span>Copiar Link</span>
        </button>
    </div>
</article>

<!-- Bloco de Notícias Relacionadas (Mesma Categoria) -->
<?php if (!empty($relatedPosts)): ?>
    <section style="max-width: 1750px; margin: 4rem auto 0;">
        <h3 style="font-size: 1.5rem; font-weight: 900; letter-spacing: -0.02em; border-left: 4px solid var(--primary-accent); padding-left: 0.85rem; color: var(--primary); margin-bottom: 1.75rem;">
            Notícias Relacionadas
        </h3>
        <div class="grid-related">
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

<script>
function copyPostLink(btn, url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            var orig = btn.innerHTML;
            btn.innerHTML = '<span>✓ Link Copiado!</span>';
            btn.style.borderColor = '#16a34a';
            btn.style.color = '#16a34a';
            setTimeout(function() {
                btn.innerHTML = orig;
                btn.style.borderColor = '';
                btn.style.color = '';
            }, 2500);
        }).catch(function() {
            prompt('Copie o link:', url);
        });
    } else {
        prompt('Copie o link:', url);
    }
}
</script>

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
