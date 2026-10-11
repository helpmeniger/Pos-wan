/* Fiche parcelle : mini-carte (versions des limites) et recherche de propriétaires. */
(function () {
    'use strict';
    const NP = window.NP;

    // ---------------- Mini-carte
    const dataEl = document.getElementById('parcel-map-data');
    const mapEl = document.getElementById('miniMap');
    if (dataEl && mapEl && window.L) {
        const d = JSON.parse(dataEl.textContent);
        const map = L.map(mapEl, { scrollWheelZoom: false });
        const base = {};
        if (d.tiles.osm) base[d.tiles.osm.name] = L.tileLayer(d.tiles.osm.url, { attribution: d.tiles.osm.attribution, maxZoom: 19 }).addTo(map);
        if (d.tiles.satellite) base[d.tiles.satellite.name] = L.tileLayer(d.tiles.satellite.url, { attribution: d.tiles.satellite.attribution, maxZoom: 19 });
        L.control.layers(base, null, { collapsed: true }).addTo(map);
        L.control.scale({ imperial: false }).addTo(map);
        let current = null;
        let previous = null;
        const show = (version) => {
            const v = d.versions.find((x) => x.version === version) || d.versions[0];
            if (!v) return;
            if (current) map.removeLayer(current);
            if (previous) map.removeLayer(previous);
            current = L.geoJSON(v.geometry, { style: { color: '#e05206', weight: 3, fillOpacity: 0.25 } }).addTo(map);
            const prev = d.versions.find((x) => x.version === version - 1);
            if (prev) previous = L.geoJSON(prev.geometry, { style: { color: '#5c6670', weight: 2, dashArray: '4 4', fill: false } }).addTo(map).bindTooltip('Version ' + prev.version);
            map.fitBounds(current.getBounds(), { maxZoom: 19, padding: [20, 20] });
        };
        if (d.versions.length) show(d.versions[0].version);
        const sel = document.getElementById('versionSelect');
        if (sel) sel.addEventListener('change', () => show(parseInt(sel.value, 10)));
        // Recalcul de la taille quand l'onglet devient visible
        document.querySelectorAll('[data-bs-toggle="tab"]').forEach((t) => t.addEventListener('shown.bs.tab', () => map.invalidateSize()));
    }

    // ---------------- Autocomplétion des propriétaires
    document.querySelectorAll('[data-owner-search]').forEach((input) => {
        const hidden = document.getElementById(input.getAttribute('data-owner-search'));
        const list = document.createElement('div');
        list.className = 'list-group shadow np-autocomplete';
        list.hidden = true;
        input.parentNode.appendChild(list);
        let ctrl;
        input.addEventListener('input', NP.debounce(async () => {
            hidden.value = '';
            input.classList.remove('is-valid');
            const q = input.value.trim();
            if (q.length < 2) { list.hidden = true; return; }
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            try {
                const r = await NP.api('api/proprietaires.php?action=search&q=' + encodeURIComponent(q), { signal: ctrl.signal });
                list.innerHTML = r.owners.length ? r.owners.map((o) => '<button type="button" class="list-group-item list-group-item-action small" data-id="' + o.id + '" data-name="' + NP.esc(o.name) + '">'
                    + '<strong>' + NP.esc(o.name) + '</strong> <span class="text-muted">' + NP.esc(o.sub) + '</span></button>').join('')
                    : '<div class="list-group-item small text-muted">Aucun propriétaire trouvé.</div>';
                list.hidden = false;
            } catch (e) { if (e.name !== 'AbortError') list.hidden = true; }
        }, 250));
        list.addEventListener('click', (e) => {
            const b = e.target.closest('button[data-id]');
            if (!b) return;
            hidden.value = b.dataset.id;
            input.value = b.dataset.name;
            input.classList.add('is-valid');
            list.hidden = true;
        });
        input.form.addEventListener('submit', (e) => {
            if (input.value.trim() !== '' && !hidden.value) {
                e.preventDefault();
                NP.toast('Sélectionnez un propriétaire dans la liste proposée.', 'warning');
            }
        });
    });
})();
