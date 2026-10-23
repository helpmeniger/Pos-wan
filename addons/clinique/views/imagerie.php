<?php use Addons\Clinique\ImagingService as IS;
$tab = (string) ($_GET['onglet'] ?? 'examens');
$badge = ['a_rattacher' => 'danger', 'a_lire' => 'warning text-dark', 'lu' => 'info text-dark', 'valide' => 'success'];
?>
<link rel="stylesheet" href="<?= e(asset('addons/clinique/imagerie.css')) ?>">
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'examens' ? 'active' : '' ?>" href="<?= e(url('/clinique/imagerie')) ?>"><i class="bi bi-images"></i> <?= e(t('Examens')) ?></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'demandes' ? 'active' : '' ?>" href="<?= e(url('/clinique/imagerie', ['onglet' => 'demandes'])) ?>"><i class="bi bi-clipboard2-plus"></i> <?= e(t('Demandes en attente')) ?> <span class="badge bg-secondary"><?= count($requests) ?></span></a></li>
  <?php if (can('clinique.imaging')): ?><li class="nav-item"><a class="nav-link <?= $tab === 'appareils' ? 'active' : '' ?>" href="<?= e(url('/clinique/imagerie', ['onglet' => 'appareils'])) ?>"><i class="bi bi-hdd-network"></i> <?= e(t('Appareils et réception')) ?>
    <span class="badge bg-<?= $receiver['running'] ? 'success' : 'danger' ?>"><?= e($receiver['running'] ? t('actif') : t('arrêté')) ?></span></a></li><?php endif; ?>
</ul>

