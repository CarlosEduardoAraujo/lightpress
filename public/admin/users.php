<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Gerenciador de Usuários e Autores
 * Gestão de repórteres, colunistas, biografias, avatares, senhas e perfis
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\BlockCompiler;
use LightPress\Core\Database;
use LightPress\Core\Security;

$currentUser = Security::requireAuth(Authorization::ACTION_MANAGE_USERS);

$message = '';
$error = '';
$activationLink = '';

$editUser = null;
$editId = (int)($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    $editUser = Database::fetchOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$editId]);
}

// 1. Processar Criação ou Edição
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $action = $_POST['action'] ?? 'create';
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'author';
        $bio = trim($_POST['bio'] ?? '');
        $avatar = !empty($_POST['avatar']) ? BlockCompiler::sanitizeImageUrl(trim($_POST['avatar'])) : null;
        $newPassword = trim($_POST['password'] ?? '');

        if (!in_array($role, ['author', 'editor', 'admin'], true)) {
            $role = 'author';
        }

        if (empty($name) || empty($username) || empty($email)) {
            $error = "Nome, login e e-mail são obrigatórios.";
        } else {
            if ($action === 'edit') {
                $targetId = (int)($_POST['user_id'] ?? 0);
                $existing = Database::fetchOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$targetId]);

                if (!$existing) {
                    $error = "Usuário não encontrado.";
                } else {
                    $editUser = $existing;
                    // Validar se username ou email já existem em outro usuário
                    $duplicate = Database::fetchOne(
                        "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1",
                        [$username, $email, $targetId]
                    );

                    if ($duplicate) {
                        $error = "Nome de usuário ou e-mail já está em uso por outra conta.";
                    } else {
                        try {
                            if (!empty($newPassword) && strlen($newPassword) < 8) {
                                throw new \InvalidArgumentException("A nova senha deve ter no mínimo 8 caracteres.");
                            }
                            $hash = !empty($newPassword) ? Security::hashPassword($newPassword) : null;

                            Database::transaction(function () use ($name, $username, $email, $role, $bio, $avatar, $hash, $targetId): void {
                                $forUpdate = Database::getDriver() === 'mysql' ? ' FOR UPDATE' : '';
                                $targetRow = Database::fetchOne(
                                    "SELECT role, is_active FROM users WHERE id = ?" . $forUpdate,
                                    [$targetId]
                                );

                                if (!$targetRow) {
                                    throw new \RuntimeException("Usuário não encontrado.");
                                }

                                if ($targetRow['role'] === 'admin' && (int)$targetRow['is_active'] === 1 && $role !== 'admin') {
                                    $otherAdmins = (int)Database::fetchValue(
                                        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?" . $forUpdate,
                                        [$targetId]
                                    );
                                    if ($otherAdmins === 0) {
                                        throw new \RuntimeException("Não é permitido rebaixar o único administrador ativo do sistema.");
                                    }
                                }

                                if ($hash !== null) {
                                    Database::query(
                                        "UPDATE users 
                                         SET name = ?, username = ?, email = ?, role = ?, bio = ?, avatar = ?, password_hash = ?, session_version = session_version + 1 
                                         WHERE id = ?",
                                        [$name, $username, $email, $role, $bio, $avatar, $hash, $targetId]
                                    );
                                    Database::query(
                                        "UPDATE user_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL",
                                        [$targetId]
                                    );
                                } else {
                                    Database::query(
                                        "UPDATE users 
                                         SET name = ?, username = ?, email = ?, role = ?, bio = ?, avatar = ?, session_version = session_version + 1 
                                         WHERE id = ?",
                                        [$name, $username, $email, $role, $bio, $avatar, $targetId]
                                    );
                                }
                            });

                            $message = "Perfil de '{$name}' atualizado com sucesso!";
                            $editUser = Database::fetchOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$targetId]);
                        } catch (\Throwable $e) {
                            $error = $e->getMessage();
                        }
                    }
                }
            } else {
                // Criação de novo usuário
                try {
                    $duplicate = Database::fetchOne(
                        "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1",
                        [$username, $email]
                    );

                    if ($duplicate) {
                        throw new \InvalidArgumentException("Nome de usuário ou e-mail já cadastrado.");
                    }

                    if (!empty($newPassword)) {
                        if (strlen($newPassword) < 8) {
                            throw new \InvalidArgumentException("A senha deve ter no mínimo 8 caracteres.");
                        }
                        $passwordHash = Security::hashPassword($newPassword);
                        $isActive = 1;
                        $resetRequired = 0;
                    } else {
                        // Senha temporária e emissão de link de ativação
                        $tempSecret = bin2hex(random_bytes(16));
                        $passwordHash = Security::hashPassword($tempSecret);
                        $isActive = 0;
                        $resetRequired = 1;
                    }

                    Database::transaction(function (\PDO $pdo) use ($name, $username, $email, $passwordHash, $role, $bio, $avatar, $isActive, $resetRequired, $newPassword, &$activationLink, $config): void {
                        Database::query(
                            "INSERT INTO users (name, username, email, password_hash, role, bio, avatar, is_active, password_reset_required, session_version)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                            [$name, $username, $email, $passwordHash, $role, $bio, $avatar, $isActive, $resetRequired]
                        );

                        $newUserId = (int)Database::lastInsertId();

                        if (empty($newPassword)) {
                            $rawToken = bin2hex(random_bytes(32));
                            $tokenHash = hash('sha256', $rawToken);
                            $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400);

                            Database::query(
                                "INSERT INTO user_reset_tokens (user_id, token_hash, purpose, expires_at)
                                 VALUES (?, ?, 'activation', ?)",
                                [$newUserId, $tokenHash, $expiresAt]
                            );

                            $canonicalOrigin = rtrim($config['app']['canonical_origin'] ?? 'http://localhost:8080', '/');
                            $activationLink = "{$canonicalOrigin}/admin/activate.php?token={$rawToken}";
                        }
                    });

                    if (!empty($activationLink)) {
                        $message = "Usuário cadastrado com sucesso! Compartilhe o link de ativação abaixo:";
                    } else {
                        $message = "Usuário cadastrado e ativado com a senha definida!";
                    }
                } catch (\Throwable $e) {
                    $error = "Erro ao cadastrar usuário: " . $e->getMessage();
                }
            }
        }
    }
}

