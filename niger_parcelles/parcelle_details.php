<?php
/** Fiche détaillée d'une parcelle (identification, foncier, propriétaires, documents, historique). */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('parcel.view');
$uid = (int) $user['id'];
$id = (int) ($_GET['id'] ?? 0);
$parcel = ParcelService::get($id);
if (!$parcel) {
    http_response_code(404);
    flash('warning', 'Parcelle introuvable.');
    redirect('parcelles.php');
}

// ------------------------------------------------------------ Actions (POST)
if (request_method() === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $tab = 'identification';
    try {
        switch ($action) {
            case 'status':
                ParcelService::changeStatus($id, (string) ($_POST['status'] ?? ''), $uid, input_str('comment', 1000));
                flash('success', 'Statut mis à jour.');
                break;
            case 'archive':
                require_permission('parcel.archive');
                $reason = input_str('reason', 255);
                if (!$reason) {
                    throw new UserFacingException('Indiquez le motif de l\'archivage.');
                }
                ParcelService::archive($id, $uid, $reason);
                flash('success', 'Parcelle archivée. Sa référence reste réservée.');
                break;
            case 'link_owner':
                require_permission('owner.manage');
                $tab = 'proprietaires';
                if (!can_edit_parcel($parcel) && !can('parcel.edit')) {
                    throw new UserFacingException('Vous ne pouvez pas modifier les propriétaires de cette parcelle.', 403);
                }
                OwnerService::link($id, (int) ($_POST['owner_id'] ?? 0), $_POST, $uid);
                flash('success', 'Propriétaire associé.');
                break;
            case 'end_link':
                require_permission('owner.manage');
                $tab = 'proprietaires';
                OwnerService::endLink((int) ($_POST['link_id'] ?? 0), (string) ($_POST['valid_to'] ?? date('Y-m-d')), $uid, null, input_str('reason', 255));
                flash('success', 'Lien clos ; il reste visible dans l\'historique.');
                break;
            case 'link_status':
                if (!can('parcel.verify') && !can('owner.manage')) {
                    require_permission('parcel.verify');
                }
                $tab = 'proprietaires';
                $link = db_one('SELECT parcel_id FROM parcel_owners WHERE id = ?', [(int) ($_POST['link_id'] ?? 0)]);
                if (!$link || (int) $link['parcel_id'] !== $id) {
                    throw new UserFacingException('Lien introuvable.', 404);
                }
                $st = (string) ($_POST['verification_status'] ?? '');
                if ($st === 'verifie' && !can('parcel.validate')) {
                    throw new UserFacingException('Seuls les profils habilités peuvent marquer un propriétaire comme vérifié.', 403);
                }
                OwnerService::setLinkStatus((int) $_POST['link_id'], $st, $uid);
                flash('success', 'Statut du propriétaire mis à jour.');
                break;
            case 'mutation':
                require_permission('owner.mutation');
                $tab = 'proprietaires';
                $new = [];
                foreach ((array) ($_POST['new_owner_id'] ?? []) as $i => $oid) {
                    if ((int) $oid > 0) {
                        $new[] = ['owner_id' => (int) $oid, 'ownership_role' => $_POST['new_role'][$i] ?? 'proprietaire',
                                  'share_percentage' => $_POST['new_share'][$i] ?? null, 'verification_status' => $_POST['new_status'][$i] ?? 'declare'];
                    }
                }
                $close = array_map('intval', (array) ($_POST['close_link'] ?? []));
                OwnerService::recordMutation($id, $_POST, $new, $close, $uid);
                flash('success', 'Mutation enregistrée. Les anciens liens sont conservés dans l\'historique.');
                break;
            case 'upload':
                require_permission('document.upload');
                $tab = 'documents';
                DocumentService::store($_FILES['file'] ?? [], ['parcel_id' => $id] + $_POST, $uid);
                flash('success', 'Document ajouté.');
                break;
            case 'doc_status':
                require_permission('document.verify');
                $tab = 'documents';
                $doc = DocumentService::get((int) ($_POST['document_id'] ?? 0));
                if (!$doc || (int) $doc['parcel_id'] !== $id) {
                    throw new UserFacingException('Document introuvable.', 404);
                }
                DocumentService::setStatus((int) $doc['id'], (string) ($_POST['verification_status'] ?? ''), $uid);
                flash('success', 'Statut du document mis à jour.');
                break;
            case 'overlap':
                require_permission('overlap.resolve');
                $tab = 'chevauchements';
                $ov = db_one('SELECT * FROM parcel_overlaps WHERE id = ? AND (parcel_a_id = ? OR parcel_b_id = ?)', [(int) ($_POST['overlap_id'] ?? 0), $id, $id]);
                if (!$ov) {
                    throw new UserFacingException('Chevauchement introuvable.', 404);
                }
                ParcelService::resolveOverlap((int) $ov['id'], (string) ($_POST['status'] ?? ''), input_str('notes', 255), $uid);
                flash('success', 'Décision enregistrée.');
                break;
            default:
                throw new UserFacingException('Action inconnue.');
        }
    } catch (UserFacingException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('parcelle_details.php', ['id' => $id, 'tab' => $tab]);
}

// ---------------------------------------------------------------- Données
$geometry = ParcelService::currentGeometry($id);
$areas = admin_area_breakdown($parcel['administrative_area_id'] ? (int) $parcel['administrative_area_id'] : null);
$owners = OwnerService::currentOwners($id);
$pastOwners = OwnerService::pastOwners($id);
$mutations = OwnerService::mutations($id);
$documents = can('document.view') ? db_all('SELECT d.*, u.full_name AS uploaded_by_name FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
                                           WHERE d.parcel_id = ? ORDER BY d.is_archived, d.uploaded_at DESC', [$id]) : [];
$history = ParcelService::historyEntries($id);
$versions = ParcelService::geometryVersions($id);
$overlaps = ParcelService::overlapsOf($id);
$transitions = allowed_status_transitions($parcel);
$canEdit = can_edit_parcel($parcel);
$canPersonal = can('owner.view_personal');
$tab = preg_match('/^[a-z]+$/', (string) ($_GET['tab'] ?? '')) ? $_GET['tab'] : 'identification';
$shareSum = array_sum(array_map(static fn ($o) => (float) $o['share_percentage'], $owners));

$pageTitle = 'Parcelle ' . $parcel['parcel_reference'];
$activeMenu = 'parcelles';
$vendor = ['leaflet'];
$scripts = ['parcel-details.js'];
require __DIR__ . '/includes/header.php';

$versionsJs = array_map(static fn ($v) => ['version' => (int) $v['geometry_version'], 'geometry' => json_decode($v['geojson'], true),
                                          'date' => fmt_date($v['created_at'], true)], $versions);
$mapData = ['geometry' => $geometry, 'versions' => $versionsJs, 'tiles' => config('tile_layers')];
$dd = static fn (string $k, $v) => '<dt class="col-sm-4">' . e($k) . '</dt><dd class="col-sm-8">' . ($v === null || $v === '' ? '<span class="text-muted">—</span>' : e($v)) . '</dd>';
?>
<script type="application/json" id="parcel-map-data"><?= json_encode($mapData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<div class="page-header">
    <div>
        <h1><span class="ref"><?= e($parcel['parcel_reference']) ?></span> <?= status_badge($parcel['verification_status']) ?>
            <?php if ((int) $parcel['is_archived'] === 1): ?><span class="badge text-bg-dark">Archivée</span><?php endif; ?>
            <?php if ((int) $parcel['is_demo'] === 1): ?><span class="badge text-bg-warning">DÉMONSTRATION FICTIVE</span><?php endif; ?></h1>
        <div class="text-muted small">Identifiant interne #<?= (int) $parcel['id'] ?> · <?= e(label('survey_level', $parcel['survey_level'])) ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('carte.php', ['parcel' => $id])) ?>"><i class="bi bi-map"></i> Carte</a>
        <?php if ($canEdit): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('parcelle_edit.php', ['id' => $id])) ?>"><i class="bi bi-pencil"></i> Modifier les informations</a>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('carte.php', ['edit' => $id])) ?>"><i class="bi bi-vector-pen"></i> Modifier les limites</a>
        <?php endif; ?>
        <?php if (can('export.run')): ?>
            <a class="btn btn-sm btn-primary" href="<?= e(url('api/export.php', ['type' => 'fiche_pdf', 'id' => $id])) ?>"><i class="bi bi-filetype-pdf"></i> Fiche PDF</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('api/export.php', ['type' => 'geojson', 'id' => $id])) ?>"><i class="bi bi-filetype-json"></i> GeoJSON</a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('parcelle_print.php', ['id' => $id])) ?>"><i class="bi bi-printer"></i> Carte de situation</a>
    </div>
</div>

<?php if ((int) $parcel['has_overlap_warning'] === 1): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-intersect"></i> Cette parcelle présente un chevauchement potentiel à vérifier (onglet Chevauchements).</div>
<?php endif; ?>
<div class="legal-notice mb-3"><i class="bi bi-info-circle"></i> Cette fiche est un document de gestion. Elle ne constitue pas, à elle seule, un titre foncier officiel ;
    l'enregistrement d'un nom ne prouve pas la propriété. Référence cadastrale officielle : <strong><?= e($parcel['cadastral_reference'] ?? 'non renseignée') ?></strong>.</div>

<?php if ($transitions): ?>
<form method="post" class="card card-body mb-3 no-print">
    <?= csrf_field() ?><input type="hidden" name="action" value="status">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-0" for="st">Changer le statut</label>
            <select class="form-select form-select-sm" id="st" name="status" required>
                <?php foreach ($transitions as $t): ?><option value="<?= e($t) ?>"><?= e(label('verification_status', $t)) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-7"><label class="form-label small mb-0" for="stc">Commentaire (motif, anomalie constatée…)</label>
            <input class="form-control form-control-sm" id="stc" name="comment" maxlength="1000"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Appliquer</button></div>
    </div>
</form>
<?php endif; ?>

<ul class="nav nav-tabs mb-3 no-print" role="tablist">
    <?php foreach (['identification' => 'Identification', 'foncier' => 'Informations foncières', 'proprietaires' => 'Propriétaires (' . count($owners) . ')',
                    'documents' => 'Documents (' . count(array_filter($documents, static fn ($d) => !$d['is_archived'])) . ')', 'historique' => 'Historique',
                    'chevauchements' => 'Chevauchements (' . count($overlaps) . ')'] as $k => $v):
        if ($k === 'documents' && !can('document.view')) { continue; } ?>
        <li class="nav-item"><button class="nav-link<?= $tab === $k ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $k ?>" type="button" role="tab"><?= e($v) ?></button></li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">
<!-- ======================= Identification ======================= -->
<div class="tab-pane fade<?= $tab === 'identification' ? ' show active' : '' ?>" id="tab-identification" role="tabpanel">
    <div class="row g-3">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header">A. Identification</div><div class="card-body"><dl class="row mb-0 small">
            <?= $dd('Référence application', $parcel['parcel_reference']) ?>
            <?= $dd('Référence cadastrale officielle', $parcel['cadastral_reference']) ?>
            <?= $dd('Région', $areas['region']) ?><?= $dd('Département', $areas['departement']) ?><?= $dd('Commune', $areas['commune']) ?>
            <?= $dd('Quartier', $areas['quartier']) ?><?= $dd('Village', $areas['village']) ?>
            <?= $dd('Localisation', $parcel['locality_description']) ?><?= $dd('Adresse', $parcel['address']) ?>
            <?= $dd('Centre (lat, lng)', sprintf('%.7f, %.7f', $parcel['centroid_lat'], $parcel['centroid_lng'])) ?>
            <?= $dd('Superficie', fmt_number($parcel['area_m2'], 2) . ' m²') ?>
            <?= $dd('Superficie (ha)', fmt_number($parcel['area_m2'] / 10000, 4) . ' ha') ?>
            <?= $dd('Périmètre', fmt_number($parcel['perimeter_m'], 2) . ' m') ?>
            <?= $dd('Catégorie (B)', label('category', $parcel['category'])) ?>
            <?= $dd('Nature de la délimitation', label('survey_level', $parcel['survey_level'])) ?>
            <?= $dd('Statut de vérification', label('verification_status', $parcel['verification_status'])) ?>
            <?= $dd('Vérifiée par', $parcel['verified_by_name'] ? $parcel['verified_by_name'] . ' le ' . fmt_date($parcel['verified_at'], true) : null) ?>
            <?= $dd('Date de création', fmt_date($parcel['created_at'], true)) ?>
            <?= $dd('Agent responsable', $parcel['created_by_name'] ?? 'Import / démonstration') ?>
            <?= $dd('Version des limites', 'v' . (int) $parcel['current_geometry_version']) ?>
            <?= $dd('Observations', $parcel['description']) ?>
        </dl></div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header d-flex justify-content-between">Localisation
            <select class="form-select form-select-sm w-auto" id="versionSelect" aria-label="Version des limites">
                <?php foreach ($versions as $v): ?><option value="<?= (int) $v['geometry_version'] ?>">Version <?= (int) $v['geometry_version'] ?> (<?= e(fmt_date($v['created_at'])) ?>)</option><?php endforeach; ?>
            </select></div>
            <div class="card-body"><div id="miniMap" class="mini-map"></div>
                <details class="mt-2 small"><summary>Coordonnées des sommets (WGS84)</summary>
                    <table class="table table-sm mt-2"><thead><tr><th>#</th><th>Latitude</th><th>Longitude</th><th>Distance au suivant</th></tr></thead><tbody>
                    <?php $ring = $geometry['coordinates'][0] ?? []; for ($i = 0, $n = count($ring) - 1; $i < $n; $i++): ?>
                        <tr><td><?= $i + 1 ?></td><td><?= sprintf('%.7f', $ring[$i][1]) ?></td><td><?= sprintf('%.7f', $ring[$i][0]) ?></td>
                            <td><?= fmt_number(Geo::distance($ring[$i], $ring[$i + 1]), 2) ?> m</td></tr>
                    <?php endfor; ?>
                    </tbody></table></details>
            </div></div></div>
    </div>
    <?php if (can('parcel.archive') && (int) $parcel['is_archived'] === 0): ?>
    <form method="post" class="mt-3 no-print d-flex gap-2" data-confirm="Archiver cette parcelle ? Elle n'apparaîtra plus sur la carte mais son historique et sa référence sont conservés.">
        <?= csrf_field() ?><input type="hidden" name="action" value="archive">
        <input class="form-control form-control-sm w-auto" name="reason" placeholder="Motif de l'archivage" required maxlength="255">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-archive"></i> Archiver</button>
    </form>
    <?php endif; ?>
</div>

<!-- ======================= Foncier ======================= -->
<div class="tab-pane fade<?= $tab === 'foncier' ? ' show active' : '' ?>" id="tab-foncier" role="tabpanel">
    <div class="card"><div class="card-header">C. Informations foncières</div><div class="card-body"><dl class="row mb-0 small">
        <?= $dd('Situation déclarée', label('declared_status', $parcel['declared_status'])) ?>
        <?= $dd('Référence du titre foncier', $parcel['title_reference']) ?>
        <?= $dd('Type de document justificatif', $parcel['tenure_document_type']) ?>
        <?= $dd('Date du document', fmt_date($parcel['tenure_document_date'])) ?>
        <?= $dd('Autorité / organisme émetteur', $parcel['issuing_authority']) ?>
        <?= $dd('Statut de vérification', label('verification_status', $parcel['verification_status'])) ?>
        <?= $dd('Restrictions / informations administratives', $parcel['restrictions']) ?>
        <?= $dd('Observations', $parcel['observations']) ?>
    </dl>
    <p class="small text-muted mt-3 mb-0">Une parcelle peut exister dans le système sans titre foncier : son statut réel est affiché, sans valeur juridique attribuée artificiellement.</p>
    </div></div>
</div>

<!-- ======================= Propriétaires ======================= -->
<div class="tab-pane fade<?= $tab === 'proprietaires' ? ' show active' : '' ?>" id="tab-proprietaires" role="tabpanel">
    <div class="card mb-3"><div class="card-header">D. Propriétaires / ayants droit actuels
        <?php if ($shareSum > 0): ?><span class="badge text-bg-light border ms-2">Quotes-parts documentées : <?= fmt_number($shareSum, 2) ?> %</span><?php endif; ?></div>
    <?php if (!$canPersonal): ?>
        <div class="card-body text-muted"><?= count($owners) ?> propriétaire(s) associé(s). Les données personnelles sont réservées aux profils autorisés.</div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Nom / dénomination</th><th>Type</th><th>Rôle</th><th>Quote-part</th><th>Depuis</th><th>Contact</th><th>Dossier</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($owners as $o): ?>
                <tr>
                    <td><a href="<?= e(url('proprietaire_details.php', ['id' => $o['owner_id']])) ?>"><?= e(owner_display_name($o)) ?></a></td>
                    <td class="small"><?= e(label('owner_type', $o['owner_type'])) ?></td>
                    <td><?= e(label('ownership_role', $o['ownership_role'])) ?></td>
                    <td><?= $o['share_percentage'] !== null ? fmt_number($o['share_percentage'], 2) . ' %' : '—' ?></td>
                    <td><?= e(fmt_date($o['valid_from'])) ?></td>
                    <td class="small"><?= e($o['phone'] ?? '—') ?><br><?= e($o['address'] ?? '') ?></td>
                    <td class="small"><?= e($o['document_reference'] ?? $o['file_reference'] ?? '—') ?></td>
                    <td>
                        <?php if (can('parcel.verify') || can('owner.manage')): ?>
                        <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="link_status"><input type="hidden" name="link_id" value="<?= (int) $o['id'] ?>">
                            <select class="form-select form-select-sm" name="verification_status" aria-label="Statut">
                                <?php foreach (labels('link_status') as $kk => $v): ?><option value="<?= e($kk) ?>" <?= $o['verification_status'] === $kk ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
                            </select><button class="btn btn-sm btn-outline-secondary" title="Enregistrer"><i class="bi bi-check"></i></button></form>
                        <?php else: ?><?= e(label('link_status', $o['verification_status'])) ?><?php endif; ?>
                    </td>
                    <td><?php if (can('owner.manage')): ?>
                        <form method="post" class="d-flex gap-1" data-confirm="Clore ce lien ? Il restera visible dans l'historique.">
                            <?= csrf_field() ?><input type="hidden" name="action" value="end_link"><input type="hidden" name="link_id" value="<?= (int) $o['id'] ?>">
                            <input type="date" class="form-control form-control-sm" name="valid_to" value="<?= date('Y-m-d') ?>" aria-label="Date de fin">
                            <button class="btn btn-sm btn-outline-danger" title="Clore le lien"><i class="bi bi-x-lg"></i></button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$owners): ?><tr><td colspan="9" class="text-muted">Aucun propriétaire renseigné.</td></tr><?php endif; ?>
            </tbody></table></div>
    <?php endif; ?>
    </div>

    <?php if ($canPersonal && can('owner.manage') && (int) $parcel['is_archived'] === 0): ?>
    <div class="row g-3 mb-3 no-print">
        <div class="col-lg-5"><div class="card h-100"><div class="card-header">Associer un propriétaire</div><div class="card-body">
            <form method="post" class="small">
                <?= csrf_field() ?><input type="hidden" name="action" value="link_owner">
                <div class="mb-2 position-relative"><label class="form-label mb-0">Propriétaire existant *</label>
                    <input class="form-control form-control-sm" data-owner-search="ownerId1" placeholder="Tapez un nom, une dénomination ou un n° de dossier" autocomplete="off" required>
                    <input type="hidden" name="owner_id" id="ownerId1">
                    <div class="form-text"><a href="<?= e(url('proprietaires.php', ['new' => 1, 'parcel' => $id])) ?>">Créer un nouveau propriétaire</a></div></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label mb-0" for="lr">Rôle</label><select class="form-select form-select-sm" id="lr" name="ownership_role">
                        <?php foreach (labels('ownership_role') as $kk => $v): ?><option value="<?= e($kk) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label mb-0" for="ls">Quote-part (%)</label><input class="form-control form-control-sm" id="ls" name="share_percentage" type="number" step="0.001" min="0.001" max="100" placeholder="Si documentée"></div>
                    <div class="col-6"><label class="form-label mb-0" for="lf">Depuis le</label><input class="form-control form-control-sm" id="lf" name="valid_from" type="date"></div>
                    <div class="col-6"><label class="form-label mb-0" for="lst">Statut</label><select class="form-select form-select-sm" id="lst" name="verification_status">
                        <?php foreach (labels('link_status') as $kk => $v): if ($kk === 'verifie' && !can('parcel.validate')) { continue; } ?><option value="<?= e($kk) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label mb-0" for="ld">Référence du document</label><input class="form-control form-control-sm" id="ld" name="document_reference" maxlength="100"></div>
                </div>
                <button class="btn btn-sm btn-primary">Associer</button>
            </form></div></div></div>

        <?php if (can('owner.mutation')): ?>
        <div class="col-lg-7"><div class="card h-100"><div class="card-header">Enregistrer une mutation (changement de propriétaire)</div><div class="card-body">
            <form method="post" class="small" data-confirm="Confirmer l'enregistrement de la mutation ? Les liens cochés seront clos à la date indiquée.">
                <?= csrf_field() ?><input type="hidden" name="action" value="mutation">
                <div class="row g-2 mb-2">
                    <div class="col-md-4"><label class="form-label mb-0" for="mt">Type *</label><select class="form-select form-select-sm" id="mt" name="mutation_type" required>
                        <?php foreach (labels('mutation_type') as $kk => $v): ?><option value="<?= e($kk) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="form-label mb-0" for="md">Date *</label><input class="form-control form-control-sm" id="md" type="date" name="mutation_date" max="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-md-4"><label class="form-label mb-0" for="mdr">Réf. de l'acte</label><input class="form-control form-control-sm" id="mdr" name="document_reference" maxlength="100"></div>
                </div>
                <?php if ($owners): ?><div class="mb-2"><div class="form-label mb-0">Liens à clore</div>
                    <?php foreach ($owners as $o): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="close_link[]" value="<?= (int) $o['id'] ?>" id="cl<?= (int) $o['id'] ?>" checked>
                        <label class="form-check-label" for="cl<?= (int) $o['id'] ?>"><?= e(owner_display_name($o)) ?> (<?= e(label('ownership_role', $o['ownership_role'])) ?>)</label></div><?php endforeach; ?></div><?php endif; ?>
                <div class="form-label mb-0">Nouveau(x) propriétaire(s)</div>
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="row g-1 mb-1">
                    <div class="col-md-5 position-relative"><input class="form-control form-control-sm" data-owner-search="mo<?= $i ?>" placeholder="Propriétaire <?= $i ?><?= $i > 1 ? ' (facultatif)' : ' *' ?>" autocomplete="off" <?= $i === 1 ? 'required' : '' ?>>
                        <input type="hidden" name="new_owner_id[]" id="mo<?= $i ?>"></div>
                    <div class="col-md-3"><select class="form-select form-select-sm" name="new_role[]" aria-label="Rôle"><?php foreach (labels('ownership_role') as $kk => $v): ?><option value="<?= e($kk) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2"><input class="form-control form-control-sm" name="new_share[]" type="number" step="0.001" min="0.001" max="100" placeholder="%" aria-label="Quote-part"></div>
                    <div class="col-md-2"><select class="form-select form-select-sm" name="new_status[]" aria-label="Statut"><?php foreach (labels('link_status') as $kk => $v): if ($kk === 'verifie' && !can('parcel.validate')) { continue; } ?><option value="<?= e($kk) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
                </div>
                <?php endfor; ?>
                <div class="mb-2"><label class="form-label mb-0" for="mn">Observations</label><input class="form-control form-control-sm" id="mn" name="notes" maxlength="2000"></div>
                <button class="btn btn-sm btn-success">Enregistrer la mutation</button>
            </form></div></div></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canPersonal): ?>
    <div class="row g-3">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header">Anciens propriétaires</div>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Nom</th><th>Rôle</th><th>Du</th><th>Au</th></tr></thead><tbody>
            <?php foreach ($pastOwners as $o): ?><tr><td><a href="<?= e(url('proprietaire_details.php', ['id' => $o['owner_id']])) ?>"><?= e(owner_display_name($o)) ?></a></td>
                <td><?= e(label('ownership_role', $o['ownership_role'])) ?></td><td><?= e(fmt_date($o['valid_from'])) ?></td><td><?= e(fmt_date($o['valid_to'])) ?></td></tr><?php endforeach; ?>
            <?php if (!$pastOwners): ?><tr><td colspan="4" class="text-muted">Aucun.</td></tr><?php endif; ?>
            </tbody></table></div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header">Mutations enregistrées</div>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Date</th><th>Type</th><th>Acte</th><th>Par</th></tr></thead><tbody>
            <?php foreach ($mutations as $m): ?><tr><td><?= e(fmt_date($m['mutation_date'])) ?></td><td><?= e(label('mutation_type', $m['mutation_type'])) ?></td>
                <td><?= e($m['document_reference'] ?? '—') ?></td><td class="small"><?= e($m['created_by_name'] ?? '—') ?></td></tr><?php endforeach; ?>
            <?php if (!$mutations): ?><tr><td colspan="4" class="text-muted">Aucune.</td></tr><?php endif; ?>
            </tbody></table></div></div></div>
    </div>
    <?php endif; ?>
