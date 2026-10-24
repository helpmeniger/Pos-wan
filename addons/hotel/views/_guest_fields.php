<?php
/* Champs de la fiche client (préfixe g_). $g = fiche existante ou null ; $full = tous les champs (fiche de police). */
use Addons\Hotel\HotelService as HS;
$g = $g ?? [];
$v = fn(string $k) => e((string) ($g[$k] ?? ''));
$full = $full ?? true;
?>
<input type="hidden" name="g_form" value="1">
<div class="row g-2">
  <div class="col-md-5"><label class="form-label small mb-0">Nom *</label><input name="g_last_name" class="form-control" value="<?= $v('last_name') ?>" maxlength="80" data-g="last_name" autocomplete="off"></div>
  <div class="col-md-5"><label class="form-label small mb-0">Prénom(s)</label><input name="g_first_name" class="form-control" value="<?= $v('first_name') ?>" maxlength="80" data-g="first_name"></div>
  <div class="col-md-2"><label class="form-label small mb-0">Sexe</label><select name="g_sex" class="form-select" data-g="sex"><option value=""></option><option value="M" <?= ($g['sex'] ?? '') === 'M' ? 'selected' : '' ?>>M</option><option value="F" <?= ($g['sex'] ?? '') === 'F' ? 'selected' : '' ?>>F</option></select></div>
  <div class="col-md-4"><label class="form-label small mb-0">Téléphone</label><input name="g_phone" class="form-control" value="<?= $v('phone') ?>" maxlength="50" inputmode="tel" data-g="phone"></div>
  <div class="col-md-4"><label class="form-label small mb-0">E-mail</label><input name="g_email" type="email" class="form-control" value="<?= $v('email') ?>" maxlength="150" data-g="email"></div>
  <div class="col-md-4"><label class="form-label small mb-0">Nationalité</label><input name="g_nationality" class="form-control" value="<?= $v('nationality') ?: (!$g ? 'Nigérienne' : '') ?>" maxlength="60" data-g="nationality" list="hotelNat"></div>
  <?php if ($full): ?>
  <div class="col-md-3"><label class="form-label small mb-0">Pièce d'identité<?= setting('hotel_piece_obligatoire', '1') === '1' ? ' *' : '' ?></label><select name="g_id_type" class="form-select" data-g="id_type"><option value=""></option><?php foreach (HS::ID_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($g['id_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0">N° de la pièce</label><input name="g_id_number" class="form-control" value="<?= $v('id_number') ?>" maxlength="60" data-g="id_number"></div>
  <div class="col-md-3"><label class="form-label small mb-0">Délivrée le</label><input type="date" name="g_id_issued_on" class="form-control" value="<?= $v('id_issued_on') ?>" data-g="id_issued_on"></div>
  <div class="col-md-3"><label class="form-label small mb-0">à</label><input name="g_id_issued_at" class="form-control" value="<?= $v('id_issued_at') ?>" maxlength="100" data-g="id_issued_at"></div>
  <div class="col-md-3"><label class="form-label small mb-0">Né(e) le</label><input type="date" name="g_birth_date" class="form-control" value="<?= $v('birth_date') ?>" data-g="birth_date"></div>
  <div class="col-md-3"><label class="form-label small mb-0">à</label><input name="g_birth_place" class="form-control" value="<?= $v('birth_place') ?>" maxlength="100" data-g="birth_place"></div>
  <div class="col-md-6"><label class="form-label small mb-0">Profession</label><input name="g_profession" class="form-control" value="<?= $v('profession') ?>" maxlength="100" data-g="profession"></div>
  <div class="col-md-6"><label class="form-label small mb-0">Domicile (adresse)</label><input name="g_address" class="form-control" value="<?= $v('address') ?>" maxlength="255" data-g="address"></div>
  <div class="col-md-6"><label class="form-label small mb-0">Entreprise / organisme</label><input name="g_company" class="form-control" value="<?= $v('company') ?>" maxlength="150" data-g="company"></div>
  <div class="col-md-9"><label class="form-label small mb-0">Remarque (préférences, allergies…)</label><input name="g_note" class="form-control" value="<?= $v('note') ?>" maxlength="255" data-g="note"></div>
  <div class="col-md-3 d-flex align-items-end gap-3 pb-1">
    <label class="form-check-label small"><input type="checkbox" class="form-check-input" name="g_vip" value="1" <?= !empty($g['vip']) ? 'checked' : '' ?> data-g="vip"> VIP</label>
    <?php if (can('hotel.manage')): ?><label class="form-check-label small text-danger"><input type="checkbox" class="form-check-input" name="g_blacklisted" value="1" <?= !empty($g['blacklisted']) ? 'checked' : '' ?> data-g="blacklisted"> Refusé</label><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<datalist id="hotelNat"><?php foreach (['Nigérienne', 'Nigériane', 'Malienne', 'Burkinabè', 'Béninoise', 'Togolaise', 'Ivoirienne', 'Sénégalaise', 'Tchadienne', 'Algérienne', 'Libyenne', 'Camerounaise', 'Ghanéenne', 'Française', 'Américaine', 'Marocaine', 'Chinoise', 'Turque'] as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
