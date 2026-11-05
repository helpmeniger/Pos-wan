<?php use App\Saas\Billing;
$form = function (?array $p) use ($addonNames) {
    $list = $p ? array_map('trim', explode(',', (string) $p['addons'])) : [];
    $all = $p && $p['addons'] === '*';
    ob_start(); ?>
  <form method="post" action="<?= e(url($p ? '/console/offres/' . $p['id'] : '/console/offres')) ?>" class="row g-2"><?= csrf_field() ?>
    <?php if (!$p): ?><div class="col-md-2"><label class="form-label small">Code</label><input name="code" class="form-control form-control-sm" required pattern="[a-z0-9_\-]{2,30}" placeholder="premium"></div><?php endif; ?>
    <div class="col-md-3"><label class="form-label small">Nom</label><input name="name" class="form-control form-control-sm" required value="<?= e($p['name'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label small">Prix / mois (FCFA)</label><input name="price_month" type="number" min="0" class="form-control form-control-sm" value="<?= e(isset($p) ? (int) $p['price_month'] : '') ?>" required></div>
    <div class="col-md-1"><label class="form-label small">Utilisateurs</label><input name="max_users" type="number" min="1" class="form-control form-control-sm" value="<?= (int) ($p['max_users'] ?? 3) ?>"></div>
    <div class="col-md-1"><label class="form-label small">Boutiques</label><input name="max_stores" type="number" min="1" class="form-control form-control-sm" value="<?= (int) ($p['max_stores'] ?? 1) ?>"></div>
    <div class="col-md-1"><label class="form-label small">Caisses</label><input name="max_registers" type="number" min="1" class="form-control form-control-sm" value="<?= (int) ($p['max_registers'] ?? 1) ?>"></div>
    <div class="col-md-1"><label class="form-label small">Ordre</label><input name="sort" type="number" class="form-control form-control-sm" value="<?= (int) ($p['sort'] ?? 0) ?>"></div>
    <div class="col-12"><label class="form-label small">Description</label><input name="description" class="form-control form-control-sm" maxlength="255" value="<?= e($p['description'] ?? '') ?>"></div>
    <div class="col-12 small"><b>Modules inclus :</b>
      <label class="ms-2"><input type="checkbox" name="addons_all" value="1" <?= $all ? 'checked' : '' ?>> tous</label>
      <?php foreach ($addonNames as $code => $name): ?><label class="ms-2"><input type="checkbox" name="addons[]" value="<?= e($code) ?>" <?= in_array($code, $list, true) ? 'checked' : '' ?>> <?= e($name) ?></label><?php endforeach; ?></div>
    <div class="col-12 small"><label><input type="checkbox" name="public" value="1" <?= !$p || $p['public'] ? 'checked' : '' ?>> Affichée sur le site</label>
      <label class="ms-3"><input type="checkbox" name="active" value="1" <?= !$p || $p['active'] ? 'checked' : '' ?>> Active</label>
      <button class="btn btn-sm btn-primary ms-3"><?= $p ? 'Enregistrer' : 'Créer l\'offre' ?></button></div>
  </form>
<?php return (string) ob_get_clean(); }; ?>
<h1 class="mb-3">Offres</h1>
<p class="text-muted small">Les prix d'origine sont des exemples : adaptez-les. Un changement de prix s'applique aux prochains paiements ; les limites s'appliquent aux prochains ajouts d'utilisateurs, boutiques ou caisses (rien n'est supprimé chez les clients).</p>
<?php foreach ($plans as $p): ?>
  <div class="panel mb-3"><h2><?= e($p['name']) ?> <small class="text-muted fw-normal">(<?= e($p['code']) ?> · <?= (int) $p['tenants'] ?> client(s) · <?= e(Billing::fcfa($p['price_month'])) ?>/mois)</small></h2><?= $form($p) ?></div>
<?php endforeach; ?>
<div class="panel"><h2>Nouvelle offre</h2><?= $form(null) ?></div>
