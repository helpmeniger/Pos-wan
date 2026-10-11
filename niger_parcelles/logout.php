<?php
/** Déconnexion (POST + CSRF uniquement, pour éviter les déconnexions forcées). */
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (request_method() === 'POST') {
    require_csrf();
    logout_user();
    start_secure_session();
    flash('success', 'Vous êtes déconnecté.');
}
redirect('login.php');
