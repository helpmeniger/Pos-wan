<?php use Addons\Clinique\ImagingService as IS; use Addons\Clinique\ClinicService as CS;
$badge = ['a_rattacher' => 'danger', 'a_lire' => 'warning text-dark', 'lu' => 'info text-dark', 'valide' => 'success'];
$list = array_map(fn($i) => ['id' => (int) $i['id'], 'ok' => (bool) $i['renderable'], 'note' => $i['render_note'], 'series' => $i['series_description'], 'n' => $i['instance_number'], 'frames' => (int) $i['frames'], 'format' => $i['format']], $images);
$canReport = can('clinique.imaging_report') && $s['patient_id'];
$locked = $s['status'] === 'valide';
$templates = [
  'Radiographie du thorax' => ['Radiographie du thorax de face, en inspiration, debout.', "Silhouette cardiaque de taille normale.\nCoupoles diaphragmatiques régulières, culs-de-sac libres.\nParenchyme pulmonaire sans opacité focale.\nPas d'épanchement pleural ni de pneumothorax.\nCadre osseux sans anomalie visible.", 'Radiographie du thorax sans anomalie décelable.'],
  'Échographie abdominale' => ['Échographie abdominale par voie sous-costale et intercostale.', "Foie de taille et d'échostructure normales, sans lésion focale.\nVésicule alithiasique, voies biliaires non dilatées.\nPancréas, rate et reins sans anomalie.\nPas d'épanchement intrapéritonéal.", 'Échographie abdominale normale.'],
  'Radiographie osseuse' => ['Radiographie de face et de profil.', "Pas de trait de fracture visible.\nInterlignes articulaires conservés.\nMinéralisation osseuse normale.", 'Pas de lésion osseuse traumatique décelable.'],
];
?>
<link rel="stylesheet" href="<?= e(asset('addons/clinique/imagerie.css')) ?>">
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <a href="<?= e(url('/clinique/imagerie')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <?php if ($s['patient_id']): ?>
    <a class="fs-5 fw-semibold" href="<?= e(url('/clinique/patients/' . $s['patient_id'])) ?>"><?= e($s['last_name'] . ' ' . $s['first_name']) ?></a>
    <span class="text-secondary small font-monospace"><?= e($s['patient_number']) ?></span><span class="text-secondary small"><?= e($s['sex']) ?><?= $s['birth_date'] ? ' · ' . e(CS::age($s['birth_date'])) : '' ?></span>
  <?php else: ?><span class="fs-5 fw-semibold text-danger"><?= e(t('Examen non rattaché')) ?></span><?php endif; ?>
  <span class="badge bg-dark"><?= e((string) $s['modality']) ?></span><span class="badge bg-<?= e($badge[$s['status']]) ?>"><?= e(t(IS::STATUS[$s['status']])) ?></span>
  <span class="small text-secondary"><?= e((string) $s['description']) ?> · <?= e(fdate($s['study_date'] ?: $s['created_at'])) ?> · <?= (int) $s['images'] ?> <?= e(t('image(s)')) ?>
    · <?= e(['reseau' => t('reçu par le réseau'), 'dossier' => t('dossier d\'arrivée'), 'import' => t('importé')][$s['source']]) ?><?= $s['device_name'] ? ' (' . e($s['device_name']) . ')' : '' ?><?= $s['request_number'] ? ' · ' . e(t('demande')) . ' ' . e($s['request_number']) : '' ?></span>
  <div class="ms-auto d-flex gap-1">
    <?php if (can('clinique.imaging')): ?><button type="button" class="btn btn-sm btn-outline-primary" data-img-import data-study="<?= (int) $s['id'] ?>"><i class="bi bi-upload"></i> <?= e(t('Ajouter des images')) ?></button><input type="file" multiple class="d-none" data-img-files><?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/clinique/imagerie/examens/' . $s['id'] . '/imprimer')) ?>"><i class="bi bi-printer"></i> <?= e(t('Compte rendu')) ?></a>
  </div>
</div>
<div class="img-progress small mb-2" data-img-progress></div>

