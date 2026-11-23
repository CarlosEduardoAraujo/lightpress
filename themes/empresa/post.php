<?php
declare(strict_types=1);

use LightPress\Core\BlockCompiler;
use LightPress\Core\Icon;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\ThemeManager;

require __DIR__ . '/header.php';

$whatsapp = BlockCompiler::sanitizeUrl((string)ThemeManager::option('contact_whatsapp', ''));
$email = (string)ThemeManager::option('contact_email', '');
$phone = (string)ThemeManager::option('contact_phone', '');
?>

<main class="corp-section">
    <div class="corp-container">
        <div class="corp-layout-sidebar">
            <!-- Artigo Principal -->
            <article <?= empty($isPreview) ? 'data-view-endpoint="/api/view/' . (int)$post['id'] . '"' : '' ?>>
                <div style="margin-bottom: 2rem;">
                    <a href="/blog/" style="color: var(--corp-primary); text-decoration: none; font-weight: 700; font-size: 0.88rem; display: inline-flex; align-items: center; gap: 0.3rem; margin-bottom: 0.75rem;">
                        &larr; Voltar para o Blog
                    </a>
                    <h1 style="font-size: 2.5rem; font-weight: 900; line-height: 1.2; letter-spacing: -0.02em; color: var(--corp-text); margin: 0 0 1rem;">
                        <?= Security::e($post['title']) ?>
                    </h1>

                    <?php if (!empty($post['summary'])): ?>
                        <p style="font-size: 1.2rem; color: var(--corp-text-muted); line-height: 1.5; margin-bottom: 1.25rem;">
                            <?= Security::e($post['summary']) ?>
                        </p>
                    <?php endif; ?>

                    <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.88rem; color: var(--corp-text-muted); border-top: 1px solid var(--corp-border); border-bottom: 1px solid var(--corp-border); padding: 0.75rem 0;">
                        <span>Por <strong><?= Security::e($post['author_name'] ?? 'Equipe') ?></strong></span>
                        <span>&bull;</span>
                        <span>Publicado em <?= date('d/m/Y', strtotime((string)($post['published_at'] ?? 'now')) ?: time()) ?></span>
                        <span>&bull;</span>
                        <span><?= (int)($readingTime ?? 3) ?> min de leitura</span>
                    </div>
                </div>

                <?php if (!empty($post['featured_image'])): ?>
                    <div style="margin-bottom: 2.5rem; border-radius: var(--corp-radius); overflow: hidden; box-shadow: var(--corp-shadow);">
                        <?= ImageProcessor::renderPicture(
                            $post['featured_image'],
                            $post['title'],
                            [
                                'style' => 'width: 100%; height: auto; display: block;',
                                'loading' => 'eager',
                                'picture_class' => 'block w-full',
                            ],
                            $post['featured_media_id'] ?? null
                        ) ?>
                    </div>
                <?php endif; ?>

                <div class="corp-content" style="font-size: 1.08rem; line-height: 1.75; color: var(--corp-text);">
                    <?= $post['content_html'] ?>
                </div>
            </article>

            <!-- Barra Lateral com Cartão de Contato -->
            <aside style="display: flex; flex-direction: column; gap: 2rem;">
                <div class="corp-card" style="background: linear-gradient(135deg, var(--corp-surface), var(--corp-bg));">
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--corp-text);">
                        Precisa de apoio técnico?
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--corp-text-muted); margin-bottom: 1.25rem;">
                        Nossos consultores estão disponíveis para avaliar sua operação e apresentar uma proposta sob medida.
                    </p>

                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php if (!empty($whatsapp)): ?>
                            <a href="<?= Security::e($whatsapp) ?>" target="_blank" rel="noopener noreferrer" class="corp-btn corp-btn-primary" style="width: 100%; box-sizing: border-box;">
                                💬 Conversar no WhatsApp
                            </a>
                        <?php endif; ?>
                        <a href="/contato/" class="corp-btn corp-btn-secondary" style="width: 100%; box-sizing: border-box;">
                            ✉️ Solicitar Orçamento
                        </a>
                    </div>
                </div>

                <?php if (!empty($relatedPosts)): ?>
                    <div class="corp-card">
                        <h4 style="font-size: 0.95rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; color: var(--corp-text);">
                            Artigos Relacionados
                        </h4>
                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <?php foreach ($relatedPosts as $rel): ?>
                                <a href="/<?= Security::e($rel['slug']) ?>/" style="text-decoration: none; color: inherit; display: block;">
                                    <h5 style="font-size: 0.95rem; font-weight: 700; margin: 0 0 0.25rem; color: var(--corp-text); line-height: 1.35;">
                                        <?= Security::e($rel['title']) ?>
                                    </h5>
                                    <span style="font-size: 0.78rem; color: var(--corp-text-muted);">
                                        <?= date('d/m/Y', strtotime((string)($rel['published_at'] ?? 'now')) ?: time()) ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
