<?php use Addons\Hotel\HotelService as HS; $tabs = ['types' => 'Types de chambres et tarifs', 'chambres' => 'Chambres', 'prestations' => 'Prestations', 'reglages' => 'Réglages']; ?>
<ul class="nav nav-tabs mb-3"><?php foreach ($tabs as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/hotel/configuration', ['tab' => $k])) ?>"><?= e($l) ?></a></li><?php endforeach; ?></ul>
<?php if ($tab === 'types'): ?>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Nom</th><th>Code</th><th>Pers.</th><th>Prix / nuit</th><th>Week-end</th><th>Équipements</th><th>Couleur</th><th>Actif</th><th></th></tr></thead><tbody>
<?php foreach (array_merge($types, [null]) as $t): $fid = 'ty' . ($t['id'] ?? 'new'); ?>
  <tr<?= $t ? '' : ' class="table-success"' ?>><form method="post" action="<?= e(url('/hotel/configuration/types')) ?>" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>"></form>
    <td><input form="<?= $fid ?>" name="name" class="form-control form-control-sm" value="<?= e($t['name'] ?? '') ?>" placeholder="<?= $t ? '' : 'Nouveau type…' ?>" required maxlength="80"><?= $t ? '<small class="text-secondary">' . (int) $t['rooms'] . ' chambre(s)</small>' : '' ?></td>
    <td><input form="<?= $fid ?>" name="code" class="form-control form-control-sm" value="<?= e($t['code'] ?? '') ?>" maxlength="10" style="width:5rem"></td>
    <td><input form="<?= $fid ?>" type="number" name="capacity" class="form-control form-control-sm" value="<?= (int) ($t['capacity'] ?? 2) ?>" min="1" max="20" style="width:4.5rem"></td>
    <td><input form="<?= $fid ?>" name="base_price" class="form-control form-control-sm text-end" value="<?= e((string) (float) ($t['base_price'] ?? 0)) ?>" style="width:7rem"></td>
    <td><input form="<?= $fid ?>" name="weekend_price" class="form-control form-control-sm text-end" value="<?= $t && $t['weekend_price'] !== null ? e((string) (float) $t['weekend_price']) : '' ?>" placeholder="idem" style="width:7rem"></td>
    <td><input form="<?= $fid ?>" name="amenities" class="form-control form-control-sm" value="<?= e($t['amenities'] ?? '') ?>" maxlength="255"></td>
    <td><input form="<?= $fid ?>" type="color" name="color" class="form-control form-control-sm form-control-color" value="<?= e($t['color'] ?? '#0d6efd') ?>"></td>
    <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1" class="form-check-input" <?= !$t || (int) $t['active'] ? 'checked' : '' ?>></td>
    <td><button form="<?= $fid ?>" class="btn btn-sm <?= $t ? 'btn-outline-primary' : 'btn-success' ?>"><?= $t ? 'Enregistrer' : 'Ajouter' ?></button></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<p class="small text-secondary mt-2">Le prix « week-end » s'applique aux nuits du vendredi et du samedi. Un changement de prix vaut pour les nuitées portées ensuite ; les tarifs négociés d'un séjour ne changent pas.</p>
<?php elseif ($tab === 'chambres'): ?>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>N°</th><th>Étage / bâtiment</th><th>Type</th><th>Lits</th><th>Remarque</th><th>Ordre</th><th>Active</th><th></th></tr></thead><tbody>
<?php foreach (array_merge($rooms, [null]) as $r): $fid = 'rm' . ($r['id'] ?? 'new'); ?>
  <tr<?= $r ? ((int) $r['active'] ? '' : ' class="text-secondary"') : ' class="table-success"' ?>><form method="post" action="<?= e(url('/hotel/configuration/chambres')) ?>" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>"></form>
    <td><input form="<?= $fid ?>" name="number" class="form-control form-control-sm" value="<?= e($r['number'] ?? '') ?>" placeholder="<?= $r ? '' : 'N°' ?>" required maxlength="20" style="width:6rem"></td>
    <td><input form="<?= $fid ?>" name="floor" class="form-control form-control-sm" value="<?= e($r['floor'] ?? '') ?>" maxlength="30" list="floors"></td>
    <td><select form="<?= $fid ?>" name="type_id" class="form-select form-select-sm"><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($r['type_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></td>
    <td><input form="<?= $fid ?>" name="beds" class="form-control form-control-sm" value="<?= e($r['beds'] ?? '') ?>" maxlength="80"></td>
    <td><input form="<?= $fid ?>" name="note" class="form-control form-control-sm" value="<?= e($r['note'] ?? '') ?>" maxlength="255"></td>
    <td><input form="<?= $fid ?>" type="number" name="sort_order" class="form-control form-control-sm" value="<?= $r ? (int) $r['sort_order'] : '' ?>" style="width:5rem"></td>
    <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1" class="form-check-input" <?= !$r || (int) $r['active'] ? 'checked' : '' ?>></td>
    <td><button form="<?= $fid ?>" class="btn btn-sm <?= $r ? 'btn-outline-primary' : 'btn-success' ?>"><?= $r ? 'Enregistrer' : 'Ajouter' ?></button></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<datalist id="floors"><?php foreach (array_unique(array_column($rooms, 'floor')) as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
<p class="small text-secondary mt-2">Une chambre n'est jamais supprimée (historique des séjours) : décochez « Active » pour la retirer du plan.</p>
<?php elseif ($tab === 'prestations'): ?>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Prestation</th><th>Nature</th><th>Prix</th><th>Icône</th><th>Active</th><th></th></tr></thead><tbody>
<?php foreach (array_merge($extras, [null]) as $x): $fid = 'ex' . ($x['id'] ?? 'new'); ?>
  <tr<?= $x ? '' : ' class="table-success"' ?>><form method="post" action="<?= e(url('/hotel/configuration/prestations')) ?>" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($x['id'] ?? 0) ?>"></form>
    <td><input form="<?= $fid ?>" name="name" class="form-control form-control-sm" value="<?= e($x['name'] ?? '') ?>" placeholder="<?= $x ? '' : 'Nouvelle prestation…' ?>" required maxlength="100"></td>
    <td><select form="<?= $fid ?>" name="kind" class="form-select form-select-sm"><?php foreach (HS::KINDS as $k => $l): if (in_array($k, ['nuitee', 'taxe', 'remise', 'annulation', 'depart_tardif'], true)) continue; ?><option value="<?= e($k) ?>" <?= ($x['kind'] ?? 'service') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
    <td><input form="<?= $fid ?>" name="price" class="form-control form-control-sm text-end" value="<?= e((string) (float) ($x['price'] ?? 0)) ?>" style="width:7rem"></td>
    <td><input form="<?= $fid ?>" name="icon" class="form-control form-control-sm" value="<?= e($x['icon'] ?? 'bi-bag') ?>" style="width:9rem"></td>
    <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1" class="form-check-input" <?= !$x || (int) $x['active'] ? 'checked' : '' ?>></td>
    <td><button form="<?= $fid ?>" class="btn btn-sm <?= $x ? 'btn-outline-primary' : 'btn-success' ?>"><?= $x ? 'Enregistrer' : 'Ajouter' ?></button></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php else: ?>
<form method="post" action="<?= e(url('/hotel/configuration/parametres')) ?>" class="card"><div class="card-body row g-3"><?= csrf_field() ?>
  <div class="col-md-3"><label class="form-label">Heure d'arrivée (check-in)</label><input name="hotel_heure_arrivee" class="form-control" value="<?= e(setting('hotel_heure_arrivee', '14:00')) ?>" placeholder="14:00"></div>
  <div class="col-md-3"><label class="form-label">Heure de départ (check-out)</label><input name="hotel_heure_depart" class="form-control" value="<?= e(setting('hotel_heure_depart', '12:00')) ?>" placeholder="12:00"></div>
  <div class="col-md-3"><label class="form-label">Taxe de séjour (par adulte et par nuit)</label><input name="hotel_taxe_sejour" class="form-control text-end" value="<?= e(setting('hotel_taxe_sejour', '0')) ?>"><div class="form-text">0 = pas de taxe. Montant fixé par votre commune.</div></div>
  <div class="col-md-3"><label class="form-label">Départ tardif (% de la nuitée)</label><input name="hotel_depart_tardif_pourcent" class="form-control text-end" value="<?= e(setting('hotel_depart_tardif_pourcent', '50')) ?>"></div>
  <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="hotel_piece_obligatoire" value="1" id="po" <?= setting('hotel_piece_obligatoire', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="po">Pièce d'identité obligatoire à l'arrivée (registre des voyageurs)</label></div></div>
  <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="hotel_exiger_propre" value="1" id="ep" <?= setting('hotel_exiger_propre', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="ep">Refuser d'installer un client dans une chambre pas encore propre (le responsable peut passer outre)</label></div></div>
  <div class="col-12"><label class="form-label">Conditions imprimées sur la facture</label><textarea name="hotel_conditions" class="form-control" rows="3" maxlength="1000"><?= e(setting('hotel_conditions', '')) ?></textarea></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer les réglages</button></div>
</div></form>
<?php endif; ?>
