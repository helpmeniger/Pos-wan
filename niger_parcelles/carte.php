<?php
/** Carte foncière interactive : consultation, recherche, dessin et modification des parcelles. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_login();
require_permission('parcel.view');

$tiles = config('tile_layers');
if (setting('satellite_enabled', '1') !== '1') {
    unset($tiles['satellite'], $tiles['labels']);
}
$mapConfig = [
    'center' => [(float) setting('map_center_lat', 17.6078), (float) setting('map_center_lng', 8.0817)],
    'zoom' => (int) setting('map_zoom', 6),
    'bounds' => geo_bounds(),
    'tiles' => $tiles,
    'geocoder' => config('geocoder_url'),
    'areas' => array_map(static fn ($a) => ['id' => $a['id'], 'label' => $a['label']], admin_area_options()),
    'labels' => [
        'category' => labels('category'), 'verification_status' => labels('verification_status'),
        'survey_level' => labels('survey_level'), 'declared_status' => labels('declared_status'),
    ],
    'focus' => isset($_GET['parcel']) && ctype_digit((string) $_GET['parcel']) ? (int) $_GET['parcel'] : null,
    'edit' => isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) ? (int) $_GET['edit'] : null,
    'point' => isset($_GET['lat'], $_GET['lng']) && is_numeric($_GET['lat']) && is_numeric($_GET['lng'])
        ? [(float) $_GET['lat'], (float) $_GET['lng']] : null,
    'hasAdminBoundaries' => (bool) db_value('SELECT 1 FROM administrative_areas WHERE geojson IS NOT NULL LIMIT 1'),
];

$pageTitle = 'Carte foncière';
$activeMenu = 'carte';
$bodyClass = 'page-map';
$vendor = ['leaflet', 'geoman', 'turf'];
$scripts = ['map.js'];
require __DIR__ . '/includes/header.php';
?>
<script type="application/json" id="map-config"><?= json_encode($mapConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="map-shell">
    <div id="map" role="application" aria-label="Carte interactive du Niger"></div>

    <div class="map-toolbar" id="mapToolbar">
        <?php if (can('parcel.create')): ?>
            <button class="btn btn-sm btn-light" id="btnDraw" title="Dessiner une nouvelle parcelle"><i class="bi bi-pentagon"></i> Nouvelle parcelle</button>
        <?php endif; ?>
        <div class="btn-group">
            <button class="btn btn-sm btn-light" id="btnMeasureLine" title="Mesurer une distance"><i class="bi bi-rulers"></i> Distance</button>
            <button class="btn btn-sm btn-light" id="btnMeasureArea" title="Mesurer une superficie"><i class="bi bi-bounding-box-circles"></i> Surface</button>
            <button class="btn btn-sm btn-light" id="btnMeasureClear" title="Effacer les mesures"><i class="bi bi-eraser"></i></button>
        </div>
        <button class="btn btn-sm btn-light" id="btnFilters" title="Filtrer les parcelles"><i class="bi bi-funnel"></i> Filtres</button>
        <button class="btn btn-sm btn-light" id="btnLocate" title="Centrer sur ma position GPS"><i class="bi bi-crosshair"></i></button>
        <button class="btn btn-sm btn-light" id="btnNiger" title="Vue d'ensemble du Niger"><i class="bi bi-globe-europe-africa"></i></button>
    </div>

    <div class="map-search">
        <div class="input-group input-group-sm shadow-sm">
            <input class="form-control" id="mapSearch" type="search" placeholder="Parcelle, localité, « 13.51, 2.11 »…" aria-label="Rechercher sur la carte" autocomplete="off">
            <button class="btn btn-primary" id="mapSearchBtn" title="Rechercher"><i class="bi bi-search"></i></button>
        </div>
        <div class="list-group shadow mt-1" id="mapSearchResults" hidden></div>
    </div>

    <div class="map-filters card shadow-sm" id="mapFilters" hidden>
        <div class="card-body p-2">
            <div class="d-flex justify-content-between align-items-center mb-2"><strong class="small">Filtrer les parcelles</strong>
                <button class="btn-close btn-sm" id="btnFiltersClose" aria-label="Fermer"></button></div>
            <select class="form-select form-select-sm mb-2" id="fStatus" aria-label="Statut">
                <option value="">Tous les statuts</option>
                <?php foreach (labels('verification_status') as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm mb-2" id="fCategory" aria-label="Catégorie">
                <option value="">Toutes les catégories</option>
                <?php foreach (labels('category') as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm mb-2" id="fArea" aria-label="Zone">
                <option value="">Toutes les zones</option>
                <?php foreach ($mapConfig['areas'] as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['label']) ?></option><?php endforeach; ?>
            </select>
            <div class="form-check form-check-sm small mb-2">
                <input class="form-check-input" type="checkbox" id="fOverlap"><label class="form-check-label" for="fOverlap">Chevauchements à vérifier uniquement</label>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1" id="btnFiltersApply">Appliquer</button>
                <button class="btn btn-sm btn-outline-secondary" id="btnFiltersReset">Réinitialiser</button>
            </div>
        </div>
    </div>

    <div class="map-loading" id="mapLoading" hidden><span class="badge text-bg-dark"><span class="spinner-border spinner-border-sm"></span> Chargement…</span></div>
    <div class="map-status" id="mapStatus"><span class="tile-indicator" id="tileIndicator"></span><span id="tileText">Fond de carte</span> · <span id="cursorCoords">—</span></div>

    <aside class="map-panel" id="mapPanel" hidden aria-live="polite">
        <div class="panel-head">
            <strong id="panelTitle">Détails</strong>
            <button class="btn-close" id="panelClose" aria-label="Fermer le panneau"></button>
        </div>
        <div class="panel-body" id="panelBody"></div>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
