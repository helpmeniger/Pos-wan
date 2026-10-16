<?php

declare(strict_types=1);

/**
 * Référence lisible des rôles. La source de vérité des permissions est la base
 * (tables roles / permissions / role_permissions), alimentée par
 * database/seeders/001_reference_data.sql et modifiable par un SUPER_ADMIN.
 */
return [
    'staff_roles' => ['SUPER_ADMIN', 'ADMIN', 'FINANCE_ADMIN', 'MODERATOR', 'SUPPORT'],
    'public_roles' => ['WORKER', 'CLIENT'],
    // Espace vers lequel rediriger après connexion, par ordre de priorité
    'home_by_role' => [
        'SUPER_ADMIN' => '/admin',
        'ADMIN' => '/admin',
        'FINANCE_ADMIN' => '/admin',
        'MODERATOR' => '/admin',
        'SUPPORT' => '/admin',
        'WORKER' => '/espace/prestataire',
        'CLIENT' => '/espace/demandeur',
    ],
];
