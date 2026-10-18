<?php use Addons\Ecole\Presences; ?>
<script type="application/json" id="ecole-pointage-data"><?= json_encode(['points' => array_map(fn($p) => ['id' => (int) $p['id'], 'nom' => $p['nom'], 'role' => $p['role'], 'ip' => $p['camera_kind'] === 'ip', 'device' => (string) ($p['device_label'] ?: $p['camera_device']), 'camera' => $p['camera'], 'fps' => $p['fps'] ? (float) $p['fps'] : null], $points),
  'reglages' => $reglages, 'apprendre' => $apprendre, 'roles' => Presences::ROLES], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php if (!$actif): ?>
  <div class="alert alert-warning">La reconnaissance faciale est désactivée. <?php if (can('ecole.manage')): ?><a href="<?= e(url('/ecole/parametres')) ?>">L'activer dans les paramètres</a> (après information des familles et du personnel).<?php endif; ?> L'appel manuel reste disponible dans <a href="<?= e(url('/ecole/presences')) ?>">Présences</a>.</div>
<?php return; endif; ?>
<?php if (!$personnes): ?><div class="alert alert-info">Aucun visage enregistré pour l'instant : prenez les photos sur la fiche des élèves et du personnel (avec leur consentement), puis revenez ici.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="card-header d-flex flex-wrap gap-2 align-items-center">
      <select class="form-select form-select-sm" style="max-width:280px" id="ptPoint"><option value="">Poste de pointage (caméra de cet ordinateur)</option><?php foreach ($points as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nom']) ?> · <?= e(Presences::ROLES[$p['role']]) ?><?= $p['camera'] ? ' · ' . e($p['camera']) : '' ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm" style="max-width:220px" id="ptRole"><?php foreach (Presences::ROLES as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'passage' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm" style="max-width:240px" id="ptDevice" hidden></select>
      <button type="button" class="btn btn-sm btn-success" id="ptStart"><i class="bi bi-play-fill"></i> Démarrer</button>
      <button type="button" class="btn btn-sm btn-outline-danger" id="ptStop" hidden><i class="bi bi-stop-fill"></i> Arrêter</button>
      <span class="ms-auto small text-secondary" id="ptEtat">Arrêté</span>
    </div>
    <div class="ec-pt-stage"><video id="ptVideo" playsinline muted></video><canvas id="ptOverlay"></canvas><div class="ec-pt-msg" id="ptMsg">Choisissez la caméra puis « Démarrer ». Les modèles (≈ 13 Mo) se chargent une seule fois.</div></div>
    <div class="card-footer small text-secondary">Reconnu = distance ≤ <?= e(number_format($reglages['seuil'], 2, ',', '')) ?> et écart ≥ <?= e(number_format($reglages['marge'], 2, ',', '')) ?> avec la 2ᵉ personne, confirmé sur plusieurs images. Un visage douteux n'est jamais attribué : il est gardé dans « Passages à vérifier ». <?= (int) $personnes ?> personne(s) enregistrée(s).</div></div>
  </div>
  <div class="col-lg-4">
    <div class="card mb-3" id="ptCarte" hidden><div class="card-body d-flex gap-3">
      <img class="ec-photo-mid" data-c="photo" alt=""><div><div class="h5 mb-0" data-c="nom"></div><div class="small text-secondary" data-c="detail"></div><div class="small" data-c="contact"></div><div class="mt-1" data-c="evt"></div><div class="small text-secondary" data-c="score"></div></div>
    </div></div>
    <div class="card"><div class="card-header d-flex"><span><i class="bi bi-list-check"></i> Derniers passages</span><span class="ms-auto small"><span id="ptNbA">0</span> arrivée(s) · <span id="ptNbD">0</span> départ(s) · <span id="ptNbI">0</span> inconnu(s)</span></div>
      <ul class="list-group list-group-flush small" id="ptJournal"><li class="list-group-item text-secondary">—</li></ul>
      <div class="card-footer small"><a href="<?= e(url('/ecole/presences')) ?>">Présences du jour</a><?php if (can('ecole.biometrie')): ?> · <a href="<?= e(url('/ecole/passages')) ?>">Passages à vérifier</a><?php endif; ?></div></div>
  </div>
</div>
