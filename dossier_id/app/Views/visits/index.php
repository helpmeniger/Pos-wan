<?php use App\Support\Labels; ?>
<div class="page-header"><h1><i class="bi bi-calendar-check"></i> Historique des passages</h1></div>
<div class="card mb-3"><div class="card-body">
<form method="get" action="<?= e(url('visits.index')) ?>" class="row g-2">
    <input type="hidden" name="r" value="visits.index">
    <div class="col-md-2"><label class="form-label small" for="date_from">Du</label><input class="form-control" type="date" id="date_from" name="date_from" value="<?= old($f, 'date_from') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="date_to">Au</label><input class="form-control" type="date" id="date_to" name="date_to" value="<?= old($f, 'date_to') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="service">Service</label><input class="form-control" id="service" name="service" value="<?= old($f, 'service') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="reference">Référence</label><input class="form-control" id="reference" name="reference" value="<?= old($f, 'reference') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="reason">Motif</label><input class="form-control" id="reason" name="reason" value="<?= old($f, 'reason') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="closure_status">Statut</label>
        <select class="form-select" id="closure_status" name="closure_status"><option value="">Tous</option>
            <?php foreach (Labels::VISIT_CLOSURE as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['closure_status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-search"></i> Rechercher</button>
        <a class="btn btn-outline-secondary" href="<?= e(url('visits.index')) ?>">Réinitialiser</a></div>
</form>
</div></div>
<div class="card">
    <div class="card-header"><?= e($pager->summary()) ?></div>
    <div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0">
        <thead><tr><th>Date</th><th>Fiche</th><th>Service</th><th>Motif</th><th>Référence</th><th>Agent</th><th>Statut</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucun passage.</td></tr><?php endif; ?>
        <?php foreach ($rows as $v): ?>
            <tr><td class="text-nowrap"><?= e(fmt_date($v['visit_date'], true)) ?></td>
                <td><a href="<?= e(url('persons.show', ['id' => $v['person_id'], 'tab' => 'visits'])) ?>"><span class="text-uppercase"><?= e($v['last_name']) ?></span> <?= e($v['first_name']) ?></a><div class="small text-muted"><?= e($v['file_number']) ?></div></td>
                <td><?= e($v['service']) ?></td><td><?= e($v['reason']) ?></td><td><?= e($v['reference']) ?></td><td class="small"><?= e($v['responsible_agent']) ?></td>
                <td><span class="badge <?= $v['closure_status'] === 'cloture' ? 'text-bg-secondary' : 'text-bg-info' ?>"><?= e(Labels::VISIT_CLOSURE[$v['closure_status']]) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="card-footer"><?= $pager->links('visits.index', $f) ?></div>
</div>
