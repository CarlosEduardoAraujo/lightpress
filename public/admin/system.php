<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Diagnóstico do Sistema e Saúde da Infraestrutura
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\Authorization;
use LightPress\Core\Database;
use LightPress\Core\Security;

$currentUser = Security::requireAuth(null, ['admin', 'editor'], $config);

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
    [data-theme="dark"] th { background: #0f172a; }
    [data-theme="dark"] tr:hover td { background: #162036; }
    [data-theme="dark"] .stat-card { background: #131b2e; border-color: #1e293b; }

    .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; text-align: center; }
    .stat-num { font-size: 1.8rem; font-weight: 800; color: var(--primary-accent); margin-bottom: 0.25rem; }
    .stat-label { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }

    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin-bottom: 2rem; }
    .card-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 1rem; }
    
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem; }
    th { background: #f8fafc; padding: 0.75rem 1.25rem; border-bottom: 1px solid var(--border); color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; }
    td { padding: 0.75rem 1.25rem; border-bottom: 1px solid var(--border); }
    tr:last-child td { border-bottom: none; }
    .grid-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 1.5rem; }
    .card { padding: 1.5rem; }
    .card-title { font-size: 1.1rem; font-weight: 800; color: var(--primary); margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border); }
    .info-row { display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0; border-bottom: 1px dashed var(--border); font-size: 0.88rem; gap: 1rem; }
    .info-row:last-child { border-bottom: none; padding-bottom: 0; }
    .badge-ok { background: #dcfce7; color: #166534; padding: 0.2rem 0.55rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; }
    .badge-warn { background: #fef3c7; color: #b45309; padding: 0.2rem 0.55rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; }
    .badge-err { background: #fee2e2; color: #991b1b; padding: 0.2rem 0.55rem; border-radius: 4px; font-size: 0.75rem; font-weight: 700; }
    [data-theme="dark"] .badge-ok { background: #064e3b; color: #6ee7b7; }
    [data-theme="dark"] .badge-warn { background: #451a03; color: #fde68a; }
    [data-theme="dark"] .badge-err { background: #7f1d1d; color: #fca5a5; }
</style>

    <div class="container">
        <h1 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 1.5rem;">Diagnóstico e Saúde da Infraestrutura</h1>

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
    </div>

    <?php require __DIR__ . '/footer.php'; ?>
