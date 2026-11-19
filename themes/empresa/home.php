<?php
declare(strict_types=1);

use LightPress\Core\BlockCompiler;
use LightPress\Core\Icon;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\ThemeManager;

require __DIR__ . '/header.php';

$heroBadge = (string)ThemeManager::option('hero_badge', 'Inovação & Excelência Corporativa');
$heroTitle = (string)ThemeManager::option('hero_title', 'Transforme seu Negócio com Soluções de Alta Performance');
$heroSubtitle = (string)ThemeManager::option('hero_subtitle', 'Oferecemos consultoria estratégica, tecnologia de ponta e soluções sob medida para acelerar os resultados da sua empresa.');
$heroPrimText = (string)ThemeManager::option('hero_primary_cta_text', 'Solicitar Proposta');
$heroPrimLink = BlockCompiler::sanitizeUrl((string)ThemeManager::option('hero_primary_cta_link', '/contato/'));
$heroSecText = (string)ThemeManager::option('hero_secondary_cta_text', 'Nossos Serviços');
$heroSecLink = BlockCompiler::sanitizeUrl((string)ThemeManager::option('hero_secondary_cta_link', '#servicos'));

$servicesTitle = (string)ThemeManager::option('services_title', 'Nossas Soluções Especializadas');
$servicesSub = (string)ThemeManager::option('services_subtitle', 'Desenvolvemos produtos e consultorias com rigor técnico, garantindo resultados mensuráveis.');

$ctaTitle = (string)ThemeManager::option('cta_banner_title', 'Pronto para elevar o nível da sua operação?');
$ctaSub = (string)ThemeManager::option('cta_banner_subtitle', 'Fale hoje mesmo com nossos especialistas e receba um diagnóstico técnico sem compromisso.');
$ctaBtnText = (string)ThemeManager::option('cta_banner_btn_text', 'Falar com um Consultor');
$ctaBtnLink = BlockCompiler::sanitizeUrl((string)ThemeManager::option('cta_banner_btn_link', '/contato/'));
?>

