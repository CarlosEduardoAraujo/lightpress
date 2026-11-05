<?php
declare(strict_types=1);
use LightPress\Core\Database;
use LightPress\Core\ImageProcessor;
use LightPress\Core\Security;
use LightPress\Core\Seo;

// Categorias principais de automobilismo para o menu
$menuCategories = [
    ['slug' => 'formula-1', 'name' => 'Fórmula 1'],
    ['slug' => 'formula-2', 'name' => 'Fórmula 2'],
    ['slug' => 'formula-e', 'name' => 'Fórmula E'],
    ['slug' => 'indycar', 'name' => 'IndyCar'],
    ['slug' => 'stockcar', 'name' => 'Stock Car'],
    ['slug' => 'nascar', 'name' => 'NASCAR'],
    ['slug' => 'wec', 'name' => 'Le Mans / WEC'],
    ['slug' => 'motovelocidade', 'name' => 'Motovelocidade'],
];

// Tags em alta
$trendingTags = ['GPSãoPaulo2026', 'RafaelCamara', 'NASCARHomestead', 'LeMansUltimate', 'TelemetriaF1'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?= Security::e($pageTitle ?? 'SpeedRacing - Automobilismo e Velocidade') ?></title>
    <?php if (!empty($post['seo_description'])): ?>
        <meta name="description" content="<?= Security::e($post['seo_description']) ?>">
    <?php elseif (!empty($post['summary'])): ?>
        <meta name="description" content="<?= Security::e($post['summary']) ?>">
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="Feed RSS - SpeedRacing" href="/feed.xml">

    <!-- Open Graph & Twitter Cards -->
    <?= Seo::renderMetaTags($post ?? null, $config) ?>

    <!-- Schema.org JSON-LD para Google Notícias -->
    <?= Seo::renderJsonLd($post ?? null, $config) ?>

    <!-- Script Anti-Flash de Tema: MODO ESCURO É O PADRÃO -->
    <script>
        (function() {
            var saved = localStorage.getItem('sr_theme');
            if (saved === 'light') {
                document.documentElement.classList.remove('dark');
                document.documentElement.setAttribute('data-theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <!-- Tipografia e Ícones do Design System SpeedRacing (Kinetic Apex Editorial) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

    <!-- Tailwind CSS com a Paleta Exata do DESIGN.md -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "surface-bright": "#f6faff",
                        "surface-container": "#e6eff8",
                        "surface-container-high": "#e0e9f2",
                        "surface-container-highest": "#dbe4ed",
                        "surface-container-low": "#ecf5fe",
                        "surface-container-lowest": "#ffffff",
                        "carbon-black": "#111418",
                        "carbon-surface": "#1A1E24",
                        "racing-red": "#E10600",
                        "primary": "#b30400",
                        "primary-container": "#e10600",
                        "tertiary": "#af101a",
                        "tertiary-container": "#d32f2f",
                        "secondary": "#5c5e63",
                        "secondary-container": "#dedfe5",
                        "secondary-fixed": "#e1e2e8",
                        "secondary-fixed-dim": "#c5c6cc",
                        "track-asphalt": "#272727",
                        "pure-white": "#FFFFFF",
                        "canvas-cream": "#F9FAFB",
                        "subtle-border": "#E5E7EB",
                        "flag-green": "#00A859",
                        "flag-yellow": "#FFB800",
                        "surface": "#f6faff",
                        "on-surface": "#141d23",
                        "on-surface-variant": "#5e3f3a",
                        "on-primary": "#ffffff",
                        "on-tertiary": "#ffffff",
                    },
                    borderRadius: {
                        DEFAULT: "0.125rem",
                        lg: "0.25rem",
                        xl: "0.5rem",
                        full: "0.75rem",
                    },
                    spacing: {
                        "gutter-mobile": "0.75rem",
                        "space-xs": "0.25rem",
                        "space-sm": "0.5rem",
                        "space-md": "1rem",
                        "space-lg": "1.5rem",
                        "space-xl": "2.5rem",
                        "margin-mobile": "1rem",
                        "margin": "2rem",
                        "gutter": "1.5rem",
                    },
                    fontFamily: {
                        "display-hero": ["Space Grotesk", "sans-serif"],
                        "headline-lg": ["Space Grotesk", "sans-serif"],
                        "headline-md": ["Space Grotesk", "sans-serif"],
                        "headline-sm": ["Space Grotesk", "sans-serif"],
                        "label-category": ["Space Grotesk", "sans-serif"],
                        "data-telemetry": ["Space Grotesk", "sans-serif"],
                        "body-lg": ["Work Sans", "sans-serif"],
                        "body-md": ["Work Sans", "sans-serif"],
                        "body-sm": ["Work Sans", "sans-serif"],
                        "label-meta": ["Work Sans", "sans-serif"],
                    },
                    fontSize: {
                        "display-hero": ["48px", { lineHeight: "54px", letterSpacing: "-0.03em", fontWeight: "700" }],
                        "display-hero-mobile": ["32px", { lineHeight: "38px", letterSpacing: "-0.02em", fontWeight: "700" }],
                        "headline-lg": ["32px", { lineHeight: "38px", letterSpacing: "-0.02em", fontWeight: "700" }],
                        "headline-lg-mobile": ["24px", { lineHeight: "30px", letterSpacing: "-0.01em", fontWeight: "700" }],
                        "headline-md": ["22px", { lineHeight: "28px", letterSpacing: "-0.01em", fontWeight: "600" }],
                        "headline-sm": ["18px", { lineHeight: "24px", fontWeight: "600" }],
                        "body-lg": ["18px", { lineHeight: "28px", fontWeight: "400" }],
                        "body-md": ["15px", { lineHeight: "22px", fontWeight: "400" }],
                        "body-sm": ["13px", { lineHeight: "18px", fontWeight: "400" }],
                        "label-category": ["11px", { lineHeight: "14px", letterSpacing: "0.08em", fontWeight: "700" }],
                        "label-meta": ["12px", { lineHeight: "16px", fontWeight: "500" }],
                        "data-telemetry": ["13px", { lineHeight: "16px", letterSpacing: "0.02em", fontWeight: "600" }],
                    }
                }
            }
        };
    </script>

    <style>
        @layer base {
            html, body { margin: 0; padding: 0; }
            body { overscroll-behavior: none; }
        }
        ::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="bg-surface dark:bg-carbon-black font-body-md text-on-surface dark:text-pure-white antialiased transition-colors duration-200">
    <header class="fixed top-0 left-0 right-0 z-50 bg-pure-white/95 dark:bg-carbon-black/95 backdrop-blur-md shadow-[0_1px_8px_rgba(0,0,0,0.06)] dark:shadow-[0_1px_8px_rgba(0,0,0,0.5)] border-b border-subtle-border dark:border-[#222]">
        <!-- 1. Top Strip (Ao Vivo / Próxima Etapa) -->
        <div class="w-full bg-carbon-black text-on-tertiary">
            <div class="max-w-[1280px] mx-auto px-margin-mobile md:px-margin flex items-center justify-between h-9 text-label-meta font-label-meta">
                <div class="flex items-center gap-space-md">
                    <span class="flex items-center gap-space-xs text-flag-yellow font-semibold">
                        <span class="w-2 h-2 rounded-full bg-racing-red animate-ping"></span>AO VIVO
                    </span>
                    <span class="text-secondary-fixed-dim hidden sm:inline">|</span>
                    <span class="text-surface-container-lowest tracking-tight">Próxima Etapa: GP de São Paulo 2026 • Interlagos (F1)</span>
                </div>
                <div class="flex items-center gap-space-md">
                    <span class="hidden md:inline text-secondary-fixed-dim">Credibilidade no automobilismo desde 2002</span>
                    <div class="flex items-center gap-space-sm">
                        <a class="text-surface-container-lowest hover:text-primary-fixed-dim transition-colors flex items-center gap-1" href="#newsletter">
                            <span class="material-symbols-outlined text-[15px]">mail</span><span class="hidden lg:inline">Newsletter</span>
                        </a>
                        <a class="text-surface-container-lowest hover:text-primary-fixed-dim transition-colors flex items-center gap-1" href="https://www.instagram.com/speedracing.com.br/" target="_blank" rel="noopener noreferrer">
                            <span class="material-symbols-outlined text-[15px]">share</span><span class="hidden lg:inline">Social</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Main Navigation Bar -->
        <div class="h-20 max-w-[1280px] mx-auto px-margin-mobile md:px-margin flex items-center justify-between gap-space-md">
            <div class="flex items-center gap-space-lg">
                <a class="flex items-center gap-space-sm shrink-0" href="/">
                    <img alt="SpeedRacing Logo" class="h-9 w-auto object-contain" src="https://lh3.googleusercontent.com/aida/AEtjO1UOsBZfJDdPHi-XRJwnIgrTQ59llngu7hLaIpF2kDSZd2_HEO75qlogsHTlyraBKg5ZuGiiBGklemmXVS51IAtXUjVbp5AQilfc2PZ9B9IyHCgnPTctlYI9k9KXyDqFfiEbh8dQNld9zXHAcTs1kzjhg6W4uspAkTfyyaTUClDqg2oXRHHvJ0Y1-MFMwQClZZq3yFrWQWtWHtVzuoum0dfUIp_RjS0Vaod5uRrg5ZBY" onerror="this.style.display='none'"/>
                    <div class="flex flex-col">
                        <span class="font-headline-sm text-headline-sm uppercase tracking-tighter text-carbon-black dark:text-pure-white leading-none">SPEED<span class="text-racing-red">RACING</span></span>
                        <span class="font-label-category text-[9px] tracking-widest text-secondary dark:text-secondary-fixed uppercase">Desde 2002</span>
                    </div>
                </a>

                <nav class="hidden xl:flex items-center gap-space-md font-body-sm text-body-sm">
                    <a aria-current="page" class="transition-colors whitespace-nowrap text-racing-red font-semibold" href="/">Home</a>
                    <?php foreach ($menuCategories as $cat): ?>
                        <a class="text-on-surface-variant dark:text-secondary-fixed hover:text-racing-red dark:hover:text-racing-red transition-colors whitespace-nowrap" href="/categoria/<?= Security::e($cat['slug']) ?>/"><?= Security::e($cat['name']) ?></a>
                    <?php endforeach; ?>
                    <a class="text-on-surface-variant dark:text-secondary-fixed hover:text-racing-red dark:hover:text-racing-red transition-colors whitespace-nowrap" href="/quem-somos/">Nossa Equipe</a>
                </nav>
            </div>

            <div class="flex items-center gap-space-sm">
                <!-- Busca Integrada -->
                <form action="/busca" method="GET" class="relative flex items-center">
                    <input type="text" name="q" placeholder="Buscar no portal..." value="<?= Security::e($_GET['q'] ?? '') ?>" class="hidden md:block w-36 focus:w-52 transition-all duration-200 text-body-sm py-1.5 px-3 rounded-lg bg-surface-container dark:bg-carbon-surface text-carbon-black dark:text-pure-white placeholder:text-secondary dark:placeholder:text-secondary-fixed-dim focus:outline-none focus:ring-1 focus:ring-racing-red border border-subtle-border dark:border-[#2a303c]">
                    <button type="submit" aria-label="Buscar no portal" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-carbon-surface flex items-center justify-center text-on-surface dark:text-pure-white hover:bg-surface-container-high dark:hover:bg-[#252b35] transition-colors md:absolute md:right-0">
                        <span class="material-symbols-outlined text-[20px]">search</span>
                    </button>
                </form>

                <!-- Botão de Alternância de Tema Claro / Escuro -->
                <button type="button" onclick="toggleTheme()" id="btnThemeToggle" aria-label="Alternar modo claro/escuro" class="w-9 h-9 rounded-lg bg-surface-container dark:bg-carbon-surface dark:text-pure-white flex items-center justify-center text-on-surface hover:bg-surface-container-high dark:hover:bg-[#252b35] transition-colors border border-subtle-border dark:border-[#2a303c]" title="Alternar Modo Claro/Escuro">
                    <span class="material-symbols-outlined text-[20px]" id="themeIcon">light_mode</span>
                </button>
                
                <div class="hidden sm:flex items-center px-space-sm py-1 bg-surface-container dark:bg-carbon-surface rounded-lg text-label-category font-label-category text-carbon-black dark:text-pure-white uppercase tracking-wider gap-1 border border-subtle-border dark:border-[#2a303c]">
                    <span class="material-symbols-outlined text-[14px] text-racing-red">language</span>
                    <span>BR</span>
                </div>

                <a href="/admin/login.php" class="w-8 h-8 rounded-full bg-primary flex items-center justify-center shrink-0 text-pure-white hover:opacity-90 transition-opacity" title="Acessar Painel">
                    <span class="material-symbols-outlined text-on-primary text-[18px]">person</span>
                </a>
            </div>
        </div>

        <!-- 3. Em Alta Ticker Strip -->
        <div class="bg-surface-container-low dark:bg-[#0d121c] border-t border-subtle-border/60 dark:border-[#1e2533]">
            <div class="max-w-[1280px] mx-auto px-margin-mobile md:px-margin h-11 flex items-center gap-space-sm overflow-x-auto">
                <span class="font-label-category text-label-category uppercase tracking-wider text-racing-red shrink-0 flex items-center gap-1">
                    <span class="material-symbols-outlined text-[14px]">trending_up</span>Em Alta:
                </span>
                <div class="flex items-center gap-space-xs font-label-meta text-label-meta">
                    <?php foreach ($trendingTags as $tTag): ?>
                        <a class="px-space-sm py-1 rounded-full bg-pure-white dark:bg-carbon-surface hover:bg-surface-container-high dark:hover:bg-[#242b38] text-carbon-black dark:text-pure-white whitespace-nowrap transition-colors shadow-xs border border-subtle-border/60 dark:border-[#2a303c]" href="/tag/<?= urlencode(Security::slugify($tTag)) ?>/">#<?= Security::e($tTag) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </header>

    <main class="w-full pt-40 bg-surface dark:bg-carbon-black min-h-[calc(100vh-280px)] transition-colors duration-200">
        <div class="max-w-[1280px] w-full mx-auto px-margin-mobile md:px-margin space-y-space-xl pb-space-xl">

    <script>
        function updateThemeButton() {
            var isDark = document.documentElement.classList.contains('dark');
            var icon = document.getElementById('themeIcon');
            var btn = document.getElementById('btnThemeToggle');
            if (icon) {
                icon.innerText = isDark ? 'light_mode' : 'dark_mode';
            }
            if (btn) {
                btn.setAttribute('title', isDark ? 'Mudar para Modo Claro' : 'Mudar para Modo Escuro');
            }
        }
        function toggleTheme() {
            var isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                document.documentElement.classList.remove('dark');
                document.documentElement.setAttribute('data-theme', 'light');
                localStorage.setItem('sr_theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('sr_theme', 'dark');
            }
            updateThemeButton();
        }
        document.addEventListener('DOMContentLoaded', updateThemeButton);
        updateThemeButton();
    </script>
