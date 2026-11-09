<?php /** Doublons d'articles : liste des groupes détectés. */ ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <form method="get" class="d-flex flex-wrap gap-3 align-items-center">
    <div class="form-check"><input class="form-check-input" type="checkbox" name="archives" value="1" id="dArch" <?= $arch ? 'checked' : '' ?> data-autosubmit><label class="form-check-label" for="dArch">Inclure les articles archivés</label></div>
    <input type="hidden" name="ressemblants" value="0"><div class="form-check"><input class="form-check-input" type="checkbox" name="ressemblants" value="1" id="dSim" <?= $withSimilar ? 'checked' : '' ?> data-autosubmit><label class="form-check-label" for="dSim">Chercher aussi les noms ressemblants</label></div>
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Analyser</button>
  </form>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= e(url('/doublons/journal')) ?>"><i class="bi bi-clock-history"></i> Journal des fusions (<?= (int) $merged ?>)</a>
</div>
<div class="row g-2 mb-3">
  <div class="col-md-4"><div class="card h-100"><div class="card-body py-2"><div class="small text-secondary">Articles analysés</div><div class="fs-4 fw-bold"><?= (int) $scanned ?></div></div></div></div>
  <div class="col-md-4"><div class="card h-100 border-danger"><div class="card-body py-2"><div class="small text-secondary">Doublons certains (même nom)</div><div class="fs-4 fw-bold text-danger"><?= (int) $totalSame ?> groupe(s)</div></div></div></div>
  <div class="col-md-4"><div class="card h-100 border-warning"><div class="card-body py-2"><div class="small text-secondary">Noms ressemblants à vérifier</div><div class="fs-4 fw-bold text-warning-emphasis"><?= $withSimilar ? (int) $totalSimilar . ' paire(s)' : '—' ?></div></div></div></div>
</div>
<div class="alert alert-info small"><i class="bi bi-shield-check"></i> Pour chaque groupe, le module compare les fiches et <b>conseille de conserver la plus utilisée</b> : article actif, puis le plus de ventes, de mouvements de stock, de stock, avec code-barres et photo. Vous pouvez changer ce choix. Une <b>sauvegarde de la base</b> est faite avant chaque fusion, et chaque doublon supprimé est copié dans le journal.</div>
<?php if (!$same && !$similar): ?>
  <div class="text-center text-secondary py-5"><i class="bi bi-check2-circle fs-1 text-success d-block"></i>Aucun doublon trouvé.</div>
<?php endif; ?>
<?php if ($same): ?>
  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h2 class="h5 mb-0">Doublons certains</h2><span class="text-secondary small">même nom (accents, majuscules, espaces et ponctuation ignorés)<?= $totalSame > count($same) ? ' · ' . count($same) . ' premiers affichés' : '' ?></span>
    <form method="post" action="<?= e(url('/doublons/fusionner-tout')) ?>" class="ms-auto" data-confirm="Fusionner automatiquement les <?= (int) $totalSame ?> groupe(s) de doublons certains, en conservant à chaque fois l'article le plus utilisé ? Une sauvegarde est faite avant."><?= csrf_field() ?><input type="hidden" name="archives" value="<?= $arch ? '1' : '' ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-magic"></i> Tout fusionner automatiquement</button></form>
  </div>
  <?php foreach ($same as $gi => $g) { $kind = 'same'; include __DIR__ . '/_group.php'; } ?>
<?php endif; ?>
<?php if ($similar): ?>
  <h2 class="h5 mt-4">Noms ressemblants à vérifier</h2>
  <p class="small text-secondary">Une ou deux lettres de différence, mêmes chiffres (« 400 g » et « 200 g » ne sont jamais rapprochés). Rien n'est coché d'office : cochez seulement s'il s'agit vraiment du même article.</p>
  <?php foreach ($similar as $gi => $g) { $kind = 'similar'; include __DIR__ . '/_group.php'; } ?>
<?php endif; ?>
