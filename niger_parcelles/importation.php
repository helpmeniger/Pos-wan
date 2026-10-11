<?php
/** Importation de données géographiques : envoi, analyse, aperçu, confirmation, rapport. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('import.run');
$uid = (int) $user['id'];
$error = null;

if (request_method() === 'POST') {
    require_csrf();
    try {
        if (($_POST['action'] ?? '') === 'analyze') {
            $crs = (string) ($_POST['crs'] ?? 'auto');
            if ($crs !== 'auto' && !isset(Crs::SUPPORTED[$crs])) {
                throw new UserFacingException('Système de coordonnées invalide.');
            }
            [$path, $format] = ImportService::acceptUpload($_FILES['file'] ?? []);
            try {
                $state = ImportService::analyze($path, $format, (string) ($_FILES['file']['name'] ?? 'fichier'), $crs, $uid);
            } finally {
                @unlink($path);
            }
            redirect('importation.php', ['token' => $state['token']]);
        }
        if (($_POST['action'] ?? '') === 'commit') {
            if (empty($_POST['confirm'])) {
                throw new UserFacingException('Cochez la case de confirmation avant d\'enregistrer.');
            }
            $res = ImportService::commit((string) ($_POST['token'] ?? ''), $_POST, $uid);
            flash($res['status'] === 'termine' ? 'success' : 'danger', $res['status'] === 'termine'
                ? count($res['imported']) . ' parcelle(s) importée(s).' : 'Importation annulée : ' . ($res['fatal'] ?? 'erreur'));
            redirect('importation.php', ['batch' => $res['batch_id']]);
        }
    } catch (UserFacingException $e) {
        $error = $e->getMessage();
    }
}

$state = null;
$batch = null;
if (!empty($_GET['token'])) {
    try {
        $state = ImportService::loadState((string) $_GET['token'], $uid);
    } catch (UserFacingException $e) {
        $error = $e->getMessage();
    }
}
if (!empty($_GET['batch'])) {
    $batch = db_one('SELECT b.*, u.full_name FROM import_batches b LEFT JOIN users u ON u.id = b.created_by WHERE b.id = ?', [(int) $_GET['batch']]);
}
$recent = db_all('SELECT b.*, u.full_name FROM import_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 10');

$pageTitle = 'Importation';
$activeMenu = 'importation';
$vendor = $state ? ['leaflet'] : [];
$scripts = $state ? ['import.js'] : [];
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><i class="bi bi-cloud-upload"></i> Importation de données géographiques</h1></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($batch): $rep = json_decode((string) $batch['report'], true) ?: []; ?>
<div class="card mb-3"><div class="card-header">Rapport d'importation n° <?= (int) $batch['id'] ?> — <?= e($batch['filename']) ?></div><div class="card-body">
    <p class="mb-2"><strong>Statut :</strong> <?= $batch['status'] === 'termine' ? '<span class="badge text-bg-success">Terminée</span>' : '<span class="badge text-bg-danger">Échec (aucune donnée enregistrée)</span>' ?>
        · <?= e($batch['format']) ?> · <?= e($batch['source_crs']) ?> · <?= e(fmt_date($batch['created_at'], true)) ?> · <?= e($batch['full_name'] ?? '') ?></p>
    <p><?= (int) $batch['feature_count'] ?> entité(s) lue(s) · <strong><?= (int) $batch['imported_count'] ?> importée(s)</strong> · <?= (int) $batch['error_count'] ?> en erreur · <?= (int) $batch['warning_count'] ?> écartée(s) pour avertissement</p>
    <?php if (!empty($rep['fatal'])): ?><div class="alert alert-danger"><?= e($rep['fatal']) ?></div><?php endif; ?>
    <?php if (!empty($rep['imported'])): ?><h2 class="h6">Parcelles créées</h2><ul class="small">
        <?php foreach ($rep['imported'] as $it): ?><li>Entité <?= (int) $it['index'] ?> (<?= e($it['label']) ?>) → <a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $it['id']])) ?>"><?= e($it['reference']) ?></a>
            <?= $it['overlaps'] ? '<span class="text-danger">· chevauche ' . e(implode(', ', $it['overlaps'])) . '</span>' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php foreach (['errors' => 'Entités en erreur (non importées)', 'skipped' => 'Entités écartées (avertissements non acceptés)'] as $k => $t): if (!empty($rep[$k])): ?>
        <h2 class="h6"><?= e($t) ?></h2><ul class="small"><?php foreach ($rep[$k] as $it): ?><li>Entité <?= (int) $it['index'] ?> (<?= e($it['label']) ?>) : <?= e(implode(' ', $it['messages'])) ?></li><?php endforeach; ?></ul>
    <?php endif; endforeach; ?>
</div></div>
<?php endif; ?>

<?php if ($state): $s = $state['summary']; ?>
<script type="application/json" id="import-preview"><?= json_encode(['tiles' => config('tile_layers'), 'items' => array_map(static fn ($it) => ['index' => $it['index'], 'label' => $it['label'],
    'geometry' => $it['geometry'] ?? null, 'warn' => (bool) $it['warnings']], array_filter($state['items'], static fn ($it) => !$it['errors']))], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="card mb-3"><div class="card-header">2. Aperçu et contrôle — <?= e($state['filename']) ?></div><div class="card-body">
    <div class="row g-3">
        <div class="col-lg-5">
            <ul class="list-unstyled small">
                <li><strong>Format :</strong> <?= e(ImportService::FORMATS[$state['format']] ?? $state['format']) ?></li>
                <li><strong>Système de coordonnées :</strong> <?= e($state['crs']) ?> — <?= e(Crs::SUPPORTED[$state['crs']] ?? '') ?> (<?= e($state['crs_source'] ?? '') ?>)</li>
                <?php if ($state['prj']): ?><li class="text-muted text-break"><strong>.prj :</strong> <?= e($state['prj']) ?></li><?php endif; ?>
                <li><strong>Entités :</strong> <?= (int) $s['total'] ?> · <span class="text-success"><?= (int) $s['valid'] ?> valides</span> · <span class="text-danger"><?= (int) $s['errors'] ?> en erreur</span> · <span class="text-warning"><?= (int) $s['warnings'] ?> avec avertissement</span></li>
            </ul>
            <?php foreach ($state['warnings'] as $w): ?><div class="alert alert-warning py-1 small"><?= e($w) ?></div><?php endforeach; ?>
            <form method="post" class="border rounded p-2 small" data-confirm="Enregistrer les entités sélectionnées dans la base ?">
                <?= csrf_field() ?><input type="hidden" name="action" value="commit"><input type="hidden" name="token" value="<?= e($state['token']) ?>">
                <div class="mb-2"><label class="form-label mb-0" for="area_id">Zone administrative à appliquer</label><select class="form-select form-select-sm" id="area_id" name="area_id"><option value="">— Aucune —</option>
                    <?php foreach (admin_area_options() as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['label']) ?></option><?php endforeach; ?></select></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label mb-0" for="survey_level">Nature de la délimitation</label><select class="form-select form-select-sm" id="survey_level" name="survey_level">
                        <?php foreach (labels('survey_level') as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'releve_terrain' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label mb-0" for="category">Catégorie (si absente du fichier)</label><select class="form-select form-select-sm" id="category" name="category"><option value="">D'après le fichier</option>
                        <?php foreach (labels('category') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="iw" name="include_warnings" value="1"><label class="form-check-label" for="iw">Importer aussi les entités avec avertissement (doublons / chevauchements signalés pour vérification)</label></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="cf" name="confirm" value="1" required><label class="form-check-label" for="cf"><strong>Je confirme l'enregistrement</strong> (transaction unique : tout ou rien)</label></div>
                <button class="btn btn-sm btn-success" <?= $s['valid'] === 0 ? 'disabled' : '' ?>><i class="bi bi-check2-circle"></i> Enregistrer les entités valides</button>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('importation.php')) ?>">Annuler</a>
            </form>
        </div>
        <div class="col-lg-7"><div id="importMap" class="mini-map" style="height:380px"></div></div>
    </div>
    <div class="table-responsive mt-3"><table class="table table-sm small">
        <thead><tr><th>N°</th><th>Entité</th><th>Résultat</th><th>Superficie</th><th>Attributs reconnus</th><th>Messages</th></tr></thead><tbody>
        <?php foreach (array_slice($state['items'], 0, 500) as $it): ?>
            <tr class="<?= $it['errors'] ? 'table-danger' : ($it['warnings'] ? 'table-warning' : '') ?>">
                <td><?= (int) $it['index'] ?></td><td><?= e($it['label']) ?></td>
                <td><?= $it['errors'] ? 'Erreur' : ($it['warnings'] ? 'Avertissement' : 'Valide') ?></td>
                <td class="text-nowrap"><?= isset($it['measure']) ? fmt_number($it['measure']['area_m2'], 2) . ' m²' : '—' ?></td>
                <td><?= e(implode(', ', array_map(static fn ($k, $v) => "$k=$v", array_keys($it['attributes']), $it['attributes']))) ?></td>
                <td><?= e(implode(' ', array_merge($it['errors'], $it['warnings']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div></div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">1. Fichier à importer</div><div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="analyze">
            <div class="mb-3"><label class="form-label" for="file">Fichier (max <?= (int) config('import_max_mb', 20) ?> Mo)</label>
                <input class="form-control" type="file" id="file" name="file" required accept=".geojson,.json,.kml,.kmz,.csv,.zip"></div>
            <div class="mb-3"><label class="form-label" for="crs">Système de coordonnées source</label>
                <select class="form-select" id="crs" name="crs"><option value="auto">Détection automatique (.prj, crs GeoJSON, KML = WGS84)</option>
                    <?php foreach (Crs::SUPPORTED as $k => $l): ?><option value="<?= e($k) ?>"><?= e($k . ' — ' . $l) ?></option><?php endforeach; ?></select>
                <div class="form-text">Les coordonnées ne sont pas supposées en WGS84 : choisissez le système si le fichier ne le déclare pas.</div></div>
            <button class="btn btn-primary"><i class="bi bi-search"></i> Analyser le fichier</button>
            <p class="small text-muted mt-2 mb-0">Aucune donnée n'est enregistrée à cette étape : un aperçu et un rapport d'erreurs vous seront présentés.</p>
        </form></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Formats acceptés</div><div class="card-body small">
        <ul>
            <li><strong>GeoJSON</strong> : FeatureCollection de Polygon (ou MultiPolygon à une partie).</li>
            <li><strong>KML / KMZ</strong> (Google Earth) : Placemark avec Polygon ; WGS84.</li>
            <li><strong>CSV</strong> : colonne <code>wkt</code> (POLYGON((x y, …))) <em>ou</em> une ligne par sommet avec <code>identifiant;longitude;latitude[;ordre]</code> (ou <code>x;y</code> en UTM).</li>
            <li><strong>Shapefile</strong> : archive ZIP contenant .shp, .dbf, .prj (et .shx, .cpg).</li>
        </ul>
        <p class="mb-1">Colonnes reconnues automatiquement : référence cadastrale (<code>ref_cadastrale</code>, <code>cadastre</code>, <code>lot</code>), catégorie, quartier/localité, adresse, titre foncier, observations.</p>
        <p class="mb-0">Systèmes pris en charge : WGS84, UTM 31N/32N/33N (WGS84), Web Mercator. Pour un autre datum (ex. Clarke 1880), convertissez d'abord le fichier avec QGIS.</p>
    </div></div></div>
</div>
<?php endif; ?>

<div class="card mt-3"><div class="card-header">Dernières importations</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>N°</th><th>Date</th><th>Fichier</th><th>Format</th><th>Importées</th><th>Erreurs</th><th>Statut</th><th>Par</th></tr></thead><tbody>
    <?php foreach ($recent as $b): ?><tr><td><a href="<?= e(url('importation.php', ['batch' => $b['id']])) ?>"><?= (int) $b['id'] ?></a></td><td><?= e(fmt_date($b['created_at'], true)) ?></td>
        <td><?= e($b['filename']) ?></td><td><?= e($b['format']) ?></td><td><?= (int) $b['imported_count'] ?>/<?= (int) $b['feature_count'] ?></td><td><?= (int) $b['error_count'] ?></td>
        <td><?= e($b['status']) ?></td><td><?= e($b['full_name'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="8" class="text-muted">Aucune importation.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
