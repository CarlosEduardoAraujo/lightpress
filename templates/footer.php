<?php
declare(strict_types=1);
?>
    </main>
    <footer>
        <div class="container">
            <p>&copy; <?= date('Y') ?> SpeedPress / LightPress - Desenvolvido para altíssimo desempenho com PHP 8.4 e Nginx.</p>
        </div>
    </footer>

    <script>
        function updateThemeIcon() {
            var current = document.documentElement.getAttribute('data-theme');
            var btn = document.getElementById('btnThemeToggle');
            if (btn) {
                btn.innerText = (current === 'dark') ? '☀️' : '🌙';
            }
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
