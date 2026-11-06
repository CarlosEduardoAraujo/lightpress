<?php
declare(strict_types=1);

/**
 * Painel Administrativo - Configurações Gerais do Site
 * 
 * Permite a edição em tempo real de:
 * 1. Título do site (SpeedRacing)
 * 2. Descrição editorial (Tagline / Meta Description)
 * 3. Ícone do site (Favicon com preview e seletor de mídia)
 * 4. Fuso Horário (UTC, UTC-3 Brasília, UTC-12 a UTC+14)
 */

$config = require dirname(__DIR__, 2) . '/core/bootstrap.php';

use LightPress\Core\CacheManager;
use LightPress\Core\Database;
use LightPress\Core\Security;
use LightPress\Core\BlockCompiler;
use LightPress\Core\Settings;

$currentUser = Security::requireAuth(null, ['admin']);

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::verifyCsrfToken($_POST['_csrf_token'] ?? null)) {
            $errorMessage = 'Token de segurança CSRF inválido ou expirado. Recarregue a página e tente novamente.';
        } else {
            $siteTitle = trim($_POST['site_title'] ?? 'SpeedRacing');
            if ($siteTitle === '') {
                $siteTitle = 'SpeedRacing';
            }

            $siteDescription = trim($_POST['site_description'] ?? '');
            $siteFavicon = !empty($_POST['site_favicon']) ? BlockCompiler::sanitizeUrl(trim($_POST['site_favicon'])) : '';
            $siteTimezone = trim($_POST['site_timezone'] ?? 'America/Sao_Paulo');

            // Validação estrita do Fuso Horário informado
            $validTz = false;
            try {
                new \DateTimeZone($siteTimezone);
                $validTz = true;
            } catch (\Throwable) {
                $validTz = false;
            }

            if (!$validTz) {
                $siteTimezone = 'America/Sao_Paulo';
            }

            Settings::setMany([
                'site_title' => $siteTitle,
                'site_description' => $siteDescription,
                'site_favicon' => $siteFavicon,
                'site_timezone' => $siteTimezone,
            ]);

            // Aplicar imediatamente à configuração global em tempo de execução
            Settings::applyToConfig($config);

            // Expurgar cache do Nginx para propagar mudanças instantaneamente
            try {
                $cacheManager = new CacheManager($config);
                $cacheManager->purgeUrls(['/', '/feed.xml', '/sitemap.xml']);
            } catch (\Throwable) {
                // Silencioso em ambiente de desenvolvimento
            }

            $successMessage = 'Configurações do site salvas com sucesso!';
        }
    } catch (\Throwable $e) {
        error_log("[Settings Save Error] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        $errorMessage = 'Erro ao processar as configurações: ' . $e->getMessage();
    }
}

// Carregar configurações atuais
$currentTitle = Settings::get('site_title', $config['app']['name'] ?? 'SpeedRacing');
$currentDescription = Settings::get('site_description', $config['app']['description'] ?? 'Automobilismo mundial e nacional - Credibilidade em notícias desde 2002');
$currentFavicon = Settings::get('site_favicon', $config['app']['favicon'] ?? '');
$currentTimezone = Settings::get('site_timezone', $config['app']['display_timezone'] ?? 'America/Sao_Paulo');

