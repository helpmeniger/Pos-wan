<?php $pid = (int) $p['id']; $usable = count(array_filter($photos, static fn ($x) => $x['usage_split'] !== 'exclue')); ?>
<div class="row g-3">
<div class="col-lg-4">
  <div class="card mb-3"><div class="card-body">
    <?php if ($p['is_demo']): ?><div class="badge demo-badge mb-2">Donnée de démonstration fictive</div><?php endif; ?>
    <dl class="row small mb-0">
      <dt class="col-5">Catégorie</dt><dd class="col-7"><?= e($category ?? '—') ?></dd>
      <dt class="col-5">Marque</dt><dd class="col-7"><?= e($p['brand'] ?: '—') ?></dd>
      <dt class="col-5">Référence</dt><dd class="col-7"><?= e($p['reference'] ?: '—') ?></dd>
      <dt class="col-5">Code-barres</dt><dd class="col-7"><code><?= e($p['barcode'] ?: '—') ?></code></dd>
      <dt class="col-5">Prix achat / vente</dt><dd class="col-7"><?= money($p['purchase_price']) ?> / <?= money($p['sale_price']) ?></dd>
      <dt class="col-5">Stock théorique</dt><dd class="col-7"><?= qty($p['stock_quantity']) ?> <?= e($p['unit']) ?> (seuil <?= qty($p['stock_alert_threshold']) ?>)</dd>
      <dt class="col-5">Confiance min.</dt><dd class="col-7"><?= pct($p['min_confidence']) ?></dd>
      <dt class="col-5">Reconnaissance</dt><dd class="col-7"><?= e(label('recognition', $p['recognition_status'])) ?><?= $p['detection_enabled'] ? ' <span class="badge bg-success">détection activée</span>' : '' ?></dd>
      <dt class="col-5">Enregistré le</dt><dd class="col-7"><?= dt($p['created_at'], 'd/m/Y') ?></dd>
      <dt class="col-5">Statut</dt><dd class="col-7"><?= $p['is_active'] ? 'Actif' : 'Inactif' ?></dd>
    </dl>
  </div>
  <?php if (can('products.manage')): ?><div class="card-footer bg-white d-flex gap-2">
    <a href="<?= url("products/$pid/edit") ?>" class="btn btn-sm btn-outline-primary">Modifier</a>
    <form method="post" action="<?= url("products/$pid/delete") ?>" data-confirm="Archiver ce produit ?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger">Archiver</button></form>
  </div><?php endif; ?></div>

  <div class="card mb-3"><div class="card-header bg-white">Préparation de la reconnaissance IA</div><div class="card-body small">
    <div class="progress mb-2" style="height:8px"><div class="progress-bar <?= $usable >= $minImages ? 'bg-success' : 'bg-warning' ?>" style="width:<?= min(100, (int) round(100 * $usable / max(1, $recImages))) ?>%"></div></div>
    <p class="mb-2"><?= $usable ?> photo(s) utilisable(s) — minimum <?= $minImages ?>, recommandé <?= $recImages ?>.</p>
    <p class="text-muted">Variez : face avant, arrière, côtés, dessus, dessous, différentes distances et éclairages, et les variantes de l'emballage. Une photo sur quatre est mise de côté automatiquement pour TESTER la reconnaissance (sauf si vous marquez vos propres photos « test »).</p>
    <?php if (can('ai.manage')): ?>
    <form method="post" action="<?= url("products/$pid/prepare") ?>"><?= csrf_field() ?>
      <button class="btn btn-primary w-100" <?= $usable < $minImages ? 'disabled' : '' ?>><i class="bi bi-cpu me-1"></i>Enregistrer l'article et préparer sa reconnaissance IA</button></form>
    <?php endif; ?>
  </div></div>

  <?php if ($validation): ?><div class="card"><div class="card-header bg-white">Résultats de validation (photos de test)</div><ul class="list-group list-group-flush small">
    <?php foreach ($validation as $v): ?><li class="list-group-item">Version <?= (int) $v['version_number'] ?> (<?= e(label('version', $v['vstatus'])) ?>) :
      <strong><?= (int) $v['correct'] ?>/<?= (int) $v['test_images'] ?></strong> correctes, <?= (int) $v['wrong'] ?> erronée(s), <?= (int) $v['unidentified'] ?> non identifiée(s)
      <?= $v['confused_name'] ? '<br><span class="text-warning">Confondu avec : ' . e($v['confused_name']) . '</span>' : '' ?></li><?php endforeach; ?>
  </ul></div><?php endif; ?>
</div>

