<?php use Addons\Ecole\Ecole; use Addons\Ecole\Finance; use Addons\Ecole\Presences; $modes = payment_methods(); ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body text-center">
      <?php if ($e['photo']): ?><img class="ec-photo-big" src="<?= e(url('/ecole/photo/eleve/' . $e['id'])) ?>" alt=""><?php else: ?><div class="ec-photo-big ec-mini-empty"><i class="bi bi-person"></i></div><?php endif; ?>
      <h2 class="h5 mt-2 mb-0"><?= e($e['nom'] . ' ' . $e['prenom']) ?></h2>
      <div class="text-secondary small"><?= e($e['matricule']) ?> · <?= $e['sexe'] === 'F' ? 'Féminin' : 'Masculin' ?><?= $e['date_naissance'] ? ' · né(e) le ' . e(fdate($e['date_naissance'])) . ($e['lieu_naissance'] ? ' à ' . e($e['lieu_naissance']) : '') : '' ?></div>
      <div class="mt-1"><?= $cur ? '<span class="badge bg-primary">' . e($cur['classe']) . '</span>' : '<span class="badge bg-secondary">non inscrit(e) cette année</span>' ?> <?= $e['statut'] !== 'actif' ? '<span class="badge bg-dark">' . e($e['statut']) . '</span>' : '' ?></div>
      <div class="d-flex flex-wrap gap-1 justify-content-center mt-2">
        <?php if (can('ecole.manage')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/ecole/eleves/' . $e['id'] . '/modifier')) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/eleves/' . $e['id'] . '/carte')) ?>"><i class="bi bi-person-vcard"></i> Carte</a>
        <?php if (can('ecole.caisse')): ?><a class="btn btn-sm btn-success" href="<?= e(url('/ecole/caisse', ['eleve' => $e['id']])) ?>"><i class="bi bi-cash-coin"></i> Caisse</a><?php endif; ?>
      </div>
    </div>
    <ul class="list-group list-group-flush small">
      <li class="list-group-item"><i class="bi bi-person-hearts"></i> <?= e((string) $e['tuteur_nom']) ?> <?= $e['tuteur_lien'] ? '(' . e($e['tuteur_lien']) . ')' : '' ?><br><?php foreach (array_filter([$e['tuteur_tel'], $e['tuteur_tel2']]) as $t): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $t)) ?>"><?= e($t) ?></a> <?php endforeach; ?></li>
      <?php if ($e['adresse'] || $e['telephone']): ?><li class="list-group-item"><i class="bi bi-geo-alt"></i> <?= e((string) $e['adresse']) ?> <?= e((string) $e['telephone']) ?></li><?php endif; ?>
      <?php if ($e['infos_medicales']): ?><li class="list-group-item text-danger"><i class="bi bi-heart-pulse"></i> <?= nl2br(e($e['infos_medicales'])) ?></li><?php endif; ?>
      <li class="list-group-item"><i class="bi bi-person-bounding-box"></i> Visage pour le pointage : <b><?= (int) $visages ?></b> enregistré(s) · consentement <?= $e['consentement_visage'] ? 'donné par ' . e((string) $e['consentement_par']) . ' le ' . e(fdate((string) $e['consentement_date'])) : '<b>non donné</b>' ?>
        <?php if ($visages && can('ecole.biometrie')): ?><form method="post" action="<?= e(url('/ecole/visages/supprimer')) ?>" class="mt-1" data-confirm="Effacer les données faciales de cette personne ?"><?= csrf_field() ?><input type="hidden" name="type" value="eleve"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><button class="btn btn-sm btn-outline-danger">Effacer les données faciales</button></form><?php endif; ?></li>
    </ul></div>
    <?php if (!$cur && can('ecole.caisse') && $classes && $e['statut'] === 'actif'): ?>
    <form method="post" action="<?= e(url('/ecole/eleves/' . $e['id'] . '/inscrire')) ?>" class="card mt-3"><?= csrf_field() ?><div class="card-header"><i class="bi bi-journal-plus"></i> Inscrire pour l'année en cours</div><div class="card-body row g-2 small">
      <div class="col-12"><select name="classe_id" class="form-select" required><option value="">Classe…</option><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><select name="type" class="form-select"><?php foreach (Finance::TYPES_INSCRIPTION as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><input name="remise" class="form-control" placeholder="Remise" inputmode="numeric"></div>
      <div class="col-12"><button class="btn btn-sm btn-primary">Inscrire</button></div></div></form>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <?php if ($cur): ?>
    <div class="card mb-3"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><i class="bi bi-cash-stack"></i> Frais <?= e($cur['annee']) ?> — <?= e($cur['classe']) ?>
      <span class="ms-auto small">Dû <b><?= money($solde['du']) ?></b> · payé <b class="text-success"><?= money($solde['paye']) ?></b> · reste <b class="<?= $solde['echu'] > 0 ? 'text-danger' : '' ?>"><?= money($solde['reste']) ?></b></span></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Échéance</th><th>Date limite</th><th class="text-end">Montant</th><th class="text-end">Payé</th><th class="text-end">Reste</th></tr></thead><tbody>
      <?php foreach ($echeances as $x): ?><tr class="<?= (float) $x['reste'] > 0 && $x['date_limite'] && $x['date_limite'] <= date('Y-m-d') ? 'table-danger' : '' ?>"><td><?= e($x['libelle']) ?></td><td class="small"><?= $x['date_limite'] ? e(fdate($x['date_limite'])) : '' ?></td><td class="text-end"><?= money($x['montant']) ?></td><td class="text-end"><?= money($x['paye']) ?></td><td class="text-end fw-bold"><?= money($x['reste']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if (can('ecole.caisse') || can('ecole.manage')): ?><div class="card-footer d-flex flex-wrap gap-2 small">
        <?php if (can('ecole.caisse')): ?><form method="post" action="<?= e(url('/ecole/inscriptions/' . $cur['id'] . '/frais')) ?>" class="d-flex gap-1"><?= csrf_field() ?><input name="libelle" class="form-control form-control-sm" placeholder="Frais (cantine, tenue…)" required maxlength="100"><input name="montant" class="form-control form-control-sm" style="width:100px" placeholder="Montant" required inputmode="numeric"><button class="btn btn-sm btn-outline-primary">Ajouter</button></form><?php endif; ?>
        <?php if (can('ecole.manage')): ?>
        <form method="post" action="<?= e(url('/ecole/inscriptions/' . $cur['id'] . '/classe')) ?>" class="d-flex gap-1"><?= csrf_field() ?><select name="classe_id" class="form-select form-select-sm"><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $cur['classe_id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-secondary">Changer de classe</button></form>
        <?php if ($cur['statut'] === 'active'): ?><form method="post" action="<?= e(url('/ecole/inscriptions/' . $cur['id'] . '/abandon')) ?>" data-confirm="Marquer le départ ? Les montants non encore payés ne seront plus dus."><?= csrf_field() ?><input type="hidden" name="statut" value="abandon"><button class="btn btn-sm btn-outline-danger">Abandon / départ</button></form><?php endif; ?>
        <?php endif; ?>
      </div><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-receipt"></i> Reçus</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
      <?php foreach ($paiements as $p): ?><tr class="<?= $p['annule'] ? 'text-secondary' : '' ?>"><td><a href="<?= e(url('/ecole/recus/' . $p['id'])) ?>"><?= e($p['numero']) ?></a></td><td class="small"><?= e(fdate($p['created_at'])) ?></td><td class="small"><?= e($modes[$p['mode']] ?? $p['mode']) ?></td>
        <td class="text-end <?= $p['annule'] ? 'text-decoration-line-through' : 'fw-bold' ?>"><?= money($p['montant']) ?></td>
        <td class="text-end"><?php if ($p['annule']): ?><span class="badge bg-secondary" title="<?= e((string) $p['motif_annulation']) ?>">annulé</span><?php elseif (can('ecole.finance')): ?><form method="post" action="<?= e(url('/ecole/recus/' . $p['id'] . '/annuler')) ?>" data-confirm="Annuler le reçu <?= e($p['numero']) ?> ? Les montants redeviennent dus." data-reason="1"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Annuler</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$paiements): ?><tr><td class="text-secondary">Aucun reçu.</td></tr><?php endif; ?>
    </tbody></table></div></div>

    <div class="row g-3">
      <?php if ($notes): ?><div class="col-md-6"><div class="card"><div class="card-header"><i class="bi bi-mortarboard"></i> Résultats</div><ul class="list-group list-group-flush small">
        <?php foreach ($notes as $n): ?><li class="list-group-item d-flex"><span><?= e($n['periode']['nom']) ?></span><span class="ms-auto"><?= $n['moyenne'] !== null ? '<b>' . number_format($n['moyenne'], 2, ',', ' ') . '</b> / 20 · rang ' . (int) $n['rang'] . ' / ' . (int) $n['effectif'] : '—' ?></span>
          <?php if ($n['moyenne'] !== null): ?><a class="ms-2" href="<?= e(url('/ecole/bulletins/imprimer', ['classe' => $cur['classe_id'], 'periode' => $n['periode']['id'], 'eleve' => $e['id']])) ?>" title="Bulletin"><i class="bi bi-printer"></i></a><?php endif; ?></li><?php endforeach; ?>
      </ul></div></div><?php endif; ?>
      <div class="col-md-6"><div class="card"><div class="card-header"><i class="bi bi-check2-square"></i> Présences récentes</div><ul class="list-group list-group-flush small">
        <?php foreach ($presences as $pr): ?><li class="list-group-item d-flex"><span><?= e(fdate($pr['date'])) ?></span><span class="ms-2 badge <?= $pr['statut'] === 'absent' ? 'bg-danger' : ($pr['statut'] === 'retard' ? 'bg-warning text-dark' : 'bg-success') ?>"><?= e(Presences::STATUTS[$pr['statut']]) ?></span><span class="ms-auto text-secondary"><?= $pr['arrivee'] ? 'arrivée ' . e(substr($pr['arrivee'], 0, 5)) : '' ?><?= $pr['depart'] ? ' · départ ' . e(substr($pr['depart'], 0, 5)) : '' ?><?= $pr['source'] === 'camera' ? ' <i class="bi bi-camera-video" title="Pointage caméra"></i>' : '' ?></span></li><?php endforeach; ?>
        <?php if (!$presences): ?><li class="list-group-item text-secondary">Aucune présence enregistrée.</li><?php endif; ?>
      </ul></div></div>
    </div>
  </div>
</div>
