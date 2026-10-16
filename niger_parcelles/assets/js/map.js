/* Niger Parcelles - carte foncière (Leaflet + Leaflet-Geoman + Turf + NPAssist). */
(function () {
    'use strict';

    const NP = window.NP;
    const AS = window.NPAssist;
    const C = JSON.parse(document.getElementById('map-config').textContent);
    const $ = (id) => document.getElementById(id);
    const MAXZ = C.maxZoom || 25;

    const STATUS_STYLE = {
        brouillon: { color: '#6c757d', fillColor: '#adb5bd' },
        soumise: { color: '#0277bd', fillColor: '#4fc3f7' },
        en_verification: { color: '#ef6c00', fillColor: '#ffb74d' },
        verifiee: { color: '#0d8b3e', fillColor: '#66bb6a' },
        anomalie: { color: '#c62828', fillColor: '#ef5350' },
        rejetee: { color: '#212529', fillColor: '#495057' },
    };
    const DRAFT_STYLE = { color: '#e05206', weight: 3, dashArray: '6 4', fillColor: '#e05206', fillOpacity: 0.15 };
    const CANDIDATE_STYLE = { color: '#ff6d00', weight: 2.5, dashArray: '4 3', fillColor: '#ffab40', fillOpacity: 0.25 };
    const SATELLITE_URL = C.tiles.satellite ? C.tiles.satellite.url : 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';

    // ------------------------------------------------------------------ Carte
    const map = L.map('map', {
        zoomControl: true, maxZoom: MAXZ, zoomSnap: 0.5, zoomDelta: 0.5, wheelPxPerZoomLevel: 90,
        preferCanvas: false, worldCopyJump: false,
    }).setView(C.center, C.zoom);
    const nigerBounds = L.latLngBounds([C.bounds.min_lat, C.bounds.min_lng], [C.bounds.max_lat, C.bounds.max_lng]);

    // ---------------------------------------------------- Fonds de carte
    // Referer transmis (origine seulement) : OpenStreetMap refuse les tuiles sans Referer.
    function makeTiles(t) {
        const opts = {
            attribution: t.attribution, maxNativeZoom: t.maxNativeZoom || 19, maxZoom: MAXZ,
            crossOrigin: 'anonymous', referrerPolicy: 'strict-origin-when-cross-origin',
        };
        if (t.subdomains) opts.subdomains = t.subdomains;
        const layer = L.tileLayer(t.url, opts);
        layer._np = { name: t.name, errors: 0, loads: 0 };
        layer.on('tileerror', () => { layer._np.errors++; tileStatus(); });
        layer.on('tileload', () => { layer._np.loads++; tileStatus(); });
        return layer;
    }
    const baseLayers = {};
    const overlays = {};
    let roadLayer = C.tiles.osm ? makeTiles(C.tiles.osm) : null;
    const fallbackQueue = (C.tiles.osm && C.tiles.osm.fallback ? C.tiles.osm.fallback : []).filter((k) => C.fallbacks[k]);
    if (roadLayer) { baseLayers[C.tiles.osm.name] = roadLayer; roadLayer.addTo(map); }
    let satLayer = null;
    if (C.tiles.satellite) {
        satLayer = makeTiles(C.tiles.satellite);
        baseLayers[C.tiles.satellite.name] = satLayer;
        if (C.tiles.labels) {
            baseLayers['Hybride (satellite + libellés)'] = L.layerGroup([makeTiles(C.tiles.satellite), makeTiles(C.tiles.labels)]);
            overlays[C.tiles.labels.name] = makeTiles(C.tiles.labels);
        }
    }
    Object.entries(C.fallbacks || {}).forEach(([, f]) => { baseLayers[f.name] = makeTiles(f); });

    function activeBase() {
        let found = null;
        map.eachLayer((l) => { if (l instanceof L.TileLayer && l._np && Object.values(baseLayers).includes(l)) found = l; });
        return found;
    }
    /** Indicateur de disponibilité + bascule automatique vers un fond OSM de secours. */
    const tileStatus = NP.debounce(() => {
        const base = activeBase();
        if (!base) return;
        const st = base._np;
        const bad = st.errors >= 4 && st.loads === 0;
        $('tileIndicator').classList.toggle('err', bad);
        $('tileText').textContent = bad ? 'Fond « ' + st.name + ' » indisponible' : 'Fond : ' + st.name;
        if (bad && base === roadLayer && fallbackQueue.length) {
            const next = C.fallbacks[fallbackQueue.shift()];
            const nl = baseLayers[next.name];
            map.removeLayer(roadLayer);
            nl.addTo(map);
            roadLayer = nl;
            NP.toast('Le fond OpenStreetMap ne répond pas : bascule automatique sur « ' + next.name + ' ».', 'warning');
        } else if (bad && fallbackQueue.length === 0 && base === roadLayer) {
            $('tileText').textContent = 'Fonds de carte injoignables : vérifiez la connexion Internet (les données restent utilisables).';
        }
        st.errors = 0;
        st.loads = 0;
    }, 1500);

    // Couches de données
    const parcelsLayer = L.featureGroup().addTo(map);
    const adminLayer = L.geoJSON(null, {
        style: { color: '#5c3d99', weight: 2, dashArray: '4 4', fill: false },
        onEachFeature: (f, l) => l.bindTooltip(NP.esc(f.properties.name) + ' (' + NP.esc(f.properties.type_label) + ')', { sticky: true }),
        pmIgnore: true,
    });
    const highlightLayer = L.featureGroup().addTo(map);
    const measureLayer = L.featureGroup().addTo(map);
    const candidateLayer = L.featureGroup().addTo(map);
    const edgeLabelLayer = L.layerGroup().addTo(map);
    const gpsLayer = L.layerGroup().addTo(map);
    overlays['Parcelles enregistrées'] = parcelsLayer;
    overlays['Limites administratives importées'] = adminLayer;
    overlays['Propositions de l\'assistant'] = candidateLayer;
    overlays['Mesures'] = measureLayer;

    L.control.layers(baseLayers, overlays, { position: 'topright', collapsed: true }).addTo(map);
    L.control.scale({ imperial: false, position: 'bottomright', maxWidth: 160 }).addTo(map);

    // Légende
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = () => {
        const div = L.DomUtil.create('div', 'map-legend');
        div.innerHTML = '<strong>Légende</strong><br>' + Object.entries(STATUS_STYLE).map(([k, s]) =>
            '<span class="sw" style="border-color:' + s.color + ';background:' + s.fillColor + '55"></span>' + NP.esc(C.labels.verification_status[k])).join('<br>')
            + '<br><span class="sw" style="border-color:#c62828;border-style:dashed;background:transparent"></span>Chevauchement à vérifier'
            + '<br><span class="sw" style="border-color:#5c3d99;border-style:dashed;background:transparent"></span>Limite administrative'
            + '<br><span class="sw" style="border-color:#e05206;border-style:dashed;background:#e0520622"></span>Parcelle en cours de saisie'
            + '<br><span class="sw" style="border-color:#ff6d00;border-style:dashed;background:#ffab4055"></span>Proposition de l\'assistant';
        L.DomEvent.disableClickPropagation(div);
        return div;
    };
    legend.addTo(map);

    // Barre d'état placée comme contrôle Leaflet : reste dans la carte, sans chevaucher l'échelle
    const statusCtl = L.control({ position: 'bottomleft' });
    statusCtl.onAdd = () => { const el = $('mapStatus'); L.DomEvent.disableClickPropagation(el); return el; };
    statusCtl.addTo(map);
    // Taille de la carte toujours exacte (panneau latéral, rotation d'écran, barre d'état…) :
    // indispensable pour que le point cliqué corresponde exactement aux coordonnées affichées.
    map.invalidateSize({ pan: false });
    if (window.ResizeObserver) {
        new ResizeObserver(() => map.invalidateSize({ pan: false })).observe(map.getContainer());
    }

    // ------------------------------------------------- Zoom, résolution, curseur
    function updateZoomInfo() {
        const z = map.getZoom();
        const lat = map.getCenter().lat;
        const mpp = AS.metersPerPixel(lat, z);
        const widthM = mpp * map.getSize().x;
        const res = mpp >= 1 ? NP.fmt(mpp, 1) + ' m' : (mpp >= 0.01 ? NP.fmt(mpp * 100, 1) + ' cm' : NP.fmt(mpp * 1000, 1) + ' mm');
        const width = widthM >= 1000 ? NP.fmt(widthM / 1000, 1) + ' km' : NP.fmt(widthM, widthM < 20 ? 1 : 0) + ' m';
        $('zoomInfo').textContent = 'Zoom ' + NP.fmt(z, 1) + ' · 1 px ≈ ' + res + ' · vue ≈ ' + width
            + (z > 19 ? ' (image agrandie)' : '');
        $('zoomInfo').title = 'Hauteur de vue équivalente ≈ ' + NP.fmt(widthM * 0.87, widthM < 20 ? 1 : 0)
            + ' m. Au-delà du zoom 19, l\'image n\'est pas plus détaillée mais le pointage des sommets est plus précis.';
    }
    map.on('zoomend moveend resize', updateZoomInfo);
    map.on('mousemove', (e) => {
        $('cursorCoords').textContent = 'Lat ' + e.latlng.lat.toFixed(7) + ' · Lng ' + e.latlng.lng.toFixed(7);
    });

    // Limites administratives (si importées)
    if (C.hasAdminBoundaries) {
        NP.api('api/admin_areas.php?action=geojson').then((fc) => { adminLayer.addData(fc); adminLayer.addTo(map); }).catch(() => {});
    }

    // ------------------------------------------------------------- Parcelles
    let filters = {};
    let loadCtrl = null;
    let selectedId = null;
    let editing = null; // { mode: 'create'|'edit', layer, parcelId, undo: [] }
    let showOwners = false;
    let lastFc = null;

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
            action: 'list', west: b.getWest().toFixed(7), south: b.getSouth().toFixed(7),
            east: b.getEast().toFixed(7), north: b.getNorth().toFixed(7), zoom: Math.floor(map.getZoom()),
        });
        if (showOwners) q.set('owners', '1');
        Object.entries(filters).forEach(([k, v]) => { if (v) q.set(k, v); });
        $('mapLoading').hidden = false;
        try {
            const fc = await NP.api('api/parcelles.php?' + q.toString(), { signal: loadCtrl.signal });
            lastFc = fc;
            renderParcels(fc);
        } catch (e) {
            if (e.name !== 'AbortError') NP.toast('Chargement des parcelles impossible : ' + e.message, 'error');
        } finally {
            $('mapLoading').hidden = true;
        }
    }

    function ownerLabelHtml(p) {
        return '<div class="ol-ref">' + NP.esc(p.ref) + '</div>'
            + (p.owners ? '<div class="ol-own">' + NP.esc(p.owners) + '</div>' : '<div class="ol-none">Propriétaire non renseigné</div>');
    }

    /** Largeur à l'écran (px) d'une géométrie : les étiquettes permanentes ne s'affichent que si elles tiennent. */
    function screenWidth(geom) {
        if (geom.type !== 'Polygon') return 0;
        let min = Infinity, max = -Infinity;
        geom.coordinates[0].forEach(([lng, lat]) => { const x = map.latLngToContainerPoint([lat, lng]).x; min = Math.min(min, x); max = Math.max(max, x); });
        return max - min;
    }

    function renderParcels(fc) {
        parcelsLayer.clearLayers();
        const z = map.getZoom();
        fc.features.forEach((f) => {
            if (editing && editing.parcelId === f.properties.id) return; // en cours de modification
            const p = f.properties;
            let layer;
            if (f.geometry.type === 'Point') {
                const s = STATUS_STYLE[p.status] || STATUS_STYLE.brouillon;
                layer = L.circleMarker([f.geometry.coordinates[1], f.geometry.coordinates[0]], {
                    radius: 6, color: p.overlap ? '#c62828' : s.color, fillColor: s.fillColor, fillOpacity: 0.9, weight: 2, pmIgnore: true,
                });
            } else {
                layer = L.geoJSON(f, { style: () => parcelStyle(p), snapIgnore: false });
            }
            const permanent = z >= 16 && screenWidth(f.geometry) >= (showOwners ? 110 : 70);
            if (showOwners) {
                layer.bindTooltip(ownerLabelHtml(p), permanent && f.geometry.type !== 'Point'
                    ? { permanent: true, direction: 'center', className: 'owner-label' }
                    : { sticky: true, className: 'owner-label' });
            } else if (permanent && f.geometry.type !== 'Point') {
                layer.bindTooltip(NP.esc(p.ref), { permanent: true, direction: 'center', className: 'parcel-label' });
            } else {
                layer.bindTooltip(NP.esc(p.ref) + (p.demo ? ' (démo)' : ''), { sticky: true });
            }
            layer.feature = f;
            layer.on('click', (ev) => {
                if (editing || measuring || assist.mode || survey) return;
                L.DomEvent.stopPropagation(ev);
                openParcel(p.id, false, ev.latlng);
            });
            parcelsLayer.addLayer(layer);
        });
        if (fc.truncated) {
            NP.toast(fc.features.length + ' parcelles affichées sur ' + fc.total + ' dans cette zone : zoomez pour tout voir.', 'warning');
        }
    }

    map.on('moveend', NP.debounce(loadParcels, 300));

    // Noms des propriétaires sur la carte
    const btnOwners = $('btnOwners');
    if (btnOwners) {
        btnOwners.addEventListener('click', () => {
            showOwners = !showOwners;
            btnOwners.classList.toggle('active', showOwners);
            if (showOwners) NP.toast('Noms des propriétaires affichés sur les parcelles assez grandes à l\'écran ; zoomez ou survolez les autres.', 'info');
            loadParcels();
        });
    }

    // ---------------------------------------------------------------- Panneau
    const panel = $('mapPanel');
    function showPanel(title, html) {
        $('panelTitle').textContent = title;
        $('panelBody').innerHTML = html;
        panel.hidden = false;
        setTimeout(() => map.invalidateSize({ pan: false }), 50);
    }
    function hidePanel() {
        panel.hidden = true;
        selectedId = null;
        highlightLayer.clearLayers();
        setTimeout(() => map.invalidateSize({ pan: false }), 50);
    }
    $('panelClose').addEventListener('click', () => {
        if ((editing || survey || candidates.length) && !window.confirm('Abandonner la saisie en cours ? Les éléments non enregistrés seront perdus.')) return;
        stopEditing();
        stopSurvey();
        stopAssist(true);
        hidePanel();
    });

    const dl = (rows) => '<dl>' + rows.filter((r) => r[1] !== null && r[1] !== undefined && r[1] !== '')
        .map((r) => '<dt>' + NP.esc(r[0]) + '</dt><dd>' + (r[2] ? r[1] : NP.esc(r[1])) + '</dd>').join('') + '</dl>';

    async function openParcel(id, zoomTo, clicked) {
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
            if (zoomTo) map.fitBounds(hl.getBounds(), { maxZoom: 19, padding: [40, 40] });
        }
        const a = p.areas;
        let html = '';
        if (p.is_demo) html += '<div class="alert alert-warning py-1 small mb-2"><i class="bi bi-exclamation-triangle"></i> Donnée de DÉMONSTRATION fictive.</div>';
        if (p.has_overlap_warning) html += '<div class="alert alert-danger py-1 small mb-2"><i class="bi bi-intersect"></i> Chevauchement potentiel à vérifier.</div>';
        if (clicked) {
            html += '<details class="pos-popup small mb-2" open><summary><i class="bi bi-geo-alt-fill text-danger"></i> <strong>Point cliqué</strong> '
                + clicked.lat.toFixed(7) + ', ' + clicked.lng.toFixed(7) + '</summary>' + positionTable(clicked) + '</details>';
            if (posMarker) map.removeLayer(posMarker);
            posMarker = L.circleMarker(clicked, { radius: 5, color: '#fff', weight: 2, fillColor: '#e05206', fillOpacity: 1, pmIgnore: true, interactive: false }).addTo(highlightLayer);
        }
        html += '<div class="mb-2"><span class="ref fs-6">' + NP.esc(p.parcel_reference) + '</span> '
            + '<span class="badge text-bg-light border">' + NP.esc(p.verification_status_label) + '</span></div>';
        html += dl([
            ['Réf. cadastrale', p.cadastral_reference || 'Non renseignée'],
            ['Région', a.region], ['Département', a.departement], ['Commune', a.commune], ['Quartier', a.quartier], ['Village', a.village],
            ['Localisation', p.locality_description],
            ['Catégorie', p.category_label],
            ['Superficie', NP.fmtArea(p.area_m2)],
            ['Périmètre', NP.fmt(p.perimeter_m, 2) + ' m'],
            ['Centre', p.centroid[0].toFixed(7) + ', ' + p.centroid[1].toFixed(7)],
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
        // false : aucune troncature (Leaflet arrondit sinon à 6 décimales, soit ± 5,5 cm)
        const gj = layer.toGeoJSON(false);
        return gj.type === 'Feature' ? gj.geometry : gj;
    }
    function ringLatLngs(layer) {
        const lls = layer.getLatLngs();
        return (Array.isArray(lls[0]) ? lls[0] : lls).map((p) => L.latLng(p.lat, p.lng));
    }
    function polygonFromLatLngs(lls) {
        return L.polygon(lls.map((p) => [p.lat, p.lng]), DRAFT_STYLE);
    }

    function measureGeometry(geom) {
        try {
            const ring = geom.coordinates[0];
            let area = AS.geodesicArea(ring);
            geom.coordinates.slice(1).forEach((h) => { area -= AS.geodesicArea(h); });
            const perim = AS.geodesicLength(ring, false);
            const kinks = turf.kinks(turf.polygon(geom.coordinates)).features.length;
            return { area, perim, kinks, vertices: ring.length - 1 };
        } catch (e) {
            return { area: 0, perim: 0, kinks: 0, vertices: 0 };
        }
    }

    // ------------------------------------------- Position exacte au clic
    function positionTable(ll) {
        const u = AS.toUtm(ll.lat, ll.lng);
        const mpp = AS.metersPerPixel(ll.lat, map.getZoom());
        const prec = mpp / 2 >= 1 ? NP.fmt(mpp / 2, 1) + ' m' : NP.fmt(mpp * 50, 1) + ' cm';
        return '<table>'
            + '<tr><td>Latitude</td><td class="mono">' + ll.lat.toFixed(7) + '</td></tr>'
            + '<tr><td>Longitude</td><td class="mono">' + ll.lng.toFixed(7) + '</td></tr>'
            + '<tr><td>DMS</td><td class="mono">' + NP.esc(AS.toDms(ll.lat, true)) + '<br>' + NP.esc(AS.toDms(ll.lng, false)) + '</td></tr>'
            + '<tr><td>UTM ' + u.zone + 'N</td><td class="mono">X ' + u.x.toFixed(2) + '<br>Y ' + u.y.toFixed(2) + '</td></tr>'
            + '<tr><td>Pointage</td><td>± ' + prec + ' à ce zoom</td></tr></table>';
    }
    let posMarker = null;
    function showPositionPopup(ll) {
        if (posMarker) map.removeLayer(posMarker);
        posMarker = L.circleMarker(ll, { radius: 5, color: '#fff', weight: 2, fillColor: '#e05206', fillOpacity: 1, pmIgnore: true }).addTo(map);
        const div = document.createElement('div');
        div.className = 'pos-popup';
        div.innerHTML = '<strong><i class="bi bi-geo-alt-fill text-danger"></i> Position du point</strong>' + positionTable(ll)
            + '<div class="d-flex flex-wrap gap-1 mt-1">'
            + '<button class="btn btn-sm btn-outline-secondary py-0" data-act="copy">Copier</button>'
            + '<a class="btn btn-sm btn-outline-secondary py-0" href="' + NP.esc(NP.url('parcelles.php?lat=' + ll.lat.toFixed(7) + '&lng=' + ll.lng.toFixed(7))) + '">Parcelles ici</a>'
            + (C.canCreate ? '<button class="btn btn-sm btn-outline-primary py-0" data-act="tpl">Gabarit 400 m² ici</button>'
                + '<button class="btn btn-sm btn-outline-primary py-0" data-act="detect">Détecter la parcelle</button>' : '')
            + '</div>';
        div.addEventListener('click', (e) => {
            const act = e.target.getAttribute('data-act');
            if (act === 'copy') copyText(ll.lat.toFixed(7) + ', ' + ll.lng.toFixed(7));
            if (act === 'tpl') { map.closePopup(); startAssist('template'); addTemplateAt(ll); }
            if (act === 'detect') { map.closePopup(); startAssist('detect'); detectAt(ll); }
        });
        L.popup({ maxWidth: 320 }).setLatLng(ll).setContent(div).openOn(map);
        map.once('popupclose', () => { if (posMarker) { map.removeLayer(posMarker); posMarker = null; } });
    }
    function copyText(t) {
        const done = () => NP.toast('Coordonnées copiées : ' + t);
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(t).then(done, () => window.prompt('Copiez les coordonnées :', t));
        } else {
            window.prompt('Copiez les coordonnées :', t);
        }
    }
    map.on('click', (e) => {
        if (assist.mode) { onAssistClick(e.latlng); return; }
        if (editing || measuring || survey || map.pm.globalDrawModeEnabled()) return;
        showPositionPopup(e.latlng);
    });

    // ------------------------------------------------------------ Dessin
    map.pm.setLang('fr');
    map.pm.setGlobalOptions({ allowSelfIntersection: false, snappable: true, snapDistance: 14, finishOn: null });
    map.pm.setPathOptions(DRAFT_STYLE);

    function setToolbarActive(id) {
        document.querySelectorAll('#mapToolbar > .btn, #mapToolbar .btn-group > .btn').forEach((b) => {
            if (b.id !== 'btnOwners') b.classList.toggle('active', b.id === id);
        });
    }

    const btnDraw = $('btnDraw');
    if (btnDraw) {
        btnDraw.addEventListener('click', () => {
            if (editing || survey) { NP.toast('Terminez ou annulez la saisie en cours.', 'warning'); return; }
            if (map.getZoom() < 17) NP.toast('Conseil : zoomez (vue satellite, zoom 19 à 22) pour délimiter au décimètre près.', 'info');
            stopMeasure();
            stopAssist(false);
            hidePanel();
            setToolbarActive('btnDraw');
            map.pm.enableDraw('Polygon', { allowSelfIntersection: false, pathOptions: DRAFT_STYLE, templineStyle: { color: '#e05206' }, hintlineStyle: { color: '#e05206', dashArray: '5 5' } });
            showPanel('Nouvelle parcelle', drawingHelp());
            bindDrawingButtons();
        });
    }

    function drawingHelp() {
        return '<p class="small">Cliquez sur la carte pour placer les sommets. Cliquez sur le premier sommet pour fermer le polygone. '
            + 'Les sommets s\'accrochent aux parcelles voisines (limites communes exactes).</p>'
            + '<div class="d-grid gap-2">'
            + '<button class="btn btn-sm btn-outline-primary" id="drawCenter"><i class="bi bi-plus-circle-dotted"></i> Ajouter un sommet au viseur (centre)</button>'
            + '<button class="btn btn-sm btn-outline-primary" id="drawGps"><i class="bi bi-broadcast"></i> Ajouter ma position GPS (moyenne 10 s)</button>'
            + '<button class="btn btn-sm btn-outline-secondary" id="drawUndo"><i class="bi bi-arrow-counterclockwise"></i> Retirer le dernier point</button>'
            + '<button class="btn btn-sm btn-success" id="drawFinish"><i class="bi bi-check2"></i> Fermer le polygone</button>'
            + '<button class="btn btn-sm btn-outline-danger" id="drawCancel"><i class="bi bi-x"></i> Annuler</button></div>'
            + '<p class="small text-muted mt-2 mb-0">Astuce : activez le viseur (menu Précision) et déplacez la carte pour viser chaque angle de mur au zoom 21-23.</p>';
    }
    function addDrawVertex(ll) {
        try { map.pm.Draw.Polygon._createVertex({ latlng: L.latLng(ll) }); } catch (e) { NP.toast('Impossible d\'ajouter le sommet.', 'error'); }
    }
    function bindDrawingButtons() {
        $('drawUndo').addEventListener('click', () => { try { map.pm.Draw.Polygon._removeLastVertex(); } catch (e) { /* aucun point */ } });
        $('drawFinish').addEventListener('click', () => {
            try { map.pm.Draw.Polygon._finishShape(); } catch (e) { NP.toast('Placez au moins 3 sommets.', 'warning'); }
        });
        $('drawCancel').addEventListener('click', () => { map.pm.disableDraw(); setToolbarActive(null); hidePanel(); });
        $('drawCenter').addEventListener('click', () => { setCrosshair(true); addDrawVertex(map.getCenter()); });
        $('drawGps').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const fix = await gpsAverage(10, btn);
            if (fix) { addDrawVertex([fix.lat, fix.lng]); map.panTo([fix.lat, fix.lng]); }
        });
    }

    map.on('pm:create', (e) => {
        setToolbarActive(null);
        if (measuring) { finishMeasure(e.layer, e.shape); return; }
        if (assist.mode === 'subdivide') { finishBlock(e.layer); return; }
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
        layer.on('pm:markerdrag', () => updateEdgeLabels(layer));
        layer.on('pm:intersect', () => NP.toast('Les limites ne doivent pas se croiser.', 'warning'));
    }

    function attributesForm(prefix, defaults) {
        defaults = defaults || {};
        const opt = (obj, sel) => Object.entries(obj).map(([k, v]) => '<option value="' + NP.esc(k) + '"' + (k === sel ? ' selected' : '') + '>' + NP.esc(v) + '</option>').join('');
        const areas = '<option value="">— Non renseignée —</option>' + C.areas.map((a) => '<option value="' + a.id + '">' + NP.esc(a.label) + '</option>').join('');
        return '<div class="mb-2"><label class="form-label mb-0" for="' + prefix + 'Area">Zone administrative</label><select class="form-select form-select-sm" id="' + prefix + 'Area" name="administrative_area_id">' + areas + '</select>'
            + '<div class="form-text">Le code de la région (ex. NIA) sert de préfixe à la référence.</div></div>'
            + '<div class="mb-2"><label class="form-label mb-0" for="' + prefix + 'Loc">Quartier / village / description</label><input class="form-control form-control-sm" id="' + prefix + 'Loc" name="locality_description" maxlength="255"></div>'
            + '<div class="row g-2 mb-2"><div class="col-6"><label class="form-label mb-0" for="' + prefix + 'Cat">Catégorie *</label><select class="form-select form-select-sm" id="' + prefix + 'Cat" name="category" required>' + opt(C.labels.category, defaults.category || 'residentiel') + '</select></div>'
            + '<div class="col-6"><label class="form-label mb-0" for="' + prefix + 'Survey">Délimitation *</label><select class="form-select form-select-sm" id="' + prefix + 'Survey" name="survey_level">' + opt(C.labels.survey_level, defaults.survey || 'indicative') + '</select></div></div>';
    }

    function startCreate(layer, opts) {
        opts = opts || {};
        if (!layer._map) layer.addTo(map);
        editing = { mode: 'create', layer, parcelId: null, undo: [], initial: null, checked: false };
        editing.undo.push(layerToGeoJSON(layer));
        attachEditing(layer);
        const opt = (obj, sel) => Object.entries(obj).map(([k, v]) => '<option value="' + NP.esc(k) + '"' + (k === sel ? ' selected' : '') + '>' + NP.esc(v) + '</option>').join('');
        const html = (opts.note ? '<div class="alert alert-info py-1 small">' + NP.esc(opts.note) + '</div>' : '')
            + measureBox()
            + '<form id="parcelForm" class="small">'
            + attributesForm('pf', { survey: opts.survey })
            + '<div class="row g-2 mb-2"><div class="col-6"><label class="form-label mb-0" for="pfDecl">Situation déclarée</label><select class="form-select form-select-sm" id="pfDecl" name="declared_status">' + opt(C.labels.declared_status, 'inconnu') + '</select></div>'
            + '<div class="col-6"><label class="form-label mb-0" for="pfCad">Réf. cadastrale officielle</label><input class="form-control form-control-sm" id="pfCad" name="cadastral_reference" maxlength="80" placeholder="Si elle existe"></div></div>'
            + '<div class="mb-2"><label class="form-label mb-0" for="pfAddr">Adresse</label><input class="form-control form-control-sm" id="pfAddr" name="address" maxlength="255"></div>'
            + '<div class="mb-2"><label class="form-label mb-0" for="pfDesc">Observations</label><textarea class="form-control form-control-sm" id="pfDesc" name="description" rows="2" maxlength="2000"></textarea></div>'
            + '</form>' + editButtons('Enregistrer la parcelle');
        showPanel('Nouvelle parcelle', html);
        bindEditButtons();
        refreshMeasure();
        map.fitBounds(layer.getBounds(), { maxZoom: Math.max(map.getZoom(), 19), padding: [60, 60] });
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
        map.fitBounds(layer.getBounds(), { maxZoom: 20, padding: [60, 60] });
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
        return '<div class="card mb-2"><div class="card-body p-2 small" id="liveMeasure"></div></div>'
            + '<details class="small mb-2" id="precisionTools"><summary><strong>Outils de précision</strong> (sommets, équerrage, superficie)</summary>'
            + '<div class="d-flex flex-wrap gap-1 my-2">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" id="ptSquare" title="Remplacer par le rectangle le plus proche (angles droits)"><i class="bi bi-bounding-box"></i> Équerrer</button>'
            + '<div class="input-group input-group-sm" style="width:auto"><input type="number" class="form-control" id="ptArea" value="400" min="1" step="any" style="max-width:90px" aria-label="Superficie cible">'
            + '<button type="button" class="btn btn-outline-secondary" id="ptScale">Ajuster à cette superficie (m²)</button></div>'
            + '</div>'
            + '<div class="table-responsive" style="max-height:220px"><table class="table table-sm mb-1"><thead><tr><th>#</th><th>Latitude</th><th>Longitude</th></tr></thead><tbody id="ptVertices"></tbody></table></div>'
            + '<button type="button" class="btn btn-sm btn-outline-primary" id="ptApply">Appliquer les coordonnées saisies</button>'
            + '</details><div id="checkResult"></div>';
    }
    function editButtons(saveLabel) {
        return '<div class="d-flex flex-wrap gap-2 mt-2">'
            + '<button class="btn btn-sm btn-outline-secondary" id="edUndo" title="Annuler la dernière modification"><i class="bi bi-arrow-counterclockwise"></i> Annuler</button>'
            + '<button class="btn btn-sm btn-outline-primary" id="edCheck"><i class="bi bi-shield-check"></i> Vérifier</button>'
            + '<button class="btn btn-sm btn-success flex-grow-1" id="edSave"><i class="bi bi-save"></i> ' + NP.esc(saveLabel) + '</button>'
            + '<button class="btn btn-sm btn-outline-danger w-100" id="edCancel"><i class="bi bi-x-circle"></i> Abandonner les changements</button></div>';
    }

    /** Longueur de chaque côté affichée au milieu du segment. */
    function updateEdgeLabels(layer) {
        edgeLabelLayer.clearLayers();
        if (!layer) return;
        const pts = ringLatLngs(layer);
        if (pts.length < 2 || map.getZoom() < 16) return;
        for (let i = 0; i < pts.length; i++) {
            const a = pts[i], b = pts[(i + 1) % pts.length];
            const d = AS.geodesicDistance(a, b);
            const mid = L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2);
            edgeLabelLayer.addLayer(L.tooltip({ permanent: true, direction: 'center', className: 'edge-label', interactive: false })
                .setLatLng(mid).setContent(NP.fmt(d, 2) + ' m'));
        }
    }
    map.on('zoomend', () => { if (editing) updateEdgeLabels(editing.layer); });

    function refreshMeasure() {
        if (!editing) return;
        const m = measureGeometry(layerToGeoJSON(editing.layer));
        const box = $('liveMeasure');
        updateEdgeLabels(editing.layer);
        if (!box) return;
        const target = 400;
        const diff = m.area - target;
        box.innerHTML = '<div><strong>Superficie :</strong> ' + NP.fmtArea(m.area)
            + (Math.abs(diff) < 150 ? ' <span class="text-muted">(' + (diff >= 0 ? '+' : '') + NP.fmt(diff, 1) + ' m² / 400)</span>' : '') + '</div>'
            + '<div><strong>Périmètre :</strong> ' + NP.fmt(m.perim, 2) + ' m · <strong>Sommets :</strong> ' + m.vertices + '</div>'
            + (m.kinks ? '<div class="text-danger"><i class="bi bi-exclamation-triangle"></i> Les limites se croisent.</div>' : '')
            + '<div class="text-muted">Calcul géodésique (WGS84) ; longueurs des côtés affichées sur la carte. Valeurs définitives recalculées par le serveur.</div>';
        const tb = $('ptVertices');
        if (tb && !tb.contains(document.activeElement)) {
            tb.innerHTML = ringLatLngs(editing.layer).map((p, i) =>
                '<tr><td>' + (i + 1) + '</td><td><input class="form-control form-control-sm py-0" data-i="' + i + '" data-k="lat" value="' + p.lat.toFixed(7) + '" inputmode="decimal"></td>'
                + '<td><input class="form-control form-control-sm py-0" data-i="' + i + '" data-k="lng" value="' + p.lng.toFixed(7) + '" inputmode="decimal"></td></tr>').join('');
        }
    }

    function replaceDraftGeometry(lls) {
        editing.layer.pm.disable();
        editing.layer.setLatLngs(lls.map((p) => [p.lat, p.lng]));
        editing.layer.pm.enable({ allowSelfIntersection: false, snappable: true, removeVertexOn: 'contextmenu' });
        editing.undo.push(layerToGeoJSON(editing.layer));
        refreshMeasure();
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
        $('ptSquare').addEventListener('click', () => {
            const sq = AS.squarePolygon(ringLatLngs(editing.layer));
            if (sq) { replaceDraftGeometry(sq); NP.toast('Polygone équerré (rectangle le plus proche).'); }
        });
        $('ptScale').addEventListener('click', () => {
            const target = parseFloat(String($('ptArea').value).replace(',', '.'));
            if (!(target > 0)) { NP.toast('Superficie cible invalide.', 'warning'); return; }
            const cur = measureGeometry(layerToGeoJSON(editing.layer)).area;
            replaceDraftGeometry(AS.scaleToArea(ringLatLngs(editing.layer), cur, target));
            NP.toast('Superficie ajustée à ' + NP.fmt(target, 2) + ' m² (homothétie autour du centre).');
        });
        $('ptApply').addEventListener('click', () => {
            const rows = {};
            $('ptVertices').querySelectorAll('input').forEach((inp) => {
                const v = parseFloat(String(inp.value).replace(',', '.'));
                rows[inp.dataset.i] = rows[inp.dataset.i] || {};
                rows[inp.dataset.i][inp.dataset.k] = v;
            });
            const lls = Object.keys(rows).sort((a, b) => a - b).map((k) => rows[k]);
            if (lls.some((p) => !isFinite(p.lat) || !isFinite(p.lng) || Math.abs(p.lat) > 90 || Math.abs(p.lng) > 180)) {
                NP.toast('Coordonnée invalide dans le tableau.', 'error');
                return;
            }
            replaceDraftGeometry(lls);
            NP.toast('Coordonnées appliquées.');
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
                const attributes = {};
                new FormData($('parcelForm')).forEach((v, k) => { attributes[k] = v; });
                r = await NP.api('api/parcelles.php?action=create', { method: 'POST', body: { attributes, geometry, acknowledge } });
                NP.toast('Parcelle enregistrée : ' + r.parcel_reference + ' (' + NP.fmtArea(r.area_m2) + ')');
                const id = r.id;
                stopEditing();
                await loadParcels();
                if (candidates.length) renderCandidatePanel(); else openParcel(id, false);
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
        edgeLabelLayer.clearLayers();
        editing = null;
        setToolbarActive(null);
    }

    // ------------------------------------------------------------- Mesures
    let measuring = null;
    function startMeasure(kind) {
        if (editing || survey) { NP.toast('Terminez la saisie en cours avant de mesurer.', 'warning'); return; }
        stopAssist(false);
        measuring = kind;
        setToolbarActive(kind === 'Line' ? 'btnMeasureLine' : 'btnMeasureArea');
        map.pm.enableDraw(kind, { pathOptions: { color: '#1565c0', weight: 3, dashArray: '2 6', fillOpacity: 0.1 }, templineStyle: { color: '#1565c0' }, hintlineStyle: { color: '#1565c0', dashArray: '3 3' }, finishOn: kind === 'Line' ? 'dblclick' : null, allowSelfIntersection: kind === 'Line' });
        NP.toast(kind === 'Line' ? 'Cliquez les points ; double-cliquez pour terminer.' : 'Cliquez les sommets ; refermez sur le premier point.', 'info');
    }
    function finishMeasure(layer, shape) {
        layer.pm.disable();
        measureLayer.addLayer(layer);
        const gj = layerToGeoJSON(layer);
        let txt;
        if (shape === 'Line') {
            const d = AS.geodesicLength(gj.coordinates, false);
            txt = d >= 1000 ? NP.fmt(d / 1000, 3) + ' km' : NP.fmt(d, 2) + ' m';
        } else {
            txt = NP.fmtArea(AS.geodesicArea(gj.coordinates[0]));
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

    // ===================================================================
    // GPS : suivi continu, position moyennée
    // ===================================================================
    const GPS_OPTS = { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 };
    let watchId = null;
    let gpsMarker = null;
    let gpsCircle = null;
    let gpsFirst = true;
    function gpsUnavailable(err) {
        const reason = err && err.code === 1 ? 'autorisation refusée'
            : (!window.isSecureContext ? 'le navigateur exige HTTPS (ou http://localhost)' : (err ? err.message : 'non disponible'));
        NP.toast('Position GPS indisponible : ' + reason + '.', 'error');
    }
    function drawGps(lat, lng, acc, label) {
        const ll = [lat, lng];
        if (!gpsMarker) {
            gpsMarker = L.marker(ll, { icon: L.divIcon({ className: 'gps-marker', iconSize: [16, 16] }), pmIgnore: true, zIndexOffset: 1000 }).addTo(gpsLayer);
            gpsCircle = L.circle(ll, { radius: acc, color: '#1565c0', weight: 1, fillOpacity: 0.12, pmIgnore: true, interactive: false }).addTo(gpsLayer);
        }
        gpsMarker.setLatLng(ll);
        gpsCircle.setLatLng(ll).setRadius(acc);
        gpsMarker.bindPopup('<div class="pos-popup"><strong>' + NP.esc(label) + '</strong>' + positionTable(L.latLng(lat, lng))
            + '<div class="small">Précision annoncée par l\'appareil : ± ' + NP.fmt(acc, 1) + ' m</div></div>');
    }
    $('btnLocate').addEventListener('click', () => {
        const btn = $('btnLocate');
        if (watchId !== null) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
            btn.classList.remove('active');
            gpsLayer.clearLayers(); gpsMarker = null; gpsCircle = null;
            return;
        }
        if (!navigator.geolocation) { gpsUnavailable(); return; }
        btn.classList.add('active');
        gpsFirst = true;
        watchId = navigator.geolocation.watchPosition((pos) => {
            const c = pos.coords;
            drawGps(c.latitude, c.longitude, c.accuracy, 'Ma position (suivi en direct)');
            if (gpsFirst) {
                map.setView([c.latitude, c.longitude], Math.max(map.getZoom(), 19));
                gpsFirst = false;
                NP.toast('Suivi GPS activé : précision ± ' + NP.fmt(c.accuracy, 1) + ' m. Cliquez à nouveau pour arrêter.');
            }
        }, (err) => { gpsUnavailable(err); btn.classList.remove('active'); watchId = null; }, GPS_OPTS);
    });

    /**
     * Moyenne pondérée (1/précision²) des positions GPS reçues pendant `seconds`.
     * Les mesures nettement moins précises que la meilleure sont écartées.
     */
    function gpsAverage(seconds, btn) {
        return new Promise((resolve) => {
            if (!navigator.geolocation) { gpsUnavailable(); resolve(null); return; }
            const samples = [];
            const label = btn ? btn.innerHTML : '';
            const t0 = Date.now();
            if (btn) btn.disabled = true;
            const id = navigator.geolocation.watchPosition((pos) => {
                samples.push({ lat: pos.coords.latitude, lng: pos.coords.longitude, acc: Math.max(pos.coords.accuracy, 0.5) });
                if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> GPS : ' + samples.length + ' mesure(s), '
                    + Math.max(0, seconds - Math.round((Date.now() - t0) / 1000)) + ' s…';
            }, (err) => { finish(err); }, GPS_OPTS);
            const timer = setTimeout(() => finish(null), seconds * 1000);
            function finish(err) {
                clearTimeout(timer);
                navigator.geolocation.clearWatch(id);
                if (btn) { btn.disabled = false; btn.innerHTML = label; }
                if (!samples.length) { gpsUnavailable(err); resolve(null); return; }
                const best = Math.min(...samples.map((s) => s.acc));
                const kept = samples.filter((s) => s.acc <= Math.max(best * 2, best + 3));
                let sw = 0, la = 0, ln = 0;
                kept.forEach((s) => { const w = 1 / (s.acc * s.acc); sw += w; la += s.lat * w; ln += s.lng * w; });
                const fix = { lat: la / sw, lng: ln / sw, acc: Math.sqrt(1 / sw), n: kept.length, best };
                drawGps(fix.lat, fix.lng, Math.max(fix.acc, best / Math.sqrt(kept.length)), 'Position moyennée (' + kept.length + ' mesures)');
                NP.toast('Position moyennée sur ' + kept.length + ' mesure(s) : ± ' + NP.fmt(Math.max(fix.acc, 0.1), 1) + ' m (meilleure mesure ± ' + NP.fmt(best, 1) + ' m).');
                resolve(fix);
            }
        });
    }

    // ===================================================================
    // Viseur central, zoom maximal, saisie de coordonnées, relevé GPS
    // ===================================================================
    function setCrosshair(on) {
        $('mapCrosshair').hidden = !on;
        const item = $('prCrosshair');
        if (item) item.classList.toggle('active', on);
    }
    const onMenu = (id, fn) => { const el = $(id); if (el) el.addEventListener('click', (e) => { e.preventDefault(); fn(); }); };
    onMenu('prCrosshair', () => setCrosshair($('mapCrosshair').hidden));
    onMenu('prZoomMax', () => {
        map.setZoom(MAXZ);
        NP.toast('Zoom maximal : la vue couvre quelques mètres. L\'image est agrandie au-delà du zoom 19 ; utilisez-la pour pointer les sommets au centimètre.', 'info');
    });
    onMenu('prGpsAvg', async () => {
        const fix = await gpsAverage(15, null);
        if (fix) { map.setView([fix.lat, fix.lng], Math.max(map.getZoom(), 20)); gpsMarker && gpsMarker.openPopup(); }
    });

    onMenu('prCoords', () => {
        if (editing || survey) { NP.toast('Terminez la saisie en cours.', 'warning'); return; }
        stopAssist(false);
        showPanel('Saisie de coordonnées', '<p class="small">Une ligne par sommet, dans l\'ordre du contour (3 sommets minimum).</p>'
            + '<div class="mb-2"><label class="form-label small mb-0" for="ceCrs">Système</label><select class="form-select form-select-sm" id="ceCrs">'
            + '<option value="wgs">WGS84 degrés décimaux — « latitude, longitude »</option>'
            + '<option value="31">UTM 31N (WGS84) — « X Y » en mètres</option><option value="32">UTM 32N (WGS84) — « X Y »</option>'
            + '<option value="33">UTM 33N (WGS84) — « X Y »</option></select></div>'
            + '<textarea class="form-control form-control-sm font-monospace mb-2" id="ceText" rows="8" placeholder="13.5116000, 2.1254000\n13.5117800, 2.1254000\n13.5117800, 2.1255850\n13.5116000, 2.1255850"></textarea>'
            + '<button class="btn btn-sm btn-primary" id="ceGo"><i class="bi bi-check2"></i> Tracer la parcelle</button>'
            + '<div id="ceErr" class="small text-danger mt-2"></div>');
        $('ceGo').addEventListener('click', () => {
            const crs = $('ceCrs').value;
            const pts = [];
            const lines = $('ceText').value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
            for (let i = 0; i < lines.length; i++) {
                const nums = lines[i].replace(/(\d),(\d)/g, '$1.$2').split(/[\s;,]+/).map(Number).filter((n) => !isNaN(n));
                if (nums.length < 2) { $('ceErr').textContent = 'Ligne ' + (i + 1) + ' illisible.'; return; }
                const ll = crs === 'wgs' ? { lat: nums[0], lng: nums[1] } : AS.fromUtm(nums[0], nums[1], parseInt(crs, 10));
                if (ll.lat < C.bounds.min_lat || ll.lat > C.bounds.max_lat || ll.lng < C.bounds.min_lng || ll.lng > C.bounds.max_lng) {
                    $('ceErr').textContent = 'Ligne ' + (i + 1) + ' : point hors du Niger (latitude et longitude inversées ? mauvais système ?).';
                    return;
                }
                pts.push(ll);
            }
            if (pts.length < 3) { $('ceErr').textContent = '3 sommets minimum.'; return; }
            startCreate(polygonFromLatLngs(pts), { survey: 'releve_terrain', note: 'Parcelle tracée à partir de ' + pts.length + ' coordonnées saisies.' });
        });
    });

    // Relevé GPS sommet par sommet (agent sur le terrain)
    let survey = null;
    function stopSurvey() {
        if (!survey) return;
        if (survey.line) map.removeLayer(survey.line);
        survey.markers.forEach((m) => map.removeLayer(m));
        survey = null;
    }
    function renderSurvey() {
        const s = survey;
        if (s.line) map.removeLayer(s.line);
        s.markers.forEach((m) => map.removeLayer(m));
        s.markers = s.pts.map((p, i) => L.marker([p.lat, p.lng], {
            icon: L.divIcon({ className: 'parcel-label', html: '<b>' + (i + 1) + '</b>', iconSize: [18, 18] }), pmIgnore: true,
        }).addTo(map));
        s.line = s.pts.length > 1 ? L.polyline(s.pts.map((p) => [p.lat, p.lng]).concat(s.pts.length > 2 ? [[s.pts[0].lat, s.pts[0].lng]] : []),
            { color: '#1565c0', dashArray: '4 4', pmIgnore: true }).addTo(map) : null;
        $('svList').innerHTML = s.pts.length ? s.pts.map((p, i) => '<li>Sommet ' + (i + 1) + ' : ' + p.lat.toFixed(7) + ', ' + p.lng.toFixed(7)
            + (p.acc ? ' <span class="text-muted">(± ' + NP.fmt(p.acc, 1) + ' m, ' + p.n + ' mesures)</span>' : ' <span class="text-muted">(viseur)</span>') + '</li>').join('')
            : '<li class="text-muted">Aucun sommet.</li>';
        if (s.pts.length > 2) {
            const ring = s.pts.map((p) => [p.lng, p.lat]);
            ring.push(ring[0]);
            $('svArea').textContent = 'Superficie provisoire : ' + NP.fmtArea(AS.geodesicArea(ring));
        } else {
            $('svArea').textContent = '';
        }
    }
    onMenu('prGpsSurvey', () => {
        if (editing || survey) { NP.toast('Terminez la saisie en cours.', 'warning'); return; }
        stopAssist(false);
        survey = { pts: [], markers: [], line: null };
        showPanel('Relevé GPS sur le terrain', '<p class="small">Placez-vous à chaque angle de la parcelle, immobile, puis enregistrez le sommet. '
            + 'La position est moyennée pendant 10 secondes pour réduire l\'erreur GPS (smartphone : ± 3 à 5 m ; récepteur GNSS : bien mieux).</p>'
            + '<div class="d-grid gap-2 mb-2"><button class="btn btn-sm btn-primary" id="svGps"><i class="bi bi-broadcast"></i> Enregistrer ce sommet (GPS moyenné)</button>'
            + '<button class="btn btn-sm btn-outline-primary" id="svCenter"><i class="bi bi-plus-circle-dotted"></i> Ajouter le point du viseur</button>'
            + '<button class="btn btn-sm btn-outline-secondary" id="svUndo"><i class="bi bi-arrow-counterclockwise"></i> Retirer le dernier sommet</button>'
            + '<button class="btn btn-sm btn-success" id="svDone"><i class="bi bi-check2"></i> Terminer le contour</button></div>'
            + '<ol class="small ps-3" id="svList"></ol><div class="small fw-bold" id="svArea"></div>');
        renderSurvey();
        $('svGps').addEventListener('click', async (ev) => {
            const fix = await gpsAverage(10, ev.currentTarget);
            if (fix && survey) { survey.pts.push({ lat: fix.lat, lng: fix.lng, acc: fix.acc, n: fix.n }); renderSurvey(); map.panTo([fix.lat, fix.lng]); }
        });
        $('svCenter').addEventListener('click', () => { setCrosshair(true); const c = map.getCenter(); survey.pts.push({ lat: c.lat, lng: c.lng }); renderSurvey(); });
        $('svUndo').addEventListener('click', () => { survey.pts.pop(); renderSurvey(); });
        $('svDone').addEventListener('click', () => {
            if (survey.pts.length < 3) { NP.toast('3 sommets minimum.', 'warning'); return; }
            const pts = survey.pts.slice();
            const acc = Math.max(...pts.map((p) => p.acc || 0));
            stopSurvey();
            startCreate(polygonFromLatLngs(pts), { survey: 'releve_terrain',
                note: 'Relevé GPS de ' + pts.length + ' sommets' + (acc ? ' (précision la plus faible ± ' + NP.fmt(acc, 1) + ' m)' : '') + '. Ajustez si nécessaire.' });
        });
    });

    // ===================================================================
    // Assistant d'identification des parcelles
    // ===================================================================
    const assist = { mode: null, template: { w: 20, h: 20, angleMode: 'auto', angle: 0 } };
    let candidates = []; // { layer, area, score, source }

    function stopAssist(clearCandidates) {
        assist.mode = null;
        map.getContainer().style.cursor = '';
        if (clearCandidates) { candidateLayer.clearLayers(); candidates = []; }
        ['btnAssist'].forEach((id) => { const b = $(id); if (b) b.classList.remove('active'); });
    }
    function startAssist(mode) {
        if (editing || survey) { NP.toast('Terminez la saisie en cours.', 'warning'); return; }
        stopMeasure();
        map.pm.disableDraw();
        assist.mode = mode;
        const b = $('btnAssist'); if (b) b.classList.add('active');
        map.getContainer().style.cursor = 'crosshair';
        if (mode === 'subdivide') {
            map.pm.enableDraw('Polygon', { allowSelfIntersection: false, pathOptions: { color: '#5c3d99', dashArray: '6 4', fillOpacity: 0.05 } });
            NP.toast('Dessinez le contour de l\'îlot (4 angles), puis fermez-le.', 'info');
        }
        if ((mode === 'detect' || mode === 'template') && map.getZoom() < 17) {
            NP.toast('Zoomez sur le quartier (zoom 18 à 20, vue satellite) pour une identification fiable.', 'info');
        }
        if (mode === 'detect' && satLayer && !map.hasLayer(satLayer)) {
            NP.toast('Astuce : affichez le fond « Satellite » pour contrôler visuellement les propositions.', 'info');
        }
        renderCandidatePanel();
    }
    onMenu('asDetect', () => startAssist('detect'));
    onMenu('asTemplate', () => startAssist('template'));
    onMenu('asSubdivide', () => startAssist('subdivide'));

    function onAssistClick(ll) {
        if (assist.mode === 'detect') detectAt(ll);
        else if (assist.mode === 'template') addTemplateAt(ll);
    }

    function addCandidate(lls, info) {
        const layer = L.polygon(lls.map((p) => [p.lat, p.lng]), CANDIDATE_STYLE);
        layer._npCand = true;
        const cand = Object.assign({ layer }, info);
        candidates.push(cand);
        candidateLayer.addLayer(layer);
        layer.bindTooltip(() => NP.fmt(cand.area, 0) + ' m² · ' + NP.esc(cand.source), { sticky: true });
        renderCandidatePanel();
        return cand;
    }

    /** Orientation des parcelles voisines enregistrées (côté le plus proche), convention écran. */
    function neighborOrientation(ll) {
        if (!lastFc) return null;
        let best = null;
        lastFc.features.forEach((f) => {
            if (f.geometry.type !== 'Polygon') return;
            const ring = f.geometry.coordinates[0];
            for (let i = 0; i < ring.length - 1; i++) {
                const a = { lat: ring[i][1], lng: ring[i][0] }, b = { lat: ring[i + 1][1], lng: ring[i + 1][0] };
                const mid = { lat: (a.lat + b.lat) / 2, lng: (a.lng + b.lng) / 2 };
                const dist = AS.geodesicDistance(mid, ll);
                if (dist < 60 && AS.geodesicDistance(a, b) > 5 && (!best || dist < best.dist)) best = { dist, angle: AS.bearingScreen(a, b) };
            }
        });
        return best ? best.angle : null;
    }

    function templateAngle(ll, imageAngle) {
        const t = assist.template;
        if (t.angleMode === 'manual') return t.angle * Math.PI / 180;
        const nb = neighborOrientation(ll);
        if (nb !== null) return nb;
        if (imageAngle !== undefined && imageAngle !== null) return imageAngle;
        return 0;
    }

    function addTemplateAt(ll, imageAngle, source) {
        const t = assist.template;
        const ang = templateAngle(ll, imageAngle);
        const lls = AS.rectangleLatLngs(ll.lat, ll.lng, t.w, t.h, ang);
        const area = AS.geodesicArea(lls);
        return addCandidate(lls, { area, score: null, source: source || ('gabarit ' + t.w + ' × ' + t.h + ' m') });
    }

    /** Charge les tuiles satellite autour du point dans un canevas (CORS requis). */
    function loadSatellitePatch(ll, z, radiusTiles) {
        const p = AS.latLngToPixel(ll.lat, ll.lng, z);
        const tx = Math.floor(p.x / 256), ty = Math.floor(p.y / 256);
        const n = 2 * radiusTiles + 1;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = n * 256;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const jobs = [];
        for (let dx = -radiusTiles; dx <= radiusTiles; dx++) for (let dy = -radiusTiles; dy <= radiusTiles; dy++) {
            jobs.push(new Promise((resolve, reject) => {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.referrerPolicy = 'strict-origin-when-cross-origin';
                img.onload = () => { ctx.drawImage(img, (dx + radiusTiles) * 256, (dy + radiusTiles) * 256); resolve(); };
                img.onerror = () => reject(new Error('tuile'));
                img.src = SATELLITE_URL.replace('{z}', z).replace('{x}', tx + dx).replace('{y}', ty + dy);
            }));
        }
        return Promise.all(jobs).then(() => ({
            canvas, ctx, z,
            originX: (tx - radiusTiles) * 256, originY: (ty - radiusTiles) * 256,
            sx: Math.round(p.x - (tx - radiusTiles) * 256), sy: Math.round(p.y - (ty - radiusTiles) * 256),
        }));
    }

    async function detectAt(ll) {
        const t = assist.template;
        $('mapLoading').hidden = false;
        try {
            const z = 19;
            let patch;
            try {
                patch = await loadSatellitePatch(ll, z, 1);
            } catch (e) {
                addTemplateAt(ll, null, 'gabarit (image satellite indisponible)');
                NP.toast('Image satellite inaccessible (connexion Internet ?) : un gabarit de ' + t.w + ' × ' + t.h + ' m a été proposé à la place.', 'warning');
                return;
            }
            const data = patch.ctx.getImageData(0, 0, patch.canvas.width, patch.canvas.height);
            const mpp = AS.metersPerPixel(ll.lat, z);
            const res = AS.detectParcel(data.data, data.width, data.height, patch.sx, patch.sy, { mpp, targetArea: t.w * t.h });
            if (res && res.score >= 0.35) {
                const lls = res.corners.map(([x, y]) => AS.pixelToLatLng(x + patch.originX, y + patch.originY, z));
                const area = AS.geodesicArea(lls);
                addCandidate(lls, { area, score: res.score, source: 'détection image (confiance ' + Math.round(res.score * 100) + ' %)' });
                NP.toast('Parcelle proposée : ' + NP.fmt(area, 0) + ' m², confiance ' + Math.round(res.score * 100) + ' %. Vérifiez et ajustez avant d\'enregistrer.');
            } else {
                const ori = res && res.orientation && res.orientation.strength > 0.15 ? res.orientation.angle : null;
                addTemplateAt(ll, ori, 'gabarit orienté (contours peu nets)');
                NP.toast('Limites peu visibles sur l\'image : gabarit de ' + t.w + ' × ' + t.h + ' m orienté selon le bâti environnant.', 'info');
            }
        } catch (e) {
            NP.toast('Détection impossible : ' + e.message, 'error');
        } finally {
            $('mapLoading').hidden = true;
        }
    }

    function finishBlock(layer) {
        const lls = ringLatLngs(layer);
        map.removeLayer(layer);
        const t = assist.template;
        const lots = AS.subdivide(lls, t.w, t.h);
        if (!lots.length) { NP.toast('Îlot trop petit pour des lots de ' + t.w + ' × ' + t.h + ' m.', 'warning'); return; }
        lots.forEach((l) => {
            const area = AS.geodesicArea(l);
            addCandidate(l, { area, score: null, source: 'lot ' + t.w + ' × ' + t.h + ' m' });
        });
        NP.toast(lots.length + ' lots proposés dans l\'îlot.');
        assist.mode = null;
        map.getContainer().style.cursor = '';
    }

    function renderCandidatePanel() {
        if (editing) return;
        const t = assist.template;
        const modeHelp = {
            detect: 'Cliquez à l\'intérieur d\'une concession (dans la cour) sur l\'image satellite : l\'assistant cherche ses murs et propose son contour.',
            template: 'Cliquez au centre de chaque parcelle : un gabarit aux dimensions choisies est posé, orienté comme les parcelles voisines.',
            subdivide: 'Dessinez le contour de l\'îlot : il sera découpé en lots aux dimensions choisies.',
        };
        const presets = [[20, 20], [15, 25], [16, 25], [20, 25], [20, 30], [10, 40], [25, 40]];
        let html = '<div class="alert alert-warning py-1 small mb-2">Propositions automatiques indicatives : vérifiez chaque contour sur le terrain ou l\'image avant de l\'enregistrer.</div>';
        html += '<div class="btn-group btn-group-sm w-100 mb-2" role="group">'
            + ['detect:Détecter', 'template:Gabarit', 'subdivide:Lotir'].map((m) => {
                const [k, l] = m.split(':');
                return '<button class="btn btn-outline-primary' + (assist.mode === k ? ' active' : '') + '" data-mode="' + k + '">' + l + '</button>';
            }).join('') + '</div>';
        if (assist.mode) html += '<p class="small mb-2">' + NP.esc(modeHelp[assist.mode]) + '</p>';
        html += '<div class="row g-1 small mb-2 align-items-end">'
            + '<div class="col-12"><label class="form-label mb-0">Dimensions des parcelles (m)</label><select class="form-select form-select-sm" id="asPreset">'
            + presets.map(([w, h]) => '<option value="' + w + 'x' + h + '"' + (w === t.w && h === t.h ? ' selected' : '') + '>' + w + ' × ' + h + ' m = ' + (w * h) + ' m²' + (w * h === 400 ? ' (format courant)' : '') + '</option>').join('')
            + '<option value="custom"' + (presets.some(([w, h]) => w === t.w && h === t.h) ? '' : ' selected') + '>Personnalisé…</option></select></div>'
            + '<div class="col-4"><input class="form-control form-control-sm" id="asW" type="number" step="0.01" min="1" value="' + t.w + '" aria-label="Largeur"></div>'
            + '<div class="col-4"><input class="form-control form-control-sm" id="asH" type="number" step="0.01" min="1" value="' + t.h + '" aria-label="Profondeur"></div>'
            + '<div class="col-4 text-muted">= <span id="asA">' + NP.fmt(t.w * t.h, 0) + '</span> m²</div>'
            + '<div class="col-6"><label class="form-label mb-0">Orientation</label><select class="form-select form-select-sm" id="asAngleMode">'
            + '<option value="auto"' + (t.angleMode === 'auto' ? ' selected' : '') + '>Automatique (voisins / image)</option>'
            + '<option value="manual"' + (t.angleMode === 'manual' ? ' selected' : '') + '>Angle manuel</option></select></div>'
            + '<div class="col-6"><input class="form-control form-control-sm" id="asAngle" type="number" step="0.5" value="' + t.angle + '" aria-label="Angle en degrés" ' + (t.angleMode === 'manual' ? '' : 'disabled') + '></div>'
            + '</div>';
        html += '<h6 class="small fw-bold mt-2">Propositions (' + candidates.length + ')</h6>';
        html += candidates.length ? candidates.map((c, i) => '<div class="candidate-item small' + (c.score !== null && c.score < 0.5 ? ' low' : '') + '">'
            + '<div class="d-flex justify-content-between"><strong>N° ' + (i + 1) + ' · ' + NP.fmt(c.area, 1) + ' m²</strong>'
            + '<span><button class="btn btn-sm btn-link p-0 me-2" data-edit="' + i + '" title="Ajuster et enregistrer seule">Ajuster</button>'
            + '<button class="btn btn-sm btn-link p-0 me-2" data-rot="' + i + '" title="Pivoter de 5°">↻5°</button>'
            + '<button class="btn btn-sm btn-link text-danger p-0" data-del="' + i + '" title="Supprimer">✕</button></span></div>'
            + '<div class="text-muted">' + NP.esc(c.source) + '</div></div>').join('')
            : '<p class="small text-muted">Aucune proposition pour l\'instant.</p>';
        if (candidates.length) {
            html += '<form id="candForm" class="small border-top pt-2 mt-2">' + attributesForm('cf', { survey: 'indicative' }) + '</form>'
                + '<div id="candResult"></div>'
                + '<div class="d-flex gap-2"><button class="btn btn-sm btn-success flex-grow-1" id="candSave"><i class="bi bi-save"></i> Enregistrer les ' + candidates.length + ' parcelle(s)</button>'
                + '<button class="btn btn-sm btn-outline-danger" id="candClear">Tout effacer</button></div>';
        }
        showPanel('Assistant d\'identification', html);
        const body = $('panelBody');
        body.querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => startAssist(b.dataset.mode)));
        const syncTemplate = () => {
            t.w = Math.max(1, parseFloat($('asW').value) || 20);
            t.h = Math.max(1, parseFloat($('asH').value) || 20);
            t.angleMode = $('asAngleMode').value;
            t.angle = parseFloat($('asAngle').value) || 0;
            $('asA').textContent = NP.fmt(t.w * t.h, 0);
            $('asAngle').disabled = t.angleMode !== 'manual';
        };
        $('asPreset').addEventListener('change', () => {
            const v = $('asPreset').value;
            if (v !== 'custom') { const [w, h] = v.split('x').map(Number); $('asW').value = w; $('asH').value = h; }
            syncTemplate();
        });
        ['asW', 'asH', 'asAngle', 'asAngleMode'].forEach((id) => $(id).addEventListener('change', syncTemplate));
        body.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => {
            const c = candidates.splice(parseInt(b.dataset.del, 10), 1)[0];
            candidateLayer.removeLayer(c.layer);
            renderCandidatePanel();
        }));
        body.querySelectorAll('[data-rot]').forEach((b) => b.addEventListener('click', () => {
            const c = candidates[parseInt(b.dataset.rot, 10)];
            c.layer.setLatLngs(AS.rotate(ringLatLngs(c.layer), 5 * Math.PI / 180).map((p) => [p.lat, p.lng]));
        }));
        body.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
            const i = parseInt(b.dataset.edit, 10);
            const c = candidates.splice(i, 1)[0];
            candidateLayer.removeLayer(c.layer);
            assist.mode = null;
            map.getContainer().style.cursor = '';
            startCreate(polygonFromLatLngs(ringLatLngs(c.layer)), { note: 'Proposition de l\'assistant (' + c.source + ') : ajustez les sommets si nécessaire.' });
        }));
        const save = $('candSave');
        if (save) save.addEventListener('click', () => saveCandidates(false));
        const clear = $('candClear');
        if (clear) clear.addEventListener('click', () => { if (window.confirm('Effacer toutes les propositions ?')) { stopAssist(true); renderCandidatePanel(); } });
    }

    async function saveCandidates(acknowledge) {
        const attributes = {};
        new FormData($('candForm')).forEach((v, k) => { attributes[k] = v; });
        const btn = $('candSave');
        btn.disabled = true;
        const saved = [];
        const warned = [];
        const failed = [];
        for (const c of candidates.slice()) {
            const geometry = layerToGeoJSON(c.layer);
            try {
                const r = await NP.api('api/parcelles.php?action=create', { method: 'POST', body: { attributes, geometry, acknowledge: acknowledge && c._warned } });
                saved.push(r.parcel_reference);
                candidateLayer.removeLayer(c.layer);
                candidates.splice(candidates.indexOf(c), 1);
            } catch (e) {
                if (e.status === 409) { c._warned = true; warned.push(e.data.warnings.join(' ')); } else failed.push(e.message);
            }
        }
        await loadParcels();
        renderCandidatePanel();
        let msg = '';
        if (saved.length) msg += '<div class="alert alert-success py-1 small">' + saved.length + ' parcelle(s) enregistrée(s) : ' + saved.map(NP.esc).join(', ') + '</div>';
        if (warned.length) {
            msg += '<div class="alert alert-warning py-1 small">' + warned.length + ' proposition(s) chevauchent des parcelles existantes :<ul class="mb-1">'
                + warned.map((w) => '<li>' + NP.esc(w) + '</li>').join('') + '</ul><button class="btn btn-sm btn-warning" id="candForce">Enregistrer quand même (signalées pour vérification)</button></div>';
        }
        if (failed.length) msg += '<div class="alert alert-danger py-1 small">' + failed.map(NP.esc).join('<br>') + '</div>';
        const out = $('candResult');
        if (out) out.innerHTML = msg;
        else if (msg) $('panelBody').insertAdjacentHTML('afterbegin', msg);
        const force = $('candForce');
        if (force) force.addEventListener('click', () => saveCandidates(true));
    }

    // ------------------------------------------------- Vue d'ensemble
    $('btnNiger').addEventListener('click', () => map.fitBounds(nigerBounds));

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
            html += '<a href="#" class="list-group-item list-group-item-action small" data-lat="' + lat + '" data-lng="' + lng + '"><i class="bi bi-geo-alt"></i> Aller aux coordonnées ' + lat.toFixed(7) + ', ' + lng.toFixed(7) + '</a>';
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
            const res = await fetch(url, { headers: { Accept: 'application/json' }, referrerPolicy: 'strict-origin-when-cross-origin' });
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
            const ll = L.latLng(parseFloat(a.dataset.lat), parseFloat(a.dataset.lng));
            map.setView(ll, 19);
            showPositionPopup(ll);
            return;
        }
        if (a.dataset.bbox) {
            const b = JSON.parse(a.dataset.bbox).map(parseFloat);
            map.fitBounds([[b[0], b[2]], [b[1], b[3]]], { maxZoom: 17 });
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
        if (editing || survey || candidates.length) { e.preventDefault(); e.returnValue = ''; }
    });

    // ------------------------------------------------------------- Démarrage
    if (C.point) {
        map.setView(C.point, 19);
        showPositionPopup(L.latLng(C.point[0], C.point[1]));
    }
    if (C.focus) openParcel(C.focus, true);
    if (C.edit) {
        NP.api('api/parcelles.php?action=get&id=' + C.edit).then((r) => {
            if (r.parcel.can_edit) startEdit(r.parcel); else { NP.toast('Modification non autorisée pour cette parcelle.', 'error'); openParcel(C.edit, true); }
        }).catch((e) => NP.toast(e.message, 'error'));
    }
    updateZoomInfo();
    loadParcels();
    // utilisé par les tests automatisés
    window.NPMap = { map, openParcel, loadParcels, detectAt, addTemplateAt, startAssist, candidates: () => candidates, gpsAverage };
})();
