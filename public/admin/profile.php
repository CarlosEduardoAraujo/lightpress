<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Meu Perfil e Alteração de Senha Segura
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

$currentUser = Security::requireAuth(null, null, $config);

$message = '';
$error = '';

// Atualizar Perfil ou Trocar Senha
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $action = $_POST['form_action'] ?? 'update_profile';

        if ($action === 'update_profile') {
            $name = trim(strip_tags((string)($_POST['name'] ?? '')));
            $bio = trim(strip_tags((string)($_POST['bio'] ?? '')));

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
$pageTitle = 'Meu Perfil';
$activePage = 'profile';
require __DIR__ . '/header.php';
?>
<style>
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
    @media (max-width: 768px) {
        .grid { grid-template-columns: 1fr; }
    }
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
    .card-title { font-size: 1.15rem; font-weight: 800; margin-bottom: 1.5rem; }
    .form-group { margin-bottom: 1.25rem; }
    label { display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--primary); }
    input { width: 100%; padding: 0.7rem 0.85rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; background: var(--bg); color: var(--text); outline: none; }
    input:focus { border-color: var(--primary-accent); }
    .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
    .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
</style>

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

    <?php require __DIR__ . '/footer.php'; ?>
