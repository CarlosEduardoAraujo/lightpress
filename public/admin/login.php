<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Tela de Login Seguro
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

Security::startSecureSession($config);

// Se já estiver autenticado, redireciona para o dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';
$trustedProxies = $config['security']['trusted_proxies'] ?? ['127.0.0.1', '::1'];
$clientIp = Security::getClientIp($trustedProxies);
$maxAttempts = (int)($config['security']['rate_limit']['login_max_attempts'] ?? 5);
$lockoutSeconds = (int)($config['security']['rate_limit']['login_lockout_seconds'] ?? 900);

// 1. Verificação de Bloqueio por IP (independente de cookies/sessão)
$lockRemaining = Security::checkLoginRateLimit($clientIp, $maxAttempts, $lockoutSeconds);
if ($lockRemaining !== null) {
    $min = (int)ceil($lockRemaining / 60);
    $error = "Muitas tentativas incorretas a partir deste IP. Bloqueio ativo por mais {$min} minuto(s).";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!Security::verifyCsrfToken($token)) {
        $error = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0);

        if (empty($username) || empty($password)) {
            $error = 'Informe o usuário e a senha.';
        } else {
            $user = Database::fetchOne(
                "SELECT id, name, username, password_hash, role, is_active, password_reset_required, session_version
                 FROM users WHERE username = ? OR email = ? LIMIT 1",
                [$username, $username]
            );

            if ($user && !empty($user['is_active']) && Security::verifyPassword($password, $user['password_hash'])) {
                // Sucesso: limpar bloqueio por IP e sessão
                Security::clearLoginFailures($clientIp);
                unset($_SESSION['login_failures']);

                Security::regenerateSession();
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['session_version'] = (int)$user['session_version'];

                header('Location: /admin/dashboard.php');
                exit;
            } else {
                // Falha: registrar no IP e na sessão
                Security::recordLoginFailure($clientIp, $maxAttempts, $lockoutSeconds);
                $_SESSION['login_failures']++;

                $lockRemaining = Security::checkLoginRateLimit($clientIp, $maxAttempts, $lockoutSeconds);
                if ($lockRemaining !== null) {
                    $min = (int)ceil($lockRemaining / 60);
                    $error = "Muitas tentativas incorretas. Acesso bloqueado para este IP por {$min} minuto(s).";
                } else {
                    $error = 'Usuário ou senha incorretos.';
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
    <title>Login - <?= Security::e($siteTitle) ?></title>
    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #E10600;
            --primary-hover: #b80500;
            --bg: #f1f5f9;
            --surface: #ffffff;
            --border: #cbd5e1;
            --text: #0f172a;
            --radius: 8px;
            --font: system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
        .login-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 2.5rem; width: 100%; max-width: 400px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); }
        .brand { font-size: 1.6rem; font-weight: 800; text-align: center; margin-bottom: 0.5rem; color: var(--primary); }
        .brand span { color: var(--primary-accent); }
        .subtitle { font-size: 0.9rem; color: #64748b; text-align: center; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #334155; }
        input[type="text"], input[type="password"] { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 1rem; font-family: inherit; }
        input:focus { outline: none; border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(225,6,0,0.15); }
        .btn-submit { width: 100%; padding: 0.8rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.15s; margin-top: 0.5rem; }
        .btn-submit:hover { background: var(--primary-hover); }
        .alert-error { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 0.75rem; border-radius: var(--radius); font-size: 0.85rem; margin-bottom: 1.25rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand"><?= Security::e($siteTitle) ?></div>
        <div class="subtitle">Acesso ao Painel Editorial</div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= Security::e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/login.php">
            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
            
            <div class="form-group">
                <label for="username">Usuário ou E-mail</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Senha</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-submit">Entrar no Sistema</button>
        </form>
    </div>
</body>
</html>
