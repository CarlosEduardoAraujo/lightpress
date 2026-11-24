<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Tela de Ativação de Conta e Definição de Senha
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

Security::startSecureSession($config);

$rawToken = Security::getSafeString($_GET, 'token');
if ($rawToken === '') {
    $rawToken = Security::getSafeString($_POST, 'token');
}
$token = $rawToken;
$error = '';
$success = false;

if (empty($token) || strlen($token) < 32) {
    die("Token de ativação inválido ou ausente.");
}

$tokenHash = hash('sha256', $token);
$nowUtc = gmdate('Y-m-d H:i:s');

// Buscar e validar token
$tokenRecord = Database::fetchOne(
    "SELECT t.*, u.name, u.username, u.email, u.is_active, u.password_reset_required 
     FROM user_reset_tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.token_hash = ? AND t.purpose = 'activation' AND t.used_at IS NULL AND t.expires_at > ?
     LIMIT 1",
    [$tokenHash, $nowUtc]
);

if (!$tokenRecord) {
    die("Este link de ativação expirou, já foi utilizado ou é inválido.");
}

if ((int)$tokenRecord['password_reset_required'] !== 1) {
    die("Esta conta já foi ativada ou não permite ativação por este token.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = Security::getSafeString($_POST, '_csrf_token');
    if (!Security::verifyCsrfToken($csrfToken)) {
        $error = "Token CSRF inválido ou expirado. Recarregue a página e tente novamente.";
    } else {
        $password = Security::getSafePassword($_POST, 'password');
        $passwordConfirm = Security::getSafePassword($_POST, 'password_confirm');

        if (strlen($password) < 8) {
            $error = "A senha deve ter pelo menos 8 caracteres.";
        } elseif ($password !== $passwordConfirm) {
            $error = "As senhas não coincidem.";
        } else {
            try {
                Database::transaction(function (\PDO $pdo) use ($tokenHash, $password, $nowUtc): void {
                    $lockedToken = Database::fetchOne(
                        "SELECT t.*, u.id as user_id, u.is_active, u.password_reset_required 
                         FROM user_reset_tokens t
                         JOIN users u ON u.id = t.user_id
                         WHERE t.token_hash = ? AND t.purpose = 'activation' AND t.used_at IS NULL AND t.expires_at > ?
                         LIMIT 1" . (Database::getDriver() === 'mysql' ? ' FOR UPDATE' : ''),
                        [$tokenHash, $nowUtc]
                    );

                    if (!$lockedToken) {
                        throw new RuntimeException("Este link de ativação expirou, já foi utilizado ou é inválido.");
                    }

                    if ((int)$lockedToken['password_reset_required'] !== 1) {
                        throw new RuntimeException("Esta conta já foi ativada ou não permite ativação por este token.");
                    }

                    $newHash = Security::hashPassword($password);
                    $userId = (int)$lockedToken['user_id'];

                    // 1. Atualizar senha do usuário, ativar conta e limpar flag de reset obrigatório
                    Database::query(
                        "UPDATE users 
                         SET password_hash = ?, is_active = 1, password_reset_required = 0, session_version = session_version + 1
                         WHERE id = ?",
                        [$newHash, $userId]
                    );

                    // 2. Marcar todos os tokens pendentes deste usuário como consumidos
                    Database::query(
                        "UPDATE user_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL",
                        [$nowUtc, $userId]
                    );
                });

                $success = true;
            } catch (\Throwable $e) {
                error_log('[LightPress Activation Error] ' . $e->getMessage());
                if ($e instanceof RuntimeException && !($e instanceof \PDOException)) {
                    $error = $e->getMessage();
                } else {
                    $error = "Erro interno ao processar a ativação da conta. Tente novamente mais tarde.";
                }
            }
        }
    }
}

$csrfToken = Security::generateCsrfToken();
    $siteTitle = \LightPress\Core\Settings::get('site_title', $config['app']['name'] ?? 'SpeedRacing');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ativar Conta - <?= Security::e($siteTitle) ?></title>
    <script>
        (function() {
            var saved = localStorage.getItem('lp_theme');
            if (saved === 'dark' || (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/admin/admin.css') ?: '1' ?>">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1.25rem; background-image: radial-gradient(circle at 15% 15%, var(--primary-light) 0%, transparent 45%); }
        .card { padding: 2.25rem 2rem; width: 100%; max-width: 420px; border-radius: var(--radius-lg); box-shadow: var(--shadow-lg); }
        .brand { font-size: 1.5rem; font-weight: 800; justify-content: center; margin-bottom: 0.35rem; }
        .subtitle { font-size: 0.9rem; color: var(--text-muted); text-align: center; margin-bottom: 1.85rem; }
        .form-group { margin-bottom: 1.15rem; }
        label { display: block; font-size: 0.84rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--text); }
        input[type="password"] { width: 100%; padding: 0.72rem 0.9rem; font-size: 0.95rem; }
        .btn-full { width: 100%; padding: 0.78rem 1rem; font-size: 0.96rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand"><span class="brand-dot" aria-hidden="true"></span><span><?= Security::e($siteTitle) ?></span></div>
        <div class="subtitle">Ativação de Conta e Criação de Senha</div>

        <?php if ($success): ?>
            <div class="alert-success" style="display:block; text-align:center;">
                <h3 style="font-size: 1.1rem; margin-bottom: 0.5rem;">Conta ativada com sucesso!</h3>
                <p style="font-size: 0.9rem; margin-bottom: 1rem;">Sua senha foi registrada com segurança.</p>
                <a href="/admin/login.php" class="btn btn-full">Acessar o Painel</a>
            </div>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= Security::e($error) ?></div>
            <?php endif; ?>

            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Olá, <strong style="color: var(--text);"><?= Security::e($tokenRecord['name']) ?></strong>! Defina sua senha de acesso abaixo:
            </p>

            <form method="POST">
                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input type="hidden" name="token" value="<?= Security::e($token) ?>">

                <div class="form-group">
                    <label for="password">Nova Senha (Mínimo 8 caracteres)</label>
                    <input type="password" id="password" name="password" class="form-input" required autofocus minlength="8">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirme a Nova Senha</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-input" required minlength="8">
                </div>

                <button type="submit" class="btn btn-full">Ativar Conta e Entrar</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
