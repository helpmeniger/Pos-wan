<?php
$editing = $reseller !== null;
$title = $editing ? 'Modifier ' . $reseller['business_name'] : 'Nouveau revendeur';
$v = static fn (string $k, $d = '') => old($k, $editing ? ($reseller[$k] ?? $d) : $d);
?>
<div class="mc-page-head"><div><h1><?= $editing ? 'Modifier le' : 'Nouveau' ?> <span class="mc-accent">revendeur</span></h1></div>
    <a class="btn btn-outline-secondary" href="<?= e(url($editing ? '/resellers/' . $reseller['id'] : '/resellers')) ?>"><i class="bi bi-arrow-left"></i> Retour</a></div>
<form method="post" action="<?= e(url($editing ? '/resellers/' . $reseller['id'] : '/resellers')) ?>" novalidate><?= csrf_field() ?><?= $editing ? method_field('PUT') : '' ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Identité</h2></div><div class="mc-card-body row g-3">
            <div class="col-md-8"><label class="form-label" for="business_name">Nom commercial *</label><input class="form-control<?= invalid('business_name') ?>" id="business_name" name="business_name" value="<?= e($v('business_name')) ?>" required><?= error_for('business_name') ?></div>
            <div class="col-md-4"><label class="form-label" for="code">Code *</label><input class="form-control<?= invalid('code') ?>" id="code" name="code" value="<?= e($v('code')) ?>" placeholder="REV01" required><?= error_for('code') ?></div>
            <div class="col-md-6"><label class="form-label" for="contact_name">Contact</label><input class="form-control" id="contact_name" name="contact_name" value="<?= e($v('contact_name')) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="phone">Téléphone</label><input class="form-control" id="phone" name="phone" value="<?= e($v('phone')) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="email">E-mail</label><input class="form-control<?= invalid('email') ?>" id="email" name="email" value="<?= e($v('email')) ?>"><?= error_for('email') ?></div>
            <div class="col-md-6"><label class="form-label" for="address">Adresse</label><input class="form-control" id="address" name="address" value="<?= e($v('address')) ?>"></div>
            <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2"><?= e($v('notes')) ?></textarea></div>
        </div></div>
        <div class="mc-card"><div class="mc-card-head"><h2>Modèle commercial</h2></div><div class="mc-card-body row g-3">
            <div class="col-md-6"><label class="form-label" for="business_model">Modèle</label>
                <select class="form-select" id="business_model" name="business_model"><option value="commission" <?= $v('business_model', 'commission') === 'commission' ? 'selected' : '' ?>>Commission (dépôt-vente)</option><option value="wholesale" <?= $v('business_model') === 'wholesale' ? 'selected' : '' ?>>Vente en gros (achat du stock)</option></select>
                <div class="form-text">Commission : le revendeur reçoit des tickets en dépôt et touche un % sur chaque vente. Gros : il achète les tickets avec une remise, débitée de son solde.</div></div>
            <div class="col-md-3"><label class="form-label" for="commission_rate">Commission (%)</label><input class="form-control<?= invalid('commission_rate') ?>" id="commission_rate" name="commission_rate" value="<?= e($v('commission_rate', 10)) ?>"><?= error_for('commission_rate') ?></div>
            <div class="col-md-3"><label class="form-label" for="wholesale_discount">Remise de gros (%)</label><input class="form-control<?= invalid('wholesale_discount') ?>" id="wholesale_discount" name="wholesale_discount" value="<?= e($v('wholesale_discount', 15)) ?>"><?= error_for('wholesale_discount') ?></div>
            <div class="col-md-6"><label class="form-label" for="credit_limit">Plafond de crédit (FCFA)</label><input class="form-control" id="credit_limit" name="credit_limit" value="<?= e($v('credit_limit', 0)) ?>"></div>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Routeurs affectés</h2></div><div class="mc-card-body">
            <?php foreach ($routers as $r): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="routers[]" value="<?= (int) $r['id'] ?>" id="rr<?= (int) $r['id'] ?>" <?= in_array((int) $r['id'], $linked, true) ? 'checked' : '' ?>><label class="form-check-label" for="rr<?= (int) $r['id'] ?>"><?= e($r['name']) ?></label></div><?php endforeach; ?>
            <?php if (!$routers): ?><p class="small text-muted mb-0">Aucun routeur.</p><?php endif; ?>
        </div></div>
        <?php if (!$editing): ?>
        <div class="mc-card mb-3"><div class="mc-card-head"><h2>Compte de connexion</h2></div><div class="mc-card-body">
            <p class="small text-muted">Facultatif : permet au revendeur d'accéder à son espace (stock, ventes, impression).</p>
            <label class="form-label" for="username">Identifiant</label><input class="form-control mb-2<?= invalid('username') ?>" id="username" name="username" value="<?= e(old('username')) ?>" autocomplete="off"><?= error_for('username') ?>
            <label class="form-label" for="password">Mot de passe provisoire</label><input class="form-control<?= invalid('password') ?>" id="password" name="password" type="password" autocomplete="new-password"><?= error_for('password') ?>
        </div></div>
        <?php elseif ($reseller['username']): ?><p class="small text-muted">Compte de connexion : <code><?= e($reseller['username']) ?></code> (gérable dans Utilisateurs).</p><?php endif; ?>
        <button class="btn btn-primary w-100 py-2">Enregistrer</button>
    </div>
</div></form>
