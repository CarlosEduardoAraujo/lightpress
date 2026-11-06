<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * LightPress / SpeedPress - Renderizador Central de Navegação do Painel Administrativo
 */
final class AdminNav
{
    /**
     * Renderiza o cabeçalho completo do admin (<header class="site-header">) com menu e submenu de plugins.
     *
     * @param string $activePage Slug da página ativa (ex: 'dashboard', 'pages', 'media', 'categories', 'stats', 'users', 'settings', 'system', 'plugins', 'plugin-settings')
     * @param array $currentUser Usuário atualmente autenticado
     * @param string $csrfToken Token CSRF ativo para logout
     * @return string HTML compilado do cabeçalho
     */
    public static function renderHeader(string $activePage, array $currentUser, string $csrfToken): string
    {
        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        $menuPlugins = $isAdmin ? PluginManager::getActiveMenuPlugins() : [];
        $currentPluginParam = $_GET['plugin'] ?? '';

        $siteTitle = Settings::get('site_title', $GLOBALS['config']['app']['name'] ?? 'SpeedRacing');

        ob_start();
        ?>
        <style>
            .nav-dropdown { position: relative; display: inline-flex; align-items: center; }
            .nav-dropdown-toggle { display: inline-flex; align-items: center; gap: 0.25rem; cursor: pointer; color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 0.92rem; transition: color 0.15s; }
            .nav-dropdown-toggle:hover, .nav-dropdown-toggle.active { color: var(--primary-accent); }
            .nav-dropdown-toggle .arrow { font-size: 0.72rem; transition: transform 0.2s; opacity: 0.75; }
            .nav-dropdown:hover .nav-dropdown-toggle .arrow { transform: rotate(180deg); }
            .nav-dropdown-menu { 
                display: none; 
                position: absolute; 
                top: 100%; 
                left: 0; 
                margin-top: 0.45rem; 
                min-width: 210px; 
                background: var(--surface); 
                border: 1px solid var(--border); 
                border-radius: var(--radius, 8px); 
                box-shadow: 0 10px 20px -3px rgba(0,0,0,0.12), 0 4px 6px -4px rgba(0,0,0,0.08); 
                padding: 0.4rem 0; 
                z-index: 1000; 
            }
            .nav-dropdown:hover .nav-dropdown-menu,
            .nav-dropdown:focus-within .nav-dropdown-menu { 
                display: block; 
            }
            .nav-dropdown-item { 
                display: flex; 
                align-items: center; 
                gap: 0.5rem; 
                padding: 0.55rem 1rem; 
                color: var(--text); 
                text-decoration: none; 
                font-weight: 500; 
                font-size: 0.88rem; 
                transition: background 0.15s, color 0.15s; 
                white-space: nowrap; 
            }
            .nav-dropdown-item:hover, .nav-dropdown-item.active { 
                background: var(--bg); 
                color: var(--primary-accent); 
                font-weight: 700; 
            }
            .nav-dropdown-divider { 
                height: 1px; 
                background: var(--border); 
                margin: 0.35rem 0; 
            }
        </style>
        <header class="site-header">
            <div style="display: flex; align-items: center; gap: 2.5rem;">
                <a href="/admin/dashboard.php" class="brand"><?= Security::e($siteTitle) ?></a>
                <nav class="nav-links">
                    <a href="/admin/dashboard.php" class="<?= $activePage === 'dashboard' ? 'active' : '' ?>">Notícias</a>
                    <a href="/admin/pages.php" class="<?= $activePage === 'pages' ? 'active' : '' ?>">Páginas</a>
                    <a href="/admin/media.php" class="<?= $activePage === 'media' ? 'active' : '' ?>">Mídias</a>
                    <a href="/admin/categories.php" class="<?= $activePage === 'categories' ? 'active' : '' ?>">Categorias</a>
                    <a href="/admin/stats.php" class="<?= $activePage === 'stats' ? 'active' : '' ?>">Mais Lidas 📈</a>
                    <?php if ($isAdmin): ?>
                        <a href="/admin/users.php" class="<?= $activePage === 'users' ? 'active' : '' ?>">Usuários & Autores</a>
                        
                        <!-- Submenu Dropdown de Plugins -->
                        <div class="nav-dropdown">
                            <a href="/admin/plugins.php" class="nav-dropdown-toggle <?= in_array($activePage, ['plugins', 'plugin-settings'], true) ? 'active' : '' ?>">
                                Plugins <span class="arrow">▾</span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <a href="/admin/plugins.php" class="nav-dropdown-item <?= ($activePage === 'plugins' && empty($currentPluginParam)) ? 'active' : '' ?>">
                                    <span>⚙️</span> Gerenciar Plugins
                                </a>
                                <?php if (!empty($menuPlugins)): ?>
                                    <div class="nav-dropdown-divider"></div>
                                    <?php foreach ($menuPlugins as $item): ?>
                                        <a href="/admin/plugin-settings.php?plugin=<?= urlencode($item['id']) ?>" 
                                           class="nav-dropdown-item <?= ($activePage === 'plugin-settings' && $currentPluginParam === $item['id']) ? 'active' : '' ?>">
                                            <span>🔗</span> <?= Security::e($item['admin_title']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <a href="/admin/settings.php" class="<?= $activePage === 'settings' ? 'active' : '' ?>">Configurações</a>
                        <a href="/admin/system.php" class="<?= $activePage === 'system' ? 'active' : '' ?>">Diagnóstico</a>
                    <?php endif; ?>
                    <a href="/" target="_blank" style="color: var(--primary-accent);">Ver Site &rarr;</a>
                </nav>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <span>Olá, <strong><?= Security::e($currentUser['name'] ?? '') ?></strong></span>
                <a href="/admin/profile.php" style="color: var(--primary-accent); text-decoration: none; font-weight: 600; font-size: 0.9rem;">Meu Perfil</a>
                <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" id="btnThemeToggle">🌙</button>
                <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: #dc2626; text-decoration: none; font-weight: 600;">Sair</a>
            </div>
        </header>
        <?php
        return (string)ob_get_clean();
    }
}
