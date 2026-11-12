<?php
/**
 * MARWA AI 2.0 — tests (inclus par tests/run.php, après les autres modules : la base contient alors
 * des ventes, du stock, des clients, des fournisseurs, des caisses…). Variables reçues : $B, $port.
 *
 * Les valeurs attendues sont recalculées ici, directement en SQL, sans passer par le moteur de MARWA AI.
 */
declare(strict_types=1);

use Addons\MarwaIa\Brain as AI;
use App\Core\DB;

/** Une exception métier est attendue ; $what décrit le cas (message d'échec plus lisible). */
function ai_throws(callable $fn, string $what): void
{
    try {
        throws($fn);
    } catch (Throwable $e) {
        throw new RuntimeException($what . ' : ' . $e->getMessage());
    }
}

/** Pose une question comme l'utilisateur $user (droits rechargés à chaque fois). */
function ai_ask(string $user, string $q, ?int &$conv = null): array
{
    actingAs($user);
    AI\UserContext::reset();
    $ev = [];
    $r = AI\Brain::handle($q, $conv, function (string $e, array $d) use (&$ev) {
        $ev[] = [$e, $d];
    });
    $conv = $r['conversation_id'];
    $r['events'] = $ev;
    $r['all'] = $r['text'] . ' ' . (string) ($r['html'] ?? '') . ' ' . implode(' ', array_map(fn($b) => json_encode($b, JSON_UNESCAPED_UNICODE), $r['blocks']));
    return $r;
}

test('MARWA AI : migration, inventaire automatique de l\'application, dictionnaire, réglages conservés', function () {
    actingAs('admin');
    App\Core\Addons::registerAutoload();
    $tables = array_column(DB::all("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'ai\\_%'"), 'TABLE_NAME');
    sort($tables);
    eq(['ai_action_logs', 'ai_conversations', 'ai_documentation_index', 'ai_feedback', 'ai_intent_examples', 'ai_intents', 'ai_knowledge_base', 'ai_learning_logs', 'ai_messages', 'ai_model_settings',
        'ai_tool_registry', 'ai_usage_logs', 'ai_user_context'], $tables, '13 tables ai_*');
    ok(is_file(BASE_PATH . '/addons/marwa_ia/sql/down_0003.sql'), 'script de retour arrière fourni');
    ok(DB::value('SELECT 1 FROM ia_history LIMIT 1') !== false, 'tables 1.x conservées (ia_history)');
    AI\Learning::seed(true);
    eq(count(AI\Catalog::TOOLS), (int) DB::value('SELECT COUNT(*) FROM ai_intents'), 'une intention par outil de données');
    ok((int) DB::value("SELECT COUNT(*) FROM ai_intent_examples WHERE source = 'builtin'") > 150, 'exemples d\'entraînement intégrés');
    ok((int) DB::value("SELECT COUNT(*) FROM ai_tool_registry WHERE kind = 'action' AND needs_confirmation = 1") >= 4, 'outils d\'action enregistrés, avec confirmation');
    ok((int) DB::value("SELECT COUNT(*) FROM ai_knowledge_base WHERE source = 'builtin'") > 10, 'FAQ intégrée reprise dans la base de connaissances');
    // Inventaire automatique
    ok(AI\AppMap::ensureFresh(true), 'reconstruction forcée');
    $st = AI\AppMap::stats();
    foreach (['module', 'menu', 'route', 'permission', 'table', 'setting', 'doc'] as $k) {
        ok(($st[$k] ?? 0) > 0, "index : $k (" . ($st[$k] ?? 0) . ')');
    }
    ok(!AI\AppMap::ensureFresh(), 'pas de reconstruction inutile');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM ai_documentation_index WHERE kind = 'route' AND ref = 'POST /ia/v2/stream'"), 'les routes des modules sont découvertes (POST /ia/v2/stream)');
    ok((int) DB::value("SELECT COUNT(*) FROM ai_documentation_index WHERE kind = 'module' AND ref = 'pay'") === 1, 'modules découverts depuis leurs manifestes');
    $company = (string) setting('company_name', '');
    ok($company === '' || !DB::value("SELECT 1 FROM ai_documentation_index WHERE kind = 'setting' AND body LIKE ?", ['%' . $company . '%']), 'valeurs des paramètres jamais indexées');
    ok(!DB::value("SELECT 1 FROM ai_documentation_index WHERE body LIKE '%password_hash%' AND kind <> 'table'"), 'aucun secret dans l\'index');
    // Changement de schéma : l'empreinte change → reconstruction automatique
    $sig = AI\AppMap::signature();
    DB::run('CREATE TABLE ai_tmp_test (id INT PRIMARY KEY)');
    ok($sig !== AI\AppMap::signature(), 'nouvelle table : empreinte modifiée');
    setting_set('ai_index_checked_at', '0');
    ok(AI\AppMap::ensureFresh(), 'reconstruction automatique après changement du schéma');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM ai_documentation_index WHERE kind = 'table' AND ref = 'ai_tmp_test'"), 'nouvelle table connue sans documentation manuelle');
    DB::run('DROP TABLE ai_tmp_test');
    // Dictionnaire
    $sch = AI\DataDictionary::schema();
    ok(isset($sch['sales']['columns']) && $sch['sales']['description'] !== '', 'dictionnaire : table sales décrite');
    ok(in_array('customer_id → customers.id', $sch['sales']['fks'], true) || count($sch['sales']['fks']) > 0, 'dictionnaire : clés étrangères');
    // Réglages : jamais écrasés par une réinstallation
    setting_set('ai_retention_days', '45');
    App\Core\Addons::install('marwa_ia', false);
    eq('45', (string) setting('ai_retention_days'), 'réglage conservé après réinstallation');
    setting_set('ai_retention_days', '90');
});

