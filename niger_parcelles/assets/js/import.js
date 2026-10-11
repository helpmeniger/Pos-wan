/* Aperçu cartographique d'une importation (avant enregistrement). */
(function () {
    'use strict';
    const d = JSON.parse(document.getElementById('import-preview').textContent);
    const map = L.map('importMap');
    if (d.tiles.osm) L.tileLayer(d.tiles.osm.url, { attribution: d.tiles.osm.attribution, maxZoom: 19 }).addTo(map);
    const group = L.featureGroup().addTo(map);
    d.items.forEach((it) => {
        if (!it.geometry) return;
        L.geoJSON(it.geometry, { style: { color: it.warn ? '#ef6c00' : '#0d8b3e', weight: 2, fillOpacity: 0.25 } })
            .bindTooltip('N° ' + it.index + ' — ' + NP.esc(it.label)).addTo(group);
    });
    if (group.getLayers().length) map.fitBounds(group.getBounds(), { maxZoom: 18, padding: [20, 20] });
    else map.setView([17.6, 8.08], 6);
})();
