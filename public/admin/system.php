<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Diagnóstico do Sistema e Saúde da Infraestrutura
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\CacheManager;
use LightPress\Core\Database;
use LightPress\Core\Icon;
use LightPress\Core\ObjectCache;
use LightPress\Core\Security;

$currentUser = Security::requireAuth(null, ['admin', 'editor'], $config);
$isAdmin = ($currentUser['role'] ?? '') === 'admin';
$purgeMessage = '';
$purgeError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $token = $_POST['_csrf_token'] ?? '';
    if (!Security::verifyCsrfToken($token)) {
        $purgeError = 'Token CSRF inválido. Recarregue a página e tente novamente.';
    } else {
        $purgeAction = Security::getSafeString($_POST, 'purge_action');
        $cacheMgr = new CacheManager($config);

        if ($purgeAction === 'global') {
            ObjectCache::flushAll();
            $cacheMgr->purgeUrls(['/', '/blog/', '/feed.xml', '/sitemap.xml', '/sitemap-main.xml', '/sitemap-news.xml']);
            $purgeMessage = 'Cache global (ObjectCache + Home/Feeds/Sitemaps) purgado com sucesso!';
        } elseif ($purgeAction === 'url') {
            $targetUrl = trim(Security::getSafeString($_POST, 'target_url'));
            if ($targetUrl === '' || !str_starts_with($targetUrl, '/')) {
                $purgeError = 'Informe um caminho relativo válido começando com / (ex: /categoria/f1/).';
            } else {
                $cacheMgr->purgeUrls([$targetUrl]);
                $purgeMessage = 'Purga executada para a URL: ' . $targetUrl;
            }
        } elseif ($purgeAction === 'retry_failed') {
            Database::query("UPDATE cache_purge_jobs SET status = 'pending', next_run_at = CURRENT_TIMESTAMP WHERE status = 'failed'");
            $purgeMessage = 'Jobs com falha reenfileirados para reprocessamento.';
        } elseif ($purgeAction === 'clear_completed') {
            Database::query("DELETE FROM cache_purge_jobs WHERE status = 'completed'");
            $purgeMessage = 'Jobs concluídos removidos da fila.';
        }
    }
}

// Fila de purga recente & logs (somente admin)
$purgeJobs = [];
$logLines = [];
if ($isAdmin) {
    try {
        $purgeJobs = Database::fetchAll(
            "SELECT id, post_id, event, status, attempts, last_error, created_at
             FROM cache_purge_jobs ORDER BY id DESC LIMIT 15"
        );
    } catch (\Throwable) {
        $purgeJobs = [];
    }

    $logFile = ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage') . '/logs/error.log';
    if (is_file($logFile) && is_readable($logFile)) {
        $fileObj = new \SplFileObject($logFile, 'r');
        $fileObj->seek(PHP_INT_MAX);
        $lastLine = $fileObj->key();
        $startLine = max(0, $lastLine - 80);
        $fileObj->seek($startLine);
        while (!$fileObj->eof()) {
            $line = rtrim((string)$fileObj->current(), "\r\n");
            if ($line !== '') {
                $logLines[] = $line;
            }
            $fileObj->next();
        }
        $logLines = array_reverse($logLines);
    }
}

// 1. Estatísticas do Banco de Dados
$totalPosts = (int)(Database::fetchOne("SELECT COUNT(*) as c FROM posts")['c'] ?? 0);
$totalPublished = (int)(Database::fetchOne("SELECT COUNT(*) as c FROM posts WHERE status = 'published'")['c'] ?? 0);
$totalDrafts = (int)(Database::fetchOne("SELECT COUNT(*) as c FROM posts WHERE status = 'draft'")['c'] ?? 0);
$totalViews = (int)(Database::fetchOne("SELECT SUM(views) as s FROM post_stats")['s'] ?? 0);
$totalUsers = (int)(Database::fetchOne("SELECT COUNT(*) as c FROM users")['c'] ?? 0);
$totalCategories = (int)(Database::fetchOne("SELECT COUNT(*) as c FROM categories")['c'] ?? 0);

// 2. Diagnóstico de GD e Codecs
$gdInfo = extension_loaded('gd') ? gd_info() : [];
$hasWebp = !empty($gdInfo['WebP Support']);
$hasAvif = !empty($gdInfo['AVIF Support']) || function_exists('imageavif');

