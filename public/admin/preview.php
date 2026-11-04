<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Prévia Privada Autenticada
 * 
 * Regra estrita da Seção 6.4:
 * Acesso exclusivo a colaboradores autorizados com Cache-Control: private, no-store.
 * Nunca usa uma URL pública aberta para autorização de prévias.
 */

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

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

if (!$currentUser || empty($currentUser['is_active'])) {
    die("Acesso não autorizado.");
}

$postId = (int)($_GET['id'] ?? 0);
if ($postId <= 0) {
    die("Identificador de artigo inválido.");
}

$post = Database::fetchOne(
    "SELECT p.*, u.name as author_name, u.avatar as author_avatar
     FROM posts p
     JOIN users u ON u.id = p.user_id
     WHERE p.id = ? LIMIT 1",
    [$postId]
);

if (!$post) {
    die("Artigo não encontrado.");
}

// Verificar autorização do usuário sobre o post
if (!Authorization::can($currentUser, Authorization::ACTION_EDIT_POST, $post)) {
    die("Você não possui permissão para visualizar a prévia deste artigo.");
}

$pageTitle = '[PRÉVIA] ' . $post['title'];
$isPreview = true;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Security::e($pageTitle) ?></title>
    <style>
        .preview-banner {
            background: #f59e0b;
            color: #78350f;
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 9999;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: system-ui, sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .preview-btn {
            background: #78350f;
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            margin-left: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="preview-banner">
        <div>
            ⚠️ MODO DE PRÉVIA PRIVADA (Status atual: <strong><?= strtoupper(Security::e($post['status'])) ?></strong>)
            &bull; Este conteúdo não é público nem cacheável.
        </div>
        <div>
            <a href="/admin/editor.php?id=<?= (int)$post['id'] ?>" class="preview-btn">&larr; Voltar ao Editor</a>
        </div>
    </div>

    <!-- Renderização do Post usando o template oficial -->
    <?php require $config['paths']['templates'] . '/post.php'; ?>
</body>
</html>
