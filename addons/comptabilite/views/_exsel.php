<?php /** Sélecteur d'exercice (formulaire GET, conserve les autres filtres). */ ?>
<form method="get" class="d-inline-flex align-items-center gap-1">
  <?php foreach ($_GET as $k => $v): if ($k === 'exercice' || $k === 'from' || $k === 'to' || is_array($v)) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
  <label class="small text-secondary" for="exsel">Exercice</label>
  <select id="exsel" name="exercice" class="form-select form-select-sm w-auto" data-autosubmit>
    <?php foreach ($exercices as $x): ?><option value="<?= (int) $x['id'] ?>" <?= (int) $x['id'] === (int) $ex['id'] ? 'selected' : '' ?>><?= e($x['code']) ?><?= $x['statut'] === 'cloture' ? ' (clôturé)' : '' ?></option><?php endforeach; ?>
  </select>
</form>
