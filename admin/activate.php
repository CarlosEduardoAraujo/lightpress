<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Tela de Ativação de Conta e Definição de Senha
 */

$config = require dirname(__DIR__) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Security;

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = false;

if (empty($token) || strlen($token) < 32) {
    die("Token de ativação inválido ou ausente.");
}

$tokenHash = hash('sha256', $token);
$nowUtc = gmdate('Y-m-d H:i:s');

// Buscar e validar token
$tokenRecord = Database::fetchOne(
    "SELECT t.*, u.name, u.username, u.email 
     FROM user_reset_tokens t
     JOIN users u ON u.id = t.user_id
     WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > ?
     LIMIT 1",
    [$tokenHash, $nowUtc]
);

if (!$tokenRecord) {
    die("Este link de ativação expirou ou já foi utilizado.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = "A senha deve ter pelo menos 8 caracteres.";
    } elseif ($password !== $passwordConfirm) {
        $error = "As senhas não coincidem.";
    } else {
        try {
            Database::transaction(function (\PDO $pdo) use ($tokenRecord, $tokenHash, $password, $nowUtc): void {
                $newHash = Security::hashPassword($password);

                // 1. Atualizar senha do usuário, ativar conta e limpar flag de reset obrigatório
                Database::query(
                    "UPDATE users 
                     SET password_hash = ?, is_active = 1, password_reset_required = 0, session_version = session_version + 1
                     WHERE id = ?",
                    [$newHash, (int)$tokenRecord['user_id']]
                );

                // 2. Marcar token como consumido
                Database::query(
                    "UPDATE user_reset_tokens SET used_at = ? WHERE token_hash = ?",
                    [$nowUtc, $tokenHash]
                );
            });

            $success = true;
        } catch (\Throwable $e) {
            $error = "Erro ao ativar conta: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ativar Conta - SpeedPress Admin</title>
    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --bg: #f1f5f9;
            --surface: #ffffff;
            --border: #cbd5e1;
            --text: #0f172a;
            --radius: 8px;
            --font: system-ui, -apple-system, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 2.5rem; width: 100%; max-width: 420px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); }
        .brand { font-size: 1.6rem; font-weight: 800; text-align: center; margin-bottom: 0.5rem; }
        .brand span { color: var(--primary-accent); }
        .subtitle { font-size: 0.9rem; color: #64748b; text-align: center; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; color: #334155; }
        input[type="password"] { width: 100%; padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 1rem; }
        input:focus { outline: none; border-color: var(--primary-accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .btn { width: 100%; padding: 0.8rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-size: 1rem; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        .alert-error { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 0.75rem; border-radius: var(--radius); font-size: 0.85rem; margin-bottom: 1.25rem; }
        .alert-success { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 1rem; border-radius: var(--radius); text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Speed<span>Press</span></div>
        <div class="subtitle">Ativação de Conta e Criação de Senha</div>

        <?php if ($success): ?>
            <div class="alert-success">
                <h3 style="font-size: 1.1rem; margin-bottom: 0.5rem;">Conta ativada com sucesso!</h3>
                <p style="font-size: 0.9rem; margin-bottom: 1rem;">Sua senha foi registrada com segurança.</p>
                <a href="/admin/login.php" class="btn" style="display: block; text-decoration: none;">Acessar o Painel</a>
            </div>
        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= Security::e($error) ?></div>
            <?php endif; ?>

            <p style="font-size: 0.9rem; color: #475569; margin-bottom: 1.5rem;">
                Olá, <strong><?= Security::e($tokenRecord['name']) ?></strong>! Defina sua senha de acesso abaixo:
            </p>

            <form method="POST">
                <input type="hidden" name="token" value="<?= Security::e($token) ?>">

                <div class="form-group">
                    <label for="password">Nova Senha (Mínimo 8 caracteres)</label>
                    <input type="password" id="password" name="password" required autofocus minlength="8">
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirme a Nova Senha</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
                </div>

                <button type="submit" class="btn">Ativar Conta e Entrar</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
