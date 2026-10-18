<?php /* Sélecteur classe + période (notes, bulletins). $annuelPossible : ajoute « Année entière ». */ ?>
<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-auto"><label class="form-label small mb-0">Classe</label><select name="classe" class="form-select form-select-sm" data-autosubmit><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === (int) $classe ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><label class="form-label small mb-0">Période</label><select name="periode" class="form-select form-select-sm"><?php foreach ($periodes as $p): ?><option value="<?= (int) $p['id'] ?>" <?= empty($annuel) && (int) $p['id'] === (int) $periode ? 'selected' : '' ?>><?= e($p['nom']) ?><?= $p['cloturee'] ? ' (clôturée)' : '' ?></option><?php endforeach; ?><?php if (!empty($annuelPossible)): ?><option value="annee" <?= !empty($annuel) ? 'selected' : '' ?>>Année entière</option><?php endif; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Afficher</button></div>
</form>
