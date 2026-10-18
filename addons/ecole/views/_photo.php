<?php
/** Photo (caméra ou fichier) + visage pour le pointage (si activé et consenti). @var string $ptype @var ?array $e */
$visageActif = Addons\Ecole\Biometrie::active() && can('ecole.biometrie');
$nbVisages = $e ? Addons\Ecole\Biometrie::nombre($ptype, (int) $e['id']) : 0;
?>
<div class="ec-photo" data-photo-widget data-visage="<?= $visageActif ? '1' : '0' ?>">
  <div class="ec-photo-view"><video playsinline muted hidden></video>
    <img alt="" data-preview <?= $e && $e['photo'] ? 'src="' . e(url('/ecole/photo/' . $ptype . '/' . $e['id'])) . '"' : 'hidden' ?>>
    <div class="ec-photo-empty" <?= $e && $e['photo'] ? 'hidden' : '' ?>><i class="bi bi-person-square"></i></div></div>
  <div class="d-flex flex-wrap gap-1 mt-1">
    <button type="button" class="btn btn-sm btn-outline-primary" data-cam-start><i class="bi bi-camera"></i> Caméra</button>
    <button type="button" class="btn btn-sm btn-primary" data-cam-shot hidden><i class="bi bi-camera-fill"></i> Prendre la photo</button>
    <label class="btn btn-sm btn-outline-secondary mb-0"><i class="bi bi-image"></i> Fichier<input type="file" name="photo" accept="image/*" hidden data-photo-file></label>
  </div>
  <input type="hidden" name="photo_data" data-photo-data>
  <?php if ($visageActif): ?>
    <input type="hidden" name="visages" data-visages>
    <div class="small mt-1" data-visage-msg><i class="bi bi-person-bounding-box"></i> Visage pour le pointage : <b data-visage-n><?= (int) $nbVisages ?></b> enregistré(s). Les photos prises ici servent aussi au pointage <b>si le consentement est coché</b> (2 à 3 photos de face conseillées).</div>
  <?php endif; ?>
</div>
