<?php
declare(strict_types=1);

/**
 * Tela de Configurações Administrativas - API Publisher & Application Passwords
 */

use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Plugin\ApiPublisher\ApiPublisherService;

if (!class_exists(ApiPublisherService::class, false)) {
    require_once __DIR__ . '/api-publisher.php';
}

ApiPublisherService::ensureTablesSchema();

$message = null;
$error = null;
$newlyGenerated = null;

// Processamento de Ações Administrativas (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['_csrf_token'] ?? '';
    if (!Security::validateCsrfToken($submittedToken)) {
        $error = 'Token de segurança CSRF inválido ou expirado. Recarregue a página e tente novamente.';
    } elseif (isset($_POST['create_password'])) {
        $name = Security::getSafeString($_POST, 'name');
        $userId = (int)($_POST['user_id'] ?? 0);
        $allowedIps = Security::getSafeString($_POST, 'allowed_ips');

        try {
            $newlyGenerated = ApiPublisherService::generatePassword($userId, $name, $allowedIps);
            $message = 'Senha de Aplicação gerada com sucesso! Copie as credenciais abaixo imediatamente, pois a chave não será exibida novamente.';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['revoke_password'])) {
        $passwordId = (int)($_POST['password_id'] ?? 0);
        if ($passwordId > 0) {
            ApiPublisherService::revokePassword($passwordId);
            $message = "Senha de Aplicação #{$passwordId} revogada com sucesso.";
        }
    } elseif (isset($_POST['delete_password'])) {
        $passwordId = (int)($_POST['password_id'] ?? 0);
        if ($passwordId > 0) {
            ApiPublisherService::deletePassword($passwordId);
            $message = "Senha de Aplicação #{$passwordId} excluída com sucesso.";
        }
    }
}