<?php if (!$s['patient_id'] && can('clinique.imaging')): ?>
<div class="card border-danger mb-2"><div class="card-body py-2 small">
  <div class="mb-1"><b><?= e(t('À rattacher :')) ?></b> <?= e(t('le fichier indique')) ?> « <?= e((string) $s['dicom_patient_name']) ?> »<?= $s['dicom_patient_id'] ? ', ID ' . e($s['dicom_patient_id']) : '' ?><?= $s['dicom_birth_date'] ? ', ' . e(t('né(e) le')) . ' ' . e(fdate($s['dicom_birth_date'], false)) : '' ?><?= $s['accession'] ? ', ' . e(t('n° de demande')) . ' ' . e($s['accession']) : '' ?>. <?= e(t('Vérifiez l\'identité avant de rattacher.')) ?></div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?php foreach ($candidates as $c): ?><form method="post" action="<?= e(url('/clinique/imagerie/examens/' . $s['id'] . '/rattacher')) ?>" class="d-inline" data-confirm="<?= e(t('Rattacher cet examen à') . ' ' . $c['last_name'] . ' ' . $c['first_name'] . ' ?') ?>"><?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-outline-danger"><?= e($c['last_name'] . ' ' . $c['first_name']) ?> · <?= e($c['number']) ?><?= $c['birth_date'] ? ' · ' . e(fdate($c['birth_date'], false)) : '' ?></button></form><?php endforeach; ?>
    <form method="post" action="<?= e(url('/clinique/imagerie/examens/' . $s['id'] . '/rattacher')) ?>" class="d-flex gap-1"><?= csrf_field() ?><input name="patient_number" class="form-control form-control-sm font-monospace" placeholder="<?= e(t('N° de dossier')) ?>" required><button class="btn btn-sm btn-danger"><?= e(t('Rattacher')) ?></button></form>
  </div>
