<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Meu Perfil e Alteração de Senha Segura
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

Security::startSecureSession($config);

if (empty($_SESSION['user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = Database::fetchOne(
    "SELECT id, name, username, email, role, bio, is_active, session_version, password_hash 
     FROM users WHERE id = ? LIMIT 1",
    [(int)$_SESSION['user_id']]
);

if (!$currentUser || empty($currentUser['is_active'])) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

$message = '';
$error = '';

// Atualizar Perfil ou Trocar Senha
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $action = $_POST['form_action'] ?? 'update_profile';

        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');

            if (empty($name)) {
                $error = "O nome não pode ficar em branco.";
            } else {
                Database::query(
                    "UPDATE users SET name = ?, bio = ? WHERE id = ?",
                    [$name, $bio, (int)$currentUser['id']]
                );
                $_SESSION['user_name'] = $name;
                $message = "Perfil atualizado com sucesso!";
                $currentUser['name'] = $name;
                $currentUser['bio'] = $bio;
            }
        } elseif ($action === 'change_password') {
            $currentPass = (string)($_POST['current_password'] ?? '');
            $newPass = (string)($_POST['new_password'] ?? '');
            $confirmPass = (string)($_POST['confirm_password'] ?? '');

            if (!Security::verifyPassword($currentPass, $currentUser['password_hash'])) {
                $error = "A senha atual informada está incorreta.";
            } elseif (strlen($newPass) < 8) {
                $error = "A nova senha deve ter no mínimo 8 caracteres.";
            } elseif ($newPass !== $confirmPass) {
                $error = "A confirmação da nova senha não confere.";
            } else {
                $newHash = Security::hashPassword($newPass);
                $newVersion = (int)$currentUser['session_version'] + 1;

                Database::query(
                    "UPDATE users SET password_hash = ?, session_version = ? WHERE id = ?",
                    [$newHash, $newVersion, (int)$currentUser['id']]
                );

                $_SESSION['session_version'] = $newVersion;
                $message = "Senha alterada com sucesso! Suas outras sessões ativas foram desconectadas por segurança.";
                $currentUser['password_hash'] = $newHash;
                $currentUser['session_version'] = $newVersion;
            }
        }
    }
}

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - SpeedPress Admin</title>
    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --text-muted: #64748b;
            --radius: 8px;
            --font: system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); }
        header { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0.75rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 1.25rem; font-weight: 800; color: var(--primary); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .container { max-width: 900px; margin: 2rem auto; padding: 0 1.5rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; }
        .card-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #334155; }
        input[type="text"], input[type="email"], input[type="password"], textarea { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; font-family: inherit; }
        .btn { padding: 0.7rem 1.25rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <header>
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span></a>
            <span style="font-size: 0.9rem; color: var(--text-muted);">&larr; <a href="/admin/dashboard.php" style="color: inherit; text-decoration: none;">Voltar ao Dashboard</a></span>
        </div>
    </header>

    <div class="container">
        <h1 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 1.5rem;">Configurações de Perfil</h1>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="grid">
            <!-- Dados do Perfil -->
            <div class="card">
                <h2 class="card-title">Informações Pessoais</h2>
                <form method="POST">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="update_profile">

                    <div class="form-group">
                        <label for="username">Nome de Usuário (Imutável)</label>
                        <input type="text" id="username" value="<?= Security::e($currentUser['username']) ?>" disabled style="background: #f1f5f9;">
                    </div>

                    <div class="form-group">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" value="<?= Security::e($currentUser['email']) ?>" disabled style="background: #f1f5f9;">
                    </div>

                    <div class="form-group">
                        <label for="name">Nome de Exibição</label>
                        <input type="text" id="name" name="name" value="<?= Security::e($currentUser['name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="bio">Biografia / Assinatura</label>
                        <textarea id="bio" name="bio" rows="3"><?= Security::e($currentUser['bio'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Salvar Perfil</button>
                </form>
            </div>

            <!-- Troca de Senha Segura -->
            <div class="card">
                <h2 class="card-title">Segurança e Senha</h2>
                <form method="POST">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="form_action" value="change_password">

                    <div class="form-group">
                        <label for="current_password">Senha Atual</label>
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>

                    <div class="form-group">
                        <label for="new_password">Nova Senha (Mínimo 8 caracteres)</label>
                        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirme a Nova Senha</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Atualizar Senha (Argon2id)</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
