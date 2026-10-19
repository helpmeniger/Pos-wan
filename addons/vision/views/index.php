<?php
$s = $stats;
$pct = $s['total'] ? round(100 * $s['learned'] / $s['total']) : 0;
?>
<div class="alert alert-light border small mb-3">
  <i class="bi bi-eye text-primary"></i> <b>Comment ça marche ?</b>
  <ul class="mb-0 mt-1">
    <li>Pour chaque article, prenez <b>5 à 10 photos</b> avec la caméra qui servira en caisse : face avant, dos, côtés, de près, de loin.</li>
    <li>En vente, touchez <b>« Reconnaître »</b> : POS Marwa propose les articles les plus ressemblants et le caissier confirme d'un geste.</li>
    <li>Chaque confirmation ou correction pendant une vente est <b>apprise automatiquement</b> : la reconnaissance s'améliore avec l'usage.</li>
    <li>Le calcul se fait dans le navigateur de chaque appareil (PC, tablette, smartphone du réseau), <b>sans Internet</b>. Le code-barres reste prioritaire quand il est visible.</li>
  </ul>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="text-secondary small">Articles appris</div>
    <div class="fs-4 fw-bold"><?= (int) $s['learned'] ?> <span class="fs-6 text-secondary">/ <?= (int) $s['total'] ?></span></div>
    <div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= (int) $pct ?>%"></div></div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="text-secondary small">Photos apprises</div>
    <div class="fs-4 fw-bold"><?= (int) $s['refs'] ?></div>
    <div class="small text-secondary"><?php foreach (Addons\Vision\VisionService::SOURCES as $k => $l): ?><?= e($l) ?> : <?= (int) ($s['by_source'][$k] ?? 0) ?><br><?php endforeach; ?></div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body">
    <div class="text-secondary small">Bonnes propositions (30 jours)</div>
    <div class="fs-4 fw-bold"><?= $s['rate'] === null ? '—' : (int) $s['rate'] . ' %' ?></div>
    <div class="small text-secondary"><?= (int) $s['uses'] ?> reconnaissance(s) · <?= (int) $s['fixed'] ?> corrigée(s) · <?= (int) $s['none'] ?> sans résultat</div>
  </div></div></div>
  <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body d-grid gap-2">
    <button type="button" class="btn btn-primary" id="visTest"><i class="bi bi-camera"></i> Tester la reconnaissance</button>
    <button type="button" class="btn btn-outline-primary" id="visFiches" data-count="<?= (int) $withImage ?>" <?= $withImage ? '' : 'disabled' ?>><i class="bi bi-images"></i> Apprendre les photos des fiches (<?= (int) $withImage ?>)</button>
    <button type="button" class="btn btn-outline-secondary" id="visSimilar"><i class="bi bi-intersect"></i> Articles qui se ressemblent</button>
  </div></div></div>
</div>
<div id="visProgress" class="alert alert-info d-none"></div>
<div id="visSimilarOut" class="d-none card mb-3"><div class="card-header"><i class="bi bi-intersect"></i> Articles faciles à confondre</div><div class="card-body small" data-out></div></div>

