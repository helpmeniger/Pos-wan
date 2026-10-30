<?php /** @var array|null $user */ /** @var int $unread */ ?>
<nav class="navbar navbar-expand-lg jn-navbar sticky-top">
  <div class="container">
    <a class="navbar-brand" href="<?= e(url('/')) ?>"><img src="<?= e(asset('images/logo.svg')) ?>" alt="JOB NIGER — accueil" width="200" height="47"></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-main" aria-controls="nav-main" aria-expanded="false" aria-label="Ouvrir le menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav-main">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link<?= is_active_path('/offres') ?>" href="<?= e(url('/offres')) ?>"><i class="bi bi-search" aria-hidden="true"></i> Offres</a></li>
        <?php if ($user !== null && has_role('WORKER')): ?>
          <li class="nav-item"><a class="nav-link<?= is_active_path('/candidatures') ?>" href="<?= e(url('/candidatures')) ?>">Mes candidatures</a></li>
        <?php endif; ?>
        <?php if ($user !== null && has_role('CLIENT')): ?>
          <li class="nav-item"><a class="nav-link<?= is_active_path('/recruteur') ?>" href="<?= e(url('/recruteur/offres')) ?>">Mes offres</a></li>
        <?php endif; ?>
        <?php if ($user !== null && (has_role('WORKER') || has_role('CLIENT'))): ?>
          <li class="nav-item"><a class="nav-link<?= is_active_path('/emplois') ?><?= is_active_path('/fiches-de-paie') ?>" href="<?= e(url('/emplois')) ?>">Emplois &amp; paie</a></li>
        <?php endif; ?>
        <?php if ($user === null): ?>
          <li class="nav-item"><a class="nav-link<?= is_active_path('/categories') ?>" href="<?= e(url('/categories')) ?>">Métiers</a></li>
          <li class="nav-item"><a class="nav-link<?= is_active_path('/a-propos') ?>" href="<?= e(url('/a-propos')) ?>">Comment ça marche</a></li>
        <?php endif; ?>
      </ul>
      <?php if ($user === null): ?>
        <div class="d-flex flex-column flex-lg-row gap-2">
          <a class="btn btn-outline-primary" href="<?= e(url('/connexion')) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Connexion</a>
          <a class="btn btn-primary" href="<?= e(url('/inscription')) ?>">Créer un compte</a>
        </div>
      <?php else: ?>
        <ul class="navbar-nav align-items-lg-center">
          <?php if (can('admin.dashboard.view')): ?>
            <li class="nav-item"><a class="nav-link<?= is_active_path('/admin') ?>" href="<?= e(url('/admin')) ?>"><i class="bi bi-speedometer2" aria-hidden="true"></i> Administration</a></li>
          <?php endif; ?>
          <?php if (has_role('WORKER')): ?>
            <li class="nav-item"><a class="nav-link<?= is_active_path('/espace/prestataire') ?>" href="<?= e(url('/espace/prestataire')) ?>">Espace prestataire</a></li>
          <?php endif; ?>
          <?php if (has_role('CLIENT')): ?>
            <li class="nav-item"><a class="nav-link<?= is_active_path('/espace/demandeur') ?>" href="<?= e(url('/espace/demandeur')) ?>">Espace demandeur</a></li>
          <?php endif; ?>
          <li class="nav-item">
            <a class="nav-link position-relative<?= is_active_path('/notifications') ?>" href="<?= e(url('/notifications')) ?>" aria-label="Notifications (<?= (int) $unread ?> non lues)">
              <i class="bi bi-bell" aria-hidden="true"></i><span class="d-lg-none ms-1">Notifications</span>
              <?php if ($unread > 0): ?><span class="badge rounded-pill bg-danger jn-badge-count"><?= $unread > 99 ? '99+' : (int) $unread ?></span><?php endif; ?>
            </a>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle" aria-hidden="true"></i> <?= e($user['first_name']) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= e(url('/tableau-de-bord')) ?>">Mon tableau de bord</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/profil')) ?>">Mon profil</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/verification')) ?>">Vérification (documents)</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/archives')) ?>">Mes archives</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/aide')) ?>">Aide</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/mot-de-passe/changer')) ?>">Changer mon mot de passe</a></li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="post" action="<?= e(url('/deconnexion')) ?>" class="px-3">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-link p-0 text-danger text-decoration-none"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Se déconnecter</button>
                </form>
              </li>
            </ul>
          </li>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</nav>
