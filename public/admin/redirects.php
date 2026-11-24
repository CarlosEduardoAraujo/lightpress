<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Gerenciador de Redirecionamentos SEO (301/302)
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Database;
use LightPress\Core\Icon;
use LightPress\Core\Security;

$currentUser = Security::requireAuth(null, ['admin', 'editor'], $config);

$message = '';
$error = '';

/**
 * Normaliza caminho de origem garantindo barra inicial e compatibilidade com Router::dispatch.
 */
$normalizeOldPath = static function (string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    // Se colaram URL completa, extrai apenas o path
    if (preg_match('#^https?://#i', $raw)) {
        $parsed = parse_url($raw, PHP_URL_PATH);
        $raw = is_string($parsed) ? $parsed : '';
    }
    $raw = '/' . ltrim($raw, '/');
    // Router canonicaliza rotas sem extensão de arquivo com barra final
    if ($raw !== '/' && !str_ends_with($raw, '/') && !preg_match('~\.[a-zA-Z0-9]+$~', $raw)) {
        $raw .= '/';
    }
    return $raw;
};

/**
 * Valida destino contra Open Redirect (apenas caminhos internos /... ou mesmo host configurado).
 */
$isSafeTarget = static function (string $target) use ($config): bool {
    $target = trim($target);
    if ($target === '') return false;
    if (str_starts_with($target, '//') || str_starts_with($target, '/\\') || str_starts_with($target, '\\')) {
        return false;
    }
    if (str_starts_with($target, '/')) {
        return true;
    }
    if (preg_match('#^https?://#i', $target)) {
        $targetHost = strtolower((string)parse_url($target, PHP_URL_HOST));
        $canonicalHost = strtolower((string)parse_url((string)($config['app']['canonical_origin'] ?? ''), PHP_URL_HOST));
        $serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $serverHostOnly = explode(':', $serverHost)[0];
        return $targetHost !== '' && ($targetHost === $canonicalHost || $targetHost === $serverHostOnly);
    }
    return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf_token'] ?? '';
    if (!Security::verifyCsrfToken($token)) {
        $error = 'Sessão expirada ou token CSRF inválido.';
    } else {
        $action = Security::getSafeString($_POST, 'action', 'create');

        if ($action === 'delete') {
            $delId = (int)($_POST['delete_id'] ?? 0);
            if ($delId > 0) {
                Database::query("DELETE FROM redirects WHERE id = ?", [$delId]);
                $message = 'Redirecionamento removido com sucesso.';
            }
        } elseif ($action === 'create') {
            $oldPath = $normalizeOldPath(Security::getSafeString($_POST, 'old_path'));
            $targetPath = trim(Security::getSafeString($_POST, 'target_path'));
            $statusCode = (int)($_POST['status_code'] ?? 301);
            if (!in_array($statusCode, [301, 302], true)) {
                $statusCode = 301;
            }

            if ($oldPath === '' || $oldPath === '/') {
                $error = 'Informe um caminho de origem válido (ex: /artigo-antigo/).';
            } elseif (!$isSafeTarget($targetPath)) {
                $error = 'Destino inválido. Utilize um caminho interno iniciando com / (ex: /novo-artigo/) ou URL do próprio domínio.';
            } elseif ($oldPath === $targetPath) {
                $error = 'Origem e destino não podem ser idênticos (evita loop infinito).';
            } else {
                $pathHash = hash('sha256', $oldPath);
                $existing = Database::fetchOne("SELECT id FROM redirects WHERE path_hash = ? LIMIT 1", [$pathHash]);
                if ($existing) {
                    Database::query(
                        "UPDATE redirects SET old_path = ?, target_path = ?, status_code = ? WHERE id = ?",
                        [$oldPath, $targetPath, $statusCode, (int)$existing['id']]
                    );
                    $message = "Redirecionamento existente para '{$oldPath}' atualizado com sucesso.";
                } else {
                    Database::query(
                        "INSERT INTO redirects (old_path, path_hash, target_path, status_code) VALUES (?, ?, ?, ?)",
                        [$oldPath, $pathHash, $targetPath, $statusCode]
                    );
                    $message = "Redirecionamento {$statusCode} criado: {$oldPath} → {$targetPath}";
                }
            }
        }
    }
}

$filterSearch = Security::getSafeString($_GET, 'q');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$whereSql = '';
$params = [];
if ($filterSearch !== '') {
    $escaped = Security::escapeLike($filterSearch);
    $whereSql = "WHERE old_path LIKE ? ESCAPE '!' OR target_path LIKE ? ESCAPE '!'";
    $params[] = "%{$escaped}%";
    $params[] = "%{$escaped}%";
}

