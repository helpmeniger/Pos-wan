/* Niger Parcelles - utilitaires communs (aucune dépendance hors Bootstrap). */
(function () {
    'use strict';

    const cfgEl = document.getElementById('np-config');
    const config = cfgEl ? JSON.parse(cfgEl.textContent) : { base: '/', csrf: '', perms: [] };

    const NP = {
        config,
        can: (perm) => config.perms.includes(perm),
        url: (path) => config.base + path.replace(/^\//, ''),

        /** Échappement HTML pour tout contenu injecté dans le DOM. */
        esc(value) {
            return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        },

        fmt(n, d = 0) {
            return Number(n || 0).toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d });
        },

        fmtArea(m2) {
            return NP.fmt(m2, 2) + ' m² (' + NP.fmt(m2 / 10000, 4) + ' ha)';
        },

        /**
         * Appel d'API JSON avec jeton CSRF. Lève une erreur avec .data en cas d'échec.
         */
        async api(path, options = {}) {
            const opts = { method: options.method || 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin' };
            if (opts.method !== 'GET') {
                opts.headers['X-CSRF-Token'] = config.csrf;
            }
            if (options.body instanceof FormData) {
                opts.body = options.body;
            } else if (options.body !== undefined) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(options.body);
            }
            if (options.signal) opts.signal = options.signal;
            let res;
            try {
                res = await fetch(NP.url(path), opts);
            } catch (e) {
                if (e.name === 'AbortError') throw e;
                const err = new Error('Serveur injoignable. Vérifiez que WampServer est démarré.');
                err.status = 0;
                throw err;
            }
            let data = null;
            try { data = await res.json(); } catch (e) { data = null; }
            if (!res.ok || (data && data.ok === false)) {
                const err = new Error((data && data.error) || ('Erreur ' + res.status));
                err.status = res.status;
                err.data = data || {};
                if (res.status === 401) {
                    window.location.href = NP.url('login.php');
                }
                throw err;
            }
            return data;
        },

        toast(message, type = 'success') {
            const box = document.getElementById('npToasts');
            if (!box) { alert(message); return; }
            const el = document.createElement('div');
            el.className = 'toast align-items-center text-bg-' + (type === 'error' ? 'danger' : type) + ' border-0';
            el.setAttribute('role', type === 'error' ? 'alert' : 'status');
            el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fermer"></button></div>';
            el.querySelector('.toast-body').textContent = message;
            box.appendChild(el);
            const t = new bootstrap.Toast(el, { delay: type === 'error' ? 8000 : 4000 });
            t.show();
            el.addEventListener('hidden.bs.toast', () => el.remove());
        },

        debounce(fn, ms) {
            let t;
            return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
        },
    };
    window.NP = NP;

    // --- Recherche globale avec autocomplétion -------------------------------
    const input = document.getElementById('npGlobalSearch');
    const results = document.getElementById('npGlobalSearchResults');
    if (input && results) {
        let ctrl = null;
        const run = NP.debounce(async () => {
            const q = input.value.trim();
            if (q.length < 2) { results.hidden = true; return; }
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            try {
                const data = await NP.api('api/recherche.php?q=' + encodeURIComponent(q), { signal: ctrl.signal });
                if (!data.results.length) {
                    results.innerHTML = '<div class="list-group-item small text-muted">Aucun résultat</div>';
                } else {
                    results.innerHTML = data.results.map((r) =>
                        '<a class="list-group-item list-group-item-action small" href="' + NP.esc(NP.url(r.url)) + '">'
                        + '<i class="bi bi-' + NP.esc(r.icon) + ' me-1"></i><strong>' + NP.esc(r.label) + '</strong>'
                        + (r.sub ? '<div class="text-muted">' + NP.esc(r.sub) + '</div>' : '') + '</a>').join('');
                }
                results.hidden = false;
            } catch (e) {
                if (e.name !== 'AbortError') results.hidden = true;
            }
        }, 250);
        input.addEventListener('input', run);
        input.addEventListener('keydown', (e) => { if (e.key === 'Escape') results.hidden = true; });
        document.addEventListener('click', (e) => { if (!results.contains(e.target) && e.target !== input) results.hidden = true; });
    }

    // --- Confirmation des actions sensibles (data-confirm) -------------------
    document.addEventListener('submit', (e) => {
        const msg = e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            e.preventDefault();
        }
    });
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-confirm-click]');
        if (el && !window.confirm(el.getAttribute('data-confirm-click'))) {
            e.preventDefault();
        }
    });

    // --- Conservation temporaire des formulaires (data-autosave="clé") -------
    document.querySelectorAll('form[data-autosave]').forEach((form) => {
        const key = 'np-draft-' + form.getAttribute('data-autosave');
        try {
            const saved = JSON.parse(localStorage.getItem(key) || 'null');
            if (saved && saved.t > Date.now() - 7 * 86400000) {
                let restored = false;
                Object.entries(saved.v).forEach(([name, value]) => {
                    const field = form.elements.namedItem(name);
                    if (field && !field.value && field.type !== 'hidden' && field.type !== 'password' && field.type !== 'file') {
                        field.value = value;
                        restored = true;
                    }
                });
                if (restored) {
                    const note = document.createElement('div');
                    note.className = 'alert alert-info py-1 small';
                    note.textContent = 'Brouillon non enregistré restauré depuis ce navigateur.';
                    form.prepend(note);
                }
            }
        } catch (e) { /* stockage indisponible */ }
        const save = NP.debounce(() => {
            const v = {};
            Array.from(form.elements).forEach((f) => {
                if (f.name && f.name !== '_csrf' && f.type !== 'password' && f.type !== 'file' && f.type !== 'hidden') v[f.name] = f.value;
            });
            try { localStorage.setItem(key, JSON.stringify({ t: Date.now(), v })); } catch (e) { /* ignore */ }
        }, 500);
        form.addEventListener('input', save);
        form.addEventListener('submit', () => { try { localStorage.removeItem(key); } catch (e) { /* ignore */ } });
    });

    // --- Boutons d'impression -------------------------------------------------
    document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
})();
