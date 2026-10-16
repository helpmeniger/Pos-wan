<script type="application/json" id="labels-data"><?= json_encode(['preselected' => $preselected, 'default' => $default, 'templates' => array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'config' => json_decode($t['config'], true)], $templates), 'presets' => $presets], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<form method="post" action="<?= e(url('/products/labels/render')) ?>" target="labelPreview" id="labelForm"><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-box-seam"></i> 1. Articles</div><div class="card-body">
      <div class="input-group input-group-sm mb-2">
        <input id="lblSearch" class="form-control" placeholder="Nom, référence ou code-barres" autocomplete="off">
        <button type="button" class="btn btn-outline-secondary" id="lblCamera" title="Scanner pour ajouter"><i class="bi bi-camera"></i></button>
      </div>
      <div id="lblResults" class="list-group small mb-2"></div>
      <div class="input-group input-group-sm mb-2">
        <select id="lblCategory" class="form-select"><option value="">Toute une catégorie…</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <button type="button" class="btn btn-outline-primary" id="lblAddCat">Ajouter</button>
      </div>
      <div class="d-flex small align-items-center mb-1"><b id="lblCount">0 article · 0 étiquette</b>
        <span class="ms-auto">Tous à <input type="number" min="1" max="500" value="1" id="lblAllQty" class="form-control form-control-sm d-inline-block" style="width:64px"> <button type="button" class="btn btn-sm btn-link p-0" id="lblApplyAll">appliquer</button> · <button type="button" class="btn btn-sm btn-link p-0 text-danger" id="lblClear">vider</button></span></div>
      <div class="lbl-list" id="lblList"></div>
      <div class="small text-secondary mt-1">Scannez un code (douchette dans le champ ci-dessus, ou caméra) pour ajouter l'article ; un nouveau scan ajoute une étiquette.</div>
    </div></div>
  </div>
  <div class="col-xl-3">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-ui-checks"></i> 2. Informations imprimées</div><div class="card-body">
      <?php foreach ($fields as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="cfg[fields][]" value="<?= e($k) ?>" id="fld_<?= e($k) ?>" data-cfg><label class="form-check-label" for="fld_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?>
      <div class="small text-secondary mt-2">Combinaisons rapides :</div>
      <div class="d-flex flex-wrap gap-1 mt-1"><?php foreach ($presets as $l => $fl): ?><button type="button" class="btn btn-sm btn-outline-secondary py-0" data-preset="<?= e(implode(',', $fl)) ?>"><?= e($l) ?></button><?php endforeach; ?></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-rulers"></i> 3. Format (mm)</div><div class="card-body row g-2 small">
      <div class="col-12"><select name="cfg[paper]" class="form-select form-select-sm" data-cfg id="cfgPaper"><option value="a4">Planche A4 (imprimante bureau, feuilles d'étiquettes)</option><option value="roll">Rouleau / imprimante d'étiquettes (une étiquette par page)</option></select></div>
      <div class="col-6"><label class="mb-0">Largeur</label><input name="cfg[width]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6"><label class="mb-0">Hauteur</label><input name="cfg[height]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6 a4-only"><label class="mb-0">Colonnes</label><input name="cfg[cols]" class="form-control form-control-sm" data-cfg inputmode="numeric"></div>
      <div class="col-6"><label class="mb-0">Taille du texte (pt)</label><input name="cfg[font]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6 a4-only"><label class="mb-0">Marge haut</label><input name="cfg[margin_top]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6 a4-only"><label class="mb-0">Marge gauche</label><input name="cfg[margin_left]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6 a4-only"><label class="mb-0">Espace horizontal</label><input name="cfg[gap_x]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6 a4-only"><label class="mb-0">Espace vertical</label><input name="cfg[gap_y]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6"><label class="mb-0">Hauteur code-barres</label><input name="cfg[barcode_height]" class="form-control form-control-sm" data-cfg inputmode="decimal"></div>
      <div class="col-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="cfg[border]" value="1" id="cfgBorder" data-cfg><label class="form-check-label" for="cfgBorder">Bordure (réglage)</label></div></div>
    </div></div>
    <div class="card mb-3"><div class="card-header"><i class="bi bi-bookmark"></i> Modèles</div><div class="card-body">
      <select id="tplSelect" class="form-select form-select-sm mb-2"><option value="">Charger un modèle…</option></select>
      <?php if (can('products.manage')): ?>
      <div class="input-group input-group-sm"><input id="tplName" class="form-control" placeholder="Nom du modèle" maxlength="80"><button type="button" class="btn btn-outline-primary" id="tplSave">Enregistrer</button></div>
      <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" id="tplDefault"><label class="form-check-label" for="tplDefault">Utiliser par défaut</label></div>
      <button type="button" class="btn btn-sm btn-link text-danger px-0" id="tplDelete">Supprimer le modèle chargé</button>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-xl-5">
    <div class="card"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><span><i class="bi bi-eye"></i> 4. Aperçu</span>
      <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="lblTest" title="Une étiquette bordée avec ses dimensions, pour régler l'imprimante">Étiquette de calibrage</button>
      <button type="button" class="btn btn-sm btn-primary" id="lblPrint"><i class="bi bi-printer"></i> Imprimer</button></div>
      <iframe name="labelPreview" id="labelPreview" class="lbl-preview" title="Aperçu des étiquettes"></iframe>
      <div class="card-body small text-secondary border-top">
        <b>Réglages d'impression</b> : échelle 100 % (« taille réelle », pas « ajuster à la page »), marges « aucune », en-têtes et pieds de page désactivés.
        Imprimante d'étiquettes thermique (Xprinter, Zebra, TSC, Godex…) : dans le pilote Windows, réglez le format du papier sur la taille de l'étiquette (ex. 40 × 30 mm) et le mode « étiquettes avec espace » (gap) ; imprimez d'abord l'étiquette de calibrage.
      </div>
    </div>
  </div>
</div>
<div id="lblHidden"></div>
<input type="hidden" name="print" value="0" id="lblPrintFlag"><input type="hidden" name="test" value="0" id="lblTestFlag">
</form>
