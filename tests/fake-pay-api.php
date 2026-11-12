<?php
/** Fausse API de paiement pour les tests automatisés (php -S). Sans lien avec un prestataire réel. Montants en centimes. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = sys_get_temp_dir() . '/fake-pay-' . $_SERVER['SERVER_PORT'] . '.json';
$db = is_file($state) ? json_decode(file_get_contents($state), true) : ['n' => 0, 'p' => [], 'r' => [], 'hits' => []];
$save = function () use (&$db, $state) { file_put_contents($state, json_encode($db)); };
$json = function (int $c, $d) { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; };
$body = file_get_contents('php://input');
$h = array_change_key_case(getallheaders(), CASE_LOWER);
$db['hits'][] = $_SERVER['REQUEST_METHOD'] . ' ' . $path; $save();
if ($path === '/soap') {
    header('Content-Type: text/xml');
    preg_match('#<Amount>([^<]*)</Amount>#', $body, $m);
    exit('<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><PayResponse><Result>OK</Result><Ref>SOAP-1</Ref><Amount>' . ($m[1] ?? '') . '</Amount></PayResponse></s:Body></s:Envelope>');
}
if (!preg_match('#^/(ok|push|decline|down|weird)(/.*)$#', $path, $m)) $json(404, ['error' => 'route']);
[$mode, $rest] = [$m[1], $m[2]];
if (($h['x-api-key'] ?? '') !== 'PAY-KEY-123456') $json(401, ['error' => 'key']);
if ($mode === 'down') $json(503, ['error' => 'maintenance']);
if ($rest === '/ping') $json(200, ['ok' => true]);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rest === '/payments') {
    $in = json_decode($body, true);
    $k = $h['idempotency-key'] ?? '';
    foreach ($db['p'] as $ref => $p) if ($p['key'] === $k) $json(200, $p['out']);
    $db['n']++; $ref = 'PSP-' . $db['n'];
    $st = ['ok' => 'SUCCESS', 'push' => 'PENDING', 'decline' => 'FAILED', 'weird' => 'WEIRD'][$mode];
    $out = ['id' => $ref, 'status' => $st, 'amount' => $in['amount'] ?? null, 'currency' => $in['currency'] ?? null, 'fee' => 150, 'auth' => $st === 'SUCCESS' ? 'A1B2C3' : null];
    $db['p'][$ref] = ['key' => $k, 'out' => $out, 'gets' => 0, 'mode' => $mode]; $save();
    $json($st === 'FAILED' ? 402 : 201, $out);
}
if (preg_match('#^/payments/(PSP-\d+)$#', $rest, $r) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($db['p'][$r[1]])) $json(404, ['error' => 'unknown']);
    $p = &$db['p'][$r[1]]; $p['gets']++;
    if ($p['out']['status'] === 'PENDING' && $p['gets'] >= 2) { $p['out']['status'] = 'SUCCESS'; $p['out']['auth'] = 'Z9Y8X7'; }
    $save(); $json(200, $p['out']);
}
if (preg_match('#^/payments/(PSP-\d+)/cancel$#', $rest, $r)) {
    $p = &$db['p'][$r[1]]; $p['out']['status'] = 'CANCELLED'; $save(); $json(200, $p['out']);
}
if (preg_match('#^/payments/(PSP-\d+)/refunds$#', $rest, $r)) {
    $in = json_decode($body, true); $id = 'RF-' . (count($db['r']) + 1);
    $db['r'][$id] = ['refund_id' => $id, 'refund_status' => 'DONE', 'amount' => $in['amount'] ?? null]; $save(); $json(201, $db['r'][$id]);
}
$json(404, ['error' => 'route']);
