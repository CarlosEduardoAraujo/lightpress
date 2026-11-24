/* LightPress Admin JS - Interações Leves (Zero Dependências) */
(function () {
    'use strict';

    var STORAGE_COLLAPSE = 'lp_admin_sidebar_collapsed';

    /* ---- Tema Claro / Escuro ---- */
    window.toggleTheme = function () {
        var current = document.documentElement.getAttribute('data-theme') || 'light';
        var next = (current === 'dark') ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('lp_theme', next);
        updateThemeIcon();
    };

    function updateThemeIcon() {
        var current = document.documentElement.getAttribute('data-theme') || 'light';
        var sun = document.getElementById('lpIconSun');
        var moon = document.getElementById('lpIconMoon');
        if (sun && moon) {
            sun.style.display = (current === 'dark') ? 'inline-flex' : 'none';
            moon.style.display = (current === 'dark') ? 'none' : 'inline-flex';
        }
    }

    /* ---- Sidebar Colapsável & Drawer Mobile ---- */
    window.toggleSidebarCollapse = function () {
        var collapsed = document.body.classList.toggle('sidebar-collapsed');
        try {
            localStorage.setItem(STORAGE_COLLAPSE, collapsed ? '1' : '0');
        } catch (e) {}
    };

    window.toggleMobileSidebar = function (forceOpen) {
        if (typeof forceOpen === 'boolean') {
            document.body.classList.toggle('sidebar-mobile-open', forceOpen);
        } else {
            document.body.classList.toggle('sidebar-mobile-open');
        }
    };

    window.toggleSidebarGroup = function (btn) {
        var group = btn.closest('.sidebar-group');
        if (group) {
            group.classList.toggle('open');
        }
    };

    /* ---- Toast Notifications ---- */
    function getToastContainer() {
        var el = document.getElementById('lpToastContainer');
        if (!el) {
            el = document.createElement('div');
            el.id = 'lpToastContainer';
            el.className = 'lp-toast-container';
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        return el;
    }

    window.LPAdmin = {
        toast: function (message, type, duration) {
            type = type || 'info';
            duration = duration || 3200;
            var container = getToastContainer();
            var toast = document.createElement('div');
            toast.className = 'lp-toast ' + type;
            toast.textContent = message;
            container.appendChild(toast);

            setTimeout(function () {
                toast.classList.add('leaving');
                setTimeout(function () {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 200);
            }, duration);
        },

        confirm: function (message, onConfirm, options) {
            options = options || {};
            var backdrop = document.getElementById('lpConfirmModal');
            if (!backdrop) {
                backdrop = document.createElement('div');
                backdrop.id = 'lpConfirmModal';
                backdrop.className = 'lp-modal-backdrop';
                backdrop.innerHTML =
                    '<div class="lp-modal-dialog" role="dialog" aria-modal="true">' +
                        '<div class="lp-modal-title" id="lpConfirmTitle">Confirmar ação</div>' +
                        '<div class="lp-modal-message" id="lpConfirmMsg"></div>' +
                        '<div class="lp-modal-actions">' +
                            '<button type="button" class="btn btn-secondary btn-sm" id="lpConfirmCancel">Cancelar</button>' +
                            '<button type="button" class="btn btn-sm" id="lpConfirmOk">Confirmar</button>' +
                        '</div>' +
                    '</div>';
                document.body.appendChild(backdrop);
            }

            var titleEl = document.getElementById('lpConfirmTitle');
            var msgEl = document.getElementById('lpConfirmMsg');
            var okBtn = document.getElementById('lpConfirmOk');
            var cancelBtn = document.getElementById('lpConfirmCancel');

            titleEl.textContent = options.title || 'Confirmar ação';
            msgEl.textContent = message || 'Tem certeza que deseja continuar?';
            okBtn.textContent = options.confirmText || 'Confirmar';
            okBtn.className = options.danger ? 'btn btn-sm btn-danger-soft' : 'btn btn-sm';

            function close() {
                backdrop.classList.remove('open');
                okBtn.onclick = null;
                cancelBtn.onclick = null;
            }

            cancelBtn.onclick = close;
            backdrop.onclick = function (e) {
                if (e.target === backdrop) close();
            };
            okBtn.onclick = function () {
                close();
                if (typeof onConfirm === 'function') onConfirm();
            };

            backdrop.classList.add('open');
            okBtn.focus();
        },

        copyText: function (text, successMsg) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    window.LPAdmin.toast(successMsg || 'Copiado para a área de transferência!', 'success');
                }).catch(function () {
                    fallbackCopy(text, successMsg);
                });
            } else {
                fallbackCopy(text, successMsg);
            }
        }
    };

    function fallbackCopy(text, successMsg) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            window.LPAdmin.toast(successMsg || 'Copiado!', 'success');
        } catch (e) {
            window.LPAdmin.toast('Não foi possível copiar.', 'error');
        }
        document.body.removeChild(ta);
    }

    /* ---- Interceptação de formulários com data-confirm ---- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute) return;
        var msg = form.getAttribute('data-confirm');
        if (msg && !form.dataset.confirmed) {
            e.preventDefault();
            window.LPAdmin.confirm(msg, function () {
                form.dataset.confirmed = '1';
                form.submit();
            }, {
                title: form.getAttribute('data-confirm-title') || 'Confirmar exclusão',
                confirmText: form.getAttribute('data-confirm-btn') || 'Sim, confirmar',
                danger: true
            });
        }
    });

    /* ---- Atalhos de teclado: Ctrl+K / '/' para busca e Esc para fechar ---- */
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            var search = document.querySelector('input[name="q"], input[type="search"]');
            if (search) {
                e.preventDefault();
                search.focus();
                search.select();
            }
        } else if (e.key === 'Escape') {
            document.body.classList.remove('sidebar-mobile-open');
            var modal = document.getElementById('lpConfirmModal');
            if (modal) modal.classList.remove('open');
        }
    });

    /* ---- Inicialização ---- */
    function init() {
        updateThemeIcon();
        try {
            if (localStorage.getItem(STORAGE_COLLAPSE) === '1' && window.innerWidth > 1024) {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
