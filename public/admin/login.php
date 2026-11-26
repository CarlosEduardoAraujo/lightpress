<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Tela de Login Seguro
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

Security::startSecureSession($config);

// Se já estiver autenticado, redireciona para o dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';
if (isset($_GET['expired'])) {
    $error = 'Sua sessão expirou por inatividade ou tempo limite. Faça login novamente.';
} elseif (isset($_GET['revoked'])) {
    $error = 'Sessão revogada ou conta inativa. Faça login novamente.';
}
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
    $token = Security::getSafeString($_POST, '_csrf_token');
    if (!Security::verifyCsrfToken($token)) {
        $error = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } else {
        $username = Security::getSafeString($_POST, 'username');
        $password = Security::getSafePassword($_POST, 'password');

        $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0);

        if (empty($username) || empty($password)) {
            $error = 'Informe o usuário e a senha.';
        } else {
            $acctKey = 'acct_' . hash('sha256', mb_strtolower(trim($username), 'UTF-8'));
            $acctMaxAttempts = max(10, $maxAttempts * 2);
            $acctLockRemaining = Security::checkLoginRateLimit($acctKey, $acctMaxAttempts, $lockoutSeconds, 'login_acct');

            if ($acctLockRemaining !== null) {
                $min = (int)ceil($acctLockRemaining / 60);
                $error = "Muitas tentativas incorretas para esta conta. Tente novamente em {$min} minuto(s).";
            } else {
                $user = Database::fetchOne(
                    "SELECT id, name, username, password_hash, role, is_active, password_reset_required, session_version
                     FROM users WHERE username = ? OR email = ? LIMIT 1",
                    [$username, $username]
                );

                // Mitigação de timing attack: se usuário não existe OU conta inativa, valida contra DUMMY_ARGON2ID_HASH
                $isValidAccount = ($user && !empty($user['is_active']));
                $hashToVerify = $isValidAccount ? (string)$user['password_hash'] : Security::DUMMY_ARGON2ID_HASH;
                $passwordMatches = Security::verifyPassword($password, $hashToVerify);

                if ($isValidAccount && $passwordMatches) {
                    // Sucesso: limpar bloqueio por IP, conta e sessão
                    Security::clearLoginFailures($clientIp);
                    Security::clearLoginFailures($acctKey, 'login_acct');
                    unset($_SESSION['login_failures']);

                    Security::regenerateSession();
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['session_version'] = (int)$user['session_version'];
                    $_SESSION['auth_created_at'] = time();
                    $_SESSION['last_activity_at'] = time();

                    if ((int)($user['password_reset_required'] ?? 0) === 1) {
                        header('Location: /admin/profile.php?force_reset=1');
                        exit;
                    }

                    header('Location: /admin/dashboard.php');
                    exit;
                } else {
                    // Falha: registrar no IP, na conta e na sessão
                    Security::recordLoginFailure($clientIp, $maxAttempts, $lockoutSeconds);
                    Security::recordLoginFailure($acctKey, $acctMaxAttempts, $lockoutSeconds, 'login_acct');
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
}

$csrfToken = Security::generateCsrfToken();
$siteTitle = \LightPress\Core\Settings::get('site_title', $config['app']['name'] ?? 'SpeedRacing');
$adminCssVer = @filemtime(dirname(__DIR__) . '/assets/admin/admin.css') ?: '1';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= Security::e($siteTitle) ?></title>
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
    <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= $adminCssVer ?>">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.25rem;
            background-image:
                radial-gradient(circle at 15% 15%, var(--primary-light) 0%, transparent 45%),
                radial-gradient(circle at 85% 85%, rgba(15, 23, 42, 0.05) 0%, transparent 45%);
        }
        .login-wrap { width: 100%; max-width: 410px; animation: lpFadeIn 0.22s ease-out; }
        .login-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 2.25rem 2rem;
            box-shadow: var(--shadow-lg);
        }
        .login-brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.025em;
            margin-bottom: 0.35rem;
        }
        .subtitle { font-size: 0.9rem; color: var(--text-muted); text-align: center; margin-bottom: 1.85rem; }
        .form-group { margin-bottom: 1.15rem; }
        .form-label { display: block; font-size: 0.84rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--text); }
        .input-wrap { position: relative; }
        .login-input { width: 100%; padding: 0.72rem 0.9rem; font-size: 0.95rem; }
        .login-input.has-toggle { padding-right: 2.75rem; }
        .pwd-toggle {
            position: absolute;
            right: 0.55rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.3rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
        }
        .pwd-toggle:hover { color: var(--text); background: var(--surface-hover); }
        .btn-submit { width: 100%; padding: 0.78rem 1rem; font-size: 0.96rem; margin-top: 0.4rem; }
        .login-theme-btn { position: fixed; top: 1rem; right: 1rem; }
    </style>
</head>
<body>
    <button type="button" class="theme-btn login-theme-btn" onclick="toggleLoginTheme()" title="Alternar Tema" aria-label="Alternar Tema">
        <span id="loginIconSun" style="display:none;"><?= \LightPress\Core\Icon::svg('sun', '', 17) ?></span>
        <span id="loginIconMoon"><?= \LightPress\Core\Icon::svg('moon', '', 17) ?></span>
    </button>

    <div class="login-wrap">
        <div class="login-card">
            <div class="login-brand">
                <span class="brand-dot" aria-hidden="true"></span>
                <span><?= Security::e($siteTitle) ?></span>
            </div>
            <div class="subtitle">Acesso ao Painel Editorial</div>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= Security::e($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="/admin/login.php">
                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">

                <div class="form-group">
                    <label class="form-label" for="username">Usuário ou E-mail</label>
                    <input type="text" id="username" name="username" class="form-input login-input" required autofocus autocomplete="username" placeholder="seu.usuario">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Senha</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password" class="form-input login-input has-toggle" required autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="pwd-toggle" onclick="togglePwd()" aria-label="Mostrar ou ocultar senha" title="Mostrar/ocultar senha">
                            <span id="eyeOpen"><?= \LightPress\Core\Icon::svg('eye', '', 17) ?></span>
                            <span id="eyeOff" style="display:none;"><?= \LightPress\Core\Icon::svg('eye_off', '', 17) ?></span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-submit">Entrar no Sistema</button>
            </form>
        </div>
    </div>

    <script>
        function syncLoginThemeIcon() {
            var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.getElementById('loginIconSun').style.display = isDark ? 'inline-flex' : 'none';
            document.getElementById('loginIconMoon').style.display = isDark ? 'none' : 'inline-flex';
        }
        function toggleLoginTheme() {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('lp_theme', next);
            syncLoginThemeIcon();
        }
        function togglePwd() {
            var input = document.getElementById('password');
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            document.getElementById('eyeOpen').style.display = show ? 'none' : 'inline-flex';
            document.getElementById('eyeOff').style.display = show ? 'inline-flex' : 'none';
        }
        syncLoginThemeIcon();
    </script>
</body>
</html>
