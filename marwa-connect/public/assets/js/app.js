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
            if (btn && !form.hasAttribute('data-no-lock') && !form.target && (form.method || '').toLowerCase() === 'post') {
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

        /* ---- Envoi automatique des filtres (data-autosubmit) ---- */
        document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
        });
        document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

        /* ---- Test de connexion RouterOS (formulaire routeur) ---- */
        var testBtn = document.querySelector('[data-router-test]');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var form = testBtn.closest('form');
                var out = document.getElementById('router-test-result');
                var fd = new FormData(form);
                var body = {};
                ['host', 'api_port', 'api_ssl_port', 'api_username', 'api_password', 'router_id'].forEach(function (k) { body[k] = fd.get(k) || ''; });
                body.use_ssl = fd.get('use_ssl') ? 1 : 0;
                out.className = 'small mt-2 text-muted'; out.textContent = 'Connexion en cours…';
                testBtn.disabled = true;
                api.post('/routers/test', body).then(function (d) {
                    out.className = 'small mt-2 text-success fw-semibold'; out.textContent = '✓ ' + d.message;
                    var model = document.getElementById('model');
                    if (model && !model.value && d.info && d.info.model) { model.value = d.info.model; }
                }).catch(function (e) { out.className = 'small mt-2 text-danger fw-semibold'; out.textContent = '✗ ' + e.message; })
                  .then(function () { testBtn.disabled = false; });
            });
        }

        /* ---- Caisse ---- */
        var pos = document.getElementById('pos-form');
        if (pos) {
            var offerInput = document.getElementById('pos-offer');
            var qty = document.getElementById('quantity');
            var disc = document.getElementById('discount');
            var method = document.getElementById('payment_method_id');
            var refWrap = document.getElementById('pos-ref-wrap');
            var submit = document.getElementById('pos-submit');
            var current = null;
            var fmt = function (v) { return new Intl.NumberFormat('fr-FR').format(v) + ' FCFA'; };
            var refresh = function () {
                var isCash = method && method.selectedOptions[0] && method.selectedOptions[0].getAttribute('data-type') === 'cash';
                if (refWrap) { refWrap.classList.toggle('d-none', !!isCash); }
                if (!current) { submit.disabled = true; return; }
                var q = Math.max(1, parseInt(qty.value, 10) || 1);
                var stock = parseInt(current.getAttribute('data-stock'), 10);
                var total = q * parseFloat(current.getAttribute('data-price')) - (disc ? (parseFloat(disc.value) || 0) : 0);
                document.getElementById('pos-total').textContent = fmt(Math.max(0, total));
                document.getElementById('pos-summary').textContent = q + ' × ' + current.getAttribute('data-name') + (q > stock ? ' — stock insuffisant (' + stock + ')' : '');
                submit.disabled = q > stock || stock === 0;
            };
            document.querySelectorAll('[data-pos-offer]').forEach(function (b) {
                b.addEventListener('click', function () {
                    document.querySelectorAll('[data-pos-offer]').forEach(function (x) { x.classList.remove('active'); });
                    b.classList.add('active'); current = b; offerInput.value = b.getAttribute('data-pos-offer'); qty.value = 1; refresh();
                });
            });
            document.querySelectorAll('[data-qty]').forEach(function (b) {
                b.addEventListener('click', function () { qty.value = Math.max(1, (parseInt(qty.value, 10) || 1) + parseInt(b.getAttribute('data-qty'), 10)); refresh(); });
            });
            [qty, disc, method].forEach(function (el) { if (el) { el.addEventListener('input', refresh); el.addEventListener('change', refresh); } });
            refresh();
        }

        /* ---- Espace revendeur : remise d'un ticket ---- */
        var portalForm = document.getElementById('portal-sell-form');
        document.querySelectorAll('[data-portal-sell]').forEach(function (b) {
            b.addEventListener('click', function () {
                var phone = window.prompt('Ticket ' + b.getAttribute('data-label') + ' — numéro du client (facultatif) :', '');
                if (phone === null) { return; }
                portalForm.ticket_id.value = b.getAttribute('data-portal-sell');
                portalForm.phone.value = phone;
                portalForm.submit();
            });
        });

        /* ---- Optimus IA ---- */
        var ai = document.getElementById('mc-ai');
        if (ai) {
            var chat = document.getElementById('mc-chat');
            var input = document.getElementById('mc-ai-input');
            var confirmBase = ai.getAttribute('data-confirm-base').replace(BASE, '');
            var addTable = function (box, table) {
                if (!table || !table.rows || !table.rows.length) { return; }
                var wrap = document.createElement('div'); wrap.className = 'table-responsive';
                var t = document.createElement('table'); t.className = 'table table-sm table-bordered mb-0';
                var tr = document.createElement('tr');
                table.head.forEach(function (h) { var th = document.createElement('th'); th.textContent = h; tr.appendChild(th); });
                var thead = document.createElement('thead'); thead.appendChild(tr); t.appendChild(thead);
                var tb = document.createElement('tbody');
                table.rows.forEach(function (row) {
                    var r = document.createElement('tr');
                    row.forEach(function (c) { var td = document.createElement('td'); td.textContent = c === null ? '—' : String(c); r.appendChild(td); });
                    tb.appendChild(r);
                });
                t.appendChild(tb); wrap.appendChild(t); box.appendChild(wrap);
            };
            var addActions = function (box, id) {
                var bar = document.createElement('div'); bar.className = 'd-flex gap-2 mt-2';
                [['confirm', 'Confirmer et exécuter', 'btn-primary'], ['reject', 'Annuler', 'btn-outline-secondary']].forEach(function (a) {
                    var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm ' + a[2]; b.textContent = a[1];
                    b.addEventListener('click', function () {
                        bar.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
                        if (a[0] === 'confirm') { b.textContent = 'Exécution…'; }
                        api.post(confirmBase + '/' + id, { decision: a[0] }).then(function (d) { add('assistant', d.text); bar.remove(); })
                            .catch(function (e) { add('assistant', e.message); bar.remove(); });
                    });
                    bar.appendChild(b);
                });
                box.appendChild(bar);
            };
            var add = function (role, text, extra) {
                var m = document.createElement('div'); m.className = 'mc-msg ' + role; m.textContent = text;
                if (extra) { addTable(m, extra.table); if (extra.action_id) { addActions(m, extra.action_id); } if (extra.engine && extra.engine !== 'local') { var meta = document.createElement('div'); meta.className = 'mc-msg-meta'; meta.textContent = 'Modèle : ' + extra.engine; m.appendChild(meta); } }
                chat.appendChild(m); chat.scrollTop = chat.scrollHeight; return m;
            };
            chat.querySelectorAll('[data-json]').forEach(function (m) { try { addTable(m, JSON.parse(m.getAttribute('data-json')).table); } catch (e) { /* ignore */ } });
            chat.querySelectorAll('[data-pending]').forEach(function (m) { addActions(m, m.getAttribute('data-pending')); });
            chat.scrollTop = chat.scrollHeight;
            var send = function (q) {
                if (!q) { return; }
                add('user', q); input.value = '';
                var wait = add('assistant', '…');
                api.post(ai.getAttribute('data-ask').replace(BASE, ''), { question: q }).then(function (d) { wait.remove(); add('assistant', d.text, d); })
                    .catch(function (e) { wait.textContent = e.message; });
            };
            document.getElementById('mc-ai-form').addEventListener('submit', function (e) { e.preventDefault(); send(input.value.trim()); });
            document.querySelectorAll('[data-suggest]').forEach(function (b) { b.addEventListener('click', function () { send(b.getAttribute('data-suggest')); }); });
        }

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
