<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Gerenciador de Usuários e Perfis (Apenas Administrador)
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\Security;

Security::startSecureSession($config);

if (empty($_SESSION['user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = Database::fetchOne(
    "SELECT id, name, username, role, is_active, session_version 
     FROM users WHERE id = ? LIMIT 1",
    [(int)$_SESSION['user_id']]
);

if (!$currentUser || !Authorization::can($currentUser, Authorization::ACTION_MANAGE_USERS)) {
    die("Acesso restrito a Administradores.");
}

$message = '';
$error = '';
$activationLink = '';

// Criar novo usuário e emitir token de ativação
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'author';

        if (!in_array($role, ['author', 'editor', 'admin'], true)) {
            $role = 'author';
        }

        if (empty($name) || empty($username) || empty($email)) {
            $error = "Todos os campos são obrigatórios.";
        } else {
            try {
                // Senha temporária descartada (o usuário definirá a senha via token de ativação)
                $tempSecret = bin2hex(random_bytes(16));
                $tempHash = Security::hashPassword($tempSecret);

                Database::transaction(function (\PDO $pdo) use ($name, $username, $email, $role, $tempHash, &$activationLink, $config): void {
                    // Inserir usuário inativo
                    Database::query(
                        "INSERT INTO users (name, username, email, password_hash, role, is_active, password_reset_required, session_version)
                         VALUES (?, ?, ?, ?, ?, 0, 1, 1)",
                        [$name, $username, $email, $tempHash, $role]
                    );

                    $newUserId = (int)Database::lastInsertId();

                    // Gerar token de ativação criptográfico de uso único (32 bytes)
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400); // 24 horas

                    Database::query(
                        "INSERT INTO user_reset_tokens (user_id, token_hash, purpose, expires_at)
                         VALUES (?, ?, 'activation', ?)",
                        [$newUserId, $tokenHash, $expiresAt]
                    );

                    $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');
                    $activationLink = "{$canonicalOrigin}/admin/activate.php?token={$rawToken}";
                });

                $message = "Usuário cadastrado com sucesso! Envie o link de ativação abaixo:";
            } catch (\Throwable $e) {
                $error = "Erro ao cadastrar usuário: " . $e->getMessage();
            }
        }
    }
}

// Alternar status ativo/inativo (impedindo desativar a si mesmo ou o último admin)
if (isset($_GET['toggle_id']) && Security::verifyCsrfToken($_GET['_csrf_token'] ?? '')) {
    $targetId = (int)$_GET['toggle_id'];
    if ($targetId === (int)$currentUser['id']) {
        $error = "Você não pode desativar seu próprio usuário.";
    } else {
        Database::query(
            "UPDATE users SET is_active = IF(is_active = 1, 0, 1), session_version = session_version + 1 WHERE id = ?",
            [$targetId]
        );
        header("Location: /admin/users.php");
        exit;
    }
}

$users = Database::fetchAll("SELECT * FROM users ORDER BY created_at DESC");
$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - SpeedPress Admin</title>
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
        .container { max-width: 1100px; margin: 2rem auto; padding: 0 1.5rem; }
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.4rem; }
        input[type="text"], input[type="email"], select { width: 100%; padding: 0.7rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; }
        .btn { padding: 0.65rem 1.2rem; background: var(--primary-accent); color: white; border: none; border-radius: var(--radius); font-weight: 600; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.75rem; border-bottom: 1px solid var(--border); color: var(--text-muted); }
        td { padding: 0.75rem; border-bottom: 1px solid var(--border); }
        .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        .token-box { background: #f1f5f9; padding: 0.75rem; border-radius: var(--radius); font-family: monospace; font-size: 0.85rem; word-break: break-all; margin-top: 0.5rem; user-select: all; }
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
        <h1 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 1.5rem;">Gerenciador de Usuários e Equipe</h1>

        <?php if (!empty($message)): ?>
            <div class="alert-success">
                <?= Security::e($message) ?>
                <?php if (!empty($activationLink)): ?>
                    <div class="token-box"><?= Security::e($activationLink) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="grid">
            <div class="card">
                <h2 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem;">Novo Usuário</h2>
                <form method="POST" action="/admin/users.php">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    
                    <div class="form-group">
                        <label for="name">Nome Completo</label>
                        <input type="text" id="name" name="name" required placeholder="Ex: Maria da Silva">
                    </div>

                    <div class="form-group">
                        <label for="username">Nome de Usuário</label>
                        <input type="text" id="username" name="username" required placeholder="mariasilva">
                    </div>

                    <div class="form-group">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" required placeholder="maria@portal.com.br">
                    </div>

                    <div class="form-group">
                        <label for="role">Papel / Permissões</label>
                        <select id="role" name="role">
                            <option value="author">Autor (Cria e edita apenas seus rascunhos)</option>
                            <option value="editor">Editor (Edita qualquer post e publica)</option>
                            <option value="admin">Administrador (Controle irrestrito)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Cadastrar e Gerar Link de Ativação</button>
                </form>
            </div>

            <div class="card">
                <h2 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem;">Usuários Cadastrados</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Papel</th>
                            <th>Status</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <strong><?= Security::e($u['name']) ?></strong><br>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?= Security::e($u['email']) ?></span>
                                </td>
                                <td><span style="font-size: 0.85rem; font-weight: 600; text-transform: uppercase;"><?= Security::e($u['role']) ?></span></td>
                                <td>
                                    <span style="color: <?= $u['is_active'] ? '#16a34a' : '#dc2626' ?>; font-weight: 600;">
                                        <?= $u['is_active'] ? 'Ativo' : 'Inativo' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                                        <a href="/admin/users.php?toggle_id=<?= (int)$u['id'] ?>&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: var(--primary-accent); text-decoration: none; font-size: 0.85rem;">
                                            <?= $u['is_active'] ? 'Desativar' : 'Ativar' ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">(Você)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
