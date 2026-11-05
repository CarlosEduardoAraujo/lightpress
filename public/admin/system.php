<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Diagnóstico do Sistema e Saúde da Infraestrutura
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

if (!$currentUser || !in_array($currentUser['role'], ['editor', 'admin'], true)) {
    die("Acesso restrito.");
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

// 3. Status do OPcache
$opcacheEnabled = function_exists('opcache_get_status') && @opcache_get_status() !== false;
$opcacheData = $opcacheEnabled ? opcache_get_status(false) : null;

// 4. Armazenamento e Cache
$cachePath = $config['cache']['cache_path'] ?? '';
$cacheExists = is_dir($cachePath);
$cacheWritable = $cacheExists && is_writable($cachePath);

$uploadDir = $config['media']['upload_dir'] ?? '';
$uploadWritable = is_dir($uploadDir) && is_writable($uploadDir);

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saúde do Sistema - SpeedPress Admin</title>

    <!-- Detecção Instantânea de Tema Claro/Escuro sem Flash -->
    <script>
        (function() {
            var saved = localStorage.getItem('lp_theme');
            if (saved === 'dark' || (!saved && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

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
        [data-theme="dark"] {
            --primary: #f8fafc;
            --primary-accent: #3b82f6;
            --bg: #090d16;
            --surface: #131b2e;
            --border: #1e293b;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.5; transition: background 0.2s, color 0.2s; }
        
        .site-header { 
            background: var(--surface); 
            border-bottom: 1px solid var(--border); 
            padding: 0.85rem 1.75rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            position: sticky;
            top: 0;
            z-index: 100;
            transition: background 0.2s, border-color 0.2s;
        }
        .brand { font-size: 1.3rem; font-weight: 800; color: var(--primary); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; }
        .grid-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; text-align: center; transition: background 0.2s, border-color 0.2s; }
        .stat-num { font-size: 1.8rem; font-weight: 800; color: var(--primary-accent); margin-bottom: 0.25rem; }
        .stat-label { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }
        .grid-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; transition: background 0.2s, border-color 0.2s; }
        .card-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; }
        .info-row { display: flex; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        .info-row:last-child { border-bottom: none; }
        .badge-ok { color: #16a34a; font-weight: 600; }
        .badge-warn { color: #d97706; font-weight: 600; }
        .badge-err { color: #dc2626; font-weight: 600; }

        code { background: rgba(148, 163, 184, 0.15); padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.85rem; }

        .theme-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .theme-btn:hover { background: var(--border); }
    </style>
</head>
<body>
    <header class="site-header">
        <div style="display: flex; align-items: center; gap: 2.5rem;">
            <a href="/admin/dashboard.php" class="brand">Speed<span>Press</span> Redação</a>
            <nav style="display: flex; gap: 1.25rem; font-size: 0.92rem; align-items: center;">
                <a href="/admin/dashboard.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Notícias</a>
                <a href="/admin/pages.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Páginas</a>
                <a href="/admin/media.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Mídias</a>
                <a href="/admin/categories.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Categorias</a>
                <a href="/admin/stats.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Mais Lidas 📈</a>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/users.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Usuários & Autores</a>
                <?php endif; ?>
                <a href="/admin/system.php" style="color: var(--primary-accent); font-weight: 700; text-decoration: none;">Diagnóstico</a>
                <a href="/" target="_blank" style="color: var(--primary-accent); text-decoration: none; font-weight: 600;">Ver Site &rarr;</a>
            </nav>
        </div>
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span>Olá, <strong><?= Security::e($currentUser['name']) ?></strong></span>
            <button type="button" class="theme-btn" onclick="toggleTheme()" title="Alternar Modo Claro/Escuro" id="btnThemeToggle">🌙</button>
            <a href="/admin/dashboard.php?action=logout&_csrf_token=<?= Security::e($csrfToken) ?>" style="color: #dc2626; text-decoration: none; font-size: 0.9rem; font-weight: 600;">Sair</a>
        </div>
    </header>

    <div class="container">
        <h1 style="font-size: 1.6rem; font-weight: 800; margin-bottom: 1.5rem;">Diagnóstico e Saúde da Infraestrutura</h1>

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
                    <span><?= $opcacheEnabled ? '<span class="badge-ok">Sim</span>' : '<span class="badge-warn">Desativado</span>' ?></span>
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
                    <span>Nome da Sessão</span>
                    <span><code>LIGHTPRESSSESSID</code></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        function updateThemeIcon() {
            var current = document.documentElement.getAttribute('data-theme');
            var btn = document.getElementById('btnThemeToggle');
            if (btn) btn.innerText = (current === 'dark') ? '☀️' : '🌙';
        }
        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next = (current === 'dark') ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('lp_theme', next);
            updateThemeIcon();
        }
        updateThemeIcon();
    </script>
</body>
</html>
