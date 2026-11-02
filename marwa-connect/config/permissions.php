<?php
/**
 * Catalogue des permissions par module et attribution par défaut aux rôles.
 * Modifiable ensuite depuis l'écran « Rôles & permissions ».
 */
return [
    'modules' => [
        'Tableau de bord' => [
            'dashboard.view' => 'Voir le tableau de bord',
            'reports.view' => 'Consulter les rapports et statistiques',
        ],
        'Routeurs' => [
            'routers.view' => 'Voir les routeurs',
            'routers.manage' => 'Ajouter / modifier / supprimer des routeurs',
            'routers.reboot' => 'Redémarrer un routeur',
            'routers.sync' => 'Synchroniser les routeurs',
        ],
        'Offres' => [
            'offers.view' => 'Voir les offres',
            'offers.manage' => 'Gérer les offres et profils Hotspot',
        ],
        'Tickets' => [
            'tickets.view' => 'Voir les tickets',
            'tickets.generate' => 'Générer des tickets',
            'tickets.print' => 'Imprimer / exporter des tickets',
            'tickets.manage' => 'Suspendre / annuler des tickets',
        ],
        'Ventes & caisse' => [
            'sales.view' => 'Voir les ventes',
            'sales.create' => 'Vendre des tickets',
            'sales.cancel' => 'Annuler une vente',
            'sales.refund' => 'Rembourser une vente',
            'sales.discount' => 'Accorder des remises',
            'cash.manage' => 'Ouvrir / clôturer la caisse',
            'payments.manage' => 'Configurer les moyens de paiement',
        ],
        'Revendeurs' => [
            'resellers.view' => 'Voir les revendeurs',
            'resellers.manage' => 'Gérer les revendeurs, stocks et commissions',
            'reseller.portal' => 'Accès à l\'espace revendeur',
        ],
        'Clients Wi-Fi' => [
            'hotspot.view' => 'Voir les utilisateurs connectés',
            'hotspot.manage' => 'Déconnecter / suspendre des utilisateurs',
        ],
        'Monitoring' => [
            'monitoring.view' => 'Voir le monitoring réseau',
        ],
        'Notifications' => [
            'notifications.view' => 'Voir les notifications',
            'notifications.manage' => 'Configurer les alertes',
        ],
        'Optimus IA' => [
            'ai.use' => 'Utiliser Optimus IA',
            'ai.execute' => 'Exécuter des opérations proposées par l\'IA',
        ],
        'Administration' => [
            'users.view' => 'Voir les utilisateurs',
            'users.manage' => 'Gérer les utilisateurs',
            'roles.manage' => 'Gérer les rôles et permissions',
            'settings.view' => 'Voir les paramètres',
            'settings.manage' => 'Modifier les paramètres',
            'audit.view' => 'Consulter le journal d\'audit',
            'backups.manage' => 'Gérer les sauvegardes',
        ],
    ],

    'roles' => [
        'super_admin' => ['name' => 'Super administrateur', 'description' => 'Accès total, non restreignable.', 'permissions' => ['*']],
        'admin' => ['name' => 'Administrateur', 'description' => 'Administration complète hors rôles système.', 'permissions' => ['*', '-roles.manage']],
        'network_manager' => ['name' => 'Responsable réseau', 'description' => 'Routeurs, offres, monitoring et clients Wi-Fi.', 'permissions' => [
            'dashboard.view', 'reports.view', 'routers.view', 'routers.manage', 'routers.reboot', 'routers.sync',
            'offers.view', 'offers.manage', 'tickets.view', 'tickets.generate', 'tickets.print', 'tickets.manage',
            'hotspot.view', 'hotspot.manage', 'monitoring.view', 'notifications.view', 'ai.use',
        ]],
        'sales_manager' => ['name' => 'Gestionnaire commercial', 'description' => 'Ventes, offres, tickets et revendeurs.', 'permissions' => [
            'dashboard.view', 'reports.view', 'routers.view', 'offers.view', 'offers.manage',
            'tickets.view', 'tickets.generate', 'tickets.print', 'tickets.manage',
            'sales.view', 'sales.create', 'sales.cancel', 'sales.refund', 'sales.discount', 'cash.manage',
            'resellers.view', 'resellers.manage', 'notifications.view', 'ai.use',
        ]],
        'cashier' => ['name' => 'Caissier', 'description' => 'Vente et impression de tickets.', 'permissions' => [
            'dashboard.view', 'offers.view', 'tickets.view', 'tickets.print', 'sales.view', 'sales.create', 'cash.manage', 'notifications.view',
        ]],
        'reseller' => ['name' => 'Revendeur', 'description' => 'Espace revendeur : stock, ventes et commissions.', 'permissions' => [
            'reseller.portal', 'tickets.print', 'notifications.view',
        ]],
        'technician' => ['name' => 'Technicien', 'description' => 'Surveillance et dépannage du réseau.', 'permissions' => [
            'dashboard.view', 'routers.view', 'routers.sync', 'hotspot.view', 'hotspot.manage', 'monitoring.view', 'notifications.view',
        ]],
        'accountant' => ['name' => 'Comptable', 'description' => 'Consultation des ventes, caisses et rapports.', 'permissions' => [
            'dashboard.view', 'reports.view', 'sales.view', 'resellers.view', 'notifications.view', 'audit.view',
        ]],
    ],
];
