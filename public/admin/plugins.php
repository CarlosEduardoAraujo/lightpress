<?php
declare(strict_types=1);

/**
 * LightPress / SpeedPress - Painel de Gestão de Plugins
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\AdminNav;
use LightPress\Core\PluginManager;
use LightPress\Core\Security;

// Acesso estritamente restrito a Administradores
$currentUser = Security::requireAuth(null, ['admin']);
$csrfToken = Security::generateCsrfToken();

$message = null;
$error = null;

// Processar ações de ativação e desativação
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = Security::getSafeString($_POST, '_csrf_token');
    if (!Security::validateCsrfToken($submittedToken)) {
        $error = 'Token de segurança CSRF inválido ou expirado.';
    } else {
        $action = trim($_POST['action'] ?? '');
        $pluginSlug = trim($_POST['plugin'] ?? '');

        if (empty($pluginSlug)) {
            $error = 'Nenhum plugin foi especificado.';
        } elseif ($action === 'activate') {
            try {
                PluginManager::activate($pluginSlug);
                $message = "Plugin \"{$pluginSlug}\" ativado com sucesso!";
            } catch (\Throwable $e) {
                $error = "Erro ao ativar o plugin: " . $e->getMessage();
            }
        } elseif ($action === 'deactivate') {
            try {
                PluginManager::deactivate($pluginSlug);
                $message = "Plugin \"{$pluginSlug}\" desativado com sucesso!";
            } catch (\Throwable $e) {
                $error = "Erro ao desativar o plugin: " . $e->getMessage();
            }
        }
    }
}

$availablePlugins = PluginManager::getAvailablePlugins();
$activeSlugs = PluginManager::getActivePluginSlugs();
$pageTitle = 'Gerenciador de Plugins';
$activePage = 'plugins';
require __DIR__ . '/header.php';
?>
<style>
    .plugin-icon { width: 42px; height: 42px; border-radius: var(--radius-sm); background: var(--primary-light); color: var(--primary-accent); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .plugin-badge-active { background: var(--success-bg); color: var(--success-text); padding: 0.22rem 0.6rem; border-radius: var(--radius-pill); font-size: 0.74rem; font-weight: 700; }
    .plugin-badge-inactive { background: var(--surface-hover); color: var(--text-muted); padding: 0.22rem 0.6rem; border-radius: var(--radius-pill); font-size: 0.74rem; font-weight: 700; }
    .badge-active { background: var(--success-bg); color: var(--success-text); padding: 0.22rem 0.6rem; border-radius: var(--radius-pill); font-size: 0.74rem; font-weight: 700; }
    .badge-inactive { background: var(--surface-hover); color: var(--text-muted); padding: 0.22rem 0.6rem; border-radius: var(--radius-pill); font-size: 0.74rem; font-weight: 700; }
    .btn-danger { background: var(--danger-bg); color: var(--danger-text); }
    .btn-danger:hover { filter: brightness(0.95); }
</style>

    <div class="container">
        <div class="header-section">
            <div>
                <h1 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em;">Gerenciador de Plugins</h1>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem;">
                    Módulos nativos em PHP 8.4 puro e isolados para estender as capacidades do LightPress sem sobrecarga de recursos.
                </p>
            </div>
        </div>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="card" style="padding: 0; overflow: hidden;">
            <?php if (empty($availablePlugins)): ?>
                <div style="padding: 3rem 1.5rem; text-align: center; color: var(--text-muted);">
                    <p style="font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem;">Nenhum plugin instalado em <code>/plugins/</code>.</p>
                    <p style="font-size: 0.85rem;">Adicione pastas de plugins com seus manifestos <code>plugin.json</code> para gerenciá-los aqui.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 30%;">Plugin</th>
                            <th style="width: 45%;">Descrição</th>
                            <th style="width: 10%; text-align: center;">Status</th>
                            <th style="width: 15%; text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($availablePlugins as $slug => $plugin): 
                            $isActive = in_array($slug, $activeSlugs, true);
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 700; font-size: 0.95rem; color: var(--primary);">
                                        <?= Security::e($plugin['name'] ?? $slug) ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
                                        v<?= Security::e($plugin['version'] ?? '1.0.0') ?> 
                                        <?php if (!empty($plugin['author'])): ?>
                                            • Por <?= Security::e($plugin['author']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <p style="color: var(--text); font-size: 0.88rem; line-height: 1.4;">
                                        <?= Security::e($plugin['description'] ?? 'Sem descrição fornecida.') ?>
                                    </p>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($isActive): ?>
                                        <span class="badge badge-active">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 0.5rem; justify-content: flex-end; align-items: center;">
                                        <?php if ($isActive): ?>
                                            <?php if (!empty($plugin['admin_settings'])): ?>
                                                <a href="/admin/plugin-settings.php?plugin=<?= urlencode($slug) ?>" class="btn btn-secondary" title="Configurações">
                                                    ⚙️
                                                </a>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente desativar este plugin?');">
                                                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="deactivate">
                                                <input type="hidden" name="plugin" value="<?= Security::e($slug) ?>">
                                                <button type="submit" class="btn btn-danger">Desativar</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="activate">
                                                <input type="hidden" name="plugin" value="<?= Security::e($slug) ?>">
                                                <button type="submit" class="btn">Ativar</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php require __DIR__ . '/footer.php'; ?>
