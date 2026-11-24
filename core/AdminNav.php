<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * LightPress / SpeedPress - Renderizador Central de Navegação do Painel Administrativo
 */
final class AdminNav
{
    /**
     * Renderiza o shell administrativo com Sidebar lateral colapsável, drawer mobile e topbar compacta.
     *
     * @param string $activePage Slug da página ativa
     * @param array $currentUser Usuário atualmente autenticado
     * @param string $csrfToken Token CSRF ativo para logout
     * @param string $pageTitle Título exibido na topbar
     * @return string HTML compilado do cabeçalho e abertura do container principal
     */
    public static function renderHeader(string $activePage, array $currentUser, string $csrfToken, string $pageTitle = 'Painel Administrativo'): string
    {
        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        $menuPlugins = $isAdmin ? PluginManager::getActiveMenuPlugins() : [];
        $currentPluginParam = $_GET['plugin'] ?? '';

        $siteTitle = Settings::get('site_title', $GLOBALS['config']['app']['name'] ?? 'SpeedRacing');
        $userName = trim((string)($currentUser['name'] ?? 'Usuário'));
        $userRole = (string)($currentUser['role'] ?? 'autor');
        $initials = strtoupper(mb_substr($userName, 0, 2, 'UTF-8'));
        $isPluginsGroupOpen = in_array($activePage, ['plugins', 'plugin-settings'], true);

        ob_start();
        ?>
        <div class="admin-shell">
            <aside class="admin-sidebar" aria-label="Menu Administrativo">
                <div class="sidebar-brand-row">
                    <a href="/admin/dashboard.php" class="brand" title="<?= Security::e($siteTitle) ?>">
                        <span class="brand-dot" aria-hidden="true"></span>
                        <span class="brand-text"><?= Security::e($siteTitle) ?></span>
                    </a>
                    <button type="button" class="sidebar-collapse-btn" onclick="toggleSidebarCollapse()" title="Recolher/Expandir menu" aria-label="Recolher menu">
                        <?= Icon::svg('chevron_left', '', 16) ?>
                    </button>
                </div>

                <nav class="sidebar-nav">
                    <div class="sidebar-section-label">Editorial</div>

                    <a href="/admin/dashboard.php" class="sidebar-link <?= $activePage === 'dashboard' ? 'active' : '' ?>" title="Notícias">
                        <?= Icon::svg('newspaper', '', 18) ?>
                        <span class="sidebar-label">Notícias</span>
                    </a>
                    <a href="/admin/pages.php" class="sidebar-link <?= $activePage === 'pages' ? 'active' : '' ?>" title="Páginas">
                        <?= Icon::svg('file_text', '', 18) ?>
                        <span class="sidebar-label">Páginas</span>
                    </a>
                    <a href="/admin/media.php" class="sidebar-link <?= $activePage === 'media' ? 'active' : '' ?>" title="Mídias">
                        <?= Icon::svg('image', '', 18) ?>
                        <span class="sidebar-label">Mídias</span>
                    </a>
                    <a href="/admin/categories.php" class="sidebar-link <?= $activePage === 'categories' ? 'active' : '' ?>" title="Categorias">
                        <?= Icon::svg('folder', '', 18) ?>
                        <span class="sidebar-label">Categorias</span>
                    </a>
                    <a href="/admin/stats.php" class="sidebar-link <?= $activePage === 'stats' ? 'active' : '' ?>" title="Mais Lidas">
                        <?= Icon::svg('trending_up', '', 18) ?>
                        <span class="sidebar-label">Mais Lidas</span>
                    </a>

                    <?php if ($isAdmin): ?>
                        <div class="sidebar-section-label">Administração</div>

                        <a href="/admin/users.php" class="sidebar-link <?= $activePage === 'users' ? 'active' : '' ?>" title="Usuários & Autores">
                            <?= Icon::svg('users', '', 18) ?>
                            <span class="sidebar-label">Usuários & Autores</span>
                        </a>
                        <a href="/admin/themes.php" class="sidebar-link <?= $activePage === 'themes' ? 'active' : '' ?>" title="Temas">
                            <?= Icon::svg('palette', '', 18) ?>
                            <span class="sidebar-label">Temas</span>
                        </a>

                        <div class="sidebar-group <?= $isPluginsGroupOpen ? 'open' : '' ?>">
                            <button type="button" class="sidebar-link sidebar-group-toggle <?= $isPluginsGroupOpen ? 'active' : '' ?>" onclick="toggleSidebarGroup(this)" title="Plugins">
                                <span class="sidebar-group-left">
                                    <?= Icon::svg('plug', '', 18) ?>
                                    <span class="sidebar-label">Plugins</span>
                                </span>
                                <span class="sidebar-chevron"><?= Icon::svg('chevron_down', '', 15) ?></span>
                            </button>
                            <div class="sidebar-submenu">
                                <a href="/admin/plugins.php" class="sidebar-sublink <?= ($activePage === 'plugins' && empty($currentPluginParam)) ? 'active' : '' ?>">
                                    Gerenciar Plugins
                                </a>
                                <?php foreach ($menuPlugins as $item): ?>
                                    <a href="/admin/plugin-settings.php?plugin=<?= urlencode($item['id']) ?>"
                                       class="sidebar-sublink <?= ($activePage === 'plugin-settings' && $currentPluginParam === $item['id']) ? 'active' : '' ?>">
                                        <?= Security::e($item['admin_title']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <a href="/admin/settings.php" class="sidebar-link <?= $activePage === 'settings' ? 'active' : '' ?>" title="Configurações">
                            <?= Icon::svg('settings', '', 18) ?>
                            <span class="sidebar-label">Configurações</span>
                        </a>
                        <a href="/admin/system.php" class="sidebar-link <?= $activePage === 'system' ? 'active' : '' ?>" title="Diagnóstico">
                            <?= Icon::svg('activity', '', 18) ?>
                            <span class="sidebar-label">Diagnóstico</span>
                        </a>
                    <?php endif; ?>
                </nav>

                <div class="sidebar-footer">
                    <a href="/admin/profile.php" class="sidebar-user-card <?= $activePage === 'profile' ? 'active' : '' ?>" title="Meu Perfil">
                        <span class="user-avatar"><?= Security::e($initials) ?></span>
                        <span class="sidebar-user-meta">
                            <span class="sidebar-user-name"><?= Security::e($userName) ?></span>
                            <span class="sidebar-user-role"><?= Security::e($userRole) ?></span>
                        </span>
                    </a>
                    <form method="POST" action="/admin/dashboard.php" style="margin: 0;">
                        <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="sidebar-link" style="width: 100%; background: none; border: none; font-family: inherit; cursor: pointer; color: var(--danger-text);" title="Sair do sistema">
                            <?= Icon::svg('log_out', '', 18) ?>
                            <span class="sidebar-label">Sair</span>
                        </button>
                    </form>
                </div>
            </aside>

            <div class="sidebar-backdrop" onclick="toggleMobileSidebar(false)"></div>

            <div class="admin-main">
                <header class="admin-topbar">
                    <div class="topbar-left">
                        <button type="button" class="mobile-menu-btn" onclick="toggleMobileSidebar()" aria-label="Abrir menu">
                            <?= Icon::svg('menu', '', 18) ?>
                        </button>
                        <span class="topbar-breadcrumb"><?= Security::e($pageTitle) ?></span>
                    </div>
                    <div class="topbar-actions">
                        <a href="/admin/editor.php" class="btn btn-sm" title="Nova Publicação">
                            <?= Icon::svg('plus', '', 15) ?>
                            <span class="hide-xs">Nova Publicação</span>
                        </a>
                        <a href="/" target="_blank" class="icon-action-btn" title="Ver Site em nova aba" aria-label="Ver Site">
                            <?= Icon::svg('open_in_new', '', 16) ?>
                        </a>
                        <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" aria-label="Alternar Tema" id="btnThemeToggle">
                            <span id="lpIconSun" style="display:none;"><?= Icon::svg('sun', '', 17) ?></span>
                            <span id="lpIconMoon"><?= Icon::svg('moon', '', 17) ?></span>
                        </button>
                    </div>
                </header>
                <main class="admin-content">
        <?php
        return (string)ob_get_clean();
    }
}