$totalCount = (int)Database::fetchValue("SELECT COUNT(*) FROM redirects {$whereSql}", $params);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$redirects = Database::fetchAll(
    "SELECT id, old_path, target_path, status_code, created_at
     FROM redirects {$whereSql}
     ORDER BY id DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$csrfToken = Security::generateCsrfToken();
$pageTitle = 'Redirecionamentos SEO';
$activePage = 'redirects';
require __DIR__ . '/header.php';
?>
<style>
    .grid-redirects { display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; align-items: flex-start; }
    @media (max-width: 960px) { .grid-redirects { grid-template-columns: 1fr; } }
    .card { padding: 1.5rem; }
    .form-group { margin-bottom: 1.15rem; }
    .form-label { display: block; font-size: 0.84rem; font-weight: 700; margin-bottom: 0.4rem; color: var(--primary); }
</style>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Redirecionamentos SEO (301 / 302)</h1>
                <p class="page-subtitle">Preserve o tráfego orgânico redirecionando URLs antigas ou alteradas diretamente no roteador</p>
            </div>
        </div>

        <?php if (!empty($message)): ?><div class="alert-success"><?= Security::e($message) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="alert-error"><?= Security::e($error) ?></div><?php endif; ?>

        <div class="grid-redirects">
            <!-- Formulário de Cadastro -->
            <div class="card">
                <h2 style="font-size: 1.12rem; font-weight: 800; margin-bottom: 1.15rem; color: var(--primary);">
                    Novo Redirecionamento
                </h2>
                <form method="POST" action="/admin/redirects.php">
                    <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="form-group">
                        <label class="form-label" for="old_path">Caminho de Origem (Antigo)</label>
                        <input type="text" id="old_path" name="old_path" class="form-input" style="width:100%; font-family: monospace;" required placeholder="/materia-antiga/">
                        <span style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.3rem;">
                            Se já existir regra para esta origem, ela será atualizada.
                        </span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="target_path">Destino (Novo)</label>
                        <input type="text" id="target_path" name="target_path" class="form-input" style="width:100%; font-family: monospace;" required placeholder="/materia-nova/">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="status_code">Tipo HTTP</label>
                        <select id="status_code" name="status_code" class="form-select" style="width:100%;">
                            <option value="301">301 — Permanente (Recomendado para SEO)</option>
                            <option value="302">302 — Temporário</option>
                        </select>
                    </div>

                    <button type="submit" class="btn" style="width: 100%;">Salvar Redirecionamento</button>
                </form>
            </div>

            <!-- Listagem de Redirecionamentos -->
            <div class="table-card">
                <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; flex-wrap: wrap; gap: 0.75rem; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <h2 style="font-size: 1.05rem; font-weight: 800; color: var(--primary);">Regras Cadastradas</h2>
                        <span class="badge badge-neutral tabular-nums"><?= number_format($totalCount, 0, ',', '.') ?></span>
                    </div>
                    <form method="GET" action="/admin/redirects.php" style="display: flex; gap: 0.45rem; align-items: center;">
                        <input type="text" name="q" value="<?= Security::e($filterSearch) ?>" placeholder="Buscar origem ou destino..." class="form-input" style="padding: 0.4rem 0.7rem; font-size: 0.82rem; min-width: 190px;">
                        <button type="submit" class="btn btn-sm btn-secondary">Buscar</button>
                        <?php if ($filterSearch !== ''): ?>
                            <a href="/admin/redirects.php" class="btn btn-sm btn-ghost">Limpar</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Origem</th>
                                <th>Destino</th>
                                <th style="text-align: center;">Código</th>
                                <th>Criado em</th>
                                <th style="text-align: right;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($redirects)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                                        Nenhum redirecionamento cadastrado ainda.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($redirects as $r): ?>
                                    <tr>
                                        <td><code style="font-size: 0.82rem;"><?= Security::e($r['old_path']) ?></code></td>
                                        <td><code style="font-size: 0.82rem; color: var(--primary-accent);"><?= Security::e($r['target_path']) ?></code></td>
                                        <td style="text-align: center;">
                                            <span class="badge <?= (int)$r['status_code'] === 301 ? 'badge-ok' : 'badge-warn' ?> tabular-nums">
                                                <?= (int)$r['status_code'] ?>
                                            </span>
                                        </td>
                                        <td class="tabular-nums" style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                                            <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <form method="POST" action="/admin/redirects.php" style="display: inline;" data-confirm="Remover o redirecionamento de '<?= Security::e($r['old_path']) ?>'?" data-confirm-title="Excluir Redirecionamento" data-confirm-btn="Excluir">
                                                <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="btn btn-danger-soft btn-sm btn-icon" title="Excluir regra">
                                                    <?= Icon::svg('trash', '', 14) ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div style="padding: 0.85rem 1.25rem; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; font-size: 0.84rem; color: var(--text-muted);">
                        <span>Página <strong class="tabular-nums"><?= $page ?></strong> de <strong class="tabular-nums"><?= $totalPages ?></strong></span>
                        <div style="display: flex; gap: 0.35rem;">
                            <?php if ($page > 1): ?>
                                <a href="/admin/redirects.php?page=<?= $page - 1 ?><?= $filterSearch !== '' ? '&q=' . urlencode($filterSearch) : '' ?>" class="btn btn-secondary btn-sm">&larr; Anterior</a>
                            <?php endif; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="/admin/redirects.php?page=<?= $page + 1 ?><?= $filterSearch !== '' ? '&q=' . urlencode($filterSearch) : '' ?>" class="btn btn-secondary btn-sm">Próxima &rarr;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php require __DIR__ . '/footer.php'; ?>
