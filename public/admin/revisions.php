<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Histórico e Restauração de Revisões de Artigos
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\PostService;
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

if (!$currentUser || empty($currentUser['is_active'])) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

$postId = (int)($_GET['post_id'] ?? 0);
if ($postId <= 0) {
    die("Identificador de artigo inválido.");
}

$post = Database::fetchOne("SELECT * FROM posts WHERE id = ? LIMIT 1", [$postId]);
if (!$post) {
    die("Artigo não encontrado.");
}

if (!Authorization::can($currentUser, Authorization::ACTION_EDIT_POST, $post)) {
    die("Acesso não autorizado às revisões deste artigo.");
}

$message = '';
$error = '';

// Restaurar uma revisão histórica
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_revision_id'])) {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $revId = (int)$_POST['restore_revision_id'];
        $rev = Database::fetchOne(
            "SELECT * FROM post_revisions WHERE id = ? AND post_id = ? LIMIT 1",
            [$revId, $postId]
        );

        if (!$rev) {
            $error = "Revisão não encontrada.";
        } else {
            $payload = json_decode($rev['payload_json'], true) ?? [];
            $postService = new PostService();
            try {
                // Aplica a versão histórica preservando o histórico existente
                $postService->publishOrUpdate($postId, $payload, (int)$currentUser['id'], (int)$post['version']);
                $message = "Revisão #{$revId} restaurada e aplicada com sucesso!";
                $post = Database::fetchOne("SELECT * FROM posts WHERE id = ? LIMIT 1", [$postId]);
            } catch (\Throwable $e) {
                $error = "Erro ao restaurar: " . $e->getMessage();
            }
        }
    }
}

// Buscar todas as revisões do artigo ordenadas pela mais recente
$revisions = Database::fetchAll(
    "SELECT r.*, u.name as editor_name 
     FROM post_revisions r
     JOIN users u ON u.id = r.editor_id
     WHERE r.post_id = ?
     ORDER BY r.id DESC",
    [$postId]
);

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Revisões - <?= Security::e($post['title']) ?></title>
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
        .container { max-width: 1000px; margin: 2rem auto; padding: 0 1.5rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem; }
        th { background: #f8fafc; padding: 0.75rem; border-bottom: 1px solid var(--border); color: var(--text-muted); }
        td { padding: 0.85rem; border-bottom: 1px solid var(--border); }
        .btn { padding: 0.45rem 0.9rem; border-radius: var(--radius); font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-block; }
        .btn-restore { background: #d97706; color: white; }
        .btn-restore:hover { background: #b45309; }
        .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
        .badge-applied { background: #dcfce7; color: #15803d; }
        .badge-draft { background: #f1f5f9; color: #475569; }
        .badge-pending_review { background: #fef3c7; color: #b45309; }
        .alert-success { background: #dcfce7; color: #166534; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 0.85rem; border-radius: var(--radius); margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <header>
        <div style="display: flex; align-items: center; gap: 1.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span></a>
            <span style="font-size: 0.9rem; color: var(--text-muted);">&larr; <a href="/admin/editor.php?id=<?= (int)$post['id'] ?>" style="color: inherit; text-decoration: none;">Voltar ao Editor</a></span>
        </div>
    </header>

    <div class="container">
        <h1 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 0.5rem;">Histórico de Revisões</h1>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
            Artigo: <strong><?= Security::e($post['title']) ?></strong> (Versão atual: #<?= (int)$post['version'] ?>)
        </p>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Revisão</th>
                        <th>Editor Responsável</th>
                        <th>Versão Base</th>
                        <th>Status</th>
                        <th>Data do Snapshot</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($revisions)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">Nenhum histórico registrado para este artigo.</td></tr>
                    <?php else: ?>
                        <?php foreach ($revisions as $r): ?>
                            <tr>
                                <td><strong>#<?= (int)$r['id'] ?></strong></td>
                                <td><?= Security::e($r['editor_name']) ?></td>
                                <td>Versão #<?= (int)$r['base_version'] ?></td>
                                <td>
                                    <span class="badge badge-<?= Security::e($r['status']) ?>">
                                        <?= Security::e($r['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/Y H:i:s', strtotime($r['created_at'])) ?></td>
                                <td style="text-align: right;">
                                    <?php if ($r['status'] !== 'applied'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Deseja realmente restaurar este snapshot? A alteração criará uma nova revisão aplicada.');">
                                            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                            <input type="hidden" name="restore_revision_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn btn-restore">Restaurar Versão</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 0.8rem; color: #16a34a; font-weight: 600;">Aplicada</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
