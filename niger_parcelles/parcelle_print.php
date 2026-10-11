<?php
/** Carte de situation imprimable d'une parcelle (impression navigateur ou « Enregistrer en PDF »). */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_login();
require_permission('parcel.view');
$id = (int) ($_GET['id'] ?? 0);
$parcel = ParcelService::getOrFail($id);
$geometry = ParcelService::currentGeometry($id);
$areas = admin_area_breakdown($parcel['administrative_area_id'] ? (int) $parcel['administrative_area_id'] : null);
audit_log('impression_carte_situation', 'parcel', $id);

$pageTitle = 'Carte de situation ' . $parcel['parcel_reference'];
$activeMenu = 'parcelles';
$vendor = ['leaflet'];
$scripts = ['parcel-details.js'];
require __DIR__ . '/includes/header.php';
$mapData = ['geometry' => $geometry, 'versions' => [['version' => 1, 'geometry' => $geometry, 'date' => '']], 'tiles' => config('tile_layers')];
?>
<script type="application/json" id="parcel-map-data"><?= json_encode($mapData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="d-flex justify-content-between mb-2 no-print">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('parcelle_details.php', ['id' => $id])) ?>">Retour à la fiche</a>
    <button class="btn btn-sm btn-primary" data-print><i class="bi bi-printer"></i> Imprimer / Enregistrer en PDF</button>
</div>
<div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
        <div><h1 class="h4 mb-0">Carte de situation</h1><div class="ref fs-5"><?= e($parcel['parcel_reference']) ?></div></div>
        <div class="text-end small">Édité le <?= date('d/m/Y à H:i') ?><br><?= e(setting('organisation_name', 'Niger Parcelles')) ?></div>
    </div>
    <div id="miniMap" class="mini-map print-map my-3" style="height:480px"></div>
    <div class="row small">
        <div class="col-6">
            <strong>Localisation :</strong> <?= e(implode(' › ', array_filter([$areas['region'], $areas['departement'], $areas['commune'], $areas['quartier'] ?? $areas['village']]))) ?: '—' ?><br>
            <strong>Description :</strong> <?= e($parcel['locality_description'] ?? '—') ?><br>
            <strong>Centre :</strong> <?= sprintf('%.7f, %.7f', $parcel['centroid_lat'], $parcel['centroid_lng']) ?> (WGS84)
        </div>
        <div class="col-6">
            <strong>Superficie :</strong> <?= e(fmt_area($parcel['area_m2'])) ?><br>
            <strong>Périmètre :</strong> <?= fmt_number($parcel['perimeter_m'], 2) ?> m<br>
            <strong>Réf. cadastrale :</strong> <?= e($parcel['cadastral_reference'] ?? 'non renseignée') ?> · <strong>Statut :</strong> <?= e(label('verification_status', $parcel['verification_status'])) ?>
        </div>
    </div>
    <p class="legal-notice mt-3 mb-0">Document de gestion édité par l'application Niger Parcelles. Il ne constitue pas, à lui seul, un titre foncier officiel.
        Délimitation : <?= e(label('survey_level', $parcel['survey_level'])) ?>. Le fond de carte (OpenStreetMap / imagerie) est fourni à titre indicatif.</p>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
