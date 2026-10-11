<?php
/** Documents justificatifs : liste, filtres, ajout rattaché à une parcelle, vérification. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
require_permission('document.view');

if (request_method() === 'POST') {
    require_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'upload') {
            require_permission('document.upload');
            $ref = trim((string) ($_POST['parcel_reference'] ?? ''));
            $pid = (int) db_value('SELECT id FROM parcels WHERE parcel_reference = ?', [$ref]);
            if (!$pid) {
                throw new UserFacingException('Référence de parcelle inconnue : « ' . $ref . ' ».');
            }
            DocumentService::store($_FILES['file'] ?? [], ['parcel_id' => $pid] + $_POST, (int) $user['id']);
            flash('success', 'Document ajouté à la parcelle ' . $ref . '.');
        } elseif ($action === 'status') {
            require_permission('document.verify');
            DocumentService::setStatus((int) ($_POST['document_id'] ?? 0), (string) ($_POST['verification_status'] ?? ''), (int) $user['id']);
            flash('success', 'Statut mis à jour.');
        } elseif ($action === 'archive') {
            require_permission('document.verify');
            DocumentService::archive((int) ($_POST['document_id'] ?? 0), (int) $user['id']);
            flash('success', 'Document archivé (le fichier est conservé).');
        }
    } catch (UserFacingException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('documents.php', array_intersect_key($_GET, array_flip(['q', 'document_type', 'status', 'page'])));
}

$q = input_str('q', 100);
$type = isset($_GET['document_type']) && array_key_exists($_GET['document_type'], labels('document_type')) ? $_GET['document_type'] : null;
$status = isset($_GET['status']) && array_key_exists($_GET['status'], labels('document_status')) ? $_GET['status'] : null;
$w = ['d.is_archived = 0'];
$p = [];
if (!can('owner.view_personal')) {
    $w[] = "d.owner_id IS NULL AND d.document_type <> 'piece_identite'";
}
if ($q) {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $w[] = '(d.title LIKE ? OR d.document_reference LIKE ? OR d.original_filename LIKE ? OR p.parcel_reference LIKE ?)';
    array_push($p, $like, $like, $like, $like);
}
if ($type) { $w[] = 'd.document_type = ?'; $p[] = $type; }
if ($status) { $w[] = 'd.verification_status = ?'; $p[] = $status; }
$where = implode(' AND ', $w);
$total = (int) db_value("SELECT COUNT(*) FROM documents d LEFT JOIN parcels p ON p.id = d.parcel_id WHERE $where", $p);
$pg = paginate($total, 25);
$rows = db_all("SELECT d.*, p.parcel_reference, u.full_name AS uploaded_by_name, o.first_name, o.last_name, o.organization_name, o.owner_type
                FROM documents d LEFT JOIN parcels p ON p.id = d.parcel_id LEFT JOIN owners o ON o.id = d.owner_id
                LEFT JOIN users u ON u.id = d.uploaded_by WHERE $where ORDER BY d.uploaded_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $p);

$pageTitle = 'Documents';
$activeMenu = 'documents';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><i class="bi bi-folder2-open"></i> Documents <span class="text-muted fs-6">(<?= fmt_number($total) ?>)</span></h1></div>
<p class="small text-muted">Les fichiers sont stockés dans un espace privé (storage/documents) sous un nom interne aléatoire ; ils ne sont accessibles que par ce contrôleur, après vérification de vos droits. Chaque téléchargement est journalisé.</p>

<?php if (can('document.upload')): ?>
<div class="card mb-3"><div class="card-header">Ajouter un document à une parcelle</div><div class="card-body">
    <form method="post" enctype="multipart/form-data" class="row g-2 small"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <div class="col-md-3"><label class="form-label mb-0" for="pr">Référence de la parcelle *</label><input class="form-control form-control-sm" id="pr" name="parcel_reference" required placeholder="NIG-NIA-2026-000001"></div>
        <div class="col-md-3"><label class="form-label mb-0" for="f">Fichier * (PDF, JPG, PNG, WEBP)</label><input class="form-control form-control-sm" type="file" id="f" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
        <div class="col-md-2"><label class="form-label mb-0" for="t">Catégorie</label><select class="form-select form-select-sm" id="t" name="document_type"><?php foreach (labels('document_type') as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label mb-0" for="ti">Titre</label><input class="form-control form-control-sm" id="ti" name="title" maxlength="200"></div>
        <div class="col-md-2"><label class="form-label mb-0" for="r">Référence</label><input class="form-control form-control-sm" id="r" name="document_reference" maxlength="100"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Envoyer</button></div>
    </form></div></div>
<?php endif; ?>

<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
    <div class="col-md-5"><label class="form-label small mb-0" for="q">Recherche</label><input class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Titre, référence, fichier, parcelle"></div>
    <div class="col-md-3"><label class="form-label small mb-0" for="dt">Catégorie</label><select class="form-select form-select-sm" id="dt" name="document_type"><option value="">Toutes</option><?php foreach (labels('document_type') as $k => $l): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0" for="ds">Statut</label><select class="form-select form-select-sm" id="ds" name="status"><option value="">Tous</option><?php foreach (labels('document_status') as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary">Filtrer</button> <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('documents.php')) ?>">Réinit.</a></div>
</div></form>

<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Titre</th><th>Catégorie</th><th>Rattachement</th><th>Référence</th><th>Fichier</th><th>Ajouté</th><th>Statut</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $d): ?><tr>
        <td><?= e($d['title']) ?></td><td><?= e(label('document_type', $d['document_type'])) ?></td>
        <td class="small"><?php if ($d['parcel_id']): ?><a class="ref" href="<?= e(url('parcelle_details.php', ['id' => $d['parcel_id'], 'tab' => 'documents'])) ?>"><?= e($d['parcel_reference']) ?></a><?php endif; ?>
            <?php if ($d['owner_id']): ?><br><a href="<?= e(url('proprietaire_details.php', ['id' => $d['owner_id']])) ?>"><?= e(owner_display_name($d)) ?></a><?php endif; ?></td>
        <td><?= e($d['document_reference'] ?? '—') ?></td>
        <td class="small"><?= e($d['original_filename']) ?><br><?= fmt_number($d['file_size'] / 1024) ?> Ko · <?= e($d['mime_type']) ?></td>
        <td class="small"><?= e(fmt_date($d['uploaded_at'], true)) ?><br><?= e($d['uploaded_by_name'] ?? '—') ?></td>
        <td><?php if (can('document.verify')): ?><form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
            <select class="form-select form-select-sm" name="verification_status" aria-label="Statut"><?php foreach (labels('document_status') as $k => $l): ?><option value="<?= e($k) ?>" <?= $d['verification_status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <button class="btn btn-sm btn-outline-secondary" title="Enregistrer"><i class="bi bi-check"></i></button></form>
            <?php else: ?><?= e(label('document_status', $d['verification_status'])) ?><?php endif; ?></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('api/documents.php', ['action' => 'download', 'id' => $d['id']])) ?>" title="Télécharger"><i class="bi bi-download"></i></a>
            <?php if (can('document.verify')): ?><form method="post" class="d-inline" data-confirm="Archiver ce document ?"><?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Archiver"><i class="bi bi-archive"></i></button></form><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucun document.</td></tr><?php endif; ?>
    </tbody></table></div><div class="card-footer"><?= pagination_links($pg) ?></div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