// 3. Status do OPcache e Hardening do Runtime PHP
$opcacheData = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$opcacheEnabled = is_array($opcacheData) && !empty($opcacheData['opcache_enabled']);
$opcacheHitRate = null;
$opcacheUsedMb = null;
$opcacheTotalMb = null;
if ($opcacheEnabled) {
    if (isset($opcacheData['opcache_statistics']['opcache_hit_rate'])) {
        $opcacheHitRate = (float)$opcacheData['opcache_statistics']['opcache_hit_rate'];
    }
    if (isset($opcacheData['memory_usage']['used_memory'], $opcacheData['memory_usage']['free_memory'])) {
        $usedBytes = (float)$opcacheData['memory_usage']['used_memory'];
        $freeBytes = (float)$opcacheData['memory_usage']['free_memory'];
        $wastedBytes = (float)($opcacheData['memory_usage']['wasted_memory'] ?? 0);
        $opcacheUsedMb = round($usedBytes / 1048576, 1);
        $opcacheTotalMb = round(($usedBytes + $freeBytes + $wastedBytes) / 1048576, 1);
    }
}

$strictSessionMode = in_array(
    strtolower(trim((string)ini_get('session.use_strict_mode'))),
    ['1', 'on', 'true'],
    true
);
$sessionGcMaxLifetime = (int)ini_get('session.gc_maxlifetime');
$sessionIdleTimeout = (int)($config['security']['session_idle_timeout'] ?? 7200);
$sessionGcAligned = $sessionGcMaxLifetime >= $sessionIdleTimeout;

$shellFunctions = ['exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen'];
$disabledRaw = (string)ini_get('disable_functions');
$disabledList = array_filter(array_map(
    static fn(string $fn): string => strtolower(trim($fn)),
    explode(',', $disabledRaw)
));
$blockedShellCount = count(array_intersect($shellFunctions, $disabledList));
$allShellBlocked = ($blockedShellCount === count($shellFunctions));

// 4. Armazenamento e Cache
$cachePath = $config['cache']['cache_path'] ?? '';
$cacheExists = is_dir($cachePath);
$cacheWritable = $cacheExists && is_writable($cachePath);

$uploadDir = $config['media']['upload_dir'] ?? '';
$uploadWritable = is_dir($uploadDir) && is_writable($uploadDir);

$hmacSecret = (string)($config['security']['hmac_secret'] ?? '');
$isDefaultHmac = ($hmacSecret === 'ALTERAR_PARA_UMA_CHAVE_ALEATORIA_DE_64_CHARS_EM_PRODUCAO' || strlen($hmacSecret) < 32);

$encKey = (string)($config['security']['encryption_key'] ?? '');
$hasEncKey = !empty($encKey) && strlen(trim($encKey)) >= 32;

$csrfToken = Security::generateCsrfToken();
$pageTitle = 'Diagnóstico do Sistema';
$activePage = 'system';
require __DIR__ . '/header.php';
?>
<style>
    .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.75rem; }
    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.25rem;
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .stat-num { font-size: 1.75rem; font-weight: 900; color: var(--primary-accent); margin-bottom: 0.25rem; font-variant-numeric: tabular-nums; }
    .stat-label { font-size: 0.76rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }

    .grid-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; }
    @media (max-width: 480px) { .grid-details { grid-template-columns: 1fr; } }
    .card { padding: 1.5rem; margin-bottom: 1.5rem; }
    .card-title { font-size: 1.08rem; font-weight: 800; color: var(--primary); margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 0.5rem; }
    .info-row { display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0; border-bottom: 1px dashed var(--border); font-size: 0.88rem; gap: 1rem; }
    .info-row:last-child { border-bottom: none; padding-bottom: 0; }
