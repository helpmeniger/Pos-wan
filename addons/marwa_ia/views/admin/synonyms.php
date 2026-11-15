<?php /** Synonymes ajoutés par l'administrateur. */ $cur = 'synonyms'; require __DIR__ . '/_nav.php'; ?>
<div class="row g-3"><div class="col-lg-5"><form method="post" action="<?= e(url('/ia/admin/synonymes')) ?>" class="card"><?= csrf_field() ?><div class="card-header">Ajouter un synonyme</div><div class="card-body small">
  <p class="text-secondary">Un mot que vos équipes emploient (mot local, abréviation, nom de marque devenu nom commun) et le mot du logiciel auquel il correspond. Exemple : « taxi-moto » → « transport », « bidon » → « huile ».</p>
  <label class="form-label">Mot employé</label><input name="term" class="form-control form-control-sm mb-2" maxlength="80" required placeholder="ex. sachet">
  <label class="form-label">Se comprend comme</label><input name="canonical" class="form-control form-control-sm mb-2" maxlength="80" required placeholder="ex. paquet">
  <label class="form-label">Note (facultatif)</label><input name="note" class="form-control form-control-sm" maxlength="255">
</div><div class="card-footer"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Ajouter</button></div></form>
<p class="small text-secondary mt-2">L'assistant connaît déjà <?= (int) $builtin ?> synonymes et racines du métier et <?= (int) $abbr ?> abréviations (cb, stp, pk, qté, mdp…), et il corrige les fautes de frappe : inutile de les saisir ici.</p></div>
<div class="col-lg-7"><div class="card"><div class="card-header">Synonymes ajoutés (<?= count($rows) ?>)</div>
  <?php if (!$rows): ?><div class="card-body small text-secondary">Aucun pour l'instant.</div><?php else: ?><div class="table-responsive"><table class="table table-sm small mb-0"><thead><tr><th>Mot</th><th>Compris comme</th><th>Note</th><th>Ajouté par</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= e($r['term']) ?></td><td><?= e($r['canonical']) ?></td><td><?= e((string) $r['note']) ?></td><td><?= e((string) $r['full_name']) ?><div class="text-secondary"><?= e(fdate($r['created_at'])) ?></div></td>
    <td><form method="post" action="<?= e(url('/ia/admin/synonymes/' . (int) $r['id'] . '/supprimer')) ?>" data-confirm="Supprimer ce synonyme ?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></div></div></div>
