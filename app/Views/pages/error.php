<div class="auth-wrap"><div class="card auth-card">
  <h1><?= (int) $status === 404 ? 'Page introuvable' : ((int) $status === 403 ? 'Accès refusé' : 'Oups') ?></h1>
  <p><?= e($message) ?></p>
  <a class="btn btn-primary" href="<?= e(url('')) ?>">Retour à l'accueil</a>
</div></div>
