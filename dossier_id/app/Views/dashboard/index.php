<?php use App\Support\Labels; ?>
<div class="page-header">
    <h1><i class="bi bi-speedometer2"></i> Tableau de bord</h1>
    <?php if (can('persons.create')): ?>
        <a class="btn btn-primary" href="<?= e(url('persons.create')) ?>"><i class="bi bi-person-plus"></i> Nouvelle fiche</a>
    <?php endif; ?>
</div>

<?php if (can('persons.view')): ?>
<div class="card mb-4">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get" action="<?= e(url('persons.index')) ?>">
            <input type="hidden" name="r" value="persons.index">
            <div class="col-md-5"><label class="form-label small" for="dq">Nom / prénom</label>
                <input class="form-control" id="dq" name="q" placeholder="ex. : Diallo Aminata"></div>
            <div class="col-md-3"><label class="form-label small" for="dfn">N° de dossier</label>
                <input class="form-control" id="dfn" name="file_number" placeholder="DID-2026-…"></div>
            <div class="col-md-2"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="approx" value="1" id="dapprox">
                <label class="form-check-label small" for="dapprox">Orthographe approchante</label></div></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-search"></i> Rechercher</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($stats)): ?>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Fiches actives', $stats['persons_active'], 'person-vcard', 'Hors fiches archivées (' . $stats['persons_archived'] . ')'],
        ['Passages enregistrés', $stats['visits_total'], 'calendar-check', $stats['visits_30d'] . ' sur les 30 derniers jours'],
        ['Fiches mises à jour', $stats['updated_7d'], 'arrow-repeat', 'Au cours des 7 derniers jours'],
        ['Passages en cours', $stats['visits_open'], 'hourglass-split', 'Dossiers de passage non clôturés'],
    ] as [$label, $value, $icon, $hint]): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="stat-icon"><i class="bi bi-<?= e($icon) ?>"></i></div>
            <div class="stat-value"><?= number_format((int) $value, 0, ',', ' ') ?></div>
            <div class="stat-label"><?= e($label) ?></div>
            <div class="small text-muted"><?= e($hint) ?></div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-4">
    <?php if (isset($alerts)): ?>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header"><i class="bi bi-shield-exclamation"></i> Alertes de sécurité</div>
            <div class="card-body">
                <?php if (!$alerts): ?>
                    <p class="text-success mb-0"><i class="bi bi-check-circle"></i> Aucune alerte.</p>
                <?php else: foreach ($alerts as $a): ?>
                    <div class="alert alert-<?= e($a['level']) ?> py-2 mb-2"><?= e($a['text']) ?></div>
                <?php endforeach; endif; ?>
            </div></div>
    </div>
    <?php endif; ?>

    <?php if (isset($recent)): ?>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header"><i class="bi bi-clock-history"></i> Dossiers récemment mis à jour</div>
            <div class="list-group list-group-flush">
                <?php if (!$recent): ?><div class="list-group-item text-muted">Aucune fiche.</div><?php endif; ?>
                <?php foreach ($recent as $r): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e(url('persons.show', ['id' => $r['id']])) ?>">
                        <span><span class="text-uppercase fw-semibold"><?= e($r['last_name']) ?></span> <?= e($r['first_name']) ?>
                            <span class="text-muted small ms-2"><?= e($r['file_number']) ?></span>
                            <?php if ($r['access_level'] === 'restreint'): ?><span class="badge text-bg-warning ms-1">Restreint</span><?php endif; ?></span>
                        <span class="small text-muted"><?= e(fmt_date($r['updated_at'], true)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($stats)): ?>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header"><i class="bi bi-bar-chart"></i> Passages par mois (12 mois)</div>
            <div class="card-body">
                <?php $maxM = max([1, ...array_map('intval', array_column($by_month, 'n'))]); ?>
                <?php if (!$by_month): ?><p class="text-muted mb-0">Aucune donnée.</p><?php endif; ?>
                <?php foreach ($by_month as $m): ?>
                    <div class="bar-row"><span class="bar-label"><?= e(date('m/Y', strtotime($m['m'] . '-01'))) ?></span>
                        <progress class="bar" max="<?= $maxM ?>" value="<?= (int) $m['n'] ?>"></progress><span class="bar-value"><?= (int) $m['n'] ?></span></div>
                <?php endforeach; ?>
                <p class="small text-muted mt-2 mb-0">Statistiques administratives agrégées : volume d'activité des services, sans valeur d'appréciation sur les personnes.</p>
            </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header"><i class="bi bi-diagram-3"></i> Passages par service (12 mois)</div>
            <div class="card-body">
                <?php $maxS = max([1, ...array_map('intval', array_column($by_service, 'n'))]); ?>
                <?php if (!$by_service): ?><p class="text-muted mb-0">Aucune donnée.</p><?php endif; ?>
                <?php foreach ($by_service as $s): ?>
                    <div class="bar-row"><span class="bar-label text-truncate" title="<?= e($s['service']) ?>"><?= e($s['service']) ?></span>
                        <progress class="bar" max="<?= $maxS ?>" value="<?= (int) $s['n'] ?>"></progress><span class="bar-value"><?= (int) $s['n'] ?></span></div>
                <?php endforeach; ?>
            </div></div>
    </div>
    <?php endif; ?>

    <?php if (isset($last_ops)): ?>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header"><i class="bi bi-journal-text"></i> Dernières opérations</div>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($last_ops as $o): ?>
                    <tr><td class="small text-muted text-nowrap"><?= e(fmt_date($o['created_at'], true)) ?></td>
                        <td class="small"><?= e($o['username'] ?? '—') ?></td>
                        <td class="small"><?= e(Labels::AUDIT_ACTIONS[$o['action']] ?? $o['action']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <div class="card-footer"><a href="<?= e(url('audit.index')) ?>">Journal complet</a></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($db_status)): ?>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-header"><i class="bi bi-database"></i> État de la base de données</div>
            <div class="card-body"><dl class="row mb-0 small">
                <dt class="col-6">Serveur</dt><dd class="col-6"><?= e($db_status['version']) ?></dd>
                <dt class="col-6">Version du schéma</dt><dd class="col-6"><?= e($db_status['schema']) ?></dd>
                <dt class="col-6">Taille</dt><dd class="col-6"><?= e($db_status['size_mb']) ?> Mo</dd>
                <dt class="col-6">Dernière sauvegarde</dt><dd class="col-6"><?= $db_status['last_backup'] ? e(date('d/m/Y H:i', $db_status['last_backup'])) : '<span class="text-danger">aucune</span>' ?></dd>
            </dl></div></div>
    </div>
    <?php endif; ?>
</div>