</div></div>
<?php elseif ($s['patient_id'] && !$s['request_id'] && $requests && can('clinique.imaging')): ?>
<form method="post" action="<?= e(url('/clinique/imagerie/examens/' . $s['id'] . '/rattacher')) ?>" class="alert alert-info py-1 small d-flex gap-2 align-items-center"><?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $s['patient_id'] ?>">
  <?= e(t('Demande en attente pour ce patient :')) ?> <select name="request_id" class="form-select form-select-sm w-auto"><?php foreach ($requests as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['number'] . ' — ' . $r['exam']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary"><?= e(t('Lier l\'examen à la demande')) ?></button></form>
<?php endif; ?>

<div class="img-layout">
  <aside class="img-strip" id="imgStrip">
    <?php foreach ($list as $i => $im): ?><button type="button" class="img-strip-item" data-index="<?= $i ?>" title="<?= e(($im['series'] ?? '') . ' #' . ($im['n'] ?? ($i + 1))) ?>"><img loading="lazy" src="<?= e(url('/clinique/imagerie/images/' . $im['id'] . '/miniature')) ?>" alt=""><span><?= $i + 1 ?><?= $im['frames'] > 1 ? ' · ' . $im['frames'] . ' im.' : '' ?></span></button><?php endforeach; ?>
  </aside>
  <section class="img-viewer" id="viewer" data-images="<?= e(json_encode($list)) ?>" data-base="<?= e(url('/clinique/imagerie')) ?>" data-can-annotate="<?= can('clinique.medical') ? '1' : '0' ?>">
    <div class="img-toolbar" role="toolbar">
      <div class="btn-group btn-group-sm" data-group="tool">
        <button type="button" class="btn btn-dark active" data-tool="wl" title="<?= e(t('Contraste / luminosité : glisser (horizontal = largeur, vertical = niveau)')) ?>"><i class="bi bi-circle-half"></i></button>
        <button type="button" class="btn btn-dark" data-tool="pan" title="<?= e(t('Déplacer')) ?>"><i class="bi bi-arrows-move"></i></button>
        <button type="button" class="btn btn-dark" data-tool="zoom" title="<?= e(t('Loupe : glisser vers le haut / bas (ou molette)')) ?>"><i class="bi bi-zoom-in"></i></button>
        <button type="button" class="btn btn-dark" data-tool="distance" title="<?= e(t('Mesurer une distance')) ?>"><i class="bi bi-rulers"></i></button>
        <button type="button" class="btn btn-dark" data-tool="angle" title="<?= e(t('Mesurer un angle (3 points)')) ?>"><i class="bi bi-triangle"></i></button>
        <button type="button" class="btn btn-dark" data-tool="roi" title="<?= e(t('Zone : surface, moyenne, écart-type, min, max')) ?>"><i class="bi bi-circle"></i></button>
        <button type="button" class="btn btn-dark" data-tool="fleche" title="<?= e(t('Flèche')) ?>"><i class="bi bi-arrow-up-right"></i></button>
        <button type="button" class="btn btn-dark" data-tool="texte" title="<?= e(t('Texte')) ?>"><i class="bi bi-fonts"></i></button>
        <button type="button" class="btn btn-dark" data-tool="calib" title="<?= e(t('Calibrer : tracer un repère de longueur connue')) ?>"><i class="bi bi-bounding-box"></i></button>
      </div>
      <select class="form-select form-select-sm w-auto" id="preset" title="<?= e(t('Fenêtres prédéfinies')) ?>"><option value=""><?= e(t('Fenêtre…')) ?></option><option value="dicom"><?= e(t('Appareil')) ?></option><option value="auto"><?= e(t('Automatique')) ?></option><option value="40,400"><?= e(t('Tissus mous')) ?> (40/400)</option><option value="-600,1500"><?= e(t('Poumon')) ?> (-600/1500)</option><option value="400,1800"><?= e(t('Os')) ?> (400/1800)</option><option value="40,80"><?= e(t('Cerveau')) ?> (40/80)</option></select>
      <div class="btn-group btn-group-sm">
        <button type="button" class="btn btn-outline-light" data-act="invert" title="<?= e(t('Inverser')) ?>"><i class="bi bi-yin-yang"></i></button>
        <button type="button" class="btn btn-outline-light" data-act="rotate" title="<?= e(t('Pivoter de 90°')) ?>"><i class="bi bi-arrow-clockwise"></i></button>
        <button type="button" class="btn btn-outline-light" data-act="flip" title="<?= e(t('Miroir')) ?>"><i class="bi bi-symmetry-vertical"></i></button>
        <button type="button" class="btn btn-outline-light" data-act="fit" title="<?= e(t('Ajuster à l\'écran')) ?>"><i class="bi bi-aspect-ratio"></i></button>
        <button type="button" class="btn btn-outline-light" data-act="reset" title="<?= e(t('Réinitialiser')) ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
        <button type="button" class="btn btn-outline-light" data-act="full" title="<?= e(t('Plein écran')) ?>"><i class="bi bi-fullscreen"></i></button>
        <button type="button" class="btn btn-outline-light" data-act="png" title="<?= e(t('Enregistrer la vue (PNG)')) ?>"><i class="bi bi-camera"></i></button>
      </div>
      <div class="img-frames d-none" id="frameBox"><button type="button" class="btn btn-sm btn-outline-light" id="cine" title="<?= e(t('Lecture')) ?>"><i class="bi bi-play-fill"></i></button><input type="range" id="frame" min="0" value="0"><span id="frameLbl"></span></div>
    </div>
    <div class="img-stage" id="stage"><canvas id="cv"></canvas>
      <div class="img-ov tl" id="ovTl"></div><div class="img-ov tr" id="ovTr"></div><div class="img-ov bl" id="ovBl"></div><div class="img-ov br" id="ovBr"></div>
      <div class="img-msg" id="msg"><?= e(t('Chargement…')) ?></div></div>
  </section>
  <aside class="img-side">
    <div class="card mb-2"><div class="card-header py-1 d-flex"><span><i class="bi bi-rulers"></i> <?= e(t('Mesures et annotations')) ?></span><span class="ms-auto small text-secondary" id="calibInfo"></span></div>
      <ul class="list-group list-group-flush small" id="annList"><li class="list-group-item text-secondary"><?= e(t('Aucune')) ?></li></ul></div>
    <form method="post" action="<?= e(url('/clinique/imagerie/examens/' . $s['id'] . '/compte-rendu')) ?>" class="card mb-2"><?= csrf_field() ?>
      <div class="card-header py-1 d-flex align-items-center"><i class="bi bi-file-earmark-text me-1"></i> <?= e(t('Compte rendu')) ?>
        <?php if ($canReport && !$locked): ?><select class="form-select form-select-sm w-auto ms-auto" id="tpl"><option value=""><?= e(t('Modèle…')) ?></option><?php foreach ($templates as $k => $v): ?><option value="<?= e(json_encode($v)) ?>"><?= e(t($k)) ?></option><?php endforeach; ?></select><?php endif; ?></div>
      <div class="card-body p-2 small">
        <?php if ($s['request_exam']): ?><div class="mb-1"><b><?= e(t('Demande')) ?> :</b> <?= e($s['request_exam']) ?><?= $s['clinical_info'] ? ' — ' . e($s['clinical_info']) : '' ?> (<?= e((string) $s['requester']) ?>)</div><?php endif; ?>
        <label class="form-label mb-0"><?= e(t('Technique')) ?></label><textarea name="technique" rows="2" class="form-control form-control-sm mb-1" <?= $canReport && !$locked ? '' : 'readonly' ?>><?= e((string) $s['technique']) ?></textarea>
        <label class="form-label mb-0"><?= e(t('Résultats')) ?></label><textarea name="findings" rows="7" class="form-control form-control-sm mb-1" <?= $canReport && !$locked ? '' : 'readonly' ?>><?= e((string) $s['findings']) ?></textarea>
        <label class="form-label mb-0"><?= e(t('Conclusion')) ?></label><textarea name="conclusion" rows="3" class="form-control form-control-sm mb-1 fw-semibold" <?= $canReport && !$locked ? '' : 'readonly' ?>><?= e((string) $s['conclusion']) ?></textarea>
        <?php if ($s['reported_at']): ?><div class="text-secondary"><?= e(t('Rédigé par')) ?> <?= e((string) $s['reporter']) ?> — <?= e(fdate($s['reported_at'])) ?><?= $s['validated_at'] ? ' · <b class="text-success">' . e(t('validé le')) . ' ' . e(fdate($s['validated_at'])) . '</b>' : '' ?></div><?php endif; ?>
        <?php if ($canReport && !$locked): ?><div class="d-flex gap-1 mt-1"><button name="action" value="enregistrer" class="btn btn-sm btn-outline-primary flex-fill"><?= e(t('Enregistrer')) ?></button><button name="action" value="valider" class="btn btn-sm btn-success flex-fill"><?= e(t('Valider')) ?></button></div>
        <?php elseif (!can('clinique.imaging_report')): ?><div class="text-secondary"><?= e(t('Rédaction réservée au médecin.')) ?></div>
        <?php elseif (!$s['patient_id']): ?><div class="text-secondary"><?= e(t('Rattachez d\'abord l\'examen à un patient.')) ?></div><?php endif; ?>
        <div class="text-secondary mt-1" style="font-size:.72rem"><?= e(t('Aide à la lecture : mesures et contraste. Aucune interprétation automatique : le diagnostic reste celui du médecin.')) ?></div>
      </div></form>
    <?php if ($locked && can('clinique.imaging_report')): ?><form method="post" action="<?= e(url('/clinique/imagerie/examens/' . $s['id'] . '/rouvrir')) ?>" class="d-flex gap-1 mb-2"><?= csrf_field() ?><input name="reason" class="form-control form-control-sm" required minlength="3" placeholder="<?= e(t('Motif de la correction')) ?>"><button class="btn btn-sm btn-outline-warning text-nowrap"><?= e(t('Rouvrir')) ?></button></form><?php endif; ?>
    <?php if ($history): ?><div class="card"><div class="card-header py-1"><?= e(t('Examens précédents')) ?></div><ul class="list-group list-group-flush small">
      <?php foreach ($history as $h): ?><li class="list-group-item py-1"><a href="<?= e(url('/clinique/imagerie/examens/' . $h['id'])) ?>"><?= e(fdate($h['study_date'], false)) ?> · <?= e((string) $h['modality']) ?> <?= e((string) $h['description']) ?></a> <span class="badge bg-<?= e($badge[$h['status']]) ?>"><?= e(t(IS::STATUS[$h['status']])) ?></span></li><?php endforeach; ?></ul></div><?php endif; ?>
  </aside>
</div>
