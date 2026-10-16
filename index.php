<?php
declare(strict_types=1);

/*
 * Point d'entrée de secours, utilisé seulement si le module Apache « rewrite_module »
 * est désactivé : l'application reste alors utilisable avec des adresses de la forme
 * http://localhost/marwa/index.php/connexion. Avec rewrite_module (recommandé),
 * le fichier .htaccess envoie directement les requêtes vers public/.
 */
$marwaEntry = 'root';
require __DIR__ . '/bootstrap/web.php';