<main>
    <!-- HERO SECTION -->
    <section class="corp-hero">
        <div class="corp-container">
            <?php if (!empty($heroBadge)): ?>
                <div class="corp-hero-badge">
                    <span>✨</span> <?= Security::e($heroBadge) ?>
                </div>
            <?php endif; ?>

            <h1 class="corp-hero-title"><?= Security::e($heroTitle) ?></h1>
            <p class="corp-hero-subtitle"><?= Security::e($heroSubtitle) ?></p>

            <div class="corp-hero-actions">
                <?php if (!empty($heroPrimText)): ?>
                    <a href="<?= Security::e($heroPrimLink) ?>" class="corp-btn corp-btn-primary" style="padding: 0.85rem 2rem; font-size: 1rem;">
                        <?= Security::e($heroPrimText) ?> &rarr;
                    </a>
                <?php endif; ?>
                <?php if (!empty($heroSecText)): ?>
                    <a href="<?= Security::e($heroSecLink) ?>" class="corp-btn corp-btn-secondary" style="padding: 0.85rem 1.75rem; font-size: 1rem;">
                        <?= Security::e($heroSecText) ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- BARRA DE MÉTRICAS -->
            <div class="corp-metrics-bar">
                <div class="corp-metric-item">
                    <div class="corp-metric-num"><?= Security::e((string)ThemeManager::option('metrics_1_number', '+10')) ?></div>
                    <div class="corp-metric-label"><?= Security::e((string)ThemeManager::option('metrics_1_label', 'Anos de Experiência')) ?></div>
                </div>
                <div class="corp-metric-item">
                    <div class="corp-metric-num"><?= Security::e((string)ThemeManager::option('metrics_2_number', '99.9%')) ?></div>
                    <div class="corp-metric-label"><?= Security::e((string)ThemeManager::option('metrics_2_label', 'Disponibilidade SLA')) ?></div>
                </div>
                <div class="corp-metric-item">
                    <div class="corp-metric-num"><?= Security::e((string)ThemeManager::option('metrics_3_number', '+500')) ?></div>
                    <div class="corp-metric-label"><?= Security::e((string)ThemeManager::option('metrics_3_label', 'Projetos Concluídos')) ?></div>
                </div>
                <div class="corp-metric-item">
                    <div class="corp-metric-num"><?= Security::e((string)ThemeManager::option('metrics_4_number', '100/100')) ?></div>
                    <div class="corp-metric-label"><?= Security::e((string)ThemeManager::option('metrics_4_label', 'PageSpeed Score')) ?></div>
                </div>
            </div>
        </div>
    </section>

    <!-- PILARES / SERVIÇOS -->
    <section class="corp-section" id="servicos">
        <div class="corp-container">
            <div class="corp-section-header">
                <h2 class="corp-section-title"><?= Security::e($servicesTitle) ?></h2>
                <p class="corp-section-sub"><?= Security::e($servicesSub) ?></p>
            </div>

            <div class="corp-grid-3">
                <div class="corp-card">
                    <div class="corp-card-icon">
                        <?= Icon::svg((string)ThemeManager::option('service_1_icon', 'speed'), 'text-primary', 28) ?>
                    </div>
                    <h3 class="corp-card-title"><?= Security::e((string)ThemeManager::option('service_1_title', 'Performance Extrema')) ?></h3>
                    <p class="corp-card-desc"><?= Security::e((string)ThemeManager::option('service_1_desc', 'Arquiteturas otimizadas para entregar tempo de resposta ultrarrápido.')) ?></p>
                </div>

                <div class="corp-card">
                    <div class="corp-card-icon">
                        <?= Icon::svg((string)ThemeManager::option('service_2_icon', 'verified'), 'text-primary', 28) ?>
                    </div>
                    <h3 class="corp-card-title"><?= Security::e((string)ThemeManager::option('service_2_title', 'Segurança & Confiabilidade')) ?></h3>
                    <p class="corp-card-desc"><?= Security::e((string)ThemeManager::option('service_2_desc', 'Blindagem e auditoria contínua de processos com conformidade estrita.')) ?></p>
                </div>

                <div class="corp-card">
                    <div class="corp-card-icon">
                        <?= Icon::svg((string)ThemeManager::option('service_3_icon', 'trending_up'), 'text-primary', 28) ?>
                    </div>
                    <h3 class="corp-card-title"><?= Security::e((string)ThemeManager::option('service_3_title', 'Escalabilidade Contínua')) ?></h3>
                    <p class="corp-card-desc"><?= Security::e((string)ThemeManager::option('service_3_desc', 'Infraestrutura moderna projetada para suportar alta demanda sem atrito.')) ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- BLOCO SOBRE / CONTEÚDO DE PÁGINA RICA (CASO VINCULADA) -->
    <?php if (!empty($staticPage)): ?>
        <section class="corp-section" style="background: var(--corp-surface); border-top: 1px solid var(--corp-border); border-bottom: 1px solid var(--corp-border);">
            <div class="corp-container" style="max-width: 900px;">
                <div class="corp-section-header" style="text-align: left; margin-bottom: 2rem;">
                    <h2 class="corp-section-title"><?= Security::e($staticPage['title']) ?></h2>
                </div>
                <div style="font-size: 1.05rem; line-height: 1.7; color: var(--corp-text);">
                    <?= BlockCompiler::sanitizeHtml((string)($staticPage['content_html'] ?? '')) ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- SEÇÃO DE ARTIGOS RECENTES DO BLOG -->
    <?php if (!empty($recentPosts)): ?>
        <section class="corp-section">
            <div class="corp-container">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2.5rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h2 class="corp-section-title" style="margin-bottom: 0.25rem;">Novidades & Artigos Técnicos</h2>
                        <p class="corp-section-sub" style="margin: 0;">Insights, análises de mercado e comunicados da nossa equipe.</p>
                    </div>
                    <div>
                        <a href="/blog/" class="corp-btn corp-btn-secondary">
                            Acessar Blog Completo &rarr;
                        </a>
                    </div>
                </div>

                <div class="corp-grid-3">
                    <?php foreach ($recentPosts as $p): ?>
                        <a href="/<?= Security::e($p['slug']) ?>/" class="corp-blog-card">
                            <?php if (!empty($p['featured_image'])): ?>
                                <img src="<?= Security::e(ImageProcessor::toAvifUrl($p['featured_image'])) ?>" class="corp-blog-thumb" alt="<?= Security::e($p['title']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="corp-blog-thumb" style="display: flex; align-items: center; justify-content: center; font-size: 2rem;">📄</div>
                            <?php endif; ?>

                            <div class="corp-blog-body">
                                <div class="corp-blog-tag"><?= Security::e($p['category']) ?></div>
                                <h3 class="corp-blog-title"><?= Security::e($p['title']) ?></h3>
                                <p class="corp-blog-excerpt"><?= Security::e(mb_strimwidth($p['summary'] ?? '', 0, 110, '...')) ?></p>
                                <div class="corp-blog-meta">
                                    Por <?= Security::e($p['author_name'] ?? 'Equipe') ?> &bull; <?= date('d/m/Y', strtotime((string)($p['published_at'] ?? 'now')) ?: time()) ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- FAIXA DE CONVERSÃO CTA FINAL -->
    <div class="corp-container">
        <section class="corp-cta-banner">
            <h2 class="corp-cta-title"><?= Security::e($ctaTitle) ?></h2>
            <p class="corp-cta-sub"><?= Security::e($ctaSub) ?></p>
            <?php if (!empty($ctaBtnText)): ?>
                <a href="<?= Security::e($ctaBtnLink) ?>" class="corp-btn" style="background: #ffffff; color: var(--corp-primary); padding: 0.9rem 2.25rem; font-size: 1.05rem; font-weight: 800;">
                    <?= Security::e($ctaBtnText) ?> &rarr;
                </a>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
