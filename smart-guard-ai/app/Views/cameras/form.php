<?php $isEdit = !empty($cam['id']); ?>
<form method="post" action="<?= url($isEdit ? 'cameras/' . (int) $cam['id'] : 'cameras') ?>" class="card" autocomplete="off"><div class="card-body">
<?= csrf_field() ?>
<div class="row g-3">
  <div class="col-md-6"><label class="form-label">Nom de la caméra *</label><input name="name" class="form-control" required maxlength="100" value="<?= e($cam['name'] ?? '') ?>" placeholder="Ex. Porte principale"></div>
  <div class="col-md-6"><label class="form-label">Type *</label>
    <select name="type" id="camType" class="form-select"><?php foreach (['usb', 'ip_rtsp', 'ip_http', 'nvr'] as $t): ?><option value="<?= $t ?>" <?= ($cam['type'] ?? '') === $t ? 'selected' : '' ?>><?= e(label('camera_type', $t)) ?></option><?php endforeach; ?></select></div>
</div>
<div id="usbFields" class="row g-3 mt-1">
  <div class="col-md-4"><label class="form-label">Index du périphérique USB</label>
    <div class="input-group"><input name="device_index" id="deviceIndex" type="number" min="0" max="9" class="form-control" value="<?= e($cam['device_index'] ?? 0) ?>">
    <button type="button" class="btn btn-outline-secondary" id="btnDevices">Détecter</button></div>
    <div class="form-text">La webcam doit être branchée sur l'ordinateur qui exécute le <strong>service IA</strong>. Une caméra branchée sur un smartphone ou un autre PC n'est pas visible.</div>
    <div id="devicesOut" class="small mt-1"></div></div>
</div>
<div id="ipFields" class="row g-3 mt-1">
  <div class="col-md-4"><label class="form-label">Adresse IP</label><input name="ip_address" class="form-control" value="<?= e($cam['ip_address'] ?? '') ?>" placeholder="192.168.1.64"></div>
  <div class="col-md-2"><label class="form-label">Port</label><input name="port" type="number" class="form-control" value="<?= e($cam['port'] ?? '') ?>" placeholder="554"></div>
  <div class="col-md-6"><label class="form-label">Chemin du flux</label><input name="stream_path" class="form-control" value="<?= e($cam['stream_path'] ?? '') ?>" placeholder="/Streaming/Channels/102">
    <div class="form-text">Consultez la documentation du fabricant (voir docs/CAMERAS.md). Privilégiez le sous-flux (résolution réduite).</div></div>
  <div class="col-md-6"><label class="form-label">Identifiant</label><input name="username" class="form-control" value="<?= e($cam['username'] ?? '') ?>" autocomplete="off"></div>
  <div class="col-md-6"><label class="form-label">Mot de passe</label><input name="password" type="password" class="form-control" autocomplete="new-password" placeholder="<?= !empty($cam['password_enc']) ? '•••••••• (inchangé si vide)' : '' ?>">
    <?php if (!empty($cam['password_enc'])): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_password" value="1" id="cp"><label class="form-check-label small" for="cp">Effacer le mot de passe enregistré</label></div><?php endif; ?>
    <div class="form-text">Chiffré en base de données, jamais affiché.</div></div>
</div>
<hr>
<div class="row g-3">
  <div class="col-md-4"><label class="form-label">Emplacement</label><input name="location" class="form-control" value="<?= e($cam['location'] ?? '') ?>" placeholder="Entrée principale"></div>
  <div class="col-md-4"><label class="form-label">Zone surveillée</label><input name="monitored_zone" class="form-control" value="<?= e($cam['monitored_zone'] ?? '') ?>" placeholder="Passage de sortie"></div>
  <div class="col-md-4"><label class="form-label">Fonction</label><select name="role" class="form-select"><?php foreach (['sortie', 'entree', 'caisse', 'rayon', 'reserve', 'autre'] as $t): ?><option value="<?= $t ?>" <?= ($cam['role'] ?? '') === $t ? 'selected' : '' ?>><?= e(label('camera_role', $t)) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-3"><label class="form-label">Largeur (px)</label><input name="resolution_w" type="number" class="form-control" value="<?= e($cam['resolution_w']) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label">Hauteur (px)</label><input name="resolution_h" type="number" class="form-control" value="<?= e($cam['resolution_h']) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label">Images/s (capture)</label><input name="target_fps" type="number" class="form-control" value="<?= e($cam['target_fps']) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label">Images/s analysées</label><input name="analysis_fps" type="number" min="1" max="30" class="form-control" value="<?= e($cam['analysis_fps']) ?>">
    <div class="form-text">Réduire (2–5) sur un ordinateur modeste.</div></div>
  <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="recording_enabled" value="1" id="rec" <?= !empty($cam['recording_enabled']) ? 'checked' : '' ?>><label class="form-check-label" for="rec">Enregistrer un court extrait vidéo autour de chaque événement</label></div></div>
  <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="analysis_enabled" value="1" id="ana" <?= !empty($cam['analysis_enabled']) ? 'checked' : '' ?>><label class="form-check-label" for="ana">Analyse activée</label></div></div>
</div>
</div><div class="card-footer bg-white d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="<?= url('cameras') ?>" class="btn btn-light">Annuler</a></div></form>
<script>
(function(){
  const t=document.getElementById('camType');
  const sync=()=>{const usb=t.value==='usb';document.getElementById('usbFields').style.display=usb?'':'none';document.getElementById('ipFields').style.display=usb?'none':'';};
  t.addEventListener('change',sync);sync();
  document.getElementById('btnDevices').addEventListener('click',async()=>{
    const out=document.getElementById('devicesOut');out.textContent='Recherche…';
    try{const d=await sgFetch('cameras/devices');out.innerHTML=d.devices.length?d.devices.map(x=>`<a href="#" data-i="${x.index}">#${x.index} (${x.width}×${x.height}${x.readable?'':' — illisible'})</a>`).join(' · '):'Aucune caméra USB détectée sur l\'ordinateur du service IA.';
      out.querySelectorAll('[data-i]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();document.getElementById('deviceIndex').value=a.dataset.i;}));
    }catch(e){out.textContent=e.message;}
  });
})();
</script>
