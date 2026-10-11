/* DOSSIER ID — scripts d'interface (aucun script en ligne : compatible CSP stricte). */
(function () {
    'use strict';

    // Menu latéral (tablettes)
    document.querySelectorAll('[data-toggle-sidebar]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('sidebar').classList.toggle('open');
        });
    });

    // Lignes de tableau cliquables
    document.querySelectorAll('tr[data-href]').forEach(function (tr) {
        tr.addEventListener('click', function (ev) {
            if (ev.target.closest('a, button, input, form')) { return; }
            window.location.href = tr.getAttribute('data-href');
        });
    });

    // Impression
    document.querySelectorAll('[data-print]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });

    // Fenêtres de confirmation (avec saisie facultative d'un motif)
    var modalEl = document.getElementById('confirmModal');
    var modal = modalEl && window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.confirmed === '1') { return; }
            ev.preventDefault();
            var message = form.getAttribute('data-confirm');
            var promptLabel = form.getAttribute('data-prompt');
            var field = form.getAttribute('data-prompt-field');
            if (!modal) {
                var val = promptLabel ? window.prompt(message + '\n\n' + promptLabel) : (window.confirm(message) ? '' : null);
                if (val === null) { return; }
                if (field) { form.querySelector('[name="' + field + '"]').value = val; }
                form.dataset.confirmed = '1';
                form.submit();
                return;
            }
            var body = document.getElementById('confirmModalBody');
            body.textContent = '';
            var p = document.createElement('p');
            p.textContent = message;
            body.appendChild(p);
            var input = null;
            if (promptLabel) {
                var label = document.createElement('label');
                label.className = 'form-label';
                label.textContent = promptLabel;
                input = document.createElement('input');
                input.className = 'form-control';
                input.maxLength = 255;
                input.autocomplete = 'off';
                body.appendChild(label);
                body.appendChild(input);
            }
            var ok = document.getElementById('confirmModalOk');
            var handler = function () {
                if (input) {
                    if (input.value.trim().length < 5 && field !== 'confirm') {
                        input.classList.add('is-invalid');
                        return;
                    }
                    form.querySelector('[name="' + field + '"]').value = input.value.trim();
                }
                ok.removeEventListener('click', handler);
                form.dataset.confirmed = '1';
                modal.hide();
                form.submit();
            };
            ok.addEventListener('click', handler);
            modalEl.addEventListener('hidden.bs.modal', function () { ok.removeEventListener('click', handler); }, { once: true });
            modal.show();
            if (input) { setTimeout(function () { input.focus(); }, 300); }
        });
    });

    // Minuteur de session : redirection automatique à l'expiration côté serveur
    var idle = parseInt(document.body.getAttribute('data-idle-seconds') || '0', 10);
    var timerEl = document.getElementById('session-timer');
    if (idle > 0 && timerEl) {
        var deadline = Date.now() + idle * 1000;
        var tick = function () {
            var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
            var m = Math.floor(left / 60), s = left % 60;
            timerEl.textContent = 'Session : ' + m + ':' + (s < 10 ? '0' : '') + s;
            timerEl.classList.toggle('text-danger', left <= 60);
            if (left <= 0) {
                window.location.href = document.body.getAttribute('data-logout-url');
                return;
            }
            setTimeout(tick, 1000);
        };
        tick();
    }

    // Indication du statut procédural
    document.querySelectorAll('[data-status-select]').forEach(function (sel) {
        var hint = document.getElementById('status-hint');
        var update = function () {
            var opt = sel.options[sel.selectedIndex];
            if (hint && opt) { hint.textContent = opt.getAttribute('data-hint') || ''; }
        };
        sel.addEventListener('change', update);
        update();
    });

    // Rattachement d'un document à un passage ou une procédure
    document.querySelectorAll('[data-doc-link]').forEach(function (sel) {
        var form = sel.closest('form');
        sel.addEventListener('change', function () {
            var parts = sel.value.split(':');
            form.querySelector('[name="visit_id"]').value = parts[0] === 'visit' ? parts[1] : '';
            form.querySelector('[name="procedure_id"]').value = parts[0] === 'procedure' ? parts[1] : '';
        });
    });

    // Aperçu local de la photographie avant import
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        var img = document.querySelector(input.getAttribute('data-preview'));
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f || !img || !/^image\/(jpeg|png)$/.test(f.type)) { if (img) { img.classList.add('d-none'); } return; }
            var reader = new FileReader();
            reader.onload = function (e) { img.src = e.target.result; img.classList.remove('d-none'); };
            reader.readAsDataURL(f);
        });
    });

    // Comparaison visuelle : zoom et déplacement synchronisés, niveaux de gris, grille
    var frames = Array.prototype.slice.call(document.querySelectorAll('[data-compare-frame]'));
    if (frames.length) {
        var state = { zoom: 1, x: 0, y: 0 };
        var apply = function () {
            frames.forEach(function (fr) {
                var img = fr.querySelector('[data-compare-img]');
                if (img) { img.style.transform = 'translate(' + state.x + 'px,' + state.y + 'px) scale(' + state.zoom + ')'; }
            });
        };
        var zoom = document.querySelector('[data-compare-zoom]');
        if (zoom) {
            zoom.addEventListener('input', function () {
                state.zoom = parseFloat(zoom.value);
                if (state.zoom === 1) { state.x = 0; state.y = 0; }
                apply();
            });
        }
        var gray = document.querySelector('[data-compare-gray]');
        if (gray) { gray.addEventListener('change', function () { frames.forEach(function (f) { f.classList.toggle('gray', gray.checked); }); }); }
        var grid = document.querySelector('[data-compare-grid]');
        if (grid) { grid.addEventListener('change', function () { frames.forEach(function (f) { f.classList.toggle('grid', grid.checked); }); }); }
        frames.forEach(function (fr) {
            var drag = null;
            fr.addEventListener('pointerdown', function (e) { if (state.zoom > 1) { drag = { x: e.clientX - state.x, y: e.clientY - state.y }; fr.setPointerCapture(e.pointerId); } });
            fr.addEventListener('pointermove', function (e) { if (drag) { state.x = e.clientX - drag.x; state.y = e.clientY - drag.y; apply(); } });
            fr.addEventListener('pointerup', function () { drag = null; });
            fr.addEventListener('pointercancel', function () { drag = null; });
        });
        document.querySelectorAll('[data-compare-select]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var img = btn.closest('.card').querySelector('[data-compare-img]');
                if (img) { img.src = btn.getAttribute('data-compare-select'); }
            });
        });
    }
})();
