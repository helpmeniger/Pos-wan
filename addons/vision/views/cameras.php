<?php
use Addons\Vision\CameraService as CS;
$e = $edit;
$v = fn($k, $d = '') => e((string) ($e[$k] ?? $d));
?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header d-flex align-items-center"><i class="bi bi-webcam me-1"></i> Caméras enregistrées <a class="btn btn-sm btn-outline-danger ms-auto" href="<?= e(url('/vision/surveillance')) ?>"><i class="bi bi-camera-video"></i> Surveillance</a></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Caméra</th><th>Type</th><th>Utilisation</th><th class="text-center">Passages</th><th></th></tr></thead><tbody>
        <?php foreach ($cameras as $c): ?>
          <tr class="<?= $c['active'] ? '' : 'text-secondary' ?>" data-cam="<?= (int) $c['id'] ?>" data-kind="<?= e($c['kind']) ?>" data-label="<?= e((string) $c['device_label']) ?>">
            <td><b><?= e($c['name']) ?></b><?= $c['active'] ? '' : ' <span class="badge bg-secondary">désactivée</span>' ?><br><span class="small text-secondary"><?= $c['kind'] === 'ip' ? e(preg_replace('#^(https?://[^/]+).*$#', '$1/…', (string) $c['url'])) . ' · ' . ($c['url_kind'] === 'mjpeg' ? 'flux MJPEG' : 'image fixe') . ' · ' . (int) $c['fps'] . ' im./s' . ($c['username'] ? ' · identifiant enregistré' : '') : e($c['device_label'] ?: 'appareil choisi sur chaque poste') ?></span></td>
            <td class="small"><?= e(CS::KINDS[$c['kind']]) ?></td>
            <td class="small"><?= $c['use_surveillance'] ? '<span class="badge bg-danger">Surveillance · ' . ($c['mode'] === 'zone' ? 'zone' : 'ligne') . '</span> ' : '' ?><?= $c['use_caisse'] ? '<span class="badge bg-primary">Caisse / reconnaissance</span>' : '' ?></td>
            <td class="text-center"><?= (int) ($counts[$c['id']] ?? 0) ?></td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-test title="Voir l'image de la caméra"><i class="bi bi-play-btn"></i> Tester</button>
              <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/vision/cameras', ['edit' => $c['id']])) ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" action="<?= e(url('/vision/cameras/' . $c['id'] . '/delete')) ?>" class="d-inline" data-confirm="<?= e((int) ($counts[$c['id']] ?? 0) ? 'Désactiver cette caméra ? Ses passages enregistrés sont conservés.' : 'Supprimer cette caméra ?') ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cameras): ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucune caméra. Ajoutez-en une avec le formulaire.</td></tr><?php endif; ?>
        </tbody></table></div>
      <div class="card-body border-top d-none" id="camPreview">
        <div class="d-flex align-items-center mb-2"><b data-title></b><button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-stop>Fermer</button></div>
        <div class="cam-preview"><video playsinline muted></video><img alt=""></div>
        <div class="small mt-1" data-msg></div>
      </div>
    </div>

    <div class="card mt-3"><div class="card-header"><i class="bi bi-question-circle"></i> Quelle caméra choisir ?</div><div class="card-body small">
      <p class="mb-2"><b>Caméra USB / webcam</b> : branchée sur le poste qui affiche la surveillance (ou la caisse). Choisissez-la dans la liste des appareils du poste.</p>
      <p class="mb-2"><b>Carte d'acquisition / table de mixage</b> (boîtier HDMI-USB, carte PCIe, mélangeur vidéo avec sortie USB, logiciel de caméra virtuelle) : Windows la présente comme une caméra ; elle se choisit de la même façon. C'est la solution pour utiliser une caméra analogique, un enregistreur (DVR/NVR) ou une table de mixage via sa sortie HDMI.</p>
      <p class="mb-2"><b>Caméra IP</b> : POS Marwa lit, par le réseau local, son <b>image fixe</b> (« snapshot ») ou son <b>flux MJPEG</b>. Exemples courants (vérifiez la notice de votre caméra) :</p>
      <ul class="mb-2">
        <li>Hikvision : <code>http://192.168.1.64/ISAPI/Streaming/channels/101/picture</code></li>
        <li>Dahua : <code>http://192.168.1.108/cgi-bin/snapshot.cgi</code></li>
        <li>Téléphone Android avec l'application « IP Webcam » : <code>http://192.168.1.20:8080/shot.jpg</code> (image) ou <code>/video</code> (MJPEG)</li>
      </ul>
      <p class="mb-0"><b>Caméra uniquement RTSP</b> : les navigateurs ne lisent pas le RTSP. Installez le petit logiciel gratuit <b>go2rtc</b> sur le serveur puis utilisez <code>http://127.0.0.1:1984/api/frame.jpeg?src=nom_camera</code>.</p>
    </div></div>
  </div>

  <div class="col-xl-5">
    <div class="card"><div class="card-header"><i class="bi bi-<?= $e ? 'pencil' : 'plus-lg' ?>"></i> <?= $e ? 'Modifier : ' . e($e['name']) : 'Ajouter une caméra' ?></div><div class="card-body">
      <form method="post" action="<?= e(url($e ? '/vision/cameras/' . $e['id'] : '/vision/cameras')) ?>" id="camForm" class="row g-2 small"><?= csrf_field() ?>
        <div class="col-12"><label class="form-label mb-0 required">Nom</label><input name="name" class="form-control" maxlength="100" required value="<?= $v('name') ?>" placeholder="Ex. : Sortie magasin, Caisse 1, Rayon boissons"></div>
        <div class="col-12"><label class="form-label mb-0">Type</label><select name="kind" class="form-select" id="camKind"><?php foreach (CS::KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($e['kind'] ?? 'usb') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 cam-local"><label class="form-label mb-0">Appareil sur ce poste</label>
          <div class="input-group"><select class="form-select" id="camDevice"><option value="">— cliquez sur « Détecter » —</option></select><button type="button" class="btn btn-outline-secondary" id="camDetect"><i class="bi bi-search"></i> Détecter</button></div>
          <input type="hidden" name="device_label" id="camLabel" value="<?= $v('device_label') ?>">
          <div class="form-text">Nom de l'appareil retenu : <b id="camLabelShow"><?= $v('device_label', 'aucun (choisi sur chaque poste)') ?></b>. Sur un autre poste, l'appareil du même nom est choisi automatiquement.</div></div>
        <div class="col-12 cam-ip"><label class="form-label mb-0 required">Adresse de l'image ou du flux</label><input name="url" class="form-control" maxlength="500" value="<?= $v('url') ?>" placeholder="http://192.168.1.64/…"></div>
        <div class="col-6 cam-ip"><label class="form-label mb-0">Format</label><select name="url_kind" class="form-select"><option value="snapshot">Image fixe (JPEG)</option><option value="mjpeg" <?= ($e['url_kind'] ?? '') === 'mjpeg' ? 'selected' : '' ?>>Flux MJPEG</option></select></div>
        <div class="col-6 cam-ip"><label class="form-label mb-0">Images / seconde</label><input type="number" name="fps" min="1" max="10" class="form-control" value="<?= $v('fps', '3') ?>"></div>
        <div class="col-6 cam-ip"><label class="form-label mb-0">Identifiant</label><input name="username" class="form-control" maxlength="100" autocomplete="off" value="<?= $v('username') ?>"></div>
        <div class="col-6 cam-ip"><label class="form-label mb-0">Mot de passe</label><input type="password" name="password" class="form-control" autocomplete="new-password" placeholder="<?= !empty($e['secret']) ? '•••••• (inchangé)' : '' ?>"><?php if (!empty($e['secret'])): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="clear_password" value="1" id="cpw"><label class="form-check-label" for="cpw">effacer</label></div><?php endif; ?></div>
        <div class="col-12 mt-2"><b>Utilisation</b></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="use_surveillance" value="1" id="cuS" <?= !$e || $e['use_surveillance'] ? 'checked' : '' ?>><label class="form-check-label" for="cuS"><b>Surveillance</b> : comptage automatique des articles qui passent</label></div></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="use_caisse" value="1" id="cuC" <?= !empty($e['use_caisse']) ? 'checked' : '' ?>><label class="form-check-label" for="cuC"><b>Caisse et reconnaissance</b> : proposée dans la fenêtre caméra (scan, « Reconnaître »)</label></div></div>
        <div class="col-6"><label class="form-label mb-0">Comptage</label><select name="mode" class="form-select"><option value="ligne">Articles qui franchissent la ligne rouge</option><option value="zone" <?= ($e['mode'] ?? '') === 'zone' ? 'selected' : '' ?>>Articles vus dans une zone</option></select></div>
        <div class="col-6"><label class="form-label mb-0">Sensibilité (1–10)</label><input type="range" name="sensitivity" min="1" max="10" class="form-range" value="<?= $v('sensitivity', '5') ?>"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="cuA" <?= !$e || $e['active'] ? 'checked' : '' ?>><label class="form-check-label" for="cuA">Active</label></div></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button><?php if ($e): ?><a class="btn btn-outline-secondary" href="<?= e(url('/vision/cameras')) ?>">Annuler</a><?php endif; ?></div>
        <div class="col-12 form-text">La ligne rouge et la zone se dessinent sur l'image, dans l'écran Surveillance.</div>
      </form>
    </div></div>

    <div class="card mt-3"><div class="card-header"><i class="bi bi-sliders"></i> Réglages de la surveillance</div><div class="card-body">
      <form method="post" action="<?= e(url('/vision/cameras/settings')) ?>" class="row g-2 small"><?= csrf_field() ?>
        <div class="col-6"><label class="form-label mb-0">Fenêtre de rapprochement (minutes)</label><input type="number" name="vision_surv_fenetre" min="1" max="240" class="form-control" value="<?= e(setting('vision_surv_fenetre', '20')) ?>"><div class="form-text">Un passage est « justifié » par une vente du même article dans ± ce délai.</div></div>
        <div class="col-6"><label class="form-label mb-0">Conservation des passages (jours)</label><input type="number" name="vision_surv_conservation" min="1" max="365" class="form-control" value="<?= e(setting('vision_surv_conservation', '30')) ?>"><div class="form-text">Les vignettes plus anciennes sont effacées automatiquement.</div></div>
        <div class="col-6"><label class="form-label mb-0">Ressemblance minimale « identifié »</label><input name="vision_surv_seuil" class="form-control" inputmode="decimal" value="<?= e(setting('vision_surv_seuil', '0.45')) ?>"></div>
        <div class="col-6"><label class="form-label mb-0">Écart minimal avec le 2ᵉ article</label><input name="vision_surv_marge" class="form-control" inputmode="decimal" value="<?= e(setting('vision_surv_marge', '0.04')) ?>"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="vision_cam_publique" value="1" id="vcp" <?= setting('vision_cam_publique', '0') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="vcp">Autoriser des caméras IP hors du réseau local (déconseillé)</label></div></div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div>
      </form>
    </div></div>
  </div>
</div>