test('MARWA AI : banc de 114 questions réelles (simples, fautes, synonymes, suites, multi-demandes, périodes, droits, hors sujet, données absentes, rapports)', function () {
    actingAs('admin');
    $uid = fn(string $u) => (int) DB::value('SELECT id FROM users WHERE username = ?', [$u]);
    $d0 = date('Y-m-d 00:00:00');
    $d1 = date('Y-m-d 23:59:59');
    $y0 = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $y1 = date('Y-m-d 23:59:59', strtotime('-1 day'));
    $sales = fn(string $from, string $to, ?int $user = null) => DB::one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM sales WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?" . ($user ? ' AND user_id = ' . $user : ''), [$from, $to]);
    $today = $sales($d0, $d1);
    $todayC = $sales($d0, $d1, $uid('caissier'));
    $yest = $sales($y0, $y1);
    $stock = (float) DB::value('SELECT COALESCE(SUM(l.quantity * p.cost_price),0) FROM stock_levels l JOIN products p ON p.id = l.product_id WHERE p.track_stock = 1 AND l.quantity > 0');
    $whStock = fn(string $wh) => (float) DB::value('SELECT COALESCE(SUM(l.quantity * p.cost_price),0) FROM stock_levels l JOIN products p ON p.id = l.product_id JOIN warehouses w ON w.id = l.warehouse_id WHERE p.track_stock = 1 AND l.quantity > 0 AND w.name = ?', [$wh]);
    $debts = (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM customers WHERE active = 1 AND balance > 0');
    $payables = (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM suppliers WHERE active = 1 AND balance > 0');
    $outN = (int) DB::value("SELECT COUNT(*) FROM products p LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) x ON x.product_id = p.id WHERE p.status = 'active' AND p.track_stock = 1 AND p.sellable = 1 AND COALESCE(x.q,0) <= 0");
    $lastSale = (string) DB::value('SELECT number FROM sales ORDER BY created_at DESC, id DESC LIMIT 1');
    $m0 = date('Y-m-01 00:00:00');
    $month = $sales($m0, $d1);
    $soap = DB::one("SELECT p.name, COALESCE(SUM(l.quantity),0) q FROM products p LEFT JOIN stock_levels l ON l.product_id = p.id WHERE p.name = 'Savon de ménage' GROUP BY p.id");
    $n = fn(float $v) => money(round($v, 2));
    $REFUSE = 'Je ne peux pas';
    // [utilisateur, question, intention attendue (null = aucune), textes attendus, textes interdits, conversation]
    $cases = [
        // Questions simples
        ['admin', 'Quelle est la valeur de mon stock ?', 'stock_value', [$n($stock)]],
        ['admin', 'Valeur du stock au prix de vente', 'stock_value_sale', []],
        ['admin', 'Combien d\'articles en stock ?', 'stock_count', []],
        ['admin', 'Quels produits sont en rupture ?', 'stock_out', [(string) $outN]],
        ['admin', 'Quels produits ont un stock faible ?', 'stock_low', []],
        ['admin', 'Qui me doit de l\'argent ?', 'customer_debts', [$n($debts)]],
        ['admin', 'Quels fournisseurs doivent être payés ?', 'supplier_payables', [$n($payables)]],
        ['admin', 'Combien de ventes aujourd\'hui ?', 'sales_summary', [(string) $today['n'], $n((float) $today['t'])]],
        ['admin', 'Chiffre d\'affaires du mois', 'revenue', [$n((float) $month['t'])]],
        ['admin', 'Bénéfice du mois', 'profit', ['bénéfice']],
        ['admin', 'Dépenses du mois', 'expenses_total', []],
        ['admin', 'Dernière vente', 'last_sale', [$lastSale]],
        ['admin', 'Les 5 dernières ventes', 'last_sales', []],
        ['admin', 'Meilleures ventes du mois', 'top_products', []],
        ['admin', 'Ventes par mode de paiement ce mois', 'sales_by_method', []],
        ['admin', 'Ventes par vendeur ce mois', 'sales_by_user', []],
        ['admin', 'Ventes par catégorie', 'sales_by_category', []],
        ['admin', 'Heure de pointe', 'sales_by_hour', []],
        ['admin', 'Ventes annulées ce mois', 'cancelled_sales', []],
        ['admin', 'Retours du mois', 'returns', []],
        ['admin', 'Remises et TVA du mois', 'discounts_tax', ['remises']],
        ['admin', 'Solde de la caisse', 'cash_balance', []],
        ['admin', 'Entrées de caisse aujourd\'hui', 'cash_in', []],
        ['admin', 'Sorties de caisse aujourd\'hui', 'cash_out', []],
        ['admin', 'Écarts de caisse du mois', 'cash_difference', []],
        ['admin', 'Qui a effectué la dernière sortie de caisse ?', 'last_cash_out', []],
        ['admin', 'Combien de clients ?', 'customers_count', []],
        ['admin', 'Meilleur client', 'top_customer', []],
        ['admin', 'Achats du mois', 'purchases_total', []],
        ['admin', 'Commandes fournisseur en cours', 'purchase_orders', []],
        ['admin', 'Articles qui ne se vendent pas', 'dormant', []],
        ['admin', 'Produits qui périment bientôt', 'expiring', []],
        ['admin', 'Transferts de stock du mois', 'transfers', []],
        ['admin', 'Ajustements de stock', 'adjustments', []],
        ['admin', 'Inventaires en cours', 'inventories', []],
        ['admin', 'Meilleur jour de vente du mois', 'best_day', []],
        ['admin', 'Produits les plus rentables', 'most_profitable', []],
        ['admin', 'Combien d\'utilisateurs ?', 'users_count', []],
        ['admin', 'Qui suis-je ?', 'whoami', ['Admin']],
        ['admin', 'Paiements électroniques du mois', 'epay_summary', []],
        ['admin', 'Factures certifiées du mois', 'fiscal_summary', []],
        ['admin', 'Recettes et dépenses du mois', 'income_vs_expenses', []],
        ['admin', 'Résumé de l\'activité de la semaine', 'activity_summary', []],
        ['admin', 'Fais-moi un bilan de la journée', 'daily_report', [$n((float) $today['t'])]],
        ['admin', 'Compare les boutiques', 'compare_stores', []],
        ['admin', 'Dernières transactions', 'last_transactions', []],
        ['admin', 'Quel caissier a réalisé le plus de ventes ?', 'top_cashier', []],
        ['admin', 'Ventes du mois par boutique', 'sales_by_store', []],
        ['admin', 'Quels produits dois-je recommander ?', 'reorder', []],
        ['admin', 'Identifie les anomalies dans les transactions', 'anomalies', ['pas des preuves de fraude']],
        ['admin', 'stock du savon', 'product_stock', [$soap ? 'Savon de ménage' : 'article']],
        // Fautes d'orthographe et écriture rapide
        ['admin', 'valeur du stok', 'stock_value', [$n($stock)]],
        ['admin', 'combien jai vendu ojourdui', 'sales_summary', [$n((float) $today['t'])]],
        ['admin', 'produit en ruptur', 'stock_out', []],
        ['admin', 'qui me doi de largent', 'customer_debts', [$n($debts)]],
        ['admin', 'benefise du mois', 'profit', []],
        ['admin', 'chifre daffaire du mois', 'revenue', []],
        ['admin', 'depence du mois', 'expenses_total', []],
        // Synonymes et formulations libres
        ['admin', 'Combien valent les articles actuellement en magasin ?', 'stock_value', [$n($stock)]],
        ['admin', 'Mon stock représente combien d\'argent ?', 'stock_value', [$n($stock)]],
        ['admin', 'Combien de tickets ont été émis aujourd\'hui ?', 'sales_summary', [(string) $today['n']]],
        ['admin', 'produits épuisés', 'stock_out', []],
        ['admin', 'est-ce que je gagne de l\'argent', 'profit', []],
        ['admin', 'Quelles sont mes créances clients ?', 'customer_debts', [$n($debts)]],
        ['admin', 'mon CA du mois', 'revenue', []],
        ['admin', 'Total encaissé aujourd\'hui', 'sales_summary', [$n((float) $today['t'])]],
        // Périodes
        ['admin', 'Ventes d\'hier', 'sales_summary', [(string) $yest['n'], $n((float) $yest['t'])]],
        ['admin', 'Ventes de la semaine dernière', 'sales_summary', []],
        ['admin', 'Ventes du mois dernier', 'sales_summary', []],
        ['admin', 'Ventes de l\'année', 'sales_summary', []],
        ['admin', 'Ventes du 1er au 5 janvier 2001', 'sales_summary', ['Aucune vente'], [$n((float) $today['t'])]],
        ['admin', 'Ventes de l\'année 1999', 'sales_summary', ['Aucune vente', '1999']],
        ['admin', 'Ventes de lannée 1999', 'sales_summary', ['Aucune vente']],
        // Questions successives (même conversation)
        ['admin', 'Ventes d\'aujourd\'hui', 'sales_summary', [$n((float) $today['t'])], [], 'suite1'],
        ['admin', 'et hier ?', 'sales_summary', [$n((float) $yest['t'])], [], 'suite1'],
        ['admin', 'fais la comparaison', 'sales_compare', [$n((float) $today['t']), $n((float) $yest['t'])], [], 'suite1'],
        ['admin', 'et les dépenses ?', 'expenses_total', [], [], 'suite1'],
        ['admin', 'Valeur du stock du dépôt', 'stock_value', [$n($whStock('Dépôt'))], [], 'suite2'],
        ['admin', 'et dans le magasin principal ?', 'stock_value', [$n($whStock('Magasin principal'))], [], 'suite2'],
        ['admin', 'Qui est le président du Niger ?', null, ['hors de ce domaine'], [$n($whStock('Magasin principal'))], 'suite2'],
        // Plusieurs demandes dans une question
        ['admin', 'Donne-moi la valeur du stock et les produits en rupture', 'stock_out', [$n($stock), $outN . ' article']],
        ['admin', 'Combien de ventes aujourd\'hui ? Et qui me doit de l\'argent ?', 'customer_debts', [$n((float) $today['t']), $n($debts)]],
        ['admin', 'ventes d\'hier puis dépenses du mois', 'expenses_total', [$n((float) $yest['t'])]],
        // Ambiguës / données inexistantes
        ['admin', 'Combien ?', null, ['Précisez']],
        ['admin', 'Quel est le stock de l\'article Zorglub ?', 'product_stock', ['article']],
        ['admin', 'Rapport du client Inconnu Xyzzy', 'legacy', ['aucun client']],
        // Hors sujet
        ['admin', 'Raconte-moi une blague', 'legacy', ['domaine']],
        ['admin', 'Quelle est la météo demain ?', 'legacy', ['domaine']],
        // Définitions, transactions, aide pas à pas, capacités, module absent
        ['admin', 'C\'est quoi la différence entre le brut et le net ?', 'definition', ['brut', 'net']],
        ['admin', 'Que veut dire TVA ?', 'definition', ['TVA']],
        ['admin', 'Quels sont les types de transactions ?', 'transaction_types', []],
        ['admin', 'Comment faire un inventaire ?', 'help', ['nventaire']],
        ['admin', 'Comment clôturer la caisse ?', 'help', ['caisse']],
        ['admin', 'Comment créer un article ?', 'help', ['rticle']],
        ['admin', 'Que sais-tu faire ?', 'capabilities', ['confirmation']],
        ['admin', 'Combien de tickets wifi sont expirés ?', 'wifi_tickets', ['ne comporte pas']],
        // Actions (proposées, jamais exécutées sans confirmation)
        ['admin', 'Prépare un inventaire du dépôt', 'action_inventory_start', ['Rien n\'est fait']],
        ['admin', 'Supprime l\'article Savon', 'action_unsupported', ['archive']],
        ['admin', 'Ouvre les inventaires', 'action_navigate', ['Inventaires']],
        // Rapports et domaines du moteur 1.x conservés
        ['admin', 'Ventes d\'hier en PDF', 'legacy', [$n((float) $yest['t'])]],
        ['admin', 'Ventes de 1999 en PDF', 'legacy:ventes', ['1999'], [$n((float) $today['t'])]],
        ['admin', 'Mon plan marketing en PDF', 'legacy', ['marketing']],
        ['admin', 'Ma formation', 'legacy', ['formation']],
        ['admin', 'Vérifie ma configuration', 'legacy', ['configuration']],
        // Droits limités : caissier (ses ventes seulement, pas de coûts ni de marges)
        ['caissier', 'Combien j\'ai vendu aujourd\'hui ?', 'sales_summary', ['vos ventes', $n((float) $todayC['t'])]],
        ['caissier', 'Ventes de l\'admin aujourd\'hui', 'sales_summary', ['propres ventes'], [$n((float) $today['t']) === $n((float) $todayC['t']) ? '§' : $n((float) $today['t'])]],
        ['caissier', 'Quel est le bénéfice du mois ?', 'profit', [$REFUSE], ['marge']],
        ['caissier', 'Valeur du stock', 'stock_value', [$REFUSE], [$n($stock)]],
        ['caissier', 'Combien d\'utilisateurs ?', 'users_count', [$REFUSE]],
        ['caissier', 'Fais une sortie de caisse de 500 pour le pain', 'action_cash_out', [$REFUSE]],
        ['caissier', 'Annule la vente V2026-000001 pour erreur', 'action_sale_cancel', [$REFUSE]],
        // Gestionnaire de stock : pas de chiffre d'affaires
        ['magasin', 'Chiffre d\'affaires du mois', 'revenue', [$REFUSE], [$n((float) $month['t'])]],
        // Responsable de magasin rattaché à sa boutique
        ['gerant', 'Bénéfice du mois', 'profit', ['bénéfice']],
        ['gerant', 'Combien d\'utilisateurs ?', 'users_count', [$REFUSE]],
    ];
    ok(count($cases) >= 100, count($cases) . ' questions');
    $convs = [];
    $fails = [];
    $report = [];
    $cats = 0;
    foreach ($cases as $i => $c) {
        [$user, $q, $intent, $has] = $c;
        $not = $c[4] ?? [];
        $key = $c[5] ?? null;
        $cv = $key ? ($convs[$key] ?? null) : null;
        $r = ai_ask($user, $q, $cv);
        if ($key) {
            $convs[$key] = $cv;
        }
        $got = $r['intent'];
        $okI = $intent === 'legacy' ? str_starts_with((string) $got, 'legacy') : $got === $intent;
        $miss = array_values(array_filter($has, fn($h) => !str_contains($r['all'], $h)));
        $bad = array_values(array_filter($not, fn($h) => $h !== '§' && str_contains($r['all'], $h)));
        $pass = $okI && !$miss && !$bad;
        if (!$pass) {
            $fails[] = sprintf('#%d [%s] « %s » → %s (attendu %s)%s%s — %s', $i + 1, $user, $q, $got ?? 'aucune', $intent ?? 'aucune', $miss ? ' manque : ' . implode(' | ', $miss) : '', $bad ? ' interdit présent : ' . implode(' | ', $bad) : '', mb_substr($r['text'], 0, 160));
        }
        $report[] = [$i + 1, $user, $q, $intent ?? '—', $got ?? '—', $pass ? 'OK' : 'ÉCHEC', mb_substr(preg_replace('/\s+/', ' ', $r['text']), 0, 140)];
    }
    if ($path = getenv('AI_BENCH_REPORT')) {
        $md = "| # | Utilisateur | Question | Attendu | Obtenu | Résultat | Début de la réponse |\n|---|---|---|---|---|---|---|\n";
        foreach ($report as $l) {
            $md .= '| ' . implode(' | ', array_map(fn($x) => str_replace('|', '\\|', (string) $x), $l)) . " |\n";
        }
        file_put_contents($path, $md);
    }
    ok(!$fails, count($fails) . " échec(s) sur " . count($cases) . " :\n      " . implode("\n      ", $fails));
});

test('MARWA AI : mémoire (conversations séparées par utilisateur, suppression, conservation), avis, apprentissage contrôlé', function () use ($B) {
    $ca = null;
    $r = ai_ask('admin', 'Quelle est la valeur de mon stock ?', $ca);
    $mid = $r['message_id'];
    eq(2, (int) DB::value('SELECT COUNT(*) FROM ai_messages WHERE conversation_id = ?', [$ca]), 'question et réponse enregistrées');
    eq('Quelle est la valeur de mon stock ?', DB::value('SELECT title FROM ai_conversations WHERE id = ?', [$ca]), 'titre tiré de la première question');
    $state = json_decode((string) DB::value('SELECT context_json FROM ai_conversations WHERE id = ?', [$ca]), true);
    eq('stock_value', $state['intent'] ?? null, 'contexte mémorisé pour les questions de suite');
    actingAs('caissier');
    $cid = (int) DB::value("SELECT id FROM users WHERE username = 'caissier'");
    ai_throws(fn() => AI\Memory::messages($cid, $ca), 'un autre utilisateur ne lit pas la conversation');
    ai_throws(fn() => AI\Brain::handle('et hier ?', $ca, fn() => null), 'ni ne peut la poursuivre');
    ai_throws(fn() => AI\Learning::feedback($mid, 1, null), 'ni noter ses messages');
    ok(!in_array($ca, array_column(AI\Memory::list($cid), 'id')), 'absente de sa liste');
    actingAs('admin');
    AI\Learning::feedback($mid, -1, 'Je voulais le stock au prix de vente');
    eq(-1, (int) DB::value('SELECT rating FROM ai_feedback WHERE message_id = ?', [$mid]), 'avis enregistré');
    // Anonymisation et apprentissage validé par l'administrateur
    $cv = null;
    ai_ask('admin', 'appelle le 90 12 34 56 ou ecris a client@exemple.ne pour le zorglubage', $cv);
    $log = DB::one('SELECT * FROM ai_learning_logs ORDER BY id DESC LIMIT 1');
    ok(!str_contains($log['question'], '90 12 34 56') && !str_contains($log['question'], 'client@exemple.ne'), 'question anonymisée : ' . $log['question']);
    $q = 'combien pese mon entrepot en argent';
    $before = AI\Nlu::classify($q);
    $cv = null;
    ai_ask('admin', $q, $cv);
    $lid = (int) DB::value('SELECT id FROM ai_learning_logs WHERE question = ? ORDER BY id DESC LIMIT 1', [$q]);
    AI\Learning::teach($lid, 'stock_value');
    AI\Nlu::reset();
    $after = AI\Nlu::classify($q);
    eq('stock_value', $after['intent'], 'après validation, la formulation est comprise (avant : ' . $before['intent'] . ' ' . $before['score'] . ')');
    eq(1, (int) DB::value('SELECT resolved FROM ai_learning_logs WHERE id = ?', [$lid]), 'question marquée résolue');
    ai_throws(fn() => AI\Learning::teach($lid, 'intention_inexistante'), 'intention inconnue refusée');
    $res = AI\Learning::bench(AI\Learning::BENCH);
    $okN = count(array_filter($res, fn($x) => $x['ok']));
    ok($okN >= (int) ceil(count($res) * 0.9), "banc intégré de l'écran Pilotage : $okN / " . count($res));
    // Suppression et conservation
    AI\Memory::rename(DB::value('SELECT user_id FROM ai_conversations WHERE id = ?', [$ca]), $ca, 'Stock');
    eq('Stock', DB::value('SELECT title FROM ai_conversations WHERE id = ?', [$ca]), 'renommée');
    $old = AI\Memory::conversation((int) App\Core\Auth::id(), null);
    DB::update('ai_conversations', ['updated_at' => date('Y-m-d H:i:s', strtotime('-200 days'))], 'id = ?', [$old['id']]);
    ok(AI\Memory::purge() >= 1 && !DB::value('SELECT 1 FROM ai_conversations WHERE id = ?', [$old['id']]), 'purge au-delà de la durée de conservation');
    AI\Memory::delete((int) App\Core\Auth::id(), $ca);
    eq(0, (int) DB::value('SELECT COUNT(*) FROM ai_messages WHERE conversation_id = ?', [$ca]), 'suppression : messages effacés');
});

test('MARWA AI : actions préparées puis confirmées (jeton, délai, droits, mot de passe pour les opérations sensibles)', function () {
    $cv = null;
    $r = ai_ask('admin', 'Prépare un inventaire du dépôt', $cv);
    $p = $r['proposal'];
    ok($p && $p['token'] && !$p['sensitive'], 'proposition avec jeton');
    $inv = (int) DB::value('SELECT COUNT(*) FROM inventories');
    eq($inv, (int) DB::value('SELECT COUNT(*) FROM inventories'), 'rien n\'est créé avant confirmation');
    $U = AI\UserContext::current();
    ai_throws(fn() => AI\Actions::confirm($p['id'], 'mauvais-jeton', null, $U), 'jeton faux refusé');
    actingAs('caissier');
    AI\UserContext::reset();
    ai_throws(fn() => AI\Actions::confirm($p['id'], $p['token'], null, AI\UserContext::current()), 'un autre utilisateur ne peut pas confirmer');
    actingAs('admin');
    AI\UserContext::reset();
    $res = AI\Actions::confirm($p['id'], $p['token'], null, AI\UserContext::current());
    eq($inv + 1, (int) DB::value('SELECT COUNT(*) FROM inventories'), 'inventaire ouvert par la fonction métier : ' . $res['text']);
    eq('executed', DB::value('SELECT status FROM ai_action_logs WHERE id = ?', [$p['id']]), 'action journalisée');
    ok((bool) DB::value("SELECT 1 FROM audit_logs WHERE action = 'ai.action' ORDER BY id DESC LIMIT 1"), 'et auditée');
    ai_throws(fn() => AI\Actions::confirm($p['id'], $p['token'], null, AI\UserContext::current()), 'pas de double exécution');
    // Brouillon de commande fournisseur
    $r = ai_ask('admin', 'Prépare un brouillon de commande fournisseur', $cv);
    if ($r['proposal']) {
        $po = (int) DB::value('SELECT COUNT(*) FROM purchase_orders');
        AI\Actions::confirm($r['proposal']['id'], $r['proposal']['token'], null, AI\UserContext::current());
        ok((int) DB::value('SELECT COUNT(*) FROM purchase_orders') > $po && DB::value('SELECT status FROM purchase_orders ORDER BY id DESC LIMIT 1') === 'draft', 'commande créée au statut brouillon');
    } else {
        ok(str_contains($r['text'], 'rien à commander') || str_contains($r['text'], 'fournisseur'), 'pas de proposition sans article à commander : ' . $r['text']);
    }
    // Délai dépassé
    $r = ai_ask('admin', 'Prépare un inventaire du magasin principal', $cv);
    DB::update('ai_action_logs', ['expires_at' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$r['proposal']['id']]);
    ai_throws(fn() => AI\Actions::confirm($r['proposal']['id'], $r['proposal']['token'], null, AI\UserContext::current()), 'délai de confirmation dépassé');
    eq('expired', DB::value('SELECT status FROM ai_action_logs WHERE id = ?', [$r['proposal']['id']]), 'marquée expirée');
    // Opération financière : mot de passe exigé
    $session = DB::one("SELECT cs.id FROM cash_sessions cs WHERE cs.status = 'open' AND cs.user_id = ? ORDER BY cs.id DESC LIMIT 1", [App\Core\Auth::id()]);
    $r = ai_ask('admin', 'Fais une sortie de caisse de 1500 pour le transport', $cv);
    if (!$session) {
        ok(!$r['proposal'] && str_contains($r['text'], 'caisse'), 'pas de caisse ouverte : aucune proposition (' . $r['text'] . ')');
    } else {
        ok($r['proposal'] && $r['proposal']['sensitive'], 'sortie de caisse : opération sensible');
        $mv = (int) DB::value('SELECT COUNT(*) FROM cash_movements');
        try {
            AI\Actions::confirm($r['proposal']['id'], $r['proposal']['token'], null, AI\UserContext::current());
            ok(false, 'aurait dû exiger le mot de passe');
        } catch (App\Core\BusinessException $e) {
            ok(str_starts_with($e->getMessage(), 'AUTH_REQUIRED:'), 'mot de passe demandé');
        }
        ai_throws(fn() => AI\Actions::confirm($r['proposal']['id'], $r['proposal']['token'], 'mauvais', AI\UserContext::current()), 'mauvais mot de passe refusé');
        eq($mv, (int) DB::value('SELECT COUNT(*) FROM cash_movements'), 'aucun mouvement sans authentification');
        AI\Actions::confirm($r['proposal']['id'], $r['proposal']['token'], 'Essai-Niamey-2026', AI\UserContext::current());
        $mvt = DB::one('SELECT type, amount FROM cash_movements ORDER BY id DESC LIMIT 1');
        ok($mvt['type'] === 'cash_out' && abs((float) $mvt['amount']) === 1500.0, 'sortie de 1 500 F enregistrée par CashService après authentification');
    }
    $r = ai_ask('admin', 'Prépare un inventaire du magasin principal', $cv);
    ok((bool) $r['proposal'], 'proposition : ' . $r['text']);
    AI\Actions::cancel($r['proposal']['id'], AI\UserContext::current());
    eq('cancelled', DB::value('SELECT status FROM ai_action_logs WHERE id = ?', [$r['proposal']['id']]), 'annulation');
});

test('MARWA AI : modèles de langage (OpenAI, Claude, Gemini, Ollama) en continu, clé chiffrée, secours, limites, garde-fou des chiffres', function () use ($port) {
    actingAs('admin');
    $fport = $port + 17;
    $logf = sys_get_temp_dir() . "/fake-llm-$fport.log";
    @unlink($logf);
    $api = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $fport, escapeshellarg(BASE_PATH . '/tests/fake-llm-api.php')), [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $ap, null, array_merge(getenv(), ['FAKE_LLM_LOG' => $logf]));
    usleep(500000);
    $P = AI\Providers::class;
    $reqs = function () use ($logf) {
        return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents($logf))));
    };
    try {
        DB::run('UPDATE ai_model_settings SET active = 0, is_default = 0, is_fallback = 0');
        // Fournisseur externe : HTTPS obligatoire, accord explicite exigé
        ai_throws(fn() => $P::save(['provider' => 'openai', 'name' => 'x', 'model' => 'gpt-x', 'base_url' => 'http://api.exemple.com/v1', 'api_key' => 'k']), 'HTTP refusé pour un fournisseur externe');
        $ext = $P::save(['provider' => 'anthropic', 'name' => 'Claude externe', 'model' => 'claude-opus-5-5', 'api_key' => 'sk-ant-vraie-cle-123456']);
        ai_throws(fn() => $P::setRole($ext, 'default'), 'externe sans accord : non activable');
        $enc = (string) DB::value('SELECT api_key_enc FROM ai_model_settings WHERE id = ?', [$ext]);
        ok(str_starts_with($enc, 'A1.') && !str_contains($enc, 'sk-ant-vraie-cle'), 'clé chiffrée en base');
        eq('sk-ant-vraie-cle-123456', $P::decrypt($enc), 'clé déchiffrable par le serveur seulement');
        // OpenAI (format chat/completions en flux)
        $oa = $P::save(['provider' => 'openai', 'name' => 'OpenAI test', 'model' => 'gpt-test', 'base_url' => "http://127.0.0.1:$fport/v1", 'api_key' => 'sk-test-openai', 'input_cost_per_mtok' => '2', 'output_cost_per_mtok' => '8']);
        $P::setRole($oa, 'default');
        $deltas = [];
        $g = $P::generate('système', [['role' => 'user', 'content' => 'Bonjour']], 'test', function ($d) use (&$deltas) { $deltas[] = $d; }, 100);
        ok($g && count($deltas) >= 3 && implode('', $deltas) === $g['text'], 'OpenAI : texte reçu morceau par morceau (' . count($deltas) . ' morceaux)');
        $u = DB::one('SELECT * FROM ai_usage_logs ORDER BY id DESC LIMIT 1');
        ok((int) $u['input_tokens'] === 120 && (int) $u['output_tokens'] === 30 && abs((float) $u['cost'] - (120 * 2 + 30 * 8) / 1e6) < 1e-9, 'jetons et coût enregistrés');
        $last = $reqs()[count($reqs()) - 1];
        ok(isset($last['body']['max_completion_tokens']) && !isset($last['body']['temperature']) && $last['body']['stream'] === true, 'OpenAI : paramètres de la requête');
        // Claude (Messages API en flux)
        $cl = $P::save(['provider' => 'anthropic', 'name' => 'Claude test', 'model' => 'claude-opus-5-5', 'base_url' => "http://127.0.0.1:$fport", 'api_key' => 'sk-ant-test']);
        $P::setRole($cl, 'default');
        $deltas = [];
        $g = $P::generate('système', [['role' => 'user', 'content' => 'Bonjour']], 'test', function ($d) use (&$deltas) { $deltas[] = $d; }, 100);
        ok($g && count($deltas) >= 3 && $g['model'] === 'Claude test', 'Claude : flux SSE lu');
        $last = $reqs()[count($reqs()) - 1];
        ok(($last['headers']['anthropic-version'] ?? '') === AI\Providers::ANTHROPIC_VERSION && !isset($last['body']['temperature']) && ($last['body']['output_config']['effort'] ?? '') === 'low'
            && ($last['body']['fallbacks'] ?? '') === 'default' && str_contains($last['headers']['anthropic-beta'] ?? '', 'server-side-fallback'), 'Claude : version d\'API, effort, repli serveur, pas de température');
        eq(210, (int) DB::value('SELECT input_tokens FROM ai_usage_logs ORDER BY id DESC LIMIT 1'), 'Claude : jetons d\'entrée');
        // Gemini
        $gm = $P::save(['provider' => 'gemini', 'name' => 'Gemini test', 'model' => 'gemini-test', 'base_url' => "http://127.0.0.1:$fport", 'api_key' => 'gm-test']);
        $P::setRole($gm, 'default');
        $g = $P::generate('système', [['role' => 'user', 'content' => 'Bonjour']], 'test', null, 100);
        $last = $reqs()[count($reqs()) - 1];
        ok($g && isset($last['body']['systemInstruction']) && str_contains($last['path'], ':streamGenerateContent'), 'Gemini : flux SSE et consigne système');
        // Ollama (NDJSON, réseau local seulement)
        $ol = $P::save(['provider' => 'ollama', 'name' => 'Ollama test', 'model' => 'llama-test', 'base_url' => "http://127.0.0.1:$fport"]);
        $P::setRole($ol, 'default');
        $g = $P::generate('système', [['role' => 'user', 'content' => 'Bonjour']], 'test', null, 100);
        ok($g && str_contains($g['text'], 'procédure'), 'Ollama : flux NDJSON lu');
        ai_throws(fn() => $P::save(['provider' => 'ollama', 'name' => 'x', 'model' => 'y', 'base_url' => 'http://8.8.8.8:11434']), 'Ollama sur Internet refusé');
        // Secours : le modèle par défaut est en panne (clé refusée) → le modèle de secours répond
        $bad = $P::save(['provider' => 'openai', 'name' => 'OpenAI mauvaise clé', 'model' => 'gpt-test', 'base_url' => "http://127.0.0.1:$fport/v1", 'api_key' => 'sk-faux']);
        $P::setRole($bad, 'default');
        $P::setRole($cl, 'fallback');
        $g = $P::generate('système', [['role' => 'user', 'content' => 'Bonjour']], 'test', null, 100);
        eq('Claude test', $g['model'] ?? null, 'modèle de secours utilisé quand le principal échoue');
        ok((int) DB::value('SELECT COUNT(*) FROM ai_usage_logs WHERE model_setting_id = ? AND ok = 0', [$bad]) >= 1, 'échec du principal journalisé');
        ai_throws(fn() => $P::test($bad), 'test de connexion : erreur explicite');
        ok(str_contains($P::test($cl), 'procédure'), 'test de connexion réussi');
        // Limite par utilisateur et par jour
        DB::update('ai_model_settings', ['user_daily_requests' => 0], 'id IN (?, ?)', [$bad, $cl]);
        eq(null, $P::generate('système', [['role' => 'user', 'content' => 'Bonjour']], 'test', null, 100), 'limite atteinte : aucun appel');
        DB::update('ai_model_settings', ['user_daily_requests' => null], 'id IN (?, ?)', [$bad, $cl]);
        // Aide rédigée par le modèle, en continu, sans envoyer la base de données
        $P::setRole($cl, 'default');
        $cv = null;
        $r = ai_ask('admin', 'Comment faire un inventaire ?', $cv);
        $kinds = array_column($r['events'], 0);
        ok(count(array_keys($kinds, 'delta')) >= 3 && $r['provider'] === 'Claude test', 'aide : jetons du modèle relayés au fur et à mesure');
        $last = $reqs()[count($reqs()) - 1];
        $sent = json_encode($last['body'], JSON_UNESCAPED_UNICODE);
        ok(strlen($sent) < 20000 && !str_contains($sent, 'password') && !str_contains($sent, (string) DB::value('SELECT phone FROM customers WHERE phone IS NOT NULL AND phone <> "" LIMIT 1') ?: '§§'), 'seuls la question et des extraits de documentation sont envoyés (' . strlen($sent) . ' octets)');
        // Reformulation des réponses chiffrées : chiffres identiques acceptés, chiffre inventé rejeté
        setting_set('ai_llm_phrasing', '1');
        $stock = (float) DB::value('SELECT COALESCE(SUM(l.quantity * p.cost_price),0) FROM stock_levels l JOIN products p ON p.id = l.product_id WHERE p.track_stock = 1 AND l.quantity > 0');
        $r = ai_ask('admin', 'Quelle est la valeur de mon stock ?', $cv);
        ok(str_contains($r['all'], money(round($stock, 2))) && $r['provider'] === 'Claude test' && str_starts_with($r['text'], 'En résumé'), 'reformulation acceptée : mêmes chiffres (' . mb_substr($r['text'], 0, 90) . ')');
        $R = AI\Responder::class;
        ok(!$R::numberGuard('Le total est de 999 999 F.', 'Le stock vaut 2 308 571,22 F.', [2308571.22], 'valeur du stock'), 'garde-fou : chiffre inventé rejeté');
        ok($R::numberGuard('Votre stock représente 2,31 millions F, soit 2 308 571,22 F.', 'Le stock vaut 2 308 571,22 F.', [2308571.22]), 'garde-fou : même valeur arrondie acceptée');
        ok(!$R::numberGuard('Il reste 12 articles.', 'Il reste 11 articles.', [11]), 'garde-fou : nombre modifié rejeté');
    } finally {
        setting_set('ai_llm_phrasing', '0');
        DB::run('UPDATE ai_model_settings SET active = 0, is_default = 0, is_fallback = 0');
        proc_terminate($api);
    }
    $cv = null;
    $r = ai_ask('admin', 'Comment faire un inventaire ?', $cv);
    ok(!in_array('delta', array_column($r['events'], 0), true) && $r['provider'] === null, 'sans modèle actif : moteur intégré, aucun appel externe');
});

