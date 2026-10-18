<?php /** Consentement aux données faciales. @var ?array $e @var bool $eleve */ ?>
<div class="col-12"><div class="border rounded p-2 small bg-body-tertiary">
  <label class="fw-bold"><input type="checkbox" name="consentement_visage" value="1" <?= !empty($e['consentement_visage']) ? 'checked' : '' ?> data-consentement> Consentement aux données faciales (pointage par caméra)</label>
  <div class="text-secondary">La personne<?= $eleve ? ' (ou son parent / tuteur si elle est mineure)' : '' ?> a été informée et accepte que son visage serve à enregistrer automatiquement ses arrivées et départs. Sans ce consentement, aucun visage n'est enregistré ; le retirer efface immédiatement les données faciales.</div>
  <?php if ($eleve): ?><input name="consentement_par" class="form-control form-control-sm mt-1" maxlength="150" placeholder="Consentement donné par (nom et lien : l'élève majeur, son père, sa mère, son tuteur…)" value="<?= e((string) ($e['consentement_par'] ?? '')) ?>"><?php endif; ?>
  <?php if (!empty($e['consentement_date'])): ?><div class="text-secondary mt-1">Enregistré le <?= e(fdate($e['consentement_date'])) ?></div><?php endif; ?>
</div></div>
