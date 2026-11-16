<?php
/**
 * Module Reconnaissance 1.3 — contrôle « rayon → caisse » (inclus par tests/run.php). Variables reçues : $B.
 * Les événements de caméra sont envoyés comme le fait l'écran de surveillance (HTTP), les ventes sont de vraies ventes.
 */
declare(strict_types=1);

use Addons\Vision\ParcoursService as PS;
use App\Core\DB;
use App\Services as S;

test('Surveillance des étagères : article pris / remis à son emplacement (main, personne devant, lumière, caméra déplacée) — tests JavaScript', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        ok(true, 'node absent : tests JavaScript non exécutés');
        return;
    }
    exec(escapeshellarg($node) . ' ' . escapeshellarg(BASE_PATH . '/tests/js/shelf.test.js') . ' 2>&1', $out, $code);
    ok($code === 0, implode("\n", $out));
    ok((bool) preg_match('/(\d+) réussi\(s\), 0 échec/', implode("\n", $out), $m) && (int) $m[1] >= 18, 'au moins 18 vérifications du moteur d\'étagère : ' . end($out));
});

test('Module Reconnaissance 1.3 : rôles des caméras, emplacements, suivi rayon → caisse, alertes, vérification, droits', function () use ($B) {
    App\Core\Addons::install('vision', false);
    eq('1.3.0', App\Core\Addons::installed()['vision']['version'] ?? null, 'module en 1.3.0');
    foreach (['vision_slots', 'vision_journeys'] as $tb) {
        ok((bool) DB::value('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tb]), "table $tb");
    }
    ok(is_file(BASE_PATH . '/addons/vision/sql/down_0004.sql'), 'script de retour arrière fourni');
    foreach (['vision-shelf.js', 'vision-controles.js'] as $f) {
        ok(is_file(PUBLIC_PATH . '/assets/addons/vision/' . $f), "$f publié");
    }
    // Base de test : aucun suivi ni caméra de test laissés par une exécution précédente
    DB::run('DELETE FROM vision_journeys');
    DB::run("DELETE FROM stores WHERE name = 'Boutique test parcours'");
    $leftover = "SELECT id FROM vision_cameras WHERE name IN ('Rayon boissons', 'Comptoir caisse 1', 'Porte principale', 'Passage ancien')";
    DB::run("DELETE FROM vision_events WHERE camera_id IN ($leftover)");
    DB::run("DELETE FROM vision_cameras WHERE id IN (SELECT id FROM ($leftover) x)");
    actingAs('admin');
    if (!DB::value("SELECT id FROM cash_sessions WHERE register_id = 1 AND status = 'open'")) {
        S\CashService::open(1, 0);
    }
    // Articles propres à ce test (aucune vente antérieure ne peut les justifier)
    $mk = fn(string $n) => S\ProductService::create(S\ProductService::validate(['name' => $n, 'sale_price' => 500, 'cost_price' => 300, 'track_stock' => 1]), 100, 1);
    [$pa, $pb, $pc, $pd, $pe] = [$mk('Rayon test Cola'), $mk('Rayon test Sucre'), $mk('Rayon test Savon'), $mk('Rayon test Lait'), $mk('Rayon test Riz')];
    $sell = function (int $pid, float $q = 1) {
        actingAs('admin');
        $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => $q]], 'payments' => [['method' => 'cash', 'amount' => 100000]]]);
        return (int) ($r['id'] ?? $r['sale_id']);
    };
    $store = (int) DB::value('SELECT store_id FROM cash_registers WHERE id = 1');
    setting_set('vision_parcours_delai', '15');
    setting_set('vision_parcours_inconnu', '1');
    setting_set('vision_parcours_sortie', '1');

    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/vision/cameras");
    $page = $a->req('GET', "$B/vision/cameras")['body'];
    ok(str_contains($page, 'name="role"') && str_contains($page, 'Contrôle rayon → caisse') && str_contains($page, 'vision_parcours_delai'), 'formulaire : rôle, boutique, lieu, réglages du contrôle');
    $add = function (array $d) use ($a, $B, $t) {
        $a->req('POST', "$B/vision/cameras", http_build_query(['_csrf' => $t, 'kind' => 'usb', 'active' => 1, 'use_surveillance' => 0] + $d));
        return DB::one('SELECT * FROM vision_cameras WHERE name = ? ORDER BY id DESC LIMIT 1', [$d['name']]);
    };
    $shelf = $add(['name' => 'Rayon boissons', 'role' => 'etagere', 'store_id' => $store, 'place' => 'Rayon boissons']);
    $till = $add(['name' => 'Comptoir caisse 1', 'role' => 'caisse', 'register_id' => 1, 'mode' => 'ligne']);
    $door = $add(['name' => 'Porte principale', 'role' => 'sortie', 'store_id' => $store]);
    $old = $add(['name' => 'Passage ancien', 'use_surveillance' => 1]);
    ok($shelf['role'] === 'etagere' && (int) $shelf['use_surveillance'] === 1 && $shelf['place'] === 'Rayon boissons', 'caméra d\'étagère : surveillance activée d\'office');
    ok($till['role'] === 'caisse' && (int) $till['register_id'] === 1 && (int) $till['store_id'] === $store, 'caméra de caisse : caisse rattachée, boutique déduite de la caisse');
    ok($door['role'] === 'sortie' && $door['register_id'] === null, 'caméra de sortie');
    ok($old['role'] === 'passage', 'sans rôle : fonctionnement 1.2 (passages) conservé');
    $a->req('POST', "$B/vision/cameras/{$door['id']}", http_build_query(['_csrf' => $t, 'name' => 'Porte principale', 'kind' => 'usb', 'role' => 'sortie', 'register_id' => 1, 'active' => 1]));
    ok(DB::value('SELECT register_id FROM vision_cameras WHERE id = ?', [$door['id']]) === null, 'une caisse ne se rattache qu\'à une caméra de caisse');

    // Emplacements
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/vision/surveillance")];
    $post = fn(string $u, array $d) => $a->req('POST', $B . $u, json_encode($d), $hdr);
    $sl = json_decode($post("/vision/cameras/{$shelf['id']}/slots", ['slots' => [
        ['label' => 'Cola', 'product_id' => $pa, 'x' => 0.05, 'y' => 0.2, 'w' => 0.25, 'h' => 0.3],
        ['label' => 'Étagère du bas', 'x' => 0.4, 'y' => 0.6, 'w' => 0.3, 'h' => 0.3],
    ]])['body'], true);
    ok($sl['ok'] && count($sl['slots']) === 2 && $sl['slots'][0]['product'] === 'Rayon test Cola' && $sl['slots'][1]['product_id'] === null, 'deux emplacements, l\'un lié à un article');
    [$sCola, $sBas] = [$sl['slots'][0]['id'], $sl['slots'][1]['id']];
    eq(422, $post("/vision/cameras/{$till['id']}/slots", ['slots' => [['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2]]])['code'], 'pas d\'emplacement sur une caméra de caisse');
    eq(422, $post("/vision/cameras/{$shelf['id']}/slots", ['slots' => [['x' => 0.1, 'y' => 0.1, 'w' => 0.01, 'h' => 0.2]]])['code'], 'emplacement trop petit refusé');
    eq(422, $post("/vision/cameras/{$shelf['id']}/slots", ['slots' => [['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2, 'product_id' => 99999999]]])['code'], 'article inconnu refusé');
    $sv = $a->req('GET', "$B/vision/surveillance")['body'];
    ok(str_contains($sv, 'vision-shelf.js') && str_contains($sv, 'survAlerts') && str_contains($sv, 'Tracer un emplacement') && str_contains($sv, 'Rayon test Cola'), 'écran de surveillance : emplacements et panneau des vérifications');

    // Événements refusés selon le rôle
    $ev = fn(array $d) => json_decode($post('/vision/events', $d)['body'], true);
    eq(422, $post('/vision/events', ['camera_id' => $till['id'], 'direction' => 'prise'])['code'], 'une caméra de caisse ne signale pas de prise en rayon');
    eq(422, $post('/vision/events', ['camera_id' => $shelf['id'], 'direction' => 'sortie'])['code'], 'une caméra d\'étagère ne compte pas de sortie');
    eq(422, $post('/vision/events', ['camera_id' => $shelf['id'], 'direction' => 'prise'])['code'], 'prise sans emplacement refusée');
    eq(422, $post('/vision/events', ['camera_id' => $old['id'], 'direction' => 'prise', 'slot_id' => $sCola])['code'], 'caméra « passage » : pas de prise');
    $J = fn(int $id) => DB::one('SELECT * FROM vision_journeys WHERE id = ?', [$id]);
    $expire = fn(int $id) => DB::update('vision_journeys', ['deadline_at' => date('Y-m-d H:i:s', time() - 5)], 'id = ?', [$id]);

    // 1. Pris en rayon (emplacement lié), vu en caisse, encaissé
    $e = $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sCola, 'product_id' => $pb, 'score' => 0.2, 'margin' => 0.01]);
    $j1 = (int) $e['journey']['journey'];
    ok($e['status'] === 'identifie' && $J($j1)['product_id'] == $pa && $J($j1)['identified_by'] === 'emplacement' && $J($j1)['status'] === 'en_cours', 'prise : l\'emplacement nomme l\'article (pas la reconnaissance), suivi ouvert');
    ok((int) $J($j1)['store_id'] === $store && strtotime($J($j1)['deadline_at']) - strtotime($J($j1)['taken_at']) === 900, 'boutique et délai de 15 min');
    $e = $ev(['camera_id' => $till['id'], 'direction' => 'vu', 'product_id' => $pa, 'score' => 0.7, 'margin' => 0.2]);
    eq('vu_caisse', $J($j1)['status'], 'reconnu sur le comptoir : « vu en caisse »');
    $s1 = $sell($pa);
    PS::resolve();
    ok($J($j1)['status'] === 'encaisse' && (int) $J($j1)['sale_id'] === $s1, 'vente enregistrée : suivi clos « encaissé », vente liée');

    // 2. Deux articles pris, un seul vendu : une unité vendue ne justifie qu'une prise
    $j2 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sCola])['journey']['journey'];
    $j3 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sCola])['journey']['journey'];
    $sell($pa);
    PS::resolve();
    ok($J($j2)['status'] === 'encaisse' && $J($j3)['status'] === 'en_cours', 'une vente d\'une unité : un seul suivi clos');
    $expire($j3);
    PS::resolve();
    ok($J($j3)['status'] === 'alerte' && $J($j3)['level'] === 'verifier' && str_contains($J($j3)['reason'], 'ni encaissé ni remis'), 'délai dépassé : vérification demandée');
    // Vente enregistrée après l'alerte (file d'attente) : clos automatiquement, l'alerte reste tracée
    $sell($pa);
    PS::resolve();
    ok($J($j3)['status'] === 'encaisse' && $J($j3)['alerted_at'] !== null && $J($j3)['reason'] === 'Encaissé après l\'alerte', 'vente arrivée après l\'alerte : « encaissé après l\'alerte »');

    // 3. Pris puis remis : clos « remis » ; une remise sans prise ne fait rien
    $j4 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sCola])['journey']['journey'];
    $r = $ev(['camera_id' => $shelf['id'], 'direction' => 'remis', 'slot_id' => $sCola]);
    ok($J($j4)['status'] === 'remis' && $r['journey']['status'] === 'remis', 'remis à son emplacement : suivi clos');
    ok($ev(['camera_id' => $shelf['id'], 'direction' => 'remis', 'slot_id' => $sCola])['journey'] === null, 'remise sans prise suivie (réapprovisionnement) : rien');

    // 4. Emplacement sans article : la reconnaissance sûre nomme l'article ; vu en caisse puis pas de vente → priorité
    $j5 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sBas, 'product_id' => $pb, 'score' => 0.7, 'margin' => 0.2])['journey']['journey'];
    ok($J($j5)['product_id'] == $pb && $J($j5)['identified_by'] === 'reconnaissance', 'emplacement sans article : reconnu par la caméra');
    $ev(['camera_id' => $till['id'], 'direction' => 'vu', 'product_id' => $pb, 'score' => 0.7, 'margin' => 0.2]);
    $expire($j5);
    PS::resolve();
    ok($J($j5)['status'] === 'alerte' && $J($j5)['level'] === 'priorite' && str_contains($J($j5)['reason'], 'Vu en caisse'), 'vu en caisse mais pas encaissé : alerte prioritaire');

    // 5. Reconnaissance incertaine : article non nommé (pas de rapprochement sur une supposition) → alerte faible
    $j6 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sBas, 'product_id' => $pc, 'score' => 0.30, 'margin' => 0.01])['journey']['journey'];
    ok($J($j6)['product_id'] === null && $J($j6)['identified_by'] === 'aucun', 'reconnaissance incertaine : article non nommé');
    $sell($pc);
    $expire($j6);
    PS::resolve();
    ok($J($j6)['status'] === 'alerte' && $J($j6)['level'] === 'faible', 'non identifié : alerte de faible priorité (une vente de l\'article deviné ne le clôt pas)');
    setting_set('vision_parcours_inconnu', '0');
    $j7 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sBas])['journey']['journey'];
    $expire($j7);
    PS::resolve();
    ok($J($j7)['status'] === 'fausse_alerte' && $J($j7)['reviewed_at'] === null, 'option « non identifiés » désactivée : pas d\'alerte');
    setting_set('vision_parcours_inconnu', '1');

    // 6. Caméra de sortie : article pris non encaissé → alerte immédiate ; article vendu → rien
    $j8 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sBas, 'product_id' => $pd, 'score' => 0.8, 'margin' => 0.3])['journey']['journey'];
    $x = $ev(['camera_id' => $door['id'], 'direction' => 'sortie', 'product_id' => $pd, 'score' => 0.8, 'margin' => 0.3]);
    ok($x['journey']['status'] === 'alerte' && $J($j8)['level'] === 'priorite' && $J($j8)['exit_event_id'] !== null && $J($j8)['alerted_at'] !== null, 'sortie sans encaissement : alerte immédiate, sans attendre le délai');
    $sell($pd);
    $x = $ev(['camera_id' => $door['id'], 'direction' => 'sortie', 'product_id' => $pd, 'score' => 0.8, 'margin' => 0.3]);
    ok($x['journey']['status'] === 'encaisse', 'vu à la sortie, vente récente non rapprochée : justifié');
    $x = $ev(['camera_id' => $door['id'], 'direction' => 'sortie', 'product_id' => $pd, 'score' => 0.8, 'margin' => 0.3]);
    ok($x['journey']['status'] === 'alerte' && $x['journey']['level'] === 'verifier', 'une 2ᵉ sortie du même article, une seule vente : à vérifier');
    ok($ev(['camera_id' => $door['id'], 'direction' => 'sortie', 'product_id' => $pd, 'score' => 0.2, 'margin' => 0.01])['journey'] === null, 'sortie incertaine : aucune alerte (pas de supposition)');
    setting_set('vision_parcours_sortie', '0');
    ok($ev(['camera_id' => $door['id'], 'direction' => 'sortie', 'product_id' => $pd, 'score' => 0.8, 'margin' => 0.3])['journey'] === null, 'alerte de sortie désactivable');
    setting_set('vision_parcours_sortie', '1');

    // 7. Boutique : une vente d'une autre boutique ne justifie pas une prise
    $other = (int) DB::insert('stores', ['code' => 'TP' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)), 'name' => 'Boutique test parcours']);
    $j9 = (int) $ev(['camera_id' => $shelf['id'], 'direction' => 'prise', 'slot_id' => $sCola])['journey']['journey'];
    $sx = $sell($pa);
    DB::update('sales', ['store_id' => $other], 'id = ?', [$sx]);
    PS::resolve();
    eq('en_cours', $J($j9)['status'], 'vente dans une autre boutique : pas de rapprochement');
    DB::update('sales', ['store_id' => $store], 'id = ?', [$sx]);
    PS::resolve();
    eq('encaisse', $J($j9)['status'], 'même boutique : encaissé');

    // 8. Article vu sur le comptoir sans prise filmée : suivi depuis la caisse ; rappel au caissier
    $x = $ev(['camera_id' => $till['id'], 'direction' => 'vu', 'product_id' => $pe, 'score' => 0.7, 'margin' => 0.2]);
    $j10 = (int) $x['journey']['journey'];
    ok($J($j10)['origin'] === 'caisse' && $J($j10)['status'] === 'vu_caisse', 'rayon non filmé : suivi ouvert depuis le comptoir');
    $regBefore = DB::value("SELECT register_id FROM users WHERE username = 'admin'");
    DB::update('users', ['register_id' => 1], 'username = ?', ['admin']);
    DB::update('vision_journeys', ['checkout_at' => date('Y-m-d H:i:s', time() - 120)], 'id = ?', [$j10]);
    $rem = fn() => json_decode($a->req('GET', "$B/vision/controles/caisse", null, ['Accept: application/json'])['body'], true);
    ok($rem()['ok'] && $rem()['items'] === [], 'rappel au caissier désactivé par défaut');
    setting_set('vision_parcours_rappel', '1');
    $items = $rem()['items'];
    ok(count($items) === 1 && $items[0]['product'] === 'Rayon test Riz', 'rappel : « vu sur le comptoir, pas encore encaissé » (caisse rattachée)');
    $cfg = json_decode($a->req('GET', "$B/vision/config", null, ['Accept: application/json'])['body'], true);
    ok($cfg['remind'] === true && str_contains((string) file_get_contents(PUBLIC_PATH . '/assets/addons/vision/vision.js'), '/vision/controles/caisse'), 'la caisse interroge le rappel quand il est activé');
    $sell($pe);
    ok($rem()['items'] === [] && $J($j10)['status'] === 'encaisse', 'encaissé : le rappel disparaît');
    setting_set('vision_parcours_rappel', '0');
    DB::update('users', ['register_id' => $regBefore], 'username = ?', ['admin']);

    // 9. Écran en direct, page Contrôles, vérification, cloche, droits
    $live = json_decode($a->req('GET', "$B/vision/controles/live", null, ['Accept: application/json'])['body'], true);
    $ids = array_column($live['alerts'], 'id');
    ok($live['ok'] && in_array($j5, $ids, true) && in_array($j8, $ids, true) && !in_array($j1, $ids, true), 'alertes en direct (seulement celles à vérifier)');
    $a8 = array_values(array_filter($live['alerts'], fn($x) => $x['id'] === $j8))[0];
    ok($a8['level'] === 'priorite' && $a8['product'] === 'Rayon test Lait' && str_contains($a8['where'], 'Rayon boissons'), 'alerte : article, lieu, niveau');
    $pg = $a->req('GET', "$B/vision/controles");
    ok($pg['code'] === 200 && str_contains($pg['body'], 'Rayon test Lait') && str_contains($pg['body'], 'pas une preuve de vol') && str_contains($pg['body'], '2017-28') && str_contains($pg['body'], 'Fiabilité par caméra'), 'page Contrôles');
    $rv = json_decode($post("/vision/controles/$j8", ['action' => 'perte', 'note' => 'Rayon compté : 1 manquant'])['body'], true);
    ok($rv['ok'] && $J($j8)['status'] === 'perte' && $J($j8)['note'] === 'Rayon compté : 1 manquant' && $J($j8)['reviewed_by'] !== null, 'perte confirmée, avec la note');
    ok((bool) DB::value("SELECT 1 FROM audit_logs WHERE action = 'vision.parcours_review' ORDER BY id DESC LIMIT 1"), 'vérification auditée');
    ok(json_decode($post("/vision/controles/$j5", ['action' => 'fausse', 'note' => 'reflet'])['body'], true)['ok'], 'fausse alerte enregistrée');
    eq(422, $post("/vision/controles/$j1", ['action' => 'perte'])['code'], 'un suivi déjà clos ne se « vérifie » pas');
    eq(422, $post("/vision/controles/$j6", ['action' => 'volé'])['code'], 'action inconnue refusée');
    $st = PS::stats(date('Y-m-d'), date('Y-m-d'));
    ok($st['totals']['losses'] === 1 && $st['totals']['false_alerts'] >= 1 && $st['totals']['false_rate'] !== null, 'fiabilité : pertes et part des fausses alertes : ' . json_encode($st['totals']));
    actingAs('admin');
    ok((bool) array_filter(S\Notifications::get(), fn($n) => $n[2] === '/vision/controles'), 'cloche : articles à vérifier');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    foreach (['/vision/controles', '/vision/controles/live'] as $u) {
        eq(403, $c->req('GET', $B . $u)['code'], "$u réservé aux responsables");
    }
    eq(403, $c->req('POST', "$B/vision/controles/$j6", json_encode(['action' => 'fausse']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $c->token("$B/pos")])['code'], 'le caissier ne clôt pas une alerte');
    ok(json_decode($c->req('GET', "$B/vision/controles/caisse", null, ['Accept: application/json'])['body'], true)['ok'], 'le caissier peut recevoir le rappel de sa caisse');

    // 10. Le rapport « passages vus / ventes » ignore les prises en rayon (ce ne sont pas des passages)
    $cmp = Addons\Vision\SurveillanceService::compare(date('Y-m-d'), date('Y-m-d'), [$shelf['id']], 20);
    eq(0, $cmp['totals']['events'], 'prises et remises exclues de la comparaison des passages');

    // Nettoyage
    DB::run('DELETE FROM vision_journeys');
    DB::run('DELETE FROM vision_events WHERE camera_id IN (?, ?, ?, ?)', [$shelf['id'], $till['id'], $door['id'], $old['id']]);
    DB::run('DELETE FROM vision_cameras WHERE id IN (?, ?, ?, ?)', [$shelf['id'], $till['id'], $door['id'], $old['id']]);
    DB::run('DELETE FROM stores WHERE id = ?', [$other]);
    // Articles de ce test archivés (ils ont des ventes) : ils ne doivent pas rendre ambiguës les questions posées
    // ensuite à l'assistant (« le stock du savon » ne doit pas trouver deux savons)
    DB::run("UPDATE products SET status = 'archived' WHERE id IN (?, ?, ?, ?, ?)", [$pa, $pb, $pc, $pd, $pe]);
});
