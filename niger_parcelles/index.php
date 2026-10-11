<?php
/** Accueil : redirige vers le tableau de bord ou la connexion. */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

redirect(current_user() ? 'dashboard.php' : 'login.php');
