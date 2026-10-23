<?php /** Imagerie dans le dossier patient / la consultation : $p, $visitId, $imgStudies, $imgRequests */ use Addons\Clinique\ImagingService as IS;
$badge = ['a_rattacher' => 'danger', 'a_lire' => 'warning text-dark', 'lu' => 'info text-dark', 'valide' => 'success']; ?>
<link rel="stylesheet" href="<?= e(asset('addons/clinique/imagerie.css')) ?>">
<div class="card mb-3"><div class="card-header d-flex align-items-center gap-2"><span><i class="bi bi-radioactive"></i> <?= e(t('Imagerie')) ?></span>
  <?php if (can('clinique.imaging')): ?><button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-img-import data-patient="<?= (int) $p['id'] ?>"><i class="bi bi-upload"></i> <?= e(t('Importer des images')) ?></button><input type="file" multiple class="d-none" data-img-files><?php endif; ?>
  <?php if (can('clinique.doctor')): ?><button type="button" class="btn btn-sm btn-primary <?= can('clinique.imaging') ? '' : 'ms-auto' ?>" data-bs-toggle="collapse" data-bs-target="#imgReq"><i class="bi bi-clipboard2-plus"></i> <?= e(t('Demander un examen')) ?></button><?php endif; ?>
</div>
  <div class="img-progress small px-2 pt-1" data-img-progress></div>
  <?php if (can('clinique.doctor')): ?>
  <form method="post" action="<?= e(url('/clinique/imagerie/demandes')) ?>" class="collapse card-body border-bottom row g-2 small" id="imgReq"><?= csrf_field() ?>
    <input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>"><?php if (!empty($visitId)): ?><input type="hidden" name="visit_id" value="<?= (int) $visitId ?>"><?php endif; ?>
    <div class="col-md-4"><label class="form-label"><?= e(t('Type')) ?></label><select name="modality" class="form-select form-select-sm"><?php foreach (IS::MODALITIES as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-8"><label class="form-label required"><?= e(t('Examen demandé')) ?></label><input name="exam" class="form-control form-control-sm" maxlength="150" required placeholder="ex. Radiographie du thorax de face"></div>
    <div class="col-md-9"><label class="form-label"><?= e(t('Renseignements cliniques')) ?></label><input name="clinical_info" class="form-control form-control-sm" maxlength="500"></div>
    <div class="col-md-3"><label class="form-label"><?= e(t('Priorité')) ?></label><select name="priority" class="form-select form-select-sm"><option value="normal"><?= e(t('Normale')) ?></option><option value="urgent"><?= e(t('Urgente')) ?></option></select></div>
    <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-printer"></i> <?= e(t('Enregistrer et imprimer le bon')) ?></button></div>
  </form><?php endif; ?>
  <?php if ($imgRequests): ?><ul class="list-group list-group-flush small"><?php foreach ($imgRequests as $r): ?>
    <li class="list-group-item d-flex gap-2 align-items-center"><i class="bi bi-hourglass-split text-warning"></i> <b class="font-monospace"><?= e($r['number']) ?></b> <?= e($r['modality'] . ' — ' . $r['exam']) ?><?= $r['priority'] === 'urgent' ? ' <span class="badge bg-danger">URGENT</span>' : '' ?>
      <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= e(url('/clinique/imagerie/demandes/' . $r['id'] . '/imprimer')) ?>"><i class="bi bi-printer"></i></a></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if ($imgStudies): ?><div class="img-grid p-2"><?php foreach ($imgStudies as $s): ?>
    <a class="card img-card text-decoration-none" href="<?= e(url('/clinique/imagerie/examens/' . $s['id'])) ?>"><div class="img-thumb"><?php if ($s['first_image']): ?><img loading="lazy" src="<?= e(url('/clinique/imagerie/images/' . $s['first_image'] . '/miniature')) ?>" alt=""><?php else: ?><i class="bi bi-file-earmark-medical fs-1"></i><?php endif; ?>
      <span class="badge bg-dark mod"><?= e((string) $s['modality']) ?></span><span class="badge bg-<?= e($badge[$s['status']]) ?> st"><?= e(t(IS::STATUS[$s['status']])) ?></span></div>
      <div class="card-body p-2 small text-body"><div class="text-truncate"><?= e((string) ($s['description'] ?: '—')) ?></div><div class="text-secondary"><?= e(fdate($s['study_date'] ?: $s['created_at'], false)) ?> · <?= (int) $s['images'] ?> <?= e(t('image(s)')) ?></div>
      <?php if ($s['conclusion']): ?><div class="text-truncate fw-semibold" title="<?= e($s['conclusion']) ?>"><?= e($s['conclusion']) ?></div><?php endif; ?></div></a>
  <?php endforeach; ?></div>
  <?php elseif (!$imgRequests): ?><div class="card-body small text-secondary"><?= e(t('Aucun examen d\'imagerie.')) ?></div><?php endif; ?>
</div>
