<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <div><div class="fs-5 fw-semibold"><?= e($ex['libelle']) ?> <?= $ex['statut'] === 'cloture' ? '<span class="badge bg-secondary">clôturé</span>' : '<span class="badge bg-success">ouvert</span>' ?></div>
  <div class="small text-secondary">Du <?= e(fdate($ex['date_debut'], false)) ?> au <?= e(fdate($ex['date_fin'], false)) ?><?= $ex['verrou_au'] ? ' · période verrouillée jusqu\'au ' . e(fdate($ex['verrou_au'], false)) : '' ?> · référentiel <?= Compta::referentiel() === 'pcg' ? 'PCG français' : 'SYSCOHADA révisé' ?></div></div>
  <div class="ms-auto"><?php require __DIR__ . '/_exsel.php'; ?></div>
</div>
<?php if (!$d['equilibre']): ?><div class="alert alert-danger"><i class="bi bi-exclamation-octagon"></i> Total des débits différent du total des crédits sur l'exercice : contrôlez la balance.</div><?php endif; ?>
<?php if ($d['brouillard']): ?><div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-hourglass-split"></i> <span><?= $d['brouillard'] ?> écriture(s) au brouillard (provisoires, non numérotées).</span>
  <a class="btn btn-sm btn-outline-dark ms-auto" href="<?= e(url('/compta/ecritures', ['exercice' => $ex['id'], 'statut' => 'brouillard'])) ?>">Contrôler et valider</a></div><?php endif; ?>
<?php if ($pendingDays): ?><div class="alert alert-info d-flex align-items-center gap-2"><i class="bi bi-magic"></i> <span><?= $pendingDays ?> journée(s) d'activité de la caisse (ventes, achats, dépenses, banques…) pas encore passées en comptabilité.</span>
  <a class="btn btn-sm btn-outline-dark ms-auto" href="<?= e(url('/compta/generation')) ?>">Générer les écritures</a></div><?php endif; ?>
<div class="row g-2 mb-3 cpt-kpi">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-secondary">Chiffre d'affaires (70)</div><div class="v"><?= money($d['ca']) ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-secondary">Charges (classe 6)</div><div class="v"><?= money($d['charges']) ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-secondary">Résultat provisoire</div><div class="v <?= $d['resultat'] < 0 ? 'text-danger' : 'text-success' ?>"><?= money($d['resultat']) ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-secondary">Écritures validées / brouillard</div><div class="v"><?= $d['valides'] ?> / <?= $d['brouillard'] ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-secondary">Clients à encaisser (41)</div><div class="v"><?= money($d['clients']) ?></div></div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-secondary">Fournisseurs à payer (40)</div><div class="v"><?= money($d['fournisseurs']) ?></div></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body"><div class="small text-secondary mb-1">Trésorerie (classe 5)</div>
    <?php foreach ($d['tresorerie'] as $t): ?><div class="d-flex small"><a class="text-decoration-none" href="<?= e(url('/compta/lettrage', ['compte' => $t['numero'], 'exercice' => $ex['id']])) ?>"><span class="cpt-mono"><?= e($t['numero']) ?></span> <?= e($t['libelle']) ?></a><span class="ms-auto fw-semibold <?= $t['solde'] < 0 ? 'text-danger' : '' ?>"><?= money($t['solde']) ?></span></div><?php endforeach; ?>
    <?php if (!$d['tresorerie']): ?><div class="small text-secondary">Aucun mouvement de trésorerie.</div><?php endif; ?>
  </div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-header d-flex"><span>Dernières écritures</span><a class="ms-auto small" href="<?= e(url('/compta/ecritures', ['exercice' => $ex['id']])) ?>">Toutes</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0 small cpt-table"><tbody>
    <?php foreach ($d['recentes'] as $r): ?><tr><td><?= e(fdate($r['date_ecriture'], false)) ?></td><td><a href="<?= e(url('/compta/ecritures/' . $r['id'])) ?>"><?= e(Compta::pieceNo($r)) ?></a></td><td><?= e($r['libelle']) ?></td><td class="num"><?= Compta::num((float) $r['total']) ?></td>
      <td><?= $r['statut'] === 'valide' ? '<span class="badge bg-success-subtle text-success-emphasis">validée</span>' : '<span class="badge bg-warning-subtle text-warning-emphasis">brouillard</span>' ?></td></tr><?php endforeach; ?>
    <?php if (!$d['recentes']): ?><tr><td class="text-center text-secondary py-4">Aucune écriture. Commencez par la <a href="<?= e(url('/compta/saisie')) ?>">saisie</a><?= can('compta.valider') ? ' ou les <a href="' . e(url('/compta/generation')) . '">écritures de la caisse</a>' : '' ?>.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Accès rapides</div><div class="list-group list-group-flush small">
    <?php foreach ([['/compta/saisie', 'bi-pencil-square', 'Saisir une écriture', 'compta.saisie'], ['/compta/etats/balance', 'bi-table', 'Balance générale', 'compta.view'], ['/compta/etats/grand-livre', 'bi-book', 'Grand livre', 'compta.view'],
        ['/compta/etats/bilan', 'bi-columns-gap', 'Bilan', 'compta.view'], ['/compta/etats/resultat', 'bi-graph-up-arrow', 'Compte de résultat', 'compta.view'], ['/compta/lettrage', 'bi-check2-square', 'Lettrer un compte client ou fournisseur', 'compta.view'],
        ['/compta/exercices', 'bi-lock', 'Verrouiller une période, clôturer l\'exercice', 'compta.admin']] as [$u, $i, $l, $p]): if (!can($p)) continue; ?>
      <a class="list-group-item list-group-item-action" href="<?= e(url($u, str_starts_with($u, '/compta/etats') ? ['exercice' => $ex['id']] : [])) ?>"><i class="bi <?= e($i) ?> me-2"></i><?= e($l) ?></a>
    <?php endforeach; ?>
  </div></div></div>
</div>
