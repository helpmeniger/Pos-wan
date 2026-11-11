<?php
/** Boutique en ligne : compte du client (inscription, connexion, coordonnées, historique des commandes et reçus). */
$withCart = false;
$pageTitle = 'Mon compte';
$open = true;
include __DIR__ . '/_head.php';
$a = $account;
?>
<main class="container py-4 pb-5">
  <a href="<?= e(url('/boutique')) ?>" class="shop-back"><i class="bi bi-arrow-left"></i> Retour à la boutique</a>
  <?php if ($msg): ?><div class="alert alert-<?= e($msg[0]) ?> mt-3" role="alert"><?= e($msg[1]) ?></div><?php endif; ?>
  <?php if (!$a): ?>
  <h1 class="h3 fw-bold mt-2 mb-3">Mon compte</h1>
  <div class="row g-4">
    <div class="col-lg-5"><div class="shop-card p-3 p-md-4">
      <h2 class="h5 fw-bold mb-3"><i class="bi bi-box-arrow-in-right"></i> J'ai déjà un compte</h2>
      <form method="post" action="<?= e(url('/boutique/compte/connexion')) ?>"><?= csrf_field() ?>
        <label class="form-label" for="lPhone">Téléphone</label><input class="form-control form-control-lg mb-3" id="lPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="50">
        <label class="form-label" for="lPwd">Mot de passe</label><input class="form-control form-control-lg mb-3" id="lPwd" name="password" type="password" autocomplete="current-password" required>
        <button class="btn btn-shop btn-lg w-100">Me connecter</button>
      </form>
      <p class="small text-secondary mt-3 mb-0">Mot de passe oublié ? <?= $phone !== '' ? 'Appelez la boutique au ' . e($phone) . '.' : 'Contactez la boutique.' ?></p>
    </div></div>
    <div class="col-lg-7"><div class="shop-card p-3 p-md-4">
      <h2 class="h5 fw-bold mb-1"><i class="bi bi-person-plus"></i> Créer mon compte</h2>
      <p class="text-secondary small">Vos coordonnées sont enregistrées pour vos prochaines commandes, et vous retrouvez l'historique de vos achats et vos reçus.</p>
      <form method="post" action="<?= e(url('/boutique/compte/inscription')) ?>" class="row g-3"><?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label" for="rName">Nom et prénom</label><input class="form-control" id="rName" name="name" autocomplete="name" required maxlength="150"></div>
        <div class="col-md-6"><label class="form-label" for="rPhone">Téléphone</label><input class="form-control" id="rPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="50" placeholder="+227 …"></div>
        <div class="col-md-6"><label class="form-label" for="rMail">E-mail <span class="text-secondary small">(facultatif)</span></label><input class="form-control" id="rMail" name="email" type="email" autocomplete="email" maxlength="150"></div>
        <div class="col-md-6"><label class="form-label" for="rPwd">Mot de passe <span class="text-secondary small">(8 caractères au moins)</span></label><input class="form-control" id="rPwd" name="password" type="password" autocomplete="new-password" required minlength="8"></div>
        <div class="col-12"><label class="form-label" for="rAddr">Adresse de livraison <span class="text-secondary small">(facultatif)</span></label><input class="form-control" id="rAddr" name="address" maxlength="255" placeholder="Quartier, rue, repère"></div>
        <div class="shop-hp" aria-hidden="true"><label>Site web <input name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="col-12"><button class="btn btn-shop btn-lg w-100">Créer mon compte</button></div>
        <p class="small text-secondary mb-0"><i class="bi bi-shield-check"></i> Vos informations servent uniquement à traiter vos commandes. Vous pouvez supprimer votre compte à tout moment.</p>
      </form>
    </div></div>
  </div>
  <?php else: ?>
  <div class="d-flex flex-wrap align-items-center gap-2 mt-2 mb-3">
    <h1 class="h3 fw-bold mb-0">Bonjour <?= e(explode(' ', $a['name'])[0]) ?></h1>
    <form method="post" action="<?= e(url('/boutique/compte/deconnexion')) ?>" class="ms-auto"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-right"></i> Me déconnecter</button></form>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-4"><div class="shop-card p-3 text-center"><div class="fs-3 fw-bold"><?= (int) $stats['n'] ?></div><div class="small text-secondary">commande(s)</div></div></div>
    <div class="col-4"><div class="shop-card p-3 text-center"><div class="fs-3 fw-bold"><?= (int) $stats['pending'] ?></div><div class="small text-secondary">en cours</div></div></div>
    <div class="col-4"><div class="shop-card p-3 text-center"><div class="fs-5 fw-bold"><?= e(money($stats['spent'])) ?></div><div class="small text-secondary">total de mes achats</div></div></div>
  </div>
  <div class="row g-4">
    <div class="col-lg-7">
      <h2 class="h5 fw-bold mb-3"><i class="bi bi-clock-history"></i> Mes commandes et mes reçus</h2>
      <?php foreach ($orders as $o): [$sl, $sc] = $statuses[$o['status']]; ?>
      <div class="shop-card p-3 mb-3">
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <b><?= e($o['number']) ?></b><span class="badge text-bg-<?= e($sc) ?>"><?= e($sl) ?></span><span class="text-secondary small"><?= e(fdate($o['created_at'])) ?></span>
          <span class="ms-auto fw-bold"><?= e(money($o['total'])) ?></span>
        </div>
        <div class="small text-secondary mt-1"><?= e(implode(' · ', array_map(fn ($i) => qty($i['quantity']) . ' × ' . $i['product_name'], $o['items']))) ?></div>
        <div class="small mt-1"><?= $o['delivery'] === 'delivery' ? '<i class="bi bi-truck"></i> Livraison' . ($o['address'] ? ' : ' . e($o['address']) : '') : '<i class="bi bi-shop"></i> Retrait en boutique' ?><?= $o['sale_number'] ? ' · Ticket de caisse <b>' . e($o['sale_number']) . '</b>' : '' ?><?= $o['status'] === 'cancelled' && $o['cancel_reason'] ? ' · Motif : ' . e($o['cancel_reason']) : '' ?></div>
        <a class="btn btn-sm btn-outline-dark mt-2" href="<?= e(url('/boutique/commande/' . $o['id'], ['t' => $o['token']])) ?>"><i class="bi bi-receipt"></i> Voir le reçu et le suivi</a>
      </div>
      <?php endforeach; ?>
      <?php if (!$orders): ?><div class="shop-empty"><i class="bi bi-bag"></i><p>Vous n'avez pas encore commandé avec ce compte.</p><a class="btn btn-shop" href="<?= e(url('/boutique')) ?>">Voir les articles</a></div><?php endif; ?>
    </div>
    <div class="col-lg-5">
      <div class="shop-card p-3 p-md-4 mb-3">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-person-lines-fill"></i> Mes informations</h2>
        <form method="post" action="<?= e(url('/boutique/compte/profil')) ?>" class="row g-2"><?= csrf_field() ?>
          <div class="col-12"><label class="form-label small mb-0">Nom et prénom</label><input class="form-control" name="name" value="<?= e($a['name']) ?>" required maxlength="150"></div>
          <div class="col-6"><label class="form-label small mb-0">Téléphone</label><input class="form-control" name="phone" type="tel" value="<?= e($a['phone']) ?>" required maxlength="50"></div>
          <div class="col-6"><label class="form-label small mb-0">E-mail</label><input class="form-control" name="email" type="email" value="<?= e($a['email']) ?>" maxlength="150"></div>
          <div class="col-12"><label class="form-label small mb-0">Adresse de livraison</label><input class="form-control" name="address" value="<?= e($a['address']) ?>" maxlength="255"></div>
          <div class="col-6"><label class="form-label small mb-0">Mot de passe actuel</label><input class="form-control" name="current_password" type="password" autocomplete="current-password"></div>
          <div class="col-6"><label class="form-label small mb-0">Nouveau mot de passe</label><input class="form-control" name="new_password" type="password" autocomplete="new-password" minlength="8"></div>
          <div class="col-12 small text-secondary">Laissez les mots de passe vides pour ne pas le changer.</div>
          <div class="col-12"><button class="btn btn-shop w-100">Enregistrer</button></div>
        </form>
      </div>
      <details class="shop-card p-3"><summary class="text-danger small">Supprimer mon compte</summary>
        <form method="post" action="<?= e(url('/boutique/compte/supprimer')) ?>" class="mt-2"><?= csrf_field() ?>
          <p class="small text-secondary">Votre compte et vos coordonnées enregistrées sont supprimés. Les commandes déjà passées restent dans les registres de la boutique, sans lien avec un compte.</p>
          <input class="form-control mb-2" name="password" type="password" placeholder="Votre mot de passe" required><button class="btn btn-outline-danger btn-sm">Supprimer définitivement</button>
        </form></details>
    </div>
  </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/_foot.php'; ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
