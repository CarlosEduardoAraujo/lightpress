<?php
declare(strict_types=1);

/**
 * LightPress - Header Unificado da Área Administrativa
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
$adminCssVer = @filemtime(dirname(__DIR__) . '/assets/admin/admin.css') ?: '1';
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

    <link rel="stylesheet" href="/assets/admin/admin.css?v=<?= $adminCssVer ?>">
    <style>
        @media (max-width: 480px) { .hide-xs { display: none; } }
    </style>
    <?= $extraHead ?>
</head>
<body>
    <?= AdminNav::renderHeader($activePage, $currentUser, $csrfToken, $pageTitle) ?>
