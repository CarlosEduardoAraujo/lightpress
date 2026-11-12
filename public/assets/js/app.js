/**
 * LightPress / SpeedRacing - Client Core Application
 * Desacoplado de inline-scripts para conformidade rigorosa com CSP (sem 'unsafe-inline').
 */
document.addEventListener('DOMContentLoaded', function () {
    // 1. Contador de visualizações assíncrono (Beacon com fallback Fetch)
    var viewElement = document.querySelector('[data-view-endpoint]');
    if (viewElement) {
        var endpoint = viewElement.getAttribute('data-view-endpoint');
        if (endpoint) {
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(endpoint);
                } else if (window.fetch) {
                    fetch(endpoint, { method: 'POST', keepalive: true }).catch(function () {});
                }
            } catch (e) {}
        }
    }

    // 2. Alternador de Tema Claro / Escuro
    var btnTheme = document.getElementById('btnThemeToggle');
    var themeIcon = document.getElementById('themeIcon');

    // SVGs canônicos para alternância
    var SUN_SVG = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37c-.39-.39-1.03-.39-1.41 0s-.39 1.03 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.36c.39-.39.39-1.03 0-1.41s-1.03-.39-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/></svg>';
    var MOON_SVG = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12.3 2a10 10 0 0 0-.19 20 10 10 0 0 0 8.35-4.51c.36-.57-.04-1.31-.71-1.31a7.5 7.5 0 0 1-7.14-9.87c.22-.67-.38-1.31-1.06-1.31-.08 0-.17 0-.25.02z"/></svg>';

    function updateThemeButton() {
        if (!themeIcon) return;
        var isDark = document.documentElement.classList.contains('dark');
        themeIcon.innerHTML = isDark ? SUN_SVG : MOON_SVG;
    }

    if (btnTheme) {
        updateThemeButton();
        btnTheme.addEventListener('click', function () {
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
        });
    }

    // 3. Botão de Copiar Link da Matéria
    var copyBtn = document.getElementById('copy-btn');
    if (copyBtn) {
        var copyIcon = document.getElementById('copy-icon');
        var originalIcon = copyIcon ? copyIcon.innerHTML : '';
        var CHECK_SVG = '<svg class="w-4 h-4 text-flag-green" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';

        copyBtn.addEventListener('click', function () {
            var url = copyBtn.getAttribute('data-copy-url') || window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    if (copyIcon) copyIcon.innerHTML = CHECK_SVG;
                    copyBtn.classList.add('border-flag-green');
                    setTimeout(function () {
                        if (copyIcon) copyIcon.innerHTML = originalIcon;
                        copyBtn.classList.remove('border-flag-green');
                    }, 2000);
                }).catch(function () {});
            }
        });
    }

    // 4. Formulários de Newsletter (Interação Segura)
    var newsletterForms = document.querySelectorAll('.form-newsletter');
    newsletterForms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var input = form.querySelector('input[type="email"]');
            var emailVal = input ? input.value : '';
            if (emailVal) {
                alert('Inscrição confirmada no Grid SpeedRacing!');
                if (input) input.value = '';
            }
        });
    });
});
