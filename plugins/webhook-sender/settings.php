<?php
declare(strict_types=1);

/**
 * Tela de Configurações - Webhook Sender
 */

use LightPress\Core\AdminNav;
use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Core\Settings;

$message = null;
$error = null;
$testResults = null;

// Salvar ou Testar Webhooks
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['_csrf_token'] ?? '';
    if (!Security::validateCsrfToken($submittedToken)) {
        $error = 'Token de segurança CSRF inválido ou expirado.';
    } elseif (isset($_POST['save_settings'])) {
        $rawInput = trim((string)($_POST['webhook_urls'] ?? ''));
        $parsed = WebhookSenderService::parseUrls($rawInput);
        
        $cleanString = implode(",\n", $parsed);
        Settings::set('plugin_webhook_sender_urls', $cleanString);
        
        if (empty($parsed) && !empty($rawInput)) {
            $error = 'Aviso: Nenhuma URL válida iniciada com http:// ou https:// foi detectada.';
        } else {
            $message = 'Configurações de servidores de Webhook salvas com sucesso!';
        }
    } elseif (isset($_POST['test_webhooks'])) {
        $rawInput = trim((string)($_POST['webhook_urls'] ?? ''));
        $parsed = WebhookSenderService::parseUrls($rawInput);

        if (empty($parsed)) {
            $error = 'Por favor, insira pelo menos uma URL de webhook válida para realizar o teste.';
        } else {
            $testPayload = [
                'username' => $config['app']['name'] ?? 'SpeedRacing',
                'content'  => '🔔 **Teste de Webhook do LightPress** executado com sucesso!',
                'embeds'   => [
                    [
                        'title'       => 'Teste de Conexão - Webhook Sender',
                        'type'        => 'rich',
                        'description' => 'Se você recebeu esta notificação, seu servidor ou canal está perfeitamente conectado ao LightPress!',
                        'url'         => $config['app']['url'] ?? 'https://speedracing.com.br',
                        'color'       => hexdec('2563eb'),
                        'footer'      => [
                            'text' => 'Teste emitido pelo painel editorial em ' . date('d/m/Y H:i:s'),
                        ],
                    ]
                ],
                'event'    => 'test_ping',
            ];

            $testResults = WebhookSenderService::dispatch($parsed, $testPayload);
            $message = 'Teste de disparo concluído! Confira os resultados individuais abaixo.';
        }
    }
}

// Recupera valor atual configurado
$currentUrls = (string)Settings::get('plugin_webhook_sender_urls', '');

