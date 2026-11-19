<?php
declare(strict_types=1);

use LightPress\Core\BlockCompiler;
use LightPress\Core\Security;
use LightPress\Core\ThemeManager;

$siteName = $config['app']['name'] ?? 'Empresa';
$whatsapp = BlockCompiler::sanitizeUrl((string)ThemeManager::option('contact_whatsapp', ''));
$email = (string)ThemeManager::option('contact_email', '');
$phone = (string)ThemeManager::option('contact_phone', '');
$copyright = (string)ThemeManager::option('footer_copyright_company', $siteName . ' Ltda.');
?>
    <footer class="corp-footer">
        <div class="corp-container">
            <div class="corp-footer-grid">
                <div>
                    <div class="corp-brand" style="margin-bottom: 0.75rem;">
                        <span class="corp-brand-dot"></span>
                        <span><?= Security::e($siteName) ?></span>
                    </div>
                    <p style="color: var(--corp-text-muted); font-size: 0.95rem; max-width: 420px; margin-bottom: 1.5rem;">
                        <?= Security::e(!empty($config['app']['description']) ? $config['app']['description'] : 'Soluções corporativas de alto impacto, consultoria especializada e tecnologia para acelerar o crescimento da sua empresa.') ?>
                    </p>
                    <div style="font-size: 0.9rem; color: var(--corp-text); display: flex; flex-direction: column; gap: 0.35rem;">
                        <?php if (!empty($phone)): ?>
                            <div>📞 Telefone: <strong><?= Security::e($phone) ?></strong></div>
                        <?php endif; ?>
                        <?php if (!empty($email)): ?>
                            <div>✉️ E-mail: <strong><?= Security::e($email) ?></strong></div>
                        <?php endif; ?>
                        <?php if (!empty($whatsapp)): ?>
                            <div>💬 WhatsApp: <a href="<?= Security::e($whatsapp) ?>" target="_blank" style="color: var(--corp-primary); font-weight: 700;">Atendimento Direto</a></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h4 style="font-size: 0.95rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; color: var(--corp-text);">
                        Navegação
                    </h4>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
                        <li><a href="/" class="corp-nav-link">Página Inicial</a></li>
                        <li><a href="/blog/" class="corp-nav-link">Blog & Artigos</a></li>
                        <li><a href="/contato/" class="corp-nav-link">Fale Conosco</a></li>
                    </ul>
                </div>

                <div>
                    <h4 style="font-size: 0.95rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; color: var(--corp-text);">
                        Institucional
                    </h4>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
                        <li><a href="/termos-de-uso/" class="corp-nav-link">Termos de Uso</a></li>
                        <li><a href="/politica-de-privacidade/" class="corp-nav-link">Política de Privacidade</a></li>
                        <li><a href="/sitemap.xml" class="corp-nav-link">Mapa do Site (XML)</a></li>
                    </ul>
                </div>
            </div>

            <div class="corp-footer-bottom">
                <div>
                    &copy; <?= date('Y') ?> <?= Security::e($copyright) ?> Todos os direitos reservados.
                </div>
                <div>
                    Plataforma corporativa ágil e de alta performance.
                </div>
            </div>
        </div>
    </footer>
    <?php \LightPress\Core\Hook::doAction('frontend_footer'); ?>
</body>
</html>
