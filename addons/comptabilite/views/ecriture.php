<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; $td = array_sum(array_column($e['lignes'], 'debit')); $tc = array_sum(array_column($e['lignes'], 'credit')); ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <a href="<?= e(url('/compta/ecritures', ['exercice' => $e['exercice_id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <div><div class="fs-5 fw-semibold"><?= e(Compta::pieceNo($e)) ?> <?= $e['statut'] === 'valide' ? '<span class="badge bg-success">validée</span>' : '<span class="badge bg-warning text-dark">brouillard</span>' ?></div>
    <div class="small text-secondary"><?= e($e['journal_code'] . ' — ' . $e['journal_libelle']) ?> · exercice <?= e($e['exercice_code']) ?> · <?= e(fdate($e['date_ecriture'], false)) ?><?= $e['piece'] ? ' · pièce ' . e($e['piece']) : '' ?></div></div>
  <div class="ms-auto d-flex flex-wrap gap-1">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/ecritures/' . $e['id'], ['print' => 1])) ?>"><i class="bi bi-printer"></i> Imprimer</a>
    <?php if (can('compta.saisie')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/saisie', ['copie' => $e['id']])) ?>"><i class="bi bi-copy"></i> Dupliquer</a><?php endif; ?>
    <?php if ($e['statut'] === 'brouillard' && $e['exercice_statut'] === 'ouvert'): ?>
      <?php if (can('compta.saisie')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/compta/saisie', ['id' => $e['id']])) ?>"><i class="bi bi-pencil"></i> Modifier</a><?php endif; ?>
      <?php if (can('compta.valider')): ?><form method="post" action="<?= e(url('/compta/ecritures/' . $e['id'] . '/valider')) ?>" data-confirm="Valider cette écriture ? Elle recevra son numéro définitif et ne pourra plus être modifiée."><?= csrf_field() ?><button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Valider</button></form><?php endif; ?>
      <?php if (can('compta.saisie')): ?><form method="post" action="<?= e(url('/compta/ecritures/' . $e['id'] . '/supprimer')) ?>" data-confirm="Supprimer ce brouillard ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Supprimer</button></form><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<div class="card mb-2"><div class="card-body py-2"><b><?= e($e['libelle']) ?></b>
  <div class="small text-secondary">Saisie par <?= e((string) ($e['auteur'] ?? '—')) ?> le <?= e(fdate($e['created_at'])) ?><?= $e['valide_le'] ? ' · validée par ' . e((string) $e['valideur']) . ' le ' . e(fdate($e['valide_le'])) : '' ?><?= str_starts_with($e['origine'], 'pos_') ? ' · générée depuis la caisse (' . e((string) $e['origine_ref']) . ')' : '' ?></div>
  <?php if ($orig): ?><div class="small mt-1"><i class="bi bi-arrow-return-left"></i> Extourne de <a href="<?= e(url('/compta/ecritures/' . $orig['id'])) ?>"><?= e(Compta::pieceNo($orig)) ?></a></div><?php endif; ?>
  <?php if ($ext): ?><div class="small mt-1 text-danger"><i class="bi bi-arrow-counterclockwise"></i> Extournée par <a href="<?= e(url('/compta/ecritures/' . $ext['id'])) ?>"><?= e(Compta::pieceNo($ext)) ?></a></div><?php endif; ?>
</div></div>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0 small cpt-table">
  <thead><tr><th>Compte</th><th>Tiers</th><th>Libellé</th><th>Échéance</th><th>Lettrage</th><th class="num">Débit</th><th class="num">Crédit</th></tr></thead><tbody>
  <?php foreach ($e['lignes'] as $l): ?><tr>
    <td><a class="cpt-mono" href="<?= e(url('/compta/lettrage', ['compte' => $l['numero'], 'exercice' => $e['exercice_id']])) ?>"><?= e($l['numero']) ?></a> <span class="text-secondary"><?= e($l['compte_libelle']) ?></span></td>
    <td><?= $l['tiers_code'] ? e($l['tiers_code'] . ' — ' . $l['tiers_nom']) : '' ?></td><td><?= e($l['libelle']) ?></td><td><?= $l['echeance'] ? e(fdate($l['echeance'], false)) : '' ?></td>
    <td class="cpt-lettre"><?= e((string) $l['lettrage']) ?></td><td class="num"><?= Compta::num((float) $l['debit']) ?></td><td class="num"><?= Compta::num((float) $l['credit']) ?></td></tr><?php endforeach; ?>
  <tr class="cpt-total"><td colspan="5">Total</td><td class="num"><?= Compta::num($td) ?></td><td class="num"><?= Compta::num($tc) ?></td></tr>
</tbody></table></div></div>
<?php if ($e['statut'] === 'valide' && !$e['extournee_par'] && !$e['extourne_de'] && $e['origine'] !== 'cloture' && can('compta.valider')): ?>
<form method="post" action="<?= e(url('/compta/ecritures/' . $e['id'] . '/extourner')) ?>" class="card" data-confirm="Passer l'écriture inverse (extourne) ? Elle sera validée immédiatement.">
  <div class="card-body"><?= csrf_field() ?><h3 class="h6"><i class="bi bi-arrow-counterclockwise"></i> Corriger par une extourne</h3>
  <p class="small text-secondary">Une écriture validée ne se modifie ni ne se supprime : l'extourne passe la même écriture à l'envers (débits et crédits inversés), puis vous saisissez l'écriture correcte.</p>
  <div class="row g-2 align-items-end"><div class="col-sm-3"><label class="form-label small mb-0">Date de l'extourne</label><input type="date" name="date" class="form-control form-control-sm" value="<?= e($e['exercice_statut'] === 'ouvert' ? $e['date_ecriture'] : date('Y-m-d')) ?>" required></div>
  <div class="col-sm-6"><label class="form-label small mb-0">Motif</label><input name="motif" class="form-control form-control-sm" required minlength="3" maxlength="120" placeholder="Ex. erreur de compte, montant erroné"></div>
  <div class="col-sm-3"><button class="btn btn-sm btn-outline-danger w-100">Extourner</button></div></div></div>
</form>
<?php endif; ?>