</div>

<!-- ======================= Documents ======================= -->
<?php if (can('document.view')): ?>
<div class="tab-pane fade<?= $tab === 'documents' ? ' show active' : '' ?>" id="tab-documents" role="tabpanel">
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Titre</th><th>Catégorie</th><th>Référence</th><th>Date</th><th>Fichier</th><th>Ajouté par</th><th>Statut</th><th></th></tr></thead><tbody>
        <?php foreach ($documents as $d): ?>
            <tr class="<?= $d['is_archived'] ? 'text-muted' : '' ?>">
                <td><?= e($d['title']) ?><?= $d['is_archived'] ? ' <span class="badge text-bg-secondary">archivé / remplacé</span>' : '' ?></td>
                <td><?= e(label('document_type', $d['document_type'])) ?></td><td><?= e($d['document_reference'] ?? '—') ?></td>
                <td><?= e(fmt_date($d['document_date'])) ?></td>
                <td class="small"><?= e($d['original_filename']) ?><br><?= fmt_number($d['file_size'] / 1024, 0) ?> Ko</td>
                <td class="small"><?= e($d['uploaded_by_name'] ?? '—') ?><br><?= e(fmt_date($d['uploaded_at'], true)) ?></td>
                <td><?php if (can('document.verify') && !$d['is_archived']): ?>
                    <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="doc_status"><input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                        <select class="form-select form-select-sm" name="verification_status" aria-label="Statut"><?php foreach (labels('document_status') as $kk => $v): ?><option value="<?= e($kk) ?>" <?= $d['verification_status'] === $kk ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                        <button class="btn btn-sm btn-outline-secondary" title="Enregistrer"><i class="bi bi-check"></i></button></form>
                    <?php else: ?><?= e(label('document_status', $d['verification_status'])) ?><?php endif; ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('api/documents.php', ['action' => 'download', 'id' => $d['id']])) ?>" title="Télécharger"><i class="bi bi-download"></i></a>
                    <?php if (strpos($d['mime_type'], 'image/') === 0 || $d['mime_type'] === 'application/pdf'): ?>
                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(url('api/documents.php', ['action' => 'download', 'id' => $d['id'], 'inline' => 1])) ?>" title="Aperçu"><i class="bi bi-eye"></i></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$documents): ?><tr><td colspan="8" class="text-muted">Aucun document. Les dossiers sans pièce justificative apparaissent dans le rapport « Documents manquants ».</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <?php if (can('document.upload') && (int) $parcel['is_archived'] === 0): ?>
    <div class="card no-print"><div class="card-header">Ajouter un document</div><div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-2 small">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload">
            <div class="col-md-4"><label class="form-label mb-0" for="dfile">Fichier * (PDF, JPG, PNG, WEBP – <?= (int) setting('max_upload_mb', 10) ?> Mo max)</label>
                <input class="form-control form-control-sm" type="file" id="dfile" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
            <div class="col-md-3"><label class="form-label mb-0" for="dtype">Catégorie *</label><select class="form-select form-select-sm" id="dtype" name="document_type">
                <?php foreach (labels('document_type') as $kk => $v): ?><option value="<?= e($kk) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label mb-0" for="dtitle">Titre</label><input class="form-control form-control-sm" id="dtitle" name="title" maxlength="200"></div>
            <div class="col-md-3"><label class="form-label mb-0" for="dref">Référence</label><input class="form-control form-control-sm" id="dref" name="document_reference" maxlength="100"></div>
            <div class="col-md-3"><label class="form-label mb-0" for="ddate">Date du document</label><input class="form-control form-control-sm" id="ddate" type="date" name="document_date"></div>
            <div class="col-md-4"><label class="form-label mb-0" for="drep">Remplace le document</label><select class="form-select form-select-sm" id="drep" name="replaces_id"><option value="">— Aucun —</option>
                <?php foreach ($documents as $d): if ($d['is_archived']) { continue; } ?><option value="<?= (int) $d['id'] ?>"><?= e($d['title']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-upload"></i> Envoyer</button></div>
        </form></div></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ======================= Historique ======================= -->
<div class="tab-pane fade<?= $tab === 'historique' ? ' show active' : '' ?>" id="tab-historique" role="tabpanel">
    <div class="row g-3">
        <div class="col-lg-8"><div class="card"><div class="card-header">E. Historique des opérations</div><div class="card-body">
            <?php foreach ($history as $h):
                $prev = $h['previous_data'] ? json_decode($h['previous_data'], true) : null;
                $new = $h['new_data'] ? json_decode($h['new_data'], true) : null;
                unset($prev['geometrie'], $new['geometrie']);
                if (!$canPersonal) { unset($new['proprietaire'], $prev['proprietaire']); } ?>
                <div class="history-item h-<?= e($h['action_type']) ?>">
                    <div><strong><?= e(label('history_action', $h['action_type'])) ?></strong>
                        <span class="text-muted small">· <?= e(fmt_date($h['created_at'], true)) ?> · <?= e($h['user_name'] ?? 'Système') ?></span></div>
                    <?php if ($prev || $new): ?><details class="small"><summary>Détails</summary><div class="row g-2">
                        <?php if ($prev): ?><div class="col-md-6"><div class="text-muted">Avant</div><pre class="json-diff"><?= e(json_encode($prev, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div><?php endif; ?>
                        <?php if ($new): ?><div class="col-md-6"><div class="text-muted">Après</div><pre class="json-diff"><?= e(json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div><?php endif; ?>
                    </div></details><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div></div></div>
        <div class="col-lg-4"><div class="card"><div class="card-header">Versions des limites</div>
            <ul class="list-group list-group-flush small">
            <?php foreach ($versions as $v): ?>
                <li class="list-group-item"><strong>Version <?= (int) $v['geometry_version'] ?></strong><?= (int) $v['geometry_version'] === (int) $parcel['current_geometry_version'] ? ' <span class="badge text-bg-success">actuelle</span>' : '' ?><br>
                    <?= fmt_number($v['area_m2'], 2) ?> m² · <?= fmt_number($v['perimeter_m'], 2) ?> m · <?= (int) $v['vertex_count'] ?> sommets<br>
                    <span class="text-muted"><?= e(fmt_date($v['created_at'], true)) ?> · <?= e($v['created_by_name'] ?? '—') ?> · <?= e($v['source']) ?><?= $v['source_crs'] ? ' (' . e($v['source_crs']) . ')' : '' ?></span>
                    <?php if ($v['change_reason']): ?><br><em><?= e($v['change_reason']) ?></em><?php endif; ?></li>
            <?php endforeach; ?>
            </ul></div></div>
    </div>
</div>

<!-- ======================= Chevauchements ======================= -->
<div class="tab-pane fade<?= $tab === 'chevauchements' ? ' show active' : '' ?>" id="tab-chevauchements" role="tabpanel">
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Autre parcelle</th><th>Type</th><th>Détecté le</th><th>Statut</th><th>Décision</th></tr></thead><tbody>
        <?php foreach ($overlaps as $o): ?>
            <tr><td><a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $o['other_id'], 'tab' => 'chevauchements'])) ?>"><?= e($o['other_reference']) ?></a>
                    <a class="btn btn-sm btn-link" href="<?= e(url('carte.php', ['parcel' => $o['other_id']])) ?>"><i class="bi bi-map"></i></a></td>
                <td><?= e(label('overlap_type', $o['overlap_type'])) ?></td><td><?= e(fmt_date($o['detected_at'], true)) ?></td>
                <td><?= e(label('overlap_status', $o['status'])) ?><?= $o['notes'] ? '<br><small class="text-muted">' . e($o['notes']) . '</small>' : '' ?>
                    <?= $o['resolved_by_name'] ? '<br><small class="text-muted">' . e($o['resolved_by_name']) . ', ' . e(fmt_date($o['resolved_at'], true)) . '</small>' : '' ?></td>
                <td><?php if (can('overlap.resolve')): ?>
                    <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="overlap"><input type="hidden" name="overlap_id" value="<?= (int) $o['id'] ?>">
                        <select class="form-select form-select-sm" name="status" aria-label="Décision"><?php foreach (labels('overlap_status') as $kk => $v): ?><option value="<?= e($kk) ?>" <?= $o['status'] === $kk ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
                        <input class="form-control form-control-sm" name="notes" placeholder="Justification" maxlength="255">
                        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check"></i></button></form>
                <?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$overlaps): ?><tr><td colspan="5" class="text-muted">Aucun chevauchement détecté.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <p class="small text-muted mt-2">Une superposition peut être légitime (copropriété, servitude, emprise publique) : elle est signalée pour vérification, jamais rejetée automatiquement.</p>
</div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
