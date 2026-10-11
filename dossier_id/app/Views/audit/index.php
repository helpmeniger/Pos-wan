<div class="page-header"><h1><i class="bi bi-journal-text"></i> Journal d'audit</h1>
    <div class="d-flex gap-2">
        <form method="post" action="<?= e(url('audit.verify')) ?>"><?= csrf_field() ?>
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-shield-check"></i> Vérifier l'intégrité</button></form>
        <?php if (can('export.csv')): ?><a class="btn btn-outline-secondary" href="<?= e(url('audit.export', $f)) ?>"><i class="bi bi-filetype-csv"></i> Exporter</a><?php endif; ?>
    </div>
</div>
<?php if ($chain): ?>
    <div class="alert alert-<?= $chain['ok'] ? 'success' : 'danger' ?>">
        <?= $chain['ok'] ? '<i class="bi bi-shield-check"></i> Chaîne d\'intégrité valide : ' . (int) $chain['count'] . ' entrées vérifiées'
            : '<i class="bi bi-shield-x"></i> Chaîne rompue à l\'entrée n° ' . (int) $chain['broken_at'] . ' : le journal a pu être altéré. Signalez-le immédiatement.' ?>
        <span class="small">(vérification du <?= e(fmt_date($chain['at'], true)) ?>)</span>
    </div>
<?php endif; ?>
<p class="small text-muted">Chaque entrée est chaînée à la précédente par une empreinte SHA-256 : toute modification ou suppression est détectable.
    Le journal ne contient aucun mot de passe ni contenu de document.</p>
<div class="card mb-3"><div class="card-body">
<form method="get" action="<?= e(url('audit.index')) ?>" class="row g-2">
    <input type="hidden" name="r" value="audit.index">
    <div class="col-md-2"><label class="form-label small" for="username">Utilisateur</label><input class="form-control" id="username" name="username" value="<?= old($f, 'username') ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="action">Action</label>
        <select class="form-select" id="action" name="action"><option value="">Toutes</option>
            <?php foreach (['auth.' => 'Authentification (toutes)', 'access.' => 'Accès refusés', 'person.' => 'Fiches (toutes)', 'photo.' => 'Photographies', 'visit.' => 'Passages',
                'procedure.' => 'Procédures', 'document.' => 'Documents', 'duplicate.' => 'Doublons', 'user.' => 'Comptes', 'backup.' => 'Sauvegardes', 'settings.' => 'Paramètres', 'permissions.' => 'Permissions'] as $k => $l): ?>
                <option value="<?= e($k) ?>"<?= selected($f['action'], $k) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
            <optgroup label="Action précise">
            <?php foreach ($actions as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['action'], $k) ?>><?= e($l) ?></option><?php endforeach; ?>
            </optgroup></select></div>
    <div class="col-md-2"><label class="form-label small" for="entity_id">Id de fiche</label><input class="form-control" id="entity_id" name="entity_id" value="<?= old($f, 'entity_id') ?>" inputmode="numeric"></div>
    <div class="col-md-2"><label class="form-label small" for="date_from">Du</label><input class="form-control" type="date" id="date_from" name="date_from" value="<?= old($f, 'date_from') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="date_to">Au</label><input class="form-control" type="date" id="date_to" name="date_to" value="<?= old($f, 'date_to') ?>"></div>
    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100" aria-label="Filtrer"><i class="bi bi-funnel"></i></button></div>
</form>
</div></div>
<div class="card">
    <div class="card-header"><?= e($pager->summary()) ?></div>
    <div class="table-responsive"><table class="table table-sm table-hover mb-0 audit-table">
        <thead><tr><th>N°</th><th>Date</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>Poste</th><th>Détails</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= in_array($r['action'], ['access.denied', 'auth.locked', 'csrf.invalid', 'auth.blocked_ip'], true) ? 'table-warning' : '' ?>">
                <td class="small text-muted"><?= (int) $r['id'] ?></td>
                <td class="small text-nowrap"><?= e(fmt_date($r['created_at'], true)) ?></td>
                <td class="small"><?= e($r['username'] ?? '—') ?></td>
                <td class="small"><?= e($actions[$r['action']] ?? $r['action']) ?></td>
                <td class="small"><?php if ($r['entity_type'] === 'person' && $r['entity_id']): ?>Fiche #<?= (int) $r['entity_id'] ?><?php elseif ($r['entity_type']): ?><?= e($r['entity_type']) ?> #<?= (int) $r['entity_id'] ?><?php endif; ?></td>
                <td class="small"><?= e($r['ip_address']) ?></td>
                <td class="small text-break"><code><?= e($r['details'] ?? '') ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="card-footer"><?= $pager->links('audit.index', $f) ?></div>
</div>
