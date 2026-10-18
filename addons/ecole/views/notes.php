<?php use Addons\Ecole\Notes; $annuelPossible = false; $per = array_values(array_filter($periodes, fn($p) => (int) $p['id'] === (int) $periode))[0] ?? null; ?>
<?php if (!$annee || !$classes): ?><div class="alert alert-warning">Créez d'abord l'année scolaire et les classes (<a href="<?= e(url('/ecole/parametres')) ?>">Paramètres</a>, <a href="<?= e(url('/ecole/classes')) ?>">Classes</a>).</div><?php return; endif; ?>
<?php include __DIR__ . '/_choix.php'; ?>
<div class="row g-3">
  <div class="col-lg-4">
    <?php if ($per && !$per['cloturee'] && $matieres): ?>
    <form method="post" action="<?= e(url('/ecole/evaluations')) ?>" class="card"><?= csrf_field() ?><div class="card-header"><i class="bi bi-plus-lg"></i> Nouvelle évaluation · <?= e($per['nom']) ?></div><div class="card-body row g-2 small">
      <input type="hidden" name="classe_id" value="<?= (int) $classe ?>"><input type="hidden" name="periode_id" value="<?= (int) $periode ?>">
      <div class="col-12"><label class="form-label mb-0 required">Matière</label><select name="matiere_id" class="form-select" required><option value="">—</option><?php foreach ($matieres as $m): ?><option value="<?= (int) $m['matiere_id'] ?>"><?= e($m['nom']) ?> (coef. <?= e(Addons\Ecole\Ecole::nb($m['coefficient'])) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Type</label><select name="type" class="form-select"><?php foreach (Notes::TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Date</label><input type="date" name="date" class="form-control" value="<?= e(date('Y-m-d')) ?>"></div>
      <div class="col-12"><label class="form-label mb-0">Intitulé</label><input name="titre" class="form-control" maxlength="100" placeholder="Ex. : Devoir n° 1"></div>
      <div class="col-6"><label class="form-label mb-0">Noté sur</label><input name="bareme" class="form-control" inputmode="decimal" value="20"></div>
      <div class="col-6"><label class="form-label mb-0">Coefficient</label><input name="coefficient" class="form-control" inputmode="decimal" value="1"></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Créer et saisir les notes</button></div>
    </div></form>
    <?php elseif ($per && $per['cloturee']): ?><div class="alert alert-secondary">Période clôturée : les notes sont verrouillées.</div>
    <?php elseif (!$matieres): ?><div class="alert alert-warning">Aucune matière au programme de cette classe : <a href="<?= e(url('/ecole/classes/' . $classe)) ?>">définir le programme</a>.</div><?php endif; ?>
  </div>
  <div class="col-lg-8"><div class="card"><div class="card-header d-flex"><span><i class="bi bi-journal-check"></i> Évaluations</span>
      <?php if (can('ecole.bulletins')): ?><a class="ms-auto small" href="<?= e(url('/ecole/bulletins', ['classe' => $classe, 'periode' => $periode])) ?>">Résultats et bulletins →</a><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Date</th><th>Matière</th><th>Évaluation</th><th class="text-center">Sur</th><th class="text-center">Coef.</th><th class="text-center">Notes</th></tr></thead><tbody>
    <?php foreach ($evaluations as $ev): ?><tr><td class="small"><?= e(fdate($ev['date'])) ?></td><td><?= e($ev['matiere']) ?></td><td><a href="<?= e(url('/ecole/evaluations/' . $ev['id'])) ?>"><?= e($ev['titre']) ?></a> <span class="badge bg-light text-dark"><?= e(Notes::TYPES[$ev['type']] ?? $ev['type']) ?></span></td>
      <td class="text-center"><?= e(Addons\Ecole\Ecole::nb($ev['bareme'])) ?></td><td class="text-center"><?= e(Addons\Ecole\Ecole::nb($ev['coefficient'])) ?></td><td class="text-center"><?= (int) $ev['saisies'] ?></td></tr><?php endforeach; ?>
    <?php if (!$evaluations): ?><tr><td colspan="6" class="text-secondary text-center py-3">Aucune évaluation pour cette période.</td></tr><?php endif; ?>
    </tbody></table></div></div></div>
</div>
