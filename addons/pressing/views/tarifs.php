<?php use Addons\Pressing\PressingService as PS; ?>
<form method="post" action="<?= e(url('/pressing/reglages')) ?>" class="card mb-3"><?= csrf_field() ?>
  <div class="card-header"><i class="bi bi-sliders"></i> Réglages du pressing</div>
  <div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label">Express par défaut</label><div class="input-group"><input name="express_pourcent" class="form-control text-end" inputmode="decimal" value="<?= e(PS::qty(PS::expressDefault())) ?>" required><span class="input-group-text">%</span></div></div>
    <div class="col-6 col-md-3"><label class="form-label">Choix proposés au comptoir</label><input name="express_choix" class="form-control" value="<?= e(implode(' ; ', array_map(fn($c) => (string) $c, PS::expressChoices()))) ?>" placeholder="25 ; 50 ; 100"></div>
    <div class="col-6 col-md-2"><label class="form-label">Délai express (j)</label><input name="express_delai" type="number" min="0" max="30" class="form-control" value="<?= (int) setting('pressing_express_delai_jours', '1') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label">Délai normal (j)</label><input name="delai" type="number" min="0" max="60" class="form-control" value="<?= (int) setting('pressing_delai_jours', '3') ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label">Frais de livraison</label><input name="livraison_frais" class="form-control text-end" inputmode="numeric" value="<?= e((string) (float) setting('pressing_livraison_frais', '0')) ?>"></div>
    <div class="col-6 col-md-1"><button class="btn btn-primary w-100" title="Enregistrer les réglages"><i class="bi bi-save"></i></button></div>
    <div class="col-12 form-text">Au comptoir, le caissier choisit l'un des pourcentages proposés quand il coche EXPRESS ; un autre pourcentage est réservé au responsable (droit « Tarifs, remises… »).</div>
  </div>
</form>
<div class="alert alert-light border small">Prix en <?= e(currency()['symbol']) ?> par pièce, <b>par kilo</b> pour un article « Au kilo » ou <b>par mètre carré</b> pour un article « Au m² » (tapis, moquette : le poids ou la surface se saisit au dépôt avec une virgule, ex. 4,5 kg ou 6,25 m²). Laissez un prix vide si le service n'est pas proposé pour cet article. Décochez « Actif » pour retirer un article de la saisie (l'historique est conservé).</div>
<form method="post" action="<?= e(url('/pressing/tarifs')) ?>"><?= csrf_field() ?>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Article</th><th>Catégorie</th><th style="width:130px">Unité</th><?php foreach (PS::SERVICES as $l): ?><th style="width:140px"><?= e($l) ?></th><?php endforeach; ?><th>Actif</th></tr></thead><tbody>
<?php foreach ($tarifs as $a): ?>
  <tr class="<?= $a['active'] ? '' : 'text-secondary' ?>"><td><input name="names[<?= (int) $a['id'] ?>]" value="<?= e($a['name']) ?>" class="form-control form-control-sm" maxlength="100" required></td><td class="small"><?= e($a['category']) ?></td><td><select name="units[<?= (int) $a['id'] ?>]" class="form-select form-select-sm"><?php foreach (PS::UNITS as $uk => $ul): ?><option value="<?= e($uk) ?>" <?= $a['unit'] === $uk ? 'selected' : '' ?>><?= e($ul) ?></option><?php endforeach; ?></select></td>
  <?php foreach (array_keys(PS::SERVICES) as $s): ?><td><input name="prices[<?= (int) $a['id'] ?>][<?= e($s) ?>]" value="<?= isset($a['prices'][$s]) ? e((string) (float) $a['prices'][$s]) : '' ?>" class="form-control form-control-sm text-end" inputmode="numeric"></td><?php endforeach; ?>
  <td><input type="checkbox" class="form-check-input" name="active[<?= (int) $a['id'] ?>]" value="1" <?= $a['active'] ? 'checked' : '' ?>></td></tr>
<?php endforeach; ?>
</tbody></table></div><div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer les tarifs</button></div></div>
</form>
<form method="post" action="<?= e(url('/pressing/tarifs')) ?>" class="card"><div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
  <div class="col-md-4"><label class="form-label">Nouvel article</label><input name="new_name" class="form-control" maxlength="100" required placeholder="ex. Linge de maison, Tapis, Sac, Chaussures…"></div>
  <div class="col-md-2"><label class="form-label">Unité</label><select name="new_unit" class="form-select"><?php foreach (PS::UNITS as $uk => $ul): ?><option value="<?= e($uk) ?>"><?= e($ul) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Catégorie</label><input name="new_category" class="form-control" maxlength="50" list="pCats" value="Vêtements"><datalist id="pCats"><?php foreach (array_unique(array_column($tarifs, 'category')) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
  <div class="col-md-3"><button class="btn btn-outline-primary w-100"><i class="bi bi-plus"></i> Ajouter</button></div>
</div></form>
