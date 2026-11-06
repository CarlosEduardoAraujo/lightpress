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
$currentUser = Security::requireAuth(roles: ['admin']);
$csrfToken = Security::getCsrfToken();

$message = null;
$error = null;

// Processar ações de ativação e desativação
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['_csrf_token'] ?? '';
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plugins - Painel Administrativo SpeedPress</title>
    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --surface: #ffffff;
            --radius: 8px;
        }

        [data-theme="dark"] {
            --primary: #f8fafc;
            --primary-accent: #3b82f6;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
            --border: #334155;
            --bg: #0f172a;
            --surface: #1e293b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }
        
        .site-header { 
            background: var(--surface); 
            border-bottom: 1px solid var(--border); 
            padding: 0.85rem 1.75rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .brand { font-size: 1.3rem; font-weight: 800; color: var(--primary); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; align-items: center; }
        .nav-links a { color: var(--text-muted); text-decoration: none; font-weight: 600; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary-accent); }

        .theme-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .theme-btn:hover { background: var(--border); }

        .container { max-width: 1200px; margin: 2.5rem auto; padding: 0 1.5rem; }
        .header-section { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 2rem; }

        .btn { padding: 0.55rem 1rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: background 0.15s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: var(--text); }
        .btn-secondary:hover { background: #cbd5e1; }
        [data-theme="dark"] .btn-secondary { background: #334155; color: #f1f5f9; }
        [data-theme="dark"] .btn-secondary:hover { background: #475569; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }

        .badge { display: inline-block; padding: 0.25rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #f1f5f9; color: #64748b; }
        [data-theme="dark"] .badge-active { background: #064e3b; color: #6ee7b7; }
        [data-theme="dark"] .badge-inactive { background: #334155; color: #94a3b8; }

        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.8rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; }
        [data-theme="dark"] th { background: #1e293b; }
        td { padding: 1.1rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(0,0,0,0.015); }
        [data-theme="dark"] tr:hover td { background: rgba(255,255,255,0.02); }

        .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
        [data-theme="dark"] .alert-success { background: #064e3b; color: #6ee7b7; }
        [data-theme="dark"] .alert-error { background: #7f1d1d; color: #fca5a5; }
    </style>
</head>
<body>
    <?= AdminNav::renderHeader('plugins', $currentUser, $csrfToken) ?>

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

    <script>
        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme');
            const target = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', target);
            localStorage.setItem('lp_theme', target);
            document.getElementById('btnThemeToggle').textContent = target === 'dark' ? '☀️' : '🌙';
        }

        const savedTheme = localStorage.getItem('lp_theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        document.getElementById('btnThemeToggle').textContent = savedTheme === 'dark' ? '☀️' : '🌙';
    </script>
</body>
</html>