<?php if ($s['confused']): ?>
<div class="alert alert-warning small"><b>Confusions corrigées récemment</b> (ajoutez des photos des deux articles, sous plusieurs angles) :
  <?php foreach ($s['confused'] as $c): ?><br>« <?= e($c['suggested']) ?> » proposé au lieu de « <?= e($c['chosen']) ?> » — <?= (int) $c['n'] ?> fois<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <form method="get" class="d-flex flex-wrap gap-2 flex-grow-1">
          <input name="q" value="<?= e($q) ?>" class="form-control form-control-sm" style="max-width:220px" placeholder="Nom, référence, code-barres">
          <select name="category" class="form-select form-select-sm" style="max-width:180px"><option value="">Toutes catégories</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cat === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
          <select name="f" class="form-select form-select-sm" style="max-width:200px">
            <?php foreach (['' => 'Tous les articles', 'none' => 'Pas encore appris', 'few' => 'Moins de 3 photos', 'learned' => 'Déjà appris'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i> Filtrer</button>
        </form>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0 vis-table"><thead><tr><th></th><th>Article</th><th class="text-center">Photos</th><th class="text-end">Actions</th></tr></thead><tbody>
      <?php foreach ($p['rows'] as $r): $n = (int) $r['refs']; ?>
        <tr data-product="<?= (int) $r['id'] ?>" data-name="<?= e($r['name']) ?>">
          <td style="width:52px"><?php if ($r['image']): ?><img src="<?= e(upload_url($r['image'])) ?>" alt="" class="vis-mini"><?php else: ?><span class="vis-mini vis-mini-empty"><i class="bi bi-box"></i></span><?php endif; ?></td>
          <td><b><?= e($r['name']) ?></b><br><span class="small text-secondary"><?= e($r['reference']) ?><?= $r['barcode'] ? ' · ' . e($r['barcode']) : '' ?><?= $r['category'] ? ' · ' . e($r['category']) : '' ?> · <?= money($r['sale_price']) ?></span></td>
          <td class="text-center"><span class="badge <?= $n >= 5 ? 'bg-success' : ($n >= 3 ? 'bg-info text-dark' : ($n ? 'bg-warning text-dark' : 'bg-secondary')) ?>" data-count><?= $n ?></span><?php if ((int) $r['refs_sale']): ?><br><span class="small text-secondary" title="Appris pendant les ventes">dont <?= (int) $r['refs_sale'] ?> en vente</span><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <button type="button" class="btn btn-sm btn-primary" data-learn><i class="bi bi-camera"></i> Apprendre</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-show <?= $n ? '' : 'disabled' ?> title="Voir et supprimer les photos"><i class="bi bi-images"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$p['rows']): ?><tr><td colspan="4" class="text-center text-secondary py-4">Aucun article.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
    <?= App\Core\View::partial('partials/pagination', ['p' => $p]) ?>
  </div>

  <div class="col-xl-4">
    <div class="card"><div class="card-header"><i class="bi bi-sliders"></i> Réglages</div><div class="card-body">
      <form method="post" action="<?= e(url('/vision/settings')) ?>" class="small"><?= csrf_field() ?>
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="vision_apprendre_ventes" value="1" id="vsLearn" <?= setting('vision_apprendre_ventes', '1') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label" for="vsLearn"><b>Apprendre pendant les ventes</b> : chaque article confirmé ou corrigé par le caissier est mémorisé (les <input type="number" name="vision_max_ventes" min="1" max="50" value="<?= e(setting('vision_max_ventes', '12')) ?>" class="form-control form-control-sm d-inline-block" style="width:64px"> dernières photos par article).</label></div>
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="vision_auto" value="1" id="vsAuto" <?= setting('vision_auto', '0') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label" for="vsAuto"><b>Ajout automatique</b> activé par défaut sur les postes de caisse (chaque caissier peut le couper dans la fenêtre caméra). En continu, un article n'est ajouté que s'il est sûr sur 2 images de suite ; la caméra attend ensuite qu'il soit retiré ; un bouton « Annuler » permet de revenir en arrière. À n'activer qu'après avoir vérifié le taux de bonnes propositions.</label></div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label mb-0">Ressemblance minimale (0–1)</label><input name="vision_seuil_auto" class="form-control form-control-sm" inputmode="decimal" value="<?= e(setting('vision_seuil_auto', '0.80')) ?>"></div>
          <div class="col-6"><label class="form-label mb-0">Écart avec le 2ᵉ article</label><input name="vision_marge" class="form-control form-control-sm" inputmode="decimal" value="<?= e(setting('vision_marge', '0.06')) ?>"></div>
          <div class="col-12 small text-secondary mt-1">Article isolé de l'arrière-plan (fond appris) — échelle différente :</div>
          <div class="col-6"><label class="form-label mb-0">Ressemblance minimale (article isolé)</label><input name="vision_seuil_objet" class="form-control form-control-sm" inputmode="decimal" value="<?= e(setting('vision_seuil_objet', '0.45')) ?>"></div>
          <div class="col-6"><label class="form-label mb-0">Écart avec le 2ᵉ (article isolé)</label><input name="vision_marge_objet" class="form-control form-control-sm" inputmode="decimal" value="<?= e(setting('vision_marge_objet', '0.25')) ?>"></div>
        </div>
        <p class="text-secondary mt-2 mb-2">L'ajout automatique n'a lieu que si les deux conditions sont réunies. Plus les valeurs sont hautes, moins il y a d'erreurs (mais plus de confirmations manuelles). Entre deux articles déjà confondus (corrigés ou annulés au moins 2 fois), l'écart exigé est doublé automatiquement.</p>
        <p class="text-secondary mb-2"><b>Conseil :</b> sur chaque poste, ouvrez la caméra, videz le cadre et touchez « Apprendre le fond » : le comptoir et le mur ne seront plus jamais analysés comme un article, et seul l'article posé est reconnu.</p>
        <button class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> Enregistrer</button>
      </form>
    </div></div>
    <div class="card mt-3"><div class="card-header"><i class="bi bi-lightbulb"></i> Conseils pour de bons résultats</div><div class="card-body small">
      <ul class="mb-0 ps-3">
        <li>Photographiez à l'endroit et avec la caméra utilisés en caisse, sous l'éclairage habituel.</li>
        <li>L'article doit remplir le cadre jaune ; évitez les reflets et le contre-jour.</li>
        <li>Articles presque identiques (même marque, parfums différents) : photographiez la face qui les distingue, ou utilisez le code-barres.</li>
        <li>Un fond uni sous la caméra (tapis, plateau) améliore beaucoup la reconnaissance.</li>
        <li>La première ouverture sur un appareil télécharge l'intelligence (environ 10 Mo depuis le serveur local), ensuite elle reste en mémoire du navigateur.</li>
      </ul>
    </div></div>
  </div>
</div>

<div class="vis-modal" id="visModal" hidden>
  <div class="vis-box" role="dialog" aria-modal="true">
    <div class="vis-head"><b data-title></b><button type="button" class="pm-x" data-close aria-label="Fermer">✕</button></div>
    <div class="vis-body">
      <div class="vis-live">
        <div class="vis-view" data-view><video playsinline muted></video><div class="vis-aim"></div><div class="vis-flash"></div></div>
        <div class="vis-cutbox"><div class="vis-cut" title="Ce que l'intelligence apprend : l'article seul, sans l'arrière-plan"><canvas data-cut width="224" height="224"></canvas></div>
          <div class="small" data-cutinfo>Article détouré</div></div>
      </div>
      <div class="d-flex flex-wrap gap-2 align-items-center small my-1">
        <label class="mb-0"><input type="checkbox" data-isoler checked> Isoler l'article (ignorer le mur, le comptoir, le rayon)</label>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-fond><i class="bi bi-bricks"></i> Apprendre le fond (cadre vide)</button>
        <span data-fondst class="text-secondary"></span>
      </div>
      <div class="vis-msg" data-msg></div>
      <div class="d-flex flex-wrap gap-2 my-2">
        <button type="button" class="btn btn-primary btn-lg flex-grow-1" data-shot><i class="bi bi-camera"></i> Prendre la photo <small class="opacity-75">(Espace)</small></button>
        <label class="btn btn-outline-secondary btn-lg mb-0"><i class="bi bi-image"></i> Photos…<input type="file" accept="image/*" multiple hidden data-files></label>
      </div>
      <div class="vis-tip small text-secondary" data-tip></div>
      <div class="vis-thumbs" data-thumbs></div>
    </div>
  </div>
</div>
