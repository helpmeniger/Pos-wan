<?php
/**
 * Navigation latérale (à droite). 'roles' restreint un élément à certains rôles.
 */
return [
    ['section' => 'Pilotage'],
    ['label' => 'Tableau de bord', 'icon' => 'bi-speedometer2', 'path' => '/', 'can' => ['dashboard.view']],
    ['label' => 'Optimus IA', 'icon' => 'bi-stars', 'path' => '/ai', 'can' => ['ai.use']],
    ['label' => 'Monitoring', 'icon' => 'bi-activity', 'path' => '/monitoring', 'can' => ['monitoring.view']],

    ['section' => 'Réseau'],
    ['label' => 'Routeurs MikroTik', 'icon' => 'bi-router', 'path' => '/routers', 'can' => ['routers.view']],
    ['label' => 'Clients Wi-Fi', 'icon' => 'bi-people', 'path' => '/hotspot', 'can' => ['hotspot.view']],

    ['section' => 'Commercial'],
    ['label' => 'Offres', 'icon' => 'bi-tags', 'path' => '/offers', 'can' => ['offers.view']],
    ['label' => 'Tickets Wi-Fi', 'icon' => 'bi-ticket-perforated', 'path' => '/tickets', 'can' => ['tickets.view']],
    ['label' => 'Caisse', 'icon' => 'bi-cash-coin', 'path' => '/pos', 'can' => ['sales.create']],
    ['label' => 'Ventes', 'icon' => 'bi-receipt', 'path' => '/sales', 'can' => ['sales.view']],
    ['label' => 'Revendeurs', 'icon' => 'bi-shop', 'path' => '/resellers', 'can' => ['resellers.view']],
    ['label' => 'Mon espace revendeur', 'icon' => 'bi-briefcase', 'path' => '/reseller', 'can' => ['reseller.portal'], 'roles' => ['reseller']],

    ['section' => 'Administration'],
    ['label' => 'Notifications', 'icon' => 'bi-bell', 'path' => '/notifications', 'can' => ['notifications.view']],
    ['label' => 'Utilisateurs', 'icon' => 'bi-person-gear', 'path' => '/admin/users', 'can' => ['users.view', 'users.manage']],
    ['label' => 'Rôles & permissions', 'icon' => 'bi-shield-lock', 'path' => '/admin/roles', 'can' => ['roles.manage']],
    ['label' => "Journal d'audit", 'icon' => 'bi-journal-text', 'path' => '/admin/audit', 'can' => ['audit.view']],
    ['label' => 'Paramètres', 'icon' => 'bi-sliders', 'path' => '/settings', 'can' => ['settings.view']],
    ['label' => 'Sauvegardes', 'icon' => 'bi-database-check', 'path' => '/admin/backups', 'can' => ['backups.manage']],
];
