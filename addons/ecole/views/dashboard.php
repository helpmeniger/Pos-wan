<?php use Addons\Ecole\Ecole; $s = $stat; ?>
<?php if (!$annee): ?>
  <div class="alert alert-warning"><i class="bi bi-calendar-plus"></i> <b>Première utilisation :</b> créez l'année scolaire en cours (avec ses trimestres ou semestres), puis vos classes et matières.
    <?php if (can('ecole.manage')): ?><a class="btn btn-sm btn-warning ms-2" href="<?= e(url('/ecole/parametres')) ?>">Créer l'année scolaire</a><?php endif; ?></div>
<?php endif; ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <h2 class="h5 mb-0"><?= e(Ecole::nom()) ?><?= $annee ? ' · <span class="text-secondary">Année ' . e($annee['libelle']) . '</span>' : '' ?></h2>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <?php if (can('ecole.caisse')): ?><a class="btn btn-success" href="<?= e(url('/ecole/caisse')) ?>"><i class="bi bi-cash-coin"></i> Caisse : inscriptions et paiements</a><?php endif; ?>
    <?php if (can('ecole.manage')): ?><a class="btn btn-outline-primary" href="<?= e(url('/ecole/eleves/nouveau')) ?>"><i class="bi bi-person-plus"></i> Nouvel(le) <?= e(mb_strtolower(Ecole::apprenant())) ?></a><?php endif; ?>
    <?php if (can('ecole.presence')): ?><a class="btn btn-outline-danger" href="<?= e(url('/ecole/pointage')) ?>"><i class="bi bi-person-bounding-box"></i> Pointage caméra</a><?php endif; ?>
  </div>
</div>
<div class="row g-2 mb-3 ec-stats">
  <?php foreach (array_filter([
      [Ecole::apprenant(true) . ' inscrits', $s['eleves'], $s['eleves'] ? round(100 * $s['filles'] / max(1, $s['eleves'])) . ' % de filles' : '', '/ecole/eleves', 'bi-people', true],
      ['Classes', $s['classes'], '', '/ecole/classes', 'bi-diagram-3', true],
      ['Personnel actif', $s['personnel'], $s['personnel_present'] . ' présent(s) aujourd\'hui', '/ecole/personnel', 'bi-person-badge', true],
      ['Présents aujourd\'hui', $s['presents'], $s['retards'] . ' en retard · ' . $s['absents'] . ' absent(s)', '/ecole/presences', 'bi-check2-square', can('ecole.presence')],
      ['Encaissé aujourd\'hui', money($s['jour']), 'ce mois : ' . money($s['mois']), '/ecole/comptabilite', 'bi-cash-coin', can('ecole.caisse') || can('ecole.finance')],
      ['Impayés échus', money($s['echu']), 'à relancer', '/ecole/impayes', 'bi-exclamation-diamond', can('ecole.finance')],
  ], fn($x) => $x[5]) as [$l, $v, $sub, $u, $ic]): ?>
    <div class="col-6 col-md-4 col-xl-2"><a class="card h-100 text-decoration-none" href="<?= e(url($u)) ?>"><div class="card-body py-2">
      <div class="small text-secondary"><i class="bi <?= e($ic) ?>"></i> <?= e($l) ?></div><div class="fs-4 fw-bold text-body"><?= e((string) $v) ?></div><div class="small text-secondary"><?= e($sub) ?></div></div></a></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-diagram-3"></i> Classes</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Classe</th><th>Cycle</th><th>Titulaire</th><th class="text-center">Effectif</th></tr></thead><tbody>
    <?php foreach ($classes as $c): ?><tr><td><a href="<?= e(url('/ecole/classes/' . $c['id'])) ?>"><b><?= e($c['nom']) ?></b></a></td><td class="small"><?= e(Ecole::CYCLES[$c['cycle']] ?? '') ?></td><td class="small"><?= e($c['titulaire'] ?? '—') ?></td><td class="text-center"><?= (int) $c['effectif'] ?><?= $c['capacite'] ? ' / ' . (int) $c['capacite'] : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$classes): ?><tr><td colspan="4" class="text-center text-secondary py-3">Aucune classe pour l'année en cours.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <div class="col-lg-5"><?php if ($derniers): ?><div class="card"><div class="card-header"><i class="bi bi-receipt"></i> Derniers reçus</div><ul class="list-group list-group-flush small">
    <?php foreach ($derniers as $d): ?><li class="list-group-item d-flex"><a href="<?= e(url('/ecole/recus/' . $d['id'])) ?>"><?= e($d['numero']) ?></a><span class="ms-2"><?= e($d['prenom'] . ' ' . $d['nom']) ?></span><span class="ms-auto <?= $d['annule'] ? 'text-decoration-line-through text-secondary' : 'fw-bold' ?>"><?= money($d['montant']) ?></span></li><?php endforeach; ?>
  </ul></div><?php endif; ?></div>
</div>
