<?php
/**
 * POS Marwa Bureau — routeur des serveurs PHP lancés par « POS Marwa.exe » (php -S 127.0.0.1:<port> -t public routeur.php).
 *
 * - Fichiers statiques : servis seulement depuis public/ (jamais un fichier caché, ni un .php autre que l'application).
 * - Adresse du poste client : le lanceur (proxy) transmet l'adresse réelle dans X-Bureau-Ip, avec un jeton secret tiré
 *   au hasard à chaque démarrage (variable d'environnement POS_BUREAU_JETON). Sans le bon jeton, l'en-tête est ignoré :
 *   un autre programme du PC ne peut pas se faire passer pour un autre poste (blocage après échecs de connexion par adresse).
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$jeton = (string) getenv('POS_BUREAU_JETON');
$hdrJeton = (string) ($_SERVER['HTTP_X_BUREAU_JETON'] ?? '');
if ($jeton !== '' && $hdrJeton !== '' && hash_equals($jeton, $hdrJeton)) {
    $ip = (string) ($_SERVER['HTTP_X_BUREAU_IP'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }
}
unset($_SERVER['HTTP_X_BUREAU_JETON'], $_SERVER['HTTP_X_BUREAU_IP']);

$public = __DIR__ . '/public';
if ($path !== '/' && !str_contains($path, '..') && !preg_match('#/\.#', $path)) {
    $file = $public . $path;
    if (is_file($file) && !str_ends_with(strtolower($file), '.php')) {
        return false; // fichier statique servi par PHP (types MIME du serveur intégré)
    }
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public . '/index.php';
require $public . '/index.php';
