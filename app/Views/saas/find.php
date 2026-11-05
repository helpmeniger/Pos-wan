<section class="container py-5" style="max-width:640px">
  <h1 class="h3">Se connecter à mon espace</h1>
  <p class="text-muted">Chaque commerce a sa propre adresse, de la forme <b>nom-du-commerce.<?= e(config('saas.domain')) ?></b>. Saisissez l'adresse de votre espace ou le nom exact de votre commerce.</p>
  <?php foreach ($flash as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?>
  <form method="post" action="<?= e(url('/trouver')) ?>" class="d-flex gap-2 mb-4">
    <?= csrf_field() ?>
    <input name="q" class="form-control form-control-lg" required minlength="3" value="<?= e($q) ?>" placeholder="boutique-awa ou Boutique Awa">
    <button class="btn btn-primary btn-lg">Rechercher</button>
  </form>
  <?php if (is_array($results)): ?>
    <?php if (!$results): ?><div class="alert alert-warning">Aucun espace trouvé avec « <?= e($q) ?> ». Vérifiez l'orthographe, ou <a href="<?= e(url('/inscription')) ?>">créez votre espace</a>.</div><?php endif; ?>
    <?php foreach ($results as $r): ?>
      <a class="saas-card d-flex justify-content-between align-items-center text-decoration-none mb-2" href="<?= e($r['url']) ?>"><span><b><?= e($r['company']) ?></b><br><small class="text-muted"><?= e($r['host']) ?></small></span><span class="btn btn-primary">Se connecter <i class="bi bi-arrow-right"></i></span></a>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
