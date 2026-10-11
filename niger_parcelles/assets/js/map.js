/* Niger Parcelles - carte foncière (Leaflet + Leaflet-Geoman + Turf). */
(function () {
    'use strict';

    const NP = window.NP;
    const C = JSON.parse(document.getElementById('map-config').textContent);
    const $ = (id) => document.getElementById(id);

    const STATUS_STYLE = {
        brouillon: { color: '#6c757d', fillColor: '#adb5bd' },
        soumise: { color: '#0277bd', fillColor: '#4fc3f7' },
        en_verification: { color: '#ef6c00', fillColor: '#ffb74d' },
        verifiee: { color: '#0d8b3e', fillColor: '#66bb6a' },
        anomalie: { color: '#c62828', fillColor: '#ef5350' },
        rejetee: { color: '#212529', fillColor: '#495057' },
    };
    const DRAFT_STYLE = { color: '#e05206', weight: 3, dashArray: '6 4', fillColor: '#e05206', fillOpacity: 0.15 };

    // ------------------------------------------------------------------ Carte
    const map = L.map('map', { zoomControl: true, preferCanvas: false, worldCopyJump: false })
        .setView(C.center, C.zoom);
    const nigerBounds = L.latLngBounds([C.bounds.min_lat, C.bounds.min_lng], [C.bounds.max_lat, C.bounds.max_lng]);

    // Fonds de carte + indicateur de disponibilité
    let tileErrors = 0;
    let tileLoads = 0;
    const updateTileIndicator = NP.debounce(() => {
        const bad = tileErrors > 0 && tileErrors >= tileLoads;
        $('tileIndicator').classList.toggle('err', bad);
        $('tileText').textContent = bad ? 'Fond de carte indisponible (connexion Internet ?)' : 'Fond de carte disponible';
        tileErrors = 0;
        tileLoads = 0;
    }, 1200);
    const makeTiles = (t) => {
        const layer = L.tileLayer(t.url, { attribution: t.attribution, maxZoom: t.maxZoom || 19, crossOrigin: true });
        layer.on('tileerror', () => { tileErrors++; updateTileIndicator(); });
        layer.on('tileload', () => { tileLoads++; updateTileIndicator(); });
        return layer;
    };
    const baseLayers = {};
    const overlays = {};
    const osm = C.tiles.osm ? makeTiles(C.tiles.osm) : null;
    if (osm) { baseLayers[C.tiles.osm.name] = osm; osm.addTo(map); }
    if (C.tiles.satellite) {
        const sat = makeTiles(C.tiles.satellite);
        baseLayers[C.tiles.satellite.name] = sat;
        if (C.tiles.labels) {
            baseLayers['Hybride (satellite + libellés)'] = L.layerGroup([makeTiles(C.tiles.satellite), makeTiles(C.tiles.labels)]);
            overlays[C.tiles.labels.name] = makeTiles(C.tiles.labels);
        }
    }

    // Couches de données
    const parcelsLayer = L.featureGroup().addTo(map);
    const adminLayer = L.geoJSON(null, {
        style: { color: '#5c3d99', weight: 2, dashArray: '4 4', fill: false },
        onEachFeature: (f, l) => l.bindTooltip(NP.esc(f.properties.name) + ' (' + NP.esc(f.properties.type_label) + ')', { sticky: true }),
        pmIgnore: true,
    });
    const highlightLayer = L.featureGroup().addTo(map);
    const measureLayer = L.featureGroup().addTo(map);
    overlays['Parcelles enregistrées'] = parcelsLayer;
    overlays['Limites administratives importées'] = adminLayer;
    overlays['Mesures'] = measureLayer;

    L.control.layers(baseLayers, overlays, { position: 'topright', collapsed: true }).addTo(map);
    L.control.scale({ imperial: false, position: 'bottomright' }).addTo(map);

    // Légende
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = () => {
        const div = L.DomUtil.create('div', 'map-legend');
        div.innerHTML = '<strong>Légende</strong><br>' + Object.entries(STATUS_STYLE).map(([k, s]) =>
            '<span class="sw" style="border-color:' + s.color + ';background:' + s.fillColor + '55"></span>' + NP.esc(C.labels.verification_status[k])).join('<br>')
            + '<br><span class="sw" style="border-color:#c62828;border-style:dashed;background:transparent"></span>Chevauchement à vérifier'
            + '<br><span class="sw" style="border-color:#5c3d99;border-style:dashed;background:transparent"></span>Limite administrative'
            + '<br><span class="sw" style="border-color:#e05206;border-style:dashed;background:#e0520622"></span>Parcelle en cours de saisie';
        L.DomEvent.disableClickPropagation(div);
        return div;
    };
    legend.addTo(map);

    // Coordonnées du curseur
    map.on('mousemove', (e) => {
        $('cursorCoords').textContent = 'Lat ' + e.latlng.lat.toFixed(6) + ' · Lng ' + e.latlng.lng.toFixed(6);
    });

    // Limites administratives (si importées)
    if (C.hasAdminBoundaries) {
        NP.api('api/admin_areas.php?action=geojson').then((fc) => {
            adminLayer.addData(fc);
            adminLayer.addTo(map);
        }).catch(() => {});
    }

    // ------------------------------------------------------------- Parcelles
    let filters = {};
    let loadCtrl = null;
    let selectedId = null;
    let editing = null; // { mode: 'create'|'edit', layer, parcelId, undo: [] }

    function parcelStyle(p) {
        const s = STATUS_STYLE[p.status] || STATUS_STYLE.brouillon;
        return {
            color: p.overlap ? '#c62828' : s.color,
            weight: p.id === selectedId ? 4 : 2,
            dashArray: p.overlap ? '5 4' : null,
            fillColor: s.fillColor,
            fillOpacity: p.survey === 'indicative' ? 0.15 : 0.35,
        };
    }

    async function loadParcels() {
        if (loadCtrl) loadCtrl.abort();
        loadCtrl = new AbortController();
        const b = map.getBounds();
        const q = new URLSearchParams({
            action: 'list', west: b.getWest().toFixed(6), south: b.getSouth().toFixed(6),
            east: b.getEast().toFixed(6), north: b.getNorth().toFixed(6), zoom: map.getZoom(),
        });
        Object.entries(filters).forEach(([k, v]) => { if (v) q.set(k, v); });
        $('mapLoading').hidden = false;
        try {
            const fc = await NP.api('api/parcelles.php?' + q.toString(), { signal: loadCtrl.signal });
            renderParcels(fc);
        } catch (e) {
            if (e.name !== 'AbortError') NP.toast('Chargement des parcelles impossible : ' + e.message, 'error');
        } finally {
            $('mapLoading').hidden = true;
        }
    }

    function renderParcels(fc) {
        parcelsLayer.clearLayers();
        const showLabels = map.getZoom() >= 16;
        fc.features.forEach((f) => {
            if (editing && editing.parcelId === f.properties.id) return; // en cours de modification
            let layer;
            if (f.geometry.type === 'Point') {
                const s = STATUS_STYLE[f.properties.status] || STATUS_STYLE.brouillon;
                layer = L.circleMarker([f.geometry.coordinates[1], f.geometry.coordinates[0]], {
                    radius: 6, color: f.properties.overlap ? '#c62828' : s.color, fillColor: s.fillColor, fillOpacity: 0.9, weight: 2, pmIgnore: true,
                });
            } else {
                layer = L.geoJSON(f, { style: () => parcelStyle(f.properties), snapIgnore: false });
                if (showLabels) {
                    layer.bindTooltip(NP.esc(f.properties.ref), { permanent: true, direction: 'center', className: 'parcel-label' });
                }
            }
            layer.feature = f;
            layer.on('click', (ev) => {
                if (editing || measuring) return;
                L.DomEvent.stopPropagation(ev);
                openParcel(f.properties.id, false);
            });
            if (!showLabels) layer.bindTooltip(NP.esc(f.properties.ref) + (f.properties.demo ? ' (démo)' : ''), { sticky: true });
            parcelsLayer.addLayer(layer);
        });
        if (fc.truncated) {
            NP.toast(fc.features.length + ' parcelles affichées sur ' + fc.total + ' dans cette zone : zoomez pour tout voir.', 'warning');
        }
    }

    map.on('moveend', NP.debounce(loadParcels, 300));

    // ---------------------------------------------------------------- Panneau
    const panel = $('mapPanel');
    function showPanel(title, html) {
        $('panelTitle').textContent = title;
        $('panelBody').innerHTML = html;
        panel.hidden = false;
        setTimeout(() => map.invalidateSize(), 50);
    }
    function hidePanel() {
        panel.hidden = true;
        selectedId = null;
        highlightLayer.clearLayers();
        setTimeout(() => map.invalidateSize(), 50);
    }
    $('panelClose').addEventListener('click', () => {
        if (editing && !window.confirm('Abandonner la saisie en cours ? Les modifications non enregistrées seront perdues.')) return;
        stopEditing();
        hidePanel();
    });

    const dl = (rows) => '<dl>' + rows.filter((r) => r[1] !== null && r[1] !== undefined && r[1] !== '')
        .map((r) => '<dt>' + NP.esc(r[0]) + '</dt><dd>' + (r[2] ? r[1] : NP.esc(r[1])) + '</dd>').join('') + '</dl>';

    async function openParcel(id, zoomTo) {
        selectedId = id;
        showPanel('Chargement…', '<div class="text-center p-4"><span class="spinner-border"></span></div>');
        let p;
        try {
            p = (await NP.api('api/parcelles.php?action=get&id=' + id)).parcel;
        } catch (e) {
            showPanel('Erreur', '<div class="alert alert-danger">' + NP.esc(e.message) + '</div>');
            return;
        }
        highlightLayer.clearLayers();
        if (p.geometry) {
            const hl = L.geoJSON(p.geometry, { style: { color: '#ffeb3b', weight: 5, fill: false }, pmIgnore: true, interactive: false });
            highlightLayer.addLayer(hl);
            if (zoomTo) map.fitBounds(hl.getBounds(), { maxZoom: 18, padding: [40, 40] });
        }
        const a = p.areas;
        let html = '';
        if (p.is_demo) html += '<div class="alert alert-warning py-1 small mb-2"><i class="bi bi-exclamation-triangle"></i> Donnée de DÉMONSTRATION fictive.</div>';
        if (p.has_overlap_warning) html += '<div class="alert alert-danger py-1 small mb-2"><i class="bi bi-intersect"></i> Chevauchement potentiel à vérifier.</div>';
        html += '<div class="mb-2"><span class="ref fs-6">' + NP.esc(p.parcel_reference) + '</span> '
            + '<span class="badge text-bg-light border">' + NP.esc(p.verification_status_label) + '</span></div>';
        html += dl([
            ['Réf. cadastrale', p.cadastral_reference || 'Non renseignée'],
            ['Région', a.region], ['Département', a.departement], ['Commune', a.commune], ['Quartier', a.quartier], ['Village', a.village],
            ['Localisation', p.locality_description],
            ['Catégorie', p.category_label],
            ['Superficie', NP.fmtArea(p.area_m2)],
            ['Périmètre', NP.fmt(p.perimeter_m, 2) + ' m'],
            ['Centre', p.centroid[0].toFixed(6) + ', ' + p.centroid[1].toFixed(6)],
            ['Délimitation', p.survey_level_label],
            ['Situation déclarée', p.declared_status_label],
            ['Titre foncier', p.title_reference],
            ['Créée le', new Date(p.created_at.replace(' ', 'T')).toLocaleDateString('fr-FR')],
            ['Agent', p.created_by_name],
        ]);
        if (p.owners) {
            html += '<h6 class="mt-3">Propriétaire(s)</h6>';
            html += p.owners.length ? '<ul class="list-unstyled small mb-0">' + p.owners.map((o) =>
                '<li class="mb-1"><i class="bi bi-person"></i> <strong>' + NP.esc(o.name) + '</strong> — ' + NP.esc(o.role)
                + (o.share !== null ? ' (' + NP.fmt(o.share, 2) + ' %)' : '') + '<br><span class="text-muted">' + NP.esc(o.status) + '</span></li>').join('') + '</ul>'
                : '<div class="small text-muted">Aucun propriétaire renseigné.</div>';
        } else {
            html += '<div class="small text-muted mt-2">' + p.owner_count + ' propriétaire(s) associé(s) — détails réservés aux profils autorisés.</div>';
        }
        if (p.overlaps.length) {
            html += '<h6 class="mt-3">Chevauchements</h6><ul class="small">' + p.overlaps.map((o) =>
                '<li><a href="#" data-open="' + o.other_id + '">' + NP.esc(o.other_reference) + '</a> — ' + NP.esc(o.status_label) + '</li>').join('') + '</ul>';
        }
        html += '<div class="legal-notice mt-3">Fiche de gestion : elle ne constitue pas un titre foncier officiel.</div>';
        html += '<div class="d-grid gap-2 mt-3">'
            + '<a class="btn btn-primary btn-sm" href="' + NP.esc(NP.url('parcelle_details.php?id=' + p.id)) + '"><i class="bi bi-file-text"></i> Ouvrir la fiche complète</a>'
            + (p.can_edit ? '<button class="btn btn-outline-primary btn-sm" id="btnEditGeom"><i class="bi bi-vector-pen"></i> Modifier les limites</button>' : '')
            + (NP.can('export.run') ? '<a class="btn btn-outline-secondary btn-sm" href="' + NP.esc(NP.url('api/export.php?type=fiche_pdf&id=' + p.id)) + '"><i class="bi bi-filetype-pdf"></i> Fiche PDF</a>' : '')
            + '</div>';
        showPanel('Parcelle ' + p.parcel_reference, html);
        $('panelBody').querySelectorAll('[data-open]').forEach((el) => el.addEventListener('click', (ev) => {
            ev.preventDefault();
            openParcel(parseInt(el.getAttribute('data-open'), 10), true);
        }));
        const be = $('btnEditGeom');
        if (be) be.addEventListener('click', () => startEdit(p));
    }

    // -------------------------------------------------- Géométrie & mesures
    function layerToGeoJSON(layer) {
        const gj = layer.toGeoJSON();
        return gj.type === 'Feature' ? gj.geometry : gj;
    }

    function measureGeometry(geom) {
        try {
            const area = turf.area(geom);
            const ring = geom.coordinates[0];
            const perim = turf.length(turf.lineString(ring), { units: 'meters' });
            const kinks = turf.kinks(turf.polygon(geom.coordinates)).features.length;
            return { area, perim, kinks, vertices: ring.length - 1 };
        } catch (e) {
            return { area: 0, perim: 0, kinks: 0, vertices: 0 };
        }
    }

    // ------------------------------------------------------------ Dessin
    map.pm.setLang('fr');
    map.pm.setGlobalOptions({ allowSelfIntersection: false, snappable: true, snapDistance: 12, finishOn: null });
    map.pm.setPathOptions(DRAFT_STYLE);

    function setToolbarActive(id) {
        document.querySelectorAll('#mapToolbar .btn').forEach((b) => b.classList.toggle('active', b.id === id));
    }

    const btnDraw = $('btnDraw');
    if (btnDraw) {
        btnDraw.addEventListener('click', () => {
            if (editing) { NP.toast('Terminez ou annulez la saisie en cours.', 'warning'); return; }
            if (map.getZoom() < 14) NP.toast('Conseil : zoomez davantage (vue satellite) pour délimiter précisément.', 'info');
            stopMeasure();
            hidePanel();
            setToolbarActive('btnDraw');
            map.pm.enableDraw('Polygon', { allowSelfIntersection: false, pathOptions: DRAFT_STYLE, templineStyle: { color: '#e05206' }, hintlineStyle: { color: '#e05206', dashArray: '5 5' } });
            showPanel('Nouvelle parcelle', drawingHelp());
            bindDrawingButtons();
        });
    }

    function drawingHelp() {
        return '<p class="small">Cliquez sur la carte pour placer les sommets de la parcelle. Cliquez sur le premier sommet pour fermer le polygone.</p>'
            + '<div class="d-grid gap-2"><button class="btn btn-sm btn-outline-secondary" id="drawUndo"><i class="bi bi-arrow-counterclockwise"></i> Retirer le dernier point</button>'
            + '<button class="btn btn-sm btn-success" id="drawFinish"><i class="bi bi-check2"></i> Fermer le polygone</button>'
            + '<button class="btn btn-sm btn-outline-danger" id="drawCancel"><i class="bi bi-x"></i> Annuler</button></div>';
    }
    function bindDrawingButtons() {
        $('drawUndo').addEventListener('click', () => { try { map.pm.Draw.Polygon._removeLastVertex(); } catch (e) { /* aucun point */ } });
        $('drawFinish').addEventListener('click', () => {
            try { map.pm.Draw.Polygon._finishShape(); } catch (e) { NP.toast('Placez au moins 3 sommets.', 'warning'); }
        });
        $('drawCancel').addEventListener('click', () => { map.pm.disableDraw(); setToolbarActive(null); hidePanel(); });
    }

    map.on('pm:create', (e) => {
        setToolbarActive(null);
        if (e.layer && e.layer._npMeasure) return; // géré par l'outil de mesure
        if (measuring) { finishMeasure(e.layer, e.shape); return; }
        startCreate(e.layer);
    });

    function attachEditing(layer) {
        layer.setStyle(DRAFT_STYLE);
        layer.pm.enable({ allowSelfIntersection: false, snappable: true, removeVertexOn: 'contextmenu' });
        const onChange = () => {
            editing.undo.push(layerToGeoJSON(layer));
            if (editing.undo.length > 50) editing.undo.shift();
            editing.checked = false;
            refreshMeasure();
        };
        layer.on('pm:edit', onChange);
        layer.on('pm:intersect', () => NP.toast('Les limites ne doivent pas se croiser.', 'warning'));
    }

    function startCreate(layer) {
        editing = { mode: 'create', layer, parcelId: null, undo: [], initial: null, checked: false };
        editing.undo.push(layerToGeoJSON(layer));
        attachEditing(layer);
        const opt = (obj, sel) => Object.entries(obj).map(([k, v]) => '<option value="' + NP.esc(k) + '"' + (k === sel ? ' selected' : '') + '>' + NP.esc(v) + '</option>').join('');
        const areas = '<option value="">— Non renseignée —</option>' + C.areas.map((a) => '<option value="' + a.id + '">' + NP.esc(a.label) + '</option>').join('');
        const html = measureBox()
            + '<form id="parcelForm" class="small" data-autosave="carte-nouvelle-parcelle">'
            + '<div class="mb-2"><label class="form-label mb-0" for="pfArea">Zone administrative</label><select class="form-select form-select-sm" id="pfArea" name="administrative_area_id">' + areas + '</select>'
            + '<div class="form-text">Le code de la région (ex. NIA) sert de préfixe à la référence.</div></div>'
            + '<div class="mb-2"><label class="form-label mb-0" for="pfLoc">Quartier / village / description</label><input class="form-control form-control-sm" id="pfLoc" name="locality_description" maxlength="255"></div>'
            + '<div class="row g-2 mb-2"><div class="col-6"><label class="form-label mb-0" for="pfCat">Catégorie *</label><select class="form-select form-select-sm" id="pfCat" name="category" required>' + opt(C.labels.category, 'residentiel') + '</select></div>'
            + '<div class="col-6"><label class="form-label mb-0" for="pfSurvey">Délimitation *</label><select class="form-select form-select-sm" id="pfSurvey" name="survey_level">' + opt(C.labels.survey_level, 'indicative') + '</select></div></div>'
            + '<div class="row g-2 mb-2"><div class="col-6"><label class="form-label mb-0" for="pfDecl">Situation déclarée</label><select class="form-select form-select-sm" id="pfDecl" name="declared_status">' + opt(C.labels.declared_status, 'inconnu') + '</select></div>'
            + '<div class="col-6"><label class="form-label mb-0" for="pfCad">Réf. cadastrale officielle</label><input class="form-control form-control-sm" id="pfCad" name="cadastral_reference" maxlength="80" placeholder="Si elle existe"></div></div>'
            + '<div class="mb-2"><label class="form-label mb-0" for="pfAddr">Adresse</label><input class="form-control form-control-sm" id="pfAddr" name="address" maxlength="255"></div>'
            + '<div class="mb-2"><label class="form-label mb-0" for="pfDesc">Observations</label><textarea class="form-control form-control-sm" id="pfDesc" name="description" rows="2" maxlength="2000"></textarea></div>'
            + '</form>' + editButtons('Enregistrer la parcelle');
        showPanel('Nouvelle parcelle', html);
        bindEditButtons();
        refreshMeasure();
    }

    function startEdit(p) {
        if (!p.geometry) return;
        hidePanel();
        selectedId = p.id;
        const layer = L.geoJSON(p.geometry).getLayers()[0];
        layer.addTo(map);
        editing = { mode: 'edit', layer, parcelId: p.id, undo: [], initial: p.geometry, checked: false, ref: p.parcel_reference };
        editing.undo.push(layerToGeoJSON(layer));
        attachEditing(layer);
        loadParcels(); // masque la version affichée
        map.fitBounds(layer.getBounds(), { maxZoom: 19, padding: [60, 60] });
        const html = '<p class="small">Faites glisser les sommets pour modifier les limites. Cliquez sur un point intermédiaire pour ajouter un sommet, '
            + 'clic droit sur un sommet pour le supprimer. L\'ancienne version est conservée dans l\'historique.</p>'
            + measureBox()
            + '<div class="mb-2 small"><label class="form-label mb-0" for="pfReason">Motif de la modification *</label>'
            + '<input class="form-control form-control-sm" id="pfReason" maxlength="255" placeholder="ex. relevé GPS du 12/03, correction de limite…"></div>'
            + editButtons('Enregistrer la nouvelle version');
        showPanel('Limites de ' + p.parcel_reference, html);
        bindEditButtons();
        refreshMeasure();
    }

    function measureBox() {
        return '<div class="card mb-2"><div class="card-body p-2 small" id="liveMeasure"></div></div><div id="checkResult"></div>';
    }
    function editButtons(saveLabel) {
        return '<div class="d-flex flex-wrap gap-2 mt-2">'
            + '<button class="btn btn-sm btn-outline-secondary" id="edUndo" title="Annuler la dernière modification"><i class="bi bi-arrow-counterclockwise"></i> Annuler</button>'
            + '<button class="btn btn-sm btn-outline-primary" id="edCheck"><i class="bi bi-shield-check"></i> Vérifier</button>'
            + '<button class="btn btn-sm btn-success flex-grow-1" id="edSave"><i class="bi bi-save"></i> ' + NP.esc(saveLabel) + '</button>'
            + '<button class="btn btn-sm btn-outline-danger w-100" id="edCancel"><i class="bi bi-x-circle"></i> Abandonner les changements</button></div>';
    }

    function refreshMeasure() {
        if (!editing) return;
        const m = measureGeometry(layerToGeoJSON(editing.layer));
        const box = $('liveMeasure');
        if (!box) return;
        box.innerHTML = '<div><strong>Superficie :</strong> ' + NP.fmtArea(m.area) + '</div>'
            + '<div><strong>Périmètre :</strong> ' + NP.fmt(m.perim, 2) + ' m</div>'
            + '<div><strong>Sommets :</strong> ' + m.vertices + '</div>'
            + (m.kinks ? '<div class="text-danger"><i class="bi bi-exclamation-triangle"></i> Les limites se croisent.</div>' : '')
            + '<div class="text-muted">Calcul géodésique (WGS84) ; valeurs définitives recalculées par le serveur.</div>';
    }

    function bindEditButtons() {
        $('edUndo').addEventListener('click', () => {
            if (!editing || editing.undo.length < 2) { NP.toast('Rien à annuler.', 'info'); return; }
            editing.undo.pop();
            const prev = editing.undo[editing.undo.length - 1];
            editing.layer.pm.disable();
            editing.layer.setLatLngs(L.GeoJSON.coordsToLatLngs(prev.coordinates, 1));
            editing.layer.pm.enable({ allowSelfIntersection: false, snappable: true, removeVertexOn: 'contextmenu' });
            refreshMeasure();
        });
        $('edCheck').addEventListener('click', () => checkGeometry());
        $('edSave').addEventListener('click', () => saveGeometry(false));
        $('edCancel').addEventListener('click', () => {
            if (!window.confirm('Abandonner les changements non enregistrés ?')) return;
            const id = editing && editing.parcelId;
            stopEditing();
            hidePanel();
            loadParcels();
            if (id) openParcel(id, false);
        });
    }

    async function checkGeometry() {
        const out = $('checkResult');
        out.innerHTML = '<div class="small text-muted">Vérification…</div>';
        try {
            const r = await NP.api('api/parcelles.php?action=check', { method: 'POST', body: { geometry: layerToGeoJSON(editing.layer), id: editing.parcelId } });
            let html = '<div class="alert alert-success py-1 small mb-2"><i class="bi bi-check-circle"></i> Géométrie valide : ' + NP.fmtArea(r.measure.area_m2) + '.</div>';
            if (r.overlaps.length) {
                html = '<div class="alert alert-warning py-1 small mb-2"><i class="bi bi-intersect"></i> ' + r.overlaps.map((o) =>
                    NP.esc((o.type === 'doublon_probable' ? 'Doublon probable avec ' : 'Chevauchement avec ') + o.parcel_reference)).join('<br>')
                    + '<br>L\'enregistrement reste possible après confirmation (copropriété, servitude…).</div>';
            }
            out.innerHTML = html;
            editing.checked = true;
        } catch (e) {
            out.innerHTML = '<div class="alert alert-danger py-1 small mb-2">' + NP.esc(e.message) + '</div>';
        }
    }

    async function saveGeometry(acknowledge) {
        if (!editing) return;
        const geometry = layerToGeoJSON(editing.layer);
        const m = measureGeometry(geometry);
        if (m.kinks) { NP.toast('Corrigez les limites qui se croisent avant d\'enregistrer.', 'error'); return; }
        const btn = $('edSave');
        btn.disabled = true;
        try {
            let r;
            if (editing.mode === 'create') {
                const form = $('parcelForm');
                const attributes = {};
                new FormData(form).forEach((v, k) => { attributes[k] = v; });
                r = await NP.api('api/parcelles.php?action=create', { method: 'POST', body: { attributes, geometry, acknowledge } });
                try { localStorage.removeItem('np-draft-carte-nouvelle-parcelle'); } catch (e) { /* ignore */ }
                NP.toast('Parcelle enregistrée : ' + r.parcel_reference + ' (' + NP.fmtArea(r.area_m2) + ')');
                const id = r.id;
                stopEditing();
                await loadParcels();
                openParcel(id, false);
            } else {
                const reason = ($('pfReason').value || '').trim();
                if (!reason) { NP.toast('Indiquez le motif de la modification.', 'warning'); btn.disabled = false; return; }
                r = await NP.api('api/parcelles.php?action=update_geometry', { method: 'POST', body: { id: editing.parcelId, geometry, reason, acknowledge } });
                NP.toast('Nouvelle version ' + r.version + ' enregistrée (' + NP.fmtArea(r.area_m2) + ').');
                const id = editing.parcelId;
                stopEditing();
                await loadParcels();
                openParcel(id, false);
            }
        } catch (e) {
            btn.disabled = false;
            if (e.status === 409 && e.data && e.data.warnings) {
                $('checkResult').innerHTML = '<div class="alert alert-warning py-2 small"><strong>Avertissements :</strong><ul class="mb-2">'
                    + e.data.warnings.map((w) => '<li>' + NP.esc(w) + '</li>').join('') + '</ul>'
                    + '<button class="btn btn-sm btn-warning" id="edForce">Enregistrer malgré tout (sera signalé pour vérification)</button></div>';
                $('edForce').addEventListener('click', () => saveGeometry(true));
            } else {
                const list = e.data && e.data.errors ? '<ul class="mb-0">' + e.data.errors.map((x) => '<li>' + NP.esc(x) + '</li>').join('') + '</ul>' : NP.esc(e.message);
                $('checkResult').innerHTML = '<div class="alert alert-danger py-1 small">' + list + '</div>';
            }
        }
    }

    function stopEditing() {
        map.pm.disableDraw();
        if (editing && editing.layer) {
            editing.layer.pm.disable();
            map.removeLayer(editing.layer);
        }
        editing = null;
        setToolbarActive(null);
    }

    // ------------------------------------------------------------- Mesures
    let measuring = null;
    function startMeasure(kind) {
        if (editing) { NP.toast('Terminez la saisie en cours avant de mesurer.', 'warning'); return; }
        measuring = kind;
        setToolbarActive(kind === 'Line' ? 'btnMeasureLine' : 'btnMeasureArea');
        map.pm.enableDraw(kind, { pathOptions: { color: '#1565c0', weight: 3, dashArray: '2 6', fillOpacity: 0.1 }, templineStyle: { color: '#1565c0' }, hintlineStyle: { color: '#1565c0', dashArray: '3 3' }, finishOn: kind === 'Line' ? 'dblclick' : null, allowSelfIntersection: kind === 'Line' });
        NP.toast(kind === 'Line' ? 'Cliquez les points ; double-cliquez pour terminer.' : 'Cliquez les sommets ; refermez sur le premier point.', 'info');
    }
    function finishMeasure(layer, shape) {
        layer.pm.disable();
        layer.setStyle && layer.setStyle({ pmIgnore: true });
        measureLayer.addLayer(layer);
        const gj = layerToGeoJSON(layer);
        let txt;
        if (shape === 'Line') {
            const d = turf.length(gj, { units: 'meters' });
            txt = d >= 1000 ? NP.fmt(d / 1000, 3) + ' km' : NP.fmt(d, 2) + ' m';
        } else {
            txt = NP.fmtArea(turf.area(gj));
        }
        layer.bindTooltip(txt, { permanent: true, className: 'measure-tooltip' }).openTooltip();
        measuring = null;
        setToolbarActive(null);
    }
    function stopMeasure() {
        if (measuring) map.pm.disableDraw();
        measuring = null;
    }
    $('btnMeasureLine').addEventListener('click', () => startMeasure('Line'));
    $('btnMeasureArea').addEventListener('click', () => startMeasure('Polygon'));
    $('btnMeasureClear').addEventListener('click', () => { stopMeasure(); measureLayer.clearLayers(); setToolbarActive(null); });
    map.on('pm:drawend', () => { if (measuring) { measuring = null; setToolbarActive(null); } });

    // ------------------------------------------------------------ Filtres
    $('btnFilters').addEventListener('click', () => { $('mapFilters').hidden = !$('mapFilters').hidden; });
    $('btnFiltersClose').addEventListener('click', () => { $('mapFilters').hidden = true; });
    $('btnFiltersApply').addEventListener('click', () => {
        filters = { status: $('fStatus').value, category: $('fCategory').value, area_id: $('fArea').value, overlap: $('fOverlap').checked ? '1' : '' };
        loadParcels();
    });
    $('btnFiltersReset').addEventListener('click', () => {
        ['fStatus', 'fCategory', 'fArea'].forEach((id) => { $(id).value = ''; });
        $('fOverlap').checked = false;
        filters = {};
        loadParcels();
    });

    // ------------------------------------------------- Géolocalisation, vue
    $('btnNiger').addEventListener('click', () => map.fitBounds(nigerBounds));
    $('btnLocate').addEventListener('click', () => {
        if (!navigator.geolocation) { NP.toast('Géolocalisation non disponible sur cet appareil.', 'error'); return; }
        navigator.geolocation.getCurrentPosition((pos) => {
            const ll = [pos.coords.latitude, pos.coords.longitude];
            map.setView(ll, 17);
            L.circle(ll, { radius: pos.coords.accuracy, color: '#1565c0', pmIgnore: true, interactive: false }).addTo(highlightLayer);
            NP.toast('Position GPS : précision ± ' + Math.round(pos.coords.accuracy) + ' m');
        }, (err) => NP.toast('Position indisponible : ' + err.message + ' (HTTPS requis hors localhost).', 'error'), { enableHighAccuracy: true, timeout: 15000 });
    });

    // ------------------------------------------------------------- Recherche
    const searchInput = $('mapSearch');
    const searchBox = $('mapSearchResults');
    async function runSearch() {
        const q = searchInput.value.trim();
        if (q.length < 2) { searchBox.hidden = true; return; }
        const coord = q.match(/^\s*(-?\d+(?:[.,]\d+)?)\s*[,;\s]\s*(-?\d+(?:[.,]\d+)?)\s*$/);
        let html = '';
        if (coord) {
            const lat = parseFloat(coord[1].replace(',', '.'));
            const lng = parseFloat(coord[2].replace(',', '.'));
            html += '<a href="#" class="list-group-item list-group-item-action small" data-lat="' + lat + '" data-lng="' + lng + '"><i class="bi bi-geo-alt"></i> Aller aux coordonnées ' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '</a>';
        }
        try {
            const data = await NP.api('api/recherche.php?map=1&q=' + encodeURIComponent(q));
            data.results.forEach((r) => {
                html += '<a href="#" class="list-group-item list-group-item-action small" data-result="' + NP.esc(JSON.stringify(r)) + '">'
                    + '<i class="bi bi-' + NP.esc(r.icon) + '"></i> <strong>' + NP.esc(r.label) + '</strong>' + (r.sub ? '<div class="text-muted">' + NP.esc(r.sub) + '</div>' : '') + '</a>';
            });
        } catch (e) { /* recherche locale indisponible */ }
        if (!coord) {
            html += '<a href="#" class="list-group-item list-group-item-action small text-primary" data-osm="1"><i class="bi bi-globe"></i> Rechercher « ' + NP.esc(q) + ' » dans les localités OpenStreetMap (Niger)</a>';
        }
        searchBox.innerHTML = html || '<div class="list-group-item small text-muted">Aucun résultat</div>';
        searchBox.hidden = false;
    }
    async function osmSearch(q) {
        searchBox.innerHTML = '<div class="list-group-item small text-muted">Recherche OpenStreetMap…</div>';
        try {
            const url = C.geocoder + '?format=jsonv2&countrycodes=ne&limit=8&accept-language=fr&q=' + encodeURIComponent(q);
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            const items = await res.json();
            searchBox.innerHTML = items.length ? items.map((it) =>
                '<a href="#" class="list-group-item list-group-item-action small" data-bbox="' + NP.esc(JSON.stringify(it.boundingbox)) + '">'
                + '<i class="bi bi-geo"></i> ' + NP.esc(it.display_name) + '</a>').join('')
                : '<div class="list-group-item small text-muted">Aucune localité trouvée.</div>';
        } catch (e) {
            searchBox.innerHTML = '<div class="list-group-item small text-danger">Service de recherche de localités indisponible (connexion Internet requise).</div>';
        }
    }
    searchInput.addEventListener('input', NP.debounce(runSearch, 300));
    searchInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); runSearch(); } });
    $('mapSearchBtn').addEventListener('click', runSearch);
    searchBox.addEventListener('click', (e) => {
        const a = e.target.closest('a');
        if (!a) return;
        e.preventDefault();
        if (a.dataset.osm) { osmSearch(searchInput.value.trim()); return; }
        searchBox.hidden = true;
        if (a.dataset.lat) {
            const ll = [parseFloat(a.dataset.lat), parseFloat(a.dataset.lng)];
            map.setView(ll, 17);
            L.marker(ll, { pmIgnore: true }).addTo(highlightLayer).bindPopup(ll[0].toFixed(6) + ', ' + ll[1].toFixed(6)).openPopup();
            return;
        }
        if (a.dataset.bbox) {
            const b = JSON.parse(a.dataset.bbox).map(parseFloat);
            map.fitBounds([[b[0], b[2]], [b[1], b[3]]], { maxZoom: 16 });
            return;
        }
        const r = JSON.parse(a.dataset.result);
        if (r.type === 'parcel') {
            openParcel(r.id, true);
        } else if (r.bbox) {
            map.fitBounds([[r.bbox[1], r.bbox[0]], [r.bbox[3], r.bbox[2]]]);
        } else if (r.center) {
            map.setView(r.center, 13);
        } else {
            NP.toast('Aucune limite géographique importée pour cette zone : importez-la dans Paramètres.', 'info');
        }
    });
    document.addEventListener('click', (e) => { if (!searchBox.contains(e.target) && e.target !== searchInput) searchBox.hidden = true; });

    // Les contrôles flottants ne doivent pas propager les clics à la carte
    ['mapToolbar', 'mapFilters', 'mapPanel'].forEach((id) => {
        const el = $(id);
        if (el) { L.DomEvent.disableClickPropagation(el); L.DomEvent.disableScrollPropagation(el); }
    });
    document.querySelector('.map-search') && L.DomEvent.disableClickPropagation(document.querySelector('.map-search'));

    window.addEventListener('beforeunload', (e) => {
        if (editing) { e.preventDefault(); e.returnValue = ''; }
    });

    // ------------------------------------------------------------- Démarrage
    if (C.point) {
        map.setView(C.point, 17);
        L.marker(C.point, { pmIgnore: true }).addTo(highlightLayer);
    }
    if (C.focus) openParcel(C.focus, true);
    if (C.edit) {
        NP.api('api/parcelles.php?action=get&id=' + C.edit).then((r) => {
            if (r.parcel.can_edit) startEdit(r.parcel); else { NP.toast('Modification non autorisée pour cette parcelle.', 'error'); openParcel(C.edit, true); }
        }).catch((e) => NP.toast(e.message, 'error'));
    }
    loadParcels();
    window.NPMap = { map, openParcel, loadParcels }; // utilisé par les tests automatisés
})();
