<?php
/** Écouteurs du module Modèles de documents (chargés seulement si le module est actif). */
use Addons\Modeles\Modeles;
use App\Core\Events;

// Mise en page des tickets et documents A4 : seulement pour les documents auxquels un modèle est affecté.
Events::on('document.template', function (array $p): ?array {
    return Modeles::forDocument((string) $p['kind']) ? ['view' => 'modeles::rendu'] : null;
});
// Présentation des tickets et reçus des autres pages (règlements, retours, restaurant, pressing, clinique, école…).
Events::on('document.css', function (array $p): ?array {
    $css = Modeles::othersCss((string) $p['path']);
    return $css !== '' ? ['css' => $css] : null;
});
