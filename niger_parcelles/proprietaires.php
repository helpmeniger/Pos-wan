<?php
/** Gestion des propriétaires : liste, recherche, création avec détection des doublons. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('owner.view_personal');

$form = [];
$duplicates = [];
$error = null;
$parcelId = isset($_GET['parcel']) && ctype_digit((string) $_GET['parcel']) ? (int) $_GET['parcel'] : null;

if (request_method() === 'POST') {
    require_csrf();
    require_permission('owner.manage');
    $form = array_intersect_key($_POST, OwnerService::FIELDS);
    try {
        $clean = OwnerService::sanitize($form);
        $duplicates = OwnerService::findDuplicates($clean);
        if ($duplicates && empty($_POST['confirm_duplicate'])) {
            $error = 'Des propriétaires similaires existent déjà. Vérifiez qu\'il ne s\'agit pas de la même personne, puis confirmez la création si nécessaire.';
        } else {
            $newId = OwnerService::create($form, (int) $user['id']);
            if ($parcelId && !empty($_POST['link_parcel'])) {
                OwnerService::link($parcelId, $newId, $_POST, (int) $user['id']);
                flash('success', 'Propriétaire créé et associé à la parcelle.');
                redirect('parcelle_details.php', ['id' => $parcelId, 'tab' => 'proprietaires']);
            }
            flash('success', 'Propriétaire créé.');
            redirect('proprietaire_details.php', ['id' => $newId]);
        }
    } catch (UserFacingException $e) {
        $error = $e->getMessage();
    }
}

// ------------------------------------------------------------------ Liste
$q = input_str('q', 100);
$type = isset($_GET['owner_type']) && array_key_exists($_GET['owner_type'], labels('owner_type')) ? $_GET['owner_type'] : null;
$status = isset($_GET['info_status']) && array_key_exists($_GET['info_status'], labels('owner_info_status')) ? $_GET['info_status'] : null;
$archived = !empty($_GET['archived']);
$w = ['o.is_archived = ?'];
$p = [$archived ? 1 : 0];
if ($q) {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $w[] = "(CONCAT_WS(' ', o.last_name, o.first_name) LIKE ? OR CONCAT_WS(' ', o.first_name, o.last_name) LIKE ? OR o.organization_name LIKE ?
            OR o.phone LIKE ? OR o.file_reference LIKE ? OR o.id_document_number LIKE ?)";
    array_push($p, $like, $like, $like, $like, $like, $like);
}
if ($type) { $w[] = 'o.owner_type = ?'; $p[] = $type; }
if ($status) { $w[] = 'o.info_status = ?'; $p[] = $status; }
$where = implode(' AND ', $w);
$total = (int) db_value("SELECT COUNT(*) FROM owners o WHERE $where", $p);
$pg = paginate($total, 25);
$rows = db_all("SELECT o.*, (SELECT COUNT(*) FROM parcel_owners po WHERE po.owner_id = o.id AND po.valid_to IS NULL) AS parcels
                FROM owners o WHERE $where ORDER BY COALESCE(o.last_name, o.organization_name), o.first_name LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $p);
$groups = ($_GET['view'] ?? '') === 'doublons' ? OwnerService::duplicateGroups() : [];
$showForm = !empty($_GET['new']) || request_method() === 'POST';
$parcel = $parcelId ? ParcelService::get($parcelId) : null;

$pageTitle = 'Propriétaires';
$activeMenu = 'proprietaires';
require __DIR__ . '/includes/header.php';
$fv = static fn (string $k) => e($form[$k] ?? '');
?>
<div class="page-header">
    <h1><i class="bi bi-people"></i> Propriétaires <span class="text-muted fs-6">(<?= fmt_number($total) ?>)</span></h1>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('proprietaires.php', ['view' => 'doublons'])) ?>"><i class="bi bi-people-fill"></i> Doublons potentiels</a>
        <?php if (can('owner.manage')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('proprietaires.php', ['new' => 1])) ?>"><i class="bi bi-person-plus"></i> Nouveau propriétaire</a><?php endif; ?>
    </div>
</div>
<div class="legal-notice mb-3">Un nom enregistré dans l'application ne constitue jamais une preuve juridique de propriété. Le statut de l'information (déclaré, documenté, vérifié, contesté) doit toujours être précisé.</div>

<?php if ($showForm && can('owner.manage')): ?>
<div class="card mb-3"><div class="card-header">Nouveau propriétaire<?= $parcel ? ' — pour la parcelle ' . e($parcel['parcel_reference']) : '' ?></div><div class="card-body">
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($duplicates): ?><div class="alert alert-warning"><strong>Doublons potentiels :</strong><ul class="mb-0">
        <?php foreach ($duplicates as $d): ?><li><a href="<?= e(url('proprietaire_details.php', ['id' => $d['id']])) ?>" target="_blank"><?= e(owner_display_name($d)) ?></a>
            <?= $d['phone'] ? '· ' . e($d['phone']) : '' ?><?= $d['is_archived'] ? ' (archivé)' : '' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="row g-3" data-autosave="nouveau-proprietaire">
        <?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label" for="owner_type">Type *</label><select class="form-select" id="owner_type" name="owner_type">
            <?php foreach (labels('owner_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= ($form['owner_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="last_name">Nom (personne physique)</label><input class="form-control" id="last_name" name="last_name" maxlength="100" value="<?= $fv('last_name') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="first_name">Prénom(s)</label><input class="form-control" id="first_name" name="first_name" maxlength="100" value="<?= $fv('first_name') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="organization_name">Dénomination (société, collectivité, famille…)</label><input class="form-control" id="organization_name" name="organization_name" maxlength="200" value="<?= $fv('organization_name') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="phone">Téléphone</label><input class="form-control" id="phone" name="phone" maxlength="40" value="<?= $fv('phone') ?>" placeholder="+227 …"></div>
        <div class="col-md-3"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= $fv('email') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="address">Adresse déclarée</label><input class="form-control" id="address" name="address" maxlength="255" value="<?= $fv('address') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="id_document_type">Pièce d'identité (type)</label><input class="form-control" id="id_document_type" name="id_document_type" maxlength="60" value="<?= $fv('id_document_type') ?>" placeholder="CNI, passeport, RCCM…"></div>
        <div class="col-md-3"><label class="form-label" for="id_document_number">Numéro</label><input class="form-control" id="id_document_number" name="id_document_number" maxlength="80" value="<?= $fv('id_document_number') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="file_reference">Référence du dossier</label><input class="form-control" id="file_reference" name="file_reference" maxlength="80" value="<?= $fv('file_reference') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="info_status">Statut des informations</label><select class="form-select" id="info_status" name="info_status">
            <?php foreach (labels('owner_info_status') as $k => $l): if ($k === 'verifie' && !can('parcel.validate')) { continue; } ?><option value="<?= e($k) ?>" <?= ($form['info_status'] ?? 'declare') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="notes">Observations</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= $fv('notes') ?></textarea></div>
        <?php if ($parcel): ?>
            <div class="col-12 border-top pt-2"><div class="form-check"><input class="form-check-input" type="checkbox" id="link_parcel" name="link_parcel" value="1" checked>
                <label class="form-check-label" for="link_parcel">Associer à la parcelle <?= e($parcel['parcel_reference']) ?> en tant que</label></div></div>
            <div class="col-md-4"><select class="form-select form-select-sm" name="ownership_role" aria-label="Rôle"><?php foreach (labels('ownership_role') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><input class="form-control form-control-sm" type="number" step="0.001" min="0.001" max="100" name="share_percentage" placeholder="Quote-part % (si documentée)" aria-label="Quote-part"></div>
            <div class="col-md-4"><input class="form-control form-control-sm" type="date" name="valid_from" aria-label="Depuis le"></div>
        <?php endif; ?>
        <?php if ($duplicates): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="confirm_duplicate" name="confirm_duplicate" value="1">
            <label class="form-check-label" for="confirm_duplicate">Il s'agit bien d'une personne différente : créer quand même</label></div></div><?php endif; ?>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
            <a class="btn btn-outline-secondary" href="<?= e(url($parcel ? 'parcelle_details.php' : 'proprietaires.php', $parcel ? ['id' => $parcelId, 'tab' => 'proprietaires'] : [])) ?>">Annuler</a></div>
    </form>
</div></div>
<?php endif; ?>

<?php if (($_GET['view'] ?? '') === 'doublons'): ?>
<div class="card mb-3"><div class="card-header">Doublons potentiels (même nom ou même téléphone)</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Critère</th><th>Valeur</th><th>Fiches</th></tr></thead><tbody>
    <?php foreach ($groups as $g): ?><tr><td><?= e($g['critere']) ?></td><td><?= e($g['valeur']) ?></td>
        <td><?php foreach (explode(',', (string) $g['ids']) as $oid): ?><a class="me-2" href="<?= e(url('proprietaire_details.php', ['id' => (int) $oid])) ?>">#<?= (int) $oid ?></a><?php endforeach; ?></td></tr><?php endforeach; ?>
    <?php if (!$groups): ?><tr><td colspan="3" class="text-muted">Aucun doublon potentiel détecté.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>

<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
    <div class="col-md-5"><label class="form-label small mb-0" for="q">Recherche</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Nom, dénomination, téléphone, dossier, pièce d'identité"></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="ot">Type</label><select class="form-select form-select-sm" id="ot" name="owner_type"><option value="">Tous</option>
        <?php foreach (labels('owner_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="is">Statut</label><select class="form-select form-select-sm" id="is" name="info_status"><option value="">Tous</option>
        <?php foreach (labels('owner_info_status') as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-1"><div class="form-check small"><input class="form-check-input" type="checkbox" id="arch" name="archived" value="1" <?= $archived ? 'checked' : '' ?>><label class="form-check-label" for="arch">Archivés</label></div></div>
    <div class="col-md-2 d-flex gap-1"><button class="btn btn-sm btn-primary">Filtrer</button><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('proprietaires.php')) ?>">Réinit.</a></div>
</div></form>

<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
    <thead><tr><th>Nom / dénomination</th><th>Type</th><th>Téléphone</th><th>Dossier</th><th>Statut</th><th class="text-center">Parcelles</th><th>Créé le</th></tr></thead><tbody>
    <?php foreach ($rows as $o): ?>
        <tr><td><a href="<?= e(url('proprietaire_details.php', ['id' => $o['id']])) ?>"><?= e(owner_display_name($o)) ?></a>
                <?= (int) $o['is_demo'] === 1 ? ' <span class="badge text-bg-warning">DÉMO</span>' : '' ?></td>
            <td class="small"><?= e(label('owner_type', $o['owner_type'])) ?></td><td><?= e($o['phone'] ?? '—') ?></td>
            <td><?= e($o['file_reference'] ?? '—') ?></td><td class="small"><?= e(label('owner_info_status', $o['info_status'])) ?></td>
            <td class="text-center"><?= (int) $o['parcels'] ?></td><td><?= e(fmt_date($o['created_at'])) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucun propriétaire.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="card-footer"><?= pagination_links($pg) ?></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
