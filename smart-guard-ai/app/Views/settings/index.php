<?php $groups = []; foreach ($settings as $s) { $groups[explode('.', $s['setting_key'])[0]][] = $s; }
$names = ['recon' => 'Rapprochement avec la caisse', 'alerts' => 'Niveaux d\'alerte', 'retention' => 'Conservation des données', 'storage' => 'Stockage', 'ai' => 'Reconnaissance IA', 'privacy' => 'Vie privée', 'reports' => 'Rapports']; ?>
<form method="post" action="<?= url('settings') ?>"><?= csrf_field() ?>
<div class="row g-3">
<?php foreach ($groups as $g => $rows): ?>
<div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white"><?= e($names[$g] ?? $g) ?></div><div class="card-body">
<?php foreach ($rows as $s): $k = $s['setting_key']; ?>
  <div class="mb-3"><label class="form-label small"><?= e($s['description']) ?></label>
  <?php if ($s['value_type'] === 'bool'): ?>
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="s[<?= e($k) ?>]" value="1" <?= $s['setting_value'] === '1' ? 'checked' : '' ?>></div>
  <?php elseif (str_ends_with($k, '_level')): ?>
    <select name="s[<?= e($k) ?>]" class="form-select form-select-sm"><?php foreach (['information', 'a_verifier', 'prioritaire'] as $l): ?><option value="<?= $l ?>" <?= $s['setting_value'] === $l ? 'selected' : '' ?>><?= e(label('level', $l)) ?></option><?php endforeach; ?></select>
  <?php elseif ($s['value_type'] === 'string' && mb_strlen($s['setting_value']) > 60): ?>
    <textarea name="s[<?= e($k) ?>]" class="form-control form-control-sm" rows="3" maxlength="1000"><?= e($s['setting_value']) ?></textarea>
  <?php else: ?>
    <input name="s[<?= e($k) ?>]" class="form-control form-control-sm" value="<?= e($s['setting_value']) ?>" <?= in_array($s['value_type'], ['int', 'float'], true) ? 'inputmode="decimal"' : '' ?>>
  <?php endif; ?><div class="form-text"><code><?= e($k) ?></code></div></div>
<?php endforeach; ?>
</div></div></div>
<?php endforeach; ?>
</div>
<button class="btn btn-primary mt-3">Enregistrer les paramètres</button>
</form>
<div class="alert alert-light border small mt-3"><strong>Vie privée :</strong> affichez à l'entrée du magasin un panneau d'information sur la vidéosurveillance (texte ci-dessus), limitez les durées de conservation au nécessaire et respectez les obligations légales applicables au Niger. Le système n'utilise aucune reconnaissance faciale et n'identifie pas les personnes.</div>
