(function() {
    var saved = localStorage.getItem('sr_theme');
    if (saved === 'light') {
        document.documentElement.classList.remove('dark');
        document.documentElement.setAttribute('data-theme', 'light');
    } else {
        document.documentElement.classList.add('dark');
        document.documentElement.setAttribute('data-theme', 'dark');
    }
    var gf = document.getElementById('gf-async');
    if (gf) {
        gf.addEventListener('load', function() { this.media = 'all'; });
        if (gf.sheet) { gf.media = 'all'; }
    }
})();
