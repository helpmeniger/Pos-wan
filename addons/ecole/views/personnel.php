<?php use Addons\Ecole\Ecole; use Addons\Ecole\Presences; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card"><div class="card-body text-center">
    <?php if ($e['photo']): ?><img class="ec-photo-big" src="<?= e(url('/ecole/photo/personnel/' . $e['id'])) ?>" alt=""><?php else: ?><div class="ec-photo-big ec-mini-empty"><i class="bi bi-person"></i></div><?php endif; ?>
    <h2 class="h5 mt-2 mb-0"><?= e($e['prenom'] . ' ' . $e['nom']) ?></h2>
    <div class="text-secondary small"><?= e($e['matricule']) ?> · <?= e(Ecole::FONCTIONS[$e['fonction']]) ?><?= $e['poste'] ? ' · ' . e($e['poste']) : '' ?></div>
    <div class="d-flex flex-wrap gap-1 justify-content-center mt-2">
      <?php if (can('ecole.manage')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/ecole/personnel/' . $e['id'] . '/modifier')) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/personnel/' . $e['id'] . '/carte')) ?>"><i class="bi bi-person-vcard"></i> Carte</a></div>
  </div><ul class="list-group list-group-flush small">
    <li class="list-group-item"><i class="bi bi-telephone"></i> <?= e((string) $e['telephone']) ?> <?= e((string) $e['email']) ?></li>
    <?php if ($e['date_embauche']): ?><li class="list-group-item"><i class="bi bi-calendar-check"></i> Embauché(e) le <?= e(fdate($e['date_embauche'])) ?></li><?php endif; ?>
    <?php if (can('ecole.finance') && $e['salaire'] !== null): ?><li class="list-group-item"><i class="bi bi-wallet2"></i> Salaire mensuel : <b><?= money($e['salaire']) ?></b></li><?php endif; ?>
    <li class="list-group-item"><i class="bi bi-person-bounding-box"></i> Visage pour le pointage : <b><?= (int) $visages ?></b> · consentement <?= $e['consentement_visage'] ? 'enregistré le ' . e(fdate((string) $e['consentement_date'])) : '<b>non donné</b>' ?>
      <?php if ($visages && can('ecole.biometrie')): ?><form method="post" action="<?= e(url('/ecole/visages/supprimer')) ?>" class="mt-1" data-confirm="Effacer les données faciales ?"><?= csrf_field() ?><input type="hidden" name="type" value="personnel"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><button class="btn btn-sm btn-outline-danger">Effacer les données faciales</button></form><?php endif; ?></li>
  </ul></div></div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-book"></i> Cours (année en cours)</div><ul class="list-group list-group-flush small"><?php foreach ($cours as $c): ?><li class="list-group-item"><?= e($c['classe']) ?> · <?= e($c['matiere']) ?> <span class="text-secondary">(coef. <?= e(Addons\Ecole\Ecole::nb($c['coefficient'])) ?>)</span></li><?php endforeach; ?><?php if (!$cours): ?><li class="list-group-item text-secondary">Aucun cours attribué.</li><?php endif; ?></ul></div>
    <div class="row g-3">
      <?php if (can('ecole.finance')): ?><div class="col-md-6"><div class="card"><div class="card-header"><i class="bi bi-wallet2"></i> Salaires versés</div><ul class="list-group list-group-flush small"><?php foreach ($salaires as $s): ?><li class="list-group-item d-flex <?= $s['annule'] ? 'text-secondary text-decoration-line-through' : '' ?>"><a href="<?= e(url('/ecole/salaires/' . $s['id'])) ?>"><?= e($s['periode']) ?></a><span class="ms-auto"><?= money($s['montant']) ?></span></li><?php endforeach; ?><?php if (!$salaires): ?><li class="list-group-item text-secondary">Aucun versement.</li><?php endif; ?></ul></div></div><?php endif; ?>
      <div class="col-md-6"><div class="card"><div class="card-header"><i class="bi bi-check2-square"></i> Présences récentes</div><ul class="list-group list-group-flush small"><?php foreach ($presences as $pr): ?><li class="list-group-item d-flex"><span><?= e(fdate($pr['date'])) ?></span><span class="ms-2 badge <?= $pr['statut'] === 'absent' ? 'bg-danger' : ($pr['statut'] === 'retard' ? 'bg-warning text-dark' : 'bg-success') ?>"><?= e(Presences::STATUTS[$pr['statut']]) ?></span><span class="ms-auto text-secondary"><?= $pr['arrivee'] ? e(substr($pr['arrivee'], 0, 5)) : '' ?><?= $pr['depart'] ? ' – ' . e(substr($pr['depart'], 0, 5)) : '' ?></span></li><?php endforeach; ?><?php if (!$presences): ?><li class="list-group-item text-secondary">Aucune présence enregistrée.</li><?php endif; ?></ul></div></div>
    </div>
  </div>
</div>