test('MARWA AI (HTTP) : page, flux SSE réel, conversations, avis, actions, administration, droits, XSS', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $pg = $a->req('GET', "$B/ia");
    ok($pg['code'] === 200 && str_contains($pg['body'], 'data-mai-app') && str_contains($pg['body'], 'marwa-ai.js'), 'page de conversation');
    $t = $a->token("$B/ia");
    $js = fn(Http $c, string $tok, string $url, array $body) => $c->req('POST', $B . $url, json_encode($body), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $tok]);
    $s = $a->req('POST', "$B/ia/v2/stream", json_encode(['q' => 'Fais-moi un bilan de la journée']), ['Content-Type: application/json', 'Accept: text/event-stream', 'X-CSRF-Token: ' . $t]);
    ok($s['code'] === 200 && str_contains($s['headers'], 'text/event-stream'), 'réponse en flux (text/event-stream)');
    preg_match_all('/^event: (\w+)$/m', $s['body'], $m);
    $evs = $m[1];
    ok($evs && $evs[0] === 'meta' && end($evs) === 'done' && in_array('text', $evs, true) && in_array('block', $evs, true), 'événements : ' . implode(',', array_unique($evs)));
    preg_match('/event: meta\ndata: (.+)\n/', $s['body'], $mm);
    $conv = (int) (json_decode($mm[1], true)['conversation_id'] ?? 0);
    preg_match('/event: done\ndata: (.+)\n/', $s['body'], $md);
    $mid = (int) (json_decode($md[1], true)['message_id'] ?? 0);
    ok($conv > 0 && $mid > 0, 'conversation et message identifiés');
    $s2 = $a->req('POST', "$B/ia/v2/stream", json_encode(['q' => 'et hier ?', 'conversation_id' => $conv]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $t]);
    ok(str_contains($s2['body'], 'hier'), 'suite dans la même conversation');
    eq(419, $a->req('POST', "$B/ia/v2/stream", json_encode(['q' => 'stock']), ['Content-Type: application/json'])['code'], 'sans jeton CSRF : refusé');
    $l = json_decode($a->req('GET', "$B/ia/conversations", null, ['Accept: application/json'])['body'], true);
    ok(in_array($conv, array_column($l['items'], 'id')), 'liste des conversations');
    $msgs = json_decode($a->req('GET', "$B/ia/conversations/$conv", null, ['Accept: application/json'])['body'], true);
    eq(4, count($msgs['items']), 'messages de la conversation');
    ok(json_decode($js($a, $t, '/ia/avis', ['message_id' => $mid, 'rating' => 1])['body'], true)['ok'], 'avis 👍');
    ok(json_decode($js($a, $t, "/ia/conversations/$conv/renommer", ['title' => 'Bilan <b>'])['body'], true)['ok'], 'renommer');
    // Isolation par HTTP
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    $tc = $c->token("$B/ia");
    ok(!json_decode($c->req('GET', "$B/ia/conversations/$conv", null, ['Accept: application/json'])['body'], true)['ok'], 'caissier : conversation de l\'admin inaccessible');
    $sc = $c->req('POST', "$B/ia/v2/stream", json_encode(['q' => 'et hier ?', 'conversation_id' => $conv]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $tc]);
    ok(str_contains($sc['body'], 'event: error') && !str_contains($sc['body'], 'event: done'), 'caissier : impossible de poursuivre la conversation de l\'admin');
    ok(!json_decode($js($c, $tc, "/ia/conversations/$conv/supprimer", [])['body'], true)['ok'], 'ni de la supprimer');
    // Action confirmée par HTTP, avec mot de passe pour une opération sensible
    $sa = $a->req('POST', "$B/ia/v2/stream", json_encode(['q' => 'Prépare un inventaire du magasin principal']), ['Content-Type: application/json', 'X-CSRF-Token: ' . $t]);
    preg_match('/event: done\ndata: (.+)\n/', $sa['body'], $md);
    $prop = json_decode($md[1], true)['proposal'];
    ok($prop && $prop['token'], 'proposition reçue dans le flux');
    $cf = json_decode($js($c, $tc, '/ia/actions/' . $prop['id'] . '/confirmer', ['token' => $prop['token']])['body'], true);
    ok(!$cf['ok'], 'le caissier ne peut pas confirmer l\'action de l\'admin');
    $cf = json_decode($js($a, $t, '/ia/actions/' . $prop['id'] . '/confirmer', ['token' => $prop['token']])['body'], true);
    ok($cf['ok'] && str_contains($cf['text'], 'nventaire'), 'confirmée : ' . ($cf['text'] ?? $cf['error'] ?? ''));
    // Bulle (format 1.x) : même moteur
    $bub = json_decode($js($a, $t, '/ia/ask', ['q' => 'Quelle est la valeur de mon stock ?'])['body'], true);
    ok(($bub['intent'] ?? '') === 'stock_value' && !empty($bub['kpis']), 'bulle : nouveau moteur, indicateurs');
    // Administration
    eq(403, $c->req('GET', "$B/ia/admin")['code'], 'pilotage réservé');
    eq(403, $c->req('GET', "$B/ia/admin/modeles")['code'], 'modèles réservés');
    foreach (['/ia/admin' => 'Banc de tests', '/ia/admin/modeles' => 'Ajouter un modèle', '/ia/admin/connaissances' => 'Nouvelle fiche', '/ia/admin/intentions' => 'Ajouter un exemple', '/ia/admin/dictionnaire' => 'sales', '/ia/reglages' => 'Durée de conservation'] as $u => $txt) {
        $pgA = $a->req('GET', $B . $u);
        ok($pgA['code'] === 200 && str_contains($pgA['body'], $txt), "écran $u");
    }
    $ta = $a->token("$B/ia/admin");
    $bn = $a->req('POST', "$B/ia/admin/banc", http_build_query(['_csrf' => $ta]));
    ok(str_contains($bn['body'], 'questions classées'), 'banc de tests lancé depuis l\'écran');
    // Clé d'API jamais réaffichée ; fiche de connaissance protégée contre le XSS
    $tm = $a->token("$B/ia/admin/modeles");
    $a->req('POST', "$B/ia/admin/modeles", http_build_query(['_csrf' => $tm, 'provider' => 'anthropic', 'name' => 'Claude siège', 'model' => 'claude-opus-5-5', 'api_key' => 'sk-ant-SECRET-987654321']));
    $mp = $a->req('GET', "$B/ia/admin/modeles");
    ok(str_contains($mp['body'], 'Claude siège') && !str_contains($mp['body'], 'SECRET-987654321') && str_contains($mp['body'], '…4321'), 'clé masquée (seuls les 4 derniers caractères)');
    $mid2 = (int) DB::value("SELECT id FROM ai_model_settings WHERE name = 'Claude siège'");
    ok(!str_contains($a->req('GET', "$B/ia/admin/modeles?id=$mid2")['body'], 'SECRET-987654321'), 'ni dans le formulaire de modification');
    $tk = $a->token("$B/ia/admin/connaissances");
    $a->req('POST', "$B/ia/admin/connaissances", http_build_query(['_csrf' => $tk, 'kind' => 'procedure', 'title' => 'Procédure zorglub', 'keywords' => 'zorglub', 'body' => "<script>alert(1)</script>\nÉtape 1 : ouvrir l'écran zorglub."]));
    $kb = DB::one("SELECT body FROM ai_knowledge_base WHERE title = 'Procédure zorglub'");
    ok($kb && !str_contains($kb['body'], '<script>') && str_contains($kb['body'], '&lt;script&gt;'), 'contenu échappé à l\'enregistrement');
    $sz = $a->req('POST', "$B/ia/v2/stream", json_encode(['q' => 'Comment faire la procédure zorglub ?']), ['Content-Type: application/json', 'X-CSRF-Token: ' . $t]);
    ok(str_contains($sz['body'], 'zorglub') && !str_contains($sz['body'], '<script>alert'), 'fiche utilisée par l\'assistant, sans script');
    $tt = $a->token("$B/ia/admin/intentions");
    $a->req('POST', "$B/ia/admin/intentions/exemple", http_build_query(['_csrf' => $tt, 'intent' => 'stock_value', 'text' => 'combien dort dans mes rayons']));
    eq(1, (int) DB::value("SELECT COUNT(*) FROM ai_intent_examples WHERE text = 'combien dort dans mes rayons'"), 'exemple ajouté depuis l\'écran');
    $tr = $a->token("$B/ia/reglages");
    $a->req('POST', "$B/ia/reglages", http_build_query(['_csrf' => $tr, 'bubble' => '1', 'retention' => '0']));
    $ret = fn() => (string) DB::value("SELECT svalue FROM settings WHERE skey = 'ai_retention_days'");
    eq('90', $ret(), 'durée de conservation invalide refusée');
    $a->req('POST', "$B/ia/reglages", http_build_query(['_csrf' => $tr, 'bubble' => '1', 'retention' => '120']));
    eq('120', $ret(), 'durée de conservation enregistrée');
    setting_set('ai_retention_days', '90');
    $td = $a->token("$B/ia");
    $a->req('POST', "$B/ia/conversations/supprimer-tout", http_build_query(['_csrf' => $td]));
    eq(0, (int) DB::value('SELECT COUNT(*) FROM ai_conversations WHERE user_id = (SELECT id FROM users WHERE username = \'admin\')'), 'toutes mes conversations effacées');
    ok((int) DB::value('SELECT COUNT(*) FROM ai_conversations WHERE user_id = (SELECT id FROM users WHERE username = \'caissier\')') > 0, 'celles des autres utilisateurs conservées');
});
