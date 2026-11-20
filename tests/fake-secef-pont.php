<?php
/**
 * FAUX pont MARWA SECEF pour les tests automatisés (php -S). AUCUN lien avec le portail de la DGI :
 * les références renvoyées sont marquées « TEST » et servent seulement à vérifier le comportement de POS Marwa
 * (anti-doublon, états, reçu). Le vrai pont est addons/fiscal/pont-secef/serveur.js.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = sys_get_temp_dir() . '/fake-secef-' . $_SERVER['SERVER_PORT'] . '.json';
$db = is_file($state) ? json_decode(file_get_contents($state), true) : [];
$db += ['jeton' => '', 'scenario' => 'certifie', 'jobs' => [], 'soumissions' => [], 'acces_vus' => []];
$save = function () use (&$db, $state) { file_put_contents($state, json_encode($db)); };
$json = function (int $code, $data) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; };
$hdr = array_change_key_case(getallheaders(), CASE_LOWER);
if (($hdr['x-pont-jeton'] ?? '') === '' || !hash_equals((string) $db['jeton'], (string) ($hdr['x-pont-jeton'] ?? ''))) {
    $json(401, ['ok' => false, 'erreur' => 'jeton du pont invalide']);
}
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (isset($body['acces'])) {
    $db['acces_vus'][] = $body['acces']['identifiant'] ?? '';
    $save();
}
if ($path === '/etat') {
    $json(200, ['ok' => true, 'version' => 'test', 'navigateur' => 'arrete', 'session' => 'fermee', 'travail' => null]);
}
if ($path === '/session/tester') {
    if (($body['acces']['mot_de_passe'] ?? '') !== 'mot-de-passe-de-test') {
        $json(200, ['ok' => false, 'etat' => 'identifiants', 'erreur' => 'Identifiant ou mot de passe e-SECeF refusé par le portail.']);
    }
    $json(200, ['ok' => true, 'nim' => 'TEST-NIM-0001', 'message' => 'Session e-SECeF ouverte (TEST).']);
}
if ($path === '/factures/soumettre') {
    $f = $body['facture'];
    $cle = $f['cle'];
    $db['soumissions'][] = ['cle' => $cle, 'facture' => $f, 'certifier' => $body['certifier']];
    $job = $db['jobs'][$cle] ?? null;
    if ($job && ($job['certification_envoyee'] || in_array($job['etat'], ['certifiee', 'incertaine', 'en_cours'], true))) {
        $save();
        $json(200, $job); // jamais renvoyée
    }
    $sc = $body['certifier'] ? $db['scenario'] : 'prepare';
    $res = ['total' => $f['total']];
    $job = match ($sc) {
        'certifie' => ['ok' => true, 'cle' => $cle, 'etat' => 'certifiee', 'certification_envoyee' => true, 'message' => 'Facture certifiée.',
            'resultat' => $res + ['code_secef' => 'TEST-SECEF-' . strtoupper(substr($cle, 0, 12)), 'nim' => 'TEST-NIM-0001', 'compteurs' => '1/1 FV (TEST)', 'heure' => '20/11/2026 18:00:00']],
        'prepare' => ['ok' => true, 'cle' => $cle, 'etat' => 'preparee', 'certification_envoyee' => false, 'message' => 'Facture préparée et vérifiée.', 'resultat' => $res],
        'lent' => ['ok' => true, 'cle' => $cle, 'etat' => 'en_cours', 'certification_envoyee' => true, 'message' => '', 'resultat' => null],
        'incertaine' => ['ok' => true, 'cle' => $cle, 'etat' => 'incertaine', 'certification_envoyee' => true, 'message' => 'Résultat non relevé.', 'resultat' => null],
        'ecart' => ['ok' => true, 'cle' => $cle, 'etat' => 'erreur', 'certification_envoyee' => false, 'reessayable' => false, 'message' => 'Total e-SFE 1 ≠ total POS Marwa 2 : facture non certifiée.', 'resultat' => null],
    };
    $db['jobs'][$cle] = $job;
    $save();
    $json(200, $job);
}
if (preg_match('#^/factures/([a-f0-9]{64})$#', $path, $m)) {
    $job = $db['jobs'][$m[1]] ?? null;
    if (!$job) {
        $json(404, ['ok' => false, 'etat' => 'inconnue']);
    }
    $json(200, $job);
}
$json(404, ['ok' => false, 'erreur' => 'route']);
