<?php
/**
 * Navigation latérale (à droite). 'phase' indique un module pas encore livré :
 * il est affiché désactivé avec la mention « Bientôt » au lieu d'un lien mort.
 * 'roles' restreint un élément à certains rôles (ex. espace revendeur).
 */
return [
    ['section' => 'Pilotage'],
    ['label' => 'Tableau de bord', 'icon' => 'bi-speedometer2', 'path' => '/', 'can' => ['dashboard.view']],
    ['label' => 'Optimus IA', 'icon' => 'bi-stars', 'path' => '/ai', 'can' => ['ai.use'], 'phase' => 9],
    ['label' => 'Monitoring', 'icon' => 'bi-activity', 'path' => '/monitoring', 'can' => ['monitoring.view'], 'phase' => 8],

    ['section' => 'Réseau'],
    ['label' => 'Routeurs MikroTik', 'icon' => 'bi-router', 'path' => '/routers', 'can' => ['routers.view'], 'phase' => 3],
    ['label' => 'Clients Wi-Fi', 'icon' => 'bi-people', 'path' => '/hotspot', 'can' => ['hotspot.view'], 'phase' => 8],

    ['section' => 'Commercial'],
    ['label' => 'Offres', 'icon' => 'bi-tags', 'path' => '/offers', 'can' => ['offers.view'], 'phase' => 4],
    ['label' => 'Tickets Wi-Fi', 'icon' => 'bi-ticket-perforated', 'path' => '/tickets', 'can' => ['tickets.view'], 'phase' => 5],
    ['label' => 'Caisse', 'icon' => 'bi-cash-coin', 'path' => '/pos', 'can' => ['sales.create'], 'phase' => 6],
    ['label' => 'Ventes', 'icon' => 'bi-receipt', 'path' => '/sales', 'can' => ['sales.view'], 'phase' => 6],
    ['label' => 'Revendeurs', 'icon' => 'bi-shop', 'path' => '/resellers', 'can' => ['resellers.view'], 'phase' => 7],
    ['label' => 'Mon espace revendeur', 'icon' => 'bi-briefcase', 'path' => '/reseller', 'can' => ['reseller.portal'], 'roles' => ['reseller'], 'phase' => 7],

    ['section' => 'Administration'],
    ['label' => 'Utilisateurs', 'icon' => 'bi-person-gear', 'path' => '/admin/users', 'can' => ['users.view', 'users.manage']],
    ['label' => 'Rôles & permissions', 'icon' => 'bi-shield-lock', 'path' => '/admin/roles', 'can' => ['roles.manage']],
    ['label' => "Journal d'audit", 'icon' => 'bi-journal-text', 'path' => '/admin/audit', 'can' => ['audit.view']],
    ['label' => 'Notifications', 'icon' => 'bi-bell', 'path' => '/notifications', 'can' => ['notifications.manage'], 'phase' => 9],
    ['label' => 'Paramètres', 'icon' => 'bi-sliders', 'path' => '/settings', 'can' => ['settings.view'], 'phase' => 2],
    ['label' => 'Sauvegardes', 'icon' => 'bi-database-check', 'path' => '/admin/backups', 'can' => ['backups.manage'], 'phase' => 10],
];