// Catálogo de Fusos Horários
$timezoneGroups = [
    'Fusos Horários Recomendados' => [
        'America/Sao_Paulo'   => 'America/Sao_Paulo (UTC-3 • Horário Oficial de Brasília)',
        'UTC'                 => 'UTC (UTC+0 • Tempo Universal Coordenado / FIA / WEC)',
        'Europe/London'       => 'Europe/London (UTC+0 / UTC+1 • Reino Unido / Silverstone)',
        'Europe/Paris'        => 'Europe/Paris (UTC+1 / UTC+2 • Europa Central / Le Mans / Monza)',
        'America/New_York'    => 'America/New_York (UTC-5 / UTC-4 • Nova York / Miami / NASCAR)',
        'America/Chicago'     => 'America/Chicago (UTC-6 / UTC-5 • Chicago / Indianápolis)',
        'America/Los_Angeles' => 'America/Los_Angeles (UTC-8 / UTC-7 • Costa Oeste EUA)',
        'America/Buenos_Aires'=> 'America/Buenos_Aires (UTC-3 • Argentina)',
        'Asia/Tokyo'          => 'Asia/Tokyo (UTC+9 • Japão / Suzuka)',
    ],
    'Escala UTC Direta (Offsets Fixos)' => [
        'Etc/GMT+12' => 'UTC-12:00',
        'Etc/GMT+11' => 'UTC-11:00',
        'Etc/GMT+10' => 'UTC-10:00 (Havaí)',
        'Etc/GMT+9'  => 'UTC-09:00 (Alasca)',
        'Etc/GMT+8'  => 'UTC-08:00 (Pacífico)',
        'Etc/GMT+7'  => 'UTC-07:00 (Montanhas)',
        'Etc/GMT+6'  => 'UTC-06:00 (Central)',
        'Etc/GMT+5'  => 'UTC-05:00 (Leste)',
        'Etc/GMT+4'  => 'UTC-04:00 (Atlântico)',
        'Etc/GMT+3'  => 'UTC-03:00 (Brasília / Buenos Aires)',
        'Etc/GMT+2'  => 'UTC-02:00 (Fernando de Noronha)',
        'Etc/GMT+1'  => 'UTC-01:00 (Cabo Verde)',
        'UTC'        => 'UTC (UTC+00:00)',
        'Etc/GMT-1'  => 'UTC+01:00 (Europa Central)',
        'Etc/GMT-2'  => 'UTC+02:00 (Leste Europeu / Cairo)',
        'Etc/GMT-3'  => 'UTC+03:00 (Moscou / Arábia Saudita)',
        'Etc/GMT-4'  => 'UTC+04:00 (Dubai / Abu Dhabi)',
        'Etc/GMT-5'  => 'UTC+05:00 (Paquistão)',
        'Etc/GMT-6'  => 'UTC+06:00 (Bangladesh)',
        'Etc/GMT-7'  => 'UTC+07:00 (Tailândia / Vietnã)',
        'Etc/GMT-8'  => 'UTC+08:00 (Singapura / China)',
        'Etc/GMT-9'  => 'UTC+09:00 (Tóquio / Coreia)',
        'Etc/GMT-10' => 'UTC+10:00 (Sydney / Melbourne)',
        'Etc/GMT-11' => 'UTC+11:00 (Ilhas Salomão)',
        'Etc/GMT-12' => 'UTC+12:00 (Nova Zelândia / Fiji)',
        'Etc/GMT-13' => 'UTC+13:00 (Samoa)',
        'Etc/GMT-14' => 'UTC+14:00 (Ilhas da Linha)',
    ]
];

// Cálculo de Hora Atual no Fuso Ativo
try {
    $nowInTz = new \DateTime('now', new \DateTimeZone($currentTimezone));
    $tzFormattedNow = $nowInTz->format('d/m/Y H:i:s (T, P)');
} catch (\Throwable) {
    $tzFormattedNow = date('d/m/Y H:i:s');
}