<?php if ($tab === 'demandes'): ?>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>N° de demande</th><th><?= e(t('Patient')) ?></th><th><?= e(t('Examen')) ?></th><th><?= e(t('Renseignements cliniques')) ?></th><th><?= e(t('Demandé par')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($requests as $r): ?>
  <tr class="<?= $r['priority'] === 'urgent' ? 'table-danger' : '' ?>"><td class="fw-semibold font-monospace"><?= e($r['number']) ?><?= $r['priority'] === 'urgent' ? ' <span class="badge bg-danger">URGENT</span>' : '' ?><br><span class="small text-secondary"><?= e(fdate($r['created_at'])) ?></span></td>
  <td><a href="<?= e(url('/clinique/patients/' . $r['patient_id'])) ?>"><?= e($r['last_name'] . ' ' . $r['first_name']) ?></a><br><span class="small font-monospace"><?= e($r['patient_number']) ?></span></td>
  <td><?= e($r['modality']) ?> — <?= e($r['exam']) ?></td><td class="small"><?= e((string) $r['clinical_info']) ?></td><td class="small"><?= e($r['requester']) ?></td>
  <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/clinique/imagerie/demandes/' . $r['id'] . '/imprimer')) ?>"><i class="bi bi-printer"></i></a>
  <?php if (can('clinique.doctor')): ?><form method="post" action="<?= e(url('/clinique/imagerie/demandes/' . $r['id'] . '/annuler')) ?>" class="d-inline" data-confirm="<?= e(t('Annuler cette demande ?')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$requests): ?><tr><td colspan="6" class="text-center text-secondary py-4"><?= e(t('Aucune demande en attente. Les demandes se font depuis la consultation ou le dossier du patient.')) ?></td></tr><?php endif; ?>
</tbody></table></div></div>

<?php elseif ($tab === 'appareils' && can('clinique.imaging')): ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3 <?= $receiver['running'] ? 'border-success' : 'border-danger' ?>"><div class="card-header"><i class="bi bi-broadcast"></i> <?= e(t('Récepteur d\'images (DICOM)')) ?></div><div class="card-body small">
      <?php if ($receiver['running']): ?>
        <div class="text-success fw-semibold mb-2"><i class="bi bi-check-circle"></i> <?= e(t('Actif depuis')) ?> <?= e(fdate($receiver['started'] ?? null)) ?> — <?= (int) ($receiver['received'] ?? 0) ?> <?= e(t('image(s) reçue(s)')) ?><?= !empty($receiver['last']) ? ', ' . e(t('dernière à')) . ' ' . e(substr((string) $receiver['last'], 11, 5)) : '' ?></div>
      <?php else: ?>
        <div class="text-danger fw-semibold mb-2"><i class="bi bi-exclamation-triangle"></i> <?= e(t('Arrêté : les appareils ne peuvent pas envoyer leurs images.')) ?></div>
        <div class="mb-2"><?= e(t('Démarrez-le sur le PC qui héberge POS Marwa : double-cliquez')) ?> <code>windows\imagerie-serveur.bat</code> <?= e(t('(démarrage automatique avec POS Marwa ensuite).')) ?></div>
      <?php endif; ?>
      <div class="fw-semibold"><?= e(t('À régler dans chaque appareil (destination « PACS », « DICOM Send » ou « Storage ») :')) ?></div>
      <table class="table table-sm mb-2"><tr><td><?= e(t('Adresse IP')) ?></td><td class="font-monospace fw-bold"><?= e(implode(' / ', $ips ?: ['?'])) ?></td></tr><tr><td><?= e(t('Port')) ?></td><td class="font-monospace fw-bold"><?= (int) $cfg['port'] ?></td></tr><tr><td><?= e(t('Titre AE (Called AE)')) ?></td><td class="font-monospace fw-bold"><?= e($cfg['aet']) ?></td></tr></table>
      <div><?= e(t('Pour que l\'examen arrive directement dans le bon dossier, saisissez dans l\'appareil le n° de demande (Accession Number) ou le n° de dossier du patient (Patient ID). Sinon, l\'examen arrive « à rattacher ».')) ?></div>
      <div class="mt-2"><?= e(t('Appareil sans réseau DICOM : copiez les fichiers (DICOM, JPEG, PNG) dans le dossier partagé')) ?> <code>storage\imagerie\arrivee</code>.</div>
    </div></div>
    <form method="post" action="<?= e(url('/clinique/imagerie/reglages')) ?>" class="card"><?= csrf_field() ?><div class="card-header"><?= e(t('Réglages du récepteur')) ?></div><div class="card-body row g-2 small">
      <div class="col-6"><label class="form-label"><?= e(t('Titre AE de POS Marwa')) ?></label><input name="aet" class="form-control form-control-sm font-monospace" maxlength="16" value="<?= e($cfg['aet']) ?>"></div>
      <div class="col-6"><label class="form-label"><?= e(t('Port')) ?></label><input name="port" type="number" min="104" max="65535" class="form-control form-control-sm" value="<?= (int) $cfg['port'] ?>"></div>
      <div class="col-12"><label><input type="checkbox" name="unknown" value="1" <?= $cfg['unknown'] ? 'checked' : '' ?>> <?= e(t('Accepter un nouvel appareil du réseau sans le déclarer (il est ajouté à la liste, désactivable)')) ?></label></div>
      <div class="col-12"><button class="btn btn-sm btn-primary"><?= e(t('Enregistrer')) ?></button></div>
    </div></form>
  </div>
  <div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-pc-display"></i> <?= e(t('Appareils d\'imagerie')) ?></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead><tr><th><?= e(t('Nom')) ?></th><th><?= e(t('Type')) ?></th><th>AE</th><th>IP</th><th class="num"><?= e(t('Images')) ?></th><th><?= e(t('Dernier envoi')) ?></th><th></th></tr></thead><tbody>
    <?php foreach ($devices as $d): ?>
      <tr class="<?= (int) $d['active'] ? '' : 'text-secondary' ?>"><td colspan="7" class="p-0"><form method="post" action="<?= e(url('/clinique/imagerie/appareils/' . $d['id'])) ?>" class="d-flex flex-wrap gap-1 p-1 align-items-center"><?= csrf_field() ?>
        <input name="name" class="form-control form-control-sm" style="width:11rem" value="<?= e($d['name']) ?>"><?= (int) $d['auto_detected'] ? '<span class="badge bg-warning text-dark" title="' . e(t('Détecté automatiquement')) . '">' . e(t('nouveau')) . '</span>' : '' ?>
        <select name="modality" class="form-select form-select-sm" style="width:9rem"><?php foreach (IS::MODALITIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $d['modality'] === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select>
        <input name="ae_title" class="form-control form-control-sm font-monospace" style="width:8rem" value="<?= e((string) $d['ae_title']) ?>" placeholder="AE">
        <input name="ip" class="form-control form-control-sm font-monospace" style="width:8rem" value="<?= e((string) $d['ip']) ?>" placeholder="IP">
        <span class="num" style="width:3rem"><?= (int) $d['images_count'] ?></span><span style="width:7rem"><?= e($d['last_seen'] ? fdate($d['last_seen']) : '—') ?></span>
        <label class="text-nowrap"><input type="checkbox" name="active" value="1" <?= (int) $d['active'] ? 'checked' : '' ?>> <?= e(t('actif')) ?></label>
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check2"></i></button></form></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="7" class="p-0"><form method="post" action="<?= e(url('/clinique/imagerie/appareils')) ?>" class="d-flex flex-wrap gap-1 p-1 align-items-center bg-body-tertiary"><?= csrf_field() ?>
      <input name="name" class="form-control form-control-sm" style="width:11rem" placeholder="<?= e(t('Nouvel appareil')) ?>" required>
      <select name="modality" class="form-select form-select-sm" style="width:9rem"><?php foreach (IS::MODALITIES as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select>
      <input name="ae_title" class="form-control form-control-sm font-monospace" style="width:8rem" placeholder="AE"><input name="ip" class="form-control form-control-sm font-monospace" style="width:8rem" placeholder="IP">
      <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> <?= e(t('Ajouter')) ?></button></form></td></tr>
    </tbody></table></div></div></div>
</div>

<?php else: ?>
<div class="d-flex flex-wrap gap-1 mb-2 align-items-center">
  <?php foreach (['' => 'Tous'] + IS::STATUS as $k => $l): ?><a class="btn btn-sm <?= $status === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e(url('/clinique/imagerie', array_filter(['statut' => $k, 'q' => $q]))) ?>"><?= e(t($l)) ?><?= $k && !empty($counts[$k]) ? ' <span class="badge bg-' . e(explode(' ', $badge[$k])[0]) . '">' . (int) $counts[$k] . '</span>' : '' ?></a><?php endforeach; ?>
  <form class="d-flex gap-1 ms-auto" method="get"><input type="hidden" name="statut" value="<?= e($status) ?>"><input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="<?= e(t('Patient, n° de dossier, n° de demande')) ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></form>
  <?php if (can('clinique.imaging')): ?><button type="button" class="btn btn-sm btn-primary" data-img-import><i class="bi bi-upload"></i> <?= e(t('Importer des images')) ?></button><input type="file" multiple class="d-none" data-img-files><?php endif; ?>
</div>
<div class="img-progress small mb-2" data-img-progress></div>
<div class="img-grid">
<?php foreach ($studies as $s): ?>
  <a class="card img-card text-decoration-none" href="<?= e(url('/clinique/imagerie/examens/' . $s['id'])) ?>">
    <div class="img-thumb"><?php if ($s['first_image']): ?><img loading="lazy" src="<?= e(url('/clinique/imagerie/images/' . $s['first_image'] . '/miniature')) ?>" alt=""><?php else: ?><i class="bi bi-file-earmark-medical fs-1"></i><?php endif; ?>
      <span class="badge bg-dark mod"><?= e((string) $s['modality']) ?></span><span class="badge bg-<?= e($badge[$s['status']]) ?> st"><?= e(t(IS::STATUS[$s['status']])) ?></span></div>
    <div class="card-body p-2 small text-body">
      <div class="fw-semibold text-truncate"><?= $s['patient_id'] ? e($s['last_name'] . ' ' . $s['first_name']) : '<span class="text-danger">' . e(t('Non rattaché')) . '</span> · ' . e((string) $s['dicom_patient_name']) ?></div>
      <div class="text-truncate text-secondary"><?= e((string) ($s['description'] ?: '—')) ?></div>
      <div class="d-flex justify-content-between text-secondary"><span><?= e(fdate($s['study_date'] ?: $s['created_at'], false)) ?></span><span><?= (int) $s['images'] ?> <?= e(t('image(s)')) ?></span></div>
    </div></a>
<?php endforeach; ?>
<?php if (!$studies): ?><div class="text-secondary p-4"><?= e(t('Aucun examen. Les images arrivent ici dès qu\'un appareil les envoie, ou par « Importer des images ».')) ?></div><?php endif; ?>
</div>
<?php endif; ?>
