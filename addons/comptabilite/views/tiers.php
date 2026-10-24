<?php use Addons\Comptabilite\Compta; require __DIR__ . '/_nav.php'; $new = (bool) App\Core\Request::query('nouveau'); $o = fn($k, $d = '') => App\Core\Session::old($k, (string) ($edit[$k] ?? $d)); ?>
<div class="row g-3">
<div class="col-xl-8">
  <form method="get" class="d-flex flex-wrap gap-2 mb-2 align-items-end small">
    <div><label class="form-label mb-0">Type</label><select name="type" class="form-select form-select-sm" data-autosubmit><option value="">Tous</option><?php foreach (Compta::TIERS_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($l) ?>s</option><?php endforeach; ?></select></div>
    <div><label class="form-label mb-0">Recherche</label><input name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm" placeholder="Code, nom, téléphone"></div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
    <?php if (can('compta.saisie')): ?>
      <a class="btn btn-sm btn-success ms-auto" href="<?= e(url('/compta/tiers', ['nouveau' => 1])) ?>"><i class="bi bi-plus-lg"></i> Nouveau tiers</a>
    <?php endif; ?>
  </form>
  <div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 small cpt-table">
    <thead><tr><th>Code</th><th>Nom</th><th>Type</th><th>Compte</th><th>Téléphone</th><th class="num">Solde</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $t): ?><tr class="<?= (int) $t['actif'] ? '' : 'text-secondary' ?>">
      <td class="cpt-mono"><?= e($t['code']) ?></td><td><?= e($t['nom']) ?><?= $t['source'] ? ' <span class="badge bg-light text-secondary border">' . ($t['source'] === 'customer' ? 'client caisse' : 'fournisseur caisse') . '</span>' : '' ?></td>
      <td><?= e(Compta::TIERS_TYPES[$t['type']]) ?></td><td class="cpt-mono"><?= e($t['compte_numero']) ?></td><td><?= e((string) $t['telephone']) ?></td>
      <td class="num <?= (float) $t['solde'] < 0 ? 'text-danger' : '' ?>"><?= Compta::num(abs((float) $t['solde'])) ?><?= abs((float) $t['solde']) >= 0.01 ? ((float) $t['solde'] > 0 ? ' D' : ' C') : '' ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-link p-0 me-2" href="<?= e(url('/compta/lettrage', ['compte' => $t['compte_numero'], 'tiers' => $t['code']])) ?>" title="Compte du tiers"><i class="bi bi-eye"></i></a>
        <?php if (can('compta.saisie')): ?><a class="btn btn-sm btn-link p-0" href="<?= e(url('/compta/tiers', ['edit' => $t['id']])) ?>" title="Modifier"><i class="bi bi-pencil"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-secondary py-4">Aucun tiers. Les clients et fournisseurs de la caisse sont créés automatiquement lors de la génération des écritures, ou en un clic ci-contre.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-4">
  <?php if (can('compta.saisie') && ($edit || $new)): ?>
  <form method="post" action="<?= e(url($edit ? '/compta/tiers/' . $edit['id'] : '/compta/tiers')) ?>" class="card mb-3"><div class="card-body"><?= csrf_field() ?>
    <h3 class="h6"><?= $edit ? 'Modifier ' . e($edit['code']) : 'Nouveau tiers' ?></h3>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small mb-0">Type</label><select name="type" class="form-select form-select-sm"><?php foreach (Compta::TIERS_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $o('type', 'client') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label small mb-0">Code</label><input name="code" class="form-control form-control-sm cpt-mono" maxlength="20" value="<?= e($o('code')) ?>" placeholder="Automatique"></div>
      <div class="col-12"><label class="form-label small mb-0">Nom</label><input name="nom" class="form-control form-control-sm" required maxlength="150" value="<?= e($o('nom')) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0">Compte collectif</label><select name="compte" class="form-select form-select-sm" required><?php foreach ($collectifs as $c): ?><option value="<?= e($c['numero']) ?>" <?= $o('compte', (string) ($edit['compte_numero'] ?? '')) === $c['numero'] ? 'selected' : '' ?>><?= e($c['numero'] . ' — ' . $c['libelle']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label small mb-0">Téléphone</label><input name="telephone" class="form-control form-control-sm" maxlength="40" value="<?= e($o('telephone')) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0">NIF</label><input name="nif" class="form-control form-control-sm" maxlength="40" value="<?= e($o('nif')) ?>"></div>
      <div class="col-12"><label class="form-label small mb-0">Adresse</label><input name="adresse" class="form-control form-control-sm" maxlength="190" value="<?= e($o('adresse')) ?>"></div>
      <div class="col-12 form-check small ms-1"><input type="hidden" name="actif" value="0"><input class="form-check-input" type="checkbox" name="actif" value="1" id="ta" <?= $o('actif', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="ta">Actif</label></div>
    </div>
    <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Enregistrer</button><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/compta/tiers')) ?>">Fermer</a></div>
  </div></form>
  <?php if ($edit && can('compta.admin')): ?><form method="post" action="<?= e(url('/compta/tiers/' . $edit['id'] . '/supprimer')) ?>" class="mb-3" data-confirm="Supprimer ce tiers ? (possible seulement s'il n'a aucune écriture)"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Supprimer ce tiers</button></form><?php endif; ?>
  <?php endif; ?>
  <?php if (can('compta.saisie')): ?>
  <form method="post" action="<?= e(url('/compta/tiers/importer')) ?>" class="card"><div class="card-body"><?= csrf_field() ?>
    <h3 class="h6">Clients et fournisseurs de la caisse</h3>
    <p class="small text-secondary mb-2">Crée un compte de tiers pour chaque client et fournisseur enregistré dans POS Marwa qui n'en a pas encore (rattachés aux comptes collectifs des paramètres).</p>
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-people"></i> Créer les tiers manquants</button>
  </div></form>
  <?php endif; ?>
</div>
</div>
