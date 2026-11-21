<?php /** Personnalisation d'un modèle, avec aperçu immédiat. */
use Addons\Modeles\Modeles as M;
$o = $m['options']; $type = $m['type'];
$action = $m['id'] ? url('/modeles/' . $m['id']) : url('/modeles');
$groupes = [
    'En-tête' => ['titre', 'slogan', 'logo', 'adresse', 'nif', 'cadre_numero'],
    'Présentation' => ['police', 'taille', 'couleur', 'separateur', 'majuscules', 'reference'],
    'Informations affichées' => ['date', 'caisse', 'caissier', 'client', 'libelle_total', 'montant_lettres', 'solde_client', 'nb_articles', 'qr'],
    'Bas du document' => ['signature', 'conditions', 'pied', 'pied2', 'filigrane'],
];
$kinds = $type === 'a4' ? ['invoice' => 'Facture', 'quote' => 'Devis', 'delivery' => 'Bon de livraison'] : ['sale' => 'Vente payée', 'credit' => 'Vente à crédit'];
?>
<div class="mb-2"><a href="<?= e(url('/modeles')) ?>" class="small"><i class="bi bi-arrow-left"></i> Modèles de tickets et factures</a></div>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" action="<?= e($action) ?>" id="mdlForm" data-preview="<?= e(url('/modeles/apercu')) ?>" class="card"><?= csrf_field() ?>
      <input type="hidden" name="type" value="<?= e($type) ?>">
      <div class="card-body">
        <div class="mb-2"><label class="form-label required" for="mNom">Nom du modèle</label><input class="form-control" id="mNom" name="nom" value="<?= e($m['nom']) ?>" maxlength="80" required placeholder="ex. Ticket grossiste"></div>
        <div class="mb-3"><label class="form-label" for="mBase">Mise en page (<?= $type === 'a4' ? 'A4' : 'ticket' ?>)</label>
          <select class="form-select" id="mBase" name="base"><?php foreach (M::BASES[$type] as $b => [$bl, $bd]): ?><option value="<?= e($b) ?>" <?= $m['base'] === $b ? 'selected' : '' ?> title="<?= e($bd) ?>"><?= e($bl) ?></option><?php endforeach; ?></select>
          <div class="form-text"><?= e(M::BASES[$type][$m['base']][1]) ?><?= str_starts_with($m['base'], 'core') ? ' Les modèles d\'origine n\'affichent pas toutes les options (slogan, solde, signature, conditions) : l\'aperçu montre le résultat réel.' : '' ?></div></div>
        <?php foreach ($groupes as $g => $keys): $keys = array_values(array_filter($keys, fn($k) => array_key_exists($k, $o))); if (!$keys) continue; ?>
          <fieldset class="mb-3"><legend class="h6 border-bottom pb-1"><?= e($g) ?></legend><div class="row g-2">
          <?php foreach ($keys as $k): [$lbl, $kind] = M::OPTIONS[$k]; $id = 'o_' . $k; ?>
            <?php if ($kind === 'bool'): ?>
              <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="o[<?= e($k) ?>]" value="0"><input class="form-check-input" type="checkbox" name="o[<?= e($k) ?>]" value="1" id="<?= e($id) ?>" <?= $o[$k] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="<?= e($id) ?>"><?= e($lbl) ?></label></div></div>
            <?php elseif ($kind === 'select'): ?>
              <div class="col-sm-6"><label class="form-label small mb-0" for="<?= e($id) ?>"><?= e($lbl) ?></label><select class="form-select form-select-sm" name="o[<?= e($k) ?>]" id="<?= e($id) ?>"><?php foreach (M::OPTIONS[$k][4] as $v => $vl): ?><option value="<?= e((string) $v) ?>" <?= $o[$k] === (string) $v ? 'selected' : '' ?>><?= e($vl) ?></option><?php endforeach; ?></select></div>
            <?php elseif ($kind === 'color'): ?>
              <div class="col-sm-6"><label class="form-label small mb-0" for="<?= e($id) ?>"><?= e($lbl) ?></label><input type="color" class="form-control form-control-color w-100" name="o[<?= e($k) ?>]" id="<?= e($id) ?>" value="<?= e($o[$k]) ?>"></div>
            <?php elseif (str_starts_with($kind, 'area')): ?>
              <div class="col-12"><label class="form-label small mb-0" for="<?= e($id) ?>"><?= e($lbl) ?></label><textarea class="form-control form-control-sm" rows="2" name="o[<?= e($k) ?>]" id="<?= e($id) ?>" maxlength="<?= (int) explode(':', $kind)[1] ?>"><?= e($o[$k]) ?></textarea></div>
            <?php else: ?>
              <div class="col-12"><label class="form-label small mb-0" for="<?= e($id) ?>"><?= e($lbl) ?></label><input class="form-control form-control-sm" name="o[<?= e($k) ?>]" id="<?= e($id) ?>" value="<?= e($o[$k]) ?>" maxlength="<?= (int) explode(':', $kind)[1] ?>"></div>
            <?php endif; ?>
          <?php endforeach; ?>
          </div></fieldset>
        <?php endforeach; ?>
      </div>
      <div class="card-footer d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button><a class="btn btn-outline-secondary" href="<?= e(url('/modeles')) ?>">Annuler</a></div>
    </form>
  </div>
  <div class="col-lg-7">
    <div class="card position-sticky" style="top:1rem">
      <div class="card-header d-flex flex-wrap align-items-center gap-2"><b><i class="bi bi-eye"></i> Aperçu</b>
        <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Document de l'aperçu"><?php $first = true; foreach ($kinds as $kv => $kl): ?><input type="radio" class="btn-check" name="mdlKind" id="k_<?= e($kv) ?>" value="<?= e($kv) ?>" form="none" <?= $first ? 'checked' : '' ?>><label class="btn btn-outline-secondary" for="k_<?= e($kv) ?>"><?= e($kl) ?></label><?php $first = false; endforeach; ?></div>
      </div>
      <iframe id="mdlPreview" title="Aperçu du modèle" style="width:100%;height:78vh;border:0;background:#e9ecef"></iframe>
    </div>
  </div>
</div>