$csrfToken = Security::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações do Site - SpeedPress Admin</title>
    <?php if (!empty($config['app']['favicon'])): ?>
        <link rel="icon" href="<?= Security::e($config['app']['favicon']) ?>">
    <?php endif; ?>

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
            --primary-accent: #E10600;
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
            --primary-accent: #E10600;
            --bg: #090d16;
            --surface: #111827;
            --border: #1f2937;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); padding-bottom: 5rem; }
        .site-header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0.85rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .brand { font-size: 1.15rem; font-weight: 800; color: var(--text); text-decoration: none; }
        .brand span { color: var(--primary-accent); }
        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; font-weight: 600; }
        .nav-links a { color: var(--text-muted); text-decoration: none; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary-accent); }
        .user-menu { display: flex; align-items: center; gap: 1.25rem; font-size: 0.88rem; }
        .theme-btn {
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 0.35rem 0.65rem;
            border-radius: var(--radius);
            cursor: pointer;
            color: var(--text);
            font-size: 1rem;
        }
        .logout { color: #ef4444; text-decoration: none; font-weight: 600; }

        .container { max-width: 980px; margin: 2rem auto; padding: 0 1.5rem; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .page-title { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; }
        .page-desc { color: var(--text-muted); font-size: 0.88rem; margin-top: 0.25rem; }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .section-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            line-height: 1.4;
        }

        .form-group { margin-bottom: 1.75rem; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label {
            display: block;
            font-size: 0.88rem;
            font-weight: 700;
            margin-bottom: 0.45rem;
            color: var(--text);
        }
        .form-hint {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 0.35rem;
            line-height: 1.4;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            background: var(--bg);
            color: var(--text);
            font-size: 0.92rem;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-accent);
            box-shadow: 0 0 0 3px rgba(225, 6, 0, 0.15);
        }

        /* Favicon Preview & Tab Mockup */
        .favicon-preview-grid {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: start;
            margin-top: 0.5rem;
        }
        @media (max-width: 700px) {
            .favicon-preview-grid { grid-template-columns: 1fr; }
        }

        .browser-tab-mockup {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .tab-bar {
            display: flex;
            align-items: center;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 0.45rem 0.85rem;
            gap: 0.65rem;
            max-width: 280px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .tab-icon {
            width: 18px;
            height: 18px;
            border-radius: 3px;
            object-fit: contain;
            background: #fff;
            padding: 1px;
            border: 1px solid rgba(0,0,0,0.1);
        }
        .tab-title {
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text);
        }
        .tab-close {
            margin-left: auto;
            color: var(--text-muted);
            font-size: 0.75rem;
            opacity: 0.7;
        }

        .icon-sizes {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .icon-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.72rem;
            color: var(--text-muted);
        }
        .icon-box img {
            border: 1px solid var(--border);
            border-radius: 6px;
            background: #fff;
            padding: 2px;
            object-fit: contain;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: background 0.15s, transform 0.05s;
            text-decoration: none;
        }
        .btn:active { transform: scale(0.98); }
        .btn-primary {
            background: var(--primary-accent);
            color: #ffffff;
        }
        .btn-primary:hover {
            background: #b30400;
        }
        .btn-secondary {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-secondary:hover {
            background: var(--surface);
            border-color: var(--text-muted);
        }

        .alert-success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            font-size: 0.92rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            font-size: 0.92rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Modal Seletor de Mídia */
        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .modal-content {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            width: 100%;
            max-width: 900px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4);
        }
        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body {
            padding: 1.5rem;
            overflow-y: auto;
            flex-grow: 1;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg);
        }
        .modal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 1rem;
        }
        .modal-media-item {
            border: 2px solid transparent;
            border-radius: var(--radius);
            overflow: hidden;
            cursor: pointer;
            background: var(--bg);
            transition: all 0.15s;
            display: flex;
            flex-direction: column;
        }
        .modal-media-item:hover { border-color: var(--text-muted); }
        .modal-media-item.selected { border-color: var(--primary-accent); box-shadow: 0 0 0 2px var(--primary-accent); }
        .modal-media-thumb {
            width: 100%;
            aspect-ratio: 1/1;
            object-fit: cover;
            background: #000;
        }
        .modal-media-title {
            font-size: 0.72rem;
            padding: 0.4rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text);
        }
    </style>
