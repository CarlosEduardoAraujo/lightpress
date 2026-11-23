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
use LightPress\Core\ThemeManager;

$currentUser = Security::requireAuth(null, null, $config);

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

// Carregar revisão privada específica ou pendente mais recente (AUD-ARCH-04)
$revisionId = isset($_GET['revision_id']) ? (int)$_GET['revision_id'] : 0;
if ($revisionId > 0) {
    $rev = Database::fetchOne(
        "SELECT * FROM post_revisions WHERE id = ? AND post_id = ? LIMIT 1",
        [$revisionId, $postId]
    );
    if ($rev) {
        $payload = json_decode((string)$rev['payload_json'], true);
        if (is_array($payload)) {
            $post = array_merge($post, $payload);
        }
        $post['content_html'] = $rev['content_html'];
        $post['revision_id'] = (int)$rev['id'];
        $post['status'] = (string)$rev['status'];
    }
} else {
    // Se não especificado revision_id, busca a revisão pendente mais recente da versão atual se existir
    $latestRev = Database::fetchOne(
        "SELECT * FROM post_revisions 
         WHERE post_id = ? AND status IN ('draft', 'pending_review') 
         ORDER BY id DESC LIMIT 1",
        [$postId]
    );
    if ($latestRev) {
        $payload = json_decode((string)$latestRev['payload_json'], true);
        if (is_array($payload)) {
            $post = array_merge($post, $payload);
        }
        $post['content_html'] = $latestRev['content_html'];
        $post['revision_id'] = (int)$latestRev['id'];
        $post['status'] = (string)$latestRev['status'];
    }
}

$pageTitle = '[PRÉVIA] ' . $post['title'];
$isPreview = true;

// Renderização do Post usando o template oficial sem duplicação de DOCTYPE (AUD-ARCH-04)
require ThemeManager::getTemplateFile('post.php');