</style>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Diagnóstico e Saúde da Infraestrutura</h1>
                <p class="page-subtitle">Monitoramento do ambiente PHP, armazenamento, segurança e cache</p>
            </div>
        </div>

        <?php if (!empty($purgeMessage)): ?><div class="alert-success"><?= Security::e($purgeMessage) ?></div><?php endif; ?>
        <?php if (!empty($purgeError)): ?><div class="alert-error"><?= Security::e($purgeError) ?></div><?php endif; ?>

        <?php if ($isAdmin): ?>
            <div class="card">
                <h2 class="card-title"><?= Icon::svg('activity', '', 18) ?> Gerenciamento Rápido de Cache</h2>
                <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between;">
                    <form method="POST" style="flex: 1; min-width: 280px; display: flex; gap: 0.5rem;">
                        <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="purge_action" value="url">
                        <input type="text" name="target_url" class="form-input" style="flex: 1;" placeholder="Purgar URL específica (ex: /categoria/f1/ ou /minha-materia/)" required>
                        <button type="submit" class="btn btn-secondary btn-sm">Purgar URL</button>
                    </form>
                    <form method="POST" data-confirm="Deseja purgar todo o ObjectCache e as rotas principais (Home, Blog, Feeds e Sitemaps)?" data-confirm-title="Purgar Cache Global" data-confirm-btn="Purgar Tudo">
                        <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <input type="hidden" name="purge_action" value="global">
                        <button type="submit" class="btn btn-sm">Purgar Cache Global</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isDefaultHmac): ?>
            <div style="background: rgba(239, 68, 68, 0.12); border: 2px solid #ef4444; color: #dc2626; border-radius: var(--radius); padding: 1.25rem 1.5rem; margin-bottom: 2rem;">
                <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                    <span style="font-size: 1.6rem; line-height: 1;">⚠️</span>
                    <div>
                        <strong style="font-size: 1.05rem; display: block; margin-bottom: 0.35rem; color: #ef4444;">Alerta de Segurança: Chave HMAC Insegura ou Padrão</strong>
                        <p style="font-size: 0.9rem; margin-bottom: 0.5rem; line-height: 1.4;">A chave <code>hmac_secret</code> configurada ainda é o valor padrão de desenvolvimento ou possui menos de 32 caracteres. Em produção, isso permite que terceiros forjem tokens e inflem visualizações no endpoint <code>/api/view.php</code>.</p>
                        <p style="font-size: 0.85rem; line-height: 1.4;"><strong>Correção recomendada:</strong> Configure uma chave de 64 caracteres em seu <code>config.local.php</code>:<br><code style="display: inline-block; margin-top: 0.25rem;">'security' => ['hmac_secret' => '<?= bin2hex(random_bytes(32)) ?>']</code></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Métricas Rápidas -->
        <div class="grid-stats">
            <div class="stat-card">
                <div class="stat-num"><?= number_format($totalPublished, 0, ',', '.') ?></div>
                <div class="stat-label">Artigos no Ar</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= number_format($totalDrafts, 0, ',', '.') ?></div>
                <div class="stat-label">Rascunhos</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= number_format($totalViews, 0, ',', '.') ?></div>
                <div class="stat-label">Visualizações Totais</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $totalCategories ?></div>
                <div class="stat-label">Categorias</div>
            </div>
            <div class="stat-card">
                <div class="stat-num"><?= $totalUsers ?></div>
                <div class="stat-label">Colaboradores</div>
            </div>
        </div>

        <div class="grid-details">
            <!-- Ambiente e PHP -->
            <div class="card">
                <h2 class="card-title">Ambiente de Execução</h2>
                <div class="info-row">
                    <span>Versão do PHP</span>
                    <strong><?= PHP_VERSION ?></strong>
                </div>
                <div class="info-row">
                    <span>Interface PHP</span>
                    <span><?= php_sapi_name() ?></span>
                </div>
                <div class="info-row">
                    <span>Limite de Memória</span>
                    <span><?= ini_get('memory_limit') ?></span>
                </div>
                <div class="info-row">
                    <span>Timezone do Servidor</span>
                    <span><?= date_default_timezone_get() ?></span>
                </div>
                <div class="info-row">
                    <span>OPcache Ativo</span>
                    <span>
                        <?php if ($opcacheEnabled): ?>
                            <span class="badge-ok">Sim</span>
                            <?php if ($opcacheUsedMb !== null && $opcacheTotalMb !== null): ?>
                                <small style="color: var(--text-muted); margin-left: 0.35rem;">
                                    <?= number_format($opcacheUsedMb, 1, ',', '.') ?>/<?= number_format($opcacheTotalMb, 0, ',', '.') ?> MB<?= $opcacheHitRate !== null ? ' · ' . number_format($opcacheHitRate, 1, ',', '.') . '% hit' : '' ?>
                                </small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge-warn">Desativado</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span>Driver de Banco</span>
                    <span><strong><?= strtoupper($config['database']['driver'] ?? 'MYSQL') ?></strong></span>
                </div>
            </div>

            <!-- Pipeline de Mídia GD -->
            <div class="card">
                <h2 class="card-title">Pipeline de Imagens (GD)</h2>
                <div class="info-row">
                    <span>Extensão GD</span>
                    <span><?= extension_loaded('gd') ? '<span class="badge-ok">Instalada</span>' : '<span class="badge-err">Ausente</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Suporte WebP (Obrigatório)</span>
                    <span><?= $hasWebp ? '<span class="badge-ok">Ativo (Leitura/Escrita)</span>' : '<span class="badge-err">Inativo</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Suporte AVIF (Opcional)</span>
                    <span><?= $hasAvif ? '<span class="badge-ok">Ativo (libavif)</span>' : '<span class="badge-warn">Inativo (Fallback WebP)</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Diretório de Uploads</span>
                    <span><?= $uploadWritable ? '<span class="badge-ok">Permissão de Escrita OK</span>' : '<span class="badge-err">Sem Escrita</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Resoluções Padronizadas</span>
                    <span>1920px, 1024px, 300x169 (16:9)</span>
                </div>
            </div>

            <!-- Nginx FastCGI Cache -->
            <div class="card">
                <h2 class="card-title">Cache FastCGI Nginx</h2>
                <div class="info-row">
                    <span>Cache Integrado</span>
                    <span><?= !empty($config['cache']['enabled']) ? '<span class="badge-ok">Habilitado</span>' : '<span class="badge-warn">Desabilitado</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Diretório de Cache</span>
                    <span style="font-family: monospace; font-size: 0.8rem;"><?= Security::e($cachePath) ?></span>
                </div>
                <div class="info-row">
                    <span>Acesso ao Disco</span>
                    <span><?= $cacheWritable ? '<span class="badge-ok">Gravável</span>' : ($cacheExists ? '<span class="badge-warn">Somente Leitura</span>' : '<span class="badge-warn">Não Encontrado (Dev)</span>') ?></span>
                </div>
                <div class="info-row">
                    <span>Estrutura de Níveis</span>
                    <span><code>levels=1:2</code> (MD5 hash)</span>
                </div>
            </div>

            <!-- Cloudflare Edge Cache Purge -->
            <div class="card">
                <h2 class="card-title">Cloudflare Edge Cache (API v4)</h2>
                <div class="info-row">
                    <span>Purga Automática</span>
                    <span><?= !empty($config['cloudflare']['enabled']) ? '<span class="badge-ok">Habilitada</span>' : '<span class="badge-warn">Desabilitada</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Zone ID</span>
                    <span><?= !empty($config['cloudflare']['zone_id']) ? '<span class="badge-ok">' . Security::e(substr((string)$config['cloudflare']['zone_id'], 0, 6) . '...' . substr((string)$config['cloudflare']['zone_id'], -4)) . '</span>' : '<span class="badge-warn">Pendente</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>API Token</span>
                    <span><?= !empty($config['cloudflare']['api_token']) ? '<span class="badge-ok">Configurado</span>' : '<span class="badge-warn">Pendente</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Método de Purga</span>
                    <span>Seletiva por URLs (Home, Feeds, Matérias)</span>
                </div>
            </div>

            <!-- Segurança Defensiva -->
            <div class="card">
                <h2 class="card-title">Segurança Ativa</h2>
                <div class="info-row">
                    <span>Hashing de Senhas</span>
                    <span class="badge-ok">Argon2id Nativo</span>
                </div>
                <div class="info-row">
                    <span>Proteção CSRF</span>
                    <span class="badge-ok">Tokens Criptográficos</span>
                </div>
                <div class="info-row">
                    <span>Defesa contra XSS</span>
                    <span class="badge-ok">BlockCompiler Whitelist</span>
                </div>
                <div class="info-row">
                    <span>Deduplicação de Views</span>
                    <span class="badge-ok">HMAC-SHA-256 Anonimizado</span>
                </div>
                <div class="info-row">
                    <span>Segredo HMAC</span>
                    <span><?= $isDefaultHmac ? '<span class="badge-err">Inseguro (Padrão ou &lt;32 chars)</span>' : '<span class="badge-ok">Configurado (' . strlen($hmacSecret) . ' chars)</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Criptografia de Segredos (Repouso)</span>
                    <span><?= $hasEncKey ? '<span class="badge-ok">Configurada (' . strlen(trim($encKey)) . ' chars)</span>' : '<span class="badge-warn">Pendente (Vazia)</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>Nome da Sessão</span>
                    <span><code>LIGHTPRESSSESSID</code></span>
                </div>
                <div class="info-row">
                    <span>Sessão Strict Mode (php.ini)</span>
                    <span><?= $strictSessionMode ? '<span class="badge-ok">Ativo</span>' : '<span class="badge-warn">Inativo</span>' ?></span>
                </div>
                <div class="info-row">
                    <span>GC de Sessão (gc_maxlifetime)</span>
                    <span><?= $sessionGcAligned
                        ? '<span class="badge-ok">' . $sessionGcMaxLifetime . 's</span>'
                        : '<span class="badge-warn">' . $sessionGcMaxLifetime . 's (&lt; ' . $sessionIdleTimeout . 's)</span>'
                    ?></span>
                </div>
                <div class="info-row">
                    <span>Funções de Shell (disable_functions)</span>
                    <span><?= $allShellBlocked
                        ? '<span class="badge-ok">' . $blockedShellCount . '/' . count($shellFunctions) . ' Bloqueadas</span>'
                        : ($blockedShellCount > 0
                            ? '<span class="badge-warn">' . $blockedShellCount . '/' . count($shellFunctions) . ' Bloqueadas</span>'
                            : '<span class="badge-warn">Não restritas</span>')
                    ?></span>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <!-- Monitor da Fila de Purga de Cache -->
            <div class="card" style="margin-top: 1.5rem;">
                <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border);">
                    <h2 class="card-title" style="margin-bottom: 0; padding-bottom: 0; border-bottom: none;">Fila de Purga de Cache (Últimos 15 Jobs)</h2>
                    <div style="display: flex; gap: 0.5rem;">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                            <input type="hidden" name="purge_action" value="retry_failed">
                            <button type="submit" class="btn btn-secondary btn-sm">Reenfileirar Falhos</button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">
                            <input type="hidden" name="purge_action" value="clear_completed">
                            <button type="submit" class="btn btn-secondary btn-sm">Limpar Concluídos</button>
                        </form>
                    </div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Evento</th>
                                <th>Post ID</th>
                                <th>Status</th>
                                <th>Tentativas</th>
                                <th>Último Erro</th>
                                <th>Criado em</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($purgeJobs)): ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 1.75rem;">Nenhum job registrado na fila.</td></tr>
                            <?php else: ?>
                                <?php foreach ($purgeJobs as $job): ?>
                                    <tr>
                                        <td class="tabular-nums">#<?= (int)$job['id'] ?></td>
                                        <td><code><?= Security::e($job['event']) ?></code></td>
                                        <td class="tabular-nums"><?= $job['post_id'] ? '#' . (int)$job['post_id'] : '—' ?></td>
                                        <td>
                                            <span class="badge <?= match($job['status']) { 'completed' => 'badge-ok', 'failed' => 'badge-err', default => 'badge-warn' } ?>">
                                                <?= Security::e($job['status']) ?>
                                            </span>
                                        </td>
                                        <td class="tabular-nums"><?= (int)$job['attempts'] ?></td>
                                        <td style="font-size: 0.8rem; color: var(--danger-text); max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= Security::e((string)($job['last_error'] ?? '')) ?>">
                                            <?= !empty($job['last_error']) ? Security::e((string)$job['last_error']) : '—' ?>
                                        </td>
                                        <td class="tabular-nums" style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                                            <?= date('d/m/Y H:i', strtotime($job['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Visualizador de Logs (error.log) -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border);">
                    <h2 class="card-title" style="margin-bottom: 0; padding-bottom: 0; border-bottom: none;">Logs Recentes (storage/logs/error.log)</h2>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="LPAdmin.copyText(document.getElementById('lpErrorLogBox').textContent, 'Logs copiados!')">
                        <?= Icon::svg('copy', '', 14) ?> Copiar
                    </button>
                </div>
                <?php if (empty($logLines)): ?>
                    <p style="color: var(--text-muted); font-size: 0.88rem;">Nenhum registro recente em <code>error.log</code>.</p>
                <?php else: ?>
                    <pre id="lpErrorLogBox" style="background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 1rem; font-size: 0.78rem; line-height: 1.5; max-height: 320px; overflow: auto; white-space: pre-wrap; word-break: break-word; color: var(--text);"><?= Security::e(implode("\n", $logLines)) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/footer.php'; ?>
