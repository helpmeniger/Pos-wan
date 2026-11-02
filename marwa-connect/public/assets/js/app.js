/* ==========================================================================
   MARWA CONNECT — script principal (aucun script inline : CSP stricte)
   ========================================================================== */
(function () {
    'use strict';

    var meta = function (name) { var m = document.querySelector('meta[name="' + name + '"]'); return m ? m.content : ''; };
    var BASE = meta('app-base');

    /** Client API interne : session + jeton CSRF, réponses JSON. */
    var api = {
        request: function (method, path, body) {
            var opts = {
                method: method,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': meta('csrf-token') }
            };
            if (body !== undefined) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            return fetch(BASE + path, opts).then(function (res) {
                if (res.status === 401) { window.location.href = BASE + '/login'; throw new Error('Session expirée'); }
                return res.json().then(function (data) {
                    if (!res.ok) { var err = new Error(data.message || 'Erreur ' + res.status); err.data = data; throw err; }
                    return data;
                });
            });
        },
        get: function (p) { return api.request('GET', p); },
        post: function (p, b) { return api.request('POST', p, b || {}); }
    };
    window.MarwaApi = api;

    /** Notifications toast. */
    function toast(message, type) {
        var box = document.getElementById('mc-toasts');
        if (!box || !window.bootstrap) { return; }
        var el = document.createElement('div');
        el.className = 'toast align-items-center border-0 text-bg-' + (type || 'dark');
        el.setAttribute('role', 'status');
        var wrap = document.createElement('div');
        wrap.className = 'd-flex';
        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close btn-close-white me-2 m-auto';
        close.setAttribute('data-bs-dismiss', 'toast');
        wrap.appendChild(body); wrap.appendChild(close); el.appendChild(wrap); box.appendChild(el);
        new bootstrap.Toast(el, { delay: 4000 }).show();
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    }
    window.MarwaToast = toast;

    document.addEventListener('DOMContentLoaded', function () {
        var body = document.body;

        /* ---- Navigation latérale (droite) ---- */
        var isDesktop = function () { return window.matchMedia('(min-width: 992px)').matches; };
        var toggle = document.getElementById('mc-sidebar-toggle');
        if (toggle) {
            toggle.addEventListener('click', function () {
                if (isDesktop()) {
                    body.classList.toggle('mc-sidebar-hidden');
                } else {
                    body.classList.toggle('mc-sidebar-open');
                }
            });
        }
        document.querySelectorAll('[data-mc-sidebar-close]').forEach(function (el) {
            el.addEventListener('click', function () { body.classList.remove('mc-sidebar-open'); });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { body.classList.remove('mc-sidebar-open'); } });
        var collapse = document.getElementById('mc-sidebar-collapse');
        if (collapse) {
            collapse.addEventListener('click', function () {
                body.classList.toggle('mc-sidebar-collapsed');
                try { localStorage.setItem('mc-sidebar', body.classList.contains('mc-sidebar-collapsed') ? 'collapsed' : 'expanded'); } catch (e) { /* ignore */ }
            });
        }

        /* ---- Thème clair / sombre ---- */
        var themeBtn = document.getElementById('mc-theme-toggle');
        var syncThemeIcon = function () {
            if (!themeBtn) { return; }
            var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            themeBtn.querySelector('i').className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars');
        };
        syncThemeIcon();
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                document.documentElement.setAttribute('data-theme-pref', next);
                try { localStorage.setItem('mc-theme', next); } catch (e) { /* ignore */ }
                syncThemeIcon();
                api.post('/api/preferences/theme', { theme: next }).catch(function () { /* préférence locale conservée */ });
            });
        }

        /* ---- Confirmation des actions sensibles (data-confirm) ---- */
        document.addEventListener('submit', function (e) {
            var form = e.target;
            var msg = form.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
            var btn = form.querySelector('[type="submit"]');
            if (btn && !form.hasAttribute('data-no-lock')) {
                // Évite les doubles soumissions (doubles ventes, doubles créations).
                setTimeout(function () { btn.disabled = true; }, 0);
            }
        }, true);

        /* ---- Saisie d'une valeur avant envoi (data-prompt) ---- */
        document.querySelectorAll('form[data-prompt]').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                var target = form.querySelector('[data-prompt-target]');
                if (!target || target.value) { return; }
                var value = window.prompt(form.getAttribute('data-prompt'));
                if (!value) { e.preventDefault(); e.stopImmediatePropagation(); return; }
                target.value = value;
            });
        });

        /* ---- Afficher / masquer un mot de passe ---- */
        document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-toggle-password'));
                if (!input) { return; }
                input.type = input.type === 'password' ? 'text' : 'password';
                btn.querySelector('i').className = 'bi ' + (input.type === 'password' ? 'bi-eye' : 'bi-eye-slash');
            });
        });

        /* ---- Cases « tout cocher » par module (permissions) ---- */
        document.querySelectorAll('[data-check-all]').forEach(function (master) {
            var group = document.querySelectorAll('[data-check-group="' + master.getAttribute('data-check-all') + '"]');
            var sync = function () {
                var checked = Array.prototype.filter.call(group, function (c) { return c.checked; }).length;
                master.checked = checked === group.length;
                master.indeterminate = checked > 0 && checked < group.length;
            };
            master.addEventListener('change', function () { group.forEach(function (c) { c.checked = master.checked; }); });
            group.forEach(function (c) { c.addEventListener('change', sync); });
            sync();
        });

        /* ---- Sélecteur du routeur actif ---- */
        var routerSwitch = document.getElementById('mc-router-switch');
        if (routerSwitch) {
            routerSwitch.addEventListener('change', function () {
                api.post('/api/preferences/router', { router_id: parseInt(routerSwitch.value, 10) || 0 })
                    .then(function () { window.location.reload(); })
                    .catch(function (err) { toast(err.message, 'danger'); });
            });
        }

        /* ---- Indicateur de connexion temps réel ---- */
        var conn = document.getElementById('mc-conn');
        var setConn = function (state, label, title) {
            if (!conn) { return; }
            conn.setAttribute('data-state', state);
            conn.title = title || label;
            var l = conn.querySelector('.mc-conn-label');
            if (l) { l.textContent = label; }
        };
        var ping = function () {
            if (!conn) { return; }
            if (!navigator.onLine) { setConn('offline', 'Hors ligne', 'Le poste n\'est pas connecté au réseau.'); return; }
            api.get('/api/ping').then(function (d) {
                if (!d.database) { setConn('offline', 'Base indisponible', 'Le serveur ne joint pas la base de données.'); return; }
                var r = d.routers || { total: 0, online: 0, offline: 0 };
                if (r.total === 0) { setConn('online', 'Serveur OK', 'Serveur en ligne (' + d.latency_ms + ' ms). Aucun routeur configuré.'); return; }
                var state = r.offline > 0 ? 'degraded' : 'online';
                setConn(state, r.online + '/' + r.total + ' routeurs', 'Serveur en ligne — ' + r.online + ' routeur(s) en ligne, ' + r.offline + ' hors ligne.');
            }).catch(function () { setConn('offline', 'Serveur injoignable', 'Impossible de joindre le serveur MARWA CONNECT.'); });
        };
        ping();
        setInterval(ping, 30000);
        window.addEventListener('online', ping);
        window.addEventListener('offline', ping);

        /* ---- Notifications ---- */
        var notifCount = document.getElementById('mc-notif-count');
        var notifList = document.getElementById('mc-notif-list');
        var levelIcon = { info: 'info-circle text-info', success: 'check-circle text-success', warning: 'exclamation-triangle text-warning', danger: 'exclamation-octagon text-danger' };
        var renderNotifs = function (d) {
            if (notifCount) {
                notifCount.textContent = d.unread > 99 ? '99+' : String(d.unread);
                notifCount.classList.toggle('d-none', d.unread === 0);
            }
            if (!notifList) { return; }
            notifList.textContent = '';
            if (!d.items.length) {
                var empty = document.createElement('div');
                empty.className = 'text-muted small p-3 text-center';
                empty.textContent = 'Aucune notification.';
                notifList.appendChild(empty);
                return;
            }
            d.items.forEach(function (n) {
                var a = document.createElement(n.link ? 'a' : 'div');
                a.className = 'mc-notif-item' + (n.is_read ? '' : ' unread');
                if (n.link) { a.href = BASE + n.link; }
                var t = document.createElement('div');
                t.className = 'fw-semibold small';
                var i = document.createElement('i');
                i.className = 'bi bi-' + (levelIcon[n.level] || levelIcon.info) + ' me-1';
                t.appendChild(i);
                t.appendChild(document.createTextNode(n.title));
                var m = document.createElement('div');
                m.className = 'small';
                m.textContent = n.message;
                var s = document.createElement('small');
                s.textContent = new Date(n.created_at.replace(' ', 'T')).toLocaleString('fr-FR');
                a.appendChild(t); a.appendChild(m); a.appendChild(s);
                notifList.appendChild(a);
            });
        };
        var loadNotifs = function () {
            if (!notifCount) { return; }
            api.get('/api/notifications').then(renderNotifs).catch(function () { /* silencieux */ });
        };
        loadNotifs();
        setInterval(loadNotifs, 60000);
        var readAll = document.getElementById('mc-notif-readall');
        if (readAll) {
            readAll.addEventListener('click', function (e) {
                e.preventDefault();
                api.post('/api/notifications/read', {}).then(loadNotifs);
            });
        }
    });
})();