</head>
<body>
    <?= \LightPress\Core\AdminNav::renderHeader('settings', $currentUser, $csrfToken) ?>

    <div class="container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Configurações Gerais</h1>
                <p class="page-desc">Gerencie a identidade visual, títulos, ícone oficial e fuso horário do portal SpeedRacing.</p>
            </div>
            <a href="/" target="_blank" class="btn btn-secondary">
                <span>🌐</span> Ver Site Ao Vivo
            </a>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="alert-success">
                <span>✓</span> <?= Security::e($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert-error">
                <span>⚠</span> <?= Security::e($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/admin/settings.php" id="formSettings">
            <input type="hidden" name="_csrf_token" value="<?= Security::e($csrfToken) ?>">

            <!-- Card 1: Identidade do Site -->
            <div class="card">
                <h2 class="section-title">
                    <span>🏁</span> Identidade &amp; Metadados do Portal
                </h2>
                <p class="section-desc">Defina o nome oficial da publicação e o resumo editorial padrão utilizado para SEO e compartilhamento nas redes sociais.</p>

                <!-- Título do Site -->
                <div class="form-group">
                    <label class="form-label" for="site_title">Título do Site (Nome da Publicação)</label>
                    <input type="text" id="site_title" name="site_title" class="form-input" value="<?= Security::e($currentTitle) ?>" required placeholder="Ex: SpeedRacing" oninput="updateTabPreview()">
                    <p class="form-hint">Exibido na tag &lt;title&gt;, no cabeçalho das abas, nos feeds RSS e nas buscas do Google Notícias.</p>
                </div>

                <!-- Descrição do Site -->
                <div class="form-group">
                    <label class="form-label" for="site_description">Descrição / Slogan (Tagline)</label>
                    <textarea id="site_description" name="site_description" rows="3" class="form-textarea" placeholder="Ex: Automobilismo mundial e nacional - Credibilidade em notícias desde 2002"><?= Security::e($currentDescription) ?></textarea>
                    <p class="form-hint">Meta description padrão para a Home e quando matérias ou páginas não possuírem resumo próprio.</p>
                </div>
            </div>

            <!-- Card 2: Ícone do Site (Favicon) -->
            <div class="card">
                <h2 class="section-title">
                    <span>🖼️</span> Ícone do Site (Favicon)
                </h2>
                <p class="section-desc">O ícone oficial exibido nas abas dos navegadores, favoritos, tela inicial de celulares e resultados do Google.</p>

                <div class="form-group">
                    <label class="form-label" for="site_favicon">URL / Caminho da Imagem do Favicon</label>
                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                        <input type="text" id="site_favicon" name="site_favicon" class="form-input" value="<?= Security::e($currentFavicon) ?>" placeholder="/favicon.ico ou /uploads/2026/10/icone.png" oninput="updateFaviconPreview(this.value)">
                        <button type="button" class="btn btn-secondary" onclick="openMediaModal()" style="white-space: nowrap;">
                            <span>📷</span> Selecionar da Biblioteca
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearFavicon()" style="white-space: nowrap; color: #ef4444;" title="Limpar / Usar Padrão">
                            ✕
                        </button>
                    </div>
                    <p class="form-hint">Formatos ideais: .ico, .png, .svg ou .webp quadrado (recomendado: 32x32, 64x64, 192x192 ou 512x512 pixels).</p>
                </div>

                <!-- Live Browser Tab Preview -->
                <div class="form-group">
                    <label class="form-label">Pré-visualização em Tempo Real</label>
                    <div class="favicon-preview-grid">
                        <div class="browser-tab-mockup">
                            <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">
                                Simulação de Aba do Navegador
                            </span>
                            <div class="tab-bar">
                                <img id="previewTabIcon" class="tab-icon" src="<?= !empty($currentFavicon) ? Security::e($currentFavicon) : '/favicon.ico' ?>" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏁</text></svg>'">
                                <span id="previewTabTitle" class="tab-title"><?= Security::e($currentTitle) ?> - Notícias</span>
                                <span class="tab-close">✕</span>
                            </div>
                        </div>

                        <div class="browser-tab-mockup">
                            <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);">
                                Dimensões
                            </span>
                            <div class="icon-sizes">
                                <div class="icon-box">
                                    <img id="preview32" src="<?= !empty($currentFavicon) ? Security::e($currentFavicon) : '/favicon.ico' ?>" width="32" height="32" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏁</text></svg>'">
                                    <span>32x32</span>
                                </div>
                                <div class="icon-box">
                                    <img id="preview64" src="<?= !empty($currentFavicon) ? Security::e($currentFavicon) : '/favicon.ico' ?>" width="48" height="48" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏁</text></svg>'">
                                    <span>48x48</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 3: Fuso Horário e Localização -->
            <div class="card">
                <h2 class="section-title">
                    <span>🕒</span> Fuso Horário do Sistema (Timezone)
                </h2>
                <p class="section-desc">Define a hora e a data exibidas nas matérias, agendamentos editoriais e logs do servidor.</p>

                <div class="form-group">
                    <label class="form-label" for="site_timezone">Fuso Horário Oficial</label>
                    <select id="site_timezone" name="site_timezone" class="form-select" onchange="updateTimezonePreview(this.value)">
                        <?php foreach ($timezoneGroups as $groupLabel => $options): ?>
                            <optgroup label="<?= Security::e($groupLabel) ?>">
                                <?php foreach ($options as $tzKey => $tzLabel): ?>
                                    <option value="<?= Security::e($tzKey) ?>" <?= ($currentTimezone === $tzKey) ? 'selected' : '' ?>>
                                        <?= Security::e($tzLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint" id="tzFeedback">
                        Hora atual neste fuso: <strong><?= Security::e($tzFormattedNow) ?></strong>
                    </p>
                </div>
            </div>

            <!-- Ações -->
            <div style="display: flex; gap: 1rem; align-items: center;">
                <button type="submit" class="btn btn-primary" style="padding: 0.9rem 2rem; font-size: 1rem;">
                    <span>💾</span> Salvar Configurações
                </button>
                <a href="/admin/dashboard.php" class="btn btn-secondary">
                    Cancelar
                </a>
            </div>
        </form>
    </div>

    <!-- Modal Seletor de Mídia Integrado -->
    <div id="mediaModal" class="modal-backdrop">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="font-size: 1.15rem; font-weight: 800; color: var(--text);">Biblioteca de Mídias</h3>
                <button type="button" onclick="closeMediaModal()" style="background: none; border: none; font-size: 1.5rem; color: var(--text-muted); cursor: pointer;">✕</button>
            </div>
            <div style="padding: 1rem 1.5rem 0.5rem; display: flex; gap: 1rem; align-items: center; background: var(--bg); border-bottom: 1px solid var(--border);">
                <input type="text" id="modalSearch" placeholder="Filtrar por nome do arquivo..." class="form-input" style="flex-grow: 1;" oninput="filterMediaItems(this.value)">
                <label class="btn btn-primary" style="cursor: pointer; white-space: nowrap; font-size: 0.85rem; padding: 0.6rem 1rem;">
                    <span>⬆</span> Enviar Foto
                    <input type="file" id="modalUploadInput" accept="image/*,.ico" style="display: none;" onchange="uploadFromModal(this)">
                </label>
            </div>
            <div class="modal-body">
                <div id="modalLoading" style="display: none; text-align: center; padding: 2rem; color: var(--text-muted);">
                    Carregando catálogo de fotos...
                </div>
                <div id="modalMediaGrid" class="modal-grid"></div>
            </div>
            <div class="modal-footer">
                <div id="modalSelectionDetails" style="font-size: 0.85rem; color: var(--text-muted);">
                    Selecione uma imagem na grade acima
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeMediaModal()">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnApplySelection" disabled onclick="applyMediaSelection()">
                        Aplicar como Ícone
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('lp_theme', next);
            var btn = document.getElementById('btnThemeToggle');
            if (btn) btn.innerText = next === 'dark' ? '🌙' : '☀️';
        }

        // Atualizar Mockup de Aba em Tempo Real
        function updateTabPreview() {
            var titleInput = document.getElementById('site_title');
            var tabTitle = document.getElementById('previewTabTitle');
            if (titleInput && tabTitle) {
                tabTitle.innerText = (titleInput.value || 'SpeedRacing') + ' - Notícias';
            }
        }

        function updateFaviconPreview(url) {
            var finalUrl = (url && url.trim()) ? url.trim() : '/favicon.ico';
            var tabIcon = document.getElementById('previewTabIcon');
            var p32 = document.getElementById('preview32');
            var p64 = document.getElementById('preview64');

            if (tabIcon) tabIcon.src = finalUrl;
            if (p32) p32.src = finalUrl;
            if (p64) p64.src = finalUrl;
        }

        function clearFavicon() {
            document.getElementById('site_favicon').value = '';
            updateFaviconPreview('');
        }

        function updateTimezonePreview(tz) {
            var feedback = document.getElementById('tzFeedback');
            try {
                var now = new Date();
                var formatted = new Intl.DateTimeFormat('pt-BR', {
                    timeZone: tz.startsWith('Etc/') ? 'UTC' : tz,
                    dateStyle: 'short',
                    timeStyle: 'medium'
                }).format(now);
                feedback.innerHTML = 'Hora estimada neste fuso: <strong>' + formatted + '</strong>';
            } catch (e) {
                feedback.innerHTML = 'Fuso selecionado: <strong>' + tz + '</strong>';
            }
        }

        // Lógica do Modal de Mídias
        var modalMediaList = [];
        var selectedMediaItem = null;

        function openMediaModal() {
            document.getElementById('mediaModal').style.display = 'flex';
            loadMediaCatalog();
        }

        function closeMediaModal() {
            document.getElementById('mediaModal').style.display = 'none';
        }

        function loadMediaCatalog() {
            var grid = document.getElementById('modalMediaGrid');
            var loading = document.getElementById('modalLoading');
            grid.innerHTML = '';
            loading.style.display = 'block';

            fetch('/admin/api/media.php')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    loading.style.display = 'none';
                    if (data.ok && Array.isArray(data.media)) {
                        modalMediaList = data.media;
                        renderModalGrid(modalMediaList);
                    } else {
                        grid.innerHTML = '<p style="padding: 2rem; color: #dc2626;">Erro ao carregar mídias.</p>';
                    }
                })
                .catch(function(err) {
                    loading.style.display = 'none';
                    grid.innerHTML = '<p style="padding: 2rem; color: #dc2626;">Falha de comunicação: ' + err.message + '</p>';
                });
        }

        function renderModalGrid(items) {
            var grid = document.getElementById('modalMediaGrid');
            grid.innerHTML = '';

            if (items.length === 0) {
                grid.innerHTML = '<p style="padding: 3rem; text-align: center; color: var(--text-muted); grid-column: 1 / -1;">Nenhuma imagem encontrada.</p>';
                return;
            }

            items.forEach(function(item) {
                var card = document.createElement('div');
                card.className = 'modal-media-item';
                if (selectedMediaItem && selectedMediaItem.id === item.id) {
                    card.classList.add('selected');
                }

                var img = document.createElement('img');
                img.className = 'modal-media-thumb';
                img.loading = 'lazy';
                var safeThumb = (typeof item.thumb_url === 'string' && (item.thumb_url.startsWith('/') || item.thumb_url.startsWith('http://') || item.thumb_url.startsWith('https://')))
                    ? item.thumb_url : '';
                img.src = safeThumb;
                img.alt = item.original_name || 'Mídia';

                var titleEl = document.createElement('div');
                titleEl.className = 'modal-media-title';
                titleEl.title = item.original_name || '';
                titleEl.textContent = item.original_name || '';

                card.appendChild(img);
                card.appendChild(titleEl);

                card.onclick = function() {
                    document.querySelectorAll('.modal-media-item').forEach(function(el) { el.classList.remove('selected'); });
                    card.classList.add('selected');
                    selectedMediaItem = item;

                    var detailsEl = document.getElementById('modalSelectionDetails');
                    detailsEl.textContent = '';
                    var strong = document.createElement('strong');
                    strong.textContent = 'Selecionada: ';
                    detailsEl.appendChild(strong);
                    var textNode = document.createTextNode((item.original_name || '') + ' (' + Math.round(item.file_size / 1024) + ' KiB)');
                    detailsEl.appendChild(textNode);

                    document.getElementById('btnApplySelection').disabled = false;
                };

                card.ondblclick = function() {
                    selectedMediaItem = item;
                    applyMediaSelection();
                };

                grid.appendChild(card);
            });
        }

        function filterMediaItems(query) {
            query = query.toLowerCase().trim();
            if (!query) {
                renderModalGrid(modalMediaList);
                return;
            }
            var filtered = modalMediaList.filter(function(it) {
                return it.original_name.toLowerCase().includes(query);
            });
            renderModalGrid(filtered);
        }

        function uploadFromModal(input) {
            if (!input.files || input.files.length === 0) return;
            var file = input.files[0];
            var formData = new FormData();
            formData.append('media_file', file);
            formData.append('_csrf_token', '<?= Security::e($csrfToken) ?>');

            document.getElementById('modalLoading').style.display = 'block';
            document.getElementById('modalLoading').innerText = 'Enviando e processando ícone...';

            fetch('/admin/media.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                input.value = '';
                document.getElementById('modalLoading').innerText = 'Carregando fotos...';
                if (data.ok && data.media) {
                    loadMediaCatalog();
                } else {
                    alert('Erro no upload: ' + (data.error || 'Falha ao processar'));
                    document.getElementById('modalLoading').style.display = 'none';
                }
            })
            .catch(function(err) {
                input.value = '';
                alert('Erro ao enviar imagem: ' + err.message);
                document.getElementById('modalLoading').style.display = 'none';
            });
        }

        function applyMediaSelection() {
            if (!selectedMediaItem) return;
            var url = selectedMediaItem.full_url || selectedMediaItem.thumb_url;
            document.getElementById('site_favicon').value = url;
            updateFaviconPreview(url);
            closeMediaModal();
        }

        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            var modal = document.getElementById('mediaModal');
            if (event.target === modal) {
                closeMediaModal();
            }
        };
    </script>
</body>
</html>
