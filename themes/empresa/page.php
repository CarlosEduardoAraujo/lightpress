<?php
declare(strict_types=1);

use LightPress\Core\BlockCompiler;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\ThemeManager;

require __DIR__ . '/header.php';

$whatsapp = BlockCompiler::sanitizeUrl((string)ThemeManager::option('contact_whatsapp', ''));
?>

<main class="corp-section">
    <div class="corp-container">
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 3.5rem; align-items: start;">
            <article>
                <h1 style="font-size: 2.6rem; font-weight: 900; line-height: 1.2; letter-spacing: -0.02em; color: var(--corp-text); margin: 0 0 1.5rem;">
                    <?= Security::e($page['title']) ?>
                </h1>

                <?php if (!empty($page['featured_image'])): ?>
                    <div style="margin-bottom: 2.5rem; border-radius: var(--corp-radius); overflow: hidden; box-shadow: var(--corp-shadow);">
                        <img src="<?= Security::e(ImageProcessor::toAvifUrl($page['featured_image'])) ?>" alt="<?= Security::e($page['title']) ?>" style="width: 100%; height: auto; display: block;" loading="eager">
                    </div>
                <?php endif; ?>

                <div class="corp-content" style="font-size: 1.08rem; line-height: 1.75; color: var(--corp-text);">
                    <?= $page['content_html'] ?>
                </div>
            </article>

            <aside style="display: flex; flex-direction: column; gap: 2rem;">
                <div class="corp-card" style="background: linear-gradient(135deg, var(--corp-surface), var(--corp-bg));">
                    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--corp-text);">
                        Atendimento Comercial
                    </h3>
                    <p style="font-size: 0.9rem; color: var(--corp-text-muted); margin-bottom: 1.25rem;">
                        Entre em contato direto com nossos especialistas para esclarecer dúvidas ou solicitar atendimento.
                    </p>

                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php if (!empty($whatsapp)): ?>
                            <a href="<?= Security::e($whatsapp) ?>" target="_blank" class="corp-btn corp-btn-primary" style="width: 100%; box-sizing: border-box;">
                                💬 WhatsApp Direto
                            </a>
                        <?php endif; ?>
                        <a href="/contato/" class="corp-btn corp-btn-secondary" style="width: 100%; box-sizing: border-box;">
                            ✉️ Formulário de Contato
                        </a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