// Consulta os últimos envios registrados na tabela relacional
$recentLogs = [];
try {
    $recentLogs = Database::fetchAll("
        SELECT l.post_id, l.sent_at, l.servers_count, l.last_response_codes, p.title, p.slug
        FROM plugin_webhook_sender_logs l
        LEFT JOIN posts p ON p.id = l.post_id
        ORDER BY l.sent_at DESC
        LIMIT 10
    ");
} catch (\Throwable) {
    // Tabela pode ainda estar vazia
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Webhook Sender</title>
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

        .container { max-width: 1000px; margin: 2.5rem auto; padding: 0 1.5rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 2rem; }

        .btn { padding: 0.7rem 1.25rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: background 0.15s; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn:hover { background: #1d4ed8; }
        .btn-secondary { background: #e2e8f0; color: var(--text); }
        .btn-secondary:hover { background: #cbd5e1; }
        [data-theme="dark"] .btn-secondary { background: #334155; color: #f1f5f9; }
        [data-theme="dark"] .btn-secondary:hover { background: #475569; }

        textarea { width: 100%; padding: 0.85rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.92rem; font-family: monospace; background: var(--bg); color: var(--text); outline: none; resize: vertical; line-height: 1.5; }
        textarea:focus { border-color: var(--primary-accent); }

        .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
        [data-theme="dark"] .alert-success { background: #064e3b; color: #6ee7b7; }
        [data-theme="dark"] .alert-error { background: #7f1d1d; color: #fca5a5; }

        .badge-status { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; font-family: monospace; }
        .badge-2xx { background: #dcfce7; color: #166534; }
        .badge-4xx { background: #fee2e2; color: #991b1b; }
        [data-theme="dark"] .badge-2xx { background: #064e3b; color: #6ee7b7; }
        [data-theme="dark"] .badge-4xx { background: #7f1d1d; color: #fca5a5; }

        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; }
        th { background: #f8fafc; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; font-weight: 700; }
        [data-theme="dark"] th { background: #1e293b; }
        td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    </style>
</head>
<body>
    <?= AdminNav::renderHeader('plugin-settings', $currentUser, $csrfToken) ?>

    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem;">
            <div>
                <a href="/admin/plugins.php" style="color: var(--primary-accent); text-decoration: none; font-size: 0.85rem; font-weight: 700;">&larr; Voltar para Plugins</a>
                <h1 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; margin-top: 0.35rem;">
                    🔗 Webhook Sender
                </h1>
            </div>
            <span class="badge-status badge-2xx" style="padding: 0.4rem 0.75rem; font-size: 0.82rem;">v1.0.0 Ativo</span>
        </div>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <?php if (!empty($testResults)): ?>
            <div class="card" style="border-left: 4px solid var(--primary-accent);">
                <h3 style="font-size: 1rem; font-weight: 800; margin-bottom: 0.75rem;">📡 Resultado do Teste de Conexão:</h3>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($testResults as $testUrl => $code): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.75rem; background: var(--bg); border-radius: 6px; font-size: 0.85rem; font-family: monospace;">
                            <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;"><?= Security::e($testUrl) ?></span>
                            <?php if ($code >= 200 && $code < 300): ?>
                                <span class="badge-status badge-2xx">HTTP <?= $code ?> (Sucesso)</span>
                            <?php else: ?>
                                <span class="badge-status badge-4xx">HTTP <?= $code ?: 'Falha / Timeout' ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.75rem; color: var(--primary);">
                Servidores e Canais de Notificação
            </h2>
            <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 1.25rem;">
                Cole as URLs dos Webhooks de destino (ex: Discord, Slack, n8n, Zapier ou endpoint HTTP próprio).<br>
                <strong>Você pode informar múltiplos servidores separando-os por vírgula (<code>,</code>) ou um por linha.</strong>
            </p>

            <form method="POST">
                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                
                <div style="margin-bottom: 1.5rem;">
                    <textarea name="webhook_urls" rows="6" placeholder="https://discord.com/api/webhooks/...,&#10;https://meuservidor.com/webhook,&#10;https://hooks.slack.com/services/..."><?= Security::e($currentUrls) ?></textarea>
                </div>

                <div style="display: flex; gap: 0.75rem; align-items: center;">
                    <button type="submit" name="save_settings" class="btn">
                        Salvar Configurações
                    </button>
                    <button type="submit" name="test_webhooks" class="btn btn-secondary">
                        🧪 Testar Envio de Webhook
                    </button>
                </div>
            </form>
        </div>

        <div class="card" style="padding: 0; overflow: hidden;">
            <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border);">
                <h3 style="font-size: 1.05rem; font-weight: 800; color: var(--primary);">
                    📋 Últimas Notificações Enviadas (Tabela Relacional)
                </h3>
            </div>
            <?php if (empty($recentLogs)): ?>
                <div style="padding: 2rem 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.88rem;">
                    Nenhum envio registrado até o momento. As notificações serão registradas automaticamente quando novas matérias forem publicadas.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Matéria</th>
                            <th>Data do Envio</th>
                            <th style="text-align: center;">Servidores</th>
                            <th>Status Retornado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td>
                                    <strong><?= Security::e($log['title'] ?? 'Matéria #' . $log['post_id']) ?></strong>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.82rem;">
                                    <?= Security::e($log['sent_at']) ?>
                                </td>
                                <td style="text-align: center; font-weight: 700;">
                                    <?= (int)$log['servers_count'] ?>
                                </td>
                                <td style="font-family: monospace; font-size: 0.8rem; color: var(--text-muted);">
                                    <?= Security::e($log['last_response_codes'] ?? '-') ?>
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
