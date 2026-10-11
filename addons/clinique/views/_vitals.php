<?php /* Saisie des constantes : champs vitals[...] (contrôlés côté serveur). */ ?>
<div class="row g-1 clin-vitals">
  <div class="col-4 col-md-3"><label class="form-label small mb-0">T° (°C)</label><input name="vitals[temperature]" class="form-control form-control-sm" inputmode="decimal"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">TA systolique</label><input name="vitals[systolic]" class="form-control form-control-sm" inputmode="numeric"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">TA diastolique</label><input name="vitals[diastolic]" class="form-control form-control-sm" inputmode="numeric"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">Pouls (/min)</label><input name="vitals[pulse]" class="form-control form-control-sm" inputmode="numeric"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">SpO₂ (%)</label><input name="vitals[spo2]" class="form-control form-control-sm" inputmode="numeric"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">Fréq. resp.</label><input name="vitals[resp_rate]" class="form-control form-control-sm" inputmode="numeric"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">Glycémie (g/L)</label><input name="vitals[glycemia]" class="form-control form-control-sm" inputmode="decimal"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">Douleur (0-10)</label><input name="vitals[pain]" class="form-control form-control-sm" inputmode="numeric"></div>
  <?php if (!empty($full)): ?>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">Poids (kg)</label><input name="vitals[weight]" class="form-control form-control-sm" inputmode="decimal"></div>
  <div class="col-4 col-md-3"><label class="form-label small mb-0">Taille (cm)</label><input name="vitals[height]" class="form-control form-control-sm" inputmode="numeric"></div>
  <?php endif; ?>
  <?php if (!empty($withNote)): ?><div class="col-12 col-md-6"><label class="form-label small mb-0">Observation</label><input name="vitals[note]" class="form-control form-control-sm" maxlength="255"></div><?php endif; ?>
</div>
