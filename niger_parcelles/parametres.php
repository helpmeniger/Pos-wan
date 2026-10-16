<?php
/** Paramètres : général, découpage administratif, sauvegardes, journal d'audit, données de démonstration. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$me = require_login();
require_permission('settings.manage');
$tab = preg_match('/^[a-z]+$/', (string) ($_GET['tab'] ?? '')) ? $_GET['tab'] : 'general';

const SETTING_FIELDS = [
    'organisation_name' => ['Nom de l\'organisation (affiché sur les fiches)', 'text'],
    'map_center_lat' => ['Centre de la carte : latitude', 'float'], 'map_center_lng' => ['Centre de la carte : longitude', 'float'],
    'map_zoom' => ['Zoom initial (1-19)', 'int'],
    'bounds_min_lat' => ['Zone plausible : latitude min', 'float'], 'bounds_max_lat' => ['Zone plausible : latitude max', 'float'],
    'bounds_min_lng' => ['Zone plausible : longitude min', 'float'], 'bounds_max_lng' => ['Zone plausible : longitude max', 'float'],
    'min_parcel_area_m2' => ['Superficie minimale d\'une parcelle (m²)', 'float'], 'max_parcel_area_m2' => ['Superficie maximale (m²)', 'float'],
    'overlap_tolerance_m' => ['Tolérance de contiguïté (m) pour les chevauchements', 'float'],
    'max_upload_mb' => ['Taille maximale des documents (Mo)', 'int'], 'map_max_features' => ['Nombre max. de parcelles par requête carte', 'int'],
    'satellite_enabled' => ['Activer la couche satellite (Esri World Imagery)', 'bool'],
];

if (request_method() === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'settings':
                $tab = 'general';
                foreach (SETTING_FIELDS as $k => [$label, $type]) {
                    $v = $_POST[$k] ?? null;
                    if ($type === 'bool') {
                        $v = !empty($v) ? '1' : '0';
                    } elseif ($type === 'int') {
                        if (filter_var($v, FILTER_VALIDATE_INT) === false) { throw new UserFacingException("Valeur entière attendue : $label."); }
                    } elseif ($type === 'float') {
                        $v = str_replace(',', '.', (string) $v);
                        if (filter_var($v, FILTER_VALIDATE_FLOAT) === false) { throw new UserFacingException("Valeur numérique attendue : $label."); }
                    } else {
                        $v = mb_substr(trim((string) $v), 0, 150);
                    }
                    set_setting($k, (string) $v);
                }
                if ((float) setting('bounds_min_lat') >= (float) setting('bounds_max_lat') || (float) setting('bounds_min_lng') >= (float) setting('bounds_max_lng')) {
                    throw new UserFacingException('Zone plausible incohérente (min ≥ max).');
                }
                audit_log('parametres_modification');
                flash('success', 'Paramètres enregistrés.');
                break;

            case 'area_add':
            case 'area_update':
                $tab = 'decoupage';
                $name = trim((string) ($_POST['name'] ?? ''));
                $type = (string) ($_POST['area_type'] ?? '');
                $code = strtoupper(trim((string) ($_POST['code'] ?? ''))) ?: null;
                $parent = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
                if ($name === '' || mb_strlen($name) > 150 || !array_key_exists($type, labels('area_type'))) {
                    throw new UserFacingException('Nom et type de zone obligatoires.');
                }
                if ($code !== null && !preg_match('/^[A-Z]{2,5}$/', $code)) {
                    throw new UserFacingException('Le code doit comporter 2 à 5 lettres majuscules (ex. NIA).');
                }
                if ($parent !== null && !db_value('SELECT 1 FROM administrative_areas WHERE id = ?', [$parent])) {
                    throw new UserFacingException('Zone parente inconnue.');
                }
                $ext = trim((string) ($_POST['external_reference'] ?? '')) ?: null;
                if ($action === 'area_add') {
                    db_query('INSERT INTO administrative_areas (name, area_type, parent_id, code, external_reference, source) VALUES (?, ?, ?, ?, ?, ?)',
                        [$name, $type, $parent, $code, $ext, 'Saisie manuelle']);
                    audit_log('zone_creation', 'area', (int) db()->lastInsertId(), ['nom' => $name]);
                } else {
                    $id = (int) ($_POST['id'] ?? 0);
                    if ($parent === $id) {
                        throw new UserFacingException('Une zone ne peut pas être sa propre parente.');
                    }
                    db_query('UPDATE administrative_areas SET name = ?, area_type = ?, parent_id = ?, code = ?, external_reference = ? WHERE id = ?',
                        [$name, $type, $parent, $code, $ext, $id]);
                    audit_log('zone_modification', 'area', $id, ['nom' => $name, 'code' => $code]);
                }
                flash('success', 'Découpage administratif mis à jour.');
                break;

            case 'area_import':
                $tab = 'decoupage';
                $file = $_FILES['file'] ?? [];
                if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    throw new UserFacingException('Fichier GeoJSON manquant.');
                }
                if ($file['size'] > 50 * 1024 * 1024) {
                    throw new UserFacingException('Fichier trop volumineux (50 Mo max).');
                }
                $source = trim((string) ($_POST['source'] ?? ''));
                if ($source === '') {
                    throw new UserFacingException('Indiquez la source officielle ou fiable des limites (obligatoire).');
                }
                $type = (string) ($_POST['area_type'] ?? '');
                if (!array_key_exists($type, labels('area_type'))) {
                    throw new UserFacingException('Type de zone invalide.');
                }
                $nameProp = trim((string) ($_POST['name_property'] ?? 'name'));
                $crs = (string) ($_POST['crs'] ?? 'EPSG:4326');
                if (!isset(Crs::SUPPORTED[$crs])) {
                    throw new UserFacingException('Système de coordonnées invalide.');
                }
                $parent = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
                $fc = json_decode((string) file_get_contents($file['tmp_name']), true);
                if (($fc['type'] ?? '') !== 'FeatureCollection') {
                    throw new UserFacingException('Un GeoJSON FeatureCollection est attendu.');
                }
                $created = $updated = $skipped = 0;
                db_transaction(static function () use ($fc, $nameProp, $type, $parent, $crs, $source, &$created, &$updated, &$skipped) {
                    foreach ((array) $fc['features'] as $f) {
                        $name = trim((string) ($f['properties'][$nameProp] ?? ''));
                        $g = $f['geometry'] ?? null;
                        if ($name === '' || !in_array($g['type'] ?? '', ['Polygon', 'MultiPolygon'], true)) {
                            $skipped++;
                            continue;
                        }
                        $g = Crs::transformGeometry($g, $crs);
                        $lngs = $lats = [];
                        array_walk_recursive($g['coordinates'], static function ($v, $k) use (&$lngs, &$lats) {
                            if ($k === 0) { $lngs[] = (float) $v; } elseif ($k === 1) { $lats[] = (float) $v; }
                        });
                        if (!$lngs || !$lats) {
                            $skipped++;
                            continue;
                        }
                        $json = json_encode($g);
                        $existing = db_value('SELECT id FROM administrative_areas WHERE name = ? AND area_type = ?' . ($parent ? ' AND parent_id = ?' : ''),
                            $parent ? [$name, $type, $parent] : [$name, $type]);
                        if ($existing) {
                            db_query('UPDATE administrative_areas SET geojson = ?, source = ?, min_lat = ?, max_lat = ?, min_lng = ?, max_lng = ? WHERE id = ?',
                                [$json, mb_substr($source, 0, 255), min($lats), max($lats), min($lngs), max($lngs), $existing]);
                            $updated++;
                        } else {
                            db_query('INSERT INTO administrative_areas (name, area_type, parent_id, geojson, source, min_lat, max_lat, min_lng, max_lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                                [mb_substr($name, 0, 150), $type, $parent, $json, mb_substr($source, 0, 255), min($lats), max($lats), min($lngs), max($lngs)]);
                            $created++;
                        }
                    }
                });
                audit_log('import_limites', null, null, compact('created', 'updated', 'skipped', 'source'));
                flash('success', "Limites importées : $created créée(s), $updated mise(s) à jour, $skipped ignorée(s).");
                break;

            case 'backup':
                require_permission('backup.manage');
                $tab = 'sauvegarde';
                $sql = BackupService::createSql();
                $zip = !empty($_POST['with_documents']) ? BackupService::createDocumentsZip() : null;
                flash('success', 'Sauvegarde créée : ' . $sql . ($zip ? ' + ' . $zip : '') . '. Copiez-la sur un support externe.');
                break;

            case 'restore':
                require_permission('backup.manage');
                $tab = 'sauvegarde';
                if (($_POST['confirm_text'] ?? '') !== 'RESTAURER') {
                    throw new UserFacingException('Tapez RESTAURER en majuscules pour confirmer.');
                }
                $file = $_FILES['file'] ?? [];
                if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'sql') {
                    throw new UserFacingException('Sélectionnez un fichier .sql produit par Niger Parcelles.');
                }
                $r = BackupService::restore($file['tmp_name']);
                flash('success', 'Restauration effectuée (' . $r['statements'] . ' instructions). Sauvegarde de sécurité préalable : ' . $r['safety'] . '. Reconnectez-vous si nécessaire.');
                break;

            case 'recompute':
                $tab = 'systeme';
                $r = ParcelService::recomputeMeasures((int) $me['id']);
                flash('success', "Superficies recalculées sur l'ellipsoïde WGS84 : {$r['updated']} parcelle(s) corrigée(s) sur {$r['checked']}. Les limites n'ont pas été modifiées.");
                break;

            case 'purge_demo':
                $tab = 'demo';
                if (($_POST['confirm_text'] ?? '') !== 'SUPPRIMER') {
                    throw new UserFacingException('Tapez SUPPRIMER en majuscules pour confirmer.');
                }
                $r = BackupService::purgeDemo();
                flash('success', "Données de démonstration supprimées : {$r['parcels']} parcelle(s), {$r['owners']} propriétaire(s).");
                break;

            default:
                throw new UserFacingException('Action inconnue.');
        }
    } catch (UserFacingException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('parametres.php', ['tab' => $tab]);
}

// Téléchargement d'une sauvegarde
if (isset($_GET['download'])) {
    require_permission('backup.manage');
    $path = BackupService::path((string) $_GET['download']);
    audit_log('sauvegarde_telechargement', null, null, ['fichier' => basename($path)]);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$areas = db_all('SELECT a.*, p.name AS parent_name, (a.geojson IS NOT NULL) AS has_geom,
                        (SELECT COUNT(*) FROM parcels x WHERE x.administrative_area_id = a.id) AS n_parcels
                 FROM administrative_areas a LEFT JOIN administrative_areas p ON p.id = a.parent_id
                 ORDER BY FIELD(a.area_type, "pays","region","departement","commune","quartier","village"), a.name');
$areaOptions = admin_area_options();
$demoCount = (int) db_value('SELECT COUNT(*) FROM parcels WHERE is_demo = 1') + (int) db_value('SELECT COUNT(*) FROM owners WHERE is_demo = 1');

// Journal d'audit
$auditQ = input_str('aq', 60);
$aw = [];
$ap = [];
if ($auditQ) {
    $aw[] = '(a.action LIKE ? OR u.username LIKE ? OR a.entity_type LIKE ?)';
    $like = '%' . addcslashes($auditQ, '%_\\') . '%';
    array_push($ap, $like, $like, $like);
}
$awhere = $aw ? 'WHERE ' . implode(' AND ', $aw) : '';
$auditTotal = (int) db_value("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $awhere", $ap);
$apg = paginate($auditTotal, 50, 'apage');
$audit = can('audit.view') ? db_all("SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $awhere ORDER BY a.id DESC LIMIT {$apg['per_page']} OFFSET {$apg['offset']}", $ap) : [];

$pageTitle = 'Paramètres';
$activeMenu = 'parametres';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><i class="bi bi-gear"></i> Paramètres</h1></div>
<ul class="nav nav-tabs mb-3">
    <?php foreach (['general' => 'Général', 'decoupage' => 'Découpage administratif', 'sauvegarde' => 'Sauvegardes', 'audit' => 'Journal d\'audit', 'demo' => 'Données de démonstration', 'systeme' => 'Système'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= e(url('parametres.php', ['tab' => $k])) ?>"><?= e($l) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'general'): ?>
<form method="post" class="card"><div class="card-body row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <?php foreach (SETTING_FIELDS as $k => [$label, $type]): ?>
        <?php if ($type === 'bool'): ?>
            <div class="col-md-6"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="s_<?= $k ?>" name="<?= $k ?>" value="1" <?= setting($k) === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="s_<?= $k ?>"><?= e($label) ?></label></div>
                <div class="form-text">Respectez les conditions d'utilisation du fournisseur d'imagerie.</div></div>
        <?php else: ?>
            <div class="col-md-<?= $type === 'text' ? '12' : '3' ?>"><label class="form-label small" for="s_<?= $k ?>"><?= e($label) ?></label>
                <input class="form-control form-control-sm" id="s_<?= $k ?>" name="<?= $k ?>" value="<?= e(setting($k, '')) ?>" required></div>
        <?php endif; ?>
    <?php endforeach; ?>
</div><div class="card-footer"><button class="btn btn-primary">Enregistrer</button></div></form>

<?php elseif ($tab === 'decoupage'): ?>
<div class="alert alert-info small">Les limites administratives ne sont <strong>jamais inventées</strong> par l'application. Importez-les depuis une source officielle ou fiable
    (services du cadastre, Institut National de la Statistique, jeux de données publiés sur HDX/geoBoundaries…) et indiquez cette source.
    Le <strong>code</strong> (2 à 5 lettres) d'une zone sert de préfixe aux références des parcelles (ex. NIG-NIA-2026-000001).</div>
<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Ajouter une zone</div><div class="card-body">
        <form method="post" class="row g-2 small"><?= csrf_field() ?><input type="hidden" name="action" value="area_add">
            <div class="col-md-6"><label class="form-label mb-0" for="an">Nom *</label><input class="form-control form-control-sm" id="an" name="name" required maxlength="150"></div>
            <div class="col-md-3"><label class="form-label mb-0" for="at">Type *</label><select class="form-select form-select-sm" id="at" name="area_type"><?php foreach (labels('area_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'commune' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label mb-0" for="ac">Code</label><input class="form-control form-control-sm" id="ac" name="code" maxlength="5" placeholder="ex. NIA"></div>
            <div class="col-md-8"><label class="form-label mb-0" for="ap">Zone parente</label><select class="form-select form-select-sm" id="ap" name="parent_id"><option value="">—</option><?php foreach ($areaOptions as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label mb-0" for="ae">Réf. externe</label><input class="form-control form-control-sm" id="ae" name="external_reference" maxlength="100"></div>
            <div class="col-12"><button class="btn btn-sm btn-primary">Ajouter</button></div>
        </form></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Importer des limites (GeoJSON)</div><div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 small"><?= csrf_field() ?><input type="hidden" name="action" value="area_import">
            <div class="col-12"><input class="form-control form-control-sm" type="file" name="file" accept=".geojson,.json" required aria-label="Fichier GeoJSON"></div>
            <div class="col-md-4"><label class="form-label mb-0" for="it">Type des entités</label><select class="form-select form-select-sm" id="it" name="area_type"><?php foreach (labels('area_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'commune' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label mb-0" for="inp">Propriété contenant le nom</label><input class="form-control form-control-sm" id="inp" name="name_property" value="name" required></div>
            <div class="col-md-4"><label class="form-label mb-0" for="ic">Système</label><select class="form-select form-select-sm" id="ic" name="crs"><?php foreach (Crs::SUPPORTED as $k => $l): ?><option value="<?= e($k) ?>"><?= e($k) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label mb-0" for="ipa">Zone parente</label><select class="form-select form-select-sm" id="ipa" name="parent_id"><option value="">—</option><?php foreach ($areaOptions as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label mb-0" for="isrc">Source des données *</label><input class="form-control form-control-sm" id="isrc" name="source" required maxlength="255" placeholder="Organisme, jeu de données, date"></div>
            <div class="col-12 form-text">Les zones existantes (même nom et type) reçoivent la géométrie ; les autres sont créées.</div>
            <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Importer</button></div>
        </form></div></div></div>
</div>
<div class="card mt-3"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Nom</th><th>Type</th><th>Parente</th><th>Code</th><th>Réf. externe</th><th>Limites</th><th>Parcelles</th><th></th></tr></thead><tbody>
    <?php foreach ($areas as $a): ?><tr>
        <td colspan="5"><form method="post" class="row g-1" id="af<?= (int) $a['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="area_update"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <div class="col-md-3"><input class="form-control form-control-sm" name="name" value="<?= e($a['name']) ?>" required aria-label="Nom"></div>
            <div class="col-md-2"><select class="form-select form-select-sm" name="area_type" aria-label="Type"><?php foreach (labels('area_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $a['area_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="parent_id" aria-label="Parente"><option value="">—</option><?php foreach ($areaOptions as $o): if ($o['id'] === (int) $a['id']) { continue; } ?><option value="<?= $o['id'] ?>" <?= (int) $a['parent_id'] === $o['id'] ? 'selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="code" value="<?= e($a['code']) ?>" maxlength="5" aria-label="Code"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" name="external_reference" value="<?= e($a['external_reference']) ?>" aria-label="Référence externe"></div>
        </form></td>
        <td class="small"><?= $a['has_geom'] ? '<span class="text-success">Importées</span><br><span class="text-muted">' . e($a['source']) . '</span>' : '<span class="text-muted">Non disponibles</span>' ?></td>
        <td><?= (int) $a['n_parcels'] ?></td>
        <td><button class="btn btn-sm btn-outline-primary" form="af<?= (int) $a['id'] ?>"><i class="bi bi-check"></i></button></td></tr><?php endforeach; ?>
    </tbody></table></div></div>

<?php elseif ($tab === 'sauvegarde'): ?>
<?php if (!can('backup.manage')): ?><div class="alert alert-warning">Accès réservé.</div><?php else: ?>
<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Créer une sauvegarde</div><div class="card-body small">
        <p>La sauvegarde contient toute la base (fichier .sql) et, en option, les documents (archive .zip). Elle est enregistrée dans <code>storage/backups</code> (non accessible depuis le navigateur).
            <strong>Copiez régulièrement les sauvegardes sur un support externe chiffré.</strong></p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup">
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="wd" name="with_documents" value="1" checked><label class="form-check-label" for="wd">Inclure les documents</label></div>
            <button class="btn btn-primary btn-sm"><i class="bi bi-hdd"></i> Sauvegarder maintenant</button></form>
        <hr><p class="mb-0">Alternative : phpMyAdmin &gt; base niger_parcelles &gt; Exporter (format SQL), et copie du dossier <code>storage/documents</code>.</p>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header text-danger">Restaurer une sauvegarde</div><div class="card-body small">
        <p>Remplace <strong>toutes</strong> les données actuelles par celles du fichier. Une sauvegarde de sécurité est créée automatiquement juste avant.</p>
        <form method="post" enctype="multipart/form-data" data-confirm="Restaurer ? Les données actuelles seront remplacées."><?= csrf_field() ?><input type="hidden" name="action" value="restore">
            <input class="form-control form-control-sm mb-2" type="file" name="file" accept=".sql" required aria-label="Fichier SQL">
            <input class="form-control form-control-sm mb-2" name="confirm_text" placeholder="Tapez RESTAURER pour confirmer" required autocomplete="off">
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Restaurer</button></form>
        <p class="mt-2 mb-0">Pour les documents : décompressez l'archive et copiez son dossier <code>documents/</code> dans <code>storage/</code>.</p>
    </div></div></div>
</div>
<div class="card mt-3"><div class="card-header">Sauvegardes disponibles</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Fichier</th><th>Date</th><th>Taille</th><th></th></tr></thead><tbody>
    <?php foreach (BackupService::listBackups() as $b): ?><tr><td><?= e($b['name']) ?></td><td><?= date('d/m/Y H:i', $b['date']) ?></td><td><?= fmt_number($b['size'] / 1024) ?> Ko</td>
        <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('parametres.php', ['download' => $b['name']])) ?>"><i class="bi bi-download"></i></a></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
<?php endif; ?>

<?php elseif ($tab === 'audit'): ?>
<?php if (!can('audit.view')): ?><div class="alert alert-warning">Accès réservé.</div><?php else: ?>
<form class="d-flex gap-2 mb-2" method="get"><input type="hidden" name="tab" value="audit"><input class="form-control form-control-sm w-auto" name="aq" value="<?= e($auditQ) ?>" placeholder="Action, utilisateur, entité"><button class="btn btn-sm btn-primary">Filtrer</button></form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 small">
    <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Entité</th><th>Détails</th><th>IP</th></tr></thead><tbody>
    <?php foreach ($audit as $l): ?><tr><td class="text-nowrap"><?= e(fmt_date($l['created_at'], true)) ?></td><td><?= e($l['username'] ?? '—') ?></td><td><?= e($l['action']) ?></td>
        <td><?= e(trim(($l['entity_type'] ?? '') . ' ' . ($l['entity_id'] ?? ''))) ?></td><td class="text-break" style="max-width:420px"><?= e(mb_substr((string) $l['details'], 0, 300)) ?></td><td><?= e($l['ip_address']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><div class="card-footer"><?= pagination_links($apg, 'apage') ?></div></div>
<?php endif; ?>

<?php elseif ($tab === 'demo'): ?>
<div class="card"><div class="card-body">
    <p>Enregistrements de démonstration (fictifs) présents : <strong><?= $demoCount ?></strong>.</p>
    <p class="small text-muted">Les données de démonstration sont marquées <code>is_demo = 1</code>. Supprimez-les avant toute exploitation réelle. Les données réelles ne sont pas affectées.</p>
    <?php if ($demoCount): ?>
    <form method="post" class="d-flex gap-2" data-confirm="Supprimer définitivement les données de démonstration ?"><?= csrf_field() ?><input type="hidden" name="action" value="purge_demo">
        <input class="form-control form-control-sm w-auto" name="confirm_text" placeholder="Tapez SUPPRIMER" required autocomplete="off">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Supprimer les données de démonstration</button></form>
    <?php endif; ?>
</div></div>

<?php elseif ($tab === 'systeme'): ?>
<div class="card"><div class="card-body small"><dl class="row mb-0">
    <dt class="col-sm-4">Version de l'application</dt><dd class="col-sm-8"><?= e(config('version')) ?></dd>
    <dt class="col-sm-4">PHP</dt><dd class="col-sm-8"><?= e(PHP_VERSION) ?></dd>
    <dt class="col-sm-4">Base de données</dt><dd class="col-sm-8"><?= e((string) db_value('SELECT VERSION()')) ?></dd>
    <dt class="col-sm-4">Extensions</dt><dd class="col-sm-8"><?php foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'zip', 'zlib', 'openssl'] as $x): ?><span class="badge text-bg-<?= extension_loaded($x) ? 'success' : 'secondary' ?> me-1"><?= e($x) ?></span><?php endforeach; ?></dd>
    <dt class="col-sm-4">upload_max_filesize / post_max_size</dt><dd class="col-sm-8"><?= e(ini_get('upload_max_filesize')) ?> / <?= e(ini_get('post_max_size')) ?></dd>
    <dt class="col-sm-4">HTTPS</dt><dd class="col-sm-8"><?= is_https() ? 'Oui' : 'Non (acceptable en local uniquement ; obligatoire avant toute exposition sur un réseau)' ?></dd>
    <dt class="col-sm-4">Stockage privé</dt><dd class="col-sm-8"><?= e(storage_path()) ?></dd>
</dl></div></div>
<div class="card mt-3"><div class="card-header">Calcul des superficies</div><div class="card-body small">
    <p>Les superficies et périmètres sont calculés sur l'ellipsoïde WGS84 (écart inférieur à 1 ppm avec la méthode de référence de Karney ;
        coordonnées stockées au millimètre). Les parcelles enregistrées avec une version antérieure de l'application (formule sphérique,
        environ +0,4 % à la latitude du Niger) peuvent être recalculées : seules les valeurs calculées changent, jamais les limites.
        Chaque correction est inscrite dans l'historique de la parcelle.</p>
    <form method="post" data-confirm="Recalculer les superficies et périmètres de toutes les parcelles ?"><?= csrf_field() ?><input type="hidden" name="action" value="recompute">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-calculator"></i> Recalculer les superficies</button></form>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
