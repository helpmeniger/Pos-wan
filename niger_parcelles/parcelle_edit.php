<?php
/** Modification des informations descriptives et foncières d'une parcelle (la géométrie se modifie sur la carte). */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('parcel.view');
$id = (int) ($_GET['id'] ?? 0);
$parcel = ParcelService::get($id);
if (!$parcel) {
    flash('warning', 'Parcelle introuvable.');
    redirect('parcelles.php');
}
if (!can_edit_parcel($parcel)) {
    require_permission('parcel.edit');
}

$error = null;
if (request_method() === 'POST') {
    require_csrf();
    try {
        $in = array_intersect_key($_POST, ParcelService::FIELDS);
        $res = ParcelService::updateAttributes($id, $in, (int) $user['id']);
        flash('success', $res['changed'] ? 'Informations enregistrées (' . count($res['fields']) . ' champ(s) modifié(s)).' : 'Aucune modification.');
        redirect('parcelle_details.php', ['id' => $id]);
    } catch (UserFacingException $e) {
        $error = $e->getMessage();
        $parcel = array_merge($parcel, array_intersect_key($_POST, ParcelService::FIELDS));
    }
}

$areas = admin_area_options();
$pageTitle = 'Modifier ' . $parcel['parcel_reference'];
$activeMenu = 'parcelles';
require __DIR__ . '/includes/header.php';
$v = static fn (string $k) => e($parcel[$k] ?? '');
$sel = static function (string $name, string $group, ?string $current): string {
    $h = '<select class="form-select" id="' . $name . '" name="' . $name . '">';
    foreach (labels($group) as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . ($current === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h . '</select>';
};
?>
<div class="page-header">
    <h1><i class="bi bi-pencil"></i> Modifier <span class="ref"><?= e($parcel['parcel_reference']) ?></span></h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('parcelle_details.php', ['id' => $id])) ?>">Retour à la fiche</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card" data-autosave="parcelle-<?= $id ?>">
    <?= csrf_field() ?>
    <div class="card-body row g-3">
        <h2 class="h6 col-12 mb-0 text-muted">Identification</h2>
        <div class="col-md-4"><label class="form-label">Référence application</label><input class="form-control" value="<?= e($parcel['parcel_reference']) ?>" disabled>
            <div class="form-text">Générée automatiquement, non modifiable.</div></div>
        <div class="col-md-4"><label class="form-label" for="cadastral_reference">Référence cadastrale officielle</label>
            <input class="form-control" id="cadastral_reference" name="cadastral_reference" maxlength="80" value="<?= $v('cadastral_reference') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="administrative_area_id">Zone administrative</label>
            <select class="form-select" id="administrative_area_id" name="administrative_area_id"><option value="">— Non renseignée —</option>
            <?php foreach ($areas as $a): ?><option value="<?= $a['id'] ?>" <?= (string) $parcel['administrative_area_id'] === (string) $a['id'] ? 'selected' : '' ?>><?= e($a['label']) ?></option><?php endforeach; ?>
            </select><div class="form-text">Modifier la zone ne change pas la référence déjà attribuée.</div></div>
        <div class="col-md-6"><label class="form-label" for="locality_description">Quartier / village / description</label>
            <input class="form-control" id="locality_description" name="locality_description" maxlength="255" value="<?= $v('locality_description') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="address">Adresse</label><input class="form-control" id="address" name="address" maxlength="255" value="<?= $v('address') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="category">Catégorie</label><?= $sel('category', 'category', $parcel['category']) ?></div>
        <div class="col-md-4"><label class="form-label" for="survey_level">Nature de la délimitation</label><?= $sel('survey_level', 'survey_level', $parcel['survey_level']) ?></div>
        <div class="col-12"><label class="form-label" for="description">Observations générales</label><textarea class="form-control" id="description" name="description" rows="2" maxlength="2000"><?= $v('description') ?></textarea></div>

        <h2 class="h6 col-12 mb-0 mt-4 text-muted">Informations foncières</h2>
        <div class="col-md-4"><label class="form-label" for="declared_status">Situation déclarée</label><?= $sel('declared_status', 'declared_status', $parcel['declared_status']) ?></div>
        <div class="col-md-4"><label class="form-label" for="title_reference">Référence du titre foncier</label><input class="form-control" id="title_reference" name="title_reference" maxlength="80" value="<?= $v('title_reference') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="tenure_document_type">Type de document justificatif</label><input class="form-control" id="tenure_document_type" name="tenure_document_type" maxlength="100" value="<?= $v('tenure_document_type') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="tenure_document_date">Date du document</label><input class="form-control" type="date" id="tenure_document_date" name="tenure_document_date" value="<?= $v('tenure_document_date') ?>"></div>
        <div class="col-md-8"><label class="form-label" for="issuing_authority">Autorité / organisme émetteur</label><input class="form-control" id="issuing_authority" name="issuing_authority" maxlength="150" value="<?= $v('issuing_authority') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="restrictions">Restrictions / informations administratives</label><textarea class="form-control" id="restrictions" name="restrictions" rows="3" maxlength="2000"><?= $v('restrictions') ?></textarea></div>
        <div class="col-md-6"><label class="form-label" for="observations">Observations foncières</label><textarea class="form-control" id="observations" name="observations" rows="3" maxlength="2000"><?= $v('observations') ?></textarea></div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('parcelle_details.php', ['id' => $id])) ?>">Annuler</a>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