<div class="col-lg-8">
  <?php if (can('products.manage')): ?>
  <div class="card mb-3"><div class="card-header bg-white">Ajouter des photos de référence</div><div class="card-body">
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabImport" type="button">Importer / smartphone</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCam" type="button">Webcam</button></li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="tabImport">
        <form method="post" enctype="multipart/form-data" action="<?= url("products/$pid/photos") ?>" class="row g-2 align-items-end"><?= csrf_field() ?>
          <div class="col-md-6"><label class="form-label small">Photos (JPEG, PNG, WebP — <?= (int) $maxMb ?> Mo max chacune)</label>
            <input type="file" name="photos[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple required></div>
          <div class="col-md-3"><label class="form-label small">Vue</label><select name="view_angle" class="form-select">
            <?php foreach (['autre', 'avant', 'arriere', 'gauche', 'droite', 'dessus', 'dessous'] as $a): ?><option value="<?= $a ?>"><?= e(ucfirst($a === 'arriere' ? 'arrière' : $a)) ?></option><?php endforeach; ?></select></div>
          <input type="hidden" name="source" value="import">
          <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>Envoyer</button></div>
        </form>
        <div class="form-text mt-2"><i class="bi bi-phone"></i> Sur smartphone, ce bouton permet de <strong>prendre la photo directement avec l'appareil photo</strong> du téléphone (connecté au même réseau Wi-Fi).</div>
        <form method="post" enctype="multipart/form-data" action="<?= url("products/$pid/photos") ?>" class="mt-2 d-md-none"><?= csrf_field() ?>
          <input type="hidden" name="source" value="smartphone"><input type="hidden" name="view_angle" value="autre">
          <label class="btn btn-outline-primary w-100"><i class="bi bi-camera me-1"></i>Prendre une photo<input type="file" name="photos[]" accept="image/*" capture="environment" hidden onchange="this.form.submit()"></label>
        </form>
      </div>
      <div class="tab-pane fade" id="tabCam">
        <div class="row g-2">
          <div class="col-md-8"><video id="camVideo" autoplay playsinline muted class="w-100 rounded bg-dark" style="aspect-ratio:4/3"></video></div>
          <div class="col-md-4 d-flex flex-column gap-2">
            <select id="camSelect" class="form-select form-select-sm"></select>
            <select id="camAngle" class="form-select form-select-sm"><?php foreach (['avant', 'arriere', 'gauche', 'droite', 'dessus', 'dessous', 'autre'] as $a): ?><option value="<?= $a ?>">Vue : <?= e($a === 'arriere' ? 'arrière' : $a) ?></option><?php endforeach; ?></select>
            <button class="btn btn-outline-secondary btn-sm" id="camStart" type="button"><i class="bi bi-camera-video me-1"></i>Activer la caméra</button>
            <button class="btn btn-primary" id="camShot" type="button" data-pid="<?= $pid ?>" disabled><i class="bi bi-camera me-1"></i>Prendre la photo</button>
            <div id="camMsg" class="small text-muted">L'accès à la caméra du navigateur nécessite HTTPS, sauf sur l'ordinateur serveur (localhost).</div>
          </div>
        </div>
      </div>
    </div>
  </div></div>
  <?php endif; ?>

  <div class="card"><div class="card-header bg-white d-flex justify-content-between"><span>Photos de référence (<?= count($photos) ?>)</span><small class="text-muted">Supprimez les images floues ou inutilisables</small></div>
  <div class="card-body"><div class="row g-3">
  <?php foreach ($photos as $ph): $issues = $ph['quality_notes']; ?>
    <div class="col-6 col-md-4 col-xl-3"><div class="photo-thumb border rounded p-1 <?= $ph['usage_split'] === 'exclue' ? 'opacity-50' : '' ?>">
      <img src="<?= url('media/product-image/' . (int) $ph['id']) ?>" alt="Photo" loading="lazy">
      <div class="small mt-1">Qualité : <?= $ph['quality_score'] !== null ? (int) round($ph['quality_score'] * 100) . ' %' : '—' ?>
        <?= $issues ? '<br><span class="text-warning">' . e($issues) . '</span>' : '' ?></div>
      <?php if (can('products.manage')): ?>
      <form method="post" action="<?= url("products/$pid/photos/" . (int) $ph['id']) ?>" class="mt-1"><?= csrf_field() ?>
        <select name="view_angle" class="form-select form-select-sm mb-1"><?php foreach (['avant', 'arriere', 'gauche', 'droite', 'dessus', 'dessous', 'autre'] as $a): ?><option value="<?= $a ?>" <?= $ph['view_angle'] === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select>
        <select name="usage_split" class="form-select form-select-sm mb-1"><option value="reference" <?= $ph['usage_split'] === 'reference' ? 'selected' : '' ?>>Référence</option><option value="test" <?= $ph['usage_split'] === 'test' ? 'selected' : '' ?>>Test (validation)</option><option value="exclue" <?= $ph['usage_split'] === 'exclue' ? 'selected' : '' ?>>Exclue</option></select>
        <input name="annotation" class="form-control form-control-sm mb-1" placeholder="Annotation" maxlength="255" value="<?= e($ph['annotation']) ?>">
        <div class="d-flex gap-1"><button class="btn btn-sm btn-outline-primary flex-fill">OK</button>
          <button class="btn btn-sm btn-outline-danger" formaction="<?= url("products/$pid/photos/" . (int) $ph['id'] . '/delete') ?>" onclick="return confirm('Supprimer cette photo ?')"><i class="bi bi-trash"></i></button></div>
      </form>
      <?php endif; ?>
    </div></div>
  <?php endforeach; if (!$photos): ?><div class="col-12 text-muted">Aucune photo pour l'instant.</div><?php endif; ?>
  </div></div></div>
</div>
</div>
