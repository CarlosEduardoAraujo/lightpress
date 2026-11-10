<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Histórico e Restauração de Revisões de Artigos
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\PostService;
use LightPress\Core\Security;

$currentUser = Security::requireAuth(null, null, $config);

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
$latestRevisionId = (int)(Database::fetchValue(
    "SELECT id FROM post_revisions WHERE post_id = ? AND base_version = ?
     AND status IN ('draft', 'pending_review') ORDER BY id DESC LIMIT 1",
    [$postId, (int)$post['version']]
) ?? 0);

// Restaurar uma revisão histórica (executado atomicamente em transação com bloqueio FOR UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_revision_id'])) {
    if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = "Token CSRF inválido ou expirado.";
    } else {
        $revId = (int)$_POST['restore_revision_id'];
        $knownVersion = (int)($_POST['version'] ?? 0);
        $knownRevisionId = (int)($_POST['known_revision_id'] ?? 0);

        try {
            // Migrações podem executar DDL: concluir antes de adquirir os locks editoriais.
            $postService = new PostService();
            Database::transaction(function (\PDO $pdo) use (
                $postId, $revId, $currentUser, $postService, $knownVersion, $knownRevisionId, &$message
            ): void {
                // 1. Bloqueia e relê a linha atualizada da publicação
                $forUpdate = Database::getDriver() === 'mysql' ? ' FOR UPDATE' : '';
                $current = Database::fetchOne(
                    "SELECT * FROM posts WHERE id = ?{$forUpdate}",
                    [$postId]
                );
                if (!$current) {
                    throw new RuntimeException("Artigo não encontrado.", 404);
                }
                if ((int)$current['version'] !== $knownVersion) {
                    throw new RuntimeException('A publicação mudou. Recarregue o histórico antes de restaurar.', 409);
                }
                $latest = Database::fetchOne(
                    "SELECT id FROM post_revisions WHERE post_id = ? AND base_version = ?
                     AND status IN ('draft', 'pending_review') ORDER BY id DESC LIMIT 1",
                    [$postId, $knownVersion]
                );
                if ((int)($latest['id'] ?? 0) !== $knownRevisionId) {
                    throw new RuntimeException('O rascunho mudou. Recarregue o histórico antes de restaurar.', 409);
                }

                // 2. Reavalia autorização com o estado real do banco
                if (!Authorization::can($currentUser, Authorization::ACTION_EDIT_POST, $current)) {
                    throw new RuntimeException("Acesso não autorizado às revisões deste artigo.", 403);
                }

                $rev = Database::fetchOne(
                    "SELECT * FROM post_revisions WHERE id = ? AND post_id = ? LIMIT 1",
                    [$revId, $postId]
                );
                if (!$rev) {
                    throw new RuntimeException("Revisão não encontrada.", 404);
                }

                $payload = json_decode($rev['payload_json'], true) ?? [];
                $canPublish = Authorization::can($currentUser, Authorization::ACTION_PUBLISH_POST);

                if (!$canPublish) {
                    // Autor restaurando revisão (LP-06 / P1):
                    // Se o artigo já estiver no ar ou agendado, salva SEMPRE como revisão privada pendente
                    if (in_array($current['status'], ['published', 'scheduled'], true)) {
                        $payload['status'] = 'pending_review';
                        Database::query(
                            "INSERT INTO post_revisions (
                                post_id, editor_id, base_version, status, payload_json, content_html, created_at
                             ) VALUES (?, ?, ?, 'pending_review', ?, ?, CURRENT_TIMESTAMP)",
                            [
                                $postId,
                                (int)$currentUser['id'],
                                (int)$current['version'],
                                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                $rev['content_html']
                            ]
                        );

                        $newRevId = (int)Database::getConnection()->lastInsertId();
                        ImageProcessor::syncRefs($postId, $newRevId > 0 ? $newRevId : null, $rev['content_html'], $payload['featured_image'] ?? null);

                        $message = "A revisão #{$revId} foi restaurada como uma NOVA REVISÃO PENDENTE de aprovação. O artigo público no ar permaneceu inalterado.";
                    } else {
                        // Artigo é rascunho: atualiza condicionalmente garantindo que o status ainda é 'draft' e versão não mudou
                        $payload['status'] = 'draft';
                        $payload['is_featured'] = 0;
                        $newVersion = (int)$current['version'] + 1;

                        $stmt = Database::query(
                            "UPDATE posts SET 
                                title = ?, slug = ?, summary = ?, category = ?, tags = ?, featured_image = ?, 
                                content_json = ?, content_html = ?, seo_title = ?, 
                                seo_description = ?, focus_keyword = ?, status = 'draft', is_featured = 0,
                                version = ?, updated_at = CURRENT_TIMESTAMP 
                             WHERE id = ? AND version = ? AND status = 'draft'",
                            [
                                $payload['title'] ?? $current['title'],
                                $payload['slug'] ?? $current['slug'],
                                $payload['summary'] ?? null,
                                $payload['category'] ?? $current['category'],
                                $payload['tags'] ?? null,
                                $payload['featured_image'] ?? null,
                                $payload['content_json'] ?? $current['content_json'],
                                $rev['content_html'],
                                $payload['seo_title'] ?? null,
                                $payload['seo_description'] ?? null,
                                $payload['focus_keyword'] ?? null,
                                $newVersion,
                                $postId,
                                (int)$current['version']
                            ]
                        );

                        if ($stmt->rowCount() === 0) {
                            // Houve publicação concorrente por outro usuário enquanto o autor restaurava!
                            // Protege a versão aprovada criando com segurança uma revisão privada pendente
                            $payload['status'] = 'pending_review';
                            Database::query(
                                "INSERT INTO post_revisions (
                                    post_id, editor_id, base_version, status, payload_json, content_html, created_at
                                 ) VALUES (?, ?, ?, 'pending_review', ?, ?, CURRENT_TIMESTAMP)",
                                [
                                    $postId,
                                    (int)$currentUser['id'],
                                    (int)$current['version'],
                                    json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                    $rev['content_html']
                                ]
                            );

                            $newRevId = (int)Database::getConnection()->lastInsertId();
                            ImageProcessor::syncRefs($postId, $newRevId > 0 ? $newRevId : null, $rev['content_html'], $payload['featured_image'] ?? null);

                            $message = "O artigo foi alterado ou publicado concorrentemente. A revisão foi restaurada com segurança como uma REVISÃO PENDENTE de aprovação para não sobrescrever a versão pública aprovada.";
                        } else {
                            Database::query(
                                "INSERT INTO post_revisions (
                                    post_id, editor_id, base_version, status, payload_json, content_html, created_at
                                 ) VALUES (?, ?, ?, 'draft', ?, ?, CURRENT_TIMESTAMP)",
                                [
                                    $postId,
                                    (int)$currentUser['id'],
                                    $newVersion,
                                    json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                    $rev['content_html']
                                ]
                            );

                            $newRevId = (int)Database::getConnection()->lastInsertId();
                            ImageProcessor::syncRefs($postId, $newRevId > 0 ? $newRevId : null, $rev['content_html'], $payload['featured_image'] ?? null);

                            $message = "Revisão #{$revId} restaurada no rascunho com sucesso!";
                        }
                    }
                } else {
                    // Editor ou Administrador: aplicando/aprovando a revisão na publicação (P2 / Item 4)
                    // Separa a intenção do snapshot do estado público real:
                    // Se o artigo já estiver no ar ou agendado, a aprovação preserva o estado público ('published' ou 'scheduled')
                    $nowUtc = gmdate('Y-m-d H:i:s');
                    if (in_array($current['status'], ['published', 'scheduled'], true)) {
                        $targetPubAt = !empty($payload['published_at']) ? gmdate('Y-m-d H:i:s', strtotime($payload['published_at'])) : $current['published_at'];
                        $payload['status'] = ($targetPubAt > $nowUtc) ? 'scheduled' : 'published';
                        $payload['published_at'] = $targetPubAt;
                    } elseif (!empty($payload['status']) && $payload['status'] === 'pending_review') {
                        $targetPubAt = !empty($payload['published_at']) ? gmdate('Y-m-d H:i:s', strtotime($payload['published_at'])) : $nowUtc;
                        $payload['status'] = ($targetPubAt > $nowUtc) ? 'scheduled' : 'published';
                        $payload['published_at'] = $targetPubAt;
                    }

                    if (!array_key_exists('content_html', $payload) || $payload['content_html'] === null) {
                        $payload['content_html'] = $rev['content_html'];
                    }

                    $postService->publishOrUpdate($postId, $payload, (int)$currentUser['id'], $knownVersion, $knownRevisionId, $revId);
                    $message = "Revisão #{$revId} aprovada e aplicada à versão pública com sucesso!";
                }
            });

            $post = Database::fetchOne("SELECT * FROM posts WHERE id = ? LIMIT 1", [$postId]);
            $latestRevisionId = (int)(Database::fetchValue(
                "SELECT id FROM post_revisions WHERE post_id = ? AND base_version = ?
                 AND status IN ('draft', 'pending_review') ORDER BY id DESC LIMIT 1",
                [$postId, (int)$post['version']]
            ) ?? 0);
        } catch (\Throwable $e) {
            if ($e->getCode() === 409) {
                http_response_code(409);
            }
            $error = "Erro ao restaurar: " . $e->getMessage();
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
$pageTitle = 'Histórico de Revisões';
$activePage = 'dashboard';
require __DIR__ . '/header.php';
?>
<style>
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; margin-bottom: 1.5rem; transition: background 0.2s, border-color 0.2s; }
    .revision-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem 0; border-bottom: 1px solid var(--border); }
    .revision-item:last-child { border-bottom: none; }
    .diff-box { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem; margin-top: 1rem; font-family: monospace; font-size: 0.88rem; overflow-x: auto; }
</style>

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
                                            <input type="hidden" name="version" value="<?= (int)$post['version'] ?>">
                                            <input type="hidden" name="known_revision_id" value="<?= $latestRevisionId ?>">
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

    <?php require __DIR__ . '/footer.php'; ?>
