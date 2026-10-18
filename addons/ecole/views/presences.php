<?php use Addons\Ecole\Ecole; use Addons\Ecole\Presences; $tot = ['inscrits' => 0, 'presents' => 0, 'retards' => 0, 'absents' => 0, 'excuses' => 0, 'non_pointes' => 0];
foreach ($jour['classes'] as $c) { foreach ($tot as $k => $_) { $tot[$k] += (int) $c[$k]; } } ?>
<div class="d-flex flex-wrap gap-2 align-items-end mb-3">
  <form method="get" class="d-flex gap-2 align-items-end"><div><label class="form-label small mb-0">Date</label><input type="date" name="date" class="form-control form-control-sm" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" data-autosubmit></div></form>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-danger" href="<?= e(url('/ecole/pointage')) ?>"><i class="bi bi-person-bounding-box"></i> Pointage caméra</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/presences/rapport')) ?>"><i class="bi bi-bar-chart"></i> Absences et retards</a>
    <?php if (can('ecole.biometrie')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/passages')) ?>"><i class="bi bi-camera-video"></i> Passages à vérifier</a><?php endif; ?>
  </div>
</div>
<div class="row g-2 mb-3 ec-stats">
  <?php foreach ([['Inscrits', $tot['inscrits'], ''], ['Présents', $tot['presents'] + $tot['retards'], 'dont ' . $tot['retards'] . ' en retard'], ['Absents', $tot['absents'] + $tot['excuses'], $tot['excuses'] . ' excusé(s)'], ['Non pointés', $tot['non_pointes'], 'ni caméra ni appel']] as [$l, $v, $sub]): ?>
    <div class="col-6 col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary"><?= e($l) ?></div><div class="fs-4 fw-bold"><?= (int) $v ?></div><div class="small text-secondary"><?= e($sub) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header d-flex align-items-center"><span><i class="bi bi-diagram-3"></i> <?= e(Ecole::apprenant(true)) ?> par classe · <?= e(fdate($date)) ?></span>
    <?php if ($tot['non_pointes']): ?><form method="post" action="<?= e(url('/ecole/presences/cloturer')) ?>" class="ms-auto" data-confirm="Noter absents les <?= (int) $tot['non_pointes'] ?> inscrit(s) qui n'ont été ni pointés ni appelés ce jour ? (L'appel reste modifiable ensuite.)"><?= csrf_field() ?><input type="hidden" name="date" value="<?= e($date) ?>"><button class="btn btn-sm btn-outline-danger">Clôturer la journée</button></form><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Classe</th><th class="text-center">Inscrits</th><th class="text-center">Présents</th><th class="text-center">Retards</th><th class="text-center">Absents</th><th class="text-center">Non pointés</th><th></th></tr></thead><tbody>
    <?php foreach ($jour['classes'] as $c): ?><tr><td><?= e($c['nom']) ?></td><td class="text-center"><?= (int) $c['inscrits'] ?></td><td class="text-center text-success"><?= (int) $c['presents'] + (int) $c['retards'] ?></td><td class="text-center"><?= (int) $c['retards'] ?></td><td class="text-center text-danger"><?= (int) $c['absents'] + (int) $c['excuses'] ?></td><td class="text-center text-secondary"><?= (int) $c['non_pointes'] ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/ecole/presences/classe/' . $c['id'], ['date' => $date])) ?>">Appel</a></td></tr><?php endforeach; ?>
    <?php if (!$jour['classes']): ?><tr><td colspan="7" class="text-secondary">Aucune classe avec des inscrits pour l'année en cours.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header d-flex align-items-center"><span><i class="bi bi-person-badge"></i> Personnel · <?= e(fdate($date)) ?></span><a class="ms-auto btn btn-sm btn-outline-primary" href="<?= e(url('/ecole/presences/personnel', ['date' => $date])) ?>">Appel du personnel</a></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Nom</th><th>Fonction</th><th>Arrivée</th><th>Départ</th><th>Statut</th></tr></thead><tbody>
    <?php foreach ($jour['personnel'] as $p): ?><tr><td><a href="<?= e(url('/ecole/personnel/' . $p['id'])) ?>"><?= e($p['nom'] . ' ' . $p['prenom']) ?></a></td><td class="small"><?= e((string) ($p['poste'] ?: (Ecole::FONCTIONS[$p['fonction']] ?? $p['fonction']))) ?></td>
      <td><?= $p['arrivee'] ? e(substr($p['arrivee'], 0, 5)) : '' ?></td><td><?= $p['depart'] ? e(substr($p['depart'], 0, 5)) : '' ?></td>
      <td><?php if ($p['statut']): ?><span class="badge <?= $p['statut'] === 'absent' ? 'bg-danger' : ($p['statut'] === 'retard' ? 'bg-warning text-dark' : ($p['statut'] === 'excuse' ? 'bg-secondary' : 'bg-success')) ?>"><?= e(Presences::STATUTS[$p['statut']]) ?></span><?= $p['source'] === 'camera' ? ' <i class="bi bi-camera-video text-secondary" title="Pointage caméra"></i>' : '' ?><?php else: ?><span class="text-secondary small">non pointé</span><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$jour['personnel']): ?><tr><td colspan="5" class="text-secondary">Aucun membre du personnel actif.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
