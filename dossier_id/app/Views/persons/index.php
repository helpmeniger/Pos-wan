<?php use App\Support\Labels; $advanced = array_filter(array_diff_key($f, ['q' => 1, 'file_number' => 1, 'approx' => 1, 'sort' => 1, 'status' => 1])); ?>
<div class="page-header">
    <h1><i class="bi bi-person-vcard"></i> Fiches individuelles</h1>
    <div class="d-flex gap-2">
        <?php if (can('export.csv')): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('persons.export', $f)) ?>"><i class="bi bi-filetype-csv"></i> Exporter (CSV)</a>
        <?php endif; ?>
        <?php if (can('persons.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('persons.create')) ?>"><i class="bi bi-person-plus"></i> Nouvelle fiche</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3"><div class="card-body">
<form method="get" action="<?= e(url('persons.index')) ?>" class="row g-2">
    <input type="hidden" name="r" value="persons.index">
    <div class="col-md-4"><label class="form-label small" for="q">Nom, prénom ou autre nom</label>
        <input class="form-control" id="q" name="q" value="<?= old($f, 'q') ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="file_number">N° de dossier</label>
        <input class="form-control" id="file_number" name="file_number" value="<?= old($f, 'file_number') ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="admin_id">Identifiant administratif</label>
        <input class="form-control" id="admin_id" name="admin_id" value="<?= old($f, 'admin_id') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="sort">Tri</label>
        <select class="form-select" id="sort" name="sort">
            <option value="">Mise à jour récente</option>
            <option value="name"<?= selected($f['sort'], 'name') ?>>Nom</option>
            <option value="number"<?= selected($f['sort'], 'number') ?>>N° de dossier</option>
        </select></div>
    <div class="col-12">
        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="approx" value="1" id="approx"<?= checked($f['approx']) ?>>
            <label class="form-check-label small" for="approx">Inclure les orthographes approchantes (ex. Moussa / Musa)</label></div>
        <a class="small ms-2" data-bs-toggle="collapse" href="#advanced" role="button" aria-expanded="<?= $advanced ? 'true' : 'false' ?>">Critères avancés</a>
    </div>
    <div class="collapse<?= $advanced ? ' show' : '' ?> col-12" id="advanced"><div class="row g-2">
        <div class="col-md-2"><label class="form-label small" for="birth_from">Né(e) à partir du</label><input class="form-control" type="date" id="birth_from" name="birth_from" value="<?= old($f, 'birth_from') ?>"></div>
        <div class="col-md-2"><label class="form-label small" for="birth_to">Né(e) jusqu'au</label><input class="form-control" type="date" id="birth_to" name="birth_to" value="<?= old($f, 'birth_to') ?>"></div>
        <div class="col-md-2"><label class="form-label small" for="reg_from">Enregistré(e) du</label><input class="form-control" type="date" id="reg_from" name="reg_from" value="<?= old($f, 'reg_from') ?>"></div>
        <div class="col-md-2"><label class="form-label small" for="reg_to">au</label><input class="form-control" type="date" id="reg_to" name="reg_to" value="<?= old($f, 'reg_to') ?>"></div>
        <?php if (can('procedures.view')): ?>
        <div class="col-md-2"><label class="form-label small" for="procedure_status">Statut procédural</label>
            <select class="form-select" id="procedure_status" name="procedure_status"><option value="">Tous</option>
                <?php foreach (Labels::PROCEDURE_STATUS as $k => [$l]): ?><option value="<?= e($k) ?>"<?= selected($f['procedure_status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label small" for="procedure_number">N° procédure / PV</label><input class="form-control" id="procedure_number" name="procedure_number" value="<?= old($f, 'procedure_number') ?>"></div>
        <?php endif; ?>
        <?php if (can('persons.view_archived')): ?>
        <div class="col-md-2"><label class="form-label small" for="status">Fiches</label>
            <select class="form-select" id="status" name="status">
                <option value="actif">Actives</option>
                <option value="archive"<?= selected($f['status'], 'archive') ?>>Archivées</option>
                <option value="tous"<?= selected($f['status'], 'tous') ?>>Toutes</option>
            </select></div>
        <?php endif; ?>
    </div></div>
    <div class="col-12 d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-search"></i> Rechercher</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('persons.index')) ?>">Réinitialiser</a>
    </div>
</form>
</div></div>

<div class="card">
    <div class="card-header d-flex justify-content-between"><span><?= e($pager->summary()) ?></span></div>
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead><tr><th>N° dossier</th><th>Nom</th><th>Prénom</th><th>Naissance</th><th>Service</th>
            <th class="text-center">Passages</th><th class="text-center">Procédures</th><th>État</th><th>Mise à jour</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">Aucune fiche ne correspond aux critères.</td></tr><?php endif; ?>
        <?php foreach ($rows as $p): ?>
            <tr data-href="<?= e(url('persons.show', ['id' => $p['id']])) ?>" class="row-link">
                <td><a href="<?= e(url('persons.show', ['id' => $p['id']])) ?>" class="fw-semibold"><?= e($p['file_number']) ?></a></td>
                <td class="text-uppercase"><?= e($p['last_name']) ?></td>
                <td><?= e($p['first_name']) ?><?php if ($p['other_names']): ?><div class="small text-muted">dit(e) <?= e($p['other_names']) ?></div><?php endif; ?></td>
                <td><?= e(fmt_date($p['birth_date'])) ?><?= !$p['birth_date'] && $p['declared_age'] ? '<span class="small text-muted">' . (int) $p['declared_age'] . ' ans décl.</span>' : '' ?></td>
                <td class="small"><?= e($p['registering_service']) ?></td>
                <td class="text-center"><?= (int) $p['visit_count'] ?></td>
                <td class="text-center"><?= (int) $p['procedure_count'] ?></td>
                <td>
                    <?php if ($p['status'] === 'archive'): ?><span class="badge text-bg-secondary">Archivée</span><?php endif; ?>
                    <?php if ($p['access_level'] === 'restreint'): ?><span class="badge text-bg-warning">Restreint</span><?php endif; ?>
                    <?php if ($p['is_demo']): ?><span class="badge badge-demo">Fictif</span><?php endif; ?>
                </td>
                <td class="small text-muted"><?= e(fmt_date($p['updated_at'], true)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="card-footer"><?= $pager->links('persons.index', $f) ?></div>
</div>
