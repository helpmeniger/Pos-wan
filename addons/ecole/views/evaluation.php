<?php use Addons\Ecole\Notes; $sur = rtrim(rtrim((string) $ev['bareme'], '0'), '.'); $ro = (bool) $ev['cloturee'];
$vals = array_values(array_filter(array_map(fn($x) => $x['note'] !== null ? (float) $x['note'] : null, $eleves), fn($v) => $v !== null)); ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <h2 class="h5 mb-0"><?= e($ev['titre']) ?> <span class="text-secondary">· <?= e($ev['matiere']) ?> · <?= e($ev['classe']) ?> · <?= e($ev['periode']) ?> · <?= e(fdate($ev['date'])) ?> · sur <?= e($sur) ?> · coef. <?= e(rtrim(rtrim((string) $ev['coefficient'], '0'), '.')) ?></span></h2>
  <a class="ms-auto btn btn-sm btn-outline-secondary" href="<?= e(url('/ecole/notes', ['classe' => $ev['classe_id'], 'periode' => $ev['periode_id']])) ?>"><i class="bi bi-arrow-left"></i> Évaluations</a>
</div>
<?php if ($vals): ?><div class="small mb-2">Moyenne <b><?= number_format(array_sum($vals) / count($vals), 2, ',', ' ') ?></b> / <?= e($sur) ?> · plus basse <?= e((string) min($vals)) ?> · plus haute <?= e((string) max($vals)) ?> · <?= count($vals) ?> note(s)</div><?php endif; ?>
<form method="post" action="<?= e(url('/ecole/evaluations/' . $ev['id'])) ?>" class="card" id="ecNotes"><?= csrf_field() ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>Matricule</th><th>Nom et prénom(s)</th><th style="width:140px">Note / <?= e($sur) ?></th><th>Appréciation</th></tr></thead><tbody>
  <?php foreach ($eleves as $i => $el): $v = $el['absent'] ? 'abs' : ($el['note'] !== null ? rtrim(rtrim((string) $el['note'], '0'), '.') : ''); ?>
    <tr><td class="text-secondary small"><?= $i + 1 ?></td><td class="small"><?= e($el['matricule']) ?></td><td><?= e($el['nom'] . ' ' . $el['prenom']) ?></td>
      <td><input name="notes[<?= (int) $el['id'] ?>]" class="form-control form-control-sm text-end ec-note" value="<?= e($v) ?>" inputmode="decimal" data-bareme="<?= e($sur) ?>" autocomplete="off" <?= $ro ? 'readonly' : '' ?>></td>
      <td class="small text-secondary"><?= $el['note'] !== null ? e(Notes::appreciation((float) $el['note'] * 20 / (float) $ev['bareme'])) : ($el['absent'] ? 'Absent(e)' : '') ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$eleves): ?><tr><td colspan="5" class="text-secondary">Aucun inscrit dans cette classe.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if (!$ro && $eleves): ?><div class="card-footer d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer les notes</button>
    <span class="small text-secondary">Entrée / ↓ : élève suivant · ↑ : précédent · « abs » = absent(e) · case vide = non noté(e).</span></div><?php endif; ?>
</form>
<?php if (!$ro && !$vals && !array_filter($eleves, fn($x) => $x['absent'])): ?>
<form method="post" action="<?= e(url('/ecole/evaluations/' . $ev['id'] . '/delete')) ?>" class="mt-3" data-confirm="Supprimer cette évaluation ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Supprimer l'évaluation</button></form>
<?php endif; ?>