// 2. Alternar Status Ativo / Bloqueado (Estritamente via POST com CSRF)
$toggleUserId = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int)($_POST['toggle_id'] ?? 0) : 0;
$csrfUsr = $_POST['_csrf_token'] ?? '';
if ($toggleUserId > 0 && Security::verifyCsrfToken($csrfUsr)) {
    if ($toggleUserId === (int)$currentUser['id']) {
        $error = "Você não pode desativar seu próprio usuário.";
    } else {
        try {
            Database::transaction(function () use ($toggleUserId): void {
                $forUpdate = Database::getDriver() === 'mysql' ? ' FOR UPDATE' : '';
                $targetToggle = Database::fetchOne(
                    "SELECT id, role, is_active FROM users WHERE id = ?" . $forUpdate,
                    [$toggleUserId]
                );

                if (!$targetToggle) {
                    throw new \RuntimeException("Usuário não encontrado.");
                }

                if ($targetToggle['role'] === 'admin' && (int)$targetToggle['is_active'] === 1) {
                    $otherAdmins = (int)Database::fetchValue(
                        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id != ?" . $forUpdate,
                        [$toggleUserId]
                    );
                    if ($otherAdmins === 0) {
                        throw new \RuntimeException("Não é permitido desativar o único administrador ativo do sistema.");
                    }
                }

                Database::query(
                    "UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, session_version = session_version + 1 WHERE id = ?",
                    [$toggleUserId]
                );
                Database::query(
                    "UPDATE user_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL",
                    [$toggleUserId]
                );
            });

            header("Location: /admin/users.php");
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// Listar todos os usuários com contagem de matérias
$users = Database::fetchAll(
    "SELECT u.*, COUNT(p.id) as total_posts 
     FROM users u
     LEFT JOIN posts p ON p.user_id = u.id AND p.status = 'published'
     GROUP BY u.id
     ORDER BY u.name ASC"
);

$csrfToken = Security::generateCsrfToken();
$pageTitle = 'Gerenciador de Usuários e Autores';
$activePage = 'users';
require __DIR__ . '/header.php';
?>
<style>
    [data-theme="dark"] th { background: #0f172a; }
    [data-theme="dark"] tr:hover td { background: #162036; }
    [data-theme="dark"] input, [data-theme="dark"] select, [data-theme="dark"] textarea { background: #0b1120; color: #f1f5f9; border-color: #1e293b; }

    .grid { display: grid; grid-template-columns: 1.1fr 2fr; gap: 2rem; align-items: flex-start; }
    @media (max-width: 960px) {
        .grid { grid-template-columns: 1fr; }
    }
    
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
    .form-group { margin-bottom: 1.25rem; }
    label { display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--primary); }
    input, select, textarea { width: 100%; padding: 0.65rem 0.85rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: 0.95rem; background: var(--bg); color: var(--text); outline: none; }
    input:focus, select:focus, textarea:focus { border-color: var(--primary-accent); }
    
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
    th { background: #f8fafc; padding: 0.8rem 1rem; border-bottom: 1px solid var(--border); color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; }
    td { padding: 0.9rem 1rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #fafafa; }

    .user-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; background: #e2e8f0; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem; color: var(--primary-accent); }

    .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem; }
    .token-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem; }
    .token-input { font-family: monospace; font-size: 0.85rem; background: #ffffff; margin-top: 0.25rem; cursor: pointer; }
    [data-theme="dark"] .token-box { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .token-input { background: #090d16; color: #f1f5f9; }
</style>

    <div class="container">
        <h1 style="font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 1.75rem;">
            Equipe Editorial & Gestão de Autores
        </h1>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <?php if (!empty($activationLink)): ?>
            <div class="token-box">
                <strong style="color: #1e40af; display: block; margin-bottom: 0.4rem;">🔗 Link de Ativação de Conta (Válido por 24 horas):</strong>
                <input type="text" readonly value="<?= Security::e($activationLink) ?>" class="token-input" onclick="this.select()">
                <p style="font-size: 0.8rem; color: var(--primary-accent); margin-top: 0.4rem;">
                    Copie e envie este link para o repórter. Ele definirá a própria senha e ativará o acesso com segurança.
                </p>
            </div>
        <?php endif; ?>

        <div class="grid">
            <!-- Formulário de Usuário / Autor -->
            <div class="card">
                <h2 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 1.25rem; color: var(--primary);">
                    <?= $editUser ? 'Editar Perfil de Autor' : 'Novo Membro da Redação' ?>
                </h2>

                <form method="POST" action="/admin/users.php">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="<?= $editUser ? 'edit' : 'create' ?>">
                    <?php if ($editUser): ?>
                        <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="name">Nome Público de Autor</label>
                        <input type="text" id="name" name="name" required value="<?= Security::e($editUser['name'] ?? '') ?>" placeholder="Ex: Ayrton Senna">
                        <span style="font-size: 0.75rem; color: var(--text-muted);">Assinatura exibida no topo e no box de autor das matérias.</span>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="username">Login de Acesso</label>
                            <input type="text" id="username" name="username" required value="<?= Security::e($editUser['username'] ?? '') ?>" placeholder="ayrton">
                        </div>

                        <div class="form-group">
                            <label for="role">Perfil de Acesso</label>
                            <select id="role" name="role">
                                <option value="author" <?= ($editUser['role'] ?? '') === 'author' ? 'selected' : '' ?>>Autor (Escreve)</option>
                                <option value="editor" <?= ($editUser['role'] ?? '') === 'editor' ? 'selected' : '' ?>>Editor (Publica/Revisa)</option>
                                <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador Total</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email">E-mail Corporativo</label>
                        <input type="email" id="email" name="email" required value="<?= Security::e($editUser['email'] ?? '') ?>" placeholder="autor@speedpress.com">
                    </div>

                    <div class="form-group">
                        <label for="bio">Biografia do Autor</label>
                        <textarea id="bio" name="bio" rows="3" placeholder="Jornalista esportivo especializado em automobilismo internacional e coberturas de GPs..."><?= Security::e($editUser['bio'] ?? '') ?></textarea>
                        <span style="font-size: 0.75rem; color: var(--text-muted);">Apresentada automaticamente na caixa de autor de cada publicação.</span>
                    </div>

                    <div class="form-group">
                        <label for="avatar">URL do Avatar / Foto de Perfil</label>
                        <input type="text" id="avatar" name="avatar" value="<?= Security::e($editUser['avatar'] ?? '') ?>" placeholder="Ex: /uploads/2026/11/foto-autor.avif">
                    </div>

                    <div class="form-group">
                        <label for="password">
                            <?= $editUser ? 'Nova Senha (Opcional - preencha apenas para alterar)' : 'Senha Inicial (Opcional - deixe em branco para gerar link de ativação)' ?>
                        </label>
                        <input type="password" id="password" name="password" placeholder="Mínimo de 8 caracteres" autocomplete="new-password">
                    </div>

                    <div style="display: flex; gap: 0.5rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn" style="flex: 1;">
                            <?= $editUser ? 'Salvar Perfil' : 'Cadastrar Membro' ?>
                        </button>
                        <?php if ($editUser): ?>
                            <a href="/admin/users.php" class="btn btn-secondary">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Listagem de Usuários -->
            <div class="card">
                <h2 style="font-size: 1.2rem; font-weight: 800; margin-bottom: 1.25rem; color: var(--primary);">
                    Equipe da Redação
                </h2>
                <table>
                    <thead>
                        <tr>
                            <th>Autor</th>
                            <th>Perfil</th>
                            <th style="text-align: center;">Artigos</th>
                            <th>Status</th>
                            <th style="text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <?php if (!empty($u['avatar'])): ?>
                                            <img src="<?= Security::e($u['avatar']) ?>" alt="<?= Security::e($u['name']) ?>" class="user-avatar">
                                        <?php else: ?>
                                            <div class="user-avatar">
                                                <?= mb_strtoupper(mb_substr($u['name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong style="color: var(--primary); display: block;"><?= Security::e($u['name']) ?></strong>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">@<?= Security::e($u['username']) ?> &bull; <?= Security::e($u['email']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; background: var(--primary-light); color: var(--primary-accent); text-transform: uppercase;">
                                        <?= Security::e($u['role']) ?>
                                    </span>
                                </td>
                                <td style="text-align: center; font-weight: 700;">
                                    <?= (int)$u['total_posts'] ?>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; background: <?= $u['is_active'] ? '#dcfce7' : '#fee2e2' ?>; color: <?= $u['is_active'] ? '#166534' : '#991b1b' ?>;">
                                        <?= $u['is_active'] ? 'Ativo' : 'Bloqueado' ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="/admin/users.php?edit_id=<?= (int)$u['id'] ?>" style="color: var(--primary-accent); text-decoration: none; font-weight: 700; font-size: 0.85rem; margin-right: 0.5rem;">
                                        Editar
                                    </a>

                                    <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                                        <form method="POST" action="/admin/users.php" style="display:inline;" onsubmit="return confirm('Alternar status deste usuário? Se for bloqueado, suas sessões ativas serão canceladas imediatamente.');">
                                            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                            <input type="hidden" name="toggle_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" style="background:none; border:none; cursor:pointer; color: <?= $u['is_active'] ? '#dc2626' : '#16a34a' ?>; font-size: 0.85rem; font-weight: 600; padding:0;">
                                                <?= $u['is_active'] ? 'Bloquear' : 'Ativar' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/footer.php'; ?>
