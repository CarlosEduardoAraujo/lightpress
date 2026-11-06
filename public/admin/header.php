<?php
declare(strict_types=1);

/**
 * LightPress - Header Unificado da Área Administrativa
 * 
 * Fornece a estrutura HTML compartilhada, paleta de cores padrão em Vermelho (#E10600),
 * largura máxima universal de 1900px e cabeçalho dinâmico com o Título do Site.
 */

use LightPress\Core\AdminNav;
use LightPress\Core\Security;
use LightPress\Core\Settings;

$currentUser = $currentUser ?? Security::getCurrentUser() ?? [];
$csrfToken = $csrfToken ?? Security::generateCsrfToken();
$activePage = $activePage ?? '';
$pageTitle = $pageTitle ?? 'Painel Administrativo';
$extraHead = $extraHead ?? '';

$adminSiteTitle = Settings::get('site_title', $config['app']['name'] ?? 'SpeedRacing');
$adminSiteFavicon = Settings::get('site_favicon', $config['app']['favicon'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Security::e($pageTitle) ?> - <?= Security::e($adminSiteTitle) ?></title>
    <?php if (!empty($adminSiteFavicon)): ?>
        <link rel="icon" href="<?= Security::e($adminSiteFavicon) ?>">
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
            --primary-hover: #b80500;
            --primary-light: rgba(225, 6, 0, 0.08);
            --primary-focus: rgba(225, 6, 0, 0.2);
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --text-muted: #64748b;
            --radius: 8px;
            --font: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --admin-max-width: 1900px;
        }

        [data-theme="dark"] {
            --primary: #f8fafc;
            --primary-accent: #E10600;
            --primary-hover: #ff2a24;
            --primary-light: rgba(225, 6, 0, 0.15);
            --primary-focus: rgba(225, 6, 0, 0.3);
            --bg: #090d16;
            --surface: #111827;
            --border: #1f2937;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            transition: background 0.2s, color 0.2s;
            min-height: 100vh;
        }

        /* Largura Universal Padronizada para TODAS as Telas Administrativas */
        .container {
            width: 100%;
            max-width: var(--admin-max-width);
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Cabeçalho da Aplicação */
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
        .brand { 
            font-size: 1.3rem; 
            font-weight: 800; 
            color: var(--primary); 
            text-decoration: none; 
            display: flex; 
            align-items: center; 
            gap: 0.35rem; 
            letter-spacing: -0.02em;
            transition: color 0.15s;
        }
        .brand:hover { color: var(--primary-accent); }
        .brand span { color: var(--primary-accent); }

        .nav-links { display: flex; gap: 1.25rem; font-size: 0.92rem; align-items: center; }
        .nav-links a { color: var(--text-muted); text-decoration: none; font-weight: 600; transition: color 0.15s; }
        .nav-links a:hover, .nav-links a.active { color: var(--primary-accent); }

        .user-menu { display: flex; align-items: center; gap: 1rem; font-size: 0.9rem; }
        .user-menu a.logout { color: #dc2626; text-decoration: none; font-weight: 600; }

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

        /* Botões Padronizados com a Paleta Vermelha */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            padding: 0.65rem 1.2rem;
            background: var(--primary-accent);
            color: #ffffff;
            text-decoration: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: background 0.15s, opacity 0.15s, transform 0.05s;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn:active { transform: translateY(1px); }

        .btn-secondary {
            background: var(--border);
            color: var(--text);
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        [data-theme="dark"] .btn-secondary {
            background: #1e293b;
            color: #f1f5f9;
        }
        [data-theme="dark"] .btn-secondary:hover {
            background: #334155;
        }

        /* Formulários e Inputs */
        .form-select, .form-input, .form-textarea {
            padding: 0.55rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.88rem;
            background: var(--bg);
            color: var(--text);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-select:focus, .form-input:focus, .form-textarea:focus {
            border-color: var(--primary-accent);
            box-shadow: 0 0 0 3px var(--primary-focus);
        }

        /* Badges e Elementos de Suporte */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.55rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 700;
        }
    </style>
    <?= $extraHead ?>
</head>
<body>
    <?= AdminNav::renderHeader($activePage, $currentUser, $csrfToken) ?>