// Carregar lista de usuários elegíveis para autoria
$eligibleUsers = Database::fetchAll("
    SELECT id, name, username, role 
    FROM users 
    WHERE is_active = 1 AND role IN ('author', 'editor', 'admin')
    ORDER BY name ASC
");

// Carregar senhas ativas e emitidas
$passwords = ApiPublisherService::listPasswords();

// Carregar logs recentes de ingestão
$recentLogs = ApiPublisherService::getRecentLogs(15);

$canonicalOrigin = rtrim($GLOBALS['config']['app']['canonical_origin'] ?? 'http://localhost:8080', '/');
$apiEndpointUrl = "{$canonicalOrigin}/api/v1/posts";

$pageTitle = 'API Publisher & Application Passwords';
$activePage = 'plugin-settings';
require dirname(__DIR__, 2) . '/public/admin/header.php';
?>

<style>
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); margin-bottom: 2rem; }
    .form-group { margin-bottom: 1.25rem; }
    .form-group label { display: block; font-weight: 600; font-size: 0.88rem; margin-bottom: 0.4rem; color: var(--text); }
    .form-group small { display: block; color: var(--text-muted); font-size: 0.8rem; margin-top: 0.35rem; line-height: 1.4; }
    input[type="text"], select, textarea { width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.92rem; background: var(--bg); color: var(--text); outline: none; }
    input[type="text"]:focus, select:focus, textarea:focus { border-color: var(--primary-accent); }
    
    .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    [data-theme="dark"] .alert-success { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .alert-error { background: #7f1d1d; color: #fca5a5; }

    .badge-status { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; font-family: monospace; }
    .badge-active { background: #dcfce7; color: #166534; }
    .badge-revoked { background: #fee2e2; color: #991b1b; }
    [data-theme="dark"] .badge-active { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .badge-revoked { background: #7f1d1d; color: #fca5a5; }

    .table-container { overflow-x: auto; margin-top: 1rem; }
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; }
    th { background: #f8fafc; padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase; font-weight: 700; }
    [data-theme="dark"] th { background: #1e293b; }
    td { padding: 0.85rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }

    .flash-box { background: #eff6ff; border: 2px dashed #3b82f6; border-radius: var(--radius); padding: 1.5rem; margin-bottom: 2rem; }
    [data-theme="dark"] .flash-box { background: #1e3a8a25; border-color: #3b82f6; }
    .code-secret { background: #1e293b; color: #38bdf8; font-family: monospace; font-size: 1rem; padding: 0.5rem 0.75rem; border-radius: 6px; word-break: break-all; user-select: all; }
    .btn-copy { background: #3b82f6; color: white; border: none; padding: 0.45rem 0.85rem; border-radius: 6px; font-weight: 600; font-size: 0.82rem; cursor: pointer; transition: 0.2s; }
    .btn-copy:hover { background: #2563eb; }
</style>

<div class="admin-content-container">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.6rem; font-weight: 700; margin: 0; color: var(--text);">API Publisher & Senhas de Aplicação</h1>
            <p style="color: var(--text-muted); font-size: 0.92rem; margin: 0.35rem 0 0 0;">
                Ingestão segura de matérias automatizadas (n8n / IA) como rascunhos para triagem editorial humana.
            </p>
        </div>
        <div>
            <a href="/admin/plugins.php" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.5rem 1rem;">
                Voltar aos Plugins
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert-success"><?= Security::e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert-error"><?= Security::e($error) ?></div>
    <?php endif; ?>

    <?php if ($newlyGenerated): ?>
        <div class="flash-box">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                <span style="font-size: 1.3rem;">🔑</span>
                <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #1e40af;">Nova Senha de Aplicação Gerada com Sucesso!</h3>
            </div>
            <p style="margin: 0 0 1rem 0; font-size: 0.88rem; color: var(--text);">
                Copie as credenciais abaixo e configure no nó <strong>HTTP Request</strong> do seu <strong>n8n</strong> (Autenticação HTTP Basic Auth).
                <strong style="color: #b91c1c;">Atenção: Por razões de segurança, este segredo nunca mais será exibido.</strong>
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                <div style="background: var(--surface); padding: 0.85rem; border-radius: var(--radius); border: 1px solid var(--border);">
                    <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 0.3rem;">Endpoint da API</div>
                    <div style="font-family: monospace; font-size: 0.9rem; color: var(--text); word-break: break-all;"><?= Security::e($apiEndpointUrl) ?></div>
                </div>
                <div style="background: var(--surface); padding: 0.85rem; border-radius: var(--radius); border: 1px solid var(--border);">
                    <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 0.3rem;">Usuário Autor (Basic Auth User)</div>
                    <div style="font-family: monospace; font-size: 0.9rem; font-weight: 700; color: var(--text);"><?= Security::e($newlyGenerated['username']) ?></div>
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 0.35rem;">Senha de Aplicação (Basic Auth Password)</div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <div id="secretTokenDisplay" class="code-secret" style="flex: 1;"><?= Security::e($newlyGenerated['full_password']) ?></div>
                    <button type="button" class="btn-copy" onclick="copySecretToClipboard('<?= Security::e($newlyGenerated['full_password']) ?>')">
                        Copiar Senha
                    </button>
                </div>
            </div>

            <div style="font-size: 0.82rem; color: var(--text-muted); background: var(--surface); padding: 0.75rem 1rem; border-radius: var(--radius); border: 1px solid var(--border);">
                💡 <strong>Dica de configuração no n8n:</strong> No nó <code>HTTP Request</code>, defina Method como <code>POST</code>, URL como <code><?= Security::e($apiEndpointUrl) ?></code> e em Authentication selecione <code>Predefined Credential Type -> Basic Auth</code> (Username: <code><?= Security::e($newlyGenerated['username']) ?></code>, Password: a chave copiada acima).
            </div>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
        <!-- Card 1: Criar Senha de Aplicação -->
        <div class="card">
            <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0 0 1rem 0; color: var(--text); display: flex; align-items: center; gap: 0.5rem;">
                <span>➕</span> Emitir Nova Senha de Aplicação
            </h2>
            <form method="POST" action="">
                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="create_password" value="1">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem;">
                    <div class="form-group">
                        <label for="nameInput">Nome da Aplicação / Integração *</label>
                        <input type="text" id="nameInput" name="name" placeholder="Ex: n8n-reddit-ia" required>
                        <small>Rótulo identificador amigável para auditar nos logs.</small>
                    </div>

                    <div class="form-group">
                        <label for="userSelect">Autor Vinculado às Matérias *</label>
                        <select id="userSelect" name="user_id" required>
                            <?php foreach ($eligibleUsers as $u): ?>
                                <option value="<?= (int)$u['id'] ?>">
                                    <?= Security::e($u['name']) ?> (@<?= Security::e($u['username']) ?>) — Papel: <?= Security::e(ucfirst($u['role'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Todo rascunho recebido por esta chave terá a autoria atribuída a este usuário.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="allowedIpsInput">Allowlist de IPs Permitidos (Opcional)</label>
                    <input type="text" id="allowedIpsInput" name="allowed_ips" placeholder="Ex: 192.168.1.100, 203.0.113.5">
                    <small>Se preenchido, apenas requisições vindas destes IPs serão aceitas. Deixe em branco para permitir qualquer IP autenticado com a senha.</small>
                </div>

                <div style="margin-top: 1.25rem;">
                    <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1.25rem; font-size: 0.92rem; font-weight: 600;">
                        Gerar Senha de Aplicação
                    </button>
                </div>
            </form>
        </div>

        <!-- Card 2: Senhas Emitidas -->
        <div class="card">
            <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0 0 0.5rem 0; color: var(--text); display: flex; align-items: center; gap: 0.5rem;">
                <span>🛡️</span> Senhas de Aplicação Cadastradas
            </h2>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 1rem 0;">
                Senhas ativas autorizam ingestão exclusivamente em modo rascunho. Revogar bloqueia imediatamente novas chamadas.
            </p>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Aplicação</th>
                            <th>Autor Vinculado</th>
                            <th>Prefixo Público</th>
                            <th>Criado em</th>
                            <th>Último Uso</th>
                            <th>Último IP</th>
                            <th>Status</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($passwords)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                    Nenhuma Senha de Aplicação emitida até o momento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($passwords as $p): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--text);">
                                        <?= Security::e($p['name']) ?>
                                    </td>
                                    <td>
                                        <?= Security::e($p['author_name']) ?> <span style="color: var(--text-muted); font-size: 0.78rem;">(@<?= Security::e($p['author_username']) ?>)</span>
                                    </td>
                                    <td>
                                        <code style="font-size: 0.82rem; background: var(--bg); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border);"><?= Security::e($p['key_prefix']) ?>...</code>
                                    </td>
                                    <td style="color: var(--text-muted); font-size: 0.82rem;">
                                        <?= Security::e(date('d/m/Y H:i', strtotime($p['created_at']))) ?>
                                    </td>
                                    <td style="color: var(--text-muted); font-size: 0.82rem;">
                                        <?= !empty($p['last_used_at']) ? Security::e(date('d/m/Y H:i', strtotime($p['last_used_at']))) : '<em>Nunca usada</em>' ?>
                                    </td>
                                    <td style="font-family: monospace; font-size: 0.82rem; color: var(--text-muted);">
                                        <?= !empty($p['last_ip']) ? Security::e($p['last_ip']) : '—' ?>
                                    </td>
                                    <td>
                                        <?php if (empty($p['is_revoked'])): ?>
                                            <span class="badge-status badge-active">Ativa</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-revoked">Revogada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <?php if (empty($p['is_revoked'])): ?>
                                            <form method="POST" action="" style="display: inline-block;" onsubmit="return confirm('Deseja realmente revogar esta Senha de Aplicação? O n8n não conseguirá mais publicar matérias com ela.');">
                                                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                <input type="hidden" name="revoke_password" value="1">
                                                <input type="hidden" name="password_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="btn" style="background: #ef4444; color: white; padding: 0.35rem 0.65rem; font-size: 0.78rem; border-radius: 4px; border: none; cursor: pointer;">
                                                    Revogar
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="" style="display: inline-block;" onsubmit="return confirm('Deseja excluir permanentemente o registro desta chave revogada?');">
                                                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                <input type="hidden" name="delete_password" value="1">
                                                <input type="hidden" name="password_id" value="<?= (int)$p['id'] ?>">
                                                <button type="submit" class="btn" style="background: var(--border); color: var(--text-muted); padding: 0.35rem 0.65rem; font-size: 0.78rem; border-radius: 4px; border: none; cursor: pointer;">
                                                    Excluir
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Card 3: Auditoria Básica de Ingestão -->
        <div class="card">
            <h2 style="font-size: 1.15rem; font-weight: 700; margin: 0 0 0.5rem 0; color: var(--text); display: flex; align-items: center; gap: 0.5rem;">
                <span>📋</span> Auditoria de Rascunhos Ingeridos via API (Últimos 15)
            </h2>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 1rem 0;">
                Histórico de matérias recebidas via n8n prontas para revisão editorial.
            </p>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Data Ingestão</th>
                            <th>Título do Rascunho</th>
                            <th>ID Externo (Reddit/RSS)</th>
                            <th>Autor</th>
                            <th>Estado</th>
                            <th style="text-align: right;">Ação Editorial</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentLogs)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                    Nenhuma matéria ingerida via API até o momento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td style="font-size: 0.82rem; color: var(--text-muted); white-space: nowrap;">
                                        <?= Security::e(date('d/m/Y H:i', strtotime($log['ingested_at']))) ?>
                                    </td>
                                    <td style="font-weight: 600; color: var(--text); max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?= Security::e($log['title']) ?>
                                    </td>
                                    <td style="font-family: monospace; font-size: 0.8rem; color: var(--text-muted);">
                                        <?= Security::e($log['external_key']) ?>
                                    </td>
                                    <td style="font-size: 0.85rem;">
                                        <?= Security::e($log['author_name']) ?>
                                    </td>
                                    <td>
                                        <span class="badge-status badge-active" style="text-transform: capitalize;">
                                            <?= Security::e($log['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <a href="/admin/editor.php?id=<?= (int)$log['post_id'] ?>" class="btn btn-secondary" style="font-size: 0.78rem; padding: 0.35rem 0.65rem; border-radius: 4px; text-decoration: none;" target="_blank">
                                            Revisar no Editor ↗
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function copySecretToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Senha de Aplicação copiada para a área de transferência!');
        }, function() {
            prompt('Copie a senha manualmente:', text);
        });
    } else {
        prompt('Copie a senha manualmente:', text);
    }
}
</script>

<?php require dirname(__DIR__, 2) . '/public/admin/footer.php'; ?>
