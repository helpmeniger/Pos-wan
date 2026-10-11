<?php use App\Support\Labels; ?>
<div class="page-header"><h1><i class="bi bi-folder2-open"></i> Procédures</h1></div>
<div class="alert alert-info small">Le statut indique la situation procédurale documentée, et non une appréciation de culpabilité.</div>
<div class="card mb-3"><div class="card-body">
<form method="get" action="<?= e(url('procedures.index')) ?>" class="row g-2">
    <input type="hidden" name="r" value="procedures.index">
    <div class="col-md-3"><label class="form-label small" for="number">N° procédure, PV ou juridiction</label><input class="form-control" id="number" name="number" value="<?= old($f, 'number') ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="status">Statut</label>
        <select class="form-select" id="status" name="status"><option value="">Tous</option>
            <?php foreach (Labels::PROCEDURE_STATUS as $k => [$l]): ?><option value="<?= e($k) ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small" for="service">Service enquêteur</label><input class="form-control" id="service" name="service" value="<?= old($f, 'service') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="date_from">Faits du</label><input class="form-control" type="date" id="date_from" name="date_from" value="<?= old($f, 'date_from') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="date_to">au</label><input class="form-control" type="date" id="date_to" name="date_to" value="<?= old($f, 'date_to') ?>"></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i> Rechercher</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('procedures.index')) ?>">Réinitialiser</a></div>
</form>
</div></div>
<div class="card">
    <div class="card-header"><?= e($pager->summary()) ?></div>
    <div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0">
        <thead><tr><th>N° procédure</th><th>Fiche</th><th>Faits allégués</th><th>Service</th><th>Statut</th><th>Mise à jour</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Aucune procédure.</td></tr><?php endif; ?>
        <?php foreach ($rows as $pr): ?>
            <tr><td><?= e($pr['procedure_number']) ?><?= $pr['pv_reference'] ? '<div class="small text-muted">PV ' . e($pr['pv_reference']) . '</div>' : '' ?></td>
                <td><a href="<?= e(url('persons.show', ['id' => $pr['person_id'], 'tab' => 'procedures'])) ?>"><span class="text-uppercase"><?= e($pr['last_name']) ?></span> <?= e($pr['first_name']) ?></a><div class="small text-muted"><?= e($pr['file_number']) ?></div></td>
                <td><?= e($pr['facts_nature']) ?><div class="small text-muted"><?= e(fmt_date($pr['facts_date'])) ?></div></td>
                <td class="small"><?= e($pr['investigating_service']) ?></td><td><?= status_badge($pr['status']) ?></td>
                <td class="small text-muted"><?= e(fmt_date($pr['updated_at'], true)) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="card-footer"><?= $pager->links('procedures.index', $f) ?></div>
</div>
