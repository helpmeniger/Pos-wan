<?php
/** Fiche propriétaire : informations, parcelles (actuelles et passées), documents, modification, archivage. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('owner.view_personal');
$id = (int) ($_GET['id'] ?? 0);
$owner = OwnerService::get($id);
if (!$owner) {
    flash('warning', 'Propriétaire introuvable.');
    redirect('proprietaires.php');
}

$error = null;
if (request_method() === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'update':
                require_permission('owner.manage');
                $in = array_intersect_key($_POST, OwnerService::FIELDS);
                if (($in['info_status'] ?? '') === 'verifie' && $owner['info_status'] !== 'verifie' && !can('parcel.validate')) {
                    throw new UserFacingException('Seuls les profils habilités peuvent marquer des informations comme vérifiées.', 403);
                }
                OwnerService::update($id, $in, (int) $user['id']);
                flash('success', 'Fiche mise à jour.');
                break;
            case 'archive':
            case 'unarchive':
                require_permission('owner.manage');
                OwnerService::setArchived($id, $action === 'archive');
                flash('success', $action === 'archive' ? 'Dossier archivé (historique conservé).' : 'Dossier réactivé.');
                break;
            case 'upload':
                require_permission('document.upload');
                DocumentService::store($_FILES['file'] ?? [], ['owner_id' => $id] + $_POST, (int) $user['id']);
                flash('success', 'Document ajouté.');
                break;
            default:
                throw new UserFacingException('Action inconnue.');
        }
        redirect('proprietaire_details.php', ['id' => $id]);
    } catch (UserFacingException $e) {
        $error = $e->getMessage();
    }
}

audit_log('consultation_proprietaire', 'owner', $id);
$parcels = OwnerService::parcelsOf($id);
$documents = can('document.view') ? db_all('SELECT * FROM documents WHERE owner_id = ? ORDER BY is_archived, uploaded_at DESC', [$id]) : [];
$duplicates = OwnerService::findDuplicates($owner, $id);

$pageTitle = owner_display_name($owner);
$activeMenu = 'proprietaires';
require __DIR__ . '/includes/header.php';
$v = static fn (string $k) => e($owner[$k] ?? '');
$dd = static fn (string $k, $val) => '<dt class="col-sm-4">' . e($k) . '</dt><dd class="col-sm-8">' . ($val === null || $val === '' ? '<span class="text-muted">—</span>' : e($val)) . '</dd>';
?>
<div class="page-header">
    <div><h1><i class="bi bi-person"></i> <?= e(owner_display_name($owner)) ?>
        <?= (int) $owner['is_archived'] === 1 ? '<span class="badge text-bg-dark">Archivé</span>' : '' ?>
        <?= (int) $owner['is_demo'] === 1 ? '<span class="badge text-bg-warning">DÉMONSTRATION FICTIVE</span>' : '' ?></h1>
        <div class="text-muted small"><?= e(label('owner_type', $owner['owner_type'])) ?> · <?= e(label('owner_info_status', $owner['info_status'])) ?></div></div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('parcelles.php', ['owner_id' => $id])) ?>"><i class="bi bi-bounding-box"></i> Ses parcelles</a>
        <?php if (can('export.run')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('api/export.php', ['type' => 'owner_pdf', 'id' => $id])) ?>"><i class="bi bi-filetype-pdf"></i> Fiche PDF</a><?php endif; ?>
    </div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($duplicates): ?><div class="alert alert-warning py-2"><i class="bi bi-people-fill"></i> Doublons potentiels :
    <?php foreach ($duplicates as $d): ?><a class="ms-2" href="<?= e(url('proprietaire_details.php', ['id' => $d['id']])) ?>"><?= e(owner_display_name($d)) ?> (#<?= (int) $d['id'] ?>)</a><?php endforeach; ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5"><div class="card h-100"><div class="card-header">Informations</div><div class="card-body"><dl class="row small mb-0">
        <?= $dd('Type', label('owner_type', $owner['owner_type'])) ?>
        <?= $dd('Nom', $owner['last_name']) ?><?= $dd('Prénom(s)', $owner['first_name']) ?><?= $dd('Dénomination', $owner['organization_name']) ?>
        <?= $dd('Téléphone', $owner['phone']) ?><?= $dd('E-mail', $owner['email']) ?><?= $dd('Adresse déclarée', $owner['address']) ?>
        <?= $dd('Pièce d\'identité', trim(($owner['id_document_type'] ?? '') . ' ' . ($owner['id_document_number'] ?? ''))) ?>
        <?= $dd('Référence du dossier', $owner['file_reference']) ?>
        <?= $dd('Statut des informations', label('owner_info_status', $owner['info_status'])) ?>
        <?= $dd('Dernière vérification', $owner['last_verified_at'] ? fmt_date($owner['last_verified_at'], true) . ' (' . ($owner['verified_by_name'] ?? '—') . ')' : null) ?>
        <?= $dd('Observations', $owner['notes']) ?>
        <?= $dd('Créé le', fmt_date($owner['created_at'], true)) ?>
    </dl></div></div></div>
    <div class="col-lg-7"><div class="card h-100"><div class="card-header">Parcelles associées</div>
        <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Parcelle</th><th>Rôle</th><th>Quote-part</th><th>Période</th><th>Statut du lien</th></tr></thead><tbody>
        <?php foreach ($parcels as $pp): ?><tr class="<?= $pp['valid_to'] ? 'text-muted' : '' ?>">
            <td><a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $pp['parcel_id'], 'tab' => 'proprietaires'])) ?>"><?= e($pp['parcel_reference']) ?></a></td>
            <td><?= e(label('ownership_role', $pp['ownership_role'])) ?></td>
            <td><?= $pp['share_percentage'] !== null ? fmt_number($pp['share_percentage'], 2) . ' %' : '—' ?></td>
            <td class="small"><?= e(fmt_date($pp['valid_from'])) ?> → <?= $pp['valid_to'] ? e(fmt_date($pp['valid_to'])) : '<span class="badge text-bg-success">en cours</span>' ?></td>
            <td class="small"><?= e(label('link_status', $pp['verification_status'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$parcels): ?><tr><td colspan="5" class="text-muted">Aucune parcelle associée.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
</div>

<?php if (can('document.view')): ?>
<div class="card mt-3"><div class="card-header">Documents du propriétaire</div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Titre</th><th>Catégorie</th><th>Référence</th><th>Ajouté le</th><th>Statut</th><th></th></tr></thead><tbody>
    <?php foreach ($documents as $d): ?><tr class="<?= $d['is_archived'] ? 'text-muted' : '' ?>"><td><?= e($d['title']) ?></td><td><?= e(label('document_type', $d['document_type'])) ?></td>
        <td><?= e($d['document_reference'] ?? '—') ?></td><td><?= e(fmt_date($d['uploaded_at'])) ?></td><td><?= e(label('document_status', $d['verification_status'])) ?></td>
        <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('api/documents.php', ['action' => 'download', 'id' => $d['id']])) ?>"><i class="bi bi-download"></i></a></td></tr><?php endforeach; ?>
    <?php if (!$documents): ?><tr><td colspan="6" class="text-muted">Aucun document.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if (can('document.upload')): ?><div class="card-body border-top">
        <form method="post" enctype="multipart/form-data" class="row g-2 small"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
            <div class="col-md-4"><input class="form-control form-control-sm" type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp" aria-label="Fichier"></div>
            <div class="col-md-3"><select class="form-select form-select-sm" name="document_type" aria-label="Catégorie"><?php foreach (labels('document_type') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="title" placeholder="Titre" maxlength="200" aria-label="Titre"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-upload"></i> Ajouter</button></div>
        </form></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if (can('owner.manage')): ?>
<div class="card mt-3"><div class="card-header">Modifier la fiche</div><div class="card-body">
    <form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="action" value="update">
        <div class="col-md-4"><label class="form-label" for="owner_type">Type</label><select class="form-select" id="owner_type" name="owner_type">
            <?php foreach (labels('owner_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $owner['owner_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="last_name">Nom</label><input class="form-control" id="last_name" name="last_name" maxlength="100" value="<?= $v('last_name') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="first_name">Prénom(s)</label><input class="form-control" id="first_name" name="first_name" maxlength="100" value="<?= $v('first_name') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="organization_name">Dénomination</label><input class="form-control" id="organization_name" name="organization_name" maxlength="200" value="<?= $v('organization_name') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="phone">Téléphone</label><input class="form-control" id="phone" name="phone" maxlength="40" value="<?= $v('phone') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= $v('email') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="address">Adresse</label><input class="form-control" id="address" name="address" maxlength="255" value="<?= $v('address') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="id_document_type">Pièce (type)</label><input class="form-control" id="id_document_type" name="id_document_type" maxlength="60" value="<?= $v('id_document_type') ?>"></div>
        <div class="col-md-3"><label class="form-label" for="id_document_number">Numéro</label><input class="form-control" id="id_document_number" name="id_document_number" maxlength="80" value="<?= $v('id_document_number') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="file_reference">Référence du dossier</label><input class="form-control" id="file_reference" name="file_reference" maxlength="80" value="<?= $v('file_reference') ?>"></div>
        <div class="col-md-4"><label class="form-label" for="info_status">Statut des informations</label><select class="form-select" id="info_status" name="info_status">
            <?php foreach (labels('owner_info_status') as $k => $l): ?><option value="<?= e($k) ?>" <?= $owner['info_status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="notes">Observations</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= $v('notes') ?></textarea></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button></div>
    </form>
    <form method="post" class="mt-3" data-confirm="<?= (int) $owner['is_archived'] === 1 ? 'Réactiver ce dossier ?' : 'Archiver ce dossier ? Son historique sera conservé.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="<?= (int) $owner['is_archived'] === 1 ? 'unarchive' : 'archive' ?>">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-archive"></i> <?= (int) $owner['is_archived'] === 1 ? 'Réactiver le dossier' : 'Archiver le dossier' ?></button>
    </form>
</div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
