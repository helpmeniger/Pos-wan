<?php /* Rédaction d'une ordonnance : médicaments du stock (liste) ou hors stock (texte libre). $action = URL d'envoi, $drugs */ ?>
<form method="post" action="<?= e($action) ?>" data-presc-form><?= csrf_field() ?>
  <datalist id="clinDrugList"><?php foreach ($drugs as $d): ?><option value="<?= e($d['name'] . ($d['dosage'] ? ' ' . $d['dosage'] : '')) ?>" data-id="<?= (int) $d['id'] ?>" label="<?= e(t('Stock')) ?> : <?= e(qty($d['stock'])) ?>"></option><?php endforeach; ?></datalist>
  <div class="table-responsive"><table class="table table-sm align-middle mb-1"><thead><tr><th style="min-width:220px">Médicament</th><th style="min-width:180px">Posologie</th><th style="width:110px">Durée</th><th style="width:80px">Qté</th><th></th></tr></thead>
  <tbody data-presc-lines>
    <tr data-presc-line>
      <td><input class="form-control form-control-sm" list="clinDrugList" data-drug placeholder="Rechercher dans le stock ou saisir" autocomplete="off"><input type="hidden" data-pid><input type="hidden" data-dname><small class="text-secondary" data-drug-info></small></td>
      <td><input class="form-control form-control-sm" data-posology placeholder="ex. 1 cp matin et soir" maxlength="255"></td>
      <td><input class="form-control form-control-sm" data-duration placeholder="5 jours" maxlength="50"></td>
      <td><input class="form-control form-control-sm" data-qty value="1" inputmode="numeric"></td>
      <td><button type="button" class="btn btn-sm btn-outline-danger" data-del-line title="Retirer"><i class="bi bi-x"></i></button></td>
    </tr>
  </tbody></table></div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-add-line><i class="bi bi-plus"></i> Ligne</button>
    <input name="presc_note" class="form-control form-control-sm" style="max-width:320px" placeholder="Note pour le pharmacien (facultatif)" maxlength="255">
    <button class="btn btn-sm btn-success ms-auto"><i class="bi bi-send"></i> Enregistrer et transmettre à la pharmacie</button>
  </div>
  <div class="small text-secondary mt-1">Les médicaments choisis dans la liste sont délivrés par la pharmacie (stock, caisse). Un médicament saisi librement figure sur l'ordonnance imprimée mais n'est pas délivré ici.</div>
</form>
