<?php
use App\Services\CustomerDisplay as CD;
$chk = fn($k) => $s[$k] === '1' ? 'checked' : '';
$mb = fn($b) => round($b / 1048576) . ' Mo';
?>
<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" action="<?= e(url('/settings/display')) ?>" class="card mb-3"><?= csrf_field() ?>
      <div class="card-header"><i class="bi bi-display"></i> Écran client (POS double écran ou second moniteur)</div>
      <div class="card-body row g-2">
        <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="display_enabled" value="1" id="dEn" <?= $chk('display_enabled') ?>><label class="form-check-label fw-semibold" for="dEn">Activer l'écran client (bouton <i class="bi bi-display"></i> dans la caisse)</label></div>
        <div class="col-md-6"><label class="form-label">Message d'accueil</label><input name="display_welcome" class="form-control" maxlength="120" value="<?= e($s['display_welcome']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Message après la vente</label><input name="display_thanks" class="form-control" maxlength="120" value="<?= e($s['display_thanks']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label">Remerciement (s)</label><input name="display_thanks_seconds" type="number" min="2" max="60" class="form-control" value="<?= e($s['display_thanks_seconds']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label">Image par défaut (s)</label><input name="display_image_seconds" type="number" min="3" max="300" class="form-control" value="<?= e($s['display_image_seconds']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label">Thème</label><select name="display_theme" class="form-select"><option value="dark" <?= $s['display_theme'] === 'dark' ? 'selected' : '' ?>>Sombre</option><option value="light" <?= $s['display_theme'] === 'light' ? 'selected' : '' ?>>Clair</option></select></div>
        <div class="col-6 col-md-3 d-flex flex-column justify-content-end">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="display_show_lines" value="1" id="dLines" <?= $chk('display_show_lines') ?>><label class="form-check-label" for="dLines">Détail des articles</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="display_video_sound" value="1" id="dSound" <?= $chk('display_video_sound') ?>><label class="form-check-label" for="dSound">Son des vidéos</label></div>
        </div>
      </div>
      <div class="card-header border-top"><i class="bi bi-window-dock"></i> Afficheur client (2 lignes, « pole display »)</div>
      <div class="card-body row g-2">
        <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="vfd_enabled" value="1" id="vEn" <?= $chk('vfd_enabled') ?>><label class="form-check-label fw-semibold" for="vEn">Activer l'afficheur client</label></div>
        <div class="col-12"><label class="form-label">Branchement</label><select name="vfd_mode" class="form-select" id="vMode"><?php foreach (CD::MODES as $k => $l): ?><option value="<?= e($k) ?>" <?= $s['vfd_mode'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Jeu de commandes</label><select name="vfd_protocol" class="form-select"><?php foreach (CD::PROTOCOLS as $k => $l): ?><option value="<?= e($k) ?>" <?= $s['vfd_protocol'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label">Caractères / ligne</label><input name="vfd_cols" type="number" min="16" max="40" class="form-control" value="<?= e($s['vfd_cols']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label">Vitesse (bauds)</label><select name="vfd_baud" class="form-select"><?php foreach (CD::BAUDS as $b): ?><option value="<?= $b ?>" <?= (int) $s['vfd_baud'] === $b ? 'selected' : '' ?>><?= $b ?></option><?php endforeach; ?></select></div>
        <div class="col-12" id="vAddrBox"><label class="form-label">Port (afficheur relié au serveur)</label><input name="vfd_address" class="form-control" maxlength="40" value="<?= e($s['vfd_address']) ?>" placeholder="COM3, /dev/ttyUSB0 ou 192.168.1.60:9100"><div class="form-text">Windows : la vitesse du port se règle dans le Gestionnaire de périphériques (Ports COM → Propriétés) ; la plupart des afficheurs sont à 9600 bauds.</div></div>
        <div class="col-md-6"><label class="form-label">Ligne 1 au repos</label><input name="vfd_line1" class="form-control" maxlength="40" value="<?= e($s['vfd_line1']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Ligne 2 au repos</label><input name="vfd_line2" class="form-control" maxlength="40" value="<?= e($s['vfd_line2']) ?>" placeholder="<?= e((string) setting('company_name', '')) ?>"></div>
      </div>
      <div class="card-footer d-flex flex-wrap gap-2">
        <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
        <?php if ($displayConfig['enabled']): ?><button type="button" class="btn btn-outline-primary" data-cd-open><i class="bi bi-display"></i> Ouvrir l'écran client</button><?php endif; ?>
        <?php if ($displayConfig['vfd']['enabled']): ?><button type="button" class="btn btn-outline-secondary" data-cd-vfd-test><i class="bi bi-window-dock"></i> <?= $displayConfig['vfd']['mode'] === 'browser' ? 'Connecter et tester l\'afficheur (ce poste)' : 'Tester l\'afficheur' ?></button><?php endif; ?>
      </div>
    </form>

    <div class="alert alert-info small">
      <b>Mise en place</b>
      <ul class="mb-0 mt-1">
        <li><b>POS double écran ou second moniteur</b> (PC Windows) : ouvrez la caisse dans Chrome ou Edge, touchez <i class="bi bi-display"></i>. Le navigateur demande l'autorisation de « gérer les fenêtres sur tous vos écrans » : acceptez, l'écran client s'ouvre sur le second écran. Touchez-le une fois pour le plein écran. Sans cette autorisation, faites glisser la fenêtre sur le second écran.</li>
        <li>L'écran client reçoit le panier de la caisse <b>ouverte dans le même navigateur, sur le même poste</b> (aucun envoi par Internet) : il continue de fonctionner sans connexion.</li>
        <li><b>Afficheur client branché sur le poste</b> (USB / série) : Chrome ou Edge, page en <b>https</b> (o2switch) ou ouverte <b>sur le PC serveur</b> (localhost). Choisissez l'afficheur une fois avec « Connecter et tester » ; la caisse le reconnecte ensuite seule. Un adaptateur USB-série doit avoir son pilote Windows installé.</li>
        <li><b>Afficheur relié au serveur</b> : seulement si le serveur POS Marwa est le PC de la boutique (port COM) ou si l'afficheur a une adresse réseau. Impossible depuis l'hébergement o2switch.</li>
      </ul>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-collection-play"></i> Images et vidéos diffusées au repos</div>
      <form method="post" action="<?= e(url('/settings/display/media')) ?>" enctype="multipart/form-data" class="card-body row g-2 border-bottom"><?= csrf_field() ?>
        <div class="col-12"><input type="file" name="media" class="form-control" required accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm"></div>
        <div class="col-8"><input name="title" class="form-control" maxlength="150" placeholder="Titre (facultatif) : promotion, nouveauté…"></div>
        <div class="col-4"><button class="btn btn-success w-100"><i class="bi bi-upload"></i> Ajouter</button></div>
        <div class="col-12 form-text">Images JPG, PNG, WEBP, GIF (<?= e($mb($limits['image'])) ?> au plus, réduites à 1920 px) ; vidéos MP4 (H.264) ou WEBM (<?= e($mb($limits['video'])) ?> au plus). Limite de ce serveur PHP : <?= e($limits['php_upload']) ?> par fichier, <?= e($limits['php_post']) ?> par envoi.</div>
      </form>
      <ul class="list-group list-group-flush">
      <?php foreach ($media as $i => $m): $url = upload_url($m['path']); ?>
        <li class="list-group-item <?= (int) $m['active'] ? '' : 'opacity-50' ?>">
          <div class="d-flex gap-2 align-items-center">
            <div style="width:96px;height:54px;flex:none;background:#000;border-radius:6px;overflow:hidden">
              <?php if ($m['kind'] === 'video'): ?><video src="<?= e($url) ?>#t=0.5" muted preload="metadata" style="width:100%;height:100%;object-fit:cover"></video><?php else: ?><img src="<?= e($url) ?>" alt="" style="width:100%;height:100%;object-fit:cover"><?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('/settings/display/media/' . $m['id'])) ?>" class="flex-grow-1 row g-1 align-items-center"><?= csrf_field() ?>
              <div class="col-12"><input name="title" class="form-control form-control-sm" maxlength="150" value="<?= e($m['title'] ?? '') ?>" placeholder="<?= $m['kind'] === 'video' ? 'Vidéo' : 'Image' ?> <?= $i + 1 ?>"></div>
              <div class="col-5"><?php if ($m['kind'] === 'image'): ?><div class="input-group input-group-sm"><input name="duration_s" type="number" min="3" max="300" class="form-control" value="<?= (int) $m['duration_s'] ?>"><span class="input-group-text">s</span></div><?php else: ?><input type="hidden" name="duration_s" value="<?= (int) $m['duration_s'] ?>"><span class="badge bg-dark"><i class="bi bi-film"></i> vidéo entière</span><?php endif; ?></div>
              <div class="col-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="ma<?= (int) $m['id'] ?>" <?= (int) $m['active'] ? 'checked' : '' ?>><label class="form-check-label small" for="ma<?= (int) $m['id'] ?>">Diffusé</label></div></div>
              <div class="col-3 text-end"><button class="btn btn-sm btn-outline-primary" title="Enregistrer"><i class="bi bi-check2"></i></button></div>
            </form>
            <div class="d-flex flex-column gap-1">
              <form method="post" action="<?= e(url('/settings/display/media/' . $m['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="move" value="up"><button class="btn btn-sm btn-light py-0" title="Monter" <?= $i === 0 ? 'disabled' : '' ?>><i class="bi bi-chevron-up"></i></button></form>
              <form method="post" action="<?= e(url('/settings/display/media/' . $m['id'])) ?>"><?= csrf_field() ?><input type="hidden" name="move" value="down"><button class="btn btn-sm btn-light py-0" title="Descendre" <?= $i === count($media) - 1 ? 'disabled' : '' ?>><i class="bi bi-chevron-down"></i></button></form>
              <form method="post" action="<?= e(url('/settings/display/media/' . $m['id'] . '/delete')) ?>" data-confirm="Retirer ce fichier de la diffusion et le supprimer du serveur ?"><?= csrf_field() ?><button class="btn btn-sm btn-light text-danger py-0" title="Supprimer"><i class="bi bi-trash"></i></button></form>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
      <?php if (!$media): ?><li class="list-group-item text-secondary small">Aucune image ni vidéo : l'écran client affiche le logo, le nom de l'entreprise et le message d'accueil.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<script type="application/json" id="pm-display-config"><?= json_encode($displayConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
