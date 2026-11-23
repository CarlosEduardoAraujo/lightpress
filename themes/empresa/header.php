<?php
declare(strict_types=1);

use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\Icon;
use LightPress\Core\ObjectCache;
use LightPress\Core\Security;
use LightPress\Core\Seo;
use LightPress\Core\ThemeManager;

// Páginas institucionais ativas para a navegação corporativa
$navPages = ObjectCache::remember('corporate_nav_pages', 86400, function (): array {
    try {
        return Database::fetchAll("SELECT slug, title FROM pages WHERE status = 'published' ORDER BY id ASC LIMIT 6");
    } catch (\Throwable) {
        return [];
    }
}, 'pages');

$siteName = $config['app']['name'] ?? 'Empresa';
$headerCtaText = (string)ThemeManager::option('header_cta_text', 'Fale Conosco');
$headerCtaLink = BlockCompiler::sanitizeUrl((string)ThemeManager::option('header_cta_link', '/contato/'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= Security::e($pageTitle ?? $siteName) ?></title>
    <?php if (empty($post) && !empty($config['app']['description'])): ?>
        <meta name="description" content="<?= Security::e($config['app']['description']) ?>">
    <?php endif; ?>

    <link rel="alternate" type="application/rss+xml" title="Feed RSS - <?= Security::e($siteName) ?>" href="/feed.xml">

    <!-- Favicon -->
    <?php $siteFavicon = $config['app']['favicon'] ?? ''; if (!empty($siteFavicon)): ?>
        <link rel="icon" href="<?= Security::e($siteFavicon) ?>">
    <?php else: ?>
        <link rel="icon" href="/favicon.ico">
    <?php endif; ?>

    <!-- Open Graph & Schema -->
    <?= Seo::renderMetaTags($post ?? null, $config) ?>
    <?= Seo::renderJsonLd($post ?? null, $config) ?>

    <!-- CSS Corporativo -->
    <link rel="stylesheet" href="<?= Security::e(ThemeManager::asset('css/style.css')) ?>">

    <!-- Scripts de Inicialização de Tema e App (Compatibilidade Estrita com CSP sem unsafe-inline) -->
    <script src="/assets/js/theme-init.js" defer></script>
    <script src="/assets/js/app.js" defer></script>
    <?php \LightPress\Core\Hook::doAction('frontend_head'); ?>
</head>
<body>
<?php if (!empty($isPreview)): ?>
    <div class="preview-banner" style="background: #f59e0b; color: #78350f; padding: 0.75rem 1.5rem; position: sticky; top: 0; z-index: 9999; display: flex; justify-content: space-between; align-items: center; font-family: system-ui, sans-serif; font-size: 0.9rem; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
        <div>
            ⚠️ MODO DE PRÉVIA PRIVADA (Status: <strong><?= strtoupper(Security::e($post['status'] ?? 'DRAFT')) ?></strong><?= !empty($post['revision_id']) ? ' &bull; Revisão #' . (int)$post['revision_id'] : '' ?>)
            &bull; Este conteúdo não é público nem cacheável.
        </div>
        <div>
            <a href="/admin/editor.php?id=<?= (int)$post['id'] ?>" style="background: #78350f; color: white; padding: 0.4rem 0.8rem; border-radius: 6px; text-decoration: none; font-size: 0.85rem; margin-left: 0.5rem;">&larr; Voltar ao Editor</a>
        </div>
    </div>
<?php endif; ?>
    <header class="corp-header">
        <div class="corp-container">
            <div class="corp-nav-inner">
                <a href="/" class="corp-brand">
                    <span class="corp-brand-dot"></span>
                    <span><?= Security::e($siteName) ?></span>
                </a>

                <nav class="corp-nav-links" style="display: flex;">
                    <a href="/" class="corp-nav-link <?= (($_SERVER['REQUEST_URI'] ?? '') === '/' ? 'active' : '') ?>">Início</a>
                    <?php foreach ($navPages as $np): ?>
                        <a href="/<?= Security::e($np['slug']) ?>/" class="corp-nav-link">
                            <?= Security::e($np['title']) ?>
                        </a>
                    <?php endforeach; ?>
                    <a href="/blog/" class="corp-nav-link <?= (str_starts_with(($_SERVER['REQUEST_URI'] ?? ''), '/blog') ? 'active' : '') ?>">Blog</a>
                </nav>

                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <button type="button" id="btnThemeToggle" title="Alternar Modo Escuro" class="corp-btn corp-btn-secondary" style="padding: 0.5rem 0.75rem; font-size: 1rem;">
                        <span id="themeIcon">🌙</span>
                    </button>
                    <?php if (!empty($headerCtaText)): ?>
                        <a href="<?= Security::e($headerCtaLink) ?>" class="corp-btn corp-btn-primary">
                            <?= Security::e($headerCtaText) ?> &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
