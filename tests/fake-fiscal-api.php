<?php
/**
 * Fausse API fiscale pour les tests automatisés (php -S). AUCUN lien avec une API officielle :
 * elle sert seulement à vérifier le comportement des pilotes génériques (auth, refus, panne, délai, doublon).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = sys_get_temp_dir() . '/fake-fiscal-' . $_SERVER['SERVER_PORT'] . '.json';
$db = is_file($state) ? json_decode(file_get_contents($state), true) : ['n' => 0, 'inv' => [], 'hits' => []];
$save = function () use (&$db, $state) { file_put_contents($state, json_encode($db)); };
$json = function (int $code, $data) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; };
$body = file_get_contents('php://input');
$hdr = array_change_key_case(getallheaders(), CASE_LOWER);
$db['hits'][] = $_SERVER['REQUEST_METHOD'] . ' ' . $path; $save();

if ($path === '/oauth/token') {
    if (($hdr['authorization'] ?? '') !== 'Basic ' . base64_encode('client-1:SECRET-CLIENT-777')) $json(401, ['error' => 'invalid_client']);
    $json(200, ['access_token' => 'TOKEN-OAUTH-424242', 'expires_in' => 3600]);
}
if ($path === '/soap') {
    header('Content-Type: text/xml');
    if (str_contains($body, '<Reject>')) {
        http_response_code(500);
        exit('<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>s:Client</faultcode><faultstring>Facture refusée</faultstring></s:Fault></s:Body></s:Envelope>');
    }
    preg_match('#<Number>([^<]*)</Number>#', $body, $m);
    exit('<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><CertifyResponse xmlns="urn:test"><Status>OK</Status><Code>SOAP-' . htmlspecialchars($m[1] ?? '') . '</Code><Signature>SIG-SOAP</Signature></CertifyResponse></s:Body></s:Envelope>');
}
if (!preg_match('#^/(ok|down|reject|slow|garbage|oa)(/.*)$#', $path, $m)) $json(404, ['error' => 'route']);
[$mode, $rest] = [$m[1], $m[2]];
if ($mode === 'oa') {
    if (($hdr['authorization'] ?? '') !== 'Bearer TOKEN-OAUTH-424242') $json(401, ['error' => 'token']);
} elseif (($hdr['x-api-key'] ?? '') !== 'GOOD-KEY-123456') {
    $json(401, ['error' => 'bad key']);
}
if ($mode === 'down') $json(503, ['error' => 'maintenance']);
if ($mode === 'slow') { sleep(3); $json(200, ['status' => 'UP']); }
if ($mode === 'garbage') { http_response_code(200); exit('<html>proxy</html>'); }
if ($rest === '/status') $json(200, ['status' => 'UP']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rest === '/invoices') {
    $inv = json_decode($body, true);
    if ($mode === 'reject' || empty($inv['idempotency_key']) || empty($inv['lines'])) $json(422, ['result' => 'REJECTED', 'reason' => 'invalid']);
    $k = $inv['idempotency_key'];
    if (!isset($db['inv'][$k])) { $db['n']++; $db['inv'][$k] = ['code' => 'API-' . str_pad((string) $db['n'], 6, '0', STR_PAD_LEFT), 'mcf' => 'DEV-01', 'sig' => strtoupper(substr(hash('sha256', $k), 0, 24)), 'qr' => 'QR|' . $k, 'ttc' => $inv['totals']['ttc'] ?? null]; $save(); }
    $json(201, ['result' => 'ACCEPTED', 'data' => $db['inv'][$k]]);
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#^/invoices/([a-f0-9]{64})$#', $rest, $k)) {
    isset($db['inv'][$k[1]]) ? $json(200, ['result' => 'ACCEPTED', 'data' => $db['inv'][$k[1]]]) : $json(404, ['result' => 'UNKNOWN']);
}
$json(404, ['error' => 'route']);
