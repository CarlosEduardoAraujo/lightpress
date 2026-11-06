<?php
declare(strict_types=1);

/**
 * LightPress - Footer Unificado da Área Administrativa
 * 
 * Centraliza os scripts de alternância de tema e fechamento do layout HTML.
 */
?>
    <script>
        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var next = (current === 'dark') ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('lp_theme', next);
            updateThemeIcon();
        }

        function updateThemeIcon() {
            var current = document.documentElement.getAttribute('data-theme') || 'light';
            var btn = document.getElementById('btnThemeToggle');
            if (btn) {
                btn.textContent = (current === 'dark') ? '☀️' : '🌙';
            }
        }

        // Sincroniza ícone na inicialização
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', updateThemeIcon);
        } else {
            updateThemeIcon();
        }
    </script>
</body>
</html>
