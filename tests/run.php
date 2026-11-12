<?php
/**
 * POS Marwa — suite de tests automatisés (sans dépendance externe).
 *
 * Usage :
 *   TEST_DB_USER=xxx TEST_DB_PASS=yyy php tests/run.php
 * Variables optionnelles : TEST_DB_HOST (127.0.0.1), TEST_DB_NAME (poswan_test), TEST_PORT (8099)
 *
 * ATTENTION : la base TEST_DB_NAME est entièrement recréée. N'utilisez jamais la base de production.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$dbName = getenv('TEST_DB_NAME') ?: 'poswan_test';
if (!preg_match('/test/i', $dbName)) {
    fwrite(STDERR, "Par sécurité, le nom de la base de test doit contenir « test ».\n");
    exit(1);
}
$env = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => bin2hex(random_bytes(32)),
    'DB_HOST' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'DB_DATABASE' => $dbName,
    'DB_USERNAME' => getenv('TEST_DB_USER') ?: 'root', 'DB_PASSWORD' => getenv('TEST_DB_PASS') ?: '',
];
// Paire de clés de licence propre aux tests (la vraie clé privée de l'auteur n'est jamais ici)
$licKp = sodium_crypto_sign_keypair();
$LIC_SK = base64_encode(sodium_crypto_sign_secretkey($licKp));
$env['LICENCE_TEST_PUBLIC_KEY'] = base64_encode(sodium_crypto_sign_publickey($licKp));
foreach ($env as $k => $v) {
    putenv("$k=$v");
}

// Recrée la base de test
$pdo = new PDO("mysql:host={$env['DB_HOST']};charset=utf8mb4", $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
$pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
unset($pdo);

require dirname(__DIR__) . '/app/bootstrap.php';
restore_error_handler();
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false; // erreur volontairement masquée par @
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services as S;

// ------------------------------------------------------------------ mini-framework
$results = ['pass' => 0, 'fail' => 0, 'failures' => []];
$current = '';
function test(string $name, callable $fn): void
{
    global $results, $current;
    $current = $name;
    try {
        $fn();
        $results['pass']++;
        echo "  \033[32m✔\033[0m $name\n";
    } catch (Throwable $e) {
        $results['fail']++;
        $results['failures'][] = "$name : " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo "  \033[31m✘ $name\033[0m\n      → " . $e->getMessage() . "\n";
    }
}
function ok(bool $cond, string $msg = 'assertion échouée'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if (is_float($expected) || is_float($actual)) {
        if (abs((float) $expected - (float) $actual) > 0.001) {
            throw new RuntimeException(($msg ? "$msg : " : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
        }
        return;
    }
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg : " : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}
function throws(callable $fn, string $contains = '', string $class = BusinessException::class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new RuntimeException('Exception inattendue ' . get_class($e) . ' : ' . $e->getMessage());
        }
        if ($contains !== '' && mb_stripos($e->getMessage(), $contains) === false) {
            throw new RuntimeException("Message inattendu : « {$e->getMessage()} » (attendu « $contains »)");
        }
        return;
    }
    throw new RuntimeException('Une exception était attendue.');
}
function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function actingAs(string $username): array
{
    $u = DB::one('SELECT * FROM users WHERE username = ?', [$username]);
    Auth::login($u);
    Auth::reset();
    return Auth::user();
}
function stock(int $pid, int $wh = 1): float
{
    return (float) DB::value('SELECT COALESCE(quantity,0) FROM stock_levels WHERE product_id = ? AND warehouse_id = ?', [$pid, $wh]);
}

// Session CLI (sans cookie) pour simuler un utilisateur connecté
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '0');
session_save_path(sys_get_temp_dir());
session_start();

$ADMIN_PWD = 'Essai-Niamey-2026';
echo "\n== Installation ==\n";
test('Installation complète (schéma, rôles, paramètres, démo)', function () use ($ADMIN_PWD) {
    @unlink(S\InstallService::lockFile() . '.bak');
    if (S\InstallService::isInstalled()) {
        rename(S\InstallService::lockFile(), S\InstallService::lockFile() . '.bak');
    }
    S\InstallService::install(['company' => 'Test SARL', 'business_type' => 'alimentation', 'admin_username' => 'admin', 'admin_name' => 'Admin Test', 'admin_password' => $ADMIN_PWD, 'store_name' => '', 'demo' => true]);
    ok(S\InstallService::isInstalled());
    ok((int) DB::value('SELECT COUNT(*) FROM permissions') >= 40, 'permissions');
    eq(11, (int) DB::value('SELECT COUNT(*) FROM roles'), 'rôles');
    ok((bool) DB::value("SELECT 1 FROM permissions WHERE code = 'quotes.manage'"), 'permission devis');
    ok((int) DB::value('SELECT COUNT(*) FROM products') >= 10, 'articles démo');
    eq('1', setting('feature_lots'), 'lots activés pour alimentation');
});
test('Mot de passe administrateur haché (jamais en clair)', function () use ($ADMIN_PWD) {
    $h = DB::value("SELECT password_hash FROM users WHERE username = 'admin'");
    ok($h !== $ADMIN_PWD && password_verify($ADMIN_PWD, $h) && str_starts_with($h, '$2y$') || str_starts_with($h, '$argon'));
});
test('Politique de mot de passe', function () {
    ok(Auth::passwordPolicyError('court1', 'x') !== null, 'trop court');
    ok(Auth::passwordPolicyError('seulementdeslettres', 'x') !== null, 'sans chiffre');
    ok(Auth::passwordPolicyError('admin2026xyz', 'admin') !== null, 'contient identifiant');
    ok(Auth::passwordPolicyError('Bonne-Phrase-2026', 'kader') === null, 'valide');
});
test('Migrations idempotentes', function () {
    eq([], S\InstallService::migrate());
});

// Comptes de test pour chaque rôle
$roles = array_column(DB::all('SELECT id, code FROM roles'), 'id', 'code');
foreach (['caissier' => 'cashier', 'gerant' => 'store_manager', 'magasin' => 'stock_manager', 'auditeur' => 'auditor', 'pharma' => 'pharmacist', 'serveur' => 'waiter', 'cuisto' => 'cook'] as $u => $r) {
    DB::insert('users', ['username' => $u, 'full_name' => ucfirst($u), 'password_hash' => Auth::hash('Mot-de-passe-2026'), 'pin_hash' => Auth::hash('4826'), 'role_id' => $roles[$r], 'store_id' => 1, 'register_id' => 1]);
}

echo "\n== Authentification & permissions ==\n";
test('Permissions par rôle (serveur)', function () {
    actingAs('caissier');
    ok(Auth::can('pos.sell'), 'caissier vend');
    ok(!Auth::can('pos.cancel_sale'), 'caissier n\'annule pas');
    ok(!Auth::can('products.price_edit'), 'caissier ne modifie pas les prix');
    ok(!Auth::can('users.manage'), 'caissier ne gère pas les utilisateurs');
    actingAs('auditeur');
    ok(Auth::can('reports.financial') && !Auth::can('pos.sell') && !Auth::can('products.manage'), 'auditeur lecture seule');
    actingAs('admin');
    ok(Auth::can('backup.manage') && Auth::can('anything.future'), 'admin a tout');
});
test('Autorisation superviseur : identifiants valides et permission requise', function () {
    eq((int) DB::value("SELECT id FROM users WHERE username = 'gerant'"), Auth::verifySupervisor('gerant', '4826', 'pos.discount_high'), 'PIN gérant');
    throws(fn() => Auth::verifySupervisor('gerant', 'mauvais', 'pos.discount_high'), 'incorrects');
    throws(fn() => Auth::verifySupervisor('caissier', 'Mot-de-passe-2026', 'pos.discount_high'), 'permission');
});

// ------------------------------------------------------------------ caisse
echo "\n== Caisse : sessions ==\n";
$riz = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Sac de riz%'");      // lots, 13000 F, coût 10000
$savon = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Savon%'");         // sans lot, 250 F, stock 6
$eau = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Eau min%'");          // lots + pack de 6
$niebe = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Ni%b%'");           // au kg
$session = 0;

test('Vente refusée caisse fermée', function () use ($savon) {
    actingAs('caissier');
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 250]]]), 'fermée');
});
test('Ouverture de caisse et unicité de la session ouverte', function () use (&$session) {
    actingAs('caissier');
    $session = S\CashService::open(1, 10000);
    ok($session > 0);
    throws(fn() => S\CashService::open(1, 5000), 'déjà ouverte');
});

echo "\n== Ventes ==\n";
$saleId = 0;
test('Vente simple : totaux, monnaie, stock, coût, caisse', function () use ($savon, $riz, &$saleId, &$session) {
    actingAs('caissier');
    $s0 = stock($savon);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 2], ['product_id' => $riz, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 15000]]]);
    $saleId = $r['sale_id'];
    eq(13500.0, $r['total'], 'total');
    eq(1500.0, $r['change'], 'monnaie');
    eq($s0 - 2, stock($savon), 'stock savon');
    $sale = DB::one('SELECT * FROM sales WHERE id = ?', [$saleId]);
    eq(13500.0, (float) $sale['paid_total']);
    eq(10350.0, (float) $sale['cost_total'], 'coût des ventes (2×175 + 10000)');
    eq(23500.0, S\CashService::expectedCash($session), 'espèces théoriques = fond + vente nette');
});
test('Idempotence : double soumission = une seule vente', function () use ($savon) {
    actingAs('caissier');
    $u = uuid();
    $data = ['client_uuid' => $u, 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 250]]];
    $a = S\SaleService::create($data);
    $b = S\SaleService::create($data);
    eq($a['sale_id'], $b['sale_id']);
    ok($b['duplicate'] === true);
    eq(1, (int) DB::value('SELECT COUNT(*) FROM sales WHERE client_uuid = ?', [$u]));
});
test('Paiement mixte espèces + mobile money', function () use ($riz) {
    actingAs('caissier');
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $riz, 'quantity' => 1]], 'payments' => [['method' => 'mobile_money', 'amount' => 8000, 'reference' => 'MM123'], ['method' => 'cash', 'amount' => 10000]]]);
    eq(5000.0, $r['change']);
    $p = array_column(DB::all('SELECT method, amount FROM sale_payments WHERE sale_id = ?', [$r['sale_id']]), 'amount', 'method');
    eq(8000.0, (float) $p['mobile_money']);
    eq(5000.0, (float) $p['cash'], 'espèces nettes de la monnaie');
});
test('Paiement électronique supérieur au total refusé', function () use ($savon) {
    actingAs('caissier');
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'card', 'amount' => 1000]]]), 'dépasser');
});
test('Stock insuffisant : vente refusée et rien n\'est enregistré (transaction)', function () use ($savon) {
    actingAs('caissier');
    $before = (int) DB::value('SELECT COUNT(*) FROM sales');
    $s = stock($savon);
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 999]], 'payments' => [['method' => 'cash', 'amount' => 999999]]]), 'insuffisant');
    eq($before, (int) DB::value('SELECT COUNT(*) FROM sales'));
    eq($s, stock($savon));
});
test('Quantité décimale refusée pour un article à l\'unité, acceptée au kg', function () use ($savon, $niebe) {
    actingAs('caissier');
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1.5]], 'payments' => [['method' => 'cash', 'amount' => 1000]]]), 'entière');
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $niebe, 'quantity' => 2.5]], 'payments' => [['method' => 'cash', 'amount' => 2250]]]);
    eq(2250.0, $r['total']);
});
test('Le prix envoyé par le navigateur est ignoré sans permission (dérogation)', function () use ($riz) {
    actingAs('caissier');
    try {
        S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $riz, 'quantity' => 1, 'unit_price' => 100]], 'payments' => [['method' => 'cash', 'amount' => 100]]]);
        throw new RuntimeException('dérogation acceptée sans autorisation');
    } catch (BusinessException $e) {
        ok(str_starts_with($e->getMessage(), 'AUTH_REQUIRED'), 'autorisation exigée');
    }
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $riz, 'quantity' => 1, 'unit_price' => 12500]], 'payments' => [['method' => 'cash', 'amount' => 12500]], 'supervisor' => ['username' => 'gerant', 'secret' => '4826']]);
    eq(12500.0, $r['total']);
    ok((int) DB::value('SELECT authorized_by FROM sales WHERE id = ?', [$r['sale_id']]) > 0, 'superviseur tracé');
});
test('Remise : seuil, autorisation superviseur, répartition', function () use ($riz) {
    actingAs('caissier');
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $riz, 'quantity' => 1]], 'discount_type' => 'percent', 'discount_value' => 5, 'payments' => [['method' => 'cash', 'amount' => 12350]]]);
    eq(12350.0, $r['total'], 'remise 5 % autorisée');
    try {
        S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $riz, 'quantity' => 1]], 'discount_type' => 'percent', 'discount_value' => 20, 'payments' => [['method' => 'cash', 'amount' => 10400]]]);
        throw new RuntimeException('remise 20 % acceptée');
    } catch (BusinessException $e) {
        ok(str_contains($e->getMessage(), 'AUTH_REQUIRED'));
    }
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $riz, 'quantity' => 1]], 'discount_type' => 'percent', 'discount_value' => 20, 'payments' => [['method' => 'cash', 'amount' => 10400]], 'supervisor' => ['username' => 'gerant', 'secret' => 'Mot-de-passe-2026']]);
    eq(10400.0, $r['total']);
    eq(2600.0, (float) DB::value('SELECT SUM(discount) FROM sale_items WHERE sale_id = ?', [$r['sale_id']]), 'remise portée sur les lignes');
});
test('Conditionnement : pack de 6 décompte 6 unités', function () use ($eau) {
    actingAs('caissier');
    $pack = (int) DB::value('SELECT id FROM product_packagings WHERE product_id = ?', [$eau]);
    $s = stock($eau);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $eau, 'packaging_id' => $pack, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 4400]]]);
    eq(4400.0, $r['total']);
    eq($s - 12, stock($eau));
});
test('FEFO : le lot qui périme en premier sort en premier ; lot périmé et lot rappelé exclus', function () {
    actingAs('admin');
    $pid = S\ProductService::create(S\ProductService::validate(['name' => 'Yaourt test', 'sale_price' => 500, 'cost_price' => 300, 'track_lots' => 1, 'track_stock' => 1]));
    DB::transaction(function () use ($pid) {
        S\StockService::receive($pid, 1, 5, 300, 'reception', 'test', null, 't', 'LOT-OLD', date('Y-m-d', strtotime('-2 days')));   // périmé
        S\StockService::receive($pid, 1, 5, 300, 'reception', 'test', null, 't', 'LOT-RECALL', date('Y-m-d', strtotime('+5 days')));
        S\StockService::receive($pid, 1, 5, 300, 'reception', 'test', null, 't', 'LOT-B', date('Y-m-d', strtotime('+20 days')));
        S\StockService::receive($pid, 1, 5, 300, 'reception', 'test', null, 't', 'LOT-A', date('Y-m-d', strtotime('+10 days')));
    });
    DB::run("UPDATE stock_lots SET recalled = 1 WHERE lot_number = 'LOT-RECALL'");
    actingAs('caissier');
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 7]], 'payments' => [['method' => 'cash', 'amount' => 3500]]]);
    $alloc = DB::all('SELECT l.lot_number, sil.quantity FROM sale_item_lots sil JOIN stock_lots l ON l.id = sil.lot_id JOIN sale_items si ON si.id = sil.sale_item_id WHERE si.sale_id = ? ORDER BY sil.id', [$r['sale_id']]);
    eq('LOT-A', $alloc[0]['lot_number']);
    eq(5.0, (float) $alloc[0]['quantity']);
    eq('LOT-B', $alloc[1]['lot_number']);
    eq(2.0, (float) $alloc[1]['quantity']);
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 4]], 'payments' => [['method' => 'cash', 'amount' => 2000]]]), 'vendable insuffisant');
});

echo "\n== Crédit client ==\n";
test('Vente à crédit : client obligatoire, plafond, grand livre', function () use ($riz) {
    actingAs('caissier');
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $riz, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 3000]]]), 'sélectionnez un client');
    $cid = DB::insert('customers', ['name' => 'Client crédit', 'credit_limit' => 20000]);
    try {
        S\SaleService::create(['client_uuid' => uuid(), 'customer_id' => $cid, 'items' => [['product_id' => $riz, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 3000]]]);
        throw new RuntimeException('crédit accordé par un caissier sans droit');
    } catch (BusinessException $e) {
        ok(str_contains($e->getMessage(), 'AUTH_REQUIRED'));
    }
});
test('Vente à crédit autorisée et dépassement de plafond bloqué', function () use ($riz) {
    actingAs('gerant');
    $cid = (int) DB::value("SELECT id FROM customers WHERE name = 'Client crédit'");
    $r = S\SaleService::create(['client_uuid' => uuid(), 'customer_id' => $cid, 'items' => [['product_id' => $riz, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 3000]]]);
    eq(10000.0, (float) DB::value('SELECT credit_amount FROM sales WHERE id = ?', [$r['sale_id']]));
    eq(10000.0, (float) DB::value('SELECT balance FROM customers WHERE id = ?', [$cid]));
    try {
        S\SaleService::create(['client_uuid' => uuid(), 'customer_id' => $cid, 'items' => [['product_id' => $riz, 'quantity' => 1]], 'payments' => []]);
        throw new RuntimeException('plafond dépassé accepté');
    } catch (BusinessException $e) {
        ok(str_contains($e->getMessage(), 'Plafond'), $e->getMessage());
    }
    // Règlement partiel du client
    S\CustomerService::payment($cid, 4000, 'cash', 'Acompte test');
    eq(6000.0, (float) DB::value('SELECT balance FROM customers WHERE id = ?', [$cid]));
    eq(3, (int) DB::value('SELECT COUNT(*) FROM customer_transactions WHERE customer_id = ?', [$cid]) + 1, 'grand livre');
});

echo "\n== Retours & annulations ==\n";
test('Retour partiel : remboursement espèces + réintégration stock', function () use (&$saleId, $savon, &$session) {
    actingAs('gerant');
    $item = DB::one('SELECT * FROM sale_items WHERE sale_id = ? AND product_id = ?', [$saleId, $savon]);
    $s = stock($savon);
    $cash = S\CashService::expectedCash($session);
    $retId = S\SaleService::createReturn($saleId, [$item['id'] => 1], 'cash', 'Défectueux', true);
    eq(250.0, (float) DB::value('SELECT total FROM sale_returns WHERE id = ?', [$retId]));
    eq($s + 1, stock($savon));
    eq($cash - 250, S\CashService::expectedCash($session));
    eq('partially_returned', DB::value('SELECT status FROM sales WHERE id = ?', [$saleId]));
    throws(fn() => S\SaleService::createReturn($saleId, [$item['id'] => 5], 'cash', 'trop', true), 'supérieure');
});
test('Annulation : contre-passation stock et caisse, vente conservée', function () use ($savon, &$session) {
    actingAs('caissier');
    $s = stock($savon);
    $cash = S\CashService::expectedCash($session);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 250]]]);
    actingAs('gerant');
    S\SaleService::cancel($r['sale_id'], 'Erreur de saisie');
    eq('cancelled', DB::value('SELECT status FROM sales WHERE id = ?', [$r['sale_id']]));
    eq($s, stock($savon));
    eq($cash, S\CashService::expectedCash($session));
    throws(fn() => S\SaleService::cancel($r['sale_id'], 'x'), 'Seule');
    throws(fn() => S\SaleService::cancel($r['sale_id'], ''), 'motif');
});

echo "\n== Achats & fournisseurs ==\n";
test('Commande : n\'affecte pas le stock ; réception partielle puis complète ; CMP ; dette', function () use ($savon) {
    actingAs('admin');
    $sup = DB::insert('suppliers', ['name' => 'Fournisseur test']);
    $s0 = stock($savon);
    $cost0 = (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$savon]);
    $po = S\PurchaseService::createOrder($sup, 1, [['product_id' => $savon, 'quantity' => 100, 'unit_cost' => 200]], null, null);
    eq($s0, stock($savon), 'commande sans effet stock');
    $poi = (int) DB::value('SELECT id FROM purchase_order_items WHERE purchase_order_id = ?', [$po]);
    S\PurchaseService::receive($sup, 1, [['product_id' => $savon, 'quantity' => 40, 'unit_cost' => 200, 'po_item_id' => $poi]], 400, $po, 'F-1', null, null);
    eq($s0 + 40, stock($savon));
    eq('partial', DB::value('SELECT status FROM purchase_orders WHERE id = ?', [$po]));
    $expected = round(($s0 * $cost0 + 40 * 210) / ($s0 + 40), 2); // 400 F de frais / 40 = +10 F
    eq($expected, (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$savon]), 'CMP avec frais d\'approche');
    eq(8400.0, (float) DB::value('SELECT balance FROM suppliers WHERE id = ?', [$sup]), 'dette');
    S\PurchaseService::receive($sup, 1, [['product_id' => $savon, 'quantity' => 60, 'unit_cost' => 200, 'po_item_id' => $poi]], 0, $po, 'F-2', null, null);
    eq('received', DB::value('SELECT status FROM purchase_orders WHERE id = ?', [$po]));
    throws(fn() => S\PurchaseService::receive($sup, 1, [['product_id' => $savon, 'quantity' => 1, 'unit_cost' => 200, 'po_item_id' => $poi]], 0, $po, null, null, null), 'ne peut plus');
    S\SupplierService::pay($sup, 5000, 'transfer', 'Virement', false);
    eq(15400.0, (float) DB::value('SELECT balance FROM suppliers WHERE id = ?', [$sup]));
});
test('Réception d\'un article suivi par lot : lot et péremption obligatoires', function () use ($riz) {
    actingAs('admin');
    $sup = (int) DB::value("SELECT id FROM suppliers WHERE name = 'Fournisseur test'");
    throws(fn() => S\PurchaseService::receive($sup, 1, [['product_id' => $riz, 'quantity' => 5, 'unit_cost' => 10000]], 0, null, null, null, null), 'lot obligatoire');
    S\PurchaseService::receive($sup, 1, [['product_id' => $riz, 'quantity' => 5, 'unit_cost' => 10000, 'lot_number' => 'R-2027', 'expiry_date' => '2027-06-30']], 0, null, null, null, null);
    ok((bool) DB::value("SELECT id FROM stock_lots WHERE lot_number = 'R-2027' AND quantity = 5"));
});
test('Retour fournisseur : stock et dette diminués', function () use ($savon) {
    actingAs('admin');
    $sup = (int) DB::value("SELECT id FROM suppliers WHERE name = 'Fournisseur test'");
    $s = stock($savon);
    $d = (float) DB::value('SELECT balance FROM suppliers WHERE id = ?', [$sup]);
    S\PurchaseService::supplierReturn($sup, 1, [['product_id' => $savon, 'quantity' => 10, 'unit_cost' => 200]], 'Lot abîmé');
    eq($s - 10, stock($savon));
    eq($d - 2000, (float) DB::value('SELECT balance FROM suppliers WHERE id = ?', [$sup]));
});

echo "\n== Stocks ==\n";
test('Ajustement : motif obligatoire, mouvement tracé', function () use ($savon) {
    actingAs('magasin');
    throws(fn() => S\StockService::adjust($savon, 1, -1, 'loss', ''), 'motif');
    $s = stock($savon);
    S\StockService::adjust($savon, 1, -2, 'loss', 'Casse');
    eq($s - 2, stock($savon));
    $m = DB::one('SELECT * FROM stock_movements WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$savon]);
    eq('loss', $m['type']);
    eq((int) DB::value("SELECT id FROM users WHERE username = 'magasin'"), (int) $m['user_id'], 'utilisateur tracé');
    eq($s - 2, (float) $m['balance_after']);
});
test('Cohérence : niveau de stock = somme des mouvements (tous articles)', function () {
    $bad = DB::all('SELECT sl.product_id, sl.warehouse_id, sl.quantity, COALESCE(SUM(m.quantity),0) AS mv FROM stock_levels sl LEFT JOIN stock_movements m ON m.product_id = sl.product_id AND m.warehouse_id = sl.warehouse_id GROUP BY sl.product_id, sl.warehouse_id, sl.quantity HAVING ABS(sl.quantity - mv) > 0.001');
    eq([], $bad);
});
test('Cohérence : niveau de stock = somme des lots (articles suivis par lot)', function () {
    $bad = DB::all('SELECT sl.product_id FROM stock_levels sl JOIN products p ON p.id = sl.product_id AND p.track_lots = 1 LEFT JOIN stock_lots l ON l.product_id = sl.product_id AND l.warehouse_id = sl.warehouse_id GROUP BY sl.product_id, sl.warehouse_id, sl.quantity HAVING ABS(sl.quantity - COALESCE(SUM(l.quantity),0)) > 0.001');
    eq([], $bad);
});
test('Transfert entre entrepôts (lots conservés)', function () use ($riz) {
    actingAs('admin');
    $wh2 = DB::insert('warehouses', ['name' => 'Dépôt', 'code' => 'E02', 'store_id' => 1]);
    $s = stock($riz);
    S\StockService::transfer(1, $wh2, [['product_id' => $riz, 'quantity' => 3]], 'test');
    eq($s - 3, stock($riz));
    eq(3.0, stock($riz, $wh2));
    eq(3.0, (float) DB::value('SELECT SUM(quantity) FROM stock_lots WHERE product_id = ? AND warehouse_id = ?', [$riz, $wh2]));
    throws(fn() => S\StockService::transfer(1, 1, [['product_id' => $riz, 'quantity' => 1]], null), 'différents');
});
test('Inventaire : écart appliqué au stock actuel, articles non comptés ignorés', function () use ($savon, $niebe) {
    actingAs('gerant');
    $inv = S\InventoryService::start(1, null, null);
    $itemSavon = (int) DB::value('SELECT id FROM inventory_items WHERE inventory_id = ? AND product_id = ?', [$inv, $savon]);
    $expected = (float) DB::value('SELECT expected_qty FROM inventory_items WHERE id = ?', [$itemSavon]);
    $niebeBefore = stock($niebe);
    S\InventoryService::saveCounts($inv, [$itemSavon => (string) ($expected - 3)]);
    // une vente pendant l'inventaire
    S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 250]]]);
    $r = S\InventoryService::validate($inv);
    eq(1, $r['adjusted']);
    eq($expected - 3 - 1, stock($savon));
    eq($niebeBefore, stock($niebe), 'non compté inchangé');
    throws(fn() => S\InventoryService::validate($inv), 'déjà');
});

echo "\n== Clôture de caisse ==\n";
test('Clôture : écart calculé, commentaire obligatoire si écart', function () use (&$session) {
    actingAs('caissier');
    $exp = S\CashService::expectedCash($session);
    throws(fn() => S\CashService::close($session, $exp - 500, ''), 'commentaire');
    $r = S\CashService::close($session, $exp - 500, 'Billet manquant');
    eq(-500.0, $r['difference']);
    throws(fn() => S\CashService::close($session, 0, 'x'), 'déjà clôturée');
});

echo "\n== Articles ==\n";
test('Unicité du code-barres et de la référence', function () use ($savon) {
    actingAs('admin');
    $bc = DB::value('SELECT barcode FROM products WHERE id = ?', [$savon]);
    throws(fn() => S\ProductService::validate(['name' => 'Doublon', 'sale_price' => 10, 'barcode' => $bc]), 'déjà utilisé');
    throws(fn() => S\ProductService::validate(['name' => 'Doublon', 'sale_price' => 10, 'reference' => 'ART00001']), 'déjà utilisée');
    throws(fn() => S\ProductService::validate(['name' => '', 'sale_price' => 10]), 'obligatoire');
    throws(fn() => S\ProductService::validate(['name' => 'X', 'sale_price' => -5]), 'minimum');
});
test('Historique des prix enregistré ; prix protégés sans permission', function () use ($savon) {
    actingAs('admin');
    $p = DB::one('SELECT * FROM products WHERE id = ?', [$savon]);
    $d = S\ProductService::validate(['sale_price' => 300] + $p, $savon);
    S\ProductService::update($savon, $d, true, false);
    eq(300.0, (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]));
    ok((bool) DB::value("SELECT id FROM price_history WHERE product_id = ? AND field = 'sale_price' AND new_value = 300", [$savon]));
    $d = S\ProductService::validate(['sale_price' => 1] + $p, $savon);
    S\ProductService::update($savon, $d, false, false);
    eq(300.0, (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]), 'prix inchangé sans droit');
});
test('Le suivi par lot ne peut pas changer si du stock existe', function () use ($savon) {
    actingAs('admin');
    $p = DB::one('SELECT * FROM products WHERE id = ?', [$savon]);
    throws(fn() => S\ProductService::update($savon, S\ProductService::validate(['track_lots' => 1] + $p, $savon), true, true), 'lot');
});
test('Modification groupée des prix avec arrondi', function () {
    actingAs('admin');
    $cat = (int) DB::value("SELECT id FROM categories WHERE name = 'Boissons'");
    $before = (float) DB::value("SELECT sale_price FROM products WHERE name LIKE 'Jus de bissap%'");
    S\ProductService::bulkPrice($cat, 'percent', 10, 25);
    eq(ceil($before * 1.1 / 25) * 25, (float) DB::value("SELECT sale_price FROM products WHERE name LIKE 'Jus de bissap%'"));
});

echo "\n== Pharmacie ==\n";
test('Médicament sur ordonnance : ordonnance et pharmacien exigés', function () {
    actingAs('admin');
    setting_set('feature_pharmacy', '1');
    $pid = S\ProductService::create(S\ProductService::validate(['name' => 'Amoxicilline test', 'sale_price' => 1500, 'cost_price' => 1000, 'requires_prescription' => 1, 'is_medicine' => 1]), 10, 1);
    S\CashService::open(1, 0);
    actingAs('caissier');
    try {
        S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1500]]]);
        throw new RuntimeException('délivrance sans pharmacien');
    } catch (BusinessException $e) {
        ok(str_contains($e->getMessage(), 'AUTH_REQUIRED'), $e->getMessage());
    }
    actingAs('pharma');
    try {
        S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1500]]]);
        throw new RuntimeException('délivrance sans ordonnance');
    } catch (BusinessException $e) {
        ok(str_contains($e->getMessage(), 'PRESCRIPTION_REQUIRED'), $e->getMessage());
    }
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1500]], 'prescription' => ['prescriber' => 'Dr Issa', 'patient_name' => 'Aïcha', 'prescription_ref' => 'ORD-1']]);
    ok((bool) DB::value('SELECT id FROM prescriptions WHERE sale_id = ?', [$r['sale_id']]), 'ordonnancier');
    setting_set('feature_pharmacy', '0');
});

echo "\n== Divers ==\n";
test('Montant en lettres', function () {
    eq('treize mille cinq cents', amount_words(13500));
    eq('quatre-vingt-onze', amount_words(91));
    eq('un million deux cent cinquante mille trois cents', amount_words(1250300));
});
test('Numérotation continue sans doublon', function () {
    $a = S\Sequence::next('expense');
    $b = S\Sequence::next('expense');
    ok($a !== $b && (int) substr($b, -6) === (int) substr($a, -6) + 1);
});
test('Dépense puis contrepassation (aucune suppression)', function () {
    actingAs('gerant');
    $cat = (int) DB::value("SELECT id FROM expense_categories WHERE type = 'expense' LIMIT 1");
    $id = S\ExpenseService::create('expense', $cat, 5000, 'transfer', date('Y-m-d'), 'Loyer test', false);
    S\ExpenseService::reverse($id, 'Erreur');
    eq('reversed', DB::value('SELECT status FROM expenses WHERE id = ?', [$id]));
    eq(0.0, (float) DB::value("SELECT SUM(amount) FROM cash_movements WHERE ref_type = 'expense' AND ref_id = ?", [$id]), 'écritures équilibrées');
});
test('Rapports : CA net = ventes − retours ; marge = CA net − CMV', function () {
    $s = S\ReportService::summary(date('Y-m-d'), date('Y-m-d'));
    ok($s['tickets'] > 5);
    eq(round($s['gross'] - $s['returns'], 2), round($s['net'], 2));
    eq(round($s['net'] - $s['cogs'], 2), round($s['margin'], 2));
    $gross = (float) DB::value("SELECT SUM(total) FROM sales WHERE status <> 'cancelled' AND DATE(created_at) = CURDATE()");
    eq($gross, $s['gross']);
});

echo "\n== Restaurant ==\n";
$R = [];
test('Préparation : salle, tables, ingrédient non vendable, fiche technique', function () use (&$R) {
    actingAs('admin');
    setting_set('feature_restaurant', '1');
    setting_set('feature_recipes', '1');
    $area = DB::insert('restaurant_areas', ['name' => 'Salle test']);
    $R['t1'] = DB::insert('restaurant_tables', ['area_id' => $area, 'name' => 'T1']);
    $R['t2'] = DB::insert('restaurant_tables', ['area_id' => $area, 'name' => 'T2']);
    $R['t3'] = DB::insert('restaurant_tables', ['area_id' => $area, 'name' => 'T3']);
    $cat = DB::insert('categories', ['name' => 'Plats test', 'kitchen_station' => 'cuisine']);
    $drinks = DB::insert('categories', ['name' => 'Boissons test', 'kitchen_station' => 'aucun']); // servies directement
    $R['poulet'] = S\ProductService::create(S\ProductService::validate(['name' => 'Poulet cru', 'sale_price' => 0, 'cost_price' => 3000, 'sellable' => 0]), 10, 1);
    $R['huile'] = S\ProductService::create(S\ProductService::validate(['name' => 'Huile test', 'sale_price' => 0, 'cost_price' => 1000, 'sellable' => 0]), 10, 1);
    $R['plat'] = S\ProductService::create(S\ProductService::validate(['name' => 'Poulet braisé test', 'sale_price' => 4500, 'category_id' => $cat, 'track_stock' => 0]));
    $R['soda'] = S\ProductService::create(S\ProductService::validate(['name' => 'Soda test', 'sale_price' => 600, 'cost_price' => 350, 'category_id' => $drinks]), 20, 1);
    S\ProductService::saveRecipe($R['plat'], [$R['poulet'] => 0.5, $R['huile'] => 0.1]);
    eq(1600.0, S\ProductService::recipeCost($R['plat']), 'coût matière (0,5×3000 + 0,1×1000)');
    throws(fn() => S\ProductService::saveRecipe($R['plat'], [$R['plat'] => 1]), 'propre ingrédient');
    actingAs('caissier');
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $R['poulet'], 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10]]]), 'ingrédient');
});
test('Commande : une seule commande ouverte par table, regroupement des lignes', function () use (&$R) {
    actingAs('serveur');
    $R['o1'] = S\RestaurantService::open($R['t1'], 'dine_in', 3);
    throws(fn() => S\RestaurantService::open($R['t1'], 'dine_in', 2), 'occupée');
    S\RestaurantService::addItem($R['o1'], $R['plat'], 2, null, 2);
    S\RestaurantService::addItem($R['o1'], $R['plat'], 1, null, 2);
    S\RestaurantService::addItem($R['o1'], $R['plat'], 1, 'Sans piment', 2);
    S\RestaurantService::addItem($R['o1'], $R['soda'], 3, null, 0);
    eq(3, (int) DB::value('SELECT COUNT(*) FROM restaurant_order_items WHERE order_id = ?', [$R['o1']]), 'lignes (note distincte = ligne distincte)');
    $o = S\RestaurantService::order($R['o1']);
    eq(4 * 4500.0 + 3 * 600.0, $o['total']);
});
test('Poste par défaut : catégorie sans poste → cuisine ; « aucun » → servi sans ticket', function () {
    eq('cuisine', S\RestaurantService::stationFor(null));
    eq(null, S\RestaurantService::stationFor('aucun'));
    eq('bar', S\RestaurantService::stationFor('bar'));
    setting_set('restaurant_default_station', 'aucun');
    eq(null, S\RestaurantService::stationFor(null));
    setting_set('restaurant_default_station', 'cuisine');
    ok(!in_array('aucun', S\RestaurantService::stations(), true), 'pas d\'onglet « aucun » en cuisine');
});
test('Envoi en cuisine : ticket par poste, articles « aucun » servis directement', function () use (&$R) {
    actingAs('serveur');
    $tickets = S\RestaurantService::send($R['o1']);
    eq(1, count($tickets), 'un ticket cuisine');
    $R['ticket'] = $tickets[0];
    eq(2, (int) DB::value("SELECT COUNT(*) FROM restaurant_order_items WHERE order_id = ? AND status = 'sent'", [$R['o1']]));
    eq('served', DB::value('SELECT status FROM restaurant_order_items WHERE order_id = ? AND product_id = ?', [$R['o1'], $R['soda']]));
    throws(fn() => S\RestaurantService::send($R['o1']), 'Aucune nouvelle');
    eq(1, count(S\RestaurantService::kitchenFeed('cuisine')));
    $line = (int) DB::value("SELECT id FROM restaurant_order_items WHERE order_id = ? AND status = 'sent' LIMIT 1", [$R['o1']]);
    throws(fn() => S\RestaurantService::updateItem($line, 9, null, null), 'déjà envoyée');
});
test('Écran cuisine : préparation → prêt → servi', function () use (&$R) {
    actingAs('cuisto');
    S\RestaurantService::ticketStatus($R['ticket'], 'preparing');
    S\RestaurantService::ticketStatus($R['ticket'], 'ready');
    eq(2, (int) DB::value("SELECT COUNT(*) FROM restaurant_order_items WHERE order_id = ? AND status = 'ready'", [$R['o1']]));
    actingAs('serveur');
    eq(2, S\RestaurantService::serveReady($R['o1']));
    eq('served', DB::value('SELECT status FROM kitchen_tickets WHERE id = ?', [$R['ticket']]));
    ok(DB::value('SELECT ready_at FROM kitchen_tickets WHERE id = ?', [$R['ticket']]) !== null, 'temps de préparation mesuré');
});
test('Annulation d\'un plat envoyé : autorisation, ticket d\'annulation, perte des ingrédients', function () use (&$R) {
    actingAs('serveur');
    $line = (int) DB::value("SELECT id FROM restaurant_order_items WHERE order_id = ? AND product_id = ? AND note IS NULL", [$R['o1'], $R['plat']]);
    try {
        S\RestaurantService::cancelItem($line, 1, 'Client a changé d\'avis', true);
        throw new RuntimeException('annulation acceptée sans droit');
    } catch (BusinessException $e) {
        ok(str_contains($e->getMessage(), 'AUTH_REQUIRED'));
    }
    $sup = Auth::verifySupervisor('gerant', '4826', 'restaurant.cancel_item');
    $poulet = stock($R['poulet']);
    $t = S\RestaurantService::cancelItem($line, 1, 'Client a changé d\'avis', true, $sup);
    ok($t > 0, 'ticket d\'annulation');
    eq('cancel', DB::value('SELECT type FROM kitchen_tickets WHERE id = ?', [$t]));
    eq(2.0, (float) DB::value('SELECT quantity FROM restaurant_order_items WHERE id = ?', [$line]), 'ligne réduite');
    eq($poulet - 0.5, stock($R['poulet']), 'perte déstockée');
    eq('kitchen_waste', DB::value('SELECT type FROM stock_movements WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$R['poulet']]));
});
test('Addition séparée puis paiement complet : ingrédients déstockés, table libérée', function () use (&$R) {
    actingAs('gerant');
    S\RestaurantService::bill($R['o1']);
    eq('billed', DB::value('SELECT status FROM restaurant_orders WHERE id = ?', [$R['o1']]));
    $items = array_column(DB::all("SELECT id, product_id, quantity FROM restaurant_order_items WHERE order_id = ? AND status <> 'cancelled'", [$R['o1']]), null, 'id');
    $platLine = (int) array_values(array_filter($items, fn($i) => (int) $i['product_id'] === $R['plat']))[0]['id'];
    $poulet = stock($R['poulet']);
    // 1re part : 1 plat
    $r1 = S\RestaurantService::pay($R['o1'], [$platLine => 1], ['client_uuid' => uuid(), 'payments' => [['method' => 'cash', 'amount' => 5000]]]);
    eq(4500.0, $r1['total']);
    eq(500.0, $r1['change']);
    ok(!$r1['order_closed'], 'commande toujours ouverte');
    eq($poulet - 0.5, stock($R['poulet']), 'ingrédients de la portion vendue');
    eq(1600.0, (float) DB::value('SELECT cost_total FROM sales WHERE id = ?', [$r1['sale_id']]), 'coût matière réel');
    throws(fn() => S\RestaurantService::pay($R['o1'], [$platLine => 5], ['client_uuid' => uuid(), 'payments' => [['method' => 'cash', 'amount' => 99999]]]), 'supérieure');
    // reste
    $rest = [];
    foreach (DB::all("SELECT id, quantity - paid_qty AS q FROM restaurant_order_items WHERE order_id = ? AND status <> 'cancelled'", [$R['o1']]) as $l) {
        $rest[$l['id']] = (float) $l['q'];
    }
    $due = S\RestaurantService::order($R['o1'])['due'];
    $r2 = S\RestaurantService::pay($R['o1'], $rest, ['client_uuid' => uuid(), 'payments' => [['method' => 'mobile_money', 'amount' => $due, 'reference' => 'MM9']]]);
    ok($r2['order_closed'], 'commande soldée');
    eq('paid', DB::value('SELECT status FROM restaurant_orders WHERE id = ?', [$R['o1']]));
    eq(2, (int) DB::value('SELECT COUNT(*) FROM sales WHERE restaurant_order_id = ?', [$R['o1']]));
    $R['sale2'] = $r2['sale_id'];
    actingAs('serveur');
    $R['o1b'] = S\RestaurantService::open($R['t1'], 'dine_in', 2); // table de nouveau libre
    ok($R['o1b'] > 0);
});
test('Annulation d\'une vente restaurant : ingrédients réintégrés', function () use (&$R) {
    actingAs('gerant');
    $poulet = stock($R['poulet']);
    $used = (float) DB::value('SELECT COALESCE(SUM(c.quantity),0) FROM sale_item_components c JOIN sale_items si ON si.id = c.sale_item_id WHERE si.sale_id = ? AND c.ingredient_id = ?', [$R['sale2'], $R['poulet']]);
    ok($used > 0);
    S\SaleService::cancel($R['sale2'], 'Erreur de caisse');
    eq($poulet + $used, stock($R['poulet']));
});
test('Transfert et fusion de tables', function () use (&$R) {
    actingAs('serveur');
    $o2 = S\RestaurantService::open($R['t2'], 'dine_in', 2);
    S\RestaurantService::addItem($o2, $R['soda'], 2);
    S\RestaurantService::transfer($o2, $R['t3']);
    eq($R['t3'], (int) DB::value('SELECT table_id FROM restaurant_orders WHERE id = ?', [$o2]));
    throws(fn() => S\RestaurantService::transfer($o2, $R['t1']), 'occupée');
    S\RestaurantService::addItem($R['o1b'], $R['soda'], 1);
    S\RestaurantService::merge($o2, $R['o1b']);
    eq('cancelled', DB::value('SELECT status FROM restaurant_orders WHERE id = ?', [$o2]));
    eq(3.0, (float) DB::value('SELECT SUM(quantity) FROM restaurant_order_items WHERE order_id = ?', [$R['o1b']]));
    eq(4, (int) DB::value('SELECT covers FROM restaurant_orders WHERE id = ?', [$R['o1b']]));
});
test('Commande à emporter / livraison', function () use (&$R) {
    actingAs('serveur');
    throws(fn() => S\RestaurantService::open(null, 'delivery', 1, ['name' => 'Awa']), 'téléphone');
    $o = S\RestaurantService::open(null, 'delivery', 1, ['name' => 'Awa', 'phone' => '+227 96 11 22 33', 'address' => 'Plateau']);
    ok(count(S\RestaurantService::openWithoutTable()) >= 1);
    S\RestaurantService::cancelOrder($o, 'Client injoignable');
    eq('cancelled', DB::value('SELECT status FROM restaurant_orders WHERE id = ?', [$o]));
});

test('Canal de vente : article restaurant refusé en caisse, article boutique refusé au restaurant', function () use (&$R) {
    actingAs('admin');
    $plat = (int) $R['plat'];
    DB::update('products', ['sales_channel' => 'restaurant'], 'id = ?', [$plat]);
    actingAs('caissier');
    throws(fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $plat, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 99999]]]), 'réservé au restaurant');
    DB::update('products', ['sales_channel' => 'all'], 'id = ?', [$plat]);
    $savon = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Savon%'");
    $cat = (int) DB::value('SELECT category_id FROM products WHERE id = ?', [$savon]);
    DB::update('categories', ['sales_channel' => 'shop'], 'id = ?', [$cat]);
    eq('shop', S\ProductService::channelOf($savon), 'hérité de la catégorie');
    actingAs('serveur');
    $o = S\RestaurantService::open(null, 'takeaway', 1, ['name' => 'Canal']);
    throws(fn() => S\RestaurantService::addItem($o, $savon, 1), 'caisse boutique');
    DB::update('categories', ['sales_channel' => 'all'], 'id = ?', [$cat]);
    S\RestaurantService::cancelOrder($o, 'test');
});

echo "\n== Version 1.2 : devise, impression, devis, achats, canaux ==\n";
// Petit « serveur d'impression » local : reçoit les octets ESC/POS envoyés au port 9100 simulé
function printListener(): array
{
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($srv, false);
    return [$srv, (int) substr($name, strrpos($name, ':') + 1)];
}
function printReceived($srv): string
{
    $data = '';
    while ($c = @stream_socket_accept($srv, 2)) {
        stream_set_timeout($c, 2);
        while (!feof($c) && ($chunk = fread($c, 65536)) !== false && $chunk !== '') {
            $data .= $chunk;
        }
        fclose($c);
    }
    return $data;
}
test('Devise configurable : symbole, position, décimales', function () {
    eq('13 500 F', str_replace("\u{202F}", ' ', money(13500)), 'FCFA par défaut');
    foreach (['currency_code' => 'EUR', 'currency_symbol' => '€', 'currency_position' => 'before', 'currency_decimals' => '2'] as $k => $v) {
        setting_set($k, $v);
    }
    ok(preg_match('/^€\s?1[\s\x{202F}\x{00A0}]?234,50$/u', money(1234.5)) === 1, 'euro : ' . money(1234.5));
    foreach (['currency_code' => 'XOF', 'currency_symbol' => 'F', 'currency_position' => 'after', 'currency_decimals' => '0'] as $k => $v) {
        setting_set($k, $v);
    }
    eq('XOF', currency()['code']);
});
test('Traduction : dictionnaires valides, pages traduites, scripts intacts', function () {
    foreach (array_keys(App\Core\Lang::LANGUAGES) as $code) {
        if ($code === 'fr' || !is_file(BASE_PATH . "/lang/$code.php")) {
            continue;
        }
        $d = require BASE_PATH . "/lang/$code.php";
        ok(is_array($d) && count($d) > 500, "dictionnaire $code");
        foreach ($d as $k => $v) {
            preg_match_all('/\{[a-z_]+\}/', $k, $a);
            preg_match_all('/\{[a-z_]+\}/', $v, $b);
            ok($a[0] == $b[0] || (sort($a[0]) || true) && (sort($b[0]) || true) && $a[0] == $b[0], "paramètres conservés ($code) : $k");
        }
    }
    App\Core\Lang::set('en');
    eq('Quote', t('Devis'));
    $h = App\Core\Lang::html('<h1>Devis</h1><label>Client :</label><input placeholder="N° ou client"><script>var x = "Devis";</script><td>Mon article perso 42</td>');
    ok(str_contains($h, '<h1>Quote</h1>') && str_contains($h, 'Customer :') || str_contains($h, 'Client :') === false, $h);
    ok(str_contains($h, 'var x = "Devis"'), 'script non modifié');
    ok(str_contains($h, 'Mon article perso 42'), 'données saisies non traduites');
    App\Core\Lang::set('ar');
    eq('rtl', App\Core\Lang::dir());
    App\Core\Lang::reset();
    eq('fr', App\Core\Lang::locale());
});
test('Adresses d\'imprimante : réseau local uniquement, partage Windows strict', function () {
    eq('192.168.1.50:9100', S\PrintService::validateAddress('network', '192.168.1.50'));
    eq('10.0.0.8:9101', S\PrintService::validateAddress('network', '10.0.0.8:9101'));
    throws(fn() => S\PrintService::validateAddress('network', '8.8.8.8'), 'réseau local');
    throws(fn() => S\PrintService::validateAddress('network', 'imprimante.example.com'), 'réseau local');
    eq('\\\\localhost\\XP-80', S\PrintService::validateAddress('windows', '\\\\localhost\\XP-80'));
    throws(fn() => S\PrintService::validateAddress('windows', 'C:\\Windows\\win.ini'), 'invalide');
    throws(fn() => S\PrintService::validateAddress('windows', '\\\\localhost\\..\\..\\x'), 'invalide');
});
$printerId = 0;
test('Ticket ESC/POS envoyé à une imprimante réseau (écoute TCP locale)', function () use ($savon, &$printerId) {
    actingAs('caissier');
    [$srv, $port] = printListener();
    $printerId = DB::insert('printers', ['name' => 'Ticket alimentation', 'driver' => 'network', 'address' => "127.0.0.1:$port", 'paper' => '80', 'usages' => 'receipt', 'copies' => 1, 'cut' => 1, 'open_drawer' => 1, 'active' => 1]);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 500]]]);
    $p = S\PrintService::printReceipt($r['sale_id']);
    eq(1, $p['printed'], implode(' ', $p['errors']));
    $bytes = printReceived($srv);
    fclose($srv);
    ok(str_starts_with($bytes, "\x1B\x40"), 'initialisation ESC @');
    ok(str_contains($bytes, $r['number']), 'numéro de ticket');
    ok(str_contains($bytes, "\x1D\x28\x6B"), 'QR code natif');
    ok(str_contains($bytes, "\x1B\x70\x00"), 'ouverture du tiroir');
    ok(str_contains($bytes, "\x1D\x56"), 'coupe papier');
    eq(1, (int) DB::value('SELECT print_count FROM sales WHERE id = ?', [$r['sale_id']]));
});
test('Imprimante injoignable : erreur signalée, vente conservée', function () use ($savon, &$printerId) {
    actingAs('caissier');
    [$srv, $port] = printListener();
    fclose($srv); // port fermé
    DB::update('printers', ['address' => "127.0.0.1:$port"], 'id = ?', [$printerId]);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 5000]]]);
    $p = S\PrintService::printReceipt($r['sale_id']);
    eq(0, $p['printed']);
    ok(count($p['errors']) === 1 && str_contains($p['errors'][0], 'injoignable'), implode(' ', $p['errors']));
    eq('completed', DB::value('SELECT status FROM sales WHERE id = ?', [$r['sale_id']]));
    DB::update('printers', ['active' => 0], 'id = ?', [$printerId]);
});
test('QR code signé : vérification, falsification détectée', function () {
    $s = DB::one('SELECT * FROM sales ORDER BY id DESC LIMIT 1');
    $code = S\DocumentRef::saleQr($s);
    $v = S\DocumentRef::verify($code);
    ok($v['valid'] && $v['doc']['number'] === $s['number'], $v['message']);
    $forged = preg_replace('/\|(\d+(\.\d+)?)XOF\|/', '|1XOF|', $code);
    ok($forged !== $code && !S\DocumentRef::verify($forged)['valid'], 'montant modifié');
    ok(!S\DocumentRef::verify('nimporte quoi')['valid']);
});
test('Mobile money : opérateur enregistré, opérateur inconnu ignoré', function () use ($savon) {
    actingAs('caissier');
    setting_set('mobile_money_providers', 'Airtel Money,Moov Money');
    $price = (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'mobile_money', 'provider' => 'moov money', 'amount' => $price, 'reference' => 'TX9']]]);
    eq('Moov Money', DB::value('SELECT provider FROM sale_payments WHERE sale_id = ?', [$r['sale_id']]));
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'mobile_money', 'provider' => '<script>', 'amount' => $price]]]);
    eq(null, DB::value('SELECT provider FROM sale_payments WHERE sale_id = ?', [$r['sale_id']]));
});
test('Code-barres interne EAN-13 : clé de contrôle valide et unicité', function () {
    $a = S\Barcode::newInternalEan();
    ok(preg_match('/^21\d{11}$/', $a) === 1);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $a[$i] * ($i % 2 ? 3 : 1);
    }
    eq((10 - $sum % 10) % 10, (int) $a[12], 'clé EAN');
    ok($a !== S\Barcode::newInternalEan());
});
$quoteId = 0;
test('Devis : prix protégés, statuts, aucun effet sur le stock', function () use ($savon, $riz, &$quoteId) {
    actingAs('caissier');
    $s0 = stock($savon);
    throws(fn() => S\QuoteService::create(['customer_name' => 'Client devis'], [['product_id' => $savon, 'quantity' => 2, 'unit_price' => 100]]), 'autorisé');
    throws(fn() => S\QuoteService::create(['customer_name' => ''], [['product_id' => $savon, 'quantity' => 2]]), 'client');
    $quoteId = S\QuoteService::create(['customer_name' => 'Client devis', 'customer_phone' => '90000000'], [['product_id' => $savon, 'quantity' => 2, 'discount' => 50], ['product_id' => $riz, 'quantity' => 1]]);
    $q = DB::one('SELECT * FROM quotes WHERE id = ?', [$quoteId]);
    $expected = 2 * (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]) - 50 + (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$riz]);
    eq($expected, (float) $q['total'], 'total 2 × prix − remise + riz');
    ok(str_starts_with($q['number'], 'DV'), 'numérotation DV');
    eq($s0, stock($savon), 'stock inchangé');
    throws(fn() => S\QuoteService::setStatus($quoteId, 'converted'), 'non autorisé');
    S\QuoteService::setStatus($quoteId, 'accepted');
    eq('accepted', DB::value('SELECT status FROM quotes WHERE id = ?', [$quoteId]));
});
test('Devis converti en vente : stock, caisse, idempotence, verrouillage', function () use ($savon, &$quoteId) {
    actingAs('caissier');
    $s0 = stock($savon);
    $u = uuid();
    $total = (float) DB::value('SELECT total FROM quotes WHERE id = ?', [$quoteId]);
    $r = S\QuoteService::convert($quoteId, [['method' => 'cash', 'amount' => 15000]], $u);
    eq(15000 - $total, $r['change'], 'monnaie');
    eq($s0 - 2, stock($savon), 'stock déduit à la conversion');
    $q = DB::one('SELECT * FROM quotes WHERE id = ?', [$quoteId]);
    eq('converted', $q['status']);
    eq($r['sale_id'], (int) $q['sale_id']);
    eq($total, (float) DB::value('SELECT total FROM sales WHERE id = ?', [$r['sale_id']]), 'prix du devis conservés');
    $again = S\QuoteService::convert($quoteId, [['method' => 'cash', 'amount' => 15000]], uuid());
    ok($again['duplicate'] && $again['sale_id'] === $r['sale_id'], 'pas de double vente');
    throws(fn() => S\QuoteService::update($quoteId, ['customer_name' => 'X'], [['product_id' => $savon, 'quantity' => 1]]), 'ne peut plus');
});
test('Réception : coût nouveau prix / ancien prix / moyen et nouveau prix de vente', function () use ($savon) {
    actingAs('admin');
    $sup = (int) DB::value('SELECT id FROM suppliers ORDER BY id LIMIT 1');
    $c0 = (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$savon]);
    S\PurchaseService::receive($sup, 1, [['product_id' => $savon, 'quantity' => 10, 'unit_cost' => 300, 'cost_mode' => 'old']], 0, null, null, null, null);
    eq($c0, (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$savon]), 'ancien prix conservé');
    S\PurchaseService::receive($sup, 1, [['product_id' => $savon, 'quantity' => 10, 'unit_cost' => 320, 'cost_mode' => 'new', 'new_sale_price' => '400']], 0, null, null, null, null);
    eq(320.0, (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$savon]), 'nouveau prix sur tout le stock');
    eq(400.0, (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]), 'nouveau prix de vente');
    ok((bool) DB::value("SELECT 1 FROM price_history WHERE product_id = ? AND field = 'sale_price' AND source LIKE 'reception:%'", [$savon]), 'historique');
    $st = stock($savon);
    S\PurchaseService::receive($sup, 1, [['product_id' => $savon, 'quantity' => 10, 'unit_cost' => 200]], 0, null, null, null, null);
    eq(round(($st * 320 + 10 * 200) / ($st + 10), 2), (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$savon]), 'prix moyen pondéré par défaut');
    eq('average', DB::value('SELECT cost_mode FROM reception_items ORDER BY id DESC LIMIT 1'));
    actingAs('magasin'); // pas de droit de modifier les prix de vente
    throws(fn() => S\PurchaseService::receive($sup, 1, [['product_id' => $savon, 'quantity' => 1, 'unit_cost' => 200, 'new_sale_price' => '999']], 0, null, null, null, null), 'autorisé');
    eq(400.0, (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]), 'transaction annulée');
});

echo "\n== Version 1.3 : TVA, PDF, cuisine ==\n";
test('TVA générale : taux appliqué, article exonéré, sans TVA', function () use ($savon) {
    actingAs('caissier');
    $price = (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]);
    setting_set('vat_mode', 'general');
    setting_set('vat_rate', '19');
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2 * $price]]]);
    eq(round(2 * $price * 19 / 119, 2), (float) DB::value('SELECT tax_total FROM sales WHERE id = ?', [$r['sale_id']]), 'TVA extraite du TTC');
    eq(19.0, (float) DB::value('SELECT tax_rate FROM sale_items WHERE sale_id = ?', [$r['sale_id']]), 'taux mémorisé sur la ligne');
    eq(2 * $price, $r['total'], 'prix TTC inchangés');
    DB::update('products', ['vat_exempt' => 1], 'id = ?', [$savon]);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => $price]]]);
    eq(0.0, (float) DB::value('SELECT tax_total FROM sales WHERE id = ?', [$r['sale_id']]), 'article exonéré');
    DB::update('products', ['vat_exempt' => 0], 'id = ?', [$savon]);
    setting_set('vat_mode', 'none');
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => $price]]]);
    eq(0.0, (float) DB::value('SELECT tax_total FROM sales WHERE id = ?', [$r['sale_id']]), 'non assujetti');
    setting_set('vat_mode', 'general');
});
test('Rapport TVA : par taux, net des retours', function () use ($savon) {
    actingAs('gerant');
    $before = S\ReportService::vat(date('Y-m-d'), date('Y-m-d'));
    $price = (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$savon]);
    $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $savon, 'quantity' => 3]], 'payments' => [['method' => 'cash', 'amount' => 3 * $price]]]);
    $item = DB::one('SELECT * FROM sale_items WHERE sale_id = ?', [$r['sale_id']]);
    S\SaleService::createReturn($r['sale_id'], [$item['id'] => 1], 'cash', 'Test TVA', true);
    $after = S\ReportService::vat(date('Y-m-d'), date('Y-m-d'));
    $ret = (float) DB::value('SELECT SUM(amount) FROM sale_return_items WHERE sale_item_id = ?', [$item['id']]);
    $expected = (float) $item['tax_amount'] - round($ret * 19 / 119, 2);
    eq(round($before['total']['net_tax'] + $expected, 2), $after['total']['net_tax'], 'TVA nette');
    $rate19 = array_values(array_filter($after['rates'], fn($x) => $x['rate'] == 19))[0];
    eq(round($rate19['net_ttc'] - $rate19['net_tax'], 2), $rate19['net_ht'], 'base HT = TTC − TVA');
    eq(date('Y-m'), end($after['months'])['m']);
    setting_set('vat_mode', 'product');
});
test('PDF : document valide, pagination, accents', function () {
    $rows = [];
    for ($i = 1; $i <= 120; $i++) {
        $rows[] = ['Article é ' . $i, '9000000' . $i, $i, $i * 1250.5];
    }
    $pdf = S\Pdf::table('Ventes par article — été', ['Article', 'Téléphone', 'Quantité', 'Montant'], $rows, ['subtitle' => 'Du 01/10 au 10/10', 'footer' => ['TOTAL', '', 7260, 9075630.0]]);
    ok(str_starts_with($pdf, '%PDF-1.4') && str_ends_with(rtrim($pdf), '%%EOF'), 'en-tête / fin');
    ok(substr_count($pdf, '/Type /Page ') >= 3, 'plusieurs pages');
    ok(str_contains($pdf, 'Page 1 / ' . substr_count($pdf, '/Type /Page ')), 'numérotation');
    ok(str_contains($pdf, "\xE9t\xE9"), 'accents encodés (WinAnsi)');
    preg_match('/startxref\n(\d+)/', $pdf, $m);
    ok(substr($pdf, (int) $m[1], 4) === 'xref', 'table de références exacte');
});
test('Restaurant : articles non envoyés partent en cuisine à l\'encaissement', function () use (&$R) {
    actingAs('gerant');
    $o = S\RestaurantService::open(null, 'takeaway', 1, ['name' => 'Emporter direct']);
    S\RestaurantService::addItem($o, (int) $R['plat'], 1);
    $line = (int) DB::value('SELECT id FROM restaurant_order_items WHERE order_id = ?', [$o]);
    $r = S\RestaurantService::pay($o, [$line => 1], ['client_uuid' => uuid(), 'payments' => [['method' => 'cash', 'amount' => 10000]]]);
    ok($r['order_closed'] && count($r['sent_tickets']) === 1, 'ticket cuisine créé au paiement');
    eq('sent', DB::value('SELECT status FROM restaurant_order_items WHERE id = ?', [$line]));
    ok(count(array_filter(S\RestaurantService::kitchenFeed('cuisine'), fn($t) => (int) $t['order_id'] === $o)) === 1, 'visible sur l\'écran cuisine');
});

echo "\n== Version 1.4 : modules complémentaires, menu, assistance ==\n";
test('Modules : découverte, manifestes invalides refusés', function () {
    $bad = App\Core\Addons::dir() . '/zz_test_invalide';
    $old = App\Core\Addons::dir() . '/zz_test_ancien';
    @mkdir($bad);
    @mkdir($old);
    file_put_contents($bad . '/manifest.json', '{"code": "autre", "name": "", "version": "x"');
    file_put_contents($old . '/manifest.json', json_encode(['code' => 'zz_test_ancien', 'name' => 'Ancien', 'version' => '1.0.0', 'requires' => '99.0.0']));
    @mkdir(App\Core\Addons::dir() . '/Mauvais-Nom');
    try {
        $all = App\Core\Addons::discover(true);
        ok(isset($all['livraisons']) && $all['livraisons']['errors'] === [], 'module exemple valide');
        ok(!empty($all['zz_test_invalide']['errors']), 'JSON invalide signalé');
        ok(str_contains(implode(' ', $all['zz_test_ancien']['errors']), 'Nécessite POS Marwa 99.0.0'), 'version requise contrôlée');
        ok(!isset($all['Mauvais-Nom']), 'nom de dossier invalide ignoré');
        eq('error', App\Core\Addons::status('zz_test_ancien'));
        throws(fn() => App\Core\Addons::install('zz_test_ancien', false), 'invalide');
        throws(fn() => App\Core\Addons::install('../app', false), 'Code de module invalide');
    } finally {
        @unlink($bad . '/manifest.json');
        @rmdir($bad);
        @unlink($old . '/manifest.json');
        @rmdir($old);
        @rmdir(App\Core\Addons::dir() . '/Mauvais-Nom');
        App\Core\Addons::reset();
    }
});
test('Module exemple « Livraisons » : installation, permissions, données, réinstallation sans effet', function () {
    actingAs('admin');
    eq('available', App\Core\Addons::status('livraisons'));
    $applied = App\Core\Addons::install('livraisons', false);
    eq(['0001_livraisons'], $applied, 'migration du module');
    ok((bool) DB::value("SHOW TABLES LIKE 'livraisons'"), 'table créée');
    eq('active', App\Core\Addons::status('livraisons'));
    eq('1000', setting('livraisons_frais_defaut'), 'paramètre par défaut');
    ok(is_file(PUBLIC_PATH . '/assets/addons/livraisons/livraisons.css'), 'fichiers publics copiés');
    actingAs('caissier');
    ok(Auth::can('livraisons.manage'), 'droit accordé au rôle prévu');
    actingAs('auditeur');
    ok(!Auth::can('livraisons.view'), 'pas de droit pour les autres rôles');
    actingAs('caissier');
    $id = Addons\Livraisons\LivraisonService::create(['customer_name' => 'Awa', 'phone' => '+227 90 11 22 33', 'address' => 'Plateau', 'fee' => '1 000']);
    throws(fn() => Addons\Livraisons\LivraisonService::create(['customer_name' => 'X', 'phone' => '', 'address' => '']), 'obligatoires');
    Addons\Livraisons\LivraisonService::setStatus($id, 'livree');
    throws(fn() => Addons\Livraisons\LivraisonService::setStatus($id, 'en_route'), 'non autorisé');
    eq(1000.0, (float) DB::value('SELECT fee FROM livraisons WHERE id = ?', [$id]));
    eq([], App\Core\Addons::install('livraisons', false), 'réinstallation idempotente');
    $menu = App\Core\Addons::menu();
    ok(isset($menu['Gestion des ventes']) && $menu['Gestion des ventes'][0][2] === '/livraisons', 'entrée de menu');
});
$PR = [];
test('Module Pressing : dépôt express, acompte en caisse, étiquettes, prix protégés', function () use (&$PR) {
    actingAs('admin');
    eq(['0001_pressing', '0002_suivi_retraits', '0003_articles_au_poids', '0004_express_livraison', '0005_lavage_seul_m2_clients'], App\Core\Addons::install('pressing', false), 'installation');
    $PR['chemise'] = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Chemise'");
    $PR['boubou'] = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Boubou simple'");
    $PR['couverture'] = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Couverture'");
    actingAs('caissier');
    $session = (int) S\CashService::currentSession(Auth::user())['id'];
    $cash0 = S\CashService::expectedCash($session);
    $lines = [['article_id' => $PR['chemise'], 'service' => 'lavage', 'quantity' => 2, 'color' => 'blanc'], ['article_id' => $PR['boubou'], 'service' => 'sec', 'quantity' => 1, 'defects' => 'tache au col']];
    throws(fn() => Addons\Pressing\PressingService::create(['customer_name' => 'Moussa', 'phone' => '9012', 'lines' => $lines]), 'téléphone');
    throws(fn() => Addons\Pressing\PressingService::create(['customer_name' => 'Moussa', 'phone' => '90121212', 'lines' => [['article_id' => $PR['chemise'], 'service' => 'lavage', 'quantity' => 1, 'unit_price' => 100]]]), 'réservée au responsable');
    throws(fn() => Addons\Pressing\PressingService::create(['customer_name' => 'Moussa', 'phone' => '90121212', 'lines' => [['article_id' => $PR['couverture'], 'service' => 'repassage', 'quantity' => 1]]]), 'non proposé');
    $r = Addons\Pressing\PressingService::create(['customer_name' => 'Moussa', 'phone' => '90 12 12 12', 'express' => true, 'lines' => $lines, 'deposit' => ['method' => 'cash', 'amount' => '2000']]);
    $o = DB::one('SELECT * FROM pressing_orders WHERE id = ?', [$r['id']]);
    eq(3000.0, (float) $o['subtotal'], '2 × 500 + 2000');
    eq(1500.0, (float) $o['express_fee'], 'express 50 %');
    eq(4500.0, (float) $o['total']);
    eq(2000.0, (float) $o['paid'], 'acompte');
    eq(3, (int) $o['pieces']);
    eq(date('Y-m-d', strtotime('+1 day')), $o['due_date'], 'délai express');
    ok(str_starts_with($o['number'], 'PR'), 'numérotation PR');
    eq(['1-1', '1-2'], array_column(DB::all('SELECT tag FROM pressing_items WHERE order_id = ? ORDER BY line_no', [$r['id']]), 'tag'), 'étiquettes');
    eq($cash0 + 2000, S\CashService::expectedCash($session), 'acompte entré dans la caisse');
    $PR['o1'] = $r['id'];
});
test('Module Pressing : traitement, retrait avec solde, monnaie, annulation remboursée', function () use (&$PR) {
    actingAs('caissier');
    $id = $PR['o1'];
    throws(fn() => Addons\Pressing\PressingService::setStatus($id, 'delivered'), 'non autorisé');
    Addons\Pressing\PressingService::setStatus($id, 'processing');
    throws(fn() => Addons\Pressing\PressingService::deliver($id), 'pas encore marqué');
    Addons\Pressing\PressingService::setStatus($id, 'ready');
    throws(fn() => Addons\Pressing\PressingService::deliver($id), 'Reste à payer');
    $change = Addons\Pressing\PressingService::deliver($id, ['method' => 'cash', 'amount' => '5000']);
    eq(2500.0, $change, 'monnaie : 5000 − 2500');
    $o = DB::one('SELECT * FROM pressing_orders WHERE id = ?', [$id]);
    eq('delivered', $o['status']);
    eq(4500.0, (float) $o['paid']);
    throws(fn() => Addons\Pressing\PressingService::cancel($id, 'erreur'), 'ne peut plus');
    $r2 = Addons\Pressing\PressingService::create(['customer_name' => 'Aïssa', 'phone' => '96000001', 'lines' => [['article_id' => $PR['chemise'], 'service' => 'repassage', 'quantity' => 4]], 'deposit' => ['method' => 'cash', 'amount' => '500']]);
    $session = (int) S\CashService::currentSession(Auth::user())['id'];
    $cash = S\CashService::expectedCash($session);
    actingAs('gerant');
    Addons\Pressing\PressingService::cancel($r2['id'], 'Client a renoncé');
    eq('cancelled', DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$r2['id']]));
    eq(0.0, (float) DB::value('SELECT paid FROM pressing_orders WHERE id = ?', [$r2['id']]), 'acompte remboursé');
    eq($cash - 500, S\CashService::expectedCash($session), 'remboursement sorti de la caisse');
    eq(['received', 'processing', 'ready', 'delivered'], array_column(DB::all('SELECT to_status FROM pressing_status_log WHERE order_id = ? ORDER BY id', [$id]), 'to_status'), 'historique des statuts');
});
$CL = [];
test('Module Clinique : installation, rôles créés, dossier patient, file d\'attente, constantes', function () use (&$CL) {
    actingAs('admin');
    eq(['0001_clinique', '0002_imagerie'], App\Core\Addons::install('clinique', false), 'installation');
    foreach (['medecin' => 'clinic_doctor', 'infirmier' => 'clinic_nurse', 'accueil' => 'clinic_reception'] as $u => $r) {
        $rid = (int) DB::value('SELECT id FROM roles WHERE code = ?', [$r]);
        ok($rid > 0, "rôle $r créé");
        DB::insert('users', ['username' => $u, 'full_name' => ucfirst($u), 'password_hash' => Auth::hash('Mot-de-passe-2026'), 'pin_hash' => Auth::hash('4826'), 'role_id' => $rid, 'store_id' => 1, 'register_id' => 1]);
    }
    actingAs('pharma');
    ok(Auth::can('clinique.pharmacy') && !Auth::can('clinique.medical'), 'pharmacien : file des ordonnances, pas le dossier médical');
    actingAs('infirmier');
    ok(Auth::can('clinique.nurse') && !Auth::can('clinique.doctor') && !Auth::can('clinique.reception'), 'droits infirmier');
    actingAs('accueil');
    ok(!Auth::can('clinique.medical'), 'accueil sans accès médical');
    throws(fn() => Addons\Clinique\ClinicService::savePatient(['last_name' => 'Issa', 'first_name' => '', 'sex' => 'M']), 'obligatoires');
    throws(fn() => Addons\Clinique\ClinicService::savePatient(['last_name' => 'Issa', 'first_name' => 'Ali', 'sex' => 'M', 'coverage_pct' => '120']), 'entre 0 et 100');
    $CL['p'] = Addons\Clinique\ClinicService::savePatient(['last_name' => 'Issa', 'first_name' => 'Aïcha', 'sex' => 'F', 'birth_date' => '1990-05-04', 'phone' => '90 00 00 01', 'allergies' => 'Pénicilline', 'insurer' => 'Mutuelle Santé', 'coverage_pct' => '80']);
    $p = DB::one('SELECT * FROM clinic_patients WHERE id = ?', [$CL['p']]);
    eq('ISSA', $p['last_name'], 'nom en majuscules');
    ok(str_starts_with($p['number'], 'PAT'), 'numéro de dossier');
    $CL['v'] = Addons\Clinique\ClinicService::openVisit(['patient_id' => $CL['p'], 'reason' => 'Fièvre', 'priority' => 'urgent', 'vitals' => ['temperature' => '39,2', 'pulse' => '110']]);
    throws(fn() => Addons\Clinique\ClinicService::openVisit(['patient_id' => $CL['p']]), 'déjà dans la file');
    $vt = DB::one('SELECT * FROM clinic_vitals WHERE visit_id = ?', [$CL['v']]);
    eq(39.2, (float) $vt['temperature'], 'virgule décimale acceptée');
    eq(['t' => 'Fièvre 39.2 °C'], Addons\Clinique\ClinicService::alerts($vt), 'alerte fièvre (seuil 38,5)');
    throws(fn() => Addons\Clinique\ClinicService::addVitals(['patient_id' => $CL['p'], 'temperature' => '93']), 'Valeur invalide');
    throws(fn() => Addons\Clinique\ClinicService::addVitals(['patient_id' => $CL['p']]), 'au moins une constante');
    // Rendez-vous : chevauchement refusé pour un même médecin, arrivée = file d'attente
    $doc = (int) DB::value("SELECT id FROM users WHERE username = 'medecin'");
    $p2 = Addons\Clinique\ClinicService::savePatient(['last_name' => 'Moussa', 'first_name' => 'Ali', 'sex' => 'M']);
    $a = Addons\Clinique\ClinicService::addAppointment(['patient_id' => $p2, 'practitioner_id' => $doc, 'start_at' => date('Y-m-d') . 'T10:00', 'duration_min' => 30]);
    throws(fn() => Addons\Clinique\ClinicService::addAppointment(['patient_id' => $CL['p'], 'practitioner_id' => $doc, 'start_at' => date('Y-m-d') . ' 10:15']), 'créneau');
    $vid = Addons\Clinique\ClinicService::setAppointmentStatus($a, 'arrived');
    ok($vid > 0 && DB::value('SELECT status FROM clinic_visits WHERE id = ?', [$vid]) === 'waiting', 'arrivée : consultation en attente');
    ok(in_array($doc, array_map('intval', array_column(Addons\Clinique\ClinicService::practitioners(), 'id')), true), 'médecin proposé');
    $CL['p2'] = $p2;
});
test('Module Clinique : consultation, diagnostic obligatoire, facture avec prise en charge, encaissement', function () use (&$CL) {
    actingAs('medecin');
    Addons\Clinique\ClinicService::startVisit($CL['v']);
    throws(fn() => Addons\Clinique\ClinicService::startVisit($CL['v']), 'déjà commencée');
    throws(fn() => Addons\Clinique\ClinicService::saveVisit($CL['v'], ['anamnesis' => 'Fièvre depuis 3 jours'], true), 'diagnostic');
    Addons\Clinique\ClinicService::saveVisit($CL['v'], ['anamnesis' => 'Fièvre depuis 3 jours', 'diagnosis' => 'Paludisme simple'], true);
    eq('done', DB::value('SELECT status FROM clinic_visits WHERE id = ?', [$CL['v']]));
    $consult = (int) DB::value("SELECT id FROM clinic_acts WHERE name = 'Consultation de médecine générale'");
    $tdr = (int) DB::value("SELECT id FROM clinic_acts WHERE name LIKE 'Goutte épaisse%'");
    throws(fn() => Addons\Clinique\ClinicService::createBill($CL['p'], [['label' => 'Remise spéciale', 'quantity' => 1, 'unit_price' => 100]]), 'réservées au responsable');
    $CL['bill'] = Addons\Clinique\ClinicService::createBill($CL['p'], [['act_id' => $consult, 'quantity' => 1], ['act_id' => $tdr, 'quantity' => 1, 'unit_price' => 1]], $CL['v']);
    $b = DB::one('SELECT * FROM clinic_bills WHERE id = ?', [$CL['bill']]);
    eq(4500.0, (float) $b['subtotal'], '3000 + 1500 (prix du catalogue, prix envoyé ignoré)');
    eq(3600.0, (float) $b['coverage_amount'], 'prise en charge 80 %');
    eq(900.0, (float) $b['patient_amount'], 'part patient');
    eq('Mutuelle Santé', $b['insurer']);
    eq($CL['bill'], (int) DB::value('SELECT bill_id FROM clinic_visits WHERE id = ?', [$CL['v']]));
    actingAs('accueil');
    $session = (int) S\CashService::currentSession(Auth::user())['id'];
    $cash = S\CashService::expectedCash($session);
    throws(fn() => Addons\Clinique\ClinicService::payBill($CL['bill'], ['method' => 'card', 'amount' => '5000']), 'électronique');
    $change = Addons\Clinique\ClinicService::payBill($CL['bill'], ['method' => 'cash', 'amount' => '1000']);
    eq(100.0, $change, 'monnaie');
    eq('paid', DB::value('SELECT status FROM clinic_bills WHERE id = ?', [$CL['bill']]));
    eq($cash + 900, S\CashService::expectedCash($session), 'encaissement dans la caisse');
    throws(fn() => Addons\Clinique\ClinicService::cancelBill($CL['bill'], 'erreur'), 'déjà encaissée');
});
test('Module Clinique : ordonnance transmise à la pharmacie, délivrance = vente + ordonnancier + stock', function () use (&$CL) {
    $amox = (int) DB::value("SELECT id FROM products WHERE name = 'Amoxicilline test'");
    $para = (int) DB::value("SELECT id FROM products WHERE status = 'active' AND sellable = 1 AND sales_channel <> 'restaurant' AND requires_prescription = 0 AND track_lots = 0 AND id IN (SELECT product_id FROM stock_levels WHERE warehouse_id = 1 AND quantity >= 5) ORDER BY id LIMIT 1");
    ok($amox > 0 && $para > 0, 'articles de test');
    DB::update('products', ['track_stock' => 1], 'id = ?', [$amox]);
    actingAs('medecin');
    throws(fn() => Addons\Clinique\PharmacyLink::create($CL['p'], [['drug_name' => '']]), 'au moins un médicament');
    $pid = Addons\Clinique\PharmacyLink::create($CL['p'], [
        ['product_id' => $amox, 'posology' => '1 gélule x 3/j', 'duration' => '7 jours', 'quantity' => 3],
        ['product_id' => $para, 'posology' => '1 cp si fièvre', 'quantity' => 2],
        ['drug_name' => 'Sirop hors stock', 'posology' => '1 c. à café', 'quantity' => 1],
    ], $CL['v'], null, 'Urgent');
    eq('new', DB::value('SELECT status FROM clinic_prescriptions WHERE id = ?', [$pid]));
    $items = array_column(DB::all('SELECT id, product_id, drug_name FROM clinic_prescription_items WHERE prescription_id = ? ORDER BY id', [$pid]), null, 'product_id');
    eq('Amoxicilline test', $items[$amox]['drug_name'], 'nom repris du stock');
    actingAs('accueil');
    throws(fn() => Addons\Clinique\PharmacyLink::dispense($pid, [$items[$amox]['id'] => 3], [['method' => 'cash', 'amount' => 99999]], uuid()), 'réservée à la pharmacie');
    setting_set('feature_pharmacy', '1');
    actingAs('pharma');
    $s0 = stock($amox);
    throws(fn() => Addons\Clinique\PharmacyLink::dispense($pid, [$items[$amox]['id'] => 4], [['method' => 'cash', 'amount' => 99999]], uuid()), 'supérieure au reste');
    $u = uuid();
    $r = Addons\Clinique\PharmacyLink::dispense($pid, [$items[$amox]['id'] => 2], [['method' => 'cash', 'amount' => 3000]], $u);
    eq(3000.0, (float) DB::value('SELECT total FROM sales WHERE id = ?', [$r['sale_id']]), '2 × 1500');
    eq($s0 - 2, stock($amox), 'stock déstocké');
    eq('partial', DB::value('SELECT status FROM clinic_prescriptions WHERE id = ?', [$pid]), 'délivrée en partie');
    $reg = DB::one('SELECT * FROM prescriptions WHERE sale_id = ?', [$r['sale_id']]);
    ok($reg && $reg['prescription_ref'] === DB::value('SELECT number FROM clinic_prescriptions WHERE id = ?', [$pid]) && str_contains($reg['patient_name'], 'ISSA'), 'ordonnancier rempli automatiquement');
    $again = Addons\Clinique\PharmacyLink::dispense($pid, [$items[$amox]['id'] => 1], [['method' => 'cash', 'amount' => 3000]], $u);
    ok($again['duplicate'], 'double envoi sans effet');
    eq(2, (int) DB::value('SELECT dispensed_qty FROM clinic_prescription_items WHERE id = ?', [$items[$amox]['id']]), 'quantité non comptée deux fois');
    Addons\Clinique\PharmacyLink::dispense($pid, [$items[$amox]['id'] => 1, $items[$para]['id'] => 2], [['method' => 'cash', 'amount' => 100000]], uuid());
    eq('dispensed', DB::value('SELECT status FROM clinic_prescriptions WHERE id = ?', [$pid]), 'entièrement délivrée (hors stock ignoré)');
    eq(2, (int) DB::value('SELECT COUNT(*) FROM clinic_dispensings WHERE prescription_id = ?', [$pid]));
    throws(fn() => Addons\Clinique\PharmacyLink::cancel($pid), 'non encore délivrée');
    setting_set('feature_pharmacy', '0');
    $CL['presc'] = $pid;
});
test('Module Clinique : hospitalisation, plan de soins, écran de supervision, sortie facturée', function () use (&$CL) {
    actingAs('medecin');
    $beds = array_column(DB::all('SELECT id, name FROM clinic_beds ORDER BY id'), 'id', 'name');
    throws(fn() => Addons\Clinique\HospitalService::admit(['patient_id' => $CL['p'], 'bed_id' => $beds['Lit 1']]), 'motif');
    $sid = Addons\Clinique\HospitalService::admit(['patient_id' => $CL['p'], 'bed_id' => $beds['Lit 1'], 'reason' => 'Paludisme grave', 'kind' => 'hospitalisation']);
    throws(fn() => Addons\Clinique\HospitalService::admit(['patient_id' => $CL['p2'], 'bed_id' => $beds['Lit 1'], 'reason' => 'x']), 'occupé');
    throws(fn() => Addons\Clinique\HospitalService::admit(['patient_id' => $CL['p'], 'bed_id' => $beds['Lit 2'], 'reason' => 'x']), 'déjà hospitalisé');
    $sid2 = Addons\Clinique\HospitalService::admit(['patient_id' => $CL['p2'], 'bed_id' => $beds['Obs 1'], 'reason' => 'Déshydratation', 'kind' => 'observation']);
    $care = Addons\Clinique\HospitalService::addCare($sid, ['kind' => 'medication', 'label' => 'Artésunate', 'dose' => '120 mg', 'route' => 'IV', 'every_hours' => 12, 'start_at' => date('Y-m-d H:i', strtotime('-2 hours'))]);
    Addons\Clinique\HospitalService::addCare($sid2, ['kind' => 'monitoring', 'label' => 'Diurèse', 'every_hours' => 4]);
    throws(fn() => Addons\Clinique\HospitalService::addCare($sid, ['label' => 'x', 'every_hours' => 0]), 'fréquence');
    actingAs('infirmier');
    Addons\Clinique\ClinicService::addVitals(['patient_id' => $CL['p'], 'stay_id' => $sid, 'temperature' => '39.5', 'systolic' => '85', 'diastolic' => '50', 'spo2' => '90']);
    $board = Addons\Clinique\HospitalService::board();
    eq(2, $board['totals']['patients']);
    eq(1, $board['totals']['alerts'], 'un lit en alerte');
    eq(1, $board['totals']['late'], 'un soin en retard');
    eq(1, $board['totals']['vitals'], 'constantes à reprendre (lit d\'observation sans relevé)');
    eq(2, $board['free'], 'lits libres');
    $first = $board['beds'][0];
    eq('Lit 1', $first['bed'], 'lit en alerte affiché en premier');
    eq(['t', 'ta', 'spo2'], $first['stay']['flags'], 'constantes hors seuils signalées');
    ok($first['stay']['allergies'] === 'Pénicilline', 'allergie affichée');
    Addons\Clinique\HospitalService::logCare($care);
    $next = DB::value('SELECT next_due_at FROM clinic_care_plan WHERE id = ?', [$care]);
    ok(abs(strtotime($next) - strtotime('+10 hours')) < 120, 'prochaine prise : échéance + 12 h (' . $next . ')');
    eq(0, Addons\Clinique\HospitalService::board()['totals']['late'], 'plus de retard');
    throws(fn() => Addons\Clinique\HospitalService::logCare($care, 'oublié'), 'invalide');
    Addons\Clinique\HospitalService::addNote($sid, 'Patient conscient, perfusion en place', 'nurse');
    Addons\Clinique\HospitalService::transfer($sid, $beds['Lit 2']);
    eq($beds['Lit 2'], (int) DB::value('SELECT bed_id FROM clinic_stays WHERE id = ?', [$sid]), 'transfert');
    actingAs('medecin');
    DB::run('UPDATE clinic_stays SET admitted_at = ? WHERE id = ?', [date('Y-m-d H:i:s', strtotime('-50 hours')), $sid]);
    $inj = (int) DB::value("SELECT id FROM clinic_acts WHERE name = 'Injection'");
    $billId = Addons\Clinique\HospitalService::discharge($sid, 'gueri', 'Apyrétique', [['act_id' => $inj, 'quantity' => 4]]);
    $b = DB::one('SELECT * FROM clinic_bills WHERE id = ?', [$billId]);
    eq(3 * 7500 + 4 * 500.0, (float) $b['subtotal'], '3 jours × 7500 + 4 injections');
    eq(round((float) $b['subtotal'] * 0.2, 2), (float) $b['patient_amount'], 'prise en charge 80 % appliquée');
    eq('discharged', DB::value('SELECT status FROM clinic_stays WHERE id = ?', [$sid]));
    eq(0, (int) DB::value('SELECT COUNT(*) FROM clinic_care_plan WHERE stay_id = ? AND active = 1', [$sid]), 'plan de soins arrêté');
    throws(fn() => Addons\Clinique\HospitalService::discharge($sid, 'gueri', ''), 'clôturée');
    $sid3 = Addons\Clinique\HospitalService::admit(['patient_id' => $CL['p'], 'bed_id' => $beds['Lit 2'], 'reason' => 'Réadmission']);
    ok($sid3 > 0, 'lit libéré à la sortie');
    $CL['stay'] = $sid2;
    $CL['care2'] = (int) DB::value('SELECT id FROM clinic_care_plan WHERE stay_id = ?', [$sid2]);
});
test('Mise à jour par copie des fichiers : base mise à jour automatiquement, modules installables', function () {
    actingAs('admin');
    // Installation 1.3 mise à jour sans « php bin/migrate.php » : table addons absente
    DB::connection()->exec('RENAME TABLE addons TO addons_sauve');
    DB::run("DELETE FROM schema_migrations WHERE version = '0005_modules_menu'");
    App\Core\Addons::reset();
    eq(['0005_modules_menu'], S\InstallService::pendingMigrations(), 'migration en attente détectée');
    App\Core\Addons::install('livraisons', false);
    eq([], S\InstallService::pendingMigrations(), 'migration appliquée avant l\'installation du module');
    eq('1', (string) DB::value("SELECT active FROM addons WHERE code = 'livraisons'"), 'module installé');
    DB::connection()->exec('DROP TABLE addons');
    DB::connection()->exec('RENAME TABLE addons_sauve TO addons');
    App\Core\Addons::reset();
    // Mise à jour automatique au premier affichage d'une page (sauvegarde avant)
    DB::run("DELETE FROM schema_migrations WHERE version = '0005_modules_menu'");
    $before = count(glob(S\BackupService::dir() . '/*avant_migration*.sql.gz') ?: []);
    eq(['0005_modules_menu'], S\InstallService::autoMigrate());
    eq($before + 1, count(glob(S\BackupService::dir() . '/*avant_migration*.sql.gz') ?: []), 'sauvegarde de sécurité avant migration');
    eq([], S\InstallService::autoMigrate(), 'rien à faire ensuite');
    foreach (glob(S\BackupService::dir() . '/*avant_migration*') ?: [] as $f) {
        @unlink($f);
    }
});
test('Modules : dossiers mal placés après décompression (Windows) rangés automatiquement', function () {
    $d = App\Core\Addons::dir();
    @mkdir($d . '/module-demo_range-1.0.0/demo_range', 0775, true);
    file_put_contents($d . '/module-demo_range-1.0.0/demo_range/manifest.json', json_encode(['code' => 'demo_range', 'name' => 'Demo', 'version' => '1.0.0']));
    @mkdir($d . '/Demo Nom (1)', 0775, true);
    file_put_contents($d . '/Demo Nom (1)/manifest.json', json_encode(['code' => 'demo_nom', 'name' => 'Demo 2', 'version' => '1.0.0']));
    $notes = App\Core\Addons::normalizeFolders();
    eq(2, count($notes), implode(' | ', $notes));
    ok(is_file($d . '/demo_range/manifest.json') && !is_dir($d . '/module-demo_range-1.0.0'), 'sous-dossier remonté');
    ok(is_file($d . '/demo_nom/manifest.json') && !is_dir($d . '/Demo Nom (1)'), 'dossier renommé selon le manifeste');
    ok(isset(App\Core\Addons::discover(true)['demo_range']), 'module détecté');
    foreach (['demo_range', 'demo_nom'] as $x) {
        @unlink("$d/$x/manifest.json");
        @rmdir("$d/$x");
    }
    App\Core\Addons::reset();
});
test('Licence : clé signée liée à l\'installation, falsification refusée, essai puis lecture seule', function () use ($LIC_SK) {
    actingAs('admin');
    S\LicenceService::reset();
    $id = S\LicenceService::installId();
    ok((bool) preg_match('/^PW(-[A-F0-9]{4}){4}$/', $id), 'identifiant d\'installation');
    eq($id, S\LicenceService::installId(), 'identifiant stable');
    $st = S\LicenceService::status();
    eq('trial', $st['state']);
    eq(30, $st['days_left']);
    ok(!S\LicenceService::blocks('POST', '/pos/sale'), 'ventes autorisées pendant l\'essai');
    $key = S\LicenceService::sign(['n' => 'L-TEST', 'c' => 'Boutique Test', 'i' => $id, 'e' => null, 't' => date('Y-m-d')], $LIC_SK);
    eq('Boutique Test', S\LicenceService::verify($key)['c']);
    // Serveur sans extension sodium (WampServer par défaut) : bibliothèque PHP pure
    S\LicenceService::$forceCompat = true;
    eq('Boutique Test', S\LicenceService::verify($key)['c'], 'vérification sans extension sodium');
    $k2 = S\LicenceService::sign(['c' => 'Sans sodium', 'i' => $id], $LIC_SK);
    S\LicenceService::$forceCompat = false;
    eq('Sans sodium', S\LicenceService::verify($k2)['c'], 'signature PHP pure vérifiée par l\'extension');
    [$h, $data, $sig] = explode('.', $key);
    $forged = json_decode(base64_decode(strtr($data, '-_', '+/')), true);
    $forged['c'] = 'Pirate';
    throws(fn() => S\LicenceService::verify($h . '.' . rtrim(strtr(base64_encode(json_encode($forged)), '+/', '-_'), '=') . '.' . $sig), 'non authentique');
    throws(fn() => S\LicenceService::verify(S\LicenceService::sign(['c' => 'X', 'i' => 'PW-0000-0000-0000-0000'], $LIC_SK)), 'autre installation');
    throws(fn() => S\LicenceService::verify(S\LicenceService::sign(['c' => 'X', 'i' => $id, 'e' => '2020-01-01'], $LIC_SK)), 'expirée');
    $other = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    throws(fn() => S\LicenceService::verify(S\LicenceService::sign(['c' => 'X', 'i' => $id], $other)), 'non authentique', BusinessException::class);
    throws(fn() => S\LicenceService::verify('n importe quoi'), 'mal formée');
    // Fin de l'essai : lecture seule (consultation, licence, sauvegardes toujours possibles)
    DB::run("UPDATE audit_logs SET created_at = NOW() - INTERVAL 40 DAY WHERE action = 'licence.trial_start'");
    setting_set('trial_started', '0');
    S\LicenceService::reset();
    eq('expired', S\LicenceService::status()['state'], 'essai terminé');
    ok(S\LicenceService::blocks('POST', '/pos/sale'), 'vente bloquée');
    ok(!S\LicenceService::blocks('GET', '/sales'), 'consultation possible');
    ok(!S\LicenceService::blocks('POST', '/licence') && !S\LicenceService::blocks('POST', '/backups/create') && !S\LicenceService::blocks('POST', '/login'), 'activation, sauvegarde, connexion possibles');
    S\LicenceService::activate($key);
    S\LicenceService::reset();
    eq('licensed', S\LicenceService::status()['state']);
    ok(!S\LicenceService::blocks('POST', '/pos/sale'), 'licence activée : ventes autorisées');
    // Copie de la base sur une autre installation : la clé n'est plus valable
    setting_set('install_id', 'PW-AAAA-BBBB-CCCC-DDDD');
    S\LicenceService::reset();
    eq('expired', S\LicenceService::status()['state'], 'clé copiée refusée');
    ok(str_contains((string) S\LicenceService::status()['error'], 'autre installation'));
    setting_set('install_id', $id);
    S\LicenceService::reset();
    eq('licensed', S\LicenceService::status()['state']);
    $md = S\Contract::markdown();
    ok(str_contains($md, 'Mahamadou Hamidou') && str_contains($md, 'Article 10'), 'contrat');
});
test('Mise à jour du logiciel : chemins contrôlés, version, intégrité, sauvegardes, copie', function () {
    actingAs('admin');
    foreach (['../etc/passwd' => null, 'app/../../x' => null, 'storage/backups/a.sql' => null, '.env' => null, 'public/uploads/x.jpg' => null, 'C:/Windows/x' => null,
              'evil.php' => null, 'app\\Core\\DB.php' => 'app/Core/DB.php', 'README.md' => 'README.md', '.htaccess' => '.htaccess', 'addons/clinique/manifest.json' => 'addons/clinique/manifest.json'] as $in => $out) {
        eq($out, S\UpdateService::normalizePath($in), "chemin $in");
    }
    $t = S\UpdateService::start();
    $dir = S\UpdateService::stagingDir($t);
    throws(fn() => S\UpdateService::stagingDir('../x'), 'invalide');
    // Copie de la version actuelle + un nouveau fichier, envoyée comme le ferait le navigateur
    @unlink(BASE_PATH . '/release.json');
    $files = S\UpdateService::listFiles(BASE_PATH);
    ok(count($files) > 200 && !in_array('.env', $files, true), 'fichiers du logiciel (sans .env)');
    $up = ['tmp_name' => [], 'error' => [], 'size' => []];
    $paths = [];
    foreach ($files as $f) {
        $up['tmp_name'][] = BASE_PATH . '/' . $f; $up['error'][] = 0; $up['size'][] = filesize(BASE_PATH . '/' . $f); $paths[] = $f;
    }
    $tmpNew = tempnam(sys_get_temp_dir(), 'maj');
    file_put_contents($tmpNew, 'nouveau fichier');
    $up['tmp_name'][] = $tmpNew; $up['error'][] = 0; $up['size'][] = 15; $paths[] = 'docs/TEST_MISE_A_JOUR.txt';
    $up['tmp_name'][] = $tmpNew; $up['error'][] = 0; $up['size'][] = 15; $paths[] = '../../pirate.php';
    $r = S\UpdateService::receive($t, $paths, $up, false);
    eq(1, $r['skipped'], 'chemin malveillant ignoré');
    $i = S\UpdateService::inspect($t);
    eq([], $i['errors']);
    ok(!$i['verified'] && str_contains(implode(' ', $i['warnings']), 'release.json'), 'sans release.json : avertissement');
    throws(fn() => S\UpdateService::apply($t, false), 'Intégrité non vérifiée');
    // Manifeste d'intégrité : fichier altéré détecté
    file_put_contents($dir . '/release.json', json_encode(S\UpdateService::buildManifest($dir)));
    eq(true, S\UpdateService::inspect($t)['verified'], 'intégrité vérifiée');
    $orig = file_get_contents($dir . '/routes/web.php');
    file_put_contents($dir . '/routes/web.php', $orig . "\n// modifié");
    ok(str_contains(implode(' ', S\UpdateService::inspect($t)['errors']), 'modifié'), 'altération détectée');
    file_put_contents($dir . '/routes/web.php', $orig);
    unlink($dir . '/app/bootstrap.php');
    ok(str_contains(implode(' ', S\UpdateService::inspect($t)['errors']), 'app/bootstrap.php'), 'dossier incomplet refusé');
    copy(BASE_PATH . '/app/bootstrap.php', $dir . '/app/bootstrap.php');
    // Version plus ancienne refusée
    $cfg = file_get_contents($dir . '/config/app.php');
    file_put_contents($dir . '/config/app.php', preg_replace("/'version' => '[0-9.]+'/", "'version' => '1.0.0'", $cfg));
    ok(str_contains(implode(' ', S\UpdateService::inspect($t)['errors']), 'plus ancienne'), 'retour en arrière refusé');
    file_put_contents($dir . '/config/app.php', $cfg);
    // Application (même version : réparation) : sauvegardes base + code, nouveau fichier copié
    $before = count(S\UpdateService::codeBackups());
    // Étapes très courtes (comme un hébergement qui coupe les requêtes longues) : reprise jusqu'à la fin
    $steps = 0;
    do {
        $st = S\UpdateService::step($t, false, 0.05);
        $steps++;
    } while (!$st['done'] && $steps < 5000);
    $res = $st['result'];
    ok($steps > 3, "installation découpée en $steps étapes");
    ok($res['files'] > 200 && is_file(BASE_PATH . '/docs/TEST_MISE_A_JOUR.txt'), 'fichiers copiés');
    ok($res['changed'] >= 1 && $res['changed'] < 20, 'seuls les fichiers modifiés sont copiés : ' . $res['changed']);
    ok(!is_file(BASE_PATH . '/pirate.php') && !is_file(dirname(BASE_PATH, 2) . '/pirate.php'), 'aucun fichier hors du logiciel');
    eq($before + 1, count(S\UpdateService::codeBackups()), 'sauvegarde du code');
    ok(is_file(S\BackupService::path($res['db_backup'])), 'sauvegarde de la base');
    ok(!is_dir($dir) && !is_file(STORAGE_PATH . '/maintenance.lock'), 'préparation et verrou nettoyés');
    throws(fn() => S\UpdateService::restoreCode('../../.env'), 'introuvable');
    unlink(BASE_PATH . '/docs/TEST_MISE_A_JOUR.txt');
    ok(is_file(BASE_PATH . '/release.json'), 'manifeste copié avec la version');
    unlink(BASE_PATH . '/release.json');
    unlink($tmpNew);
    @unlink(S\BackupService::dir() . '/' . $res['code_backup']);
    foreach (glob(S\BackupService::dir() . '/*avant_maj_*') ?: [] as $f) { @unlink($f); }
});
$V2 = [];
test('POS Marwa — modes de fonctionnement : caisse, accueil et menus adaptés, module requis', function () use (&$V2) {
    actingAs('admin');
    eq('commerce', S\BusinessMode::current(), 'mode par défaut');
    eq('/pos', S\BusinessMode::posUrl());
    App\Core\Addons::setActive('clinique', false);
    App\Core\Addons::reset();
    throws(fn() => S\BusinessMode::set('clinique'), 'nécessite le module');
    App\Core\Addons::setActive('clinique', true);
    App\Core\Addons::reset();
    S\BusinessMode::set('clinique');
    eq('/clinique/caisse', S\BusinessMode::posUrl(), 'caisse clinique');
    ok(S\BusinessMode::hides('/pressing/suivi') && S\BusinessMode::hides('/quotes') && !S\BusinessMode::hides('/clinique/caisse'), 'menus des autres activités masqués');
    S\BusinessMode::set('pressing');
    eq('/pressing/caisse', S\BusinessMode::posUrl());
    ok(S\BusinessMode::hides('/clinique') && !S\BusinessMode::hides('/pressing/suivi'));
    S\BusinessMode::set('pharmacie');
    ok(feature('pharmacy') && feature('lots'), 'fonctions pharmacie activées');
    eq('/pos', S\BusinessMode::posUrl());
    S\BusinessMode::set('commerce', true);
    ok(!S\BusinessMode::hides('/clinique'), 'option : afficher tous les menus');
    S\BusinessMode::set('commerce');
    throws(fn() => S\BusinessMode::set('inconnu'), 'inconnu');
    ok((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.business_mode'") >= 4, 'changements journalisés');
    ok((int) DB::value('SELECT COUNT(*) FROM clinic_beds') >= 1 && (int) DB::value('SELECT COUNT(*) FROM pressing_orders') >= 1, 'données des modules conservées');
});
test('POS Marwa — stock vendable : lots périmés et rappelés exclus de la quantité affichée en caisse', function () {
    actingAs('admin');
    $pid = S\ProductService::create(S\ProductService::validate(['name' => 'Sirop lots test', 'sale_price' => 900, 'cost_price' => 500, 'track_stock' => 1, 'track_lots' => 1]), 3, 1, 'L-BON', date('Y-m-d', strtotime('+1 year')));
    $old = S\StockService::lot($pid, 1, 'L-PERIME', '2020-01-01', 500);
    DB::run('UPDATE stock_lots SET quantity = 5 WHERE id = ?', [$old]);
    DB::run('UPDATE stock_levels SET quantity = quantity + 5 WHERE product_id = ? AND warehouse_id = 1', [$pid]);
    eq(8.0, stock($pid), 'stock physique (avec le lot périmé)');
    $sellable = (float) DB::value('SELECT ' . App\Controllers\PosController::stockSql(1) . ' FROM products p LEFT JOIN stock_levels sl ON sl.product_id = p.id AND sl.warehouse_id = 1 WHERE p.id = ?', [$pid]);
    eq(3.0, $sellable, 'stock vendable : lot périmé exclu');
    $a = S\BusinessMode::pharmacyAlerts(1);
    ok($a['expired'] >= 1, 'alerte lot périmé');
    DB::run('UPDATE stock_lots SET quantity = 0 WHERE id = ?', [$old]);
    DB::run('UPDATE stock_levels SET quantity = quantity - 5 WHERE product_id = ? AND warehouse_id = 1', [$pid]);
});
test('POS Marwa — appareils : approbation des nouveaux appareils du réseau, blocage, poste serveur toujours autorisé', function () {
    actingAs('admin');
    $u = DB::one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE username = ?', ['caissier']);
    $_COOKIE['pm_device'] = str_repeat('a', 40);
    $_SERVER['REMOTE_ADDR'] = '192.168.1.50';
    setting_set('device_approval', '1');
    throws(fn() => S\DeviceService::onLogin($u), 'autorisé par l\'administrateur');
    $dev = (int) DB::value('SELECT id FROM user_devices WHERE user_id = ? AND status = ?', [$u['id'], 'pending']);
    ok($dev > 0, 'appareil en attente');
    S\DeviceService::setStatus($dev, 'approved');
    eq($dev, S\DeviceService::onLogin($u), 'appareil autorisé');
    App\Core\Session::set('device_id', $dev);
    ok(S\DeviceService::sessionAllowed());
    S\DeviceService::setStatus($dev, 'revoked');
    ok(!S\DeviceService::sessionAllowed(), 'session coupée après blocage');
    throws(fn() => S\DeviceService::onLogin($u), 'bloqué');
    App\Core\Session::forget('device_id');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_COOKIE['pm_device'] = str_repeat('b', 40);
    ok(S\DeviceService::onLogin($u) > 0, 'poste serveur (local) toujours autorisé');
    setting_set('device_approval', '0');
    unset($_COOKIE['pm_device']);
    eq('Android · Chrome (mobile)', S\DeviceService::label('Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'));
    ok(S\NetworkService::isPrivate('192.168.1.10') && S\NetworkService::isPrivate('10.0.0.5') && !S\NetworkService::isPrivate('8.8.8.8') && !S\NetworkService::isPrivate('127.0.0.1'), 'adresses du réseau local');
});
test('POS Marwa — inventaire : scan (+1 par lecture), saisie manuelle, remise à zéro, synthèse, validation, verrouillage', function () use (&$V2) {
    actingAs('magasin');
    $p1 = S\ProductService::create(S\ProductService::validate(['name' => 'Inv scan A', 'barcode' => '2100000000012', 'sale_price' => 100, 'cost_price' => 60, 'track_stock' => 1]), 10, 1);
    $p2 = S\ProductService::create(S\ProductService::validate(['name' => 'Inv scan B', 'barcode' => '2100000000029', 'sale_price' => 100, 'cost_price' => 50, 'track_stock' => 1]), 4, 1);
    $p3 = S\ProductService::create(S\ProductService::validate(['name' => 'Inv scan C', 'sale_price' => 100, 'cost_price' => 40, 'track_stock' => 1]), 7, 1);
    DB::run('UPDATE products SET track_stock = 1 WHERE id IN (?,?,?)', [$p1, $p2, $p3]);
    foreach (DB::all("SELECT id FROM inventories WHERE status = 'draft'") as $d) { DB::update('inventories', ['status' => 'cancelled'], 'id = ?', [$d['id']]); }
    $inv = S\InventoryService::start(1, null, 'Test v2');
    throws(fn() => S\InventoryService::start(1, null, null), 'déjà en cours');
    for ($i = 0; $i < 15; $i++) {
        $r = S\InventoryService::scan($inv, '2100000000012');
    }
    eq(15.0, $r['counted'], '15 lectures = 15 unités');
    eq(15, $r['scans']);
    S\InventoryService::scan($inv, '2100000000029');
    throws(fn() => S\InventoryService::scan($inv, '9999999999999'), 'Aucun article');
    $it2 = (int) DB::value('SELECT id FROM inventory_items WHERE inventory_id = ? AND product_id = ?', [$inv, $p2]);
    $r2 = S\InventoryService::setCount($inv, $it2, '3', 'carton abîmé');
    eq(3.0, $r2['counted'], 'correction manuelle');
    eq('carton abîmé', $r2['note']);
    $it3 = (int) DB::value('SELECT id FROM inventory_items WHERE inventory_id = ? AND product_id = ?', [$inv, $p3]);
    S\InventoryService::setCount($inv, $it3, '9');
    S\InventoryService::setCount($inv, $it3, null, null, true);
    eq(null, DB::value('SELECT counted_qty FROM inventory_items WHERE id = ?', [$it3]), 'comptage recommencé');
    S\InventoryService::setCount($inv, $it3, '7');
    throws(fn() => S\InventoryService::setCount($inv, $it3, '-2'), 'invalide');
    $s = S\InventoryService::summary($inv);
    ok($s['surplus'] >= 1 && $s['missing'] >= 1 && $s['conform'] >= 1, 'excédents, manquants, conformes : ' . json_encode($s));
    ok((int) DB::value('SELECT COUNT(*) FROM inventory_count_log WHERE inventory_id = ?', [$inv]) >= 18, 'historique des corrections');
    eq((int) DB::value("SELECT id FROM users WHERE username = 'magasin'"), (int) DB::value('SELECT counted_by FROM inventory_items WHERE id = ?', [$it2]), 'compteur enregistré');
    actingAs('gerant');
    S\InventoryService::validate($inv);
    eq(15.0, stock($p1), 'stock corrigé : +5');
    eq(3.0, stock($p2), 'stock corrigé : −1');
    eq(7.0, stock($p3), 'conforme : inchangé');
    eq((int) DB::value("SELECT id FROM users WHERE username = 'gerant'"), (int) DB::value('SELECT validated_by FROM inventories WHERE id = ?', [$inv]), 'valideur enregistré');
    throws(fn() => S\InventoryService::scan($inv, '2100000000012'), 'ne peut plus être modifié');
    throws(fn() => S\InventoryService::setCount($inv, $it2, '1'), 'ne peut plus être modifié');
    $V2['inv'] = $inv;
});
test('POS Marwa — étiquettes : réglages contrôlés, EAN-13, champs choisis', function () {
    $c = App\Controllers\LabelController::config(['paper' => 'a4', 'width' => '50', 'height' => '30', 'cols' => '3', 'fields' => ['name', 'price', 'pirate'], 'font' => '99']);
    eq(['name', 'price'], $c['fields'], 'champs filtrés');
    eq(24.0, (float) $c['font'], 'taille bornée');
    throws(fn() => App\Controllers\LabelController::config(['paper' => 'a4', 'width' => '80', 'cols' => '3']), 'dépassent la largeur');
    eq(1, App\Controllers\LabelController::config(['paper' => 'roll', 'cols' => '4'])['cols'], 'rouleau : une colonne');
    ok(S\Barcode::isEan13('2100000000012') && !S\Barcode::isEan13('2100000000013') && !S\Barcode::isEan13('ABC'), 'contrôle EAN-13');
    ok(str_contains(S\Barcode::ean13Svg('2100000000012'), 'viewBox="0 0 117 '), 'EAN-13 : 95 modules + zones de silence');
    ok(str_contains(S\Barcode::auto('2100000000012'), 'viewBox="0 0 117 ') && !str_contains(S\Barcode::auto('ABC-12'), 'viewBox="0 0 117 '), 'EAN-13 si valide, sinon Code 128');
});
test('POS Marwa — pressing : retrait partiel, solde au dernier retrait, retards', function () use (&$V2) {
    actingAs('caissier');
    $chem = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Chemise'");
    $r = Addons\Pressing\PressingService::create(['customer_name' => 'Partiel', 'phone' => '90 55 55 55', 'lines' => [['article_id' => $chem, 'service' => 'lavage', 'quantity' => 3]], 'deposit' => ['method' => 'cash', 'amount' => '500']]);
    $id = $r['id'];
    Addons\Pressing\PressingService::setStatus($id, 'ready');
    $item = (int) DB::value('SELECT id FROM pressing_items WHERE order_id = ?', [$id]);
    throws(fn() => Addons\Pressing\PressingService::deliverPartial($id, [$item => 5]), 'restante');
    Addons\Pressing\PressingService::deliverPartial($id, [$item => 2]);
    eq('partial', DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$id]), 'partiellement retiré');
    eq(2, (int) DB::value('SELECT picked_qty FROM pressing_items WHERE id = ?', [$item]));
    throws(fn() => Addons\Pressing\PressingService::cancel($id, 'x'), 'ne peut plus être annulé');
    throws(fn() => Addons\Pressing\PressingService::deliverPartial($id, [$item => 1]), 'Reste à payer');
    $change = Addons\Pressing\PressingService::deliverPartial($id, [$item => 1], ['method' => 'cash', 'amount' => '2000']);
    eq(1000.0, $change, 'solde 1000 sur 2000 remis');
    eq('delivered', DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$id]), 'toutes les pièces retirées');
    ok(DB::value('SELECT delivered_at FROM pressing_orders WHERE id = ?', [$id]) !== null, 'date et heure du retrait');
    eq(['received', 'ready', 'partial', 'delivered'], array_column(DB::all('SELECT to_status FROM pressing_status_log WHERE order_id = ? ORDER BY id', [$id]), 'to_status'), 'historique des statuts');
    eq(3, Addons\Pressing\PressingService::lateDays(['status' => 'ready', 'due_date' => date('Y-m-d', strtotime('-3 days'))]), 'jours de retard');
    eq(0, Addons\Pressing\PressingService::lateDays(['status' => 'delivered', 'due_date' => '2020-01-01']), 'retiré : pas de retard');
    $V2['pressing'] = $id;
});
test('Pressing : pourcentage express au choix, livraison à domicile, colonne « Prêts à livrer »', function () {
    $P = Addons\Pressing\PressingService::class;
    $chem = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Chemise'");
    $line = [['article_id' => $chem, 'service' => 'lavage', 'quantity' => 4]]; // 2 000 F
    actingAs('admin');
    $P::saveSettings(['express_pourcent' => '50', 'express_choix' => '25 ; 50 ; 100', 'express_delai' => '1', 'delai' => '3', 'livraison_frais' => '1000']);
    eq([25.0, 50.0, 100.0], $P::expressChoices(), 'choix proposés');
    throws(fn() => $P::saveSettings(['express_pourcent' => '50', 'express_choix' => '25 ; abc', 'express_delai' => '1', 'delai' => '3', 'livraison_frais' => '0']), 'Choix express');
    actingAs('caissier');
    $r = $P::create(['customer_name' => 'Express', 'phone' => '90 66 66 66', 'express' => true, 'express_pct' => '100', 'lines' => $line]);
    $o = DB::one('SELECT * FROM pressing_orders WHERE id = ?', [$r['id']]);
    eq([2000.0, 100.0, 4000.0], [(float) $o['express_fee'], (float) $o['express_pct'], (float) $o['total']], 'express 100 % choisi par le caissier');
    $r = $P::create(['customer_name' => 'Express', 'phone' => '90 66 66 66', 'express' => true, 'lines' => $line]);
    eq(1000.0, (float) DB::value('SELECT express_fee FROM pressing_orders WHERE id = ?', [$r['id']]), 'sans choix : taux par défaut 50 %');
    throws(fn() => $P::create(['customer_name' => 'Express', 'phone' => '90 66 66 66', 'express' => true, 'express_pct' => '10', 'lines' => $line]), 'seuls les choix proposés');
    actingAs('admin');
    $r = $P::create(['customer_name' => 'Express', 'phone' => '90 66 66 66', 'express' => true, 'express_pct' => '37,5', 'lines' => $line]);
    eq(750.0, (float) DB::value('SELECT express_fee FROM pressing_orders WHERE id = ?', [$r['id']]), 'responsable : 37,5 % libre');
    throws(fn() => $P::create(['customer_name' => 'Express', 'phone' => '90 66 66 66', 'express' => true, 'express_pct' => '400', 'lines' => $line]), 'invalide');
    // Livraison à domicile
    actingAs('caissier');
    throws(fn() => $P::create(['customer_name' => 'Livré', 'phone' => '90 55 44 33', 'delivery' => true, 'delivery_address' => '', 'lines' => $line]), 'adresse');
    $r = $P::create(['customer_name' => 'Livré', 'phone' => '90 55 44 33', 'delivery' => true, 'delivery_address' => 'Plateau, rue du Fleuve, porte bleue', 'lines' => $line]);
    $o = DB::one('SELECT * FROM pressing_orders WHERE id = ?', [$r['id']]);
    eq([1, 1000.0, 3000.0], [(int) $o['delivery'], (float) $o['delivery_fee'], (float) $o['total']], 'frais de livraison par défaut ajoutés');
    $P::setStatus($r['id'], 'ready');
    $board = $P::board();
    ok(in_array($r['id'], array_column($board['columns']['delivery'], 'id'), true) && !in_array($r['id'], array_column($board['columns']['ready'], 'id'), true), 'écran : colonne « Prêts à livrer », pas « Prêts à retirer »');
    eq(1, $board['totals']['delivery'] >= 1 ? 1 : 0, 'compteur « à livrer »');
    ok(!in_array('Livré', array_column($P::board(true)['ready'], 'name'), true), 'affichage clients : livraisons non montrées');
    $GLOBALS['PR_LIVRAISON'] = $r['id'];
});
test('Pressing : articles au kilo, poids avec virgule, pièces entières, retrait partiel au poids', function () {
    actingAs('caissier');
    $P = Addons\Pressing\PressingService::class;
    $kilo = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Linge au kilo'");
    $chem = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Chemise'");
    eq('kg', DB::value('SELECT unit FROM pressing_articles WHERE id = ?', [$kilo]), 'article fourni au kilo');
    eq(4.5, $P::parseQty('4,5'));
    eq(0.75, $P::parseQty(' 0,750 '));
    eq(null, $P::parseQty('abc'));
    eq('4,5 kg', $P::qty('4.500', 'kg'));
    eq('3', $P::qty('3.000'));
    throws(fn() => $P::create(['customer_name' => 'Kilo', 'phone' => '90 77 77 77', 'lines' => [['article_id' => $chem, 'service' => 'lavage', 'quantity' => '1,5']]]), 'nombre entier');
    throws(fn() => $P::create(['customer_name' => 'Kilo', 'phone' => '+227 90 77 77 77+227 90 77 77 77', 'lines' => [['article_id' => $chem, 'service' => 'lavage', 'quantity' => '1']]]), 'saisi deux fois');
    DB::insert('pressing_orders', ['number' => 'PR-TEST-DOUBLON', 'customer_name' => 'Doublon', 'phone' => '90 11 22 3390 11 22 33', 'due_date' => date('Y-m-d'), 'user_id' => Auth::id()]);
    DB::insert('pressing_clients', ['phone_key' => '9011223390112233', 'name' => 'Doublon', 'phone' => '90 11 22 3390 11 22 33']);
    ok(!in_array('Doublon', array_column($P::recentClients(), 'customer_name'), true), 'numéro doublé jamais reproposé à la saisie');
    $js = (string) file_get_contents(BASE_PATH . '/addons/pressing/public/pressing.js');
    ok(str_contains($js, "closest('.pressing-tile[data-article]')") && !str_contains($js, 'type="number" min="1" max="200"'), 'saisie : un clic dans une ligne n\'ajoute pas de pièce ; virgule jamais avalée par un champ numérique');
    DB::run("DELETE FROM pressing_orders WHERE number = 'PR-TEST-DOUBLON'");
    DB::run("DELETE FROM pressing_clients WHERE phone_key = '9011223390112233'");
    throws(fn() => $P::create(['customer_name' => 'Kilo', 'phone' => '90 77 77 77', 'lines' => [['article_id' => $kilo, 'service' => 'lavage', 'quantity' => '']]]), 'Poids invalide');
    throws(fn() => $P::create(['customer_name' => 'Kilo', 'phone' => '90 77 77 77', 'lines' => [['article_id' => $kilo, 'service' => 'lavage', 'quantity' => '0']]]), 'Poids invalide');
    $r = $P::create(['customer_name' => 'Kilo', 'phone' => '90 77 77 77', 'lines' => [
        ['article_id' => $kilo, 'service' => 'lavage', 'quantity' => '4,5'], ['article_id' => $kilo, 'service' => 'repassage', 'quantity' => '2.25'], ['article_id' => $chem, 'service' => 'lavage', 'quantity' => '3']]]);
    $o = DB::one('SELECT * FROM pressing_orders WHERE id = ?', [$r['id']]);
    eq(4500.0 + 1125.0 + 1500.0, (float) $o['total'], '4,5 kg × 1000 + 2,25 kg × 500 + 3 × 500');
    eq(5, (int) $o['pieces'], 'chaque sac pesé = un colis, plus 3 chemises');
    eq(6.75, (float) $o['weight'], 'poids total');
    eq(['4.500', '2.250', '3.000'], array_column(DB::all('SELECT quantity FROM pressing_items WHERE order_id = ? ORDER BY line_no', [$r['id']]), 'quantity'));
    ok(str_contains((string) DB::value('SELECT ' . $P::CLOTHES_SQL . ' FROM pressing_items i WHERE i.order_id = ?', [$r['id']]), '4,5 kg Linge au kilo'), 'liste des vêtements : 4,5 kg');
    $P::pay($r['id'], ['method' => 'cash', 'amount' => '7125']);
    $P::setStatus($r['id'], 'ready');
    $sac = (int) DB::value('SELECT id FROM pressing_items WHERE order_id = ? AND line_no = 1', [$r['id']]);
    throws(fn() => $P::deliverPartial($r['id'], [$sac => '5']), 'restante');
    $P::deliverPartial($r['id'], [$sac => '2,5']);
    eq('partial', DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$r['id']]), 'retrait partiel au poids');
    eq(2.5, (float) DB::value('SELECT picked_qty FROM pressing_items WHERE id = ?', [$sac]));
    $P::deliver($r['id']);
    eq('delivered', DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$r['id']]));
    eq(4.5, (float) DB::value('SELECT picked_qty FROM pressing_items WHERE id = ?', [$sac]), 'tout le linge remis');
});
test('Pressing 1.5 : lavage seul, articles au m², fichier des clients, rôles laveur / repasseur / livreur', function () {
    actingAs('caissier');
    $P = Addons\Pressing\PressingService::class;
    eq('Lavage seul', $P::SERVICES['lavage_seul'], 'service « Lavage seul »');
    $chem = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Chemise'");
    eq(250.0, (float) DB::value("SELECT price FROM pressing_prices WHERE article_id = ? AND service = 'lavage_seul'", [$chem]), 'tarif indicatif : 500 − 250 de repassage');
    $couv = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Couverture'");
    eq(2500.0, (float) DB::value("SELECT price FROM pressing_prices WHERE article_id = ? AND service = 'lavage_seul'", [$couv]), 'sans repassage : prix du lavage');
    $tapis = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Tapis / moquette'");
    eq('m2', DB::value('SELECT unit FROM pressing_articles WHERE id = ?', [$tapis]), 'article fourni au m²');
    eq('6,25 m²', $P::qty('6.250', 'm2'));
    ok($P::measured('m2') && $P::measured('kg') && !$P::measured('piece'), 'unités mesurées');
    throws(fn() => $P::create(['customer_name' => 'Surface', 'phone' => '90 66 66 66', 'lines' => [['article_id' => $tapis, 'service' => 'lavage_seul', 'quantity' => '0']]]), 'Surface invalide');
    $r = $P::create(['customer_name' => 'Awa Surface', 'phone' => '+227 90 66 66 66', 'delivery' => true, 'delivery_address' => 'Plateau, rue 12', 'delivery_fee' => '0', 'lines' => [
        ['article_id' => $tapis, 'service' => 'lavage_seul', 'quantity' => '6,25'], ['article_id' => $chem, 'service' => 'lavage_seul', 'quantity' => '2']]]);
    $o = DB::one('SELECT * FROM pressing_orders WHERE id = ?', [$r['id']]);
    eq(6.25 * 1500 + 2 * 250, (float) $o['total'], '6,25 m² × 1500 + 2 × 250');
    eq(6.25, (float) $o['area'], 'surface du dépôt');
    eq(0.0, (float) $o['weight'], 'aucun poids');
    eq(3, (int) $o['pieces'], 'le tapis compte pour un colis');
    ok(str_contains((string) DB::value('SELECT ' . $P::CLOTHES_SQL . ' FROM pressing_items i WHERE i.order_id = ?', [$r['id']]), '6,25 m² Tapis / moquette'), 'liste des vêtements : 6,25 m²');
    // Fichier des clients : créé au dépôt, retrouvé par le téléphone quelle que soit sa présentation
    $cid = (int) $o['client_id'];
    ok($cid > 0, 'fiche client créée');
    eq('Plateau, rue 12', DB::value('SELECT address FROM pressing_clients WHERE id = ?', [$cid]), 'adresse de livraison retenue');
    $r2 = $P::create(['customer_name' => 'Awa S.', 'phone' => '22790666666', 'lines' => [['article_id' => $chem, 'service' => 'lavage', 'quantity' => '1']]]);
    eq($cid, (int) DB::value('SELECT client_id FROM pressing_orders WHERE id = ?', [$r2['id']]), 'même client (même numéro)');
    eq('Awa Surface', DB::value('SELECT name FROM pressing_clients WHERE id = ?', [$cid]), 'la fiche n\'est pas renommée au comptoir');
    ok(in_array('Plateau, rue 12', array_column($P::recentClients(), 'address'), true), 'adresse proposée à la saisie');
    throws(fn() => $P::updateClient($cid, ['name' => 'Awa', 'phone' => '1234']), 'Téléphone invalide');
    $other = (int) DB::value("SELECT client_id FROM pressing_orders WHERE customer_name = 'Kilo' LIMIT 1");
    $otherPhone = (string) DB::value('SELECT phone FROM pressing_clients WHERE id = ?', [$other]);
    throws(fn() => $P::updateClient($cid, ['name' => 'Awa', 'phone' => $otherPhone]), 'appartient déjà');
    $P::updateClient($cid, ['name' => 'Awa Moussa', 'phone' => '+227 91 66 66 66', 'address' => 'Plateau, rue 14', 'note' => 'appeler le soir'], true);
    eq('Awa Moussa', DB::value('SELECT customer_name FROM pressing_orders WHERE id = ?', [$r['id']]), 'dépôt en cours mis à jour');
    eq('+227 91 66 66 66', DB::value('SELECT phone FROM pressing_orders WHERE id = ?', [$r2['id']]));
    eq('Plateau, rue 14', DB::value('SELECT delivery_address FROM pressing_orders WHERE id = ?', [$r['id']]), 'livraison en attente : nouvelle adresse');
    eq('22791666666', DB::value('SELECT phone_key FROM pressing_clients WHERE id = ?', [$cid]));
    ok((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'pressing.client' AND entity_id = ?", [$cid]) === 1, 'modification inscrite au journal d\'audit');
    $old = DB::one("SELECT id, customer_name FROM pressing_orders WHERE status = 'delivered' AND client_id IS NOT NULL LIMIT 1");
    $P::updateClient((int) DB::value('SELECT client_id FROM pressing_orders WHERE id = ?', [$old['id']]), ['name' => 'Nom corrigé', 'phone' => (string) DB::value('SELECT c.phone FROM pressing_clients c JOIN pressing_orders o ON o.client_id = c.id WHERE o.id = ?', [$old['id']])], true);
    eq($old['customer_name'], DB::value('SELECT customer_name FROM pressing_orders WHERE id = ?', [$old['id']]), 'dépôt déjà retiré : informations d\'origine conservées');
    // Même personne enregistrée deux fois (numéro saisi en double) : regroupement explicite
    DB::insert('pressing_clients', ['phone_key' => '2279066666622790666666', 'name' => 'Awa doublon', 'phone' => '+227 90 66 66 66+227 90 66 66 66']);
    $dup = (int) DB::value("SELECT id FROM pressing_clients WHERE name = 'Awa doublon'");
    $od = DB::insert('pressing_orders', ['number' => 'PR-TEST-FUSION', 'client_id' => $dup, 'customer_name' => 'Awa doublon', 'phone' => '+227 90 66 66 66+227 90 66 66 66', 'due_date' => date('Y-m-d'), 'user_id' => Auth::id()]);
    throws(fn() => $P::updateClient($dup, ['name' => 'Awa Moussa', 'phone' => '+227 91 66 66 66']), 'Regrouper');
    eq($cid, $P::updateClient($dup, ['name' => 'Awa Moussa', 'phone' => '+227 91 66 66 66', 'merge' => true]), 'fiche conservée : celle qui porte déjà le numéro');
    eq($cid, (int) DB::value('SELECT client_id FROM pressing_orders WHERE id = ?', [$od]), 'dépôt rattaché à la bonne fiche');
    eq(null, DB::value('SELECT id FROM pressing_clients WHERE id = ?', [$dup]), 'fiche en double retirée');
    DB::run("DELETE FROM pressing_orders WHERE number = 'PR-TEST-FUSION'");
    // Rôles proposés par le module
    foreach (['laveur' => 'laveur', 'repasseur' => 'repasseur', 'livreur' => 'livreur'] as $u => $role) {
        $rid = (int) DB::value('SELECT id FROM roles WHERE code = ?', [$role]);
        ok($rid > 0, "rôle $role créé");
        DB::insert('users', ['username' => $u, 'full_name' => ucfirst($u), 'password_hash' => Auth::hash('Mot-de-passe-2026'), 'pin_hash' => Auth::hash('4826'), 'role_id' => $rid, 'store_id' => 1, 'register_id' => 1]);
    }
    actingAs('laveur');
    ok(Auth::can('pressing.process') && Auth::can('pressing.view') && !Auth::can('pressing.deposit') && !Auth::can('pressing.clients'), 'laveur : atelier seulement');
    actingAs('repasseur');
    ok(Auth::can('pressing.process') && !Auth::can('pressing.deliver') && !Auth::can('pressing.manage'), 'repasseur : atelier seulement');
    actingAs('livreur');
    ok(Auth::can('pressing.deliver') && Auth::can('cash.open_close') && !Auth::can('pressing.view') && !Auth::can('pressing.clients'), 'livreur : livraisons et sa caisse');
    $GLOBALS['PR_TAPIS'] = $r['id'];
});
test('Écran client et afficheur client : réglages, images et vidéos, commandes de l\'afficheur', function () {
    actingAs('admin');
    $CD = App\Services\CustomerDisplay::class;
    ok(in_array('0007_ecran_client', array_column(DB::all('SELECT version FROM schema_migrations'), 'version'), true), 'migration appliquée');
    eq(false, $CD::clientConfig()['enabled'], 'désactivé par défaut');
    eq('COM3', $CD::validateAddress('com3'));
    eq('/dev/ttyUSB0', $CD::validateAddress('/dev/ttyUSB0'));
    eq('192.168.1.60:9100', $CD::validateAddress('192.168.1.60'));
    throws(fn() => $CD::validateAddress('8.8.8.8:9100'), 'réseau local');
    throws(fn() => $CD::validateAddress('C:\\Windows\\win.ini'), 'invalide');
    throws(fn() => $CD::validateAddress('/etc/passwd'), 'invalide');
    eq('Cafe creme a 500 F', $CD::ascii('Café crème à 500 F'), 'accents retirés pour l\'afficheur');
    eq('OEuf - 3 x 75 F ?', $CD::ascii('Œuf — 3 x 75 F ☺'), 'tiret long et caractère inconnu : un seul caractère chacun');
    eq("\x1B\x40\x0C\x0B" . 'TOTAL         1 500 F' . "\x1F\x24\x01\x02" . 'RENDU           500 F', $CD::vfdBytes('TOTAL         1 500 F', 'RENDU           500 F', 'escpos', 21), 'ESC/POS : effacement, ligne 1, curseur ligne 2');
    eq("\x1B\x40\x0C\x1BQA" . str_pad('Merci', 20) . "\r\x1BQB" . str_pad('A bientot', 20) . "\r", $CD::vfdBytes('Merci', 'À bientôt', 'cd5220', 20), 'CD5220');
    eq(20 + 20 + 4, strlen($CD::vfdBytes(str_repeat('x', 50), 'y', 'text', 20)), 'lignes coupées à la largeur');
    throws(fn() => $CD::saveSettings(['display_thanks_seconds' => '0', 'display_image_seconds' => '8', 'vfd_cols' => '20', 'vfd_baud' => '9600']), 'remerciement');
    throws(fn() => $CD::saveSettings(['display_thanks_seconds' => '6', 'display_image_seconds' => '8', 'vfd_cols' => '20', 'vfd_baud' => '9600', 'vfd_enabled' => '1', 'vfd_mode' => 'server', 'vfd_address' => '../../etc']), 'Port de l\'afficheur invalide');
    // Afficheur réseau : un vrai port TCP local reçoit les octets
    $srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
    $CD::saveSettings(['display_enabled' => '1', 'display_welcome' => 'Bienvenue chez Test', 'display_thanks' => 'Merci !', 'display_thanks_seconds' => '5', 'display_image_seconds' => '7', 'display_theme' => 'light', 'display_show_lines' => '1',
        'vfd_enabled' => '1', 'vfd_mode' => 'server', 'vfd_protocol' => 'escpos', 'vfd_cols' => '20', 'vfd_baud' => '9600', 'vfd_address' => '127.0.0.1:' . $port, 'vfd_line1' => 'BIENVENUE', 'vfd_line2' => '']);
    $c = $CD::clientConfig();
    ok($c['enabled'] && $c['vfd']['enabled'] && $c['theme'] === 'light' && $c['thanksSeconds'] === 5, 'réglages enregistrés');
    $CD::vfdSend('TOTAL 1 500 F', 'Merci');
    $conn = stream_socket_accept($srv, 2);
    $got = (string) fread($conn, 200);
    fclose($conn); fclose($srv);
    eq($CD::vfdBytes('TOTAL 1 500 F', 'Merci', 'escpos', 20), $got, 'afficheur réseau : octets reçus');
    // Images et vidéos
    $png = tempnam(sys_get_temp_dir(), 'cd'); $im = imagecreatetruecolor(2400, 1200); imagepng($im, $png); imagedestroy($im);
    $id1 = $CD::addMedia(['name' => 'promo.png', 'tmp_name' => $png, 'size' => filesize($png), 'error' => UPLOAD_ERR_OK], 'Promotion');
    $m1 = DB::one('SELECT * FROM display_media WHERE id = ?', [$id1]);
    ok($m1['kind'] === 'image' && is_file(PUBLIC_PATH . '/uploads/' . $m1['path']), 'image enregistrée');
    eq(1920, getimagesize(PUBLIC_PATH . '/uploads/' . $m1['path'])[0], 'image réduite à 1920 px');
    $mp4 = tempnam(sys_get_temp_dir(), 'cd');
    file_put_contents($mp4, "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\x00", 64) . "\x00\x00\x00\x08free");
    $id2 = $CD::addMedia(['name' => 'pub.mp4', 'tmp_name' => $mp4, 'size' => filesize($mp4), 'error' => UPLOAD_ERR_OK]);
    eq('video', DB::value('SELECT kind FROM display_media WHERE id = ?', [$id2]), 'vidéo MP4 enregistrée');
    $fake = tempnam(sys_get_temp_dir(), 'cd'); file_put_contents($fake, '<?php echo 1;');
    throws(fn() => $CD::addMedia(['name' => 'piege.mp4', 'tmp_name' => $fake, 'size' => 14, 'error' => UPLOAD_ERR_OK]), 'pas une vidéo');
    throws(fn() => $CD::addMedia(['name' => 'piege.php', 'tmp_name' => $fake, 'size' => 14, 'error' => UPLOAD_ERR_OK]), 'Format d\'image non autorisé');
    $CD::move($id2, -1);
    eq([$id2, $id1], array_map('intval', array_column($CD::media(), 'id')), 'ordre de diffusion modifié');
    $CD::updateMedia($id1, ['title' => 'Promo', 'duration_s' => 12, 'active' => true]);
    $pl = $CD::playlist();
    ok(count($pl) === 2 && $pl[1]['seconds'] === 12 && str_ends_with(strtok($pl[0]['url'], '?'), '.mp4'), 'liste de diffusion');
    $CD::updateMedia($id2, ['duration_s' => 8, 'active' => false]);
    eq(1, count($CD::playlist()), 'fichier non diffusé retiré de la liste');
    $path2 = PUBLIC_PATH . '/uploads/' . DB::value('SELECT path FROM display_media WHERE id = ?', [$id2]);
    $CD::deleteMedia($id2);
    ok(!is_file($path2) && !DB::value('SELECT 1 FROM display_media WHERE id = ?', [$id2]), 'suppression : fichier et ligne');
    ok((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action IN ('display.media_add','display.media_delete','settings.customer_display')") >= 4, 'journal d\'audit');
    $GLOBALS['CD_MEDIA'] = $id1;
});
test('Écran client 3.1 : photos JPEG légères, sens EXIF, transparence, fichiers manquants, transitions', function () {
    actingAs('admin');
    $CD = App\Services\CustomerDisplay::class;
    $file = fn(string $path, string $name) => ['name' => $name, 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
    // Photo de téléphone 4032 × 3024 : reste en JPEG (léger), réduite à 1920 px
    $jpg = tempnam(sys_get_temp_dir(), 'cd');
    $im = imagecreatetruecolor(4032, 3024);
    for ($y = 0; $y < 3024; $y += 24) { imagefilledrectangle($im, 0, $y, 4031, $y + 23, (int) imagecolorallocate($im, intdiv($y, 12) % 255, 120, 200 - intdiv($y, 16) % 200)); }
    imagejpeg($im, $jpg, 92); imagedestroy($im);
    $m = DB::one('SELECT * FROM display_media WHERE id = ?', [$CD::addMedia($file($jpg, 'photo.jpg'), 'Photo')]);
    ok(str_ends_with($m['path'], '.jpg'), 'format JPEG conservé (avant : PNG 10 fois plus lourd)');
    $info = getimagesize(UPLOADS_PATH . '/' . $m['path']);
    eq([1920, 1440, 'image/jpeg'], [$info[0], $info[1], $info['mime']]);
    ok(filesize(UPLOADS_PATH . '/' . $m['path']) < 1500000, 'fichier léger : ' . filesize(UPLOADS_PATH . '/' . $m['path']));
    // Sens de prise de vue (EXIF Orientation = 6 : appareil tourné) appliqué
    if (function_exists('exif_read_data')) {
        $im = imagecreatetruecolor(400, 200); ob_start(); imagejpeg($im); $raw = (string) ob_get_clean(); imagedestroy($im);
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
        $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\x00\x00" . $tiff;
        $rot = tempnam(sys_get_temp_dir(), 'cd');
        file_put_contents($rot, substr($raw, 0, 2) . $app1 . substr($raw, 2));
        $mr = DB::one('SELECT * FROM display_media WHERE id = ?', [$CD::addMedia($file($rot, 'portrait.jpg'))]);
        eq([200, 400], array_slice(getimagesize(UPLOADS_PATH . '/' . $mr['path']), 0, 2), 'photo en portrait remise droite');
    }
    // Image avec transparence : reste en PNG, transparence conservée
    $png = tempnam(sys_get_temp_dir(), 'cd');
    $im = imagecreatetruecolor(300, 200); imagesavealpha($im, true); imagealphablending($im, false);
    imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127)); imagefilledellipse($im, 150, 100, 200, 120, (int) imagecolorallocate($im, 245, 166, 35));
    imagepng($im, $png); imagedestroy($im);
    $mp = DB::one('SELECT * FROM display_media WHERE id = ?', [$CD::addMedia($file($png, 'logo.png'))]);
    $out = imagecreatefrompng(UPLOADS_PATH . '/' . $mp['path']);
    ok(str_ends_with($mp['path'], '.png') && ((imagecolorat($out, 2, 2) >> 24) & 0x7F) === 127, 'coin transparent conservé');
    // Fichier disparu du disque (réinstallation sans le dossier uploads) : sauté, plus d'écran noir
    $before = count($CD::playlist());
    @unlink(UPLOADS_PATH . '/' . $mp['path']);
    eq($before - 1, count($CD::playlist()), 'fichier manquant retiré de la diffusion');
    ok(!$CD::fileExists($mp) && $CD::fileExists($m), 'détection du fichier manquant');
    ok(str_contains($CD::playlist()[0]['url'], '?v='), 'adresse versionnée (pas d\'ancienne image en cache)');
    // Réglages de diffusion
    $base = ['display_enabled' => '1', 'display_welcome' => 'Bienvenue chez Test', 'display_thanks' => 'Merci !', 'display_thanks_seconds' => '5', 'display_image_seconds' => '7', 'display_theme' => 'light', 'display_show_lines' => '1',
        'vfd_enabled' => '1', 'vfd_mode' => 'server', 'vfd_protocol' => 'escpos', 'vfd_cols' => '20', 'vfd_baud' => '9600', 'vfd_address' => (string) setting('vfd_address'), 'vfd_line1' => 'BIENVENUE', 'vfd_line2' => ''];
    $CD::saveSettings($base + ['display_transition' => 'slide', 'display_fill' => 'cover', 'display_captions' => '1']);
    $c = $CD::clientConfig();
    ok($c['transition'] === 'slide' && $c['fill'] === 'cover' && $c['captions'] && !$c['progress'], 'transition, remplissage, titres, progression');
    $CD::saveSettings($base + ['display_transition' => '<script>', 'display_fill' => 'x', 'display_progress' => '1']);
    $c = $CD::clientConfig();
    ok($c['transition'] === 'kenburns' && $c['fill'] === 'blur' && $c['progress'], 'valeurs inconnues → valeurs par défaut');
    foreach (array_keys($CD::TRANSITIONS) as $fx) {
        ok($fx === 'random' || str_contains((string) file_get_contents(PUBLIC_PATH . '/assets/css/customer-display.css'), '.fx-' . $fx . ' '), "effet $fx défini");
    }
    // Message clair quand le fichier dépasse la limite de PHP
    throws(fn() => $CD::addMedia(['name' => 'gros.jpg', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]), 'limite PHP');
    foreach ([$m, $mp, $mr ?? null] as $x) {
        if ($x) { $CD::deleteMedia((int) $x['id']); }
    }
});
test('MARWA IA : compréhension du français (périodes, intentions, noms), rapports, droits, aide, modèle local', function () {
    actingAs('admin');
    eq(['0001_marwa_ia', '0002_formation'], App\Core\Addons::install('marwa_ia', false), 'installation');
    App\Core\Addons::registerAutoload();
    $T = Addons\MarwaIa\Text::class;
    $E = Addons\MarwaIa\Engine::class;
    $now = strtotime('2026-11-02 10:00:00');
    eq(['2026-11-01', '2026-11-01'], array_values(array_slice($T::period('ventes d\'hier', $now), 0, 2)), 'hier');
    eq(['2026-10-01', '2026-10-31'], array_values(array_slice($T::period('le mois dernier', $now), 0, 2)), 'mois dernier');
    eq(['2026-10-26', '2026-11-01'], array_values(array_slice($T::period('la semaine dernière', $now), 0, 2)), 'semaine dernière');
    eq(['2026-10-01', '2026-10-15'], array_values(array_slice($T::period('ventes du 1er au 15 octobre', $now), 0, 2)), 'du 1er au 15 octobre');
    eq(['2026-09-01', '2026-09-30'], array_values(array_slice($T::period('rapport de septembre', $now), 0, 2)), 'septembre');
    eq(['2025-03-01', '2025-03-31'], array_values(array_slice($T::period('en mars 2025', $now), 0, 2)), 'mars 2025');
    eq(['2026-10-04', '2026-11-02'], array_values(array_slice($T::period('les 30 derniers jours', $now), 0, 2)), '30 derniers jours');
    eq(['2026-10-10', '2026-10-20'], array_values(array_slice($T::period('du 10/10 au 20/10', $now), 0, 2)), 'du 10/10 au 20/10');
    eq('2000-01-01', $T::period('depuis le début', $now)['from'], 'depuis le début');
    eq(null, $T::period('rapport du client Moussa', $now), 'pas de période inventée');
    eq('pdf', $T::format('donne moi le rapport en PDF'));
    eq('csv', $T::format('en excel'));
    eq('que sais tu faire', $T::norm('Que sais-tu faire ?'), 'trait d\'union et ponctuation');
    // Données de test : un client, un fournisseur, un article
    $cid = DB::insert('customers', ['name' => 'Moussa Garba', 'phone' => '+227 90 12 34 56', 'credit_limit' => 50000]);
    DB::insert('customers', ['name' => 'Moussa Abdou', 'phone' => '+227 91 00 00 01']);
    $sid = DB::insert('suppliers', ['name' => 'Sahel Distribution IA']);
    eq('client', $E::understand('Donne moi le rapport du client Moussa Garba')['kind'], 'rapport du client');
    eq($cid, (int) $E::understand('relevé du client mousa garba')['id'], 'faute de frappe : mousa garba');
    eq($cid, (int) $E::understand('combien me doit le 90 12 34 56')['id'], 'client retrouvé par téléphone');
    eq('fournisseur', $E::understand('combien je dois au fournisseur sahel distribution ia')['kind'], 'fournisseur');
    $u = $E::understand('donne moi le rapport en pdf de toutes les transactions');
    ok($u['kind'] === 'ventes' && $u['format'] === 'pdf' && $u['period']['from'] === '2000-01-01', 'toutes les transactions en PDF');
    $u = $E::understand('ventes payées en mobile money cette semaine');
    ok($u['kind'] === 'ventes' && $u['filters']['method'] === 'mobile_money', 'filtre mobile money');
    foreach (['que dois-je commander ?' => 'commande', 'comment va ma boutique ?' => 'bilan', 'qui me doit de l\'argent' => 'creances', 'meilleures ventes du mois' => 'top',
        'articles sous le seuil' => 'rupture', 'produits qui périment' => 'peremption', 'bénéfice du mois' => 'benefice', 'dépenses du mois' => 'depenses',
        'écarts de caisse' => 'caisse', 'prévision du mois' => 'prevision', 'heure de pointe' => 'heures', 'meilleurs clients' => 'meilleurs_clients', 'articles qui ne se vendent pas' => 'dormants'] as $q => $k) {
        eq($k, $E::understand($q)['kind'] ?? null, $q);
    }
    eq(null, $E::understand('comment vendre au carton ?'), 'question d\'aide');
    // Suite de conversation
    $ctx = $E::understand('rapport du client Moussa Garba');
    $f = $E::understand('et en pdf', $ctx);
    ok($f['kind'] === 'client' && $f['format'] === 'pdf' && (int) $f['id'] === $cid, '« et en PDF ? » reprend le rapport précédent');
    $f = $E::understand('et le mois dernier ?', $ctx);
    ok($f['kind'] === 'client' && $f['period']['label'] === 'le mois dernier', '« et le mois dernier ? »');
    // Rapports : chiffres exacts sur une vraie vente à crédit
    actingAs('gerant');
    $prod = (int) DB::value("SELECT id FROM products WHERE status = 'active' AND track_stock = 1 AND sold_by_weight = 0 AND sale_price > 0 ORDER BY id LIMIT 1");
    $price = (float) DB::value('SELECT sale_price FROM products WHERE id = ?', [$prod]);
    App\Services\SaleService::create(['client_uuid' => '0b1a7f2e-9a1c-4c0e-8f00-0000000000a1', 'customer_id' => $cid, 'items' => [['product_id' => $prod, 'quantity' => 2]], 'payments' => []]);
    actingAs('admin');
    $r = $E::answer('rapport du client Moussa Garba');
    ok($r['type'] === 'report' && str_contains($r['html'], money(2 * $price)) && $r['table'] !== null, 'relevé : montant du crédit');
    ok((bool) array_filter($r['actions'], fn($a) => $a['kind'] === 'view' && str_contains($a['url'], 'k=client') && str_contains($a['url'], 'id=' . $cid)), 'bouton « Voir le PDF »');
    $r = $E::answer('qui me doit de l\'argent ?');
    ok(str_contains(strip_tags($r['html']), 'Moussa Garba'), 'créances : le client débiteur apparaît');
    $r = $E::answer('rapport du client Jean Inconnu');
    ok(str_contains($r['html'], 'aucun client'), 'client introuvable : réponse claire');
    $r = $E::answer('Comment vendre au carton ?');
    ok($r['type'] === 'help' && str_contains($r['html'], 'Conditionnements'), 'aide : vendre au carton');
    $r = $E::answer('comment faire un inventaire');
    ok(str_contains($r['html'], 'Démarrer'), 'aide : inventaire');
    $r = $E::answer('Bonjour');
    ok($r['type'] === 'briefing' && str_contains($r['html'], 'mia-proverbe'), 'salutation : point du jour avec une citation');
    // Droits : un caissier n'obtient pas le bénéfice ni le relevé d'un fournisseur
    actingAs('caissier');
    $r = $E::answer('bénéfice du mois');
    ok($r['type'] === 'error' && str_contains($r['html'], 'rôle'), 'caissier : bénéfice refusé');
    $r = $E::answer('ventes d\'aujourd\'hui');
    ok($r['type'] === 'report', 'caissier : ses ventes du jour');
    actingAs('admin');
    // Modèle local : adresses Internet refusées, vrai échange avec un faux serveur Ollama local
    $L = Addons\MarwaIa\Llm::class;
    throws(fn() => $L::validateUrl('https://api.openai.com'), 'réseau local');
    eq('http://127.0.0.1:11434', $L::validateUrl('http://127.0.0.1:11434/'));
    eq('http://192.168.1.20:11434', $L::validateUrl('http://192.168.1.20:11434'));
    $stub = tempnam(sys_get_temp_dir(), 'oll') . '.php';
    file_put_contents($stub, '<?php $in = json_decode(file_get_contents("php://input"), true); header("Content-Type: application/json"); echo json_encode(["message" => ["role" => "assistant", "content" => "Réponse locale (" . $in["model"] . ") : " . (str_contains($in["messages"][1]["content"], "Conditionnements") ? "contexte reçu" : "sans contexte")]]);');
    $port = 18000 + random_int(0, 900);
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $stub], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(400000);
    setting_set('ia_llm_url', 'http://127.0.0.1:' . $port);
    setting_set('ia_llm_model', 'modele-test');
    $r = $E::answer('Comment vendre au carton ?');
    proc_terminate($proc);
    setting_set('ia_llm_url', '');
    setting_set('ia_llm_model', '');
    ok(str_contains($r['html'], 'Réponse locale (modele-test) : contexte reçu'), 'modèle local : réponse rédigée à partir de la documentation');
    $GLOBALS['IA_CLIENT'] = $cid;
});
test('MARWA IA 1.1 : conseils chiffrés, contrôle de configuration, formation et quiz, calculs, lexique, modules, citations', function () {
    actingAs('admin');
    App\Core\Addons::registerAutoload();
    $E = Addons\MarwaIa\Engine::class;
    $C = Addons\MarwaIa\Tools::class;
    // Conseils : idées + constats calculés + citation, sans détourner les demandes de rapport
    $r = $E::answer('Comment augmenter mes ventes ?');
    ok($r['type'] === 'advice' && str_contains($r['html'], 'Augmenter les ventes') && str_contains($r['html'], '30 derniers jours') && str_contains($r['html'], 'mia-proverbe'), 'conseils ventes avec constats et citation');
    eq('advice', $E::answer('idées pour réduire les vols')['type'], 'conseils pertes');
    ok(str_contains($E::answer('aide moi à fidéliser mes clients')['html'], 'Fidéliser'), 'aide moi à fidéliser');
    eq('report', $E::answer('bénéfice du mois en pdf')['type'], 'un rapport reste un rapport');
    // Contrôle de configuration : ce qui manque est signalé avec le lien pour corriger
    $pid = (int) DB::value("SELECT id FROM products WHERE status = 'active' ORDER BY id LIMIT 1");
    $old = DB::one('SELECT cost_price, sale_price FROM products WHERE id = ?', [$pid]);
    DB::run('UPDATE products SET cost_price = 900, sale_price = 500 WHERE id = ?', [$pid]);
    $logo = setting('company_logo');
    setting_set('company_logo', '');
    $r = $E::answer('Qu\'ai-je oublié dans la configuration ?');
    ok($r['type'] === 'checkup' && $r['score'] > 0 && $r['score'] < 100, 'contrôle : score');
    ok(str_contains($r['html'], 'vendus en dessous du prix d\'achat') && str_contains($r['html'], 'logo'), 'contrôle : vente à perte et logo signalés');
    ok(str_contains($r['html'], url('/settings')), 'contrôle : lien pour corriger');
    DB::run('UPDATE products SET cost_price = ?, sale_price = ? WHERE id = ?', [$old['cost_price'], $old['sale_price'], $pid]);
    setting_set('company_logo', $logo);
    actingAs('caissier');
    ok(!str_contains($E::answer('vérifie ma configuration')['html'], 'logo'), 'caissier : pas de contrôle des paramètres');
    // Formation : leçon, quiz corrigé, progression enregistrée
    $r = $E::answer('forme-moi à la caisse');
    ok($r['type'] === 'training' && str_contains($r['html'], 'Vendre à la caisse'), 'leçon caisse');
    $q = $E::answer('quiz caisse');
    eq('quiz', $q['spec']['kind'], 'quiz démarré');
    $a1 = $E::answer('A', $q['spec']);
    ok(str_contains($a1['html'], 'Bonne réponse') && $a1['spec']['score'] === 1, 'bonne réponse A');
    $a2 = $E::answer('A', $a1['spec']);
    ok(str_contains($a2['html'], 'Pas tout à fait') && str_contains($a2['html'], 'F8'), 'mauvaise réponse corrigée et expliquée');
    $a3 = $E::answer('fenêtre espèces F10 puis mobile money et l\'opérateur', $a2['spec']);
    ok(str_contains($a3['html'], '2 / 3') && $a3['spec']['kind'] === 'fin', 'réponse écrite reconnue, résultat 2/3');
    eq(2, (int) DB::value("SELECT score FROM ia_training WHERE user_id = ? AND lesson = 'caisse'", [App\Core\Auth::id()]), 'progression enregistrée');
    ok(str_contains($E::answer('ma formation')['html'], '2/3'), 'progression affichée');
    eq('chat', $E::answer('stop', ['kind' => 'quiz', 'lesson' => 'caisse', 'i' => 1, 'score' => 0])['type'], 'quiz arrêté');
    ok(!str_contains($E::answer('formation')['html'], 'Lire ses rapports'), 'caissier : pas de leçon rapports');
    actingAs('admin');
    // Calculs
    $r = $C::calc('prix de vente pour un achat à 2 500 F avec 30 % de marge');
    ok(str_contains($r['html'], money(3250)) && str_contains($r['html'], money(3571)), 'prix de vente : sur le coût et sur la vente');
    $r = $C::calc('acheté 1 000 vendu 1 250');
    ok(str_contains($r['html'], money(250)) && str_contains($r['html'], '25 %') && str_contains($r['html'], '20 %') && str_contains($r['html'], '1,25'), 'marge, taux de marge, taux de marque, coefficient');
    ok(str_contains($C::calc('acheté 1000 vendu 800')['html'], 'à perte'), 'vente à perte signalée');
    ok(str_contains($C::calc('10 000 HT en TTC à 19 %')['html'], money(11900)), 'HT vers TTC');
    ok(str_contains($C::calc('11 900 TTC en HT à 19%')['html'], money(10000)), 'TTC vers HT');
    ok(str_contains($C::calc('remise de 15% sur 20 000')['html'], money(17000)), 'remise');
    ok(str_contains($C::calc('seuil de rentabilité avec 300 000 F de charges et 25 % de marge')['html'], money(1200000)), 'seuil de rentabilité');
    eq(null, $C::calc('marge du mois de mars 2026'), 'une période n\'est pas un calcul');
    eq([50000.0, 1500000.0], array_column($C::numbers('50 mille et 1,5 million'), 'v'), 'nombres en lettres');
    // Lexique, citations, modules
    ok(str_contains($E::answer('c\'est quoi le CMP ?')['html'], 'coût moyen'), 'lexique : CMP');
    ok(str_contains($E::answer('que veut dire FEFO')['html'], 'Premier périmé'), 'lexique : FEFO');
    $r = $E::answer('donne moi un proverbe');
    ok($r['type'] === 'wisdom' && preg_match('/<cite>[^<]+<\/cite>/', $r['html']), 'citation avec son origine');
    foreach (Addons\MarwaIa\Wisdom::QUOTES as $w) {
        ok($w[0] !== '' && $w[1] !== '' && $w[2], 'citation complète');
    }
    ok(App\Core\Addons::isActive('pressing'), 'pressing installé plus haut');
    $r = $E::answer('état du pressing');
    ok($r['type'] === 'module' && str_contains($r['html'], 'prêt(s) à remettre'), 'état du module pressing');
    if (!App\Core\Addons::isActive('hotel')) {
        ok(str_contains($E::answer('taux d\'occupation de l\'hôtel')['html'], 'pas activé'), 'module non activé : réponse claire');
    }
});
test('MARWA IA 1.2 : coach marketing selon l\'activité, textes prêts à publier, plan marketing PDF, domaine respecté', function () {
    actingAs('admin');
    App\Core\Addons::registerAutoload();
    $E = Addons\MarwaIa\Engine::class;
    $M = Addons\MarwaIa\Marketing::class;
    $mode = setting('business_mode');
    $type = setting('business_type');
    setting_set('business_mode', 'commerce');
    setting_set('business_type', 'alimentation');
    try {
        $r = $E::answer('Comment marche le marketing ?');
        ok($r['type'] === 'marketing' && str_contains($r['html'], 'Comment marche le marketing') && str_contains($r['html'], 'alimentation'), 'les bases, adaptées à l\'activité');
        foreach (['comment être visible sur les réseaux sociaux ?' => 'réseaux sociaux', 'comment lancer une campagne publicitaire sur facebook' => 'campagne publicitaire',
            'comment utiliser whatsapp business' => 'WhatsApp Business', 'comment être trouvé sur google maps' => 'Google Maps', 'quelles méthodes marketing de nos jours ?' => 'méthodes qui marchent',
            'comment faire une promotion pour la tabaski' => 'promotion', 'comment mesurer si ma pub marche' => 'Mesurer'] as $q => $want) {
            ok(str_contains($E::answer($q)['html'], $want), $q);
        }
        $g = $E::answer('écris un post pour mon article vedette');
        $sub = $M::subject('');
        ok($g['type'] === 'marketing' && substr_count($g['html'], 'data-mia-copy') === 4 && str_contains($g['html'], e($sub['name'])) && ($sub['price'] === null || str_contains($g['html'], e(money($sub['price'])))), 'textes prêts à publier avec le vrai article et son prix');
        $g2 = $E::answer('un autre texte', $g['spec']);
        ok($g2['type'] === 'marketing' && $g2['html'] !== $g['html'], '« un autre texte » donne une autre version');
        $sms = $M::texts($M::subject('riz'), 0);
        ok(mb_strlen(end($sms)) <= 160, 'SMS de 160 caractères au plus');
        ok(str_contains($E::answer('propose-moi un slogan')['html'], 'slogans'), 'slogans');
        $p = $E::answer('mon plan marketing en pdf');
        ok(str_contains((string) $p['open'], '/ia/marketing') && str_contains($p['open'], 'view=1'), 'plan marketing : PDF ouvert directement');
        $pdf = $M::pdf();
        ok(str_starts_with($pdf, '%PDF') && preg_match('/\/Count (\d+)/', $pdf, $m) && (int) $m[1] >= 7, 'plan marketing PDF de ' . ($m[1] ?? 0) . ' pages');
        // Garde-fou : les réponses restent dans le domaine
        eq('scope', $E::answer('qui va gagner le match ce soir ?')['type'], 'sport : hors domaine');
        eq('scope', $E::answer('parle moi de politique')['type'], 'politique : hors domaine');
        ok(str_contains($E::answer('quel médicament pour le paludisme ?')['html'], 'pharmacien'), 'santé : renvoi vers un professionnel');
        eq('report', $E::answer('ventes d\'aujourd\'hui')['type'], 'une question de gestion reste traitée');
        // Autre activité : pharmacie, règles rappelées et textes sans promotion de médicament
        setting_set('business_mode', 'pharmacie');
        setting_set('business_type', 'pharmacie');
        $r = $E::answer('comment faire de la publicité pour ma pharmacie');
        ok(str_contains($r['html'], 'pour votre pharmacie') && str_contains($r['html'], 'strictement encadrée'), 'pharmacie : règle de la publicité rappelée en premier');
        $g = $E::answer('écris un post facebook');
        ok(str_contains($g['html'], 'pharmacie') && !str_contains($g['html'], 'Quantités limitées'), 'pharmacie : publication sur les services');
        setting_set('business_mode', 'pressing');
        ok(str_contains($E::answer('propose-moi un slogan')['html'], 'vêtements') || str_contains($E::answer('propose-moi un slogan')['html'], 'Propre'), 'pressing : slogans du pressing');
        ok(str_contains($M::pdf(), 'pressing'), 'pressing : plan PDF adapté');
        // Formation et lexique
        ok(str_contains($E::answer('quiz marketing')['html'], 'Avant de payer une publicité'), 'quiz marketing');
        ok(str_contains($E::answer('c\'est quoi le ciblage ?')['html'], 'verra votre publicité'), 'lexique : ciblage');
    } finally {
        setting_set('business_mode', $mode);
        setting_set('business_type', $type);
    }
});
test('Menu à droite par défaut ; numéros d\'assistance', function () {
    eq('right', setting('menu_position'));
    $n = support_numbers();
    eq(2, count($n));
    eq('+22792081282', $n[0]['tel']);
    eq('+22787225202', $n[1]['tel'], 'indicatif +227 ajouté au second numéro');
});

echo "\n== Sauvegarde & restauration ==\n";
test('Sauvegarde vérifiée, altération détectée, restauration fidèle', function () {
    actingAs('admin');
    $countBefore = (int) DB::value('SELECT COUNT(*) FROM sales');
    $b = S\BackupService::create('test');
    ok(S\BackupService::verify($b['sql']), 'intégrité');
    DB::insert('expense_categories', ['name' => 'Après sauvegarde', 'type' => 'expense']);
    DB::run('DELETE FROM held_sales');
    $r = S\BackupService::restore($b['sql']);
    ok($r['statements'] > 50);
    eq($countBefore, (int) DB::value('SELECT COUNT(*) FROM sales'));
    eq(null, DB::value("SELECT id FROM expense_categories WHERE name = 'Après sauvegarde'"), 'état antérieur restauré');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM cash_sessions WHERE register_id = 1 AND status = 'open'"), 'colonne générée recalculée');
    // altération
    $path = S\BackupService::path($b['sql']);
    file_put_contents($path, 'x', FILE_APPEND);
    ok(!S\BackupService::verify($b['sql']), 'altération détectée');
    throws(fn() => S\BackupService::restore($b['sql']), 'corrompue');
    throws(fn() => S\BackupService::path('../../.env'), 'invalide');
    foreach (glob(S\BackupService::dir() . '/poswan_*_{test,avant_restauration}*', GLOB_BRACE) ?: [] as $f) {
        @unlink($f);
    }
});

// ------------------------------------------------------------------ tests HTTP
echo "\n== Lisibilité du ticket, ajout de stock rapide, import Excel ==\n";
test('Ticket : taille du texte, titres et sous-titres en gras, impression foncée (navigateur et ESC/POS)', function () {
    actingAs('admin');
    $D = S\Documents::class;
    $css = $D::ticketCss();
    ok(str_contains($css, '.ticket{font-size:12px}') && str_contains($css, 'font-weight:800') && !str_contains($css, '!important'), 'réglages par défaut : texte normal, titre en gras');
    foreach (['ticket_font_size' => '16', 'ticket_font' => 'sans', 'ticket_title_size' => '2.1', 'ticket_total_size' => '1.7', 'ticket_subtitle_bold' => '1', 'ticket_items_bold' => '1', 'ticket_dark' => '0'] as $k => $v) {
        setting_set($k, $v);
    }
    setting('__reset');
    $css = $D::ticketCss();
    ok(str_contains($css, '.ticket{font-size:16px}') && str_contains($css, 'Arial') && str_contains($css, '.t-company,.ticket .head-inv .big{font-size:2.10em;font-weight:800')
        && str_contains($css, '.ticket .t-sub') && str_contains($css, '.ticket .t-item{font-weight:800}') && str_contains($css, '.ticket .big,.ticket .total-box{font-size:1.70em}'), 'CSS générée : ' . $css);
    setting_set('ticket_font_size', '99'); setting('__reset');
    eq(12, $D::ticketLook()['size'], 'valeur hors liste → taille normale');
    setting_set('ticket_font_size', '14'); setting('__reset');
    // Les trois modèles portent les repères de mise en forme
    foreach (['1', '2', '3'] as $n) {
        [$sale, $items, $pays] = $D::sample();
        $html = App\Core\View::partial($D::ticketTemplate($n), ['doc' => $D::saleTicket($sale, $items, $pays, false)]);
        ok(str_contains($html, 't-company') && str_contains($html, 't-item') && str_contains($html, 't-sub'), "modèle $n : titre, sous-titres, articles repérés");
    }
    // Impression directe ESC/POS
    $sale = (int) DB::value('SELECT id FROM sales ORDER BY id LIMIT 1');
    $item = (string) DB::value('SELECT product_name FROM sale_items WHERE sale_id = ? LIMIT 1', [$sale]);
    $bytes = S\PrintService::receipt($sale, ['paper' => '80', 'open_drawer' => 0]);
    ok(str_contains($bytes, "\x1B\x45\x01" . S\EscPos::encode($item)), 'article en gras');
    ok(str_contains($bytes, "\x1D\x21" . chr((3 - 1) << 4 | (3 - 1))), 'titre très grand (3 × 3)');
    ok(str_contains($bytes, "\x1D\x21\x01"), 'grande taille de texte : hauteur doublée');
    setting_set('ticket_dark', '1'); setting('__reset');
    $dark = S\PrintService::receipt($sale, ['paper' => '80', 'open_drawer' => 0]);
    ok(!str_contains($dark, "\x1B\x45\x00"), 'impression foncée : le gras n\'est jamais coupé');
    ok(str_contains($D::ticketCss(), 'font-weight:700!important'), 'impression foncée (navigateur)');
    foreach (['ticket_font_size' => '12', 'ticket_font' => 'mono', 'ticket_title_size' => '1.35', 'ticket_total_size' => '1.35', 'ticket_subtitle_bold' => '0', 'ticket_items_bold' => '0', 'ticket_dark' => '0'] as $k => $v) {
        setting_set($k, $v);
    }
    setting('__reset');
});
$QS = [];
test('Ajout de stock rapide : stock par entrepôt, quantité, auteur, historique, coût moyen, refus', function () use (&$QS) {
    $u = actingAs('admin');
    $pid = S\ProductService::create(S\ProductService::validate(['name' => 'Ciment test 50 kg', 'reference' => 'CIM-TEST', 'barcode' => '6001234500019', 'sale_price' => 5500, 'cost_price' => 4000, 'track_stock' => 1]));
    $wh2 = (int) DB::value('SELECT id FROM warehouses WHERE id <> 1 AND active = 1 LIMIT 1') ?: DB::insert('warehouses', ['store_id' => 1, 'name' => 'Dépôt test', 'code' => 'DTEST']);
    $QS = ['pid' => $pid, 'wh2' => $wh2];
    $card = S\StockEntry::productCard($pid);
    ok(count($card['levels']) >= 2 && $card['total'] == 0, 'fiche : stock par entrepôt');
    $r = S\StockEntry::quickAdd($pid, 1, 20, null, null, null, 'Livraison Dangote');
    eq([0.0, 20.0], [$r['before'], $r['after']]);
    $m = DB::one("SELECT * FROM stock_movements WHERE product_id = ? AND type = 'quick_entry' ORDER BY id DESC LIMIT 1", [$pid]);
    ok($m && (int) $m['user_id'] === (int) $u['id'] && $m['reason'] === 'Livraison Dangote' && (float) $m['balance_after'] === 20.0, 'mouvement signé et motivé');
    eq(4000.0, (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$pid]), 'sans prix saisi : coût inchangé');
    S\StockEntry::quickAdd($pid, 1, 20, 5000, null, null, '');
    eq(4500.0, (float) DB::value('SELECT cost_price FROM products WHERE id = ?', [$pid]), 'prix saisi : coût moyen pondéré (20 × 4000 + 20 × 5000) / 40');
    S\StockEntry::quickAdd($pid, $wh2, 5, null, null, null, '');
    eq(45.0, S\StockEntry::productCard($pid)['total'], 'total tous entrepôts');
    throws(fn() => S\StockEntry::quickAdd($pid, 1, 0, null, null, null, ''), 'supérieure à zéro');
    throws(fn() => S\StockEntry::quickAdd($pid, 1, 2.5, null, null, null, ''), 'à l\'unité');
    throws(fn() => S\StockEntry::quickAdd($pid, 999999, 1, null, null, null, ''), 'Entrepôt invalide');
    $lotP = S\ProductService::create(S\ProductService::validate(['name' => 'Lait test lot', 'sale_price' => 800, 'cost_price' => 500, 'track_lots' => 1, 'track_stock' => 1]));
    throws(fn() => S\StockEntry::quickAdd($lotP, 1, 10, null, null, null, ''), 'numéro de lot');
    S\StockEntry::quickAdd($lotP, 1, 10, null, 'L-QS-1', '2027-03-31', '');
    eq(10.0, (float) DB::value("SELECT quantity FROM stock_lots WHERE product_id = ? AND lot_number = 'L-QS-1'", [$lotP]), 'lot créé avec sa péremption');
    $h = S\StockEntry::quickHistory(['user' => $u['id']]);
    ok(count($h) >= 4 && $h[0]['user_name'] === $u['full_name'], 'historique avec le nom de l\'auteur');
    ok((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'stock.quick_entry'") >= 4, 'journal d\'audit');
    ok(abs(S\StockService::level($pid, 1) - (float) DB::value('SELECT SUM(quantity) FROM stock_movements WHERE product_id = ? AND warehouse_id = 1', [$pid])) < 0.001, 'stock = somme des mouvements');
});
test('Classeur Excel : écriture puis relecture (textes, nombres, dates, cellules vides)', function () {
    $bin = App\Core\Xlsx::build('Stock', ['reference', 'quantite', 'nom'], [['A-1', 12.5, 'Riz « parfumé » & co'], ['=1+1', '', 'Formule neutralisée']]);
    $f = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    file_put_contents($f, $bin);
    $rows = App\Core\Xlsx::read($f);
    eq(['reference', 'quantite', 'nom'], $rows[0]);
    eq(['A-1', '12.5', 'Riz « parfumé » & co'], $rows[1]);
    eq("'=1+1", $rows[2][0], 'formule désamorcée à l\'export');
    eq('Z', App\Core\Xlsx::colName(25));
    eq(27, App\Core\Xlsx::colIndex('AB'));
});
test('Import du stock : aperçu sans modification, ajout, stock compté, erreurs, traces conservées', function () use (&$QS) {
    actingAs('admin');
    $pid = $QS['pid'];
    $whName = (string) DB::value('SELECT name FROM warehouses WHERE id = ?', [$QS['wh2']]);
    $before = S\StockService::level($pid, 1);
    $mk = function (array $rows) {
        $f = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        file_put_contents($f, App\Core\Xlsx::build('Stock', ['Référence', 'Code-barres', 'Nom', 'Entrepôt', 'Quantité', 'Prix achat', 'Motif'], $rows));
        return $f;
    };
    $f = $mk([
        ['CIM-TEST', '', 'Ciment', '', 10, '', 'Arrivage'],
        ['', '6001234500019', 'Ciment (code-barres)', $whName, 3, '', ''],
        ['INCONNU-9', '', 'Fantôme', '', 4, '', ''],
        ['CIM-TEST', '', 'Doublon', '', 1, '', ''],
        ['CIM-TEST', '', 'Vide', $whName, '', '', ''],
    ]);
    $lines = S\StockEntry::analyze(S\StockEntry::readFile($f, 'stock.xlsx'), 'add', 1);
    eq(['ok', 'ok', 'error', 'error', 'skip'], array_column($lines, 'status'));
    ok(str_contains($lines[2]['message'], 'introuvable') && str_contains($lines[3]['message'], 'déjà présent'), 'messages clairs');
    eq($before, S\StockService::level($pid, 1), 'aperçu : aucune modification');
    $r = S\StockEntry::apply($f, 'stock.xlsx', 'add', 1, 'Test import');
    eq(2, $r['applied']);
    eq($before + 10, S\StockService::level($pid, 1));
    $imp = DB::one('SELECT * FROM stock_imports WHERE id = ?', [$r['id']]);
    ok($imp && $imp['sha256'] === hash_file('sha256', $f) && is_file(STORAGE_PATH . '/imports/' . $imp['stored_file']), 'fichier d\'origine conservé avec son empreinte');
    eq(5, (int) DB::value('SELECT COUNT(*) FROM stock_import_rows WHERE import_id = ?', [$r['id']]), 'toutes les lignes tracées');
    $mv = DB::all("SELECT * FROM stock_movements WHERE ref_type = 'stock_import' AND ref_id = ?", [$r['id']]);
    ok(count($mv) === 2 && $mv[0]['type'] === 'import' && str_contains((string) $mv[0]['reason'], 'Import n° ' . $r['id']) && (int) $mv[0]['user_id'] > 0, 'mouvements rattachés à l\'import, signés');
    // Mode « stock compté » : l'écart est calculé (hausse et baisse)
    $cur = S\StockService::level($pid, 1);
    $f2 = $mk([['CIM-TEST', '', '', '', $cur - 7, '', 'Inventaire tournant']]);
    $r2 = S\StockEntry::apply($f2, 'compte.xlsx', 'set', 1);
    eq(1, $r2['applied']);
    eq($cur - 7, S\StockService::level($pid, 1), 'stock remplacé par le stock compté');
    eq(-7.0, (float) DB::value('SELECT qty_change FROM stock_import_rows WHERE import_id = ?', [$r2['id']]), 'écart enregistré');
    // CSV accepté aussi ; ancien .xls refusé avec explication
    $csv = tempnam(sys_get_temp_dir(), 'c') . '.csv';
    file_put_contents($csv, "reference;quantite\nCIM-TEST;2,5\n");
    eq('error', S\StockEntry::analyze(S\StockEntry::readFile($csv, 'stock.csv'), 'add', 1)[0]['status'], 'CSV lu (2,5 refusé pour un article à l\'unité)');
    throws(fn() => S\StockEntry::readFile($csv, 'vieux.xls'), 'Enregistrer sous');
    $bad = tempnam(sys_get_temp_dir(), 'c') . '.csv';
    file_put_contents($bad, "article;prix\nx;1\n");
    throws(fn() => S\StockEntry::readFile($bad, 'x.csv'), 'Colonnes introuvables');
    // Modèle pré-rempli
    $tpl = tempnam(sys_get_temp_dir(), 't') . '.xlsx';
    file_put_contents($tpl, S\StockEntry::template(1));
    $rows = App\Core\Xlsx::read($tpl);
    ok($rows[0][0] === 'reference' && in_array('CIM-TEST', array_column($rows, 0), true), 'modèle : articles et stock actuel');
});

test('Synchronisation de la base : tables, colonnes et listes de valeurs manquantes recréées sans perte', function () {
    actingAs('admin');
    $SS = S\SchemaSync::class;
    $c = $SS::check();
    ok($c['ok'] && !$c['pending_migrations'], 'base de test complète : ' . json_encode([$c['missing_tables'], $c['missing_columns'], $c['enums']]));
    ok($c['tables'] > 60, 'structure attendue lue (' . $c['tables'] . ' tables, logiciel + modules installés)');
    eq(['a', "ENUM('x','y,z')", 'DECIMAL(15,3) NOT NULL'], $SS::splitTop("a, ENUM('x','y,z'), DECIMAL(15,3) NOT NULL"), 'découpage SQL');
    // Base abîmée : table supprimée alors que sa migration est marquée faite, colonne perdue, ENUM réduit
    $n = (int) DB::value('SELECT COUNT(*) FROM customers');
    $pdo = DB::connection();
    $pdo->exec('SET foreign_key_checks = 0');
    $pdo->exec('DROP TABLE stock_import_rows');
    $pdo->exec('ALTER TABLE customers DROP COLUMN loyalty_points');
    $pdo->exec("DELETE FROM display_media WHERE kind = 'video'");
    $pdo->exec("ALTER TABLE display_media MODIFY kind ENUM('image') NOT NULL");
    $pdo->exec('SET foreign_key_checks = 1');
    $c = $SS::check();
    ok(in_array('stock_import_rows', $c['missing_tables'], true), 'table manquante détectée');
    ok(in_array('customers.loyalty_points', array_map(fn($x) => $x['table'] . '.' . $x['column'], $c['missing_columns']), true), 'colonne manquante détectée');
    ok($c['enums'] && $c['enums'][0]['table'] === 'display_media' && in_array('video', $c['enums'][0]['added'], true), 'valeur manquante détectée : ' . json_encode($c['enums']));
    $r = $SS::repair();
    ok(!$r['errors'] && $r['created'] === ['stock_import_rows'] && in_array('customers.loyalty_points', $r['columns'], true) && $r['enums'], 'réparé : ' . json_encode($r['errors']));
    ok($r['backup'] && is_file(S\BackupService::dir() . '/' . $r['backup']), 'sauvegarde faite avant la réparation');
    ok($SS::check()['ok'], 'base de nouveau complète');
    eq($n, (int) DB::value('SELECT COUNT(*) FROM customers'), 'aucune donnée perdue');
    ok((int) DB::value("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_import_rows'") === 1, 'clé étrangère recréée');
    ok(S\SchemaSync::lastReport()['created'] === ['stock_import_rows'], 'rapport conservé');
    // Automatique : rien à faire tant que les fichiers ne changent pas
    @unlink(STORAGE_PATH . '/cache/schema_sync');
    eq(null, $SS::auto(), 'base complète : aucune réparation');
    ok(is_file(STORAGE_PATH . '/cache/schema_sync'), 'empreinte mémorisée');
    $pdo->exec('ALTER TABLE customers DROP COLUMN loyalty_points');
    eq(null, $SS::auto(), 'empreinte inchangée : pas de nouveau contrôle à chaque page');
    @unlink(STORAGE_PATH . '/cache/schema_sync'); // = fichiers mis à jour
    $r = $SS::auto();
    ok($r && in_array('customers.loyalty_points', $r['columns'], true), 'après une mise à jour : réparation automatique');
});
test('Photos servies par l\'application : adresse /media, types, chemins refusés', function () {
    $M = App\Core\Media::class;
    $f = UPLOADS_PATH . '/products/test-media.png';
    @mkdir(dirname($f), 0755, true);
    $im = imagecreatetruecolor(4, 4); imagepng($im, $f); imagedestroy($im);
    ok(str_contains((string) upload_url('products/test-media.png'), '/media/products/test-media.png?v='), 'adresse /media versionnée : ' . upload_url('products/test-media.png'));
    eq(realpath($f), $M::resolve('products/test-media.png'));
    foreach (['../.env', 'products/../../.env', '../../config/app.php', 'products/test-media.php', '/etc/passwd', 'products/inconnu.png', 'products/test media.png'] as $bad) {
        eq(null, $M::resolve($bad), "refusé : $bad");
    }
    @unlink($f);
});

test('Écran client : publications (menu, promotion) texte seul ou texte + photo, couleurs, contrôles', function () {
    actingAs('admin');
    $CD = S\CustomerDisplay::class;
    throws(fn() => $CD::saveText(null, ['title' => '', 'body' => '']), 'au moins un titre');
    throws(fn() => $CD::saveText(null, ['title' => 'x', 'body' => str_repeat("ligne\n", 20)]), '14 lignes');
    $id = $CD::saveText(null, ['title' => 'Menu du jour', 'body' => "# Plats\nRiz sauce arachide 1500 F\nPoulet braisé 3000 F", 'palette' => 'soleil', 'layout' => 'auto', 'duration_s' => '12']);
    $m = DB::one('SELECT * FROM display_media WHERE id = ?', [$id]);
    ok($m['kind'] === 'text' && $m['path'] === null && $CD::fileProblem($m) === null, 'publication sans photo');
    eq(['palette' => 'soleil', 'layout' => 'auto'], $CD::style($m));
    $pl = array_values(array_filter($CD::playlist(), fn($x) => $x['id'] === $id));
    ok($pl && $pl[0]['kind'] === 'text' && $pl[0]['url'] === null && str_contains($pl[0]['body'], 'Poulet braisé') && $pl[0]['seconds'] === 12, 'diffusée');
    // Couleurs personnalisées : seules les couleurs valides sont gardées
    $CD::saveText($id, ['title' => 'Menu du jour', 'body' => 'Jus de bissap 500 F', 'palette' => 'perso', 'bg' => '#112233', 'fg' => 'rouge', 'accent' => '#FFAA00', 'layout' => 'overlay', 'duration_s' => '10']);
    eq(['palette' => 'perso', 'layout' => 'overlay', 'bg' => '#112233', 'accent' => '#ffaa00'], $CD::style(DB::one('SELECT * FROM display_media WHERE id = ?', [$id])));
    // Texte + photo
    $png = tempnam(sys_get_temp_dir(), 'pub'); $im = imagecreatetruecolor(800, 600); imagejpeg($im, $png); imagedestroy($im);
    $CD::saveText($id, ['title' => 'Promo', 'body' => '- 20 % sur le riz', 'palette' => 'nuit', 'duration_s' => '10'], ['name' => 'p.jpg', 'tmp_name' => $png, 'size' => filesize($png), 'error' => UPLOAD_ERR_OK]);
    $m = DB::one('SELECT * FROM display_media WHERE id = ?', [$id]);
    ok($m['path'] && is_file(UPLOADS_PATH . '/' . $m['path']), 'photo jointe');
    $file = UPLOADS_PATH . '/' . $m['path'];
    $CD::saveText($id, ['title' => 'Promo', 'body' => '- 20 % sur le riz', 'palette' => 'nuit', 'duration_s' => '10', 'remove_photo' => '1']);
    ok(!is_file($file) && DB::value('SELECT path FROM display_media WHERE id = ?', [$id]) === null, 'photo retirée et supprimée');
    $CD::deleteMedia($id);
    eq(null, DB::value('SELECT id FROM display_media WHERE id = ?', [$id]));
});
test('Écrans : accès direct par mot de passe, cantonnement, écran mémorisé, mot de passe changé, révocation', function () {
    $SC = S\Screens::class;
    $cat = $SC::catalog();
    ok(isset($cat['client'], $cat['cuisine'], $cat['pressing'], $cat['hotel'], $cat['clinique']), 'tous les écrans');
    App\Core\Session::destroy();
    $_SESSION = [];
    throws(fn() => $SC::login('client', 'caissier', 'mauvais', false), 'incorrect');
    $role = DB::one("SELECT * FROM roles WHERE code = 'cook'");
    if (!DB::value("SELECT id FROM users WHERE username = 'ecran_cuisine'")) {
        DB::insert('users', ['username' => 'ecran_cuisine', 'full_name' => 'Cuisine', 'role_id' => $role['id'], 'password_hash' => App\Core\Auth::hash('Cuisine-Ecran-2026'), 'password_changed_at' => date('Y-m-d H:i:s')]);
    }
    throws(fn() => $SC::login('client', 'ecran_cuisine', 'Cuisine-Ecran-2026', false), 'pas accès à cet écran');
    eq(null, App\Core\Session::get('uid'), 'compte refusé : aucune session ouverte');
    $SC::login('client', 'caissier', 'Mot-de-passe-2026', false);
    eq('client', App\Core\Session::get('screen_scope'));
    eq(null, $SC::guard('/afficheur'));
    eq(null, $SC::guard('/afficheur/playlist'));
    eq(null, $SC::guard('/media/display/x.jpg'));
    eq('/afficheur', $SC::guard('/pos'), 'caisse refusée');
    eq('/afficheur', $SC::guard('/settings'), 'paramètres refusés');
    eq('/afficheur', $SC::guard('/afficheurs-pirate'), 'préfixe exact');
    App\Core\Session::destroy();
    $_SESSION = [];
    eq(null, $SC::guard('/pos'), 'session normale non cantonnée');
});

echo "\n== HTTP & sécurité (serveur PHP intégré) ==\n";
$port = (int) (getenv('TEST_PORT') ?: 8099);
if ($busy = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5)) {
    fclose($busy);
    fwrite(STDERR, "Le port $port est déjà utilisé (autre serveur PHP ?) : arrêtez-le ou définissez TEST_PORT.\n");
    exit(1);
}
$cmd = sprintf((PHP_OS_FAMILY === 'Windows' ? '' : 'exec ') . 'php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg(PUBLIC_PATH), escapeshellarg(__DIR__ . '/server-router.php'));
// Plusieurs processus PHP : requêtes réellement simultanées (test des caisses concurrentes)
$proc = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, BASE_PATH, array_merge(getenv(), $env, ['PHP_CLI_SERVER_WORKERS' => '6']));
usleep(700000);
$B = "http://127.0.0.1:$port";

final class Http
{
    public string $jar;
    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'pwjar');
    }
    public function req(string $method, string $url, array|string|null $data = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
    }
    public function token(string $url): string
    {
        $r = $this->req('GET', $url);
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        return $m[1] ?? '';
    }
    public function login(string $base, string $user, string $pwd): array
    {
        $t = $this->token($base . '/login');
        return $this->req('POST', $base . '/login', http_build_query(['_csrf' => $t, 'username' => $user, 'password' => $pwd]));
    }
}

test('En-têtes de sécurité HTTP présents', function () use ($B) {
    $r = (new Http())->req('GET', "$B/login");
    eq(200, $r['code']);
    foreach (['Content-Security-Policy', 'X-Content-Type-Options: nosniff', 'X-Frame-Options', 'Referrer-Policy'] as $h) {
        ok(stripos($r['headers'], $h) !== false, "en-tête $h");
    }
    ok(stripos($r['headers'], 'HttpOnly') !== false && stripos($r['headers'], 'SameSite=Strict') !== false, 'cookie de session HttpOnly + SameSite');
    ok(stripos($r['headers'], 'X-Powered-By') === false, 'version PHP masquée');
    ok(str_contains($r['body'], '<h1>POS Marwa</h1>') && !str_contains($r['body'], 'POS WAN'), 'page de connexion au nom de POS Marwa');
    ok((bool) preg_match('/Permissions-Policy:[^\n]*camera=\(self\)/i', $r['headers']), 'caméra autorisée pour le site (scan des codes-barres), refusée aux autres origines');
});
test('Pages protégées : redirection vers la connexion / 401 en JSON', function () use ($B) {
    $h = new Http();
    $r = $h->req('GET', "$B/products");
    eq(302, $r['code']);
    ok(str_contains($r['headers'], '/login'));
    $r = $h->req('GET', "$B/pos/products", null, ['Accept: application/json']);
    eq(401, $r['code']);
});
test('CSRF : requête POST sans jeton refusée (419)', function () use ($B) {
    $h = new Http();
    $r = $h->req('POST', "$B/login", http_build_query(['username' => 'admin', 'password' => 'x']));
    eq(419, $r['code']);
    $h->login($B, 'admin', 'Essai-Niamey-2026');
    $r = $h->req('POST', "$B/users", http_build_query(['username' => 'pirate', 'full_name' => 'P', 'role_id' => 1, 'password' => 'Pirate-2026-xx']));
    eq(419, $r['code']);
    eq(null, DB::value("SELECT id FROM users WHERE username = 'pirate'"));
    $r = $h->req('POST', "$B/pos/checkout", '{}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: faux']);
    eq(419, $r['code']);
});
test('Connexion : échec générique, succès, régénération de session', function () use ($B) {
    $h = new Http();
    $h->req('GET', "$B/login");
    $h->req('GET', "$B/login");
    preg_match('/POSWANSESSID\s+(\S+)/', file_get_contents($h->jar) ?: '', $m0);
    $r = $h->login($B, 'admin', 'mauvais');
    eq(302, $r['code']);
    $page = $h->req('GET', "$B/login");
    ok(str_contains($page['body'], 'Identifiants incorrects'), 'message générique');
    $r = $h->login($B, 'admin', 'Essai-Niamey-2026');
    ok((bool) preg_match('#Location: /\r?\n#', $r['headers']), 'redirigé vers l\'accueil');
    preg_match_all('/POSWANSESSID\s+(\S+)/', file_get_contents($h->jar) ?: '', $m1);
    ok(($m0[1] ?? '') !== end($m1[1]), 'identifiant de session régénéré');
    eq(200, $h->req('GET', "$B/dashboard")['code']);
});
test('Verrouillage du compte après 5 échecs', function () use ($B) {
    $h = new Http();
    for ($i = 0; $i < 5; $i++) {
        $h->login($B, 'auditeur', 'faux-' . $i);
    }
    ok(DB::value("SELECT locked_until FROM users WHERE username = 'auditeur'") !== null, 'verrouillé');
    $h->login($B, 'auditeur', 'Mot-de-passe-2026');
    eq(302, $h->req('GET', "$B/dashboard")['code'], 'bon mot de passe refusé pendant le verrouillage');
    DB::run("UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE username = 'auditeur'");
    DB::run("DELETE FROM login_attempts");
});
test('Connexion par PIN', function () use ($B) {
    $h = new Http();
    $t = $h->token("$B/login");
    $h->req('POST', "$B/login/pin", http_build_query(['_csrf' => $t, 'username' => 'caissier', 'pin' => '4826']));
    eq(200, $h->req('GET', "$B/pos")['code']);
});
test('Contrôle d\'accès serveur : un caissier ne peut pas administrer', function () use ($B) {
    $h = new Http();
    $h->login($B, 'caissier', 'Mot-de-passe-2026');
    foreach (['/users', '/products/create', '/settings', '/backups', '/reports/profit', '/audit', '/roles'] as $u) {
        eq(403, $h->req('GET', $B . $u)['code'], $u);
    }
    $t = $h->token("$B/sales");
    $sale = (int) DB::value("SELECT id FROM sales WHERE status = 'completed' ORDER BY id DESC LIMIT 1");
    eq(403, $h->req('POST', "$B/sales/$sale/cancel", http_build_query(['_csrf' => $t, 'reason' => 'x']))['code'], 'annulation');
    eq('completed', DB::value('SELECT status FROM sales WHERE id = ?', [$sale]));
});
test('Un vendeur ne voit pas les ventes des autres (IDOR)', function () use ($B) {
    $h = new Http();
    $h->login($B, 'caissier', 'Mot-de-passe-2026');
    $other = (int) DB::value("SELECT s.id FROM sales s JOIN users u ON u.id = s.user_id WHERE u.username = 'gerant' LIMIT 1");
    eq(403, $h->req('GET', "$B/sales/$other")['code']);
});
test('Injection SQL : paramètres malveillants sans effet', function () use ($B) {
    $h = new Http();
    $h->login($B, 'admin', 'Essai-Niamey-2026');
    $n = (int) DB::value('SELECT COUNT(*) FROM products');
    foreach (["' OR 1=1 -- ", "1; DROP TABLE products; --", "\" UNION SELECT password_hash FROM users -- "] as $p) {
        $r = $h->req('GET', "$B/products?q=" . urlencode($p) . '&category=' . urlencode($p) . '&sort=' . urlencode($p));
        eq(200, $r['code']);
        ok(!str_contains($r['body'], '$2y$'), 'aucune fuite de hash');
        $r = $h->req('GET', "$B/pos/products?q=" . urlencode($p), null, ['Accept: application/json']);
        eq(200, $r['code']);
        eq([], json_decode($r['body'], true)['items'] ?? null);
    }
    eq($n, (int) DB::value('SELECT COUNT(*) FROM products'), 'table intacte');
});
test('XSS : contenu saisi échappé à l\'affichage', function () use ($B) {
    DB::insert('products', ['reference' => 'XSS1', 'name' => '<script>alert(1)</script>', 'sale_price' => 1]);
    $h = new Http();
    $h->login($B, 'admin', 'Essai-Niamey-2026');
    $r = $h->req('GET', "$B/products?q=XSS1");
    ok(!str_contains($r['body'], '<script>alert(1)</script>') && str_contains($r['body'], '&lt;script&gt;'));
    DB::run("DELETE FROM products WHERE reference = 'XSS1'");
});
test('Fichiers sensibles inaccessibles depuis le web', function () use ($B) {
    $h = new Http();
    foreach (['/.env', '/../.env', '/app/bootstrap.php', '/config/database.php', '/storage/logs/', '/database/schema.sql', '/index.php/../.env'] as $u) {
        $r = $h->req('GET', $B . $u);
        ok(!str_contains($r['body'], 'DB_PASSWORD') && !str_contains($r['body'], 'CREATE TABLE') && !str_contains($r['body'], 'spl_autoload'), "fuite via $u");
    }
    ok(!is_file(PUBLIC_PATH . '/.env') && !glob(PUBLIC_PATH . '/*.sql'), 'aucun secret dans public/');
    ok(str_contains((string) file_get_contents(PUBLIC_PATH . '/uploads/.htaccess'), 'engine off'), 'exécution PHP désactivée dans uploads');
});
test('Téléversement : script déguisé en image refusé, image valide ré-encodée', function () use ($B) {
    $h = new Http();
    $h->login($B, 'admin', 'Essai-Niamey-2026');
    $evil = tempnam(sys_get_temp_dir(), 'ev');
    file_put_contents($evil, "<?php system(\$_GET['c']); ?>");
    $t = $h->token("$B/products/create");
    $h->req('POST', "$B/products", ['_csrf' => $t, 'name' => 'Upload test', 'sale_price' => '100', 'image' => new CURLFile($evil, 'image/png', 'photo.php.png')]);
    eq(null, DB::value("SELECT id FROM products WHERE name = 'Upload test'"), 'article non créé');
    ok(!glob(PUBLIC_PATH . '/uploads/products/*.php*'), 'aucun script déposé');
    $img = tempnam(sys_get_temp_dir(), 'im') . '.png';
    $gd = imagecreatetruecolor(20, 20);
    imagepng($gd, $img);
    $t = $h->token("$B/products/create");
    $h->req('POST', "$B/products", ['_csrf' => $t, 'name' => 'Upload ok', 'sale_price' => '100', 'image' => new CURLFile($img, 'image/png', 'photo.png')]);
    $path = DB::value("SELECT image FROM products WHERE name = 'Upload ok'");
    ok($path && preg_match('#^products/[0-9a-f]{32}\.png$#', $path), 'nom aléatoire');
    @unlink(PUBLIC_PATH . '/uploads/' . $path);
});
test('Vente complète via l\'API de caisse (JSON + CSRF)', function () use ($B) {
    $h = new Http();
    $h->login($B, 'gerant', 'Mot-de-passe-2026');
    $page = $h->req('GET', "$B/pos");
    preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
    $pid = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Dentifrice%'");
    $body = json_encode(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2000]]]);
    $r = $h->req('POST', "$B/pos/checkout", $body, ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    $d = json_decode($r['body'], true);
    eq(200, $r['code'], $r['body']);
    ok($d['ok'] === true && $d['total'] == 1800 && $d['change'] == 200, $r['body']);
    $t = $h->req('GET', $B . $d['ticket_url']);
    eq(200, $t['code']);
    ok(str_contains($t['body'], $d['number']), 'ticket');
    $t2 = (new Http());
    $t2->login($B, 'caissier', 'Mot-de-passe-2026');
});
test('Réimpression : DUPLICATA et permission requise', function () use ($B) {
    $h = new Http();
    $h->login($B, 'gerant', 'Mot-de-passe-2026');
    $sid = (int) DB::value("SELECT s.id FROM sales s JOIN users u ON u.id = s.user_id WHERE u.username = 'gerant' AND s.print_count > 0 ORDER BY s.id DESC LIMIT 1");
    $r = $h->req('GET', "$B/sales/$sid/ticket");
    ok(str_contains($r['body'], 'DUPLICATA'));
    $own = (int) DB::value("SELECT s.id FROM sales s JOIN users u ON u.id = s.user_id WHERE u.username = 'caissier' ORDER BY s.id DESC LIMIT 1");
    DB::run('UPDATE sales SET print_count = 1 WHERE id = ?', [$own]);
    DB::run("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.code = 'cashier' AND p.code = 'pos.reprint'");
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/sales/$own/ticket")['code']);
});
test('Toutes les pages principales répondent sans erreur (admin)', function () use ($B) {
    $h = new Http();
    $h->login($B, 'admin', 'Essai-Niamey-2026');
    $sale = (int) DB::value('SELECT MAX(id) FROM sales');
    $pages = ['/dashboard', '/menu', '/pos', '/sales', "/sales/$sale", "/sales/$sale/invoice", '/returns', '/cash', '/cash/sessions', '/products', '/products/create', '/products/1', '/products/1/edit', '/products/import', '/products/labels', '/products/duplicates', '/categories', '/stock', '/stock/movements', '/stock/adjust?product=1', '/stock/transfers', '/stock/transfers/create', '/stock/expiry', '/stock/lots?q=L', '/inventories', '/suppliers', '/suppliers/1', '/purchases', '/purchases/create', '/receptions', '/receptions/create', '/supplier-returns', '/customers', '/customers/1', '/customers/1/statement', '/expenses', '/reports/sales', '/reports/products', '/reports/profit', '/reports/stock', '/reports/debts', '/reports/prescriptions', '/users', '/roles', '/settings', '/stores', '/audit', '/backups', '/account/password', '/printers', '/quotes', '/quotes/create', '/verify', '/reports/vat', '/addons', "/sales/$sale/delivery"];
    $bad = [];
    foreach ($pages as $p) {
        $r = $h->req('GET', $B . $p);
        if ($r['code'] !== 200 || preg_match('/Fatal error|Warning:|Notice:|Une erreur interne/', $r['body'])) {
            $bad[] = "$p ({$r['code']})";
        }
    }
    eq([], $bad);
});
test('Restaurant (HTTP) : serveur, cuisinier et écrans', function () use ($B, &$R) {
    $w = new Http();
    $w->login($B, 'serveur', 'Mot-de-passe-2026');
    eq(200, $w->req('GET', "$B/restaurant")['code'], 'plan de salle');
    eq(200, $w->req('GET', "$B/restaurant/orders/{$R['o1b']}")['code'], 'prise de commande');
    eq(403, $w->req('GET', "$B/pos")['code'], 'pas de caisse pour un serveur');
    eq(403, $w->req('GET', "$B/restaurant/orders/{$R['o1b']}/pay")['code'], 'pas d\'encaissement pour un serveur');
    eq(403, $w->req('GET', "$B/kitchen")['code']);
    $page = $w->req('GET', "$B/restaurant");
    preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
    $r = $w->req('POST', "$B/restaurant/orders/{$R['o1b']}/items", json_encode(['product_id' => $R['plat'], 'quantity' => 1]), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    $d = json_decode($r['body'], true);
    ok($d['ok'] ?? false, $r['body']);
    $line = (int) DB::value("SELECT id FROM restaurant_order_items WHERE order_id = ? AND status = 'new' ORDER BY id DESC LIMIT 1", [$R['o1b']]);
    $r = $w->req('POST', "$B/restaurant/items/$line", json_encode(['quantity' => 2, 'note' => 'Sans piment']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    ok((json_decode($r['body'], true)['ok'] ?? false), 'modification de ligne : ' . $r['body']);
    eq('Sans piment', DB::value('SELECT note FROM restaurant_order_items WHERE id = ?', [$line]));
    $r = $w->req('POST', "$B/restaurant/orders/{$R['o1b']}/send", '{}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    $d = json_decode($r['body'], true);
    ok(($d['ok'] ?? false) && $d['print'] === [] && $d['sent'] === 1, 'envoi sans impression automatique : ' . $r['body']);
    $r = $w->req('POST', "$B/restaurant/orders/{$R['o1b']}/print-kitchen", '{}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    $d = json_decode($r['body'], true);
    ok(($d['ok'] ?? false) && count($d['print']) === 1, 'bon cuisine à la demande : ' . $r['body']);
    eq(200, $w->req('GET', $B . $d['print'][0])['code'], 'bon cuisine imprimable');
    $c = new Http();
    $c->login($B, 'cuisto', 'Mot-de-passe-2026');
    eq(200, $c->req('GET', "$B/kitchen")['code']);
    $feed = json_decode($c->req('GET', "$B/kitchen/feed?station=cuisine", null, ['Accept: application/json'])['body'], true);
    ok(count($feed['tickets']) >= 1, 'ticket visible en cuisine');
    $kp = $c->req('GET', "$B/kitchen");
    preg_match('/name="csrf-token" content="([^"]+)"/', $kp['body'], $km);
    $r = $c->req('POST', "$B/kitchen/tickets/{$feed['tickets'][0]['id']}/print", '{}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $km[1]]);
    ok((json_decode($r['body'], true)['ok'] ?? false), 'impression depuis l\'écran cuisine : ' . $r['body']);
    eq(403, $c->req('GET', "$B/restaurant")['code']);
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    foreach (['/restaurant/orders', '/restaurant/setup', '/restaurant/recipes', "/restaurant/recipes/{$R['plat']}", '/restaurant/report', "/restaurant/orders/{$R['o1b']}/pay", "/restaurant/orders/{$R['o1b']}/bill", "/restaurant/orders/{$R['o1']}"] as $u) {
        $x = $a->req('GET', $B . $u);
        ok($x['code'] === 200 && !preg_match('/Fatal error|Warning:|Une erreur interne/', $x['body']), "$u ({$x['code']})");
    }
});
test('v1.2 (HTTP) : imprimantes, test d\'impression, ticket serveur à l\'encaissement', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/printers");
    $r = $a->req('POST', "$B/printers", http_build_query(['_csrf' => $t, 'name' => 'Externe', 'driver' => 'network', 'address' => '8.8.8.8', 'usages' => ['receipt'], 'paper' => '80']));
    eq(0, (int) DB::value("SELECT COUNT(*) FROM printers WHERE name = 'Externe'"), 'adresse publique refusée');
    [$srv, $port] = printListener();
    $a->req('POST', "$B/printers", http_build_query(['_csrf' => $t, 'name' => 'Caisse 1 XP-80', 'driver' => 'network', 'address' => "127.0.0.1:$port", 'usages' => ['receipt'], 'paper' => '80', 'cut' => '1', 'copies' => '1']));
    $pid = (int) DB::value("SELECT id FROM printers WHERE name = 'Caisse 1 XP-80'");
    ok($pid > 0, 'imprimante créée');
    $a->req('POST', "$B/printers/$pid/test", http_build_query(['_csrf' => $t]));
    ok(str_contains(printReceived($srv), 'TEST D\'IMPRESSION'), 'page de test reçue');
    // Encaissement depuis la caisse avec impression serveur
    $g = new Http();
    $g->login($B, 'gerant', 'Mot-de-passe-2026');
    $page = $g->req('GET', "$B/pos");
    ok(str_contains($page['body'], '&quot;serverPrinter&quot;:true') || str_contains($page['body'], '"serverPrinter":true'), 'caisse informée de l\'imprimante');
    preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
    $pid2 = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Dentifrice%'");
    $body = json_encode(['client_uuid' => uuid(), 'items' => [['product_id' => $pid2, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]], 'print_ticket' => true]);
    $r = $g->req('POST', "$B/pos/checkout", $body, ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    $d = json_decode($r['body'], true);
    ok(($d['ok'] ?? false) && $d['printed_server'] === 1, $r['body']);
    ok(str_contains(printReceived($srv), $d['number']), 'ticket reçu par l\'imprimante');
    fclose($srv);
    DB::update('printers', ['active' => 0], 'id = ?', [$pid]);
});
test('v1.2 (HTTP) : modèles, bon de livraison, vérification du QR code', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $sale = DB::one('SELECT * FROM sales ORDER BY id DESC LIMIT 1');
    foreach (['1', '2', '3'] as $n) {
        setting_set('ticket_template', $n);
        setting_set('invoice_template', $n);
        $tk = $a->req('GET', "$B/sales/{$sale['id']}/ticket");
        ok($tk['code'] === 200 && str_contains($tk['body'], 'data-qr="POSWAN|V|'), "ticket modèle $n");
        $iv = $a->req('GET', "$B/sales/{$sale['id']}/invoice");
        ok($iv['code'] === 200 && str_contains($iv['body'], 'tpl' . $n) && str_contains($iv['body'], 'data-qr='), "facture modèle $n");
    }
    setting_set('ticket_template', '1');
    setting_set('invoice_template', '1');
    $bl = $a->req('GET', "$B/sales/{$sale['id']}/delivery");
    ok($bl['code'] === 200 && str_contains($bl['body'], 'BON DE LIVRAISON') && str_contains($bl['body'], 'BL-' . $sale['number']), 'bon de livraison');
    ok(!str_contains($bl['body'], 'TOTAL TTC'), 'sans prix par défaut');
    ok(str_contains($a->req('GET', "$B/sales/{$sale['id']}/delivery?prices=1")['body'], 'TOTAL TTC'), 'avec prix');
    $code = S\DocumentRef::saleQr($sale);
    ok(str_contains($a->req('GET', "$B/verify?code=" . rawurlencode($code))['body'], 'Document authentique'), 'QR authentique');
    ok(str_contains($a->req('GET', "$B/verify?code=" . rawurlencode(substr($code, 0, -1) . 'x'))['body'], 'Signature invalide'), 'QR falsifié');
    foreach (['ticket', 'invoice'] as $type) {
        $p = $a->req('GET', "$B/settings/template-preview?type=$type");
        ok($p['code'] === 200 && substr_count($p['body'], 'preview-item') === 3, "aperçu $type");
    }
    setting_set('receipt_qr', '0');
    ok(!str_contains($a->req('GET', "$B/sales/{$sale['id']}/invoice")['body'], 'data-qr='), 'QR désactivable');
    setting_set('receipt_qr', '1');
});
test('v1.2 (HTTP) : devis, code-barres généré, langue de l\'interface', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/quotes/create");
    $pid = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Dentifrice%'");
    $a->req('POST', "$B/quotes", http_build_query(['_csrf' => $t, 'customer_name' => 'Société Test HTTP', 'items' => [['product_id' => $pid, 'quantity' => '3', 'unit_price' => '900', 'discount' => '0']]]));
    $q = (int) DB::value("SELECT id FROM quotes WHERE customer_name = 'Société Test HTTP'");
    ok($q > 0, 'devis créé');
    foreach (['/quotes', "/quotes/$q", "/quotes/$q/edit", "/quotes/$q/convert"] as $u) {
        eq(200, $a->req('GET', $B . $u)['code'], $u);
    }
    ok(str_contains($a->req('GET', "$B/quotes/$q/print")['body'], 'data-qr="POSWAN|D|'), 'devis imprimable avec QR');
    $j = json_decode($a->req('GET', "$B/products/new-barcode", null, ['Accept: application/json'])['body'], true);
    ok(preg_match('/^21\d{11}$/', $j['barcode'] ?? '') === 1, 'code-barres proposé');
    $np = DB::insert('products', ['reference' => 'SANSCB1', 'name' => 'Article sans code', 'sale_price' => 100, 'cost_price' => 50]);
    $a->req('POST', "$B/products/$np/barcode", http_build_query(['_csrf' => $t]));
    ok(preg_match('/^21\d{11}$/', (string) DB::value('SELECT barcode FROM products WHERE id = ?', [$np])) === 1, 'code-barres attribué');
    $c = new Http();
    $c->login($B, 'auditeur', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/quotes")['code'], 'auditeur sans devis');
    // Langue : anglais puis arabe (RTL)
    $l = (new Http())->req('GET', "$B/login?lang=en");
    ok(str_contains($l['body'], 'lang="en"'), 'page de connexion en anglais');
    $tp = $a->token("$B/account/password");
    $a->req('POST', "$B/account/locale", http_build_query(['_csrf' => $tp, 'locale' => 'ar']));
    $d = $a->req('GET', "$B/dashboard");
    ok(str_contains($d['body'], 'dir="rtl"') && str_contains($d['body'], 'bootstrap.rtl.min.css'), 'arabe de droite à gauche');
    $a->req('POST', "$B/account/locale", http_build_query(['_csrf' => $tp, 'locale' => 'fr']));
    ok(str_contains($a->req('GET', "$B/dashboard")['body'], 'lang="fr"'), 'retour au français');
});
test('v1.3 (HTTP) : paramètres TVA, rapport TVA, exports PDF, bon cuisine réimprimable', function () use ($B, &$R) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/settings");
    $a->req('POST', "$B/settings/vat", http_build_query(['_csrf' => $t, 'vat_mode' => 'general', 'vat_rate' => '18', 'vat_label' => 'TVA']));
    eq('general', setting('vat_mode'));
    eq('18', setting('vat_rate'));
    $a->req('POST', "$B/settings/vat", http_build_query(['_csrf' => $t, 'vat_mode' => 'general', 'vat_rate' => '150']));
    eq('18', setting('vat_rate'), 'taux invalide refusé');
    $page = $a->req('GET', "$B/reports/vat");
    ok($page['code'] === 200 && str_contains($page['body'], 'Par taux'), 'rapport TVA');
    foreach (['/reports/vat?export=pdf', '/reports/vat?export=pdf&by=month', '/reports/sales?export=pdf', '/reports/products?export=pdf', '/reports/debts?export=pdf', '/sales?export=pdf', '/stock?export=pdf', '/expenses?export=pdf'] as $u) {
        $r = $a->req('GET', $B . $u);
        ok($r['code'] === 200 && str_starts_with($r['body'], '%PDF') && str_contains($r['headers'], 'application/pdf'), "PDF $u ({$r['code']})");
    }
    $csv = $a->req('GET', "$B/reports/vat?export=csv");
    ok(str_contains($csv['body'], 'Base HT nette'), 'CSV TVA');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/reports/vat")['code'], 'rapport TVA réservé');
    setting_set('vat_mode', 'product');
    // Bon cuisine : réimpression d'une commande dont les bons sont déjà servis
    $w = new Http();
    $w->login($B, 'serveur', 'Mot-de-passe-2026');
    $pg = $w->req('GET', "$B/restaurant");
    preg_match('/name="csrf-token" content="([^"]+)"/', $pg['body'], $m);
    $oid = (int) DB::value("SELECT order_id FROM kitchen_tickets WHERE type = 'order' AND status = 'served' ORDER BY id DESC LIMIT 1");
    $r = $w->req('POST', "$B/restaurant/orders/$oid/print-kitchen", '{}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    $d = json_decode($r['body'], true);
    ok(($d['ok'] ?? false) && count($d['print']) >= 1, 'réimpression du bon : ' . $r['body']);
});
test('v1.4 (HTTP) : page Modules, module actif / désactivé, connexion, menu', function () use ($B) {
    $login = (new Http())->req('GET', "$B/login");
    ok(str_contains($login['body'], 'href="tel:+22792081282"') && str_contains($login['body'], '87 22 52 02'), 'assistance sur la connexion');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $page = $a->req('GET', "$B/addons");
    ok($page['code'] === 200 && str_contains($page['body'], 'Livraisons'), 'page Modules');
    ok(str_contains($a->req('GET', "$B/dashboard")['body'], 'menu-right'), 'menu à droite');
    $g = new Http();
    $g->login($B, 'gerant', 'Mot-de-passe-2026');
    eq(403, $g->req('GET', "$B/addons")['code'], 'installation réservée à l\'administrateur');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    $l = $c->req('GET', "$B/livraisons");
    ok($l['code'] === 200 && str_contains($l['body'], 'Nouvelle livraison'), 'page du module');
    ok(str_contains($c->req('GET', "$B/sales")['body'], 'href="/livraisons"'), 'lien dans le menu');
    $t = $c->token("$B/livraisons");
    $c->req('POST', "$B/livraisons", http_build_query(['_csrf' => $t, 'customer_name' => 'Client HTTP', 'phone' => '96000000', 'address' => 'Yantala', 'fee' => '500']));
    eq(1, (int) DB::value("SELECT COUNT(*) FROM livraisons WHERE customer_name = 'Client HTTP'"));
    $ta = $a->token("$B/addons");
    $a->req('POST', "$B/addons", http_build_query(['_csrf' => $ta, 'code' => 'livraisons', 'do' => 'disable']));
    eq(404, $c->req('GET', "$B/livraisons")['code'], 'module désactivé : page inaccessible');
    ok(!str_contains($c->req('GET', "$B/sales")['body'], 'href="/livraisons"'), 'menu masqué');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM livraisons WHERE customer_name = 'Client HTTP'"), 'données conservées');
    $a->req('POST', "$B/addons", http_build_query(['_csrf' => $ta, 'code' => 'livraisons', 'do' => 'enable']));
    eq(200, $c->req('GET', "$B/livraisons")['code'], 'réactivé');
    $a->req('POST', "$B/addons", http_build_query(['_csrf' => $ta, 'code' => '../../app', 'do' => 'install']));
    eq(null, DB::value("SELECT code FROM addons WHERE code LIKE '%app%'"), 'code de module malveillant refusé');
});
test('MARWA IA (HTTP) : questions, PDF affiché, PDF et Excel téléchargés, droits, réglages', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $pg = $a->req('GET', "$B/ia");
    ok($pg['code'] === 200 && str_contains($pg['body'], 'data-mia-full') && str_contains($pg['body'], 'data-no-lock'), 'page de l\'assistant');
    $t = $a->token("$B/ia");
    $ask = fn(Http $c, string $tok, string $q) => json_decode($c->req('POST', "$B/ia/ask", json_encode(['q' => $q]), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $tok])['body'], true);
    $r = $ask($a, $t, 'rapport du client Moussa Garba');
    ok(($r['type'] ?? '') === 'report' && str_contains($r['title'] ?? '', 'Moussa Garba'), 'question → relevé du client');
    $r2 = $ask($a, $t, 'et en pdf');
    ok(str_contains((string) ($r2['open'] ?? ''), 'export=pdf') && str_contains($r2['open'], 'view=1'), 'suite « et en PDF ? » gardée en session');
    $pdf = $a->req('GET', $B . $r2['open']);
    ok(str_starts_with($pdf['body'], '%PDF') && str_contains($pdf['headers'], 'inline'), 'PDF affiché dans le navigateur');
    $dl = $a->req('GET', "$B/ia/rapport?k=ventes&from=2000-01-01&to=" . date('Y-m-d') . '&export=pdf');
    ok(str_starts_with($dl['body'], '%PDF') && str_contains($dl['headers'], 'attachment'), 'PDF de toutes les transactions téléchargé');
    $csv = $a->req('GET', "$B/ia/rapport?k=client&id=" . (int) $GLOBALS['IA_CLIENT'] . '&export=csv');
    ok(str_contains($csv['body'], 'Opération') && str_contains($csv['headers'], 'text/csv'), 'Excel (CSV)');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM ia_history WHERE question = 'et en pdf'"), 'historique des questions');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    $tc = $c->token("$B/ia");
    $rc = $ask($c, $tc, 'bénéfice du mois en pdf');
    ok(($rc['type'] ?? '') === 'error', 'caissier : rapport financier refusé');
    $f = $c->req('GET', "$B/ia/rapport?k=benefice&export=pdf");
    ok(!str_starts_with($f['body'], '%PDF'), 'caissier : PDF financier refusé même par l\'adresse');
    $mk = $c->req('GET', "$B/ia/marketing?export=pdf&view=1");
    ok(str_starts_with($mk['body'], '%PDF') && str_contains($mk['headers'], 'inline'), 'plan marketing PDF (caissier, sans chiffres financiers)');
    ok(str_contains($a->req('GET', "$B/ia/marketing?export=pdf")['headers'], 'attachment'), 'plan marketing téléchargé');
    eq(403, $c->req('GET', "$B/ia/reglages")['code'], 'réglages réservés');
    $tr = $a->token("$B/ia/reglages");
    $a->req('POST', "$B/ia/reglages", http_build_query(['_csrf' => $tr, 'llm_url' => 'https://serveur.example.com', 'llm_model' => 'x', 'bubble' => '1']));
    eq('', (string) setting('ia_llm_url', ''), 'modèle sur Internet refusé');
});
test('Écran client (HTTP) : paramètres, caisse, écran du second moniteur, droits', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $pg = $a->req('GET', "$B/settings/display");
    ok($pg['code'] === 200 && str_contains($pg['body'], 'Images et vidéos') && str_contains($pg['body'], 'data-cd-open'), 'page des paramètres');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/settings/display")['code'], 'paramètres réservés');
    $pos = $c->req('GET', "$B/pos");
    ok(str_contains($pos['body'], 'data-cd-open') && str_contains($pos['body'], 'customer-display-link.js'), 'caisse : bouton de l\'écran client');
    $scr = $c->req('GET', "$B/afficheur");
    ok($scr['code'] === 200 && str_contains($scr['body'], 'Bienvenue chez Test') && str_contains($scr['body'], 'theme-light') && str_contains($scr['body'], '/media/display/'), 'écran client : accueil, thème, images');
    ok(str_contains($scr['body'], 'id="cdProgress"') && str_contains($scr['body'], 'id="cdDots"'), 'écran client : progression et repères');
    preg_match('#/media/(display/[a-f0-9]+\.\w+)\?v=\d+#', $scr['body'], $mm);
    ok(!empty($mm[1]), 'écran client : images servies par l\'application');
    $img = $c->req('GET', "$B/media/" . $mm[1]);
    ok($img['code'] === 200 && preg_match('#Content-Type: image/#i', $img['headers']) && stripos($img['headers'], 'Set-Cookie') === false, 'image délivrée (200, type image, sans cookie)');
    $rg = $c->req('GET', "$B/media/" . $mm[1], null, ['Range: bytes=0-9']);
    ok($rg['code'] === 206 && strlen($rg['body']) === 10, 'lecture partielle (vidéos)');
    eq(404, (new Http())->req('GET', "$B/media/../.env")['code'], 'fichier hors des téléversements refusé');
    // Accès direct à l'écran client depuis un téléviseur (aucune session au départ)
    $tv = new Http();
    $f = $tv->req('GET', "$B/ecran/client");
    ok($f['code'] === 200 && str_contains($f['body'], 'Mémoriser cet écran'), 'formulaire de l\'écran');
    $lg = $tv->req('POST', "$B/ecran/client", http_build_query(['_csrf' => $tv->token("$B/ecran/client"), 'username' => 'caissier', 'password' => 'Mot-de-passe-2026', 'remember' => '1']));
    ok($lg['code'] === 302 && str_contains($lg['headers'], '/afficheur') && preg_match('/Set-Cookie: PMSCREEN=(\d+\.[a-f0-9]{64})/i', $lg['headers'], $ck), 'connecté et mémorisé');
    eq(200, $tv->req('GET', "$B/afficheur")['code'], 'écran affiché');
    $pos = $tv->req('GET', "$B/pos");
    ok($pos['code'] === 302 && str_contains($pos['headers'], '/afficheur'), 'caisse inaccessible depuis l\'écran');
    eq(403, $tv->req('GET', "$B/products", null, ['Accept: application/json'])['code'], 'données de gestion refusées (JSON)');
    // Écran redémarré : seul le cookie d'appareil reste → reconnexion silencieuse
    $ch = curl_init("$B/afficheur");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => 'PMSCREEN=' . $ck[1]]);
    $raw = (string) curl_exec($ch);
    eq(200, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'reconnexion automatique de l\'écran mémorisé');
    curl_close($ch);
    $devId = (int) explode('.', $ck[1])[0];
    ok((int) DB::value('SELECT user_id FROM screen_devices WHERE id = ? AND revoked_at IS NULL', [$devId]) > 0, 'appareil enregistré');
    $sc = $a->req('GET', "$B/screens");
    ok($sc['code'] === 200 && str_contains($sc['body'], '/ecran/client') && str_contains($sc['body'], 'Déconnecter'), 'page des écrans');
    $a->req('POST', "$B/screens/$devId/revoke", http_build_query(['_csrf' => $a->token("$B/screens")]));
    $ch = curl_init("$B/afficheur");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIE => 'PMSCREEN=' . $ck[1]]);
    curl_exec($ch);
    eq(302, (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'écran révoqué : mot de passe redemandé');
    curl_close($ch);
    // Cuisine : un caissier sans le droit de l'écran cuisine est refusé
    $k = new Http();
    $k->req('POST', "$B/ecran/cuisine", http_build_query(['_csrf' => $k->token("$B/ecran/cuisine"), 'username' => 'caissier', 'password' => 'Mot-de-passe-2026']));
    ok(str_contains($k->req('GET', "$B/ecran/cuisine")['body'], 'pas accès à cet écran'), 'écran cuisine refusé sans le droit');
    $db = $a->req('GET', "$B/system/database");
    ok($db['code'] === 200 && str_contains($db['body'], 'Base complète'), 'page Vérification de la base');
    $pl = json_decode($c->req('GET', "$B/afficheur/playlist", null, ['Accept: application/json'])['body'], true);
    ok(($pl['ok'] ?? false) && is_array($pl['playlist']) && isset($pl['cfg']['transition']), 'liste de diffusion relue par l\'écran (JSON)');
    ok(str_contains($pg['body'], 'data-display-upload') && str_contains($pg['body'], 'display-media-upload.js') && str_contains($pg['body'], 'name="display_transition"'), 'paramètres : préparation des images, choix de l\'effet');
    // Ajout de stock rapide et import (HTTP)
    $pid = (int) DB::value("SELECT id FROM products WHERE reference = 'CIM-TEST'");
    $q = $a->req('GET', "$B/stock/quick?product=$pid");
    ok($q['code'] === 200 && str_contains($q['body'], 'stock-quick.js') && str_contains($q['body'], 'Historique des ajouts rapides'), 'page d\'ajout rapide');
    $card = json_decode($a->req('GET', "$B/stock/quick/product/$pid", null, ['Accept: application/json'])['body'], true);
    ok(($card['ok'] ?? false) && $card['product']['reference'] === 'CIM-TEST' && isset($card['product']['levels'][0]['quantity']), 'fiche JSON');
    $t = $a->token("$B/stock/quick");
    $before = S\StockService::level($pid, 1);
    $r = json_decode($a->req('POST', "$B/stock/quick", json_encode(['product_id' => $pid, 'warehouse_id' => 1, 'quantity' => '4', 'reason' => 'Ajout HTTP']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $t])['body'], true);
    ok(($r['ok'] ?? false) && (float) $r['after'] === $before + 4 && $r['user'] !== '', 'ajout rapide JSON : ' . json_encode($r));
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/stock/quick")['code'], 'ajout rapide réservé (droit d\'ajuster le stock)');
    $tpl = $a->req('GET', "$B/stock/import/template?warehouse_id=1");
    ok($tpl['code'] === 200 && str_starts_with($tpl['body'], 'PK'), 'modèle Excel téléchargé');
    $f = tempnam(sys_get_temp_dir(), 'h') . '.xlsx';
    file_put_contents($f, App\Core\Xlsx::build('Stock', ['reference', 'quantite'], [['CIM-TEST', 6]]));
    $t = $a->token("$B/stock/import");
    $pv = $a->req('POST', "$B/stock/import/preview", ['_csrf' => $t, 'mode' => 'add', 'warehouse_id' => '1', 'file' => new CURLFile($f, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'arrivage.xlsx')]);
    ok($pv['code'] === 200 && str_contains($pv['body'], '1 ligne(s) à appliquer') && preg_match('/name="token" value="([a-f0-9]{32})"/', $pv['body'], $mm), 'aperçu de l\'import');
    eq($before + 4, S\StockService::level($pid, 1), 'aperçu : stock inchangé');
    $ap = $a->req('POST', "$B/stock/import/apply", http_build_query(['_csrf' => $a->token("$B/stock/import"), 'token' => $mm[1], 'note' => 'HTTP']));
    ok($ap['code'] === 302 && preg_match('#/stock/imports/(\d+)#', $ap['headers'], $im), 'import appliqué');
    eq($before + 10, S\StockService::level($pid, 1));
    $show = $a->req('GET', "$B/stock/imports/" . $im[1]);
    ok($show['code'] === 200 && str_contains($show['body'], 'arrivage.xlsx') && str_contains($show['body'], 'Appliquée'), 'détail de l\'import');
    ok(str_starts_with($a->req('GET', "$B/stock/imports/" . $im[1] . '/file')['body'], 'PK'), 'fichier d\'origine téléchargeable');
    $replay = $a->req('POST', "$B/stock/import/apply", http_build_query(['_csrf' => $a->token("$B/stock/import"), 'token' => $mm[1]]));
    eq($before + 10, S\StockService::level($pid, 1), 'aperçu déjà utilisé : pas de double import');
    $t = $c->token("$B/pos");
    $r = $c->req('POST', "$B/afficheur/vfd", http_build_query(['_csrf' => $t, 'line1' => 'A', 'line2' => 'B']), ['Accept: application/json']);
    ok($r['code'] === 422 && str_contains($r['body'], 'injoignable'), 'afficheur serveur absent : erreur claire, sans plantage');
});
test('Module Pressing (HTTP) : saisie, ticket QR, étiquettes, droits, tarifs, rapport PDF', function () use ($B, &$PR) {
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(200, $c->req('GET', "$B/pressing")['code'], 'tableau de bord');
    $t = $c->token("$B/pressing/create");
    $r = $c->req('POST', "$B/pressing", http_build_query(['_csrf' => $t, 'customer_name' => 'Client Web', 'phone' => '+227 93 00 00 00', 'express' => '0', 'discount' => '0',
        'lines' => [['article_id' => $PR['chemise'], 'service' => 'lavage', 'quantity' => '3'], ['article_id' => $PR['boubou'], 'service' => 'lavage', 'quantity' => '1']],
        'pay_method' => 'cash', 'pay_amount' => '1000', 'pay_reference' => '']));
    $id = (int) DB::value("SELECT id FROM pressing_orders WHERE customer_name = 'Client Web'");
    ok($id > 0 && str_contains($r['headers'], '/pressing/' . $id), 'dépôt créé : ' . substr($r['headers'], 0, 200));
    eq(2500.0, (float) DB::value('SELECT total FROM pressing_orders WHERE id = ?', [$id]), '3 × 500 + 1000');
    $show = $c->req('GET', "$B/pressing/$id?print=1");
    ok($show['code'] === 200 && str_contains($show['body'], 'data-autoprint-url'), 'fiche + impression automatique');
    $num = DB::value('SELECT number FROM pressing_orders WHERE id = ?', [$id]);
    $tk = $c->req('GET', "$B/pressing/$id/ticket");
    ok(str_contains($tk['body'], $num) && str_contains($tk['body'], 'data-qr="' . $num . '"'), 'ticket avec QR');
    eq(4, substr_count($c->req('GET', "$B/pressing/$id/labels")['body'], 'class="p-label"'), 'une étiquette par pièce');
    eq(403, $c->req('GET', "$B/pressing/tarifs")['code'], 'tarifs réservés au responsable');
    $g = new Http();
    $g->login($B, 'gerant', 'Mot-de-passe-2026');
    $tg = $g->token("$B/pressing/tarifs");
    $g->req('POST', "$B/pressing/tarifs", http_build_query(['_csrf' => $tg, 'new_name' => 'Tapis (m²)', 'new_category' => 'Maison']));
    $tapis = (int) DB::value("SELECT id FROM pressing_articles WHERE name = 'Tapis (m²)'");
    ok($tapis > 0, 'article ajouté');
    $names = array_column(DB::all('SELECT id, name FROM pressing_articles'), 'name', 'id');
    $active = array_fill_keys(array_keys($names), '1');
    $prices = [];
    foreach (DB::all('SELECT * FROM pressing_prices') as $p) {
        $prices[$p['article_id']][$p['service']] = $p['price'];
    }
    $prices[$tapis]['lavage'] = '1500';
    $g->req('POST', "$B/pressing/tarifs", http_build_query(['_csrf' => $tg, 'names' => $names, 'prices' => $prices, 'active' => $active]));
    eq(1500.0, (float) DB::value("SELECT price FROM pressing_prices WHERE article_id = ? AND service = 'lavage'", [$tapis]), 'prix enregistré');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $pdf = $a->req('GET', "$B/pressing/report?export=pdf");
    ok(str_starts_with($pdf['body'], '%PDF'), 'rapport PDF');
    eq(200, $a->req('GET', "$B/pressing/report")['code']);
    eq(200, $a->req('GET', "$B/pressing?f=late")['code']);
    eq(200, $a->req('GET', "$B/pressing?q=" . urlencode('1-1'))['code'], 'recherche par étiquette');
    // 1.5 : fichier des clients, rôles de l'atelier, livraisons
    $list = $c->req('GET', "$B/pressing/clients?q=" . urlencode('Awa'));
    ok($list['code'] === 200 && str_contains($list['body'], 'Awa Moussa'), 'liste des clients (recherche)');
    $cid = (int) DB::value("SELECT id FROM pressing_clients WHERE name = 'Awa Moussa'");
    $tc = $c->token("$B/pressing/clients/$cid");
    $c->req('POST', "$B/pressing/clients/$cid", http_build_query(['_csrf' => $tc, 'name' => 'Awa Moussa Issa', 'phone' => '+227 91 66 66 66', 'address' => 'Plateau, rue 14', 'note' => '', 'open_too' => '1']));
    eq('Awa Moussa Issa', DB::value('SELECT name FROM pressing_clients WHERE id = ?', [$cid]), 'fiche modifiée par le caissier');
    $lav = new Http();
    $lav->login($B, 'laveur', 'Mot-de-passe-2026');
    eq(200, $lav->req('GET', "$B/pressing/ecran")['code'], 'laveur : écran de l\'atelier');
    eq(403, $lav->req('GET', "$B/pressing/clients")['code'], 'laveur : pas de fichier clients');
    eq(403, $lav->req('GET', "$B/pressing/create")['code'], 'laveur : pas de dépôt');
    $tid = (int) $GLOBALS['PR_TAPIS'];
    DB::update('pressing_orders', ['status' => 'ready'], 'id = ?', [$tid]);
    $liv = new Http();
    $liv->login($B, 'livreur', 'Mot-de-passe-2026');
    eq(403, $liv->req('GET', "$B/pressing")['code'], 'livreur : pas la liste générale');
    $page = $liv->req('GET', "$B/pressing/livraisons");
    ok($page['code'] === 200 && str_contains($page['body'], 'Plateau, rue 14') && str_contains($page['body'], '6,25 m²'), 'livreur : adresse et articles à livrer');
    $tl = $liv->token("$B/pressing/livraisons");
    $liv->req('POST', "$B/pressing/livraisons/$tid", http_build_query(['_csrf' => $tl, 'pay_method' => 'cash', 'pay_amount' => '0']));
    eq('ready', DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$tid]), 'solde impayé : livraison refusée');
    DB::run('UPDATE pressing_orders SET paid = total WHERE id = ?', [$tid]);
    $liv->req('POST', "$B/pressing/livraisons/$tid", http_build_query(['_csrf' => $tl, 'pay_method' => 'cash', 'pay_amount' => '']));
    eq('delivered', DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$tid]), 'livré par le livreur');
});
test('Module Clinique (HTTP) : confidentialité, supervision JSON, soin « Fait », ordonnance, délivrance', function () use ($B, &$CL) {
    $acc = new Http();
    $acc->login($B, 'accueil', 'Mot-de-passe-2026');
    $show = $acc->req('GET', "$B/clinique/patients/{$CL['p']}");
    ok($show['code'] === 200 && str_contains($show['body'], 'réservé au personnel soignant') && !str_contains($show['body'], 'Paludisme'), 'accueil : dossier administratif sans données médicales');
    eq(403, $acc->req('GET', "$B/clinique/consultations/{$CL['v']}")['code'], 'consultation interdite à l\'accueil');
    eq(403, $acc->req('GET', "$B/clinique/supervision/feed", null, ['Accept: application/json'])['code'], 'supervision interdite à l\'accueil');
    eq(200, $acc->req('GET', "$B/clinique")['code']);
    eq(200, $acc->req('GET', "$B/clinique/rdv")['code']);
    eq(200, $acc->req('GET', "$B/clinique/factures/{$CL['bill']}")['code']);
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/clinique")['code'], 'caissier sans droit clinique');
    $inf = new Http();
    $inf->login($B, 'infirmier', 'Mot-de-passe-2026');
    $page = $inf->req('GET', "$B/clinique/supervision");
    ok($page['code'] === 200 && str_contains($page['body'], 'supervision.js') && !str_contains($page['body'], 'class="sidebar'), 'écran plein écran autonome');
    $feed = json_decode($inf->req('GET', "$B/clinique/supervision/feed", null, ['Accept: application/json'])['body'], true);
    ok(($feed['ok'] ?? false) && $feed['totals']['patients'] === 2 && count($feed['beds']) === 4, 'flux JSON de supervision');
    preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
    $due = DB::value('SELECT next_due_at FROM clinic_care_plan WHERE id = ?', [$CL['care2']]);
    $r = $inf->req('POST', "$B/clinique/soins/{$CL['care2']}/log", json_encode(['status' => 'done']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    ok($r['code'] === 200 && (json_decode($r['body'], true)['ok'] ?? false), 'soin validé depuis l\'écran : ' . $r['body']);
    ok(DB::value('SELECT next_due_at FROM clinic_care_plan WHERE id = ?', [$CL['care2']]) > $due, 'échéance suivante');
    eq(419, $inf->req('POST', "$B/clinique/soins/{$CL['care2']}/log", json_encode(['status' => 'done']), ['Content-Type: application/json', 'Accept: application/json'])['code'], 'CSRF exigé');
    eq(200, $inf->req('GET', "$B/clinique/sejours/{$CL['stay']}")['code'], 'fiche de séjour');
    $tv = $inf->token("$B/clinique/sejours/{$CL['stay']}");
    $inf->req('POST', "$B/clinique/sejours/{$CL['stay']}/vitals", http_build_query(['_csrf' => $tv, 'vitals' => ['temperature' => '37,1', 'pulse' => '82']]));
    eq(1, (int) DB::value('SELECT COUNT(*) FROM clinic_vitals WHERE stay_id = ?', [$CL['stay']]), 'relevé enregistré par formulaire');
    eq(403, $inf->req('POST', "$B/clinique/sejours/{$CL['stay']}/discharge", http_build_query(['_csrf' => $tv, 'mode' => 'gueri']))['code'], 'sortie réservée au médecin');
    $doc = new Http();
    $doc->login($B, 'medecin', 'Mot-de-passe-2026');
    $vid = (int) DB::value("SELECT id FROM clinic_visits WHERE patient_id = ? AND status = 'waiting'", [$CL['p2']]);
    $amox = (int) DB::value("SELECT id FROM products WHERE name = 'Amoxicilline test'");
    $tp = $doc->token("$B/clinique/consultations/$vid");
    $r = $doc->req('POST', "$B/clinique/consultations/$vid/prescription", http_build_query(['_csrf' => $tp, 'drugs' => [['product_id' => $amox, 'drug_name' => 'x', 'posology' => '1x3', 'quantity' => '1']]]));
    $pid = (int) DB::value('SELECT id FROM clinic_prescriptions WHERE visit_id = ?', [$vid]);
    ok($pid > 0 && str_contains($r['headers'], 'printp=' . $pid), 'ordonnance créée, impression proposée');
    $pp = $doc->req('GET', "$B/clinique/ordonnances/$pid/print");
    ok(str_contains($pp['body'], 'Amoxicilline test') && str_contains($pp['body'], 'MOUSSA'), 'ordonnance imprimable');
    $ph = new Http();
    $ph->login($B, 'pharma', 'Mot-de-passe-2026');
    ok(str_contains($ph->req('GET', "$B/clinique/pharmacie")['body'], DB::value('SELECT number FROM clinic_prescriptions WHERE id = ?', [$pid])), 'visible dans la file de la pharmacie');
    eq(403, $ph->req('GET', "$B/clinique/patients/{$CL['p']}/edit")['code'], 'pharmacien : pas de modification de dossier');
    $form = $ph->req('GET', "$B/clinique/pharmacie/$pid")['body'];
    preg_match('/name="client_uuid" value="([^"]+)"/', $form, $mu);
    preg_match('/name="_csrf" value="([^"]+)"/', $form, $mc);
    $item = (int) DB::value('SELECT id FROM clinic_prescription_items WHERE prescription_id = ?', [$pid]);
    $r = $ph->req('POST', "$B/clinique/pharmacie/$pid", http_build_query(['_csrf' => $mc[1], 'client_uuid' => $mu[1], 'qty' => [$item => '1'], 'pay_method' => 'cash', 'pay_amount' => '2000']));
    $sale = (int) DB::value('SELECT sale_id FROM clinic_dispensings WHERE prescription_id = ?', [$pid]);
    ok($sale > 0 && str_contains($r['headers'], '/sales/' . $sale), 'délivrance → vente');
    eq('dispensed', DB::value('SELECT status FROM clinic_prescriptions WHERE id = ?', [$pid]));
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    foreach (['/clinique/parametres', '/clinique/hospitalisation', '/clinique/hospitalisation?status=discharged', '/clinique/patients', '/clinique/patients/create', '/clinique/factures?status=all', "/clinique/factures/{$CL['bill']}/print", '/clinique/pharmacie?status=dispensed', "/clinique/consultations/{$CL['v']}", "/clinique/patients/{$CL['p']}"] as $u) {
        eq(200, $a->req('GET', $B . $u)['code'], $u);
    }
    ok(str_starts_with($a->req('GET', "$B/clinique/parametres?export=pdf")['body'], '%PDF'), 'rapport PDF');
    $ts = $a->token("$B/clinique/parametres");
    $th = array_map(fn($d) => $d[1], Addons\Clinique\ClinicController::THRESHOLDS);
    $th['alerte_temp_haute'] = '38';
    $a->req('POST', "$B/clinique/parametres/seuils", http_build_query(['_csrf' => $ts, 'th' => $th]));
    eq('38', DB::value("SELECT svalue FROM settings WHERE skey = 'clinique_alerte_temp_haute'"), 'seuil modifié');
    ok(DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'clinique.dossier.view'") > 0, 'accès au dossier médical journalisé');
});
test('Modules (HTTP) : erreur technique affichée à l\'administrateur au lieu d\'une erreur 500', function () use ($B) {
    $d = App\Core\Addons::dir() . '/demo_err';
    @mkdir($d . '/migrations', 0775, true);
    file_put_contents($d . '/manifest.json', json_encode(['code' => 'demo_err', 'name' => 'Démo erreur', 'version' => '1.0.0']));
    file_put_contents($d . '/migrations/0001_x.sql', "CREATE TABLE demo_err (id INT PRIMARY KEY, x TYPE_INCONNU);\n");
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/addons");
    $r = $a->req('POST', "$B/addons", http_build_query(['_csrf' => $t, 'code' => 'demo_err', 'do' => 'install']));
    eq(302, $r['code'], 'pas d\'erreur 500');
    $page = $a->req('GET', "$B/addons")['body'];
    ok(str_contains($page, 'Installation du module « demo_err » impossible') && str_contains($page, 'TYPE_INCONNU'), 'cause affichée');
    eq(null, DB::value("SELECT code FROM addons WHERE code = 'demo_err'"), 'module non enregistré');
    @unlink($d . '/migrations/0001_x.sql'); @rmdir($d . '/migrations'); @unlink($d . '/manifest.json'); @rmdir($d);
    foreach (glob(S\BackupService::dir() . '/*avant_module_demo_err*') ?: [] as $f) { @unlink($f); }
});
test('HTTP : confirmation renvoie l\'action, licence en lecture seule, mise à jour (mot de passe, lots), À propos', function () use ($B, $LIC_SK) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $page = $a->req('GET', "$B/addons")['body'];
    ok(str_contains($page, 'name="do" value="disable"') || str_contains($page, 'name="do" value="install"'), 'action transmise par un champ caché (confirmation)');
    $about = $a->req('GET', "$B/about")['body'];
    ok(str_contains($about, 'Mahamadou Hamidou') && str_contains($about, '92 08 12 82') && str_contains($about, 'Ingénieur informaticien nigérien'), 'page À propos');
    ok(str_contains($a->req('GET', "$B/sales")['body'], 'href="/about"'), 'lien À propos en bas du menu');
    ok(str_contains($a->req('GET', "$B/about/contrat")['body'], 'Article 4'), 'contrat');
    // Licence retirée et essai terminé : modifications refusées, consultation possible
    $key = DB::value("SELECT svalue FROM settings WHERE skey = 'licence_key'");
    DB::run("DELETE FROM settings WHERE skey = 'licence_key'");
    $r = $a->req('POST', "$B/pos/sale", json_encode(['items' => []]), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/licence")]);
    ok($r['code'] === 403 && str_contains($r['body'], 'lecture seule'), 'vente refusée sans licence : ' . $r['code']);
    eq(200, $a->req('GET', "$B/sales")['code'], 'consultation possible');
    ok(str_contains($a->req('GET', "$B/dashboard")['body'], 'Licence non activée'), 'bandeau');
    $t = $a->token("$B/licence");
    $a->req('POST', "$B/licence", http_build_query(['_csrf' => $t, 'licence_key' => 'PWL1.faux.faux']));
    eq(null, DB::value("SELECT svalue FROM settings WHERE skey = 'licence_key'"), 'fausse clé refusée');
    $a->req('POST', "$B/licence", http_build_query(['_csrf' => $t, 'licence_key' => $key]));
    eq($key, DB::value("SELECT svalue FROM settings WHERE skey = 'licence_key'"), 'clé activée par la page');
    // Mise à jour : mot de passe exigé, envoi par lots, contrôle
    $c = new Http();
    $c->login($B, 'gerant', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/update")['code'], 'mise à jour réservée à l\'administrateur');
    $hdr = ['Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/update")];
    $bad = json_decode($a->req('POST', "$B/update/start", json_encode(['password' => 'faux']), array_merge($hdr, ['Content-Type: application/json']))['body'], true);
    ok(!$bad['ok'] && str_contains($bad['error'], 'Mot de passe'), 'mot de passe exigé');
    $st = json_decode($a->req('POST', "$B/update/start", json_encode(['password' => 'Essai-Niamey-2026']), array_merge($hdr, ['Content-Type: application/json']))['body'], true);
    ok($st['ok'] && strlen($st['token']) === 32, 'préparation créée');
    $up = $a->req('POST', "$B/update/upload", ['token' => $st['token'], 'paths[0]' => 'config/app.php', 'paths[1]' => '../x.php', 'files[0]' => new CURLFile(BASE_PATH . '/config/app.php'), 'files[1]' => new CURLFile(BASE_PATH . '/config/app.php')], $hdr);
    $u = json_decode($up['body'], true);
    ok($u['ok'] && $u['saved'] === 1 && $u['skipped'] === 1, 'lot reçu : ' . $up['body']);
    $chk = json_decode($a->req('POST', "$B/update/check", json_encode(['token' => $st['token']]), array_merge($hdr, ['Content-Type: application/json']))['body'], true);
    ok($chk['ok'] && str_contains(implode(' ', $chk['errors']), 'app/bootstrap.php'), 'dossier incomplet signalé');
    $x = json_decode($a->req('POST', "$B/update/check", json_encode(['token' => str_repeat('a', 32)]), array_merge($hdr, ['Content-Type: application/json']))['body'], true);
    ok(!$x['ok'], 'jeton d\'une autre session refusé');
    ok(str_contains($a->req('GET', "$B/update")['body'], 'webkitdirectory'), 'page de mise à jour');
});
test('Caisses simultanées : 10 ventes en même temps pour 5 en stock, aucune survente ni double déduction', function () use ($B) {
    actingAs('admin');
    if (!DB::value("SELECT id FROM cash_sessions WHERE register_id = 1 AND status = 'open'")) {
        S\CashService::open(1, 0);
    }
    $pid = S\ProductService::create(S\ProductService::validate(['name' => 'Article concurrence', 'sale_price' => 1000, 'cost_price' => 600, 'track_stock' => 1]), 5, 1);
    DB::update('products', ['track_stock' => 1], 'id = ?', [$pid]);
    $clients = [];
    foreach (['caissier' => 'Mot-de-passe-2026', 'gerant' => 'Mot-de-passe-2026'] as $u => $pw) {
        $h = new Http();
        $h->login($B, $u, $pw);
        preg_match('/name="csrf-token" content="([^"]+)"/', $h->req('GET', "$B/pos")['body'], $m);
        $clients[] = [$h, $m[1] ?? ''];
    }
    $st = json_decode($clients[0][0]->req('GET', "$B/pos/stock?ids=$pid", null, ['Accept: application/json'])['body'], true);
    eq(5.0, (float) $st['stock'][$pid], 'stock lu par la caisse');
    $mh = curl_multi_init();
    $handles = [];
    $uuids = [];
    for ($i = 0; $i < 10; $i++) {
        [$h, $tok] = $clients[$i % 2];
        $uuids[] = $u = uuid();
        $ch = curl_init("$B/pos/checkout");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => $h->jar, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $tok],
            CURLOPT_POSTFIELDS => json_encode(['_csrf' => $tok, 'client_uuid' => $u, 'items' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]]])]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do { $s = curl_multi_exec($mh, $running); if ($running) { curl_multi_select($mh, 1); } } while ($running && $s === CURLM_OK);
    $okCount = 0;
    foreach ($handles as $ch) {
        $d = json_decode((string) curl_multi_getcontent($ch), true);
        $okCount += !empty($d['ok']) ? 1 : 0;
        curl_multi_remove_handle($mh, $ch);
    }
    eq(5, $okCount, '5 ventes acceptées, 5 refusées (stock insuffisant)');
    eq(0.0, stock($pid), 'stock final à zéro, jamais négatif');
    eq(-5.0, (float) DB::value("SELECT SUM(quantity) FROM stock_movements WHERE product_id = ? AND type = 'sale'", [$pid]), 'une seule déduction par vente');
    eq(5, (int) DB::value('SELECT COUNT(DISTINCT s.id) FROM sales s JOIN sale_items i ON i.sale_id = s.id WHERE i.product_id = ?', [$pid]));
    ok((int) DB::value("SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND type = 'sale' AND user_id IS NULL", [$pid]) === 0, 'mouvements attribués au caissier');
    // Rejouer une vente déjà validée (même identifiant) : aucune nouvelle déduction
    [$h, $tok] = $clients[0];
    $okUuid = DB::value("SELECT client_uuid FROM sales s JOIN sale_items i ON i.sale_id = s.id WHERE i.product_id = ? LIMIT 1", [$pid]);
    $r = json_decode($h->req('POST', "$B/pos/checkout", json_encode(['_csrf' => $tok, 'client_uuid' => $okUuid, 'items' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]]]), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $tok])['body'], true);
    ok(!empty($r['duplicate']), 'double envoi reconnu');
    eq(0.0, stock($pid), 'pas de double déduction');
});
test('Caisse hors connexion : catalogue gardé sur l\'appareil, jeton à jour, renvoi des ventes sans doublon', function () use ($B) {
    // Sans session : rien n'est livré
    eq(401, (new Http())->req('GET', "$B/pos/catalog", null, ['Accept: application/json'])['code'], 'catalogue : connexion obligatoire');
    eq(401, (new Http())->req('GET', "$B/pos/ping", null, ['Accept: application/json'])['code'], 'ping : connexion obligatoire');
    actingAs('admin');
    if (!DB::value("SELECT id FROM cash_sessions WHERE register_id = 1 AND status = 'open'")) {
        S\CashService::open(1, 0);
    }
    $pid = S\ProductService::create(S\ProductService::validate(['name' => 'Article hors ligne', 'barcode' => '2999000000017', 'sale_price' => 700, 'cost_price' => 400, 'track_stock' => 1]), 10, 1);
    DB::update('products', ['track_stock' => 1], 'id = ?', [$pid]);
    $h = new Http();
    $h->login($B, 'caissier', 'Mot-de-passe-2026');
    preg_match('/name="csrf-token" content="([^"]+)"/', $h->req('GET', "$B/pos")['body'], $m);
    $cat = json_decode($h->req('GET', "$B/pos/catalog", null, ['Accept: application/json'])['body'], true);
    ok($cat['ok'] && count($cat['items']) === (int) DB::value("SELECT COUNT(*) FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN categories pc ON pc.id = c.parent_id WHERE p.status = 'active' AND p.sellable = 1 AND " . S\ProductService::CHANNEL_SQL . " IN ('all','shop')"), 'catalogue complet des articles vendables (' . count($cat['items']) . ')');
    $it = array_values(array_filter($cat['items'], fn($x) => $x['id'] === $pid))[0] ?? null;
    ok($it && $it['barcode'] === '2999000000017' && $it['price'] == 700 && $it['stock'] == 10 && isset($it['packs'], $it['cats']), 'article : code-barres, prix, stock, conditionnements, catégories');
    ok(!str_contains(json_encode($cat), 'cost_price') && !array_key_exists('cost', $it), 'aucun prix d\'achat ni donnée interne dans le catalogue');
    $ping = json_decode($h->req('GET', "$B/pos/ping", null, ['Accept: application/json'])['body'], true);
    ok($ping['ok'] && $ping['csrf'] === ($m[1] ?? '') && $ping['userId'] === (int) DB::value("SELECT id FROM users WHERE username = 'caissier'"), 'ping : jeton de sécurité et utilisateur de la session');
    // Vente faite hors connexion puis envoyée deux fois (coupure pendant la réponse) : une seule vente
    $u = uuid();
    $body = json_encode(['_csrf' => $ping['csrf'], 'client_uuid' => $u, 'items' => [['product_id' => $pid, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 1400]],
        'note' => '[Vente hors connexion du 26/10/2026 10:00, ticket provisoire HL-2610-100000-ABCD]', 'print_ticket' => false]);
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $ping['csrf']];
    $r1 = json_decode($h->req('POST', "$B/pos/checkout", $body, $hdr)['body'], true);
    $r2 = json_decode($h->req('POST', "$B/pos/checkout", $body, $hdr)['body'], true);
    ok($r1['ok'] && !$r1['duplicate'] && $r2['ok'] && $r2['duplicate'] && $r1['sale_id'] === $r2['sale_id'], 'renvoi : « déjà enregistrée », même vente');
    eq(1, (int) DB::value('SELECT COUNT(*) FROM sales WHERE client_uuid = ?', [$u]));
    eq(8.0, stock($pid), 'stock déduit une seule fois');
    ok(str_contains((string) DB::value('SELECT note FROM sales WHERE client_uuid = ?', [$u]), 'HL-2610-100000-ABCD'), 'numéro provisoire conservé dans la note');
    // Le serveur reste juge : prix envoyé par le navigateur ignoré sans droit, stock vérifié
    $r3 = json_decode($h->req('POST', "$B/pos/checkout", json_encode(['_csrf' => $ping['csrf'], 'client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 50]], 'payments' => [['method' => 'cash', 'amount' => 35000]]]), $hdr)['body'], true);
    ok(!$r3['ok'] && $r3['error'] !== '', 'vente hors connexion au-delà du stock : refusée à la synchronisation (' . $r3['error'] . ')');
    // Service worker : seule la caisse est gardée ; ni POST ni JSON mis en cache
    $sw = (string) file_get_contents(PUBLIC_PATH . '/sw.js');
    ok(str_contains($sw, "req.method !== 'GET'") && str_contains($sw, "SCOPE + 'pos'") && !str_contains($sw, 'pos/catalog') && !str_contains($sw, 'pos/checkout'), 'service worker : page de caisse et fichiers statiques seulement');
    ok(str_contains((string) file_get_contents(PUBLIC_PATH . '/assets/js/pos.js'), "addEventListener('beforeunload'"), 'avertissement à la fermeture avec des ventes non envoyées');
});
test('POS Marwa (HTTP) : accès mobile, QR code, /health, appareils, application installable', function () use ($B) {
    $h = json_decode((new Http())->req('GET', "$B/health")['body'], true);
    ok(($h['ok'] ?? false) && $h['app'] === 'POS Marwa' && !isset($h['db_password']), '/health public, sans donnée sensible');
    $m = (new Http())->req('GET', "$B/m");
    ok($m['code'] === 200 && str_contains($m['body'], 'Se connecter') && str_contains($m['body'], 'manifest.webmanifest'), 'page d\'arrivée du QR code');
    $mf = (new Http())->req('GET', "$B/manifest.webmanifest");
    ok($mf['code'] === 200 && str_contains($mf['body'], '"POS Marwa"'), 'manifeste web');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    ok((int) DB::value("SELECT COUNT(*) FROM user_devices d JOIN users u ON u.id = d.user_id WHERE u.username = 'admin' AND d.status = 'approved'") >= 1, 'appareil enregistré à la connexion');
    $page = $a->req('GET', "$B/mobile")['body'];
    ok(str_contains($page, 'data-qr="http://') || str_contains($page, 'Aucune adresse de réseau local détectée'), 'page QR code (ou message explicite sans réseau)');
    $_SERVER['SERVER_PORT'] = 8080;
    $u = S\NetworkService::urlFor('192.168.1.20');
    ok(str_starts_with($u, 'http://192.168.1.20:8080/') && str_ends_with($u, '/m'), 'adresse d\'accès avec le port : ' . $u);
    ok(str_contains($page, 'Require ip 192.168') && str_contains($page, 'netsh advfirewall'), 'procédure Apache / pare-feu');
    ok(str_contains($a->req('GET', "$B/devices")['body'], 'Approbation obligatoire'), 'page des appareils');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/devices")['code'], 'appareils : administrateur seulement');
    $dev = (int) DB::value("SELECT d.id FROM user_devices d JOIN users u ON u.id = d.user_id WHERE u.username = 'caissier' ORDER BY d.id DESC LIMIT 1");
    $t = $a->token("$B/devices");
    $a->req('POST', "$B/devices/$dev", http_build_query(['_csrf' => $t, 'status' => 'revoked']));
    $r = $c->req('GET', "$B/sales");
    ok($r['code'] === 302 && str_contains($r['headers'], '/login'), 'appareil bloqué : session coupée immédiatement');
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    ok(str_contains($c->req('GET', "$B/login")['body'], 'bloqué'), 'reconnexion refusée');
    DB::update('user_devices', ['status' => 'approved'], 'id = ?', [$dev]);
});
test('POS Marwa (HTTP) : modes — caisse clinique (prestations, file d\'attente, paiement partiel, tout ou rien), caisse pressing', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/settings");
    $a->req('POST', "$B/settings/mode", http_build_query(['_csrf' => $t, 'mode' => 'clinique']));
    eq('clinique', DB::value("SELECT svalue FROM settings WHERE skey = 'business_mode'"), 'mode enregistré');
    $acc = new Http();
    $acc->login($B, 'accueil', 'Mot-de-passe-2026');
    $home = $acc->req('GET', "$B/");
    ok(str_contains($home['headers'], '/clinique/caisse'), 'accueil : la caisse clinique s\'ouvre directement');
    ok(str_contains($acc->req('GET', "$B/pos")['headers'], '/clinique/caisse'), '/pos redirigé vers la caisse clinique');
    $page = $acc->req('GET', "$B/clinique/caisse")['body'];
    ok(str_contains($page, 'Caisse clinique') && str_contains($page, 'Consultation de médecine générale') && !str_contains($page, 'productGrid'), 'prestations médicales, pas d\'articles commerciaux');
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]];
    $found = json_decode($acc->req('GET', "$B/clinique/caisse/patients?q=ISSA", null, ['Accept: application/json'])['body'], true);
    ok(count($found['items']) >= 1, 'recherche patient');
    $np = json_decode($acc->req('POST', "$B/clinique/caisse/patients", json_encode(['last_name' => 'Caisse', 'first_name' => 'Test', 'sex' => 'F', 'phone' => '96 11 22 33', 'coverage_pct' => '50', 'insurer' => 'CNSS']), $hdr)['body'], true);
    ok($np['ok'] && $np['patient']['coverage'] == 50, 'patient créé depuis la caisse');
    $consult = (int) DB::value("SELECT id FROM clinic_acts WHERE name = 'Consultation de médecine générale'");
    $glyc = (int) DB::value("SELECT id FROM clinic_acts WHERE name = 'Glycémie'");
    $bills = (int) DB::value('SELECT COUNT(*) FROM clinic_bills');
    $bad = json_decode($acc->req('POST', "$B/clinique/caisse", json_encode(['patient_id' => $np['patient']['id'], 'lines' => [['act_id' => $consult, 'quantity' => 1]], 'queue' => true, 'pay' => ['method' => 'bitcoin', 'amount' => '500']]), $hdr)['body'], true);
    ok(!$bad['ok'], 'mode de paiement refusé');
    eq($bills, (int) DB::value('SELECT COUNT(*) FROM clinic_bills'), 'tout ou rien : aucune facture enregistrée');
    eq(0, (int) DB::value("SELECT COUNT(*) FROM clinic_visits WHERE patient_id = ? AND status = 'waiting'", [$np['patient']['id']]), 'ni de consultation');
    $ok = json_decode($acc->req('POST', "$B/clinique/caisse", json_encode(['patient_id' => $np['patient']['id'], 'lines' => [['act_id' => $consult, 'quantity' => 1], ['act_id' => $glyc, 'quantity' => 2]], 'queue' => true, 'reason' => 'Fièvre', 'pay' => ['method' => 'cash', 'amount' => '1000']]), $hdr)['body'], true);
    ok($ok['ok'], 'encaissement : ' . json_encode($ok));
    eq(5000.0, $ok['total'], '3000 + 2 × 1000');
    eq(2500.0, $ok['patient_amount'], 'prise en charge 50 %');
    eq(1000.0, $ok['paid'], 'paiement partiel');
    eq(1500.0, $ok['due'], 'reste');
    ok($ok['visit_id'] > 0 && DB::value('SELECT bill_id FROM clinic_visits WHERE id = ?', [$ok['visit_id']]) == $ok['bill_id'], 'patient en file d\'attente, facture liée à la consultation');
    ok(str_contains($acc->req('GET', "$B/clinique/factures/{$ok['bill_id']}/ticket")['body'], 'PRESTATIONS MÉDICALES'), 'reçu');
    // Pressing
    $a->req('POST', "$B/settings/mode", http_build_query(['_csrf' => $t, 'mode' => 'pressing']));
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    ok(str_contains($c->req('GET', "$B/")['headers'], '/pressing/caisse'), 'caissier : caisse pressing directement');
    ok(str_contains($c->req('GET', "$B/pos")['headers'], '/pressing/caisse'), '/pos redirigé vers la caisse pressing');
    $pc = $c->req('GET', "$B/pressing/caisse")['body'];
    ok(str_contains($pc, 'Caisse pressing') && str_contains($pc, 'pressingForm'), 'dépôt direct sans vente commerciale');
    $num = DB::value('SELECT number FROM pressing_orders ORDER BY id DESC LIMIT 1');
    ok(str_contains($c->req('GET', "$B/pressing/find?q=" . urlencode($num))['headers'], '/pressing/'), 'recherche du dépôt par n° (QR du ticket)');
    $s = $c->req('GET', "$B/pressing/suivi?f=all")['body'];
    ok(str_contains($s, 'Livraison prévue') && str_contains($s, 'Retard') && str_contains($s, $num), 'écran de suivi des vêtements');
    eq(200, $c->req('GET', "$B/pressing/client?phone=" . urlencode('90 55 55 55'))['code'], 'historique client');
    ok(!str_contains($c->req('GET', "$B/pressing/suivi")['body'], 'href="/clinique"'), 'menus clinique masqués en mode pressing');
    $csv = $a->req('GET', "$B/pressing/suivi?f=all&export=csv");
    ok(str_contains($csv['body'], 'Vêtements') && str_contains($csv['body'], $num), 'export du suivi');
    $a->req('POST', "$B/settings/mode", http_build_query(['_csrf' => $t, 'mode' => 'commerce']));
    eq('commerce', DB::value("SELECT svalue FROM settings WHERE skey = 'business_mode'"));
});
test('POS Marwa (HTTP) : inventaire par scan, saisie, rapport ; étiquettes (recherche, aperçu, modèles)', function () use ($B) {
    $g = new Http();
    $g->login($B, 'gerant', 'Mot-de-passe-2026');
    foreach (DB::all("SELECT id FROM inventories WHERE status = 'draft'") as $d) { DB::update('inventories', ['status' => 'cancelled'], 'id = ?', [$d['id']]); }
    $t = $g->token("$B/inventories");
    $r = $g->req('POST', "$B/inventories", http_build_query(['_csrf' => $t, 'warehouse_id' => 1]));
    $inv = (int) DB::value("SELECT id FROM inventories WHERE status = 'draft' ORDER BY id DESC LIMIT 1");
    $page = $g->req('GET', "$B/inventories/$inv")['body'];
    ok(str_contains($page, 'invScanInput') && str_contains($page, 'invCamera'), 'écran de comptage par scan');
    preg_match('/name="csrf-token" content="([^"]+)"/', $page, $m);
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]];
    $g->req('POST', "$B/inventories/$inv/scan", json_encode(['code' => '2100000000012']), $hdr);
    $s = json_decode($g->req('POST', "$B/inventories/$inv/scan", json_encode(['code' => '2100000000012']), $hdr)['body'], true);
    eq(2.0, $s['counted'], 'deux lectures');
    $c = json_decode($g->req('POST', "$B/inventories/$inv/count", json_encode(['item_id' => $s['item_id'], 'qty' => '14', 'note' => 'recompté']), $hdr)['body'], true);
    eq(14.0, $c['counted'], 'saisie manuelle enregistrée immédiatement');
    ok(str_contains($g->req('GET', "$B/inventories/$inv/report?f=all")['body'], 'RAPPORT D&#039;INVENTAIRE'), 'rapport imprimable');
    $csv = $g->req('GET', "$B/inventories/$inv/report?f=all&export=csv");
    ok(str_contains($csv['body'], 'Théorique') && str_contains($csv['body'], 'recompté'), 'export CSV');
    // Étiquettes
    $lp = $g->req('GET', "$B/products/labels")['body'];
    ok(str_contains($lp, 'labelPreview') && str_contains($lp, 'Code-barres + nom + prix'), 'concepteur d\'étiquettes');
    $sr = json_decode($g->req('GET', "$B/products/labels/search?code=2100000000012", null, ['Accept: application/json'])['body'], true);
    eq(1, count($sr['items']), 'article trouvé par code-barres');
    $pid = $sr['items'][0]['id'];
    preg_match('/name="_csrf" value="([^"]+)"/', $lp, $mt);
    $html = $g->req('POST', "$B/products/labels/render", http_build_query(['_csrf' => $mt[1], 'items' => [$pid => 7], 'cfg' => ['paper' => 'a4', 'width' => 63.5, 'height' => 38.1, 'cols' => 3, 'fields' => ['barcode', 'name', 'price']]]))['body'];
    eq(7, substr_count($html, 'class="lbl"'), '7 étiquettes');
    ok(str_contains($html, 'viewBox="0 0 117 ') && str_contains($html, 'Inv scan A'), 'EAN-13 et nom imprimés');
    $roll = $g->req('POST', "$B/products/labels/render", http_build_query(['_csrf' => $mt[1], 'items' => [$pid => 2], 'cfg' => ['paper' => 'roll', 'width' => 40, 'height' => 30, 'fields' => ['price']]]))['body'];
    ok(str_contains($roll, 'size: 40mm 30mm') && !str_contains($roll, 'viewBox="0 0 117 ') && substr_count($roll, 'class="page"') === 2, 'rouleau 40 × 30 : prix seul, une étiquette par page');
    $tpl = json_decode($g->req('POST', "$B/products/labels/templates", json_encode(['name' => 'Test 50x30', 'cfg' => ['paper' => 'roll', 'width' => 50, 'height' => 30, 'fields' => ['barcode', 'price']]]), $hdr)['body'], true);
    ok($tpl['ok'] && $tpl['config']['width'] == 50, 'modèle enregistré');
    $del = json_decode($g->req('POST', "$B/products/labels/templates/{$tpl['id']}/delete", json_encode([]), $hdr)['body'], true);
    ok($del['ok'] && !DB::value('SELECT id FROM label_templates WHERE id = ?', [$tpl['id']]), 'modèle supprimé');
    DB::update('inventories', ['status' => 'cancelled'], 'id = ?', [$inv]);
});
test('Module Reconnaissance par photo : installation, apprentissage, droits, index, apprentissage en vente, partout', function () use ($B) {
    App\Core\Addons::install('vision', false);
    ok(App\Core\Addons::isActive('vision'), 'module actif');
    $hasPerm = fn(string $role, string $perm) => (bool) DB::value('SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ? AND p.code = ?', [$role, $perm]);
    ok($hasPerm('cashier', 'vision.use') && !$hasPerm('cashier', 'vision.teach') && $hasPerm('store_manager', 'vision.teach'), 'droits : le caissier reconnaît, le gérant apprend');
    // Intelligence livrée en local : fichiers ≤ 1 Mo (limites d'envoi de Wamp), description cohérente
    $dir = PUBLIC_PATH . '/assets/addons/vision';
    $m = json_decode((string) file_get_contents("$dir/modele/modele.txt"), true);
    $paths = $m['weightsManifest'][0]['paths'] ?? [];
    ok(count($paths) >= 5 && is_file("$dir/tf.min.js"), 'modèle et moteur publiés');
    $bytes = 0;
    foreach ($paths as $f) { ok(is_file("$dir/modele/$f") && filesize("$dir/modele/$f") <= 1024 * 1024, "fragment $f ≤ 1 Mo"); $bytes += filesize("$dir/modele/$f"); }
    $need = 0;
    foreach ($m['weightsManifest'][0]['weights'] as $w) { $need += 4 * (int) array_product($w['shape'] ?: [1]); }
    eq($need, $bytes, 'taille des poids = description du modèle');
    ok(!str_contains((string) file_get_contents("$dir/modele/modele.txt"), 'Logits/Conv2d_1c_1x1'), 'couche de classification retirée (empreinte seulement)');
    ok(str_contains((string) file_get_contents("$dir/vision.js"), "window.regeneratorRuntime = undefined"), 'compatible avec la politique de sécurité (sans unsafe-eval)');

    $pa = (int) DB::value("SELECT id FROM products WHERE name = 'Inv scan A'");
    $pb = (int) DB::value("SELECT id FROM products WHERE name = 'Inv scan B'");
    $vec = fn(int $seed) => base64_encode(implode('', array_map(fn($i) => chr((($i * 37 + $seed * 11) % 255) - 127 & 0xFF), range(0, 511))));
    $thumb = 'data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0fausse-vignette");

    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $page = $a->req('GET', "$B/vision");
    ok($page['code'] === 200 && str_contains($page['body'], 'Apprendre') && str_contains($page['body'], 'vision-teach.js'), 'page d\'apprentissage');
    $pos = $a->req('GET', "$B/pos")['body'];
    ok(str_contains($pos, 'addons/vision/vision.js') && str_contains($pos, 'addons/vision/vision.css'), 'reconnaissance chargée dans la caisse');
    ok(str_contains($a->req('GET', "$B/products")['body'], 'addons/vision/vision.js'), '… et dans les pages de gestion');
    preg_match('/name="csrf-token" content="([^"]+)"/', $pos, $mm);
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $mm[1]];
    $v0 = (int) setting('vision_version', '1');
    $r = json_decode($a->req('POST', "$B/vision/refs", json_encode(['product_id' => $pa, 'vec' => $vec(1), 'thumb' => $thumb, 'source' => 'photo']), $hdr)['body'], true);
    ok(($r['ok'] ?? false) && $r['id'] > 0 && $r['version'] > $v0, 'photo apprise, version de l\'index augmentée');
    $a->req('POST', "$B/vision/refs", json_encode(['product_id' => $pa, 'vec' => $vec(2), 'source' => 'photo']), $hdr);
    $a->req('POST', "$B/vision/refs", json_encode(['product_id' => $pb, 'vec' => $vec(3), 'source' => 'photo']), $hdr);
    eq(422, $a->req('POST', "$B/vision/refs", json_encode(['product_id' => $pa, 'vec' => base64_encode(str_repeat('a', 511)), 'source' => 'photo']), $hdr)['code'], 'empreinte de mauvaise taille refusée');
    eq(422, $a->req('POST', "$B/vision/refs", json_encode(['product_id' => $pa, 'vec' => $vec(4), 'thumb' => 'data:text/html;base64,PHNjcmlwdD4=', 'source' => 'photo']), $hdr)['code'], 'vignette non JPEG refusée');
    eq(422, $a->req('POST', "$B/vision/refs", json_encode(['product_id' => 999999, 'vec' => $vec(4), 'source' => 'photo']), $hdr)['code'], 'article inconnu refusé');

    $cfg = json_decode($a->req('GET', "$B/vision/config", null, ['Accept: application/json'])['body'], true);
    ok($cfg['ok'] && $cfg['teach'] && $cfg['refs'] === 3, 'configuration lue par les appareils');
    $idx = $a->req('GET', "$B/vision/index?v=" . $cfg['version'], null, ['Accept: application/json']);
    $ix = json_decode($idx['body'], true);
    ok(count($ix['pids']) === 3 && strlen(base64_decode($ix['vecs'])) === 3 * 512 && $ix['dim'] === 512, 'index : 3 empreintes de 512 octets');
    ok(str_contains($idx['headers'], 'max-age=86400'), 'index mis en cache par version');
    $pr = json_decode($a->req('GET', "$B/vision/products?ids=$pa,$pb", null, ['Accept: application/json'])['body'], true);
    eq('2100000000012', $pr['items'][$pa]['code'], 'code transmis à l\'écran = code-barres de l\'article');
    ok(isset($pr['items'][$pb]['stock'], $pr['items'][$pb]['price']), 'prix et stock proposés');

    // Caissier : reconnaît et apprend pendant la vente, mais n'administre pas
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    preg_match('/name="csrf-token" content="([^"]+)"/', $c->req('GET', "$B/pos")['body'], $cm);
    $ch = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $cm[1]];
    eq(403, $c->req('GET', "$B/vision")['code'], 'page d\'apprentissage réservée');
    $cc = json_decode($c->req('GET', "$B/vision/config", null, ['Accept: application/json'])['body'], true);
    ok($cc['ok'] && !$cc['teach'], 'le caissier utilise la reconnaissance');
    eq(422, $c->req('POST', "$B/vision/refs", json_encode(['product_id' => $pa, 'vec' => $vec(5), 'source' => 'photo']), $ch)['code'], 'photos d\'apprentissage : refusées au caissier');
    setting_set('vision_max_ventes', '2');
    foreach ([6, 7, 8] as $sd) {
        $v = json_decode($c->req('POST', "$B/vision/refs", json_encode(['product_id' => $pb, 'vec' => $vec($sd), 'source' => 'vente']), $ch)['body'], true);
        ok($v['ok'] && $v['id'] > 0, 'confirmation en vente apprise');
    }
    eq(2, (int) DB::value("SELECT COUNT(*) FROM vision_refs WHERE product_id = ? AND source = 'vente'", [$pb]), 'seules les 2 dernières photos de vente sont gardées');
    setting_set('vision_apprendre_ventes', '0');
    $v = json_decode($c->req('POST', "$B/vision/refs", json_encode(['product_id' => $pb, 'vec' => $vec(9), 'source' => 'vente']), $ch)['body'], true);
    ok($v['ok'] && !empty($v['skipped']), 'apprentissage en vente désactivable');
    setting_set('vision_apprendre_ventes', '1');
    setting_set('vision_max_ventes', '12');
    ok(json_decode($c->req('POST', "$B/vision/log", json_encode(['outcome' => 'confirme', 'suggested_id' => $pa, 'chosen_id' => $pa, 'score' => 0.91, 'margin' => 0.2, 'context' => 'pos']), $ch)['body'], true)['ok'], 'reconnaissance journalisée');
    $c->req('POST', "$B/vision/log", json_encode(['outcome' => 'corrige', 'suggested_id' => $pa, 'chosen_id' => $pb, 'score' => 0.7, 'margin' => 0.01, 'context' => 'pos']), $ch);
    eq(422, $c->req('POST', "$B/vision/log", json_encode(['outcome' => 'pirate']), $ch)['code'], 'résultat inconnu refusé');
    $f = json_decode($c->req('GET', "$B/vision/search?q=Inv%20scan", null, ['Accept: application/json'])['body'], true);
    ok(count($f['items']) >= 2, 'recherche pour corriger une proposition');
    eq(403, $c->req('POST', "$B/vision/products/$pa/clear", json_encode([]), $ch)['code'], 'effacement réservé');
    $st = Addons\Vision\VisionService::stats();
    ok($st['uses'] >= 2 && $st['rate'] !== null && $st['confused'], 'statistiques : taux et confusions');

    // Article archivé : absent de l'index ; oubli d'une photo et d'un article
    DB::update('products', ['status' => 'archived'], 'id = ?', [$pb]);
    $ix = Addons\Vision\VisionService::index();
    ok(!in_array($pb, $ix['pids'], true), 'article archivé jamais proposé');
    DB::update('products', ['status' => 'active'], 'id = ?', [$pb]);
    $ref = (int) DB::value('SELECT id FROM vision_refs WHERE product_id = ? LIMIT 1', [$pa]);
    ok(json_decode($a->req('POST', "$B/vision/refs/$ref/delete", json_encode([]), $hdr)['body'], true)['ok'], 'photo oubliée');
    $d = json_decode($a->req('POST', "$B/vision/products/$pb/clear", json_encode([]), $hdr)['body'], true);
    ok($d['ok'] && $d['deleted'] === 3, 'toutes les photos d\'un article oubliées');
    $t = $a->token("$B/vision");
    $a->req('POST', "$B/vision/settings", http_build_query(['_csrf' => $t, 'vision_auto' => '1', 'vision_seuil_auto' => '5', 'vision_marge' => '0,1', 'vision_apprendre_ventes' => '1', 'vision_max_ventes' => '9']));
    $sv = array_column(DB::all("SELECT skey, svalue FROM settings WHERE skey LIKE 'vision\\_%'"), 'svalue', 'skey');
    ok($sv['vision_auto'] === '1' && $sv['vision_seuil_auto'] === '0.99' && $sv['vision_marge'] === '0.1' && $sv['vision_max_ventes'] === '9', 'réglages bornés et enregistrés : ' . json_encode($sv));
    setting_set('vision_auto', '0');

    // Désactivé : plus rien n'est chargé, les données restent
    App\Core\Addons::setActive('vision', false);
    eq(404, $a->req('GET', "$B/vision")['code'], 'module désactivé : page absente');
    ok(!str_contains($a->req('GET', "$B/pos")['body'], 'vision.js'), 'module désactivé : rien dans la caisse');
    ok((int) DB::value('SELECT COUNT(*) FROM vision_refs') >= 1, 'données conservées');
    App\Core\Addons::setActive('vision', true);
});

test('Surveillance : moteur de comptage (ligne, zone, sens, hésitation, lumière) — tests JavaScript', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        ok(true, 'node absent : tests JavaScript non exécutés');
        return;
    }
    exec(escapeshellarg($node) . ' ' . escapeshellarg(BASE_PATH . '/tests/js/track.test.js') . ' 2>&1', $out, $code);
    ok($code === 0, implode("\n", $out));
    ok((bool) preg_match('/(\d+) réussi\(s\), 0 échec/', implode("\n", $out), $m) && (int) $m[1] >= 12, 'au moins 12 vérifications du moteur : ' . end($out));
});

test('Module Reconnaissance 1.1 : caméras USB / carte / IP, relais sécurisé, surveillance, comparaison avec les ventes', function () use ($B, $port) {
    App\Core\Addons::install('vision', false);
    eq('1.2.0', App\Core\Addons::installed()['vision']['version'] ?? null, 'module mis à jour en 1.2.0');
    ok((bool) DB::value("SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = 'store_manager' AND p.code = 'vision.watch'"), 'droit de surveillance pour le gérant');
    // Fausse caméra IP (serveur PHP séparé)
    $cport = (int) (getenv('TEST_CAM_PORT') ?: $port + 7);
    $cam = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cport, escapeshellarg(BASE_PATH . '/tests/fake-camera.php')), [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $cp);
    usleep(500000);
    $CB = "http://127.0.0.1:$cport";
    try {
        $a = new Http();
        $a->login($B, 'admin', 'Essai-Niamey-2026');
        $t = $a->token("$B/vision/cameras");
        ok($t !== '', 'page Caméras');
        // Caméra IP protégée par mot de passe
        $a->req('POST', "$B/vision/cameras", http_build_query(['_csrf' => $t, 'name' => 'Sortie IP', 'kind' => 'ip', 'url' => "$CB/auth.jpg", 'url_kind' => 'snapshot', 'fps' => 4, 'username' => 'cam', 'password' => 'S3cret!', 'use_surveillance' => 1, 'use_caisse' => 1, 'mode' => 'ligne', 'sensitivity' => 5, 'active' => 1]));
        $ipc = DB::one("SELECT * FROM vision_cameras WHERE name = 'Sortie IP'");
        ok($ipc && $ipc['secret'] && !str_contains((string) $ipc['secret'], 'S3cret'), 'mot de passe chiffré en base');
        eq('S3cret!', Addons\Vision\CameraService::decrypt($ipc['secret']), 'mot de passe déchiffrable par le serveur seulement');
        $page = $a->req('GET', "$B/vision/cameras")['body'];
        ok(!str_contains($page, 'S3cret') && !str_contains($page, '/auth.jpg'), 'ni le mot de passe ni le chemin complet affichés');
        preg_match('/name="csrf-token" content="([^"]+)"/', $page, $mm);
        $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $mm[1]];
        $tst = json_decode($a->req('POST', "$B/vision/cameras/{$ipc['id']}/test", json_encode([]), $hdr)['body'], true);
        ok(($tst['ok'] ?? false) && $tst['width'] === 640 && $tst['mime'] === 'image/jpeg', 'essai de connexion : image 640 px reçue (identifiants envoyés) : ' . json_encode($tst));
        $f = $a->req('GET', "$B/vision/cameras/{$ipc['id']}/frame");
        ok($f['code'] === 200 && str_starts_with($f['body'], "\xFF\xD8") && str_contains($f['headers'], 'no-store'), 'image relayée au navigateur (même origine)');
        $src = json_decode($a->req('GET', "$B/vision/sources", null, ['Accept: application/json'])['body'], true);
        ok($src['ok'] && !str_contains(json_encode($src), 'auth.jpg') && !str_contains(json_encode($src), 'secret'), 'liste des caméras sans adresse ni identifiant');
        // Erreurs claires : mauvais mot de passe, redirection, pas une image, MJPEG accepté
        DB::update('vision_cameras', ['secret' => Addons\Vision\CameraService::encrypt('faux')], 'id = ?', [$ipc['id']]);
        $e1 = json_decode($a->req('POST', "$B/vision/cameras/{$ipc['id']}/test", json_encode([]), $hdr)['body'], true);
        ok(!$e1['ok'] && str_contains($e1['error'], 'refuse'), 'mauvais mot de passe signalé');
        foreach (['/redirect' => 'redirige', '/text' => 'JPEG'] as $u => $w) {
            DB::update('vision_cameras', ['url' => $CB . $u], 'id = ?', [$ipc['id']]);
            $e = json_decode($a->req('POST', "$B/vision/cameras/{$ipc['id']}/test", json_encode([]), $hdr)['body'], true);
            ok(!$e['ok'] && str_contains($e['error'], $w), "erreur explicite ($u) : " . ($e['error'] ?? ''));
        }
        DB::update('vision_cameras', ['url' => "$CB/mjpeg", 'url_kind' => 'mjpeg', 'username' => null, 'secret' => null], 'id = ?', [$ipc['id']]);
        $mj = json_decode($a->req('POST', "$B/vision/cameras/{$ipc['id']}/test", json_encode([]), $hdr)['body'], true);
        ok(($mj['ok'] ?? false) && $mj['width'] === 640 && $mj['ms'] < 3000, 'flux MJPEG : première image extraite rapidement');
        // Sécurité : adresses hors réseau local refusées ; identifiants dans l'adresse refusés
        throws(fn() => Addons\Vision\CameraService::checkUrl('http://8.8.8.8/snap.jpg'), 'réseau local');
        throws(fn() => Addons\Vision\CameraService::checkUrl('http://cam:pw@192.168.1.5/x.jpg'), 'identifiant');
        throws(fn() => Addons\Vision\CameraService::checkUrl('file:///etc/passwd'), 'http');
        ok(count(Addons\Vision\CameraService::checkUrl('http://192.168.1.64/ISAPI/Streaming/channels/101/picture')) === 1, 'adresse locale acceptée');
        // Caméra USB / carte d'acquisition : choisie par le navigateur, pas de relais serveur
        $t = $a->token("$B/vision/cameras");
        $a->req('POST', "$B/vision/cameras", http_build_query(['_csrf' => $t, 'name' => 'Carte HDMI', 'kind' => 'carte', 'device_label' => 'USB Video (HDMI)', 'use_surveillance' => 1, 'mode' => 'zone', 'active' => 1]));
        $usb = DB::one("SELECT * FROM vision_cameras WHERE name = 'Carte HDMI'");
        ok($usb && $usb['kind'] === 'carte' && $usb['url'] === null && $usb['mode'] === 'zone', 'carte d\'acquisition enregistrée');
        $uf = $a->req('GET', "$B/vision/cameras/{$usb['id']}/frame");
        ok($uf['code'] === 502 && str_contains($uf['body'], 'navigateur du poste'), 'pas de relais serveur pour une caméra locale (lue par le navigateur)');
        // Tracé de la ligne
        $g = json_decode($a->req('POST', "$B/vision/cameras/{$ipc['id']}/geometry", json_encode(['line' => ['x1' => 0.5, 'y1' => 0, 'x2' => 0.5, 'y2' => 1], 'invert' => true, 'mode' => 'ligne']), $hdr)['body'], true);
        ok($g['ok'] && $g['geometry']['invert'] === true, 'ligne rouge et sens enregistrés');
        eq(422, $a->req('POST', "$B/vision/cameras/{$ipc['id']}/geometry", json_encode(['line' => ['x1' => 0.5, 'y1' => 0.5, 'x2' => 0.51, 'y2' => 0.5]]), $hdr)['code'], 'ligne trop courte refusée');
        // Caissier : ni caméras, ni surveillance ; il lit seulement les caméras « caisse »
        $c = new Http();
        $c->login($B, 'caissier', 'Mot-de-passe-2026');
        eq(403, $c->req('GET', "$B/vision/cameras")['code'], 'caméras : réservé');
        eq(403, $c->req('GET', "$B/vision/surveillance")['code'], 'surveillance : réservée');
        DB::update('vision_cameras', ['use_caisse' => 0], 'id = ?', [$ipc['id']]);
        eq(404, $c->req('GET', "$B/vision/cameras/{$ipc['id']}/frame")['code'], 'caméra non « caisse » : image refusée au caissier');
        DB::update('vision_cameras', ['use_caisse' => 1], 'id = ?', [$ipc['id']]);
        eq(200, $c->req('GET', "$B/vision/cameras/{$ipc['id']}/frame")['code'], 'caméra « caisse » : image disponible pour la reconnaissance');

        // Passages enregistrés par l'écran de surveillance
        $pa = (int) DB::value("SELECT id FROM products WHERE name = 'Inv scan A'");
        $pb = (int) DB::value("SELECT id FROM products WHERE name = 'Inv scan B'");
        DB::run('DELETE FROM vision_events');
        $thumb = 'data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0vignette");
        $sv = $a->req('GET', "$B/vision/surveillance");
        ok($sv['code'] === 200 && str_contains($sv['body'], 'vision-surv.js') && str_contains($sv['body'], 'Sortie IP') && str_contains($sv['body'], 'pas une preuve'), 'écran de surveillance');
        $add = fn(array $d) => json_decode($a->req('POST', "$B/vision/events", json_encode($d + ['camera_id' => $ipc['id'], 'thumb' => $thumb]), $hdr)['body'], true);
        $e = $add(['direction' => 'sortie', 'product_id' => $pa, 'score' => 0.62, 'margin' => 0.2]);
        eq('identifie', $e['status'] ?? null, 'reconnaissance sûre : « identifié »');
        eq('incertain', $add(['direction' => 'sortie', 'product_id' => $pa, 'score' => 0.30, 'margin' => 0.01])['status'], 'reconnaissance faible : « incertain »');
        eq('incertain', $add(['direction' => 'sortie'])['status'], 'sans article : non identifié');
        eq(422, $a->req('POST', "$B/vision/events", json_encode(['camera_id' => $ipc['id'], 'direction' => 'volé']), $hdr)['code'], 'sens inconnu refusé');
        eq(422, $a->req('POST', "$B/vision/events", json_encode(['camera_id' => $usb['id'] + 999, 'direction' => 'sortie']), $hdr)['code'], 'caméra inconnue refusée');

        // Comparaison avec les ventes : rapprochement dans une fenêtre de temps
        DB::run('DELETE FROM vision_events');
        $day = date('Y-m-d', strtotime('-2 days'));
        actingAs('admin');
        if (!DB::value("SELECT id FROM cash_sessions WHERE register_id = 1 AND status = 'open'")) { S\CashService::open(1, 0); }
        $sale = function (int $pid, float $q, string $at) {
            $r = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => $q]], 'payments' => [['method' => 'cash', 'amount' => 100000]]]);
            $sid = $r['id'] ?? $r['sale_id'];
            DB::update('sales', ['created_at' => $at], 'id = ?', [$sid]);
            DB::run("UPDATE stock_movements SET created_at = ? WHERE ref_type = 'sale' AND ref_id = ?", [$at, $sid]);
        };
        DB::run("UPDATE sales SET created_at = created_at - INTERVAL 30 DAY WHERE created_at BETWEEN ? AND ?", ["$day 00:00:00", "$day 23:59:59"]);
        $sale($pa, 2, "$day 10:00:00");     // 2 unités vendues à 10 h
        $sale($pb, 1, "$day 15:00:00");     // B vendu à 15 h
        $pc = (int) DB::value("SELECT id FROM products WHERE name = 'Inv scan C'");
        DB::run('DELETE FROM vision_refs WHERE product_id = ?', [$pc]);
        $sale($pc, 1, "$day 11:00:00");     // C : jamais appris ni filmé
        $ev = fn(int $cid, string $dir, ?int $pid, string $st, string $at) => DB::insert('vision_events', ['camera_id' => $cid, 'direction' => $dir, 'product_id' => $pid, 'score' => 0.6, 'margin' => 0.2, 'status' => $st, 'seen_at' => $at]);
        $ev($ipc['id'], 'sortie', $pa, 'identifie', "$day 10:05:00");   // justifié (vente 10:00)
        $ev($ipc['id'], 'sortie', $pa, 'identifie', "$day 10:12:00");   // justifié (2e unité)
        $ev($ipc['id'], 'sortie', $pa, 'identifie', "$day 10:14:00");   // 3e passage : aucune unité restante → sans vente
        $ev($ipc['id'], 'sortie', $pa, 'incertain', "$day 18:00:00");   // incertain, sans vente
        $ev($ipc['id'], 'entree', $pa, 'identifie', "$day 18:30:00");   // retour : non compté comme sortie
        $ev($ipc['id'], 'sortie', $pb, 'identifie', "$day 16:30:00");   // vente B à 15:00 → hors fenêtre de 20 min
        $ev($ipc['id'], 'sortie', null, 'incertain', "$day 12:00:00");  // non identifié
        $ignored = $ev($ipc['id'], 'sortie', $pb, 'identifie', "$day 12:30:00");
        DB::update('vision_events', ['status' => 'ignore'], 'id = ?', [$ignored]);
        $cmp = Addons\Vision\SurveillanceService::compare($day, $day, [], 20);
        $rA = array_values(array_filter($cmp['rows'], fn($r) => $r['product_id'] === $pa))[0];
        $rB = array_values(array_filter($cmp['rows'], fn($r) => $r['product_id'] === $pb))[0];
        ok($rA['seen'] === 4 && $rA['seen_sure'] === 3 && $rA['returns'] === 1, 'A : 4 sorties vues (3 sûres), 1 retour : ' . json_encode($rA));
        ok($rA['sold'] == 2 && $rA['matched'] === 2 && $rA['unmatched'] === 2 && $rA['unmatched_sure'] === 1, 'A : 2 unités vendues justifient 2 passages ; 1 passage sûr + 1 incertain sans vente');
        ok($rA['level'] === 'alerte' && $rA['gap'] == 2, 'A : à vérifier en priorité, écart +2');
        ok($rB['matched'] === 0 && $rB['unmatched_sure'] === 1 && $rB['sold'] == 1, 'B : vente hors fenêtre → passage non justifié');
        $cmp60 = Addons\Vision\SurveillanceService::compare($day, $day, [], 120);
        $rB2 = array_values(array_filter($cmp60['rows'], fn($r) => $r['product_id'] === $pb))[0];
        ok($rB2['matched'] === 1 && $rB2['level'] === 'ok', 'B : avec une fenêtre de 2 h, le passage est justifié');
        ok($cmp['totals']['unidentified'] === 1 && $cmp['totals']['events'] === 6, 'totaux : 6 sorties (ignoré exclu), 1 non identifiée');
        ok($rA['stock_out'] >= 2 && ($rA['out_types']['sale'] ?? 0) >= 2, 'sorties de stock par type (ventes)');
        eq(67, $cmp['totals']['coverage'], 'couverture : 2 des 3 unités vendues (articles surveillés) vues par la caméra');
        ok(!array_filter($cmp['rows'], fn($r) => $r['product_id'] === $pc), 'article jamais appris ni filmé : hors comparaison par défaut');
        $cAll = Addons\Vision\SurveillanceService::compare($day, $day, [], 20, false);
        ok((bool) array_filter($cAll['rows'], fn($r) => $r['product_id'] === $pc) && $cAll['totals']['coverage'] === 50, 'option « tous les articles » : inclus, couverture 2 / 4 : ' . json_encode($cAll['totals']));
        ok($cmp['by_hour'][10]['seen'] === 3 && $cmp['by_hour'][10]['unmatched'] === 1, 'répartition horaire');
        // Rapport HTTP, exports, revue
        $rp = $a->req('GET', "$B/vision/rapport?from=$day&to=$day&window=20");
        ok($rp['code'] === 200 && str_contains($rp['body'], 'Inv scan A') && str_contains($rp['body'], 'À vérifier en priorité') && str_contains($rp['body'], 'pas des preuves'), 'rapport : comparaison et analyse');
        ok(str_contains($rp['body'], 'passage(s) identifié(s) avec certitude sans vente'), 'analyse rédigée automatiquement');
        $csv = $a->req('GET', "$B/vision/rapport?from=$day&to=$day&window=20&export=csv");
        ok(str_contains($csv['body'], 'Inv scan A') && str_contains($csv['headers'], 'text/csv'), 'export CSV');
        ok(str_starts_with($a->req('GET', "$B/vision/rapport?from=$day&to=$day&export=pdf")['body'], '%PDF'), 'export PDF');
        $unk = (int) DB::value('SELECT id FROM vision_events WHERE product_id IS NULL LIMIT 1');
        $rv = json_decode($a->req('POST', "$B/vision/events/$unk", json_encode(['action' => 'correct', 'product_id' => $pb]), $hdr)['body'], true);
        ok($rv['ok'] && $rv['status'] === 'corrige', 'passage non identifié corrigé');
        $rs = json_decode($a->req('POST', "$B/vision/refs", json_encode(['product_id' => $pb, 'vec' => base64_encode(str_repeat("\x05\xFB", 256)), 'source' => 'surveillance']), $hdr)['body'], true);
        ok($rs['ok'], 'vignette apprise depuis la surveillance');
        $cf = json_decode($a->req('POST', "$B/vision/events/$unk", json_encode(['action' => 'confirm']), $hdr)['body'], true);
        ok($cf['ok'] && $cf['status'] === 'confirme' && (int) DB::value('SELECT product_id FROM vision_events WHERE id = ?', [$unk]) === $pb, 'confirmation du passage corrigé');
        $none = (int) DB::insert('vision_events', ['camera_id' => $ipc['id'], 'direction' => 'sortie', 'status' => 'incertain', 'seen_at' => date('Y-m-d H:i:s')]);
        eq(422, $a->req('POST', "$B/vision/events/$none", json_encode(['action' => 'confirm']), $hdr)['code'], 'confirmer sans article proposé : refusé');
        $ign = json_decode($a->req('POST', "$B/vision/events/$unk", json_encode(['action' => 'ignore']), $hdr)['body'], true);
        ok($ign['status'] === 'ignore', 'passage ignoré (main, personne…)');
        // Conservation : vieux passages effacés, récents gardés
        DB::insert('vision_events', ['camera_id' => $ipc['id'], 'direction' => 'sortie', 'status' => 'incertain', 'seen_at' => date('Y-m-d H:i:s', strtotime('-400 days')), 'thumb' => $thumb]);
        setting_set('vision_surv_conservation', '30');
        ok(Addons\Vision\SurveillanceService::purge() >= 1 && (int) DB::value('SELECT COUNT(*) FROM vision_events') >= 8, 'purge des passages trop anciens seulement');
        // Suppression : caméra avec passages seulement désactivée
        $t = $a->token("$B/vision/cameras");
        $a->req('POST', "$B/vision/cameras/{$ipc['id']}/delete", http_build_query(['_csrf' => $t]));
        ok((int) DB::value('SELECT active FROM vision_cameras WHERE id = ?', [$ipc['id']]) === 0 && DB::value('SELECT COUNT(*) FROM vision_events WHERE camera_id = ?', [$ipc['id']]) > 0, 'caméra désactivée, passages conservés');
        $a->req('POST', "$B/vision/cameras/{$usb['id']}/delete", http_build_query(['_csrf' => $t]));
        ok(!DB::value('SELECT 1 FROM vision_cameras WHERE id = ?', [$usb['id']]), 'caméra sans passage supprimée');
        // Lecteur caméra : choix de la caméra et caméras des modules
        $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/scanner.js');
        ok(str_contains($js, 'enumerateDevices') && str_contains($js, 'addSource') && str_contains($js, 'deviceId: { exact'), 'scanner : choix de la caméra (USB, carte, IP)');
    } finally {
        proc_terminate($cam);
    }
});

test('Module Reconnaissance 1.2 : article isolé de l\'arrière-plan, deux empreintes, annulation, articles confondus', function () use ($B) {
    // Tests JavaScript du détourage (fond appris, ombre, exposition, bords)
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        exec(escapeshellarg($node) . ' ' . escapeshellarg(BASE_PATH . '/tests/js/objet.test.js') . ' 2>&1', $out, $code);
        ok($code === 0 && (bool) preg_match('/(\d+) réussi\(s\), 0 échec/', implode("\n", $out), $m) && (int) $m[1] >= 10, 'détourage : ' . implode(' | ', $out));
    }
    ok(is_file(PUBLIC_PATH . '/assets/addons/vision/vision-objet.js'), 'script de détourage publié');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/vision")];
    $pid = (int) DB::value("SELECT id FROM products WHERE status = 'active' AND sellable = 1 ORDER BY id LIMIT 1");
    $pid2 = (int) DB::value("SELECT id FROM products WHERE status = 'active' AND sellable = 1 AND id > ? ORDER BY id LIMIT 1", [$pid]);
    $vec = base64_encode(str_repeat("\x07\xF9", 256));
    $post = fn($u, $d) => json_decode($a->req('POST', $B . $u, json_encode($d), $hdr)['body'], true);
    $r = $post('/vision/refs', ['product_id' => $pid, 'vec' => $vec, 'source' => 'photo', 'model' => 'mnv2-p512-obj']);
    ok($r['ok'] && $r['id'], 'empreinte « objet isolé » enregistrée');
    eq('mnv2-p512-obj', DB::value('SELECT model FROM vision_refs WHERE id = ?', [$r['id']]));
    $bad = $post('/vision/refs', ['product_id' => $pid, 'vec' => $vec, 'source' => 'photo', 'model' => 'autre-modele']);
    ok(!$bad['ok'], 'type d\'empreinte inconnu refusé');
    $post('/vision/refs', ['product_id' => $pid, 'vec' => $vec, 'source' => 'photo']);
    $idx = json_decode($a->req('GET', "$B/vision/index", null, ['Accept: application/json'])['body'], true);
    ok(count($idx['modes']) === count($idx['pids']) && in_array(1, $idx['modes'], true) && in_array(0, $idx['modes'], true), 'index : nature de chaque empreinte (entière / isolée)');
    ok(strlen(base64_decode($idx['vecs'])) === 512 * count($idx['pids']), 'index cohérent');
    $refs = json_decode($a->req('GET', "$B/vision/refs?product=$pid", null, ['Accept: application/json'])['body'], true);
    ok(in_array(true, array_column($refs['items'], 'obj'), true), 'vignettes : photo « isolée » signalée');
    // Annulation d'un ajout automatique et paires d'articles confondus
    ok($post('/vision/log', ['outcome' => 'annule', 'suggested_id' => $pid])['ok'], 'annulation journalisée');
    $post('/vision/log', ['outcome' => 'annule', 'suggested_id' => $pid]);
    $post('/vision/log', ['outcome' => 'corrige', 'suggested_id' => $pid, 'chosen_id' => $pid2]);
    $post('/vision/log', ['outcome' => 'corrige', 'suggested_id' => $pid2, 'chosen_id' => $pid]);
    $cfg = json_decode($a->req('GET', "$B/vision/config", null, ['Accept: application/json'])['body'], true);
    ok(in_array([min($pid, $pid2), max($pid, $pid2)], $cfg['confusions']['pairs'], true), 'paire confondue 2 fois : écart double exigé');
    ok(in_array($pid, $cfg['confusions']['risky'], true), 'article annulé 2 fois : plus prudent');
    ok(!$post('/vision/log', ['outcome' => 'inconnu'])['ok'], 'résultat inconnu refusé');
    // La caisse sait annuler un ajout (bouton « Annuler » de l'ajout automatique)
    $pos = (string) file_get_contents(PUBLIC_PATH . '/assets/js/pos.js');
    $sc = (string) file_get_contents(PUBLIC_PATH . '/assets/js/scanner.js');
    ok(str_contains($pos, 'onUndo') && str_contains($sc, 'undo: opts && opts.onUndo'), 'annulation transmise par le lecteur caméra à la caisse');
    DB::run("DELETE FROM vision_log WHERE outcome IN ('annule','corrige')");
});

test('MARWA VOICE AI (phase 1) : installation, droits, réglages, historique contrôlé, voix Piper, caisse', function () use ($B) {
    App\Core\Addons::install('marwa_voice', false);
    ok(App\Core\Addons::isActive('marwa_voice'), 'module actif');
    $hasPerm = fn(string $role, string $perm) => (bool) DB::value('SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ? AND p.code = ?', [$role, $perm]);
    ok($hasPerm('cashier', 'marwa_voice.use') && !$hasPerm('cashier', 'marwa_voice.manage') && $hasPerm('store_manager', 'marwa_voice.manage') && !$hasPerm('auditor', 'marwa_voice.use'), 'droits : le caissier entend, le gérant règle, l\'auditeur n\'a pas accès');
    // Cœur JavaScript : nombres en lettres, montants FCFA, file d'annonces
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node !== '') {
        exec(escapeshellarg($node) . ' ' . escapeshellarg(BASE_PATH . '/tests/js/voix.test.js') . ' 2>&1', $out, $code);
        ok($code === 0 && (bool) preg_match('/(\d+) réussi\(s\), 0 échec/', implode("\n", $out), $m) && (int) $m[1] >= 60, 'cœur vocal : ' . implode(' | ', $out));
    }
    foreach (['voix-core.js', 'voix.js', 'voix.css', 'voix-reglages.js'] as $f) {
        ok(is_file(PUBLIC_PATH . '/assets/addons/marwa_voice/' . $f), "$f publié");
    }
    // La caisse émet ses événements ; la politique de sécurité autorise le son généré (blob:)
    $pos = (string) file_get_contents(PUBLIC_PATH . '/assets/js/pos.js');
    foreach (['pos.sale.saved', 'pos.sale.refused', 'pos.sale.unknown', 'pos.print.failed', 'pos.stock.insufficient'] as $ev) {
        ok(str_contains($pos, "'$ev'"), "caisse : événement $ev");
    }
    $h = (new Http())->req('GET', "$B/login")['headers'];
    ok(str_contains($h, "media-src 'self' blob:") && !str_contains($h, 'unsafe-eval'), 'CSP : son local autorisé, rien d\'autre ouvert');

    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $json = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/voix")];
    foreach (['reglages', 'poste', 'diagnostic', 'historique'] as $t) {
        eq(200, $a->req('GET', "$B/voix?tab=$t")['code'], "page /voix?tab=$t");
    }
    $cfg = json_decode($a->req('GET', "$B/voix/config", null, ['Accept: application/json'])['body'], true);
    ok($cfg['ok'] && $cfg['active'] && $cfg['events']['pos.sale.saved']['on'] && !$cfg['events']['pos.product.added']['on'] && $cfg['manage'], 'configuration : ventes lues, ajout d\'article (répétitif) non lu par défaut');
    eq('XOF', $cfg['currency']['code'], 'devise transmise pour lire les montants');

    // Réglages : contrôles du serveur
    $form = fn(array $d) => $a->req('POST', "$B/voix/reglages", http_build_query(['_csrf' => $a->token("$B/voix?tab=reglages")] + $d));
    $form(['voix_actif' => 1, 'voix_moteur' => 'piper; rm -rf /']);
    ok(str_contains($a->req('GET', "$B/voix?tab=reglages")['body'], 'Moteur de voix inconnu'), 'moteur inconnu refusé');
    $form(['voix_actif' => 1, 'voix_moteur' => 'auto', 'voix_silence_debut' => '22:00', 'voix_silence_fin' => '']);
    ok(str_contains($a->req('GET', "$B/voix?tab=reglages")['body'], 'plage de silence'), 'plage de silence incomplète refusée');
    $form(['voix_actif' => 1, 'voix_moteur' => 'navigateur', 'voix_hors_ligne' => 1, 'voix_volume' => '5', 'voix_debit' => '1,2', 'voix_silence_debut' => '22:00', 'voix_silence_fin' => '06:30',
        'voix_critique_en_silence' => 1, 'voix_journal_niveau' => 2, 'voix_journal_jours' => 90,
        'ev_on' => ['pos_sale_saved' => 1, 'pos_print_failed' => 1], 'ev_level' => ['pos_sale_saved' => 1, 'pos_print_failed' => 4]]);
    eq('navigateur', setting('voix_moteur'));
    eq('1', setting('voix_volume'), 'volume borné à 100 %');
    eq('1.2', setting('voix_debit'), 'débit avec virgule accepté');
    eq('22:00-06:30', setting('voix_silence_debut') . '-' . setting('voix_silence_fin'));
    $cfg = json_decode($a->req('GET', "$B/voix/config", null, ['Accept: application/json'])['body'], true);
    ok(!$cfg['events']['page.error']['on'] && $cfg['events']['pos.print.failed']['level'] === 4, 'événements : activation et niveau enregistrés');
    ok((bool) DB::value("SELECT 1 FROM audit_logs WHERE action = 'settings.marwa_voice'"), 'réglages tracés (audit)');

    // Historique envoyé par les postes : contrôlé, filtré par niveau
    $log = fn(array $items) => json_decode($a->req('POST', "$B/voix/journal", json_encode(['items' => $items]), $json)['body'], true);
    DB::run('DELETE FROM marwa_voice_log');
    $r = $log([['event' => 'pos.print.failed', 'level' => 3, 'status' => 'lu', 'text' => "Impression\x07 en échec", 'engine' => 'piper', 'poste' => 'Caisse 1'],
        ['event' => 'pos.sale.saved', 'level' => 1, 'status' => 'lu', 'text' => 'Vente', 'engine' => 'piper'],
        ['event' => 'pos.sale.saved', 'level' => 1, 'status' => 'erreur_voix', 'text' => str_repeat('x', 900), 'engine' => 'bidon']]);
    ok($r['ok'] && $r['saved'] === 2, 'niveau 1 non conservé (réglage : avertissement et plus), sauf erreur de voix');
    $row = DB::one("SELECT * FROM marwa_voice_log WHERE event = 'pos.print.failed'");
    ok($row['text'] === 'Impression en échec' && $row['poste'] === 'Caisse 1' && (int) $row['user_id'] > 0, 'texte nettoyé, poste et utilisateur enregistrés');
    ok(mb_strlen((string) DB::value("SELECT text FROM marwa_voice_log WHERE status = 'erreur_voix'")) === 400 && DB::value("SELECT engine FROM marwa_voice_log WHERE status = 'erreur_voix'") === null, 'texte tronqué, moteur inconnu ignoré');
    ok(!$log([['event' => 'pos.inconnu', 'level' => 1, 'status' => 'lu']])['ok'], 'événement inconnu refusé');
    ok(!$log([['event' => 'pos.sale.saved', 'level' => 9, 'status' => 'lu']])['ok'], 'niveau invalide refusé');
    ok(!$log([['event' => 'pos.sale.saved', 'level' => 1, 'status' => 'piraté']])['ok'], 'résultat invalide refusé');
    ok(!$log(array_fill(0, 51, ['event' => 'pos.sale.saved', 'level' => 3, 'status' => 'lu']))['ok'], 'plus de 50 lignes refusées');
    eq(419, $a->req('POST', "$B/voix/journal", json_encode(['items' => []]), ['Content-Type: application/json', 'Accept: application/json'])['code'], 'sans jeton CSRF : refusé');
    DB::insert('marwa_voice_log', ['event' => 'pos.sale.saved', 'level' => 3, 'status' => 'lu', 'created_at' => date('Y-m-d H:i:s', time() - 200 * 86400)]);
    ok(Addons\MarwaVoice\VoiceService::purge() === 1, 'historique au-delà de la durée de conservation supprimé');

    // Voix Piper : son WAV produit sur le serveur ; le texte n'est jamais interprété comme une commande
    $tts = fn(string $t) => $a->req('POST', "$B/voix/tts", json_encode(['text' => $t]), $json);
    if (Addons\MarwaVoice\PiperService::available()) {
        $r = $tts('Vente enregistrée. Total : douze mille cinq cents francs CFA.');
        ok($r['code'] === 200 && str_starts_with($r['body'], 'RIFF') && str_contains($r['headers'], 'audio/wav'), 'Piper : son WAV reçu (' . strlen($r['body']) . ' octets)');
        $marker = STORAGE_PATH . '/tmp/voix-injection-' . getmypid();
        $r = $tts('"; touch ' . $marker . '; $(touch ' . $marker . ') `touch ' . $marker . '`');
        ok($r['code'] === 200 && !file_exists($marker), 'texte piégé lu comme du texte, aucune commande exécutée');
        $again = $tts('Vente enregistrée. Total : douze mille cinq cents francs CFA.');
        ok(str_contains($again['headers'], 'X-Voix-Via: cache'), 'phrase déjà générée : réutilisée depuis le cache');
    } else {
        $r = $tts('Bonjour.');
        ok($r['code'] === 503 && str_contains($r['body'], 'Piper'), 'Piper absent : erreur claire, le poste utilise sa propre voix');
    }
    eq(503, $tts(str_repeat('a', 401))['code'], 'texte trop long refusé');
    eq(503, $tts("\x01\x02 ")['code'], 'texte vide refusé');

    // Caissier : entend et règle son poste, ne modifie pas les réglages ; auditeur : aucun accès
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    $page = $c->req('GET', "$B/voix?tab=reglages");
    ok($page['code'] === 200 && str_contains($page['body'], 'voixPoste') && !str_contains($page['body'], '/voix/reglages'), 'caissier : réglages de son poste seulement');
    eq(403, $c->req('POST', "$B/voix/reglages", http_build_query(['_csrf' => $c->token("$B/voix"), 'voix_actif' => 0]))['code'], 'caissier : réglages généraux refusés');
    $cc = json_decode($c->req('GET', "$B/voix/config", null, ['Accept: application/json'])['body'], true);
    ok($cc['ok'] && !$cc['manage'], 'caissier : configuration lue');
    $d = new Http();
    $d->login($B, 'auditeur', 'Mot-de-passe-2026');
    eq(403, $d->req('GET', "$B/voix/config", null, ['Accept: application/json'])['code'], 'auditeur : pas d\'annonces');
    eq(403, $d->req('POST', "$B/voix/tts", json_encode(['text' => 'x']), ['Content-Type: application/json', 'X-CSRF-Token: ' . $d->token("$B/account/password")])['code'], 'auditeur : pas de synthèse');

    // Désactivation : pages masquées, données conservées
    App\Core\Addons::setActive('marwa_voice', false);
    eq(404, $a->req('GET', "$B/voix")['code'], 'module désactivé : page masquée');
    ok((int) DB::value('SELECT COUNT(*) FROM marwa_voice_log') > 0, 'historique conservé');
    App\Core\Addons::setActive('marwa_voice', true);
    foreach (['voix_moteur' => 'auto', 'voix_volume' => '0.9', 'voix_debit' => '1.0', 'voix_silence_debut' => '', 'voix_silence_fin' => '', 'voix_evenements' => '{}'] as $k => $v) {
        setting_set($k, $v);
    }
});

test('MARWA VOICE AI 1.1 : voix Piper livrée dans le dossier (fragments vérifiés), cuisine et salle', function () use ($B) {
    // Paquet intégré : fragments ≤ 1 Mo (limites d'envoi de WampServer), reconstitués à l'identique
    $P = Addons\MarwaVoice\PiperService::class;
    $bundle = $P::bundle();
    ok($bundle && isset($bundle['files']['piper/piper.exe'], $bundle['files']['voix/fr-siwis-medium.onnx']), 'paquet : Piper pour Windows et voix française livrés');
    $big = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($P::bundleDir(), FilesystemIterator::SKIP_DOTS)) as $f) {
        $big += $f->getSize() > 1048576 ? 1 : 0;
    }
    eq(0, $big, 'aucun fichier du paquet au-delà de 1 Mo');
    $clear = array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($P::bundleDir(), FilesystemIterator::SKIP_DOTS))), fn($f) => preg_match('/\.(exe|dll|onnx)$/i', $f->getFilename()));
    eq(0, count($clear), 'aucun .exe / .dll / .onnx en clair (rien que l\'antivirus bloque pendant l\'envoi de la mise à jour)');
    $up = (string) file_get_contents(PUBLIC_PATH . '/assets/js/update.js');
    ok(str_contains($up, 'sendBatch') && str_contains($up, '/health') && str_contains($up, 'unreadable'), 'mise à jour : nouvel essai par lots découpés, fichiers illisibles signalés, reprise après coupure pendant l\'installation');
    ok(str_contains((string) file_get_contents(BASE_PATH . '/app/Services/UpdateService.php'), 'ignore_user_abort(true)'), 'installation menée à son terme même si le navigateur est coupé');
    ok(is_file($P::bundleDir() . '/LICENCES.md') && is_file($P::bundleDir() . '/licences/espeak-ng-GPL-3.0.txt'), 'licences des composants livrées');
    $tmp = STORAGE_PATH . '/tmp/voix-paquet-' . getmypid();
    $r = $P::deployBundled($tmp, true);
    ok($r['done'] && $r['deployed'] === count($bundle['files']), 'déploiement Windows simulé : ' . $r['deployed'] . ' fichier(s) reconstitué(s)');
    $okHash = true;
    foreach ($bundle['files'] as $rel => $f) {
        $okHash = $okHash && hash_file('sha256', "$tmp/$rel") === $f['sha256'];
    }
    ok($okHash, 'chaque fichier reconstitué a l\'empreinte SHA-256 attendue');
    eq(0, $P::deployBundled($tmp, true)['deployed'], 'deuxième passage : rien à refaire');
    // Fragment abîmé : refusé, rien n'est remplacé par un fichier faux
    $part = $P::bundleDir() . '/voix/fr-siwis-medium.onnx.part010';
    $orig = (string) file_get_contents($part);
    file_put_contents($part, substr($orig, 0, -1) . chr(ord(substr($orig, -1)) ^ 1));
    try {
        throws(fn() => $P::deployBundled($tmp, true, true), 'altéré');
    } finally {
        file_put_contents($part, $orig);
    }
    ok(hash_file('sha256', "$tmp/voix/fr-siwis-medium.onnx") === $bundle['files']['voix/fr-siwis-medium.onnx']['sha256'], 'voix en place intacte après le refus');
    exec('rm -rf ' . escapeshellarg($tmp));

    // Écran cuisine : scripts des modules chargés, bons « ajout » repérés, annonces au catalogue
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $k = $a->req('GET', "$B/kitchen?station=cuisine");
    ok($k['code'] === 200 && str_contains($k['body'], 'addons/marwa_voice/voix.js'), 'écran cuisine : voix chargée');
    $feed = json_decode($a->req('GET', "$B/kitchen/feed?station=cuisine", null, ['Accept: application/json'])['body'], true);
    ok($feed['ok'] && (!$feed['tickets'] || array_key_exists('previous', $feed['tickets'][0])), 'bons : rang de l\'envoi (nouvelle commande / ajout)');
    $cfg = json_decode($a->req('GET', "$B/voix/config", null, ['Accept: application/json'])['body'], true);
    ok($cfg['events']['kitchen.ticket.new']['on'] && $cfg['events']['kitchen.ticket.cancel']['on'] && $cfg['events']['restaurant.table.ready']['on'], 'cuisine et salle : annonces actives par défaut');
    eq(600, $cfg['events']['kitchen.ticket.new']['ttl'], 'bon de cuisine : jamais écarté avant 10 minutes');
    $js = (string) file_get_contents(PUBLIC_PATH . '/assets/js/kitchen.js') . file_get_contents(PUBLIC_PATH . '/assets/js/restaurant-floor.js');
    foreach (['kitchen.ticket.new', 'kitchen.ticket.cancel', 'kitchen.ticket.repeat', 'restaurant.table.ready'] as $ev) {
        ok(str_contains($js, "'$ev'"), "événement $ev émis");
    }
});

test('Mise à jour du logiciel : modules actifs mis à jour automatiquement (jamais d\'erreur 500 sur leurs nouveaux écrans)', function () use ($B) {
    // Situation signalée : fichiers 2.2 copiés, module « vision » encore en 1.0.0 dans la base
    DB::update('addons', ['version' => '1.0.0'], 'code = ?', ['vision']);
    DB::run("DELETE FROM schema_migrations WHERE version = 'addon:vision:0002_cameras_surveillance'");
    DB::run('SET FOREIGN_KEY_CHECKS = 0');
    DB::run('DROP TABLE IF EXISTS vision_events');
    DB::run('DROP TABLE IF EXISTS vision_cameras');
    DB::run('SET FOREIGN_KEY_CHECKS = 1');
    @unlink(STORAGE_PATH . '/cache/addon_upgrade_failed_vision');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    foreach (['/vision/surveillance', '/vision/rapport', '/vision/cameras'] as $u) {
        eq(200, $a->req('GET', $B . $u)['code'], "$u après copie des fichiers");
    }
    eq('1.2.0', DB::value("SELECT version FROM addons WHERE code = 'vision'"), 'module mis à jour automatiquement');
    ok((bool) DB::value("SHOW TABLES LIKE 'vision_events'") && (bool) DB::value("SHOW TABLES LIKE 'vision_cameras'"), 'nouvelles tables créées');

    // Mise à jour d'un module impossible : ses écrans sont masqués (404), jamais une erreur 500
    $dir = App\Core\Addons::dir() . '/zz_maj_test';
    @mkdir($dir . '/migrations', 0775, true);
    file_put_contents("$dir/manifest.json", json_encode(['code' => 'zz_maj_test', 'name' => 'Essai mise à jour', 'version' => '1.0.0', 'menu' => [['group' => 'Configuration', 'label' => 'Essai MAJ', 'url' => '/zz-maj']]]));
    file_put_contents("$dir/routes.php", "<?php \$router->get('/zz-maj', [App\\Controllers\\MobileController::class, 'landing'], null);");
    try {
        App\Core\Addons::reset();
        App\Core\Addons::install('zz_maj_test', false);
        eq(200, $a->req('GET', "$B/zz-maj")['code'], 'module d\'essai installé');
        file_put_contents("$dir/manifest.json", json_encode(['code' => 'zz_maj_test', 'name' => 'Essai mise à jour', 'version' => '1.1.0', 'menu' => [['group' => 'Configuration', 'label' => 'Essai MAJ', 'url' => '/zz-maj']]]));
        file_put_contents("$dir/migrations/0001_casse.sql", 'CREATE TABLE zz_casse (id TYPE_INCONNU);');
        $r = $a->req('GET', "$B/zz-maj");
        eq(404, $r['code'], 'mise à jour du module impossible : écran masqué au lieu d\'une erreur 500');
        ok(is_file(STORAGE_PATH . '/cache/addon_upgrade_failed_zz_maj_test'), 'échec mémorisé (nouvel essai dans 10 minutes)');
        ok(!str_contains($a->req('GET', "$B/dashboard")['body'], 'Essai MAJ'), 'entrée de menu masquée en attendant');
        ok(str_contains($a->req('GET', "$B/addons")['body'], 'Essai mise à jour'), 'module visible dans Modules (« Mettre à jour »)');
    } finally {
        DB::run("DELETE FROM addons WHERE code = 'zz_maj_test'");
        DB::run("DELETE FROM schema_migrations WHERE version LIKE 'addon:zz_maj_test:%'");
        @unlink(STORAGE_PATH . '/cache/addon_upgrade_failed_zz_maj_test');
        foreach (['migrations/0001_casse.sql', 'manifest.json', 'routes.php'] as $f) { @unlink("$dir/$f"); }
        @rmdir("$dir/migrations"); @rmdir($dir);
        App\Core\Addons::reset();
    }
});

test('Module Établissement : installation, année, classes, inscriptions, échéances, caisse, reçus, salaires', function () {
    App\Core\Addons::install('ecole', false);
    ok(App\Core\Addons::isActive('ecole'), 'module actif');
    $hasPerm = fn(string $role, string $perm) => (bool) DB::value('SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ? AND p.code = ?', [$role, $perm]);
    ok($hasPerm('cashier', 'ecole.caisse') && !$hasPerm('cashier', 'ecole.finance') && !$hasPerm('cashier', 'ecole.biometrie') && $hasPerm('ecole_direction', 'ecole.biometrie') && $hasPerm('ecole_enseignant', 'ecole.notes'), 'rôles et droits');
    // Reconnaissance faciale livrée en local : fragments ≤ 1 Mo, descriptions cohérentes
    $dir = PUBLIC_PATH . '/assets/addons/ecole';
    ok(is_file("$dir/face-api.js") && is_file("$dir/ecole-pointage.js") && is_file("$dir/ecole.css"), 'scripts publiés');
    foreach (['detecteur', 'reperes', 'empreinte'] as $m) {
        $man = json_decode((string) file_get_contents("$dir/modeles/$m.txt"), true);
        $need = 0; $have = 0;
        foreach ($man[0]['weights'] as $w) { $need += ($w['quantization']['dtype'] ?? $w['dtype']) === 'uint8' ? (int) array_product($w['shape'] ?: [1]) : 4 * (int) array_product($w['shape'] ?: [1]); }
        foreach ($man[0]['paths'] as $f) { ok(is_file("$dir/modeles/$f") && filesize("$dir/modeles/$f") <= 1024 * 1024, "fragment $f ≤ 1 Mo"); $have += filesize("$dir/modeles/$f"); }
        eq($need, $have, "modèle $m complet");
    }
    actingAs('admin');
    throws(fn() => Addons\Ecole\Ecole::creerAnnee('X', '2026-09-01', '2026-01-01', 'trimestre', true), 'invalide');
    $an = Addons\Ecole\Ecole::creerAnnee('2026-2027', date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+270 days')), 'trimestre', true);
    eq(3, count(Addons\Ecole\Ecole::periodes($an)), '3 trimestres créés');
    $c = Addons\Ecole\Ecole::sauverClasse(['nom' => 'CM2 A', 'cycle' => 'primaire', 'capacite' => 3, 'frais_inscription' => '10 000', 'frais_scolarite' => '90000', 'nb_tranches' => 3]);
    throws(fn() => Addons\Ecole\Ecole::sauverClasse(['nom' => 'CM2 A']), 'existe déjà');
    $maths = Addons\Ecole\Ecole::sauverMatiere('Mathématiques', 'MATH');
    $fr = Addons\Ecole\Ecole::sauverMatiere('Français', 'FR');
    $prof = Addons\Ecole\Personnes::sauverPersonnel(['nom' => 'Issa', 'prenom' => 'Moussa', 'fonction' => 'enseignant', 'salaire' => '150000']);
    Addons\Ecole\Ecole::programme($c, [['matiere_id' => $maths, 'coefficient' => 4, 'enseignant_id' => $prof], ['matiere_id' => $fr, 'coefficient' => '3']]);
    eq(2, (int) DB::value('SELECT COUNT(*) FROM ecole_classe_matieres WHERE classe_id = ?', [$c]), 'programme : 2 matières');

    // Fiches : consentement facial tracé (qui l'a donné), matricule automatique
    throws(fn() => Addons\Ecole\Personnes::sauverEleve(['nom' => 'Sani', 'prenom' => 'Aïcha', 'sexe' => 'F', 'consentement_visage' => '1']), 'indiquez qui');
    $e1 = Addons\Ecole\Personnes::sauverEleve(['nom' => 'Sani', 'prenom' => 'Aïcha', 'sexe' => 'F', 'tuteur_nom' => 'Sani Ali', 'tuteur_tel' => '96 00 00 01', 'consentement_visage' => '1', 'consentement_par' => 'Sani Ali (père)']);
    $e2 = Addons\Ecole\Personnes::sauverEleve(['nom' => 'Garba', 'prenom' => 'Ibrahim', 'sexe' => 'M']);
    $e3 = Addons\Ecole\Personnes::sauverEleve(['nom' => 'Hamani', 'prenom' => 'Zeinab', 'sexe' => 'F']);
    $e4 = Addons\Ecole\Personnes::sauverEleve(['nom' => 'Oumarou', 'prenom' => 'Adamou', 'sexe' => 'M']);
    ok(str_starts_with((string) DB::value('SELECT matricule FROM ecole_eleves WHERE id = ?', [$e1]), 'EL'), 'matricule automatique');
    eq('SANI', DB::value('SELECT nom FROM ecole_eleves WHERE id = ?', [$e1]), 'nom en majuscules');
    eq(1, count(Addons\Ecole\Personnes::chercherEleves('96000001')), 'recherche par téléphone du parent');

    // Inscription : échéances (inscription + scolarité en 3 tranches, remise déduite), doublon, capacité
    throws(fn() => Addons\Ecole\Finance::inscrire($e1, $c, ['remise' => 95000]), 'dépasse');
    $i1 = Addons\Ecole\Finance::inscrire($e1, $c, ['remise' => 1000, 'frais' => [['libelle' => 'Tenue', 'montant' => '5000']]]);
    $ech = Addons\Ecole\Finance::echeances($i1);
    eq(['Frais d\'inscription', 'Scolarité – tranche 1 / 3', 'Scolarité – tranche 2 / 3', 'Scolarité – tranche 3 / 3', 'Tenue'], array_column($ech, 'libelle'), 'échéances');
    eq([10000.0, 29666.0, 29666.0, 29668.0, 5000.0], array_map('floatval', array_column($ech, 'montant')), 'tranches rondes, la dernière reçoit le reste');
    throws(fn() => Addons\Ecole\Finance::inscrire($e1, $c, []), 'déjà inscrit');
    $i2 = Addons\Ecole\Finance::inscrire($e2, $c, ['sans_frais_inscription' => 1]);
    eq(90000.0, Addons\Ecole\Finance::solde($i2)['du'], 'sans frais d\'inscription');
    $i3 = Addons\Ecole\Finance::inscrire($e3, $c, []);
    throws(fn() => Addons\Ecole\Finance::inscrire($e4, $c, []), 'complète');

    // Caisse : session de caisse exigée, idempotence, répartition, dépassement, annulation
    if (!S\CashService::currentSession(Auth::user())) {
        throws(fn() => Addons\Ecole\Finance::encaisser($i1, ['uuid' => uuid(), 'montant' => 1000]), 'caisse est fermée');
        S\CashService::open((int) DB::value('SELECT id FROM registers ORDER BY id LIMIT 1 OFFSET 1') ?: 1, 0);
    }
    throws(fn() => Addons\Ecole\Finance::encaisser($i1, ['montant' => 1000]), 'Identifiant');
    throws(fn() => Addons\Ecole\Finance::encaisser($i1, ['uuid' => uuid(), 'montant' => 200000]), 'dépasse');
    $u = uuid();
    $p1 = Addons\Ecole\Finance::encaisser($i1, ['uuid' => $u, 'montant' => '25 000', 'mode' => 'cash']);
    ok(str_starts_with($p1['numero'], 'REC'), 'numéro de reçu');
    $p1b = Addons\Ecole\Finance::encaisser($i1, ['uuid' => $u, 'montant' => '25000']);
    ok(!empty($p1b['deja']) && $p1b['id'] === $p1['id'], 'double clic : un seul reçu');
    eq([10000.0, 15000.0, 0.0], array_map('floatval', array_slice(array_column(Addons\Ecole\Finance::echeances($i1), 'paye'), 0, 3)), 'les plus anciennes d\'abord');
    $tenue = (int) DB::value("SELECT id FROM ecole_echeances WHERE inscription_id = ? AND libelle = 'Tenue'", [$i1]);
    throws(fn() => Addons\Ecole\Finance::encaisser($i1, ['uuid' => uuid(), 'montant' => 6000, 'echeances' => [$tenue]]), 'échéances choisies');
    Addons\Ecole\Finance::encaisser($i1, ['uuid' => uuid(), 'montant' => 5000, 'echeances' => [$tenue]]);
    eq(5000.0, (float) DB::value('SELECT paye FROM ecole_echeances WHERE id = ?', [$tenue]), 'échéance choisie réglée');
    eq(30000.0, (float) DB::value("SELECT SUM(amount) FROM cash_movements WHERE type = 'ecole' AND ref_type = 'ecole_paiement'"), 'encaissements en caisse');
    throws(fn() => Addons\Ecole\Finance::annuler($p1['id'], ''), 'Motif');
    Addons\Ecole\Finance::annuler($p1['id'], 'Erreur de saisie');
    eq(5000.0, Addons\Ecole\Finance::solde($i1)['paye'], 'annulation : montants de nouveau dus');
    eq(-25000.0, (float) DB::value("SELECT amount FROM cash_movements WHERE type = 'ecole_annulation'"), 'contre-passation en caisse');
    throws(fn() => Addons\Ecole\Finance::annuler($p1['id'], 'encore'), 'déjà annulé');
    Addons\Ecole\Finance::encaisser($i1, ['uuid' => uuid(), 'montant' => 10000]);
    $imp = array_column(Addons\Ecole\Finance::impayes(), 'echu', 'eleve_id');
    eq(29666.0, (float) $imp[$e1], 'impayé échu : tranche 1');
    eq(30000.0, (float) $imp[$e2], 'impayé échu sans frais d\'inscription');
    $r = Addons\Ecole\Finance::rapport(date('Y-m-d'), date('Y-m-d'));
    eq(15000.0, $r['recettes'], 'recettes du jour (reçu annulé exclu)');

    // Salaires : un seul paiement par mois (sauf complément), annulation
    $s1 = Addons\Ecole\Finance::payerSalaire($prof, date('Y-m'), []);
    eq(150000.0, (float) DB::value('SELECT montant FROM ecole_salaires WHERE id = ?', [$s1['id']]), 'salaire de la fiche par défaut');
    throws(fn() => Addons\Ecole\Finance::payerSalaire($prof, date('Y-m'), ['montant' => 1000]), 'déjà payé');
    Addons\Ecole\Finance::payerSalaire($prof, date('Y-m'), ['montant' => 20000, 'complement' => 1]);
    eq(-170000.0, (float) DB::value("SELECT SUM(amount) FROM cash_movements WHERE type = 'ecole_salaire'"), 'salaires sortis de la caisse');
    Addons\Ecole\Finance::annulerSalaire($s1['id'], 'Erreur de mois');
    eq(20000.0, Addons\Ecole\Finance::rapport(date('Y-m-d'), date('Y-m-d'))['salaires'], 'salaire annulé exclu du rapport');

    // Abandon : les montants non payés ne sont plus dus, rien n'est effacé
    Addons\Ecole\Finance::abandon($i3, 'abandon');
    eq(0.0, Addons\Ecole\Finance::solde($i3)['reste'], 'abandon : plus rien de dû');
    ok((int) DB::value('SELECT COUNT(*) FROM ecole_echeances WHERE inscription_id = ?', [$i3]) > 0, 'échéances conservées (annulées)');
});

test('Module Établissement : notes, moyennes pondérées, rangs ex æquo, bulletins, clôture', function () {
    actingAs('admin');
    $c = (int) DB::value("SELECT id FROM ecole_classes WHERE nom = 'CM2 A'");
    [$maths, $fr] = [(int) DB::value("SELECT id FROM ecole_matieres WHERE code = 'MATH'"), (int) DB::value("SELECT id FROM ecole_matieres WHERE code = 'FR'")];
    $per = Addons\Ecole\Ecole::periodes((int) DB::value('SELECT annee_id FROM ecole_classes WHERE id = ?', [$c]));
    [$e1, $e2] = [(int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'SANI'"), (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'GARBA'")];
    $e3 = (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'HAMANI'");
    throws(fn() => Addons\Ecole\Notes::creerEvaluation(['classe_id' => $c, 'matiere_id' => 999999, 'periode_id' => $per[0]['id']]), 'pas au programme');
    $d1 = Addons\Ecole\Notes::creerEvaluation(['classe_id' => $c, 'matiere_id' => $maths, 'periode_id' => $per[0]['id'], 'type' => 'devoir', 'bareme' => 10, 'coefficient' => 1]);
    $d2 = Addons\Ecole\Notes::creerEvaluation(['classe_id' => $c, 'matiere_id' => $maths, 'periode_id' => $per[0]['id'], 'type' => 'composition', 'bareme' => 20, 'coefficient' => 2]);
    $d3 = Addons\Ecole\Notes::creerEvaluation(['classe_id' => $c, 'matiere_id' => $fr, 'periode_id' => $per[0]['id'], 'bareme' => 20]);
    throws(fn() => Addons\Ecole\Notes::saisir($d1, [$e1 => '11']), 'entre 0 et 10');
    Addons\Ecole\Notes::saisir($d1, [$e1 => '8', $e2 => '6,5', $e3 => '10']); // e3 a abandonné : ignoré
    eq(0, (int) DB::value('SELECT COUNT(*) FROM ecole_notes WHERE eleve_id = ?', [$e3]), 'élève parti : aucune note');
    Addons\Ecole\Notes::saisir($d2, [$e1 => '14', $e2 => 'abs']);
    Addons\Ecole\Notes::saisir($d3, [$e1 => '12', $e2 => '15']);
    $r = Addons\Ecole\Notes::resultats($c, (int) $per[0]['id']);
    // e1 : maths = (16×1 + 14×2) / 3 = 14,67 ; français 12 → (14,67×4 + 12×3) / 7 = 13,53
    // (calculée sur les moyennes de matière arrondies, comme les colonnes « moy. × coef. » imprimées)
    eq(14.67, $r['eleves'][$e1]['matieres'][$maths], 'moyenne de matière (barème ramené sur 20, coefficients)');
    eq(13.53, $r['eleves'][$e1]['moyenne'], 'moyenne générale pondérée');
    // e2 : absence ignorée → maths 13, français 15 → (13×4 + 15×3) / 7 = 13,86
    eq(13.86, $r['eleves'][$e2]['moyenne'], 'absence ignorée par défaut');
    eq(1, $r['eleves'][$e2]['rang']); eq(2, $r['eleves'][$e1]['rang']);
    setting_set('ecole_absence_zero', '1');
    $r0 = Addons\Ecole\Notes::resultats($c, (int) $per[0]['id']);
    eq(4.33, $r0['eleves'][$e2]['matieres'][$maths], 'absence comptée 0 si l\'option est choisie');
    setting_set('ecole_absence_zero', '0');
    // e2 : note du devoir retirée, absent à la composition → plus aucune note de maths
    Addons\Ecole\Notes::saisir($d3, [$e1 => '12', $e2 => '9.75']);
    Addons\Ecole\Notes::saisir($d1, [$e2 => '']);
    $r = Addons\Ecole\Notes::resultats($c, (int) $per[0]['id']);
    eq(null, $r['eleves'][$e2]['matieres'][$maths], 'note retirée et absence : matière non évaluée');
    eq(9.75, $r['eleves'][$e2]['moyenne'], 'moyenne sur les seules matières évaluées');
    eq('Tableau d\'honneur', Addons\Ecole\Notes::distinction(13.53));
    eq('Passable', Addons\Ecole\Notes::appreciation(10.0));
    // Ex æquo
    Addons\Ecole\Notes::saisir($d1, [$e2 => '8']); Addons\Ecole\Notes::saisir($d2, [$e2 => '14']); Addons\Ecole\Notes::saisir($d3, [$e2 => '12']);
    $r = Addons\Ecole\Notes::resultats($c, (int) $per[0]['id']);
    ok($r['eleves'][$e1]['rang'] === 1 && $r['eleves'][$e2]['rang'] === 1 && $r['eleves'][$e1]['ex_aequo'], 'ex æquo : même rang');
    eq(2, $r['stat']['classes']); eq(13.53, $r['stat']['moy'], 'statistiques de classe');
    $ins1 = (int) DB::value('SELECT id FROM ecole_inscriptions WHERE eleve_id = ?', [$e1]);
    Addons\Ecole\Notes::sauverBulletin($ins1, (int) $per[0]['id'], ['appreciation' => 'Bon trimestre', 'decision' => 'Admis(e)']);
    eq('Bon trimestre', DB::value('SELECT appreciation FROM ecole_bulletins WHERE inscription_id = ?', [$ins1]));
    // Année entière = moyenne des périodes évaluées
    eq(13.53, Addons\Ecole\Notes::resultats($c, null)['eleves'][$e1]['moyenne'], 'moyenne annuelle');
    // Période clôturée : notes verrouillées
    DB::update('ecole_periodes', ['cloturee' => 1], 'id = ?', [$per[0]['id']]);
    throws(fn() => Addons\Ecole\Notes::saisir($d1, [$e1 => '9']), 'clôturée');
    throws(fn() => Addons\Ecole\Notes::creerEvaluation(['classe_id' => $c, 'matiere_id' => $maths, 'periode_id' => $per[0]['id']]), 'clôturée');
    DB::update('ecole_periodes', ['cloturee' => 0], 'id = ?', [$per[0]['id']]);
});

test('Module Établissement : appel, pointage caméra (arrivée, retard, départ), inconnus, données faciales et consentement', function () {
    actingAs('admin');
    [$e1, $e2] = [(int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'SANI'"), (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'GARBA'")];
    $prof = (int) DB::value("SELECT id FROM ecole_personnel WHERE nom = 'ISSA'");
    throws(fn() => Addons\Ecole\Presences::appel('eleve', [$e2 => 'absent'], date('Y-m-d', strtotime('+1 day'))), 'future');
    eq(1, Addons\Ecole\Presences::appel('eleve', [$e2 => 'absent', $e1 => 'n_importe'], date('Y-m-d')), 'statut inconnu ignoré');
    setting_set('ecole_heure_debut', '00:00'); setting_set('ecole_tolerance', '0');
    $pt = DB::insert('ecole_points', ['nom' => 'Portail', 'role' => 'passage', 'actif' => 1]);
    $a = Addons\Ecole\Presences::pointer('personnel', $prof, 'passage', $pt, 0.41, 0.2);
    ok($a['evenement'] === 'arrivee' && $a['statut'] === 'retard' && $a['personne']['nom'] === 'Moussa ISSA', 'arrivée en retard, fiche renvoyée');
    eq('deja', Addons\Ecole\Presences::pointer('personnel', $prof, 'passage', $pt)['evenement'], 'passage moins de 30 min après : pas de départ');
    eq('depart', Addons\Ecole\Presences::pointer('personnel', $prof, 'sortie', $pt)['evenement'], 'point de sortie : départ');
    // L'appel manuel « absent » est corrigé par la caméra (la personne est bien venue)
    $b = Addons\Ecole\Presences::pointer('eleve', $e2, 'entree', $pt);
    ok($b['evenement'] === 'arrivee' && $b['statut'] === 'retard', 'appel corrigé par la caméra');
    eq(2, (int) DB::value("SELECT COUNT(*) FROM ecole_passages WHERE statut = 'reconnu' AND personne_type = 'personnel'") - 1, 'passages journalisés');
    // Visage inconnu → revue : attribution à la bonne personne (pointée à l'heure du passage)
    $inc = Addons\Ecole\Presences::inconnu('entree', $pt, 'data:image/jpeg;base64,AAAA');
    $rv = Addons\Ecole\Presences::revue($inc, 'attribuer', 'eleve', $e1);
    eq('confirme', $rv['statut']);
    ok((bool) DB::value("SELECT arrivee FROM ecole_presences WHERE personne_type = 'eleve' AND personne_id = ? AND date = CURDATE()", [$e1]), 'élève pointé à l\'heure du passage');
    $j = Addons\Ecole\Presences::jour(date('Y-m-d'));
    eq(0, (int) $j['classes'][0]['non_pointes'], 'tous pointés');
    setting_set('ecole_heure_debut', '07:30'); setting_set('ecole_tolerance', '10');

    // Données faciales : désactivées par défaut, consentement obligatoire, empreintes validées
    $v = function (int $seed, int $bruit = 0): string {
        mt_srand($seed); $x = [];
        for ($i = 0; $i < 128; $i++) { $x[] = (mt_rand() / mt_getrandmax() - 0.5) * 0.3; }
        if ($bruit) { mt_srand($bruit); foreach ($x as $k => $y) { $x[$k] = $y + (mt_rand() / mt_getrandmax() - 0.5) * 0.02; } }
        return base64_encode(pack('g*', ...$x));
    };
    throws(fn() => Addons\Ecole\Biometrie::enregistrer('eleve', $e1, [$v(1)]), 'désactivée');
    setting_set('ecole_visage_actif', '1');
    throws(fn() => Addons\Ecole\Biometrie::enregistrer('eleve', $e2, [$v(2)]), 'Consentement');
    throws(fn() => Addons\Ecole\Biometrie::enregistrer('eleve', $e1, [base64_encode(str_repeat("\0", 100))]), 'invalide');
    throws(fn() => Addons\Ecole\Biometrie::enregistrer('eleve', $e1, [base64_encode(pack('g*', ...array_fill(0, 128, NAN)))]), 'invalide');
    throws(fn() => Addons\Ecole\Biometrie::enregistrer('eleve', $e1, [base64_encode(pack('g*', ...array_fill(0, 128, 0.0)))]), 'vide');
    $v0 = Addons\Ecole\Biometrie::version();
    eq(2, Addons\Ecole\Biometrie::enregistrer('eleve', $e1, [$v(1), $v(1, 7)]), '2 empreintes');
    ok(Addons\Ecole\Biometrie::version() > $v0, 'version de la galerie incrémentée (postes de pointage rafraîchis)');
    // Limite par personne : les photos d'inscription ne sont jamais évincées par l'apprentissage au pointage
    for ($k = 0; $k < 14; $k++) { Addons\Ecole\Biometrie::enregistrer('eleve', $e1, [$v(1, 100 + $k)], 'pointage'); }
    eq(12, Addons\Ecole\Biometrie::nombre('eleve', $e1), 'au plus 12 empreintes');
    eq(2, (int) DB::value("SELECT COUNT(*) FROM ecole_visages WHERE personne_id = ? AND source = 'inscription'", [$e1]), 'empreintes d\'inscription gardées');
    $g = Addons\Ecole\Biometrie::galerie();
    eq(12 * 512, strlen(base64_decode($g['empreintes'])), 'galerie : 128 nombres × 4 octets par empreinte');
    // Retrait du consentement : effacement immédiat
    Addons\Ecole\Personnes::sauverEleve(['nom' => 'Sani', 'prenom' => 'Aïcha', 'sexe' => 'F'], $e1);
    eq(0, Addons\Ecole\Biometrie::nombre('eleve', $e1), 'consentement retiré : visage effacé');
    Addons\Ecole\Personnes::sauverEleve(['nom' => 'Sani', 'prenom' => 'Aïcha', 'sexe' => 'F', 'consentement_visage' => '1', 'consentement_par' => 'Sani Ali (père)'], $e1);
    Addons\Ecole\Biometrie::enregistrer('eleve', $e1, [$v(1)]);
    // Clôture de la journée : les inscrits non pointés deviennent absents (sans écraser l'appel)
    $e4 = (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'OUMAROU'");
    Addons\Ecole\Finance::inscrire($e4, (int) DB::value("SELECT id FROM ecole_classes WHERE nom = 'CM2 A'"), []);
    eq(1, Addons\Ecole\Presences::cloturerJour(date('Y-m-d')), 'un absent ajouté');
    eq('retard', DB::value("SELECT statut FROM ecole_presences WHERE personne_type = 'eleve' AND personne_id = ? AND date = CURDATE()", [$e2]), 'pointage conservé');
    // Vignettes : conservation limitée
    DB::insert('ecole_passages', ['role' => 'entree', 'statut' => 'inconnu', 'thumb' => 'data:image/jpeg;base64,AAAA', 'vu_a' => date('Y-m-d H:i:s', strtotime('-90 days'))]);
    ok(Addons\Ecole\Presences::purgerVignettes() >= 1 && !DB::value("SELECT 1 FROM ecole_passages WHERE vu_a < NOW() - INTERVAL 60 DAY"), 'anciens inconnus effacés');
});

test('Mode Établissement : caisse de l\'établissement, aucun menu commercial, pages et impressions', function () use ($B) {
    actingAs('admin');
    S\BusinessMode::set('ecole');
    ok(S\BusinessMode::hides('/products') && S\BusinessMode::hides('/sales') && S\BusinessMode::hides('/vision') && !S\BusinessMode::hides('/vision/cameras') && !S\BusinessMode::hides('/expenses'), 'menus commerciaux masqués, caméras et dépenses gardées');
    eq('/ecole/caisse', S\BusinessMode::posUrl());
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    ok(str_contains($a->req('GET', "$B/")['headers'], '/ecole'), 'accueil : établissement');
    ok(str_contains($a->req('GET', "$B/pos")['headers'], '/ecole/caisse'), 'caisse : caisse de l\'établissement');
    ok(str_contains($a->req('GET', "$B/dashboard")['headers'], '/ecole'), 'tableau de bord : celui de l\'établissement');
    $home = $a->req('GET', "$B/ecole")['body'];
    ok(str_contains($home, 'Établissement') && !str_contains($home, 'Gestion des articles') && !str_contains($home, 'Achats &amp; fournisseurs') && !str_contains($home, '>Ventes<'), 'menu sans termes commerciaux');
    $pe = strpos($home, 'data-cat="Établissement"');
    $pc = strpos($home, 'data-cat="Configuration"');
    ok($pe !== false && $pc !== false && $pe < $pc, 'groupe Établissement en tête');
    ok((bool) preg_match('#side-cat open" data-cat="Établissement"#', $home), 'catégorie de la page courante ouverte');
    ok(!preg_match('#side-cat open" data-cat="Configuration"#', $home), 'autres catégories repliées');
    ok((bool) preg_match('#/ecole/caisse" class="btn btn-pos-launch"#', $home), 'bouton « Ouvrir la caisse » → caisse de l\'établissement');
    $c = (int) DB::value("SELECT id FROM ecole_classes WHERE nom = 'CM2 A'");
    $per = (int) DB::value('SELECT id FROM ecole_periodes ORDER BY id LIMIT 1');
    $e1 = (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'SANI'");
    $prof = (int) DB::value("SELECT id FROM ecole_personnel WHERE nom = 'ISSA'");
    $ev = (int) DB::value('SELECT id FROM ecole_evaluations ORDER BY id LIMIT 1');
    $rec = (int) DB::value('SELECT id FROM ecole_paiements WHERE annule = 0 ORDER BY id LIMIT 1');
    $sal = (int) DB::value('SELECT id FROM ecole_salaires ORDER BY id LIMIT 1');
    foreach (['/ecole', '/ecole/caisse', "/ecole/caisse?eleve=$e1", '/ecole/eleves', '/ecole/eleves?classe=' . $c, '/ecole/eleves/nouveau', "/ecole/eleves/$e1", "/ecole/eleves/$e1/modifier", '/ecole/classes', "/ecole/classes/$c",
              '/ecole/personnel', '/ecole/personnel/nouveau', "/ecole/personnel/$prof", "/ecole/personnel/$prof/modifier", '/ecole/notes', "/ecole/notes?classe=$c&periode=$per", "/ecole/evaluations/$ev",
              '/ecole/bulletins', "/ecole/bulletins?classe=$c&periode=annee", '/ecole/presences', "/ecole/presences/classe/$c", '/ecole/presences/personnel', '/ecole/presences/rapport', '/ecole/pointage',
              '/ecole/passages', '/ecole/passages?f=tous', '/ecole/comptabilite', '/ecole/impayes', '/ecole/salaires', '/ecole/parametres', '/cash', '/expenses', '/menu'] as $u) {
        $r = $a->req('GET', $B . $u);
        eq(200, $r['code'], "page $u");
        ok(!str_contains($r['body'], 'Une erreur interne'), "page $u sans erreur");
    }
    // Impressions : carte, cartes de classe, reçu (ticket et A4), bulletins (élève, classe, année), bulletin de paie
    $bul = $a->req('GET', "$B/ecole/bulletins/imprimer?classe=$c&periode=$per&eleve=$e1");
    ok($bul['code'] === 200 && str_contains($bul['body'], 'SANI') && str_contains($bul['body'], 'Moyenne générale') && str_contains($bul['body'], '13,53') && str_contains($bul['body'], 'Bon trimestre') && str_contains($bul['body'], 'ex æquo'), 'bulletin de l\'élève');
    $tous = $a->req('GET', "$B/ecole/bulletins/imprimer?classe=$c&periode=$per")['body'];
    eq(3, substr_count($tous, 'class="sheet bul"'), 'bulletins de toute la classe (une page par élève)');
    ok(str_contains($bul['body'], 'Moyenne ≥ 10 :'), 'seuil d\'admission imprimé « 10 » (et non « 1 »)');
    eq(['10', '2.5', '4', '0.75'], array_map([Addons\Ecole\Ecole::class, 'nb'], [10.0, '2.50', '4.00', 0.75]), 'nombres sans zéros inutiles');
    ok(str_contains($a->req('GET', "$B/ecole/bulletins/imprimer?classe=$c&periode=annee")['body'], 'Bulletin annuel'), 'bulletin annuel');
    foreach (["/ecole/eleves/$e1/carte", "/ecole/classes/$c/cartes", "/ecole/personnel/$prof/carte", "/ecole/recus/$rec", "/ecole/recus/$rec?format=a4", "/ecole/salaires/$sal"] as $u) {
        eq(200, $a->req('GET', $B . $u)['code'], "impression $u");
    }
    $ra4 = $a->req('GET', "$B/ecole/recus/$rec?format=a4")['body'];
    ok(str_contains($ra4, 'SANI') && str_contains($ra4, 'CM2 A') && str_contains($ra4, 'class="sheet'), 'reçu A4');
    foreach (['/ecole/eleves?export=csv', '/ecole/comptabilite?export=csv', '/ecole/impayes?export=csv', "/ecole/bulletins?classe=$c&periode=$per&export=csv", '/ecole/presences/rapport?export=csv'] as $u) {
        $r = $a->req('GET', $B . $u);
        ok($r['code'] === 200 && str_contains($r['headers'], 'text/csv'), "export $u");
    }
    ok(str_starts_with($a->req('GET', "$B/ecole/impayes?export=pdf")['body'], '%PDF'), 'export PDF des impayés');
    // Caisse (JSON) : dossier, paiement idempotent
    $hdr = ['Accept: application/json'];
    $d = json_decode($a->req('GET', "$B/ecole/caisse/eleve/$e1", null, $hdr)['body'], true);
    ok($d['ok'] && $d['inscription']['classe'] === 'CM2 A' && count($d['echeances']) === 5 && $d['solde']['reste'] > 0, 'dossier de caisse');
    $t = $a->token("$B/ecole/caisse");
    $u = uuid();
    $pay = fn() => json_decode($a->req('POST', "$B/ecole/caisse/paiement", http_build_query(['_csrf' => $t, 'inscription_id' => $d['inscription']['id'], 'uuid' => $u, 'montant' => '1000', 'mode' => 'cash']), $hdr)['body'], true);
    $p1 = $pay(); $p2 = $pay();
    ok($p1['ok'] && $p2['ok'] && $p1['id'] === $p2['id'] && !empty($p2['deja']), 'paiement depuis la caisse : un seul reçu même envoyé deux fois');
    $s = json_decode($a->req('GET', "$B/ecole/eleves/recherche?q=sani", null, $hdr)['body'], true);
    ok($s['ok'] && $s['items'][0]['id'] === $e1, 'recherche dans la caisse');
    // Inscription depuis la caisse (nouvel élève + premier versement), reçu affiché
    $r = $a->req('POST', "$B/ecole/caisse/inscription", http_build_query(['_csrf' => $t, 'nom' => 'Moussa', 'prenom' => 'Fati', 'sexe' => 'F', 'classe_id' => $c, 'uuid' => uuid(), 'premier_paiement' => '10000', 'mode' => 'cash']));
    ok($r['code'] === 302 && !str_contains($r['headers'], 'recu=') && !DB::value("SELECT 1 FROM ecole_eleves WHERE nom = 'MOUSSA'"), 'classe complète : rien n\'est créé (ni fiche, ni reçu)');
    DB::update('ecole_classes', ['capacite' => null], 'id = ?', [$c]);
    $r = $a->req('POST', "$B/ecole/caisse/inscription", http_build_query(['_csrf' => $t, 'nom' => 'Moussa', 'prenom' => 'Fati', 'sexe' => 'F', 'classe_id' => $c, 'uuid' => uuid(), 'premier_paiement' => '10000', 'mode' => 'cash']));
    ok($r['code'] === 302 && str_contains($r['headers'], 'recu='), 'inscription + premier versement depuis la caisse');
    ok((bool) DB::value("SELECT 1 FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id WHERE e.nom = 'MOUSSA'"), 'élève inscrit');
    // Pointage : événement envoyé par le poste, galerie des visages
    $g = json_decode($a->req('GET', "$B/ecole/visages/galerie", null, $hdr)['body'], true);
    ok($g['ok'] && strlen(base64_decode($g['empreintes'])) === 512 * count($g['ids']) && count($g['ids']) >= 1, 'galerie des empreintes');
    $tp = $a->token("$B/ecole/pointage");
    $ev = json_decode($a->req('POST', "$B/ecole/pointage/evenement", http_build_query(['_csrf' => $tp, 'type' => 'eleve', 'id' => $e1, 'role' => 'entree', 'distance' => '0.38', 'marge' => '0.21', 'thumb' => 'data:image/jpeg;base64,AAAA']), $hdr)['body'], true);
    ok($ev['ok'] && $ev['personne']['id'] === $e1, 'pointage enregistré');
    $bad = $a->req('POST', "$B/ecole/pointage/evenement", http_build_query(['_csrf' => $tp, 'type' => 'eleve', 'id' => $e1, 'thumb' => 'javascript:alert(1)']), $hdr);
    eq(422, $bad['code'], 'vignette non JPEG refusée');
    // Droits : le caissier encaisse mais ne voit ni la galerie des visages, ni l'annulation, ni les notes
    $k = new Http();
    $k->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(200, $k->req('GET', "$B/ecole/caisse")['code'], 'caissier : caisse de l\'établissement');
    eq(403, $k->req('GET', "$B/ecole/visages/galerie", null, $hdr)['code'], 'caissier : pas d\'accès aux visages');
    eq(403, $k->req('GET', "$B/ecole/notes")['code'], 'caissier : pas d\'accès aux notes');
    $tk = $k->token("$B/ecole/caisse");
    eq(403, $k->req('POST', "$B/ecole/recus/$rec/annuler", http_build_query(['_csrf' => $tk, 'reason' => 'essai']))['code'], 'caissier : annulation de reçu refusée');
    // Retour au mode commerce : rien n'est perdu
    S\BusinessMode::set('commerce');
    ok(!S\BusinessMode::hides('/products'), 'mode commerce rétabli');
});

test('Module Établissement (HTTP) : tous les formulaires enregistrent réellement', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/ecole/parametres");
    $post = fn(string $u, array $d) => $a->req('POST', $B . $u, http_build_query(['_csrf' => $t] + $d));
    $flash = fn() => $a->req('GET', "$B/ecole")['body'];
    $hdr = ['Accept: application/json'];
    // Paramètres (heure invalide refusée), année, période, point de pointage
    $post('/ecole/parametres', ['ecole_type' => 'secondaire', 'ecole_heure_debut' => '25:00', 'ecole_heure_fin' => '17:00']);
    eq('mixte', DB::value("SELECT svalue FROM settings WHERE skey = 'ecole_type'") ?: 'mixte', 'heure invalide : rien enregistré');
    $post('/ecole/parametres', ['ecole_type' => 'universite', 'ecole_heure_debut' => '08:00', 'ecole_heure_fin' => '18:00', 'ecole_tolerance' => '5', 'ecole_seuil_passage' => '10', 'ecole_visage_actif' => '1', 'ecole_visage_seuil' => '0.9', 'ecole_visage_marge' => '0.05', 'ecole_conservation' => '30', 'ecole_devise' => 'Travail – Discipline']);
    eq('universite', DB::value("SELECT svalue FROM settings WHERE skey = 'ecole_type'"), 'type d\'établissement');
    eq('0.7', DB::value("SELECT svalue FROM settings WHERE skey = 'ecole_visage_seuil'"), 'seuil de reconnaissance borné à 0,70 (jamais trop permissif)');
    ok(str_contains($a->req('GET', "$B/ecole/eleves")['body'], 'Étudiant'), 'université : « étudiants »');
    $post('/ecole/annees', ['libelle' => '2027-2028', 'debut' => '2027-10-01', 'fin' => '2028-07-31', 'decoupage' => 'semestre']);
    $an2 = (int) DB::value("SELECT id FROM ecole_annees WHERE libelle = '2027-2028'");
    ok($an2 && !DB::value('SELECT courante FROM ecole_annees WHERE id = ?', [$an2]) && (int) DB::value('SELECT COUNT(*) FROM ecole_periodes WHERE annee_id = ?', [$an2]) === 2, 'année future en 2 semestres, non courante');
    $p2 = (int) DB::value('SELECT id FROM ecole_periodes WHERE annee_id = ? ORDER BY ordre LIMIT 1', [$an2]);
    $post("/ecole/periodes/$p2", ['nom' => 'S1', 'debut' => '2027-10-01', 'fin' => '2028-02-15', 'cloturee' => '1']);
    eq(['S1', 1], array_values(DB::one('SELECT nom, cloturee FROM ecole_periodes WHERE id = ?', [$p2])), 'période modifiée et clôturée');
    $post('/ecole/points', ['nom' => 'Entrée bâtiment B', 'role' => 'entree']);
    $pt = (int) DB::value("SELECT id FROM ecole_points WHERE nom = 'Entrée bâtiment B'");
    ok($pt > 0, 'point de pointage créé');
    $post("/ecole/points/$pt/delete", []);
    ok(!DB::value('SELECT 1 FROM ecole_points WHERE id = ? AND actif = 1', [$pt]), 'point supprimé');
    // Classes, matières, programme
    $post('/ecole/matieres', ['nom' => 'Sciences physiques', 'code' => 'PC']);
    $pc = (int) DB::value("SELECT id FROM ecole_matieres WHERE nom = 'Sciences physiques'");
    $post('/ecole/classes', ['nom' => 'Licence 1 Info', 'cycle' => 'superieur', 'frais_inscription' => '25000', 'frais_scolarite' => '300000', 'nb_tranches' => '2']);
    $l1 = (int) DB::value("SELECT id FROM ecole_classes WHERE nom = 'Licence 1 Info'");
    ok($l1 > 0, 'classe créée');
    $post("/ecole/classes/$l1", ['nom' => 'Licence 1 Informatique', 'cycle' => 'superieur', 'frais_inscription' => '25000', 'frais_scolarite' => '300000', 'nb_tranches' => '3', 'active' => '1']);
    eq(['Licence 1 Informatique', 3], array_values(DB::one('SELECT nom, nb_tranches FROM ecole_classes WHERE id = ?', [$l1])), 'classe modifiée');
    $post("/ecole/classes/$l1/programme", ['lignes' => [['matiere_id' => $pc, 'coefficient' => '2', 'credits' => '6']]]);
    eq(6.0, (float) DB::value('SELECT credits FROM ecole_classe_matieres WHERE classe_id = ? AND matiere_id = ?', [$l1, $pc]), 'programme avec crédits');
    // Étudiant : fiche + inscription + visages (consentement) en une fois ; photo envoyée par la caméra
    $png = 'data:image/png;base64,' . base64_encode((function () { $im = imagecreatetruecolor(60, 60); imagefilledrectangle($im, 0, 0, 59, 59, imagecolorallocate($im, 200, 120, 40)); ob_start(); imagepng($im); return ob_get_clean(); })());
    $fp = fn(int $s) => base64_encode(pack('g*', ...array_map(fn($i) => sin($i * $s) * 0.1, range(1, 128))));
    $r = $post('/ecole/eleves', ['nom' => 'Tanko', 'prenom' => 'Halima', 'sexe' => 'F', 'classe_id' => $l1, 'type' => 'nouvelle', 'photo_data' => $png,
        'consentement_visage' => '1', 'consentement_par' => 'Elle-même (majeure)', 'visages' => json_encode([$fp(3), $fp(5)])]);
    $et = (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'TANKO'");
    ok($et && str_contains($r['headers'], '/ecole/eleves/' . $et), 'fiche créée');
    eq(2, Addons\Ecole\Biometrie::nombre('eleve', $et), 'visages enregistrés avec la fiche');
    ok((bool) DB::value('SELECT photo FROM ecole_eleves WHERE id = ?', [$et]), 'photo enregistrée');
    $ph = $a->req('GET', "$B/ecole/photo/eleve/$et");
    ok($ph['code'] === 200 && str_contains($ph['headers'], 'image/jpeg'), 'photo ré-encodée en JPEG et servie après contrôle des droits');
    eq(302, (new Http())->req('GET', "$B/ecole/photo/eleve/$et")['code'], 'photo inaccessible sans connexion');
    $ins = (int) DB::value('SELECT id FROM ecole_inscriptions WHERE eleve_id = ?', [$et]);
    eq(325000.0, Addons\Ecole\Finance::solde($ins)['du'], 'inscrite en Licence 1 avec ses frais');
    // Échec en cours de route (visage invalide) : ni fiche, ni inscription (tout ou rien)
    $post('/ecole/eleves', ['nom' => 'Erreur', 'prenom' => 'Test', 'sexe' => 'M', 'classe_id' => $l1, 'consentement_visage' => '1', 'consentement_par' => 'Lui-même', 'visages' => json_encode(['abc'])]);
    ok(!DB::value("SELECT 1 FROM ecole_eleves WHERE nom = 'ERREUR'"), 'tout ou rien : aucune fiche partielle');
    // Modification, frais, changement de classe, abandon
    $post("/ecole/eleves/$et", ['nom' => 'Tanko', 'prenom' => 'Halima', 'sexe' => 'F', 'tuteur_tel' => '90 11 22 33', 'consentement_visage' => '1', 'consentement_par' => 'Elle-même (majeure)']);
    eq('90 11 22 33', DB::value('SELECT tuteur_tel FROM ecole_eleves WHERE id = ?', [$et]), 'fiche modifiée');
    eq(2, Addons\Ecole\Biometrie::nombre('eleve', $et), 'visages gardés (consentement maintenu)');
    $post("/ecole/inscriptions/$ins/frais", ['libelle' => 'Bibliothèque', 'montant' => '7500']);
    eq(7500.0, (float) DB::value("SELECT montant FROM ecole_echeances WHERE inscription_id = ? AND libelle = 'Bibliothèque'", [$ins]), 'frais ajoutés');
    $c = (int) DB::value("SELECT id FROM ecole_classes WHERE nom = 'CM2 A'");
    $post("/ecole/inscriptions/$ins/classe", ['classe_id' => $c]);
    eq($c, (int) DB::value('SELECT classe_id FROM ecole_inscriptions WHERE id = ?', [$ins]), 'changement de classe');
    $post("/ecole/inscriptions/$ins/abandon", ['statut' => 'abandon']);
    eq('abandon', DB::value('SELECT statut FROM ecole_inscriptions WHERE id = ?', [$ins]), 'départ enregistré');
    // Effacement des données faciales à la demande
    $post('/ecole/visages/supprimer', ['type' => 'eleve', 'id' => $et]);
    eq(0, Addons\Ecole\Biometrie::nombre('eleve', $et), 'données faciales effacées');
    $rv = json_decode($a->req('POST', "$B/ecole/visages", http_build_query(['_csrf' => $t, 'type' => 'eleve', 'id' => $et, 'source' => 'pointage', 'empreintes' => [$fp(7)]]), $hdr)['body'], true);
    ok($rv['ok'] && $rv['total'] === 1, 'apprentissage au pointage (consentement présent)');
    // Inscription d'un élève existant depuis sa fiche
    $e4 = (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'OUMAROU'");
    $nv = Addons\Ecole\Personnes::sauverEleve(['nom' => 'Bako', 'prenom' => 'Yacouba', 'sexe' => 'M']);
    $post("/ecole/eleves/$nv/inscrire", ['classe_id' => $l1, 'type' => 'reinscription']);
    ok((bool) DB::value("SELECT 1 FROM ecole_inscriptions WHERE eleve_id = ? AND type = 'reinscription'", [$nv]), 'inscription depuis la fiche');
    // Personnel : création + modification
    $post('/ecole/personnel', ['nom' => 'Mahamadou', 'prenom' => 'Rabi', 'fonction' => 'administration', 'poste' => 'Secrétaire', 'salaire' => '90000']);
    $sec = (int) DB::value("SELECT id FROM ecole_personnel WHERE nom = 'MAHAMADOU'");
    ok($sec > 0, 'membre du personnel créé');
    $post("/ecole/personnel/$sec", ['nom' => 'Mahamadou', 'prenom' => 'Rabi', 'fonction' => 'administration', 'poste' => 'Secrétaire principale', 'salaire' => '95000']);
    eq('Secrétaire principale', DB::value('SELECT poste FROM ecole_personnel WHERE id = ?', [$sec]), 'fiche du personnel modifiée');
    // Notes : évaluation, saisie, appréciations, suppression d'une évaluation vide
    $per = (int) DB::value('SELECT p.id FROM ecole_periodes p JOIN ecole_annees a ON a.id = p.annee_id WHERE a.courante = 1 ORDER BY p.ordre LIMIT 1');
    $maths = (int) DB::value("SELECT id FROM ecole_matieres WHERE code = 'MATH'");
    $r = $post('/ecole/evaluations', ['classe_id' => $c, 'periode_id' => $per, 'matiere_id' => $maths, 'type' => 'interrogation', 'titre' => 'Interro 2', 'bareme' => '20', 'coefficient' => '1']);
    $ev = (int) DB::value("SELECT id FROM ecole_evaluations WHERE titre = 'Interro 2'");
    ok($ev && str_contains($r['headers'], "/ecole/evaluations/$ev"), 'évaluation créée → saisie des notes');
    $e1 = (int) DB::value("SELECT id FROM ecole_eleves WHERE nom = 'SANI'");
    $post("/ecole/evaluations/$ev", ['notes' => [$e1 => '17,5']]);
    eq(17.5, (float) DB::value('SELECT note FROM ecole_notes WHERE evaluation_id = ? AND eleve_id = ?', [$ev, $e1]), 'note enregistrée (virgule acceptée)');
    $post("/ecole/evaluations/$ev", ['notes' => [$e1 => '25']]);
    eq(17.5, (float) DB::value('SELECT note FROM ecole_notes WHERE evaluation_id = ? AND eleve_id = ?', [$ev, $e1]), 'note hors barème refusée');
    $post("/ecole/evaluations/$ev/delete", []);
    ok((bool) DB::value('SELECT 1 FROM ecole_evaluations WHERE id = ?', [$ev]), 'évaluation notée : suppression refusée');
    $post("/ecole/evaluations/$ev", ['notes' => [$e1 => '']]);
    $post("/ecole/evaluations/$ev/delete", []);
    ok(!DB::value('SELECT 1 FROM ecole_evaluations WHERE id = ?', [$ev]), 'évaluation vide supprimée');
    $i1 = (int) DB::value('SELECT id FROM ecole_inscriptions WHERE eleve_id = ?', [$e1]);
    $post('/ecole/bulletins/appreciations', ['classe_id' => $c, 'periode_id' => $per, 'b' => [$i1 => ['appreciation' => 'Très bon travail', 'conduite' => 'Bonne', 'decision' => 'Félicitations']]]);
    eq('Très bon travail', DB::value('SELECT appreciation FROM ecole_bulletins WHERE inscription_id = ? AND periode_id = ?', [$i1, $per]), 'appréciation du conseil');
    // Appel de classe et du personnel, clôture
    $hier = date('Y-m-d', strtotime('-1 day'));
    $post("/ecole/presences/classe/$c", ['date' => $hier, 'statut' => [$e1 => 'excuse'], 'note' => [$e1 => 'Malade']]);
    eq(['excuse', 'Malade'], array_values(DB::one("SELECT statut, note FROM ecole_presences WHERE personne_type = 'eleve' AND personne_id = ? AND date = ?", [$e1, $hier])), 'appel de la veille');
    $post('/ecole/presences/personnel', ['date' => $hier, 'statut' => [$sec => 'present']]);
    ok((bool) DB::value("SELECT 1 FROM ecole_presences WHERE personne_type = 'personnel' AND personne_id = ? AND date = ?", [$sec, $hier]), 'présence du personnel');
    $post('/ecole/presences/cloturer', ['date' => $hier]);
    ok((int) DB::value("SELECT COUNT(*) FROM ecole_presences WHERE personne_type = 'eleve' AND date = ? AND statut = 'absent'", [$hier]) >= 1, 'clôture de la veille');
    eq('excuse', DB::value("SELECT statut FROM ecole_presences WHERE personne_type = 'eleve' AND personne_id = ? AND date = ?", [$e1, $hier]), 'clôture sans écraser l\'appel');
    // Revue d'un passage inconnu
    $inc = Addons\Ecole\Presences::inconnu('passage', null, null);
    $j = json_decode($a->req('POST', "$B/ecole/passages/$inc", http_build_query(['_csrf' => $t, 'action' => 'ignorer']), $hdr)['body'], true);
    ok($j['ok'] && $j['statut'] === 'ignore', 'passage ignoré');
    // Salaires et reçus : versement, annulation (motif), annulation de reçu
    $post('/ecole/salaires', ['personnel_id' => $sec, 'periode' => date('Y-m'), 'montant' => '95000', 'mode' => 'cash']);
    $s = (int) DB::value('SELECT id FROM ecole_salaires WHERE personnel_id = ? AND annule = 0', [$sec]);
    ok($s > 0, 'salaire versé');
    eq(200, $a->req('GET', "$B/ecole/salaires/$s")['code'], 'bulletin de paie');
    $post("/ecole/salaires/$s/annuler", ['reason' => 'Mauvais montant']);
    eq(1, (int) DB::value('SELECT annule FROM ecole_salaires WHERE id = ?', [$s]), 'salaire annulé');
    $rec = (int) DB::value('SELECT id FROM ecole_paiements WHERE annule = 0 ORDER BY id DESC LIMIT 1');
    $post("/ecole/recus/$rec/annuler", ['reason' => 'Chèque refusé']);
    eq(['1', 'Chèque refusé'], array_map('strval', array_values(DB::one('SELECT annule, motif_annulation FROM ecole_paiements WHERE id = ?', [$rec]))), 'reçu annulé avec motif');
    // Année courante
    $post("/ecole/annees/$an2/courante", []);
    eq(1, (int) DB::value('SELECT courante FROM ecole_annees WHERE id = ?', [$an2]), 'changement d\'année');
    eq(1, (int) DB::value('SELECT COUNT(*) FROM ecole_annees WHERE courante = 1'), 'une seule année courante');
    eq(200, $a->req('GET', "$B/ecole/caisse")['code'], 'caisse sans classes pour la nouvelle année');
    eq(200, $a->req('GET', "$B/ecole/notes")['code'], 'notes sans classes pour la nouvelle année');
    eq(200, $a->req('GET', "$B/ecole/bulletins")['code'], 'bulletins sans classes pour la nouvelle année');
    setting_set('ecole_visage_actif', '0');
});

test('MARWA REMOTE ASSIST : droits, réglages, observation / contrôle autorisé, kits, jetons, paquet RustDesk', function () use ($B) {
    App\Core\Addons::install('marwa_assist', false);
    ok(App\Core\Addons::isActive('marwa_assist'), 'module actif');
    $hasPerm = fn(string $role, string $perm) => (bool) DB::value('SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ? AND p.code = ?', [$role, $perm]);
    ok($hasPerm('cashier', 'assist.request') && !$hasPerm('cashier', 'assist.control') && !$hasPerm('cashier', 'assist.manage'), 'caissier : demande l\'assistance, n\'autorise pas le contrôle');
    ok($hasPerm('store_manager', 'assist.control') && $hasPerm('store_manager', 'assist.manage'), 'gérant : autorise le contrôle et gère les postes');
    $AS = Addons\MarwaAssist\AssistService::class;
    $S = function () use ($AS): array { setting('__reset'); return $AS::settings(); }; // réglages relus (écrits par le serveur HTTP)

    // Paquet : RustDesk officiel en fragments ≤ 1 Mo, aucun programme en clair, empreintes vérifiées
    $B_ = Addons\MarwaAssist\Bundle::class;
    $m = $B_::manifest();
    foreach (['client/rustdesk.exe', 'serveur/windows/hbbs.exe', 'serveur/windows/hbbr.exe', 'serveur/linux/hbbs', 'serveur/linux/hbbr'] as $f) {
        ok(isset($m['files'][$f]), "paquet : $f");
    }
    $files = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($B_::dir(), FilesystemIterator::SKIP_DOTS)));
    eq(0, count(array_filter($files, fn($f) => $f->getSize() > 1048576)), 'aucun fragment au-delà de 1 Mo');
    eq(0, count(array_filter($files, fn($f) => preg_match('/\.(exe|dll)$/i', $f->getFilename()))), 'aucun .exe / .dll en clair');
    ok(is_file($B_::dir() . '/LICENCES.md') && is_file($B_::dir() . '/licences/rustdesk-AGPL-3.0.txt'), 'licence AGPL-3.0 et sources indiquées');
    $tmp = STORAGE_PATH . '/tmp/assist-paquet-' . getmypid();
    $r = $B_::deploy('serveur/linux/', $tmp, true);
    ok($r['done'] && $r['deployed'] === 2 && hash_file('sha256', "$tmp/serveur/linux/hbbr") === $m['files']['serveur/linux/hbbr']['sha256'], 'serveur Linux reconstitué à l\'identique');
    $part = $B_::dir() . '/serveur/linux/hbbr.part000';
    $orig = (string) file_get_contents($part);
    file_put_contents($part, chr(ord($orig[0]) ^ 1) . substr($orig, 1));
    try {
        throws(fn() => $B_::deploy('serveur/linux/', $tmp, true), 'altéré');
    } finally {
        file_put_contents($part, $orig);
    }
    ok(hash_file('sha256', "$tmp/serveur/linux/hbbr") === $m['files']['serveur/linux/hbbr']['sha256'], 'fragment altéré refusé, programme en place intact');
    exec('rm -rf ' . escapeshellarg($tmp));

    // Réglages (gérant) : adresse, clé, accès sans surveillance désactivé par défaut
    $g = new Http();
    $g->login($B, 'gerant', 'Mot-de-passe-2026');
    $form = fn(array $d) => $g->req('POST', "$B/assist/reglages", http_build_query(['_csrf' => $g->token("$B/assist?tab=reglages")] + $d));
    $key = base64_encode(random_bytes(32));
    eq(false, $S()['unattended'], 'accès sans surveillance désactivé par défaut');
    foreach (['http://1.2.3.4:21116' => 'invalide', '127.0.0.1' => 'pas « 127.0.0.1 »', 'localhost' => 'pas « localhost »'] as $host => $msg) {
        $form(['assist_serveur_adresse' => $host, 'assist_serveur_cle' => $key]);
        ok(str_contains(html_entity_decode($g->req('GET', "$B/assist?tab=reglages")['body']), $msg), "adresse « $host » refusée");
    }
    $form(['assist_serveur_adresse' => '192.0.2.10', 'assist_serveur_cle' => 'pas-une-cle']);
    ok(str_contains(html_entity_decode($g->req('GET', "$B/assist?tab=reglages")['body']), 'Clé publique invalide'), 'clé mal copiée refusée');
    $form(['assist_serveur_adresse' => '192.0.2.10', 'assist_serveur_cle' => $key, 'assist_sans_surveillance' => 1, 'confirmation' => 'oui']);
    ok(!$S()['unattended'], 'sans surveillance refusé sans la phrase de confirmation');
    $form(['assist_serveur_adresse' => '192.0.2.10', 'assist_serveur_cle' => $key, 'assist_controle_minutes' => 15, 'assist_kit_jours' => 7]);
    eq(['192.0.2.10', $key, false], [$S()['host'], $S()['key'], $S()['unattended']], 'réglages enregistrés');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('POST', "$B/assist/reglages", http_build_query(['_csrf' => $c->token("$B/assist"), 'assist_serveur_adresse' => '6.6.6.6']))['code'], 'caissier : réglages interdits');
    eq('192.0.2.10', $S()['host']);

    // Kit du poste : jeton secret, script réglé (consentement par clic, clavier coupé, fenêtre jamais masquée)
    DB::run('UPDATE assist_interventions SET status = \'terminee\' WHERE status IN (\'demandee\',\'observation\',\'controle\')');
    actingAs('gerant');
    $k = $AS::createKit('boutique', 'Caisse test', 'http://192.0.2.20/pos');
    $z = new ZipArchive();
    ok($z->open($k['path']) === true, 'kit : archive ZIP');
    $bat = (string) $z->getFromName('installer-assistance.bat');
    ok(hash('sha256', (string) $z->getFromName('rustdesk.exe')) === $m['files']['client/rustdesk.exe']['sha256'], 'kit : RustDesk officiel non modifié');
    ok($z->locateName('LICENCE-RustDesk-AGPL-3.0.txt') !== false && $z->locateName('LISEZMOI.txt') !== false, 'kit : licence et notice');
    $z->close();
    @unlink($k['path']);
    foreach (['approve-mode click', 'verification-method use-temporary-password', 'enable-keyboard N', 'enable-file-transfer N', 'allow-hide-cm N', "key $key", 'relay-server 192.0.2.10'] as $opt) {
        ok(str_contains($bat, '--option ' . $opt), "script : $opt");
    }
    ok(!str_contains($bat, '--password') && str_contains($bat, "\r\n") && mb_check_encoding($bat, 'ASCII'), 'script : aucun mot de passe permanent, ASCII, fins de ligne Windows');
    ok((bool) preg_match('#/assist/enregistrer\?jeton=([a-f0-9]{48})#', $bat, $tm), 'script : transmet l\'identifiant avec un jeton');
    $tok = $tm[1];
    eq(hash('sha256', $tok), DB::value('SELECT token_hash FROM assist_postes WHERE id = ?', [$k['poste']]), 'jeton conservé haché seulement');
    $reg = fn(string $t, string $id) => (new Http())->req('GET', "$B/assist/enregistrer?jeton=$t&id=" . rawurlencode($id) . "&poste=PC-TEST");
    eq(403, $reg(str_repeat('a', 48), '123456789')['code'], 'faux jeton refusé');
    eq(403, $reg($tok, 'abc')['code'], 'identifiant non numérique refusé');
    $ok = $reg($tok, '123 456 789');
    ok($ok['code'] === 200 && str_contains($ok['body'], 'Caisse test'), 'poste enregistré par le script (sans session)');
    eq('123456789', DB::value('SELECT rustdesk_id FROM assist_postes WHERE id = ?', [$k['poste']]));
    DB::update('assist_postes', ['token_expires' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$k['poste']]);
    eq(403, $reg($tok, '123456789')['code'], 'kit expiré refusé');
    $AS::revokePoste($k['poste']);
    ok(DB::value('SELECT token_hash FROM assist_postes WHERE id = ?', [$k['poste']]) === null, 'poste retiré : jeton effacé');
    // Kit raté (fichier du module manquant) : aucun ZIP oublié, poste créé aussitôt retiré
    $lic = Addons\MarwaAssist\Bundle::dir() . '/licences/rustdesk-AGPL-3.0.txt';
    rename($lic, "$lic.bak");
    $zips = count(glob(STORAGE_PATH . '/tmp/kit-assistance-*.zip') ?: []);
    try {
        throws(fn() => $AS::createKit('boutique', 'Kit rate', 'http://192.0.2.20'), 'incomplets');
    } finally {
        rename("$lic.bak", $lic);
    }
    eq($zips, count(glob(STORAGE_PATH . '/tmp/kit-assistance-*.zip') ?: []), 'kit raté : aucun fichier laissé');
    eq(0, (int) DB::value("SELECT COUNT(*) FROM assist_postes WHERE label = 'Kit rate'"), 'kit raté : aucun poste créé');
    $kt = $AS::createKit('technicien', 'Tech test', 'http://192.0.2.20');
    $z->open($kt['path']);
    $tb = (string) $z->getFromName('demarrer-technicien.bat');
    $z->close();
    @unlink($kt['path']);
    ok(str_contains($tb, "echo key = '$key'") && str_contains($tb, 'RustDesk2.toml.avant-marwa'), 'kit technicien : réglé sur le serveur HTN, ancienne configuration sauvegardée');

    // Intervention : demande, partage en observation, contrôle autorisé par un responsable, fin
    $post = fn(Http $h, string $u, array $d = []) => $h->req('POST', $B . $u, http_build_query(['_csrf' => $h->token("$B/assist")] + $d));
    $post($c, '/assist/demande', ['reason' => 'x']);
    eq(0, (int) DB::value("SELECT COUNT(*) FROM assist_interventions WHERE status IN ('demandee','observation','controle')"), 'motif trop court refusé');
    $post($c, '/assist/demande', ['reason' => 'Le tiroir-caisse ne s\'ouvre plus']);
    $id = (int) DB::value("SELECT id FROM assist_interventions WHERE status = 'demandee' ORDER BY id DESC LIMIT 1");
    ok($id > 0, 'demande enregistrée');
    $post($c, '/assist/demande', ['reason' => 'Deuxième demande']);
    eq(1, (int) DB::value("SELECT COUNT(*) FROM assist_interventions WHERE status IN ('demandee','observation','controle')"), 'une seule assistance à la fois par poste');
    $post($c, "/assist/$id/partager");
    eq('observation', DB::value('SELECT status FROM assist_interventions WHERE id = ?', [$id]), 'écran partagé en observation');
    $st = json_decode($c->req('GET', "$B/assist/etat", null, ['Accept: application/json'])['body'], true);
    ok($st['ok'] && $st['active'][0]['status'] === 'observation' && $st['active'][0]['seconds_left'] === null, 'bandeau : état « observé »');
    $post($c, "/assist/$id/controle", ['username' => 'gerant', 'secret' => 'mauvais', 'minutes' => 10]);
    $post($c, "/assist/$id/controle", ['username' => 'caissier', 'secret' => 'Mot-de-passe-2026', 'minutes' => 10]);
    eq('observation', DB::value('SELECT status FROM assist_interventions WHERE id = ?', [$id]), 'contrôle refusé : mauvais code, puis caissier sans droit');
    eq(2, (int) DB::value("SELECT COUNT(*) FROM assist_events WHERE intervention_id = ? AND action = 'controle_refuse'", [$id]), 'tentatives refusées inscrites dans l\'historique');
    ok(!str_contains((string) DB::value("SELECT GROUP_CONCAT(details) FROM assist_events WHERE intervention_id = ?", [$id]), 'mauvais'), 'aucun mot de passe dans l\'historique');
    $post($c, "/assist/$id/controle", ['username' => 'gerant', 'secret' => '4826', 'minutes' => 60]);
    $row = DB::one('SELECT status, control_by, control_until FROM assist_interventions WHERE id = ?', [$id]);
    ok($row['status'] === 'controle' && (int) $row['control_by'] === (int) DB::value("SELECT id FROM users WHERE username = 'gerant'"), 'contrôle autorisé par le gérant (PIN)');
    ok(strtotime($row['control_until']) - time() <= 15 * 60 + 5, 'durée bornée aux réglages (15 min)');
    $st = json_decode($c->req('GET', "$B/assist/etat", null, ['Accept: application/json'])['body'], true);
    ok($st['active'][0]['status'] === 'controle' && $st['active'][0]['seconds_left'] > 800, 'bandeau : compte à rebours du contrôle');
    DB::update('assist_interventions', ['control_until' => date('Y-m-d H:i:s', time() - 5)], 'id = ?', [$id]);
    $AS::expire();
    eq('observation', DB::value('SELECT status FROM assist_interventions WHERE id = ?', [$id]), 'fin du délai : retour automatique en observation');
    $j = json_decode($c->req('POST', "$B/assist/$id/terminer.json", '{}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $c->token("$B/assist")])['body'], true);
    ok($j['ok'] && DB::value('SELECT status FROM assist_interventions WHERE id = ?', [$id]) === 'terminee', 'terminée depuis le bandeau');
    $post($c, "/assist/$id/terminer");
    eq(1, (int) DB::value("SELECT COUNT(*) FROM assist_events WHERE intervention_id = ? AND action = 'fin'", [$id]), 'fin idempotente (double clic)');
    eq([], json_decode($c->req('GET', "$B/assist/etat", null, ['Accept: application/json'])['body'], true)['active'], 'bandeau retiré');
    // Demande oubliée : expirée après 8 h
    $old = DB::insert('assist_interventions', ['status' => 'demandee', 'reason' => 'Ancienne', 'ip' => '10.9.9.9', 'created_at' => date('Y-m-d H:i:s', time() - 9 * 3600)]);
    $AS::expire();
    eq('expiree', DB::value('SELECT status FROM assist_interventions WHERE id = ?', [$old]), 'demande de plus de 8 h expirée');
    // La caisse ne dépend pas du module : une vente passe pendant une assistance active
    $post($c, '/assist/demande', ['reason' => 'Vente pendant le partage']);
    $id2 = (int) DB::value("SELECT id FROM assist_interventions WHERE status = 'demandee' ORDER BY id DESC LIMIT 1");
    $post($c, "/assist/$id2/partager");
    actingAs('caissier');
    $pid = (int) DB::value("SELECT id FROM products WHERE name LIKE 'Eau min%'");
    $before = stock($pid);
    $sale = S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000000]]]);
    ok($sale['sale_id'] > 0 && !$sale['duplicate'] && abs(stock($pid) - ($before - 1)) < 0.001, 'vente enregistrée pendant l\'assistance (stock à jour)');
    $AS::end($id2, 'Test', '');

    // Pages, accès, diagnostic sans donnée sensible
    foreach (['assistance', 'postes', 'diagnostic', 'historique', 'reglages'] as $t) {
        eq(200, $g->req('GET', "$B/assist?tab=$t")['code'], "page /assist?tab=$t");
    }
    ok(!str_contains($c->req('GET', "$B/assist")['body'], 'tab=reglages'), 'caissier : onglets de gestion masqués');
    eq(403, $post($c, '/assist/kit', ['kind' => 'boutique', 'label' => 'X'])['code'], 'caissier : pas de kit');
    $anon = (new Http())->req('GET', "$B/assist/etat", null, ['Accept: application/json']);
    ok(in_array($anon['code'], [401, 302], true), 'état non lisible sans connexion');
    $d = Addons\MarwaAssist\Diagnostic::text(Addons\MarwaAssist\Diagnostic::run());
    ok(str_contains($d, 'serveur d\'assistance HTN') && !preg_match('/Mot-de-passe|password_hash|' . preg_quote($key, '/') . '/', $d), 'diagnostic : état technique seulement');
    ok(is_file(PUBLIC_PATH . '/assets/addons/marwa_assist/assist.js') && str_contains((string) file_get_contents(PUBLIC_PATH . '/assets/addons/marwa_assist/assist.js'), '/assist/etat'), 'bandeau publié sur toutes les pages');

    // Agent automatique du poste (1.1) : code d'accès, clavier, coupure — sans réglage manuel
    actingAs('gerant');
    $ka = $AS::createKit('boutique', 'Caisse agent', $B);
    $z = new ZipArchive();
    $z->open($ka['path']);
    $ps = (string) $z->getFromName('agent-assistance.ps1');
    $kbat = (string) $z->getFromName('installer-assistance.bat');
    $z->close();
    @unlink($ka['path']);
    ok(preg_match("#\\\$Jeton = '([a-f0-9]{48})'#", $ps, $jm) === 1 && str_contains($ps, "\$Base = '$B'") && str_contains($ps, 'Restart-Service') && mb_check_encoding($ps, 'ASCII'), 'kit : agent PowerShell (adresse, jeton, coupure des sessions), texte ASCII');
    ok(str_contains($kbat, 'schtasks /create /tn "POS Marwa assistance" /sc onstart /ru SYSTEM') && str_contains($kbat, 'icacls'), 'kit : agent installé en tâche planifiée SYSTEM, dossier protégé');
    $jt = $jm[1];
    $agent = fn(string $q = '') => (new Http())->req('GET', "$B/assist/agent?jeton=$jt" . $q, null, ['Accept: application/json']);
    ok($agent()['code'] === 200 && json_decode($agent()['body'], true)['state'] === 'aucune', 'agent servi dès l\'installation du kit (jeton valide)');
    (new Http())->req('GET', "$B/assist/enregistrer?jeton=$jt&id=987654321&poste=PC-AGENT");
    DB::update('assist_postes', ['token_expires' => date('Y-m-d H:i:s', time() - 60)], 'id = ?', [$ka['poste']]);
    $st = json_decode($agent('&version=1.1')['body'], true);
    ok($st['ok'] && $st['state'] === 'aucune' && $st['code'] === '' && !$st['keyboard'], 'agent : aucune assistance → aucun code, clavier coupé (kit expiré mais poste enregistré : toujours servi)');
    ok(DB::value('SELECT agent_seen FROM assist_postes WHERE id = ?', [$ka['poste']]) !== null && $AS::agentOnline(DB::one('SELECT * FROM assist_postes WHERE id = ?', [$ka['poste']])), 'agent vu « en ligne »');
    $post($c, '/assist/demande', ['reason' => 'Test agent']);
    $ia = (int) DB::value("SELECT id FROM assist_interventions WHERE status = 'demandee' ORDER BY id DESC LIMIT 1");
    eq((int) $ka['poste'], (int) DB::value('SELECT poste_id FROM assist_interventions WHERE id = ?', [$ia]), 'intervention rattachée au poste (adresse du PC)');
    eq('aucune', json_decode($agent()['body'], true)['state'], 'demandée mais pas encore partagée : aucun accès');
    $post($c, "/assist/$ia/partager");
    $codeA = (string) DB::value('SELECT access_code FROM assist_interventions WHERE id = ?', [$ia]);
    ok(preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $codeA) === 1, 'code d\'accès de la session : ' . $codeA);
    ok(str_contains($c->req('GET', "$B/assist")['body'], $codeA), 'code affiché au caissier');
    $st = json_decode($agent()['body'], true);
    ok($st['state'] === 'observation' && $st['code'] === $codeA && !$st['keyboard'], 'agent : observation avec le code, clavier coupé');
    $post($c, "/assist/$ia/controle", ['username' => 'gerant', 'secret' => '4826', 'minutes' => 5]);
    ok(json_decode($agent('&applique=controle%7Cclavier%7C' . $ia)['body'], true)['keyboard'] === true, 'agent : contrôle autorisé → clavier');
    ok((bool) DB::value("SELECT 1 FROM assist_events WHERE intervention_id = ? AND action = 'agent_applique'", [$ia]), 'application par l\'agent inscrite dans l\'historique');
    $post($c, "/assist/$ia/terminer");
    $st = json_decode($agent()['body'], true);
    ok($st['state'] === 'aucune' && $st['code'] === '' && DB::value('SELECT access_code FROM assist_interventions WHERE id = ?', [$ia]) === null, 'fin : code effacé, l\'agent revient à « aucune »');
    $AS::revokePoste($ka['poste']);
    eq(403, $agent()['code'], 'poste retiré : agent refusé');
    eq(403, (new Http())->req('GET', "$B/assist/agent?jeton=" . str_repeat('0', 48))['code'], 'faux jeton : agent refusé');
});

/** Jeu de données DICOM (Explicit VR Little Endian) pour les tests d'imagerie. */
function dicom_dataset(array $o): string
{
    $el = function (int $g, int $e, string $vr, string $v): string {
        if (strlen($v) % 2) {
            $v .= $vr === 'UI' ? "\0" : ' ';
        }
        if (in_array($vr, ['OB', 'OW', 'SQ', 'UN', 'UT'], true)) {
            return pack('vv', $g, $e) . $vr . "\0\0" . pack('V', strlen($v)) . $v;
        }
        return pack('vv', $g, $e) . $vr . pack('v', strlen($v)) . $v;
    };
    $w = $o['w'] ?? 64;
    $h = $o['h'] ?? 48;
    $px = '';
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $px .= pack('v', ($y >= 20 && $y < 28 && $x >= 10 && $x < 50) ? 3000 : 1000);
        }
    }
    return $el(0x0008, 0x0005, 'CS', 'ISO_IR 100') . $el(0x0008, 0x0016, 'UI', '1.2.840.10008.5.1.4.1.1.1') . $el(0x0008, 0x0018, 'UI', $o['sop'])
        . $el(0x0008, 0x0020, 'DA', '20261020') . $el(0x0008, 0x0030, 'TM', '093000') . $el(0x0008, 0x0050, 'SH', $o['acc'] ?? '') . $el(0x0008, 0x0060, 'CS', 'CR')
        . $el(0x0008, 0x1030, 'LO', 'Thorax') . $el(0x0010, 0x0010, 'PN', $o['name'] ?? "DIALLO^A\xEFcha") . $el(0x0010, 0x0020, 'LO', $o['pid'] ?? '')
        . $el(0x0010, 0x0030, 'DA', '19850101') . $el(0x0010, 0x0040, 'CS', 'F')
        // Séquence de longueur indéfinie (à ignorer sans perdre le fil de la lecture)
        . pack('vv', 0x0008, 0x1140) . 'SQ' . "\0\0" . pack('V', 0xFFFFFFFF) . pack('vvV', 0xFFFE, 0xE000, 0xFFFFFFFF) . $el(0x0008, 0x1150, 'UI', '1.2.3') . pack('vvV', 0xFFFE, 0xE00D, 0) . pack('vvV', 0xFFFE, 0xE0DD, 0)
        . $el(0x0020, 0x000D, 'UI', $o['study']) . $el(0x0020, 0x000E, 'UI', $o['study'] . '.1') . $el(0x0020, 0x0013, 'IS', '1')
        . $el(0x0028, 0x0002, 'US', pack('v', 1)) . $el(0x0028, 0x0004, 'CS', 'MONOCHROME2') . $el(0x0028, 0x0010, 'US', pack('v', $h)) . $el(0x0028, 0x0011, 'US', pack('v', $w))
        . $el(0x0028, 0x0030, 'DS', '0.2\\0.2') . $el(0x0028, 0x0100, 'US', pack('v', 16)) . $el(0x0028, 0x0101, 'US', pack('v', 12)) . $el(0x0028, 0x0102, 'US', pack('v', 11))
        . $el(0x0028, 0x0103, 'US', pack('v', 0)) . $el(0x0028, 0x1050, 'DS', '2000') . $el(0x0028, 0x1051, 'DS', '4000')
        . pack('vv', 0x7FE0, 0x0010) . 'OW' . "\0\0" . pack('V', strlen($px)) . $px;
}

/** Fichier DICOM Part 10 à partir d'un jeu de données. */
function dicom_file(array $o): string
{
    $f = STORAGE_PATH . '/tmp/test-' . bin2hex(random_bytes(4)) . '.dcm';
    file_put_contents($f, str_repeat("\0", 128) . 'DICM' . Addons\Clinique\DicomServer::fileMeta('1.2.840.10008.5.1.4.1.1.1', $o['sop'], '1.2.840.10008.1.2.1') . dicom_dataset($o));
    return $f;
}

test('Clinique — imagerie : fichiers DICOM, récepteur réseau, classement, rattachement, compte rendu, visionneuse', function () use ($B) {
    $IS = Addons\Clinique\ImagingService::class;
    $D = Addons\Clinique\Dicom::class;
    actingAs('admin');
    $pat = DB::one('SELECT * FROM clinic_patients ORDER BY id LIMIT 1');
    $study1 = '1.2.826.0.1.3680043.10.1395.' . random_int(1000, 99999);
    $f = dicom_file(['sop' => $study1 . '.1.1', 'study' => $study1, 'pid' => $pat['number']]);
    $info = $D::info($D::parse($f));
    ok($info['rows'] === 48 && $info['cols'] === 64 && $info['bits_allocated'] === 16 && $info['renderable'], 'lecture : dimensions, 16 bits, affichable');
    eq('0.2\\0.2', $info['pixel_spacing'], 'calibrage (mm par pixel)');
    eq('DIALLO Aïcha', $info['patient_name'], 'nom du patient (Latin-1 converti, ^ remplacé)');
    eq($pat['number'], $info['patient_id']);
    eq('2026-10-20 09:30:00', $info['study_date']);
    ok(str_starts_with($D::png($f, 32), "\x89PNG"), 'aperçu PNG');
    // RLE : un segment PackBits « répéter 4 fois 0x07 » puis « 2 littéraux »
    $rle = pack('V16', 1, 64, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0) . chr(253) . chr(7) . chr(1) . chr(9) . chr(10);
    eq(str_repeat(chr(7), 4) . chr(9) . chr(10), $D::rleDecode($rle, ['rows' => 2, 'cols' => 3, 'bits_allocated' => 8, 'samples' => 1]), 'décompression RLE');
    ok(preg_match('/^2\.25\.\d{20,}$/', $D::uid()) === 1, 'identifiant DICOM généré (2.25.…)');

    // Classement : par n° de dossier, doublon, par n° de demande, « à rattacher »
    $r1 = $IS::ingest($f, ['source' => 'reseau']);
    ok(!$r1['duplicate'] && $r1['matched'], 'image classée dans le dossier du patient (Patient ID = n° de dossier)');
    eq((int) $pat['id'], (int) DB::value('SELECT patient_id FROM clinic_imaging_studies WHERE id = ?', [$r1['study_id']]));
    ok($IS::ingest($f)['duplicate'], 'même image reçue deux fois : un seul exemplaire');
    actingAs('medecin');
    $req = $IS::createRequest(['patient_id' => $pat['id'], 'modality' => 'CR', 'exam' => 'Radiographie du thorax', 'priority' => 'urgent']);
    $num = DB::value('SELECT number FROM clinic_imaging_requests WHERE id = ?', [$req]);
    ok(preg_match('/^IMG\d{4}-\d{6}$/', (string) $num) === 1, 'demande d\'examen numérotée ' . $num);
    $study2 = $study1 . '.2';
    $r2 = $IS::ingest(dicom_file(['sop' => $study2 . '.1', 'study' => $study2, 'acc' => $num, 'pid' => 'AUTRE-ID']));
    eq([(int) $pat['id'], 'realise'], [(int) DB::value('SELECT patient_id FROM clinic_imaging_studies WHERE id = ?', [$r2['study_id']]), DB::value('SELECT status FROM clinic_imaging_requests WHERE id = ?', [$req])], 'n° de demande reconnu : bon patient, demande réalisée');
    $study3 = $study1 . '.3';
    $r3 = $IS::ingest(dicom_file(['sop' => $study3 . '.1', 'study' => $study3, 'pid' => 'X-99', 'name' => 'Inconnu^Test']));
    ok(!$r3['matched'] && DB::value('SELECT status FROM clinic_imaging_studies WHERE id = ?', [$r3['study_id']]) === 'a_rattacher', 'patient inconnu : examen « à rattacher » (jamais rattaché par le nom)');
    throws(fn() => $IS::saveReport($r3['study_id'], ['findings' => 'x', 'conclusion' => 'y'], true), 'Rattachez');
    $IS::attach($r3['study_id'], (int) $pat['id']);
    eq('a_lire', DB::value('SELECT status FROM clinic_imaging_studies WHERE id = ?', [$r3['study_id']]), 'rattachement manuel');
    $png = STORAGE_PATH . '/tmp/test-photo.png';
    $gd = imagecreatetruecolor(40, 30);
    imagepng($gd, $png);
    $r4 = $IS::ingest($png, ['patient_id' => $pat['id'], 'description' => 'Photo du cliché']);
    eq('png', DB::value('SELECT format FROM clinic_imaging_images WHERE id = ?', [$r4['image_id']]), 'photo PNG importée dans le dossier');
    file_put_contents(STORAGE_PATH . '/tmp/test.txt', 'texte');
    throws(fn() => $IS::ingest(STORAGE_PATH . '/tmp/test.txt'), 'Format non reconnu');
    // Compte rendu
    throws(fn() => $IS::saveReport($r1['study_id'], ['findings' => '', 'conclusion' => ''], true), 'obligatoires');
    $IS::saveReport($r1['study_id'], ['technique' => 'Face', 'findings' => 'Pas d\'anomalie.', 'conclusion' => 'Normal.'], true);
    eq('valide', DB::value('SELECT status FROM clinic_imaging_studies WHERE id = ?', [$r1['study_id']]), 'compte rendu validé');
    throws(fn() => $IS::saveReport($r1['study_id'], ['findings' => 'a', 'conclusion' => 'b'], false), 'déjà validé');
    $IS::reopen($r1['study_id'], 'Correction de latéralité');
    eq('lu', DB::value('SELECT status FROM clinic_imaging_studies WHERE id = ?', [$r1['study_id']]), 'compte rendu rouvert (motif tracé)');
    // Annotations
    throws(fn() => $IS::addAnnotation($r1['image_id'], ['kind' => 'distance', 'points' => [[1, 2]]]), 'Points');
    throws(fn() => $IS::addAnnotation($r1['image_id'], ['kind' => 'script', 'points' => [[1, 2]]]), 'inconnu');
    $IS::addAnnotation($r1['image_id'], ['kind' => 'distance', 'points' => [[10, 24], [50, 24]], 'value' => '8.0 mm']);
    eq('8.0 mm', $IS::annotations($r1['image_id'])[0]['data']['value'], 'mesure enregistrée');

    // Récepteur réseau DICOM : un émetteur (comme un appareil) envoie l'image en C-STORE
    $port = 21900 + random_int(0, 80);
    $rx = STORAGE_PATH . '/tmp/rx-' . getmypid();
    @mkdir($rx);
    $script = 'require ' . var_export(BASE_PATH . '/app/bootstrap.php', true) . ';$l=stream_socket_server("tcp://127.0.0.1:' . $port . '");'
        . '$s=new Addons\Clinique\DicomServer("POSMARWA",function($f,$c){rename($f,' . var_export($rx, true) . '."/recu.dcm");file_put_contents(' . var_export($rx, true) . '."/ae.txt",$c["ae"]);},function($m){});'
        . 'for($i=0;$i<2;$i++){$c=stream_socket_accept($l,20,$p);if($c)$s->handle($c,$p);}';
    $srv = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pp, BASE_PATH, getenv());
    usleep(800000);
    $item = fn(int $t, string $d) => chr($t) . "\0" . pack('n', strlen($d)) . $d;
    $pdu = fn(int $t, string $d) => chr($t) . "\0" . pack('N', strlen($d)) . $d;
    $read = function ($c) {
        $h = fread($c, 6);
        $len = unpack('N', substr($h, 2, 4))[1];
        $d = '';
        while (strlen($d) < $len && !feof($c)) {
            $d .= fread($c, $len - strlen($d));
        }
        return [ord($h[0]), $d];
    };
    $assoc = function (string $called) use ($port, $item, $pdu) {
        $c = stream_socket_client('tcp://127.0.0.1:' . $port, $en, $es, 5);
        stream_set_timeout($c, 10);
        $pc = $item(0x20, chr(1) . "\0\0\0" . $item(0x30, '1.2.840.10008.5.1.4.1.1.1') . $item(0x40, "1.2.840.10008.1.2.1\0") . $item(0x40, '1.2.840.10008.1.2'));
        fwrite($c, $pdu(0x01, pack('n', 1) . "\0\0" . str_pad($called, 16) . str_pad('RADIO-TEST', 16) . str_repeat("\0", 32) . $item(0x10, '1.2.840.10008.3.1.1.1') . $pc . $item(0x50, $item(0x51, pack('N', 16384)))));
        return $c;
    };
    $c = $assoc('MAUVAIS');
    eq(0x03, $read($c)[0], 'AE appelé inconnu : association refusée');
    fclose($c);
    $c = $assoc('POSMARWA');
    [$t, $ac] = $read($c);
    eq(0x02, $t, 'association acceptée');
    $sop = $study1 . '.9.1';
    $ds = dicom_dataset(['sop' => $sop, 'study' => $study1 . '.9', 'pid' => $pat['number']]);
    $cel = fn(int $e, string $v) => pack('vvV', 0, $e, strlen($v)) . $v;
    $body = $cel(0x0002, "1.2.840.10008.5.1.4.1.1.1\0") . $cel(0x0100, pack('v', 1)) . $cel(0x0110, pack('v', 7)) . $cel(0x0700, pack('v', 0)) . $cel(0x0800, pack('v', 0)) . $cel(0x1000, strlen($sop) % 2 ? $sop . "\0" : $sop);
    $cmd = $cel(0x0000, pack('V', strlen($body))) . $body;
    fwrite($c, $pdu(0x04, pack('N', strlen($cmd) + 2) . chr(1) . chr(3) . $cmd));
    foreach (str_split($ds, 8000) as $i => $frag) { // jeu de données en plusieurs fragments
        $last = $i === intdiv(strlen($ds) - 1, 8000);
        fwrite($c, $pdu(0x04, pack('N', strlen($frag) + 2) . chr(1) . chr($last ? 2 : 0) . $frag));
    }
    [$t, $rsp] = $read($c);
    $status = unpack('v', substr($rsp, strpos($rsp, pack('vvV', 0, 0x0900, 2)) + 8, 2))[1];
    eq([0x04, 0], [$t, $status], 'C-STORE : réponse « succès »');
    fwrite($c, $pdu(0x05, "\0\0\0\0"));
    eq(0x06, $read($c)[0], 'libération de l\'association');
    fclose($c);
    proc_close($srv);
    ok(is_file("$rx/recu.dcm") && file_get_contents("$rx/ae.txt") === 'RADIO-TEST', 'fichier reçu, appareil identifié');
    $back = $D::info($D::parse("$rx/recu.dcm"));
    eq([$sop, 64, 48], [$back['sop_uid'], $back['cols'], $back['rows']], 'fichier reçu lisible (Part 10 écrit par le récepteur)');
    ok(str_ends_with(file_get_contents("$rx/recu.dcm"), substr($ds, -200)), 'pixels reçus intacts');
    exec('rm -rf ' . escapeshellarg($rx));

    // Écrans, pixels, envoi en morceaux, droits
    $a = new Http();
    $a->login($B, 'medecin', 'Mot-de-passe-2026');
    foreach (['/clinique/imagerie', '/clinique/imagerie?onglet=demandes', '/clinique/imagerie/examens/' . $r1['study_id'], '/clinique/imagerie/demandes/' . $req . '/imprimer', '/clinique/imagerie/examens/' . $r1['study_id'] . '/imprimer', '/clinique/patients/' . $pat['id']] as $u) {
        eq(200, $a->req('GET', $B . $u)['code'], "page $u");
    }
    $inf = json_decode($a->req('GET', "$B/clinique/imagerie/images/{$r1['image_id']}/infos", null, ['Accept: application/json'])['body'], true);
    ok($inf['ok'] && $inf['mode'] === 'raw' && $inf['spacing']['col'] == 0.2 && $inf['bits'] === 16, 'infos de la visionneuse');
    $px = $a->req('GET', "$B/clinique/imagerie/images/{$r1['image_id']}/pixels");
    eq(64 * 48 * 2, strlen($px['body']), 'pixels bruts envoyés à la visionneuse');
    ok(str_starts_with($a->req('GET', "$B/clinique/imagerie/images/{$r1['image_id']}/miniature")['body'], "\x89PNG"), 'miniature');
    $tok = $a->token("$B/clinique/imagerie");
    $file = dicom_file(['sop' => $study1 . '.7.1', 'study' => $study1 . '.7', 'pid' => $pat['number']]);
    $bytes = file_get_contents($file);
    $half = intdiv(strlen($bytes), 2);
    $up = bin2hex(random_bytes(10));
    $send = function (int $i, string $part) use ($a, $B, $tok, $up) {
        $tmp = tempnam(sys_get_temp_dir(), 'ch');
        file_put_contents($tmp, $part);
        $r = $a->req('POST', "$B/clinique/imagerie/import", ['_csrf' => $tok, 'upload' => $up, 'index' => $i, 'total' => 2, 'chunk' => new CURLFile($tmp)], ['Accept: application/json']);
        @unlink($tmp);
        return json_decode($r['body'], true);
    };
    ok($send(1, substr($bytes, $half))['done'] === false, 'envoi en morceaux : 1er morceau reçu (ordre quelconque)');
    $fin = $send(0, substr($bytes, 0, $half));
    ok($fin['done'] && !$fin['duplicate'] && $fin['matched'], 'fichier reconstitué et classé');
    $c2 = new Http();
    $c2->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c2->req('GET', "$B/clinique/imagerie")['code'], 'caissier : pas d\'accès à l\'imagerie');
    eq(403, $c2->req('GET', "$B/clinique/imagerie/images/{$r1['image_id']}/pixels")['code'], 'caissier : pas d\'accès aux images');
    $n = new Http();
    $n->login($B, 'infirmier', 'Mot-de-passe-2026');
    eq(403, $n->req('POST', "$B/clinique/imagerie/examens/{$r1['study_id']}/compte-rendu", http_build_query(['_csrf' => $n->token("$B/clinique/imagerie"), 'findings' => 'x', 'conclusion' => 'y']))['code'], 'infirmier : pas de compte rendu');
    ok(!is_dir(STORAGE_PATH . '/imagerie/envois/' . $up), 'morceaux temporaires supprimés');
});

test('Banques et coffres : comptes, versements de clôture, dépôts, retraits, virements, annulations, relevé', function () use ($B) {
    App\Core\Addons::install('banques', false);
    ok(App\Core\Addons::isActive('banques'), 'module actif');
    $hasPerm = fn(string $role, string $perm) => (bool) DB::value('SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ? AND p.code = ?', [$role, $perm]);
    ok($hasPerm('owner', 'banques.manage') && $hasPerm('store_manager', 'banques.operate') && !$hasPerm('store_manager', 'banques.manage') && !$hasPerm('cashier', 'banques.view'), 'droits : propriétaire gère, gérant opère, caissier n\'a pas accès');
    $BS = Addons\Banques\BankService::class;
    actingAs('admin');
    $bank = $BS::saveAccount(['name' => 'BIA Niger courant', 'kind' => 'banque', 'bank_name' => 'BIA Niger', 'account_number' => '0012345', 'opening_balance' => '50 000']);
    $safe = $BS::saveAccount(['name' => 'Coffre du magasin', 'kind' => 'coffre']);
    $owner = $BS::saveAccount(['name' => 'Gardé par le propriétaire', 'kind' => 'proprietaire']);
    $bal = fn(int $id) => (float) DB::value('SELECT balance FROM bank_accounts WHERE id = ?', [$id]);
    eq(50000.0, $bal($bank), 'solde de départ enregistré comme opération');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM bank_transactions WHERE account_id = ? AND type = 'ajustement'", [$bank]));
    throws(fn() => $BS::saveAccount(['name' => 'BIA Niger courant', 'kind' => 'banque']), 'déjà');
    throws(fn() => $BS::saveAccount(['name' => 'X', 'kind' => 'banque']), 'Nom du compte');

    // Clôture de caisse : seul l'argent compté peut être versé, jamais deux fois
    $reg = DB::insert('cash_registers', ['store_id' => 1, 'warehouse_id' => 1, 'name' => 'Caisse test banques']);
    $open = DB::insert('cash_sessions', ['register_id' => $reg, 'user_id' => 1, 'opening_amount' => 0, 'status' => 'open']);
    throws(fn() => $BS::depositSession($open, ['account_id' => $bank, 'amount' => 1000]), 'Clôturez');
    DB::update('cash_sessions', ['status' => 'closed', 'expected_amount' => 152000, 'counted_amount' => 150000, 'difference' => -2000, 'closed_at' => date('Y-m-d H:i:s'), 'closed_by' => 1], 'id = ?', [$open]);
    throws(fn() => $BS::depositSession($open, ['account_id' => $bank, 'amount' => 150001]), 'supérieur');
    $v1 = $BS::depositSession($open, ['account_id' => $bank, 'amount' => '100 000', 'method' => 'especes', 'reference' => 'BRD-778', 'person' => 'Moussa']);
    $v2 = $BS::depositSession($open, ['account_id' => $safe, 'amount' => 50000, 'transfer_id' => 999, 'counter_account_id' => $owner]);
    eq([150000.0, 50000.0], [$bal($bank), $bal($safe)], 'versements : banque et coffre crédités');
    ok(DB::value('SELECT transfer_id FROM bank_transactions WHERE id = ?', [$v2]) === null && DB::value('SELECT counter_account_id FROM bank_transactions WHERE id = ?', [$v2]) === null, 'champs internes envoyés par un formulaire ignorés');
    throws(fn() => $BS::depositSession($open, ['account_id' => $bank, 'amount' => 1]), 'supérieur');
    ok(!in_array($open, array_column($BS::sessions(true), 'id')), 'clôture entièrement versée : plus dans « à verser »');
    eq('BRD-778', DB::value('SELECT reference FROM bank_transactions WHERE id = ?', [$v1]), 'n° de bordereau conservé');

    // Dépôts, retraits, virements
    throws(fn() => $BS::operation('retrait', ['account_id' => $safe, 'amount' => 1000]), 'motif');
    throws(fn() => $BS::operation('retrait', ['account_id' => $safe, 'amount' => 60000, 'description' => 'Achat']), 'Solde insuffisant');
    throws(fn() => $BS::operation('depot', ['account_id' => $bank, 'amount' => '-5']), 'supérieur à zéro');
    throws(fn() => $BS::operation('depot', ['account_id' => $bank, 'amount' => 5, 'op_date' => date('Y-m-d', strtotime('+2 days'))]), 'date future');
    $r1 = $BS::operation('retrait', ['account_id' => $safe, 'amount' => 20000, 'description' => 'Paiement fournisseur', 'person' => 'Ali']);
    $BS::operation('depot', ['account_id' => $owner, 'amount' => 75000, 'person' => 'Propriétaire']);
    $tr = $BS::transfer(['from_account_id' => $safe, 'to_account_id' => $bank, 'amount' => 25000, 'reference' => 'BRD-779']);
    throws(fn() => $BS::transfer(['from_account_id' => $bank, 'to_account_id' => $bank, 'amount' => 1]), 'différents');
    eq([175000.0, 5000.0, 75000.0], [$bal($bank), $bal($safe), $bal($owner)], 'retrait et virement coffre → banque');
    eq(2, (int) DB::value('SELECT COUNT(*) FROM bank_transactions WHERE transfer_id = ?', [$tr]), 'virement : deux lignes liées, même numéro');
    eq(1, (int) DB::value('SELECT COUNT(DISTINCT number) FROM bank_transactions WHERE transfer_id = ?', [$tr]));

    // Annulations : jamais d'effacement, solde rétabli, refus si l'argent est déjà sorti
    throws(fn() => $BS::cancel($tr, ''), 'motif');
    $BS::cancel($tr, 'Erreur de compte');
    eq([150000.0, 30000.0], [$bal($bank), $bal($safe)], 'virement annulé : les deux comptes rétablis');
    throws(fn() => $BS::cancel($tr, 'encore'), 'déjà annulée');
    $BS::operation('retrait', ['account_id' => $safe, 'amount' => 30000, 'description' => 'Sortie totale']);
    throws(fn() => $BS::cancel($v2, 'Erreur'), 'négatif');
    $BS::cancel($v1, 'Bordereau refusé par la banque');
    eq(50000.0, $bal($bank), 'versement annulé : banque débitée');
    ok(in_array($open, array_column($BS::sessions(true), 'id')), 'clôture de nouveau « à verser » après annulation');
    eq(100000.0, (float) array_values(array_filter($BS::sessions(true), fn($s) => (int) $s['id'] === $open))[0]['remaining']);
    eq(1, (int) DB::value('SELECT COUNT(*) FROM bank_transactions WHERE id = ? AND cancelled_at IS NOT NULL', [$v1]), 'opération annulée conservée dans l\'historique');
    $BS::adjust(['account_id' => $owner, 'new_balance' => '74 500', 'description' => 'Comptage du 30/09']);
    eq(74500.0, $bal($owner), 'ajustement de solde');
    throws(fn() => $BS::adjust(['account_id' => $owner, 'new_balance' => 74500, 'description' => 'Rien']), 'déjà');

    // Relevé : solde courant ligne par ligne, totaux, cohérence générale
    $st = $BS::statement($bank, date('Y-m-01'), date('Y-m-d'));
    eq(50000.0, $st['closing'], 'relevé : solde de fin = solde du compte');
    eq(round($st['opening'] + $st['in'] - $st['out'], 2), $st['closing'], 'relevé : début + entrées − sorties = fin');
    eq([], $BS::check(), 'cohérence : chaque solde = somme des opérations non annulées');
    throws(fn() => $BS::saveAccount(['name' => 'Gardé par le propriétaire', 'kind' => 'proprietaire', 'active' => 0], $owner), 'encore un solde');

    // Écrans et accès
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    foreach (['/banques', '/banques/versements', '/banques/historique', "/banques/comptes/$bank", "/banques/operations/$v1", '/banques?op=virement', '/banques?op=compte', "/banques?edit=$bank"] as $u) {
        eq(200, $a->req('GET', $B . $u)['code'], "page $u");
    }
    ok(str_contains($a->req('GET', "$B/banques/operations/$v1")['body'], 'ANNULÉE'), 'reçu d\'une opération annulée marqué « ANNULÉE »');
    $csv = $a->req('GET', "$B/banques/comptes/$bank?export=csv");
    ok($csv['code'] === 200 && str_contains($csv['body'], 'BRD-778'), 'relevé exporté (CSV)');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/banques")['code'], 'caissier : accès refusé');
    $g = new Http();
    $g->login($B, 'gerant', 'Mot-de-passe-2026');
    $tok = $g->token("$B/banques");
    $g->req('POST', "$B/banques/operations", http_build_query(['_csrf' => $tok, 'type' => 'depot', 'account_id' => $bank, 'amount' => '12 500', 'person' => 'Gérant']));
    eq(62500.0, $bal($bank), 'gérant : dépôt par le formulaire');
    eq(403, $g->req('POST', "$B/banques/operations/$r1/annuler", http_build_query(['_csrf' => $tok, 'reason' => 'test']))['code'], 'gérant : annulation réservée au propriétaire');
    // Installation automatique des modules fournis « auto_install », sans jamais réactiver un module désactivé
    App\Core\Addons::setActive('banques', false);
    App\Core\Addons::reset();
    App\Core\Addons::autoUpgrade();
    App\Core\Addons::reset();
    ok(!App\Core\Addons::isActive('banques'), 'module désactivé par l\'utilisateur : jamais réactivé automatiquement');
    App\Core\Addons::setActive('banques', true);
    DB::run("DELETE FROM addons WHERE code = 'marwa_assist'");
    App\Core\Addons::reset();
    App\Core\Addons::autoUpgrade();
    App\Core\Addons::reset();
    ok(App\Core\Addons::isActive('marwa_assist'), 'module fourni « auto_install » jamais installé : installé et activé d\'office');
});

test('Pressing : écran des dépôts en cours (atelier) et affichage clients sans données personnelles', function () use ($B) {
    $PS = Addons\Pressing\PressingService::class;
    actingAs('admin');
    $art = (int) DB::value('SELECT id FROM pressing_articles ORDER BY id LIMIT 1');
    $o = $PS::create(['customer_name' => 'Halima Souley', 'phone' => '+227 90 77 66 55', 'lines' => [['article_id' => $art, 'service' => 'lavage', 'quantity' => 2]]]);
    $id = (int) ($o['id'] ?? DB::value('SELECT MAX(id) FROM pressing_orders'));
    $b = $PS::board();
    $ids = array_column($b['columns']['received'], 'id');
    ok(in_array($id, $ids, true), 'dépôt visible dans « Déposés »');
    ok($b['totals']['open'] >= 1 && isset($b['totals']['late'], $b['totals']['due']), 'compteurs');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    eq(200, $a->req('GET', "$B/pressing/ecran")['code'], 'écran des dépôts');
    $j = json_decode($a->req('POST', "$B/pressing/ecran/$id/statut", json_encode(['status' => 'processing']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/pressing")])['body'], true);
    ok($j['ok'] && DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$id]) === 'processing', 'avancement depuis l\'écran');
    $j = json_decode($a->req('POST', "$B/pressing/ecran/$id/statut", json_encode(['status' => 'delivered']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/pressing")])['body'], true);
    ok(!$j['ok'], 'retrait impossible depuis l\'écran (paiement au comptoir)');
    $PS::setStatus($id, 'ready');
    $pub = $a->req('GET', "$B/pressing/ecran/feed?public=1", null, ['Accept: application/json'])['body'];
    ok(str_contains($pub, 'Halima') && !str_contains($pub, '90 77') && !str_contains($pub, 'Souley') && !str_contains($pub, 'rest'), 'affichage clients : numéro et prénom seulement');
    $c = new Http();
    $c->login($B, 'auditeur', 'Mot-de-passe-2026');
    $r = $c->req('POST', "$B/pressing/ecran/$id/statut", json_encode(['status' => 'processing']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $c->token("$B/pressing")]);
    eq(403, $r['code'], 'auditeur : lecture seule');
});

test('Boutiques, entrepôts, caisses : modification, désactivation, suppression d\'une caisse jamais utilisée', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/stores");
    $page = $a->req('GET', "$B/stores")['body'];
    ok(str_contains($page, 'data-bs-target="#st1"') && str_contains($page, '/registers/1"') && str_contains($page, '/registers/1/delete"'), 'boutons et formulaires de modification');
    $st = DB::one('SELECT * FROM stores WHERE id = 1');
    $a->req('POST', "$B/stores/1", http_build_query(['_csrf' => $t, 'name' => 'Boutique Centre', 'code' => $st['code'], 'address' => 'Plateau', 'phone' => '90000000', 'active' => '1']));
    eq('Boutique Centre', DB::value('SELECT name FROM stores WHERE id = 1'), 'boutique renommée');
    $wh = DB::one('SELECT * FROM warehouses WHERE id = 1');
    $a->req('POST', "$B/warehouses/1", http_build_query(['_csrf' => $t, 'name' => 'Magasin principal', 'code' => $wh['code'], 'store_id' => '1', 'is_sales_point' => '1', 'active' => '1']));
    eq('Magasin principal', DB::value('SELECT name FROM warehouses WHERE id = 1'), 'entrepôt renommé');
    // Entrepôt d'une caisse active : désactivation refusée
    $a->req('POST', "$B/warehouses/1", http_build_query(['_csrf' => $t, 'name' => 'Magasin principal', 'code' => $wh['code'], 'store_id' => '1', 'is_sales_point' => '1']));
    eq(1, (int) DB::value('SELECT active FROM warehouses WHERE id = 1'), 'entrepôt d\'une caisse active : reste actif');
    // Caisse ouverte : nom modifiable, mais pas désactivable
    if (!S\CashService::openSessionForRegister(1)) {
        actingAs('caissier');
        S\CashService::open(1, 0);
    }
    $a->req('POST', "$B/registers/1", http_build_query(['_csrf' => $t, 'name' => 'Caisse principale', 'store_id' => '1', 'warehouse_id' => '1']));
    eq(1, (int) DB::value('SELECT active FROM cash_registers WHERE id = 1'), 'caisse ouverte : désactivation refusée');
    $a->req('POST', "$B/registers/1", http_build_query(['_csrf' => $t, 'name' => 'Caisse principale', 'store_id' => '1', 'warehouse_id' => '1', 'active' => '1']));
    eq('Caisse principale', DB::value('SELECT name FROM cash_registers WHERE id = 1'), 'caisse ouverte : nom modifié');
    // Suppression : refusée pour une caisse utilisée, acceptée pour une caisse jamais ouverte
    $a->req('POST', "$B/registers/1/delete", http_build_query(['_csrf' => $t]));
    ok((bool) DB::value('SELECT 1 FROM cash_registers WHERE id = 1'), 'caisse utilisée : jamais supprimée');
    $a->req('POST', "$B/registers", http_build_query(['_csrf' => $t, 'name' => 'Caisse en trop', 'store_id' => '1', 'warehouse_id' => '1']));
    $rid = (int) DB::value("SELECT id FROM cash_registers WHERE name = 'Caisse en trop'");
    ok($rid > 0, 'caisse créée');
    $a->req('POST', "$B/registers/$rid/delete", http_build_query(['_csrf' => $t]));
    ok(!DB::value('SELECT 1 FROM cash_registers WHERE id = ?', [$rid]), 'caisse jamais utilisée : supprimée');
    eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'register.delete' AND entity_id = ?", [$rid]), 'suppression tracée');
    // Accès réservé
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('POST', "$B/stores/1", http_build_query(['_csrf' => $c->token("$B/pos"), 'name' => 'Pirate', 'code' => 'X1']))['code'], 'caissier : refusé');
});

test('Écrans : adresse du réseau local au lieu de localhost, barre de chargement dans les pages', function () use ($B) {
    $saved = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = 'localhost:8080';
    try {
        ok(S\Screens::isLoopbackHost(), 'localhost reconnu');
        $ips = S\NetworkService::localIps();
        $u = S\Screens::url('client');
        if ($ips) {
            ok(str_contains($u, $ips[0]) && !str_contains($u, 'localhost') && str_ends_with($u, '/ecran/client'), 'adresse IP du réseau : ' . $u);
        } else {
            ok(str_contains($u, 'localhost'), 'aucun réseau : localhost conservé');
        }
        $_SERVER['HTTP_HOST'] = 'pos.exemple.com';
        ok(!S\Screens::isLoopbackHost() && str_starts_with(S\Screens::url('client'), 'http://pos.exemple.com') && str_ends_with(S\Screens::url('client'), '/ecran/client'), 'nom de domaine conservé tel quel');
    } finally {
        $_SERVER['HTTP_HOST'] = $saved;
    }
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    ok(str_contains($a->req('GET', "$B/dashboard")['body'], 'js/progress.js'), 'barre de chargement : gestion');
    ok(str_contains($a->req('GET', "$B/pos")['body'], 'js/progress.js'), 'barre de chargement : caisse');
    eq(200, $a->req('GET', "$B/assets/js/progress.js")['code'], 'script servi');
});

test('Caisse : fond de caisse facultatif et clôture automatique à 23h59', function () {
    actingAs('admin');
    $rid = DB::insert('cash_registers', ['name' => 'Caisse nuit', 'store_id' => 1, 'warehouse_id' => 1]);
    // Fond de caisse non demandé : ouverture directe à 0, même si un montant est envoyé
    setting_set('cash_ask_opening', '0');
    $sid = S\CashService::open($rid, 25000);
    eq(0.0, (float) DB::value('SELECT opening_amount FROM cash_sessions WHERE id = ?', [$sid]), 'fond de 0');
    setting_set('cash_ask_opening', '1');
    // Clôture automatique désactivée : rien ne bouge
    DB::run('UPDATE cash_sessions SET opened_at = ? WHERE id = ?', [date('Y-m-d', strtotime('-1 day')) . ' 08:00:00', $sid]);
    setting_set('cash_auto_close', '0');
    eq(0, S\CashService::autoClose(true), 'option désactivée');
    setting_set('cash_auto_close', '1');
    S\CashService::movement($sid, 'sale', 'cash', 1500, null, null, 'test');
    eq(1, S\CashService::autoClose(true), 'session de la veille clôturée');
    $s = DB::one('SELECT * FROM cash_sessions WHERE id = ?', [$sid]);
    ok($s['status'] === 'closed' && (int) $s['auto_closed'] === 1, 'statut et marque « auto »');
    eq(date('Y-m-d', strtotime('-1 day')) . ' 23:59:59', $s['closed_at'], 'clôturée à 23:59:59 du jour d\'ouverture');
    ok($s['counted_amount'] === null && $s['difference'] === null && (float) $s['expected_amount'] === 1500.0, 'espèces non comptées, théorique conservé');
    // Session ouverte aujourd'hui : jamais touchée
    $sid2 = S\CashService::open($rid, 1000);
    eq(0, S\CashService::autoClose(true), 'session du jour conservée');
    eq('open', DB::value('SELECT status FROM cash_sessions WHERE id = ?', [$sid2]));
    S\CashService::close($sid2, 1000, null);
    setting_set('cash_auto_close', '0');
});

test('Boutique en ligne : catalogue public, commande, reçu, réservation, encaissement (stock), livraison, annulation', function () use ($B) {
    actingAs('admin');
    eq(['0001_boutique', '0002_comptes'], App\Core\Addons::install('boutique', false), 'installation du module');
    App\Core\Addons::reset();
    ok(App\Core\Addons::isActive('boutique'));
    ok(isset(Auth::permissionsForRole((int) DB::value("SELECT id FROM roles WHERE code = 'cashier'"))['boutique.orders']), 'caissier : droit sur les commandes');
    setting_set('shop_register_id', '1');
    // Article de test avec photo et stock connu dans l'entrepôt de la caisse 1
    $wh = (int) DB::value('SELECT warehouse_id FROM cash_registers WHERE id = 1');
    $pid = DB::insert('products', ['reference' => 'WEB-001', 'name' => 'Sac en pagne', 'description' => 'Sac cousu main', 'sale_price' => 7500, 'cost_price' => 4000, 'track_stock' => 1, 'status' => 'active']);
    S\StockService::receive($pid, $wh, 5, 4000, 'adjustment', null, null, 'stock initial boutique');
    $hid = DB::insert('products', ['reference' => 'WEB-002', 'name' => 'Article masqué', 'sale_price' => 1000, 'track_stock' => 0, 'status' => 'active']);
    DB::insert('shop_products', ['product_id' => $hid, 'hidden' => 1]);
    $cat = Addons\Boutique\ShopService::catalog();
    $p = array_values(array_filter($cat, fn ($x) => $x['id'] === $pid))[0] ?? null;
    ok($p && $p['price'] === 7500.0 && $p['avail'] === 5.0, 'article en ligne avec son stock');
    ok(!in_array($hid, array_column($cat, 'id'), true), 'article masqué absent');

    // Visiteur sans compte
    $v = new Http();
    $page = $v->req('GET', "$B/boutique");
    eq(200, $page['code'], 'boutique publique');
    ok(str_contains($page['body'], 'Sac en pagne') && !str_contains($page['body'], 'Article masqué'), 'catalogue affiché');
    preg_match('/"csrf":"([a-f0-9]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    ok($csrf !== '', 'jeton de la page');
    $post = fn (array $d) => $v->req('POST', "$B/boutique/commande", json_encode($d + ['_csrf' => $csrf]), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $csrf]);
    $r = $post(['name' => 'Aïcha', 'phone' => '+227 96 11 22 33', 'items' => [['id' => $pid, 'qty' => 9]]]);
    ok($r['code'] === 422 && str_contains($r['body'], 'Il ne reste que 5'), 'quantité supérieure au stock refusée');
    $r = $post(['name' => 'Robot', 'phone' => '+227 96 11 22 33', 'website' => 'http://spam', 'items' => [['id' => $pid, 'qty' => 1]]]);
    eq(422, $r['code'], 'robot (champ piège) refusé');
    $r = $post(['name' => 'Aïcha', 'phone' => '12', 'items' => [['id' => $pid, 'qty' => 1]]]);
    ok($r['code'] === 422 && str_contains($r['body'], 'téléphone'), 'téléphone obligatoire');
    $r = $post(['name' => 'Aïcha Moussa', 'phone' => '+227 96 11 22 33', 'items' => [['id' => $pid, 'qty' => 3]], 'note' => 'Couleur bleue']);
    $j = json_decode($r['body'], true);
    ok($r['code'] === 200 && $j['ok'] && str_starts_with($j['number'], 'WEB'), 'commande enregistrée : ' . ($j['number'] ?? $r['body']));
    eq(22500.0, (float) $j['total'], 'total recalculé par le serveur');
    $oid = (int) DB::value('SELECT id FROM shop_orders WHERE number = ?', [$j['number']]);
    // Reçu par lien secret
    $rec = $v->req('GET', $B . substr($j['url'], (int) strpos($j['url'], '/boutique/')));
    ok($rec['code'] === 200 && str_contains($rec['body'], $j['number']) && str_contains($rec['body'], 'Sac en pagne'), 'reçu de commande');
    eq(404, $v->req('GET', "$B/boutique/commande/$oid?t=" . str_repeat('0', 32))['code'], 'mauvais lien : refusé');
    // Réservation : 2 encore disponibles en ligne, stock réel inchangé
    $p = array_values(array_filter(Addons\Boutique\ShopService::catalog(), fn ($x) => $x['id'] === $pid))[0];
    eq(2.0, $p['avail'], 'quantité réservée déduite en ligne');
    eq(5.0, stock($pid, $wh), 'stock réel inchangé avant encaissement');
    $r = $post(['name' => 'Ibrahim', 'phone' => '+227 90 00 00 01', 'items' => [['id' => $pid, 'qty' => 3]]]);
    eq(422, $r['code'], 'la réservation protège la dernière pièce');

    // Traitement dans POS Marwa par le caissier
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    $list = $c->req('GET', "$B/boutique-admin")['body'];
    ok(str_contains($list, $j['number']), 'commande visible dans l\'application');
    $feed = json_decode($c->req('GET', "$B/boutique-admin/feed", null, ['Accept: application/json'])['body'], true);
    ok($feed['ok'] && $feed['new'] >= 1, 'alerte nouvelle commande');
    $show = $c->req('GET', "$B/boutique-admin/$oid")['body'];
    ok(str_contains($show, 'tout est disponible'), 'vérification du stock');
    $t = $c->token("$B/boutique-admin/$oid");
    $c->req('POST', "$B/boutique-admin/$oid/livrer", http_build_query(['_csrf' => $t]));
    eq('new', DB::value('SELECT status FROM shop_orders WHERE id = ?', [$oid]), 'livraison impossible avant encaissement');
    $c->req('POST', "$B/boutique-admin/$oid/valider", http_build_query(['_csrf' => $t]));
    eq('confirmed', DB::value('SELECT status FROM shop_orders WHERE id = ?', [$oid]), 'commande validée');
    if (!S\CashService::openSessionForRegister(1)) {
        actingAs('caissier');
        S\CashService::open(1, 0);
    }
    $c->req('POST', "$B/boutique-admin/$oid/encaisser", http_build_query(['_csrf' => $t, 'method' => 'cash', 'tendered' => '25000']));
    $o = DB::one('SELECT * FROM shop_orders WHERE id = ?', [$oid]);
    eq('paid', $o['status'], 'commande encaissée');
    $sale = DB::one('SELECT * FROM sales WHERE id = ?', [$o['sale_id']]);
    ok($sale && (float) $sale['total'] === 22500.0 && str_contains((string) $sale['note'], $j['number']) && (float) $sale['change_due'] === 2500.0, 'vente créée (total, note, monnaie)');
    eq(2.0, stock($pid, $wh), 'stock diminué à l\'encaissement');
    $p = array_values(array_filter(Addons\Boutique\ShopService::catalog(), fn ($x) => $x['id'] === $pid))[0];
    eq(2.0, $p['avail'], 'en ligne : stock réel, plus de réservation');
    $c->req('POST', "$B/boutique-admin/$oid/encaisser", http_build_query(['_csrf' => $t, 'method' => 'cash', 'tendered' => '25000']));
    eq(1, (int) DB::value('SELECT COUNT(*) FROM sales WHERE note LIKE ?', ['%' . $j['number'] . '%']), 'double encaissement impossible');
    $c->req('POST', "$B/boutique-admin/$oid/annuler", http_build_query(['_csrf' => $t, 'reason' => 'test']));
    eq('paid', DB::value('SELECT status FROM shop_orders WHERE id = ?', [$oid]), 'commande encaissée : annulation refusée');
    $c->req('POST', "$B/boutique-admin/$oid/livrer", http_build_query(['_csrf' => $t]));
    eq('delivered', DB::value('SELECT status FROM shop_orders WHERE id = ?', [$oid]), 'commande livrée');
    ok(str_contains($v->req('GET', $B . substr($j['url'], (int) strpos($j['url'], '/boutique/')))['body'], 'class="done current"'), 'le client voit l\'avancement');
    eq(4, (int) DB::value('SELECT COUNT(*) FROM shop_order_log WHERE order_id = ?', [$oid]), 'historique complet');

    // Livraison payante par mobile money ; annulation qui libère la réservation
    setting_set('shop_delivery_fee', '1000');
    $j2 = json_decode($post(['name' => 'Ibrahim', 'phone' => '+227 90 00 00 01', 'delivery' => 'delivery', 'address' => 'Quartier Plateau, près de la pharmacie', 'items' => [['id' => $pid, 'qty' => 1]]])['body'], true);
    ok($j2['ok'] && (float) $j2['total'] === 8500.0, 'livraison ajoutée au total');
    $o2 = (int) DB::value('SELECT id FROM shop_orders WHERE number = ?', [$j2['number']]);
    $c->req('POST', "$B/boutique-admin/$o2/encaisser", http_build_query(['_csrf' => $t, 'method' => 'mobile_money', 'reference' => 'TX123']));
    $o = DB::one('SELECT * FROM shop_orders WHERE id = ?', [$o2]);
    eq('paid', $o['status'], 'encaissée par mobile money');
    eq(1000.0, (float) DB::value("SELECT SUM(amount) FROM cash_movements WHERE type = 'shop_delivery' AND ref_id = ?", [$o2]), 'frais de livraison en recette de caisse');
    eq(7500.0, (float) DB::value('SELECT total FROM sales WHERE id = ?', [$o['sale_id']]), 'vente : articles seulement');
    $j3 = json_decode($post(['name' => 'Ibrahim', 'phone' => '+227 90 00 00 01', 'items' => [['id' => $pid, 'qty' => 1]]])['body'], true);
    $o3 = (int) DB::value('SELECT id FROM shop_orders WHERE number = ?', [$j3['number']]);
    $c->req('POST', "$B/boutique-admin/$o3/annuler", http_build_query(['_csrf' => $t, 'reason' => 'Client injoignable']));
    eq('cancelled', DB::value('SELECT status FROM shop_orders WHERE id = ?', [$o3]), 'commande annulée');
    $p = array_values(array_filter(Addons\Boutique\ShopService::catalog(), fn ($x) => $x['id'] === $pid))[0];
    eq(1.0, $p['avail'], 'réservation libérée');
    setting_set('shop_delivery_fee', '0');

    // Boutique fermée quand la caisse doit être ouverte et ne l'est pas ; boutique désactivée
    setting_set('shop_require_open', '1');
    ok(Addons\Boutique\ShopService::isOpen(), 'caisse 1 ouverte : commandes acceptées');
    setting_set('shop_require_open', '0');
    setting_set('shop_enabled', '0');
    eq(503, $v->req('GET', "$B/boutique")['code'], 'boutique désactivée');
    setting_set('shop_enabled', '1');
    // Réglages et articles : réservés au responsable
    eq(403, $c->req('GET', "$B/boutique-admin/reglages")['code'], 'caissier : pas de réglages');
    $g = new Http();
    $g->login($B, 'admin', 'Essai-Niamey-2026');
    ok(str_contains($g->req('GET', "$B/boutique-admin/reglages")['body'], 'Articles en ligne'), 'page des réglages');
    $tg = $g->token("$B/boutique-admin/reglages");
    $g->req('POST', "$B/boutique-admin/articles/$pid", http_build_query(['_csrf' => $tg, 'featured' => '1', 'web_description' => 'Pièce unique']));
    $sp = DB::one('SELECT * FROM shop_products WHERE product_id = ?', [$pid]);
    ok((int) $sp['hidden'] === 1 && (int) $sp['featured'] === 1 && $sp['web_description'] === 'Pièce unique', 'article masqué / à la une / texte en ligne');
    DB::run('UPDATE shop_products SET hidden = 0 WHERE product_id = ?', [$pid]);
});

test('Module Doublons : détection (accents, casse, espaces), comparaison, fusion du stock, des lots et des ventes', function () {
    actingAs('admin');
    eq(['0001_doublons'], App\Core\Addons::install('doublons', false), 'installation');
    App\Core\Addons::reset();
    $D = Addons\Doublons\DoublonService::class;
    eq($D::key('Huile végétale 5 L'), $D::key(' HUILE vegetale-5l '), 'clé : accents, casse, espaces, ponctuation');
    $a = DB::insert('products', ['reference' => 'DBL-A', 'name' => 'Café moulu 250 g', 'barcode' => '6009990001112', 'sale_price' => 1500, 'cost_price' => 1000, 'track_stock' => 1, 'status' => 'active']);
    $b = DB::insert('products', ['reference' => 'DBL-B', 'name' => 'cafe MOULU 250g', 'sale_price' => 1400, 'cost_price' => 1200, 'track_stock' => 1, 'status' => 'active']);
    $c = DB::insert('products', ['reference' => 'DBL-C', 'name' => 'Café moulu 500 g', 'sale_price' => 2800, 'track_stock' => 1, 'status' => 'active']);
    S\StockService::receive($a, 1, 10, 1000, 'adjustment', null, null, 'test');
    S\StockService::receive($b, 1, 30, 1200, 'adjustment', null, null, 'test');
    actingAs('caissier');
    if (!S\CashService::openSessionForRegister(1)) {
        S\CashService::open(1, 0);
    }
    S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $b, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 5000]]]);
    actingAs('admin');
    $scan = $D::scan(false, true);
    $grp = array_values(array_filter($scan['same'], fn ($g) => in_array($a, $g, true)))[0] ?? [];
    eq([$a, $b], $grp, 'doublon détecté');
    ok(!in_array($c, $grp, true), '250 g et 500 g jamais rapprochés');
    $cmp = $D::compare($grp);
    eq($b, $cmp['keep'], 'conserver la fiche vendue');
    $r = $D::merge($cmp['keep'], [$a]);
    ok($r[0]['deleted'], 'doublon supprimé');
    ok(!DB::value('SELECT 1 FROM products WHERE id = ?', [$a]), 'plus de fiche en double');
    eq(38.0, stock($b), 'stock additionné (10 + 30 - 2)');
    eq('6009990001112', DB::value('SELECT barcode FROM products WHERE id = ?', [$b]), 'code-barres repris');
    eq(1, (int) DB::value('SELECT COUNT(*) FROM sale_items WHERE product_id = ?', [$b]), 'ventes conservées');
    eq(0, (int) DB::value('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$a]), 'mouvements transférés');
    eq(1, (int) DB::value('SELECT COUNT(*) FROM doublons_journal WHERE removed_id = ?', [$a]), 'journal');
    throws(fn () => $D::merge($b, [$b]), 'Aucun doublon');
});

test('Module ONG : budget par ligne, circuit des dépenses (séparation des tâches), fonds, mission avec avance et justification, stocks', function () use ($B) {
    actingAs('admin');
    eq(['0001_ong', '0002_lien_grh'], App\Core\Addons::install('ong', false), 'installation');
    App\Core\Addons::reset();
    $O = Addons\Ong\OngService::class;
    $bail = $O::saveSimple('bailleur', ['name' => 'Fonds Sahel Eau', 'kind' => 'institution', 'active' => 1]);
    $proj = $O::saveSimple('projet', ['code' => 'WASH-26', 'name' => 'Accès à l\'eau potable', 'status' => 'en_cours']);
    throws(fn () => $O::saveSimple('projet', ['code' => 'WASH-26', 'name' => 'Doublon']), 'déjà ce code');
    $y = date('Y');
    $conv = $O::saveConvention(['number' => 'FSE/2026/014', 'title' => 'Forages villageois', 'bailleur_id' => $bail, 'projet_id' => $proj, 'amount' => 10000000, 'start_date' => "$y-01-01", 'end_date' => "$y-12-31", 'status' => 'active']);
    $lAct = $O::saveLigne($conv, ['code' => 'B1', 'label' => 'Forages', 'category' => 'activites', 'budget' => 6000000]);
    $lMis = $O::saveLigne($conv, ['code' => 'D1', 'label' => 'Missions de suivi', 'category' => 'deplacements', 'budget' => 500000]);
    throws(fn () => $O::saveLigne($conv, ['code' => 'E1', 'label' => 'Trop', 'category' => 'autre', 'budget' => 4000000]), 'dépasserait le montant de la convention');
    $bank = $O::saveSimple('compte', ['name' => 'BIA projet WASH', 'kind' => 'banque', 'active' => 1]);
    $cash = $O::saveSimple('compte', ['name' => 'Caisse terrain', 'kind' => 'caisse', 'active' => 1]);
    // Fonds du bailleur, virement interne
    $O::receive(['type' => 'fonds_bailleur', 'convention_id' => $conv, 'compte_id' => $bank, 'amount' => 4000000, 'op_date' => "$y-02-01", 'reference' => 'AVIS-1']);
    $O::transfer($bank, $cash, 300000, "$y-02-02", 'Approvisionnement');
    eq(3700000.0, (float) DB::value('SELECT balance FROM ong_comptes WHERE id = ?', [$bank]), 'solde banque');
    throws(fn () => $O::transfer($cash, $bank, 999999, "$y-02-03"), 'Solde insuffisant');
    // Dépense : dates, budget, circuit
    throws(fn () => $O::submitDepense(['convention_id' => $conv, 'ligne_id' => $lAct, 'amount' => 1000, 'payee' => 'X', 'description' => 'Hors période', 'exp_date' => ($y - 1) . '-12-31']), 'hors de la période');
    throws(fn () => $O::submitDepense(['convention_id' => $conv, 'ligne_id' => $lAct, 'amount' => 6000001, 'payee' => 'Foreur', 'description' => 'Trop cher', 'exp_date' => "$y-03-01"]), 'Dépassement de budget');
    $d = $O::submitDepense(['convention_id' => $conv, 'ligne_id' => $lAct, 'amount' => 2500000, 'payee' => 'Entreprise Forage Niger', 'description' => 'Forage de Kollo', 'piece' => 'FAC-88', 'exp_date' => "$y-03-01"]);
    throws(fn () => $O::payDepense($d, $bank), 'doit être approuvée');
    throws(fn () => $O::approveDepense($d), 'Séparation des tâches');
    actingAs('gerant');
    $O::approveDepense($d);
    $O::payDepense($d, $bank, "$y-03-05");
    eq('payee', DB::value('SELECT status FROM ong_depenses WHERE id = ?', [$d]));
    eq(1200000.0, (float) DB::value('SELECT balance FROM ong_comptes WHERE id = ?', [$bank]), 'banque après paiement');
    $bud = $O::budget($conv);
    $b1 = array_values(array_filter($bud['lines'], fn ($l) => $l['code'] === 'B1'))[0];
    eq([2500000.0, 3500000.0], [(float) $b1['paid'], (float) $b1['available']], 'ligne B1 : payé et disponible');
    eq(1500000.0, $bud['total']['cash'], 'trésorerie de la convention = reçu − payé');
    // Dépense rejetée : ne consomme plus le budget
    actingAs('admin');
    $d2 = $O::submitDepense(['convention_id' => $conv, 'ligne_id' => $lAct, 'amount' => 3500000, 'payee' => 'Foreur 2', 'description' => 'Forage de Say', 'exp_date' => "$y-03-10"]);
    throws(fn () => $O::submitDepense(['convention_id' => $conv, 'ligne_id' => $lAct, 'amount' => 1, 'payee' => 'Y', 'description' => 'Plus de budget', 'exp_date' => "$y-03-10"]), 'Dépassement');
    actingAs('gerant');
    $O::rejectDepense($d2, 'Devis non conforme');
    eq(3500000.0, 6000000 - $O::engaged($lAct), 'budget libéré par le rejet');
    // Mission : demande, approbation, avance, départ, retour, clôture avec reliquat
    actingAs('admin');
    $chef = $O::saveSimple('personnel', ['matricule' => 'ONG-001', 'full_name' => 'Halima Abdou', 'position' => 'Chargée de suivi', 'kind' => 'salarie', 'active' => 1]);
    $agent = $O::saveSimple('personnel', ['matricule' => 'ONG-002', 'full_name' => 'Issa Garba', 'position' => 'Chauffeur', 'kind' => 'salarie', 'active' => 1]);
    $veh = $O::saveSimple('vehicule', ['plate' => '8N-1234-RN', 'label' => 'Toyota Hilux', 'kind' => '4x4', 'status' => 'disponible', 'odometer' => 52000]);
    $m = $O::requestMission(['convention_id' => $conv, 'ligne_id' => $lMis, 'title' => 'Suivi des forages', 'destination' => 'Kollo', 'start_date' => "$y-04-01", 'end_date' => "$y-04-03", 'chef_id' => $chef, 'vehicule_id' => $veh, 'other_costs' => 30000],
        [['personnel_id' => $chef, 'per_diem' => 15000, 'days' => 3], ['personnel_id' => $agent, 'per_diem' => 10000, 'days' => 3]]);
    eq(105000.0, (float) DB::value('SELECT estimate FROM ong_missions WHERE id = ?', [$m]), 'coût prévu : per diem + autres frais');
    throws(fn () => $O::startMission($m), 'approuvée avant le départ');
    actingAs('gerant');
    $O::approveMission($m);
    eq(105000.0, $O::engaged($lMis), 'mission approuvée = budget engagé');
    $O::advanceMission($m, $cash, 100000, "$y-03-31");
    $O::startMission($m);
    eq('en_mission', DB::value('SELECT status FROM ong_vehicules WHERE id = ?', [$veh]));
    throws(fn () => $O::logTrip(['vehicule_id' => $veh, 'driver' => 'Issa', 'km_start' => 51000, 'km_end' => 51100]), 'inférieur au compteur');
    $O::logTrip(['vehicule_id' => $veh, 'mission_id' => $m, 'driver' => 'Issa Garba', 'route' => 'Niamey – Kollo', 'km_start' => 52000, 'km_end' => 52070, 'fuel_liters' => 9, 'fuel_amount' => 6000]);
    eq(52070, (int) DB::value('SELECT odometer FROM ong_vehicules WHERE id = ?', [$veh]));
    throws(fn () => $O::finishMission($m, 'court'), 'Rapport de mission obligatoire');
    $O::finishMission($m, 'Trois forages visités, deux fonctionnels, un en panne de pompe : réparation à prévoir.');
    eq('disponible', DB::value('SELECT status FROM ong_vehicules WHERE id = ?', [$veh]), 'véhicule libéré au retour');
    $O::closeMission($m, 92000, $cash, 'PJ-MIS-1');
    eq(['cloturee', 92000.0], [DB::value('SELECT status FROM ong_missions WHERE id = ?', [$m]), (float) DB::value('SELECT justified FROM ong_missions WHERE id = ?', [$m])]);
    eq(300000.0 - 100000 + 8000, (float) DB::value('SELECT balance FROM ong_comptes WHERE id = ?', [$cash]), 'caisse : avance sortie, reliquat de 8 000 reversé');
    eq(92000.0, $O::engaged($lMis), 'ligne mission : seuls les frais justifiés restent imputés');
    eq(92000.0, (float) DB::value("SELECT amount FROM ong_depenses WHERE mission_id = ? AND status = 'payee'", [$m]), 'dépense de mission créée');
    // Annulation d'une dépense payée : paiement contre-passé ; mouvement issu d'une dépense non annulable à la main
    throws(fn () => $O::cancelMovement((int) DB::value('SELECT id FROM ong_mouvements WHERE depense_id = ?', [$d]), 'erreur'), 'annulez la dépense');
    $O::cancelDepense($d, 'Facture en double');
    eq(3700000.0, (float) DB::value('SELECT balance FROM ong_comptes WHERE id = ?', [$bank]), 'banque rétablie');
    eq(1, (int) DB::value('SELECT COUNT(*) FROM ong_depenses WHERE id = ? AND status = ?', [$d, 'annulee']), 'dépense conservée, annulée');
    // Stocks : jamais négatif, distribution avec site
    $art = $O::saveSimple('article', ['name' => 'Kit d\'hygiène', 'unit' => 'kit', 'alert' => 20]);
    $O::stockMove(['article_id' => $art, 'type' => 'don', 'quantity' => 100]);
    throws(fn () => $O::stockMove(['article_id' => $art, 'type' => 'distribution', 'quantity' => 10]), 'indiquez le site');
    throws(fn () => $O::stockMove(['article_id' => $art, 'type' => 'distribution', 'quantity' => 101, 'site' => 'Kollo']), 'Stock insuffisant');
    $O::stockMove(['article_id' => $art, 'type' => 'distribution', 'quantity' => 85, 'site' => 'Village de Kollo', 'beneficiaries' => 85, 'projet_id' => $proj]);
    eq(15.0, (float) DB::value('SELECT stock FROM ong_articles WHERE id = ?', [$art]));
    eq(1, count($O::dashboard()['stockAlerts']), 'alerte de stock sous le seuil');
    $O::saveIndicateur($proj, ['label' => 'Forages réalisés', 'unit' => 'forage', 'target' => 12, 'achieved' => 3]);
    // Pages
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    foreach (['/ong', '/ong/projets', '/ong/projets?onglet=bailleurs', "/ong/projets/$proj", "/ong/projets?edit=$proj", '/ong/conventions', "/ong/conventions/$conv", "/ong/conventions/$conv?ligne=$lAct", "/ong/conventions/$conv/rapport", "/ong/conventions/$conv?export=csv",
        '/ong/depenses', "/ong/depenses/$d", '/ong/depenses?export=pdf', '/ong/tresorerie', "/ong/tresorerie?edit=$bank", '/ong/tresorerie?export=csv', '/ong/missions', "/ong/missions/$m", "/ong/missions/$m/ordre",
        '/ong/logistique', '/ong/logistique?onglet=equipements', '/ong/logistique?onglet=stocks', "/ong/logistique?edit=$veh", '/ong/personnel', "/ong/personnel?edit=$chef", '/ong/personnel?export=csv'] as $u) {
        $x = $a->req('GET', $B . $u);
        ok($x['code'] === 200 && !preg_match('/Fatal error|Warning:|Notice:|Deprecated:|Une erreur interne/', $x['body']), "$u ({$x['code']})");
    }
    $x = $a->req('GET', "$B/ong/conventions/$conv/rapport");
    ok(str_contains($x['body'], 'RAPPORT FINANCIER') && str_contains($x['body'], 'Fonds Sahel Eau'), 'rapport financier du bailleur');
});

test('Module RH : contrats, congés (jours ouvrés, solde, chevauchement), présences, avances, paie (calcul, validation, verrouillage), sortie', function () use ($B) {
    actingAs('admin');
    eq(['0001_grh'], App\Core\Addons::install('grh', false), 'installation');
    App\Core\Addons::reset();
    $G = Addons\Grh\GrhService::class;
    $dg = $G::saveDepartement(['name' => 'Direction générale']);
    $fin = $G::saveDepartement(['name' => 'Finances', 'parent_id' => $dg]);
    throws(fn () => $G::saveDepartement(['name' => 'Direction générale', 'parent_id' => $fin], $dg), 'dépendre de lui-même');
    $poste = $G::savePoste(['title' => 'Comptable', 'departement_id' => $fin, 'grade' => 'B2', 'base_salary' => 200000]);
    // Embauche il y a 14 mois : 14 × 2,5 = 35 jours acquis
    $hire = date('Y-m-d', strtotime('first day of -14 months'));
    $e = $G::saveEmploye(['matricule' => 'EMP-0001', 'civility' => 'Mme', 'last_name' => 'Maïga', 'first_name' => 'Fatouma', 'sex' => 'F', 'hire_date' => $hire, 'departement_id' => $fin, 'poste_id' => $poste, 'email' => 'f.maiga@example.org']);
    throws(fn () => $G::saveEmploye(['matricule' => 'EMP-0001', 'last_name' => 'X', 'first_name' => 'Y', 'hire_date' => $hire]), 'matricule est déjà utilisé');
    throws(fn () => $G::saveEmploye(['matricule' => 'EMP-0002', 'last_name' => 'X', 'first_name' => 'Y', 'hire_date' => $hire, 'email' => 'pas-un-email']), 'e-mail invalide');
    // Contrats : CDD sans fin refusé ; un nouveau contrat termine le précédent la veille
    throws(fn () => $G::saveContrat($e, ['kind' => 'cdd', 'start_date' => $hire, 'base_salary' => 150000]), 'a une date de fin');
    $c1 = $G::saveContrat($e, ['kind' => 'essai', 'start_date' => $hire, 'end_date' => date('Y-m-d', strtotime($hire . ' +3 months -1 day')), 'base_salary' => 150000]);
    $start2 = date('Y-m-d', strtotime($hire . ' +3 months'));
    $c2 = $G::saveContrat($e, ['kind' => 'cdi', 'start_date' => $start2, 'base_salary' => 200000, 'allowances' => 40000]);
    eq(['termine', date('Y-m-d', strtotime($start2 . ' -1 day'))], array_values(DB::one('SELECT status, end_date FROM grh_contrats WHERE id = ?', [$c1])), 'contrat précédent terminé la veille');
    eq($c2, (int) $G::currentContract($e)['id'], 'un seul contrat en cours');
    // Congés
    eq(5.0, $G::workingDays('2026-11-09', '2026-11-15'), 'lundi → dimanche = 5 jours ouvrés');
    $bal = $G::leaveBalance($e);
    eq([14, 35.0], [$bal['months'], $bal['acquired']], 'solde acquis');
    $mon = date('Y-m-d', strtotime('monday next week'));
    $l1 = $G::requestLeave(['employe_id' => $e, 'kind' => 'annuel', 'start_date' => $mon, 'end_date' => date('Y-m-d', strtotime($mon . ' +11 days'))]);
    eq(10.0, (float) DB::value('SELECT days FROM grh_conges WHERE id = ?', [$l1]), 'deux semaines = 10 jours ouvrés');
    throws(fn () => $G::requestLeave(['employe_id' => $e, 'kind' => 'maladie', 'start_date' => date('Y-m-d', strtotime($mon . ' +2 days')), 'end_date' => date('Y-m-d', strtotime($mon . ' +3 days'))]), 'couvre déjà');
    throws(fn () => $G::decideLeave($l1, false, ''), 'Motif du refus');
    $G::decideLeave($l1, true);
    eq(25.0, $G::leaveBalance($e)['balance'], 'solde après approbation');
    $far = date('Y-m-d', strtotime($mon . ' +8 weeks'));
    $l2 = $G::requestLeave(['employe_id' => $e, 'kind' => 'annuel', 'start_date' => $far, 'end_date' => date('Y-m-d', strtotime($far . ' +60 days'))]);
    throws(fn () => $G::decideLeave($l2, true), 'Solde de congé insuffisant');
    $G::decideLeave($l2, false, 'Période trop longue');
    $G::cancelLeave($l1, 'Reporté');
    eq(35.0, $G::leaveBalance($e)['balance'], 'jours rendus au solde après annulation');
    // Présences du mois dernier : 2 absences
    $pm = date('Y-m', strtotime('first day of last month'));
    throws(fn () => $G::markDay(date('Y-m-d', strtotime('+1 day')), [$e => 'present']), 'jour à venir');
    $G::markDay("$pm-03", [$e => 'absent']);
    $G::markDay("$pm-04", [$e => 'absent']);
    $G::markDay("$pm-05", [$e => 'present']);
    // Avance : plafond du tiers du salaire
    throws(fn () => $G::grantAvance(['employe_id' => $e, 'amount' => 300000, 'monthly' => 100000, 'granted_on' => "$pm-01"]), 'tiers du salaire');
    $av = $G::grantAvance(['employe_id' => $e, 'amount' => 90000, 'monthly' => 30000, 'granted_on' => "$pm-01"]);
    // Paie du mois dernier : base 200 000 + primes 40 000 − 2 jours (200 000 / 30 × 2 = 13 333) = 226 667 ; cotisation 5,25 % = 11 900
    throws(fn () => $G::generatePaie(date('Y-m', strtotime('first day of next month'))), 'mois à venir');
    $paie = $G::generatePaie($pm);
    $b = DB::one('SELECT * FROM grh_bulletins WHERE paie_id = ? AND employe_id = ?', [$paie, $e]);
    eq([2.0, 13333.0, 226667.0, 11900.0, 30000.0], [(float) $b['absence_days'], (float) $b['absence_deduction'], (float) $b['gross'], (float) $b['cnss'], (float) $b['advance']], 'brut, cotisation, avance');
    eq(226667.0 - 11900 - 30000, (float) $b['net'], 'net à payer');
    $G::updateBulletin((int) $b['id'], ['bonus' => 25000, 'overtime' => 0, 'tax' => '8000', 'other_deduction' => 0, 'note' => 'Prime de bilan']);
    $b = DB::one('SELECT * FROM grh_bulletins WHERE id = ?', [$b['id']]);
    eq([251667.0, 13213.0, 8000.0, 251667.0 - 13213 - 8000 - 30000], [(float) $b['gross'], (float) $b['cnss'], (float) $b['tax'], (float) $b['net']], 'bulletin recalculé avec prime et impôt saisi');
    $G::generatePaie($pm); // recalcul : les éléments saisis sont conservés
    eq([25000.0, 8000.0], array_map('floatval', array_values(DB::one('SELECT bonus, tax FROM grh_bulletins WHERE id = ?', [$b['id']]))), 'recalcul sans perte des saisies');
    throws(fn () => $G::payPaie($paie), 'doit être validée');
    $G::validatePaie($paie);
    eq(60000.0, (float) DB::value('SELECT remaining FROM grh_avances WHERE id = ?', [$av]), 'avance retenue à la validation');
    throws(fn () => $G::updateBulletin((int) $b['id'], ['bonus' => 1]), 'déjà validée');
    throws(fn () => $G::generatePaie($pm), 'déjà validée');
    throws(fn () => $G::markDay("$pm-06", [$e => 'absent']), 'paie de ce mois est validée');
    throws(fn () => $G::cancelAvance($av), 'déjà été retenue');
    $G::payPaie($paie);
    eq('payee', DB::value('SELECT status FROM grh_paies WHERE id = ?', [$paie]));
    $G::addDossier($e, ['kind' => 'evaluation', 'title' => 'Évaluation annuelle', 'score' => '15,5', 'detail' => 'Objectifs atteints.']);
    throws(fn () => $G::addDossier($e, ['kind' => 'evaluation', 'title' => 'Hors barème', 'score' => 25]), 'entre 0 et 20');
    $d = $G::dashboard();
    eq([1, 1, 240000.0], [$d['actifs'], $d['femmes'], $d['masse']], 'tableau de bord');
    eq('Finances', $G::tree()[0]['children'][0]['name'], 'organigramme en arbre');
    // Pages
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    foreach (['/grh', '/grh/employes', '/grh/employes?q=Ma&status=tous', '/grh/employes?export=csv', '/grh/employes/fiche', "/grh/employes/fiche?id=$e", "/grh/employes/$e", "/grh/employes/$e/attestation", '/grh/structure', "/grh/structure?departement=$fin", "/grh/structure?poste=$poste",
        '/grh/conges', '/grh/conges?status=refuse', '/grh/presences', "/grh/presences?jour=$pm-03", '/grh/presences?export=pdf', '/grh/paie', "/grh/paie/$paie", "/grh/paie/$paie?export=csv", '/grh/bulletins/' . $b['id'], '/grh/avances'] as $u) {
        $x = $a->req('GET', $B . $u);
        ok($x['code'] === 200 && !preg_match('/Fatal error|Warning:|Notice:|Deprecated:|Une erreur interne/', $x['body']), "$u ({$x['code']})");
    }
    ok(str_contains($a->req('GET', "$B/grh/bulletins/" . $b['id'])['body'], 'BULLETIN DE PAIE'), 'bulletin imprimable');
    // Sortie : contrat terminé, fiche conservée
    $G::exitEmploye($e, date('Y-m-d'), 'Démission');
    eq(['sorti', 0], [DB::value('SELECT status FROM grh_employes WHERE id = ?', [$e]), (int) DB::value("SELECT COUNT(*) FROM grh_contrats WHERE employe_id = ? AND status = 'en_cours'", [$e])], 'sortie');
    ok(str_contains($a->req('GET', "$B/grh/employes/$e/attestation")['body'], 'CERTIFICAT DE TRAVAIL'), 'certificat de travail après la sortie');
});

test('Mode Organisation : pas de caisse ni de menus commerciaux ; personnel ONG repris des RH', function () use ($B) {
    actingAs('admin');
    $M = S\BusinessMode::class;
    $before = $M::current();
    $M::set('organisation');
    ok(!$M::hasPos() && $M::posUrl() === '', 'aucune caisse en mode Organisation');
    foreach (['/pos', '/sales', '/products', '/stock', '/cash', '/customers', '/dashboard', '/banques', '/expenses'] as $u) {
        ok($M::hides($u), "menu masqué : $u");
    }
    foreach (['/ong', '/grh/employes', '/settings', '/users'] as $u) {
        ok(!$M::hides($u), "menu conservé : $u");
    }
    eq('/ong', $M::homeUrl(), 'accueil = tableau de bord ONG');
    $menu = S\Menu::visible();
    eq('ONG', array_key_first($menu), 'groupe ONG en tête');
    ok(!isset($menu['Gestion des ventes']) && !isset($menu['Gestion des articles']), 'groupes commerciaux absents');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $x = $a->req('GET', "$B/ong");
    ok($x['code'] === 200 && !str_contains($x['body'], 'btn-pos-launch'), 'bouton « Ouvrir la caisse » absent');
    $M::set($before);
    ok(str_contains($a->req('GET', "$B/ong")['body'], 'btn-pos-launch'), 'bouton présent en mode commerce');
    // Personnel ONG ↔ employés RH : une seule fiche à tenir
    $O = Addons\Ong\OngService::class; $G = Addons\Grh\GrhService::class;
    $e = $G::saveEmploye(['matricule' => 'EMP-0100', 'last_name' => 'Souley', 'first_name' => 'Aïcha', 'sex' => 'F', 'hire_date' => date('Y-m-d', strtotime('-2 months')), 'phone' => '90000000']);
    $G::saveContrat($e, ['kind' => 'cdi', 'start_date' => date('Y-m-d', strtotime('-2 months')), 'base_salary' => 180000, 'allowances' => 20000]);
    $r = $O::syncFromGrh();
    ok($r['created'] >= 1, 'employé repris dans le personnel ONG');
    $p = DB::one('SELECT * FROM ong_personnel WHERE grh_employe_id = ?', [$e]);
    eq(['Aïcha Souley', 200000.0, 1], [$p['full_name'], (float) $p['monthly_cost'], (int) $p['active']], 'fiche reprise');
    $proj = (int) DB::value('SELECT id FROM ong_projets LIMIT 1');
    $O::saveSimple('personnel', ['matricule' => 'X', 'full_name' => 'Nom modifié ici', 'kind' => 'salarie', 'projet_id' => $proj], (int) $p['id']);
    $p = DB::one('SELECT * FROM ong_personnel WHERE id = ?', [$p['id']]);
    eq(['Aïcha Souley', $proj], [$p['full_name'], (int) $p['projet_id']], 'fiche liée : seule l\'affectation se règle côté ONG');
    $G::exitEmploye($e, date('Y-m-d'), 'Fin de mission');
    $O::syncFromGrh();
    eq(0, (int) DB::value('SELECT active FROM ong_personnel WHERE grh_employe_id = ?', [$e]), 'sortie RH répercutée');
    ok(str_contains($a->req('GET', "$B/ong/personnel")['body'], 'Reprendre les employés des RH'), 'bouton de reprise affiché');
});

test('Boutique en ligne : compte client (inscription, connexion, blocage), commande rattachée, historique et reçu', function () use ($B) {
    actingAs('admin');
    ok(isset(App\Core\Addons::active()['boutique']), 'module actif');
    $v = new Http();
    $page = $v->req('GET', "$B/boutique/compte");
    ok($page['code'] === 200 && str_contains($page['body'], 'Créer mon compte'), 'page du compte');
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    ok($csrf !== '', 'jeton');
    $form = fn (string $path, array $d) => $v->req('POST', "$B$path", $d + ['_csrf' => $csrf]);
    $form('/boutique/compte/inscription', ['name' => 'Client Test', 'phone' => '+227 96 11 22 33', 'password' => 'court']);
    eq(0, (int) DB::value('SELECT COUNT(*) FROM shop_accounts'), 'mot de passe trop court refusé');
    $form('/boutique/compte/inscription', ['name' => 'Client Test', 'phone' => '+227 96 11 22 33', 'password' => 'Marche-2026', 'address' => 'Quartier Plateau', 'website' => 'http://robot']);
    eq(0, (int) DB::value('SELECT COUNT(*) FROM shop_accounts'), 'champ piège : robot refusé');
    $form('/boutique/compte/inscription', ['name' => 'Client Test', 'phone' => '+227 96 11 22 33', 'password' => 'Marche-2026', 'address' => 'Quartier Plateau']);
    $acc = DB::one('SELECT * FROM shop_accounts');
    ok($acc && $acc['phone_key'] === '22796112233' && password_verify('Marche-2026', $acc['password_hash']) && !str_contains($acc['password_hash'], 'Marche'), 'compte créé, mot de passe haché');
    $page = $v->req('GET', "$B/boutique/compte");
    ok(str_contains($page['body'], 'Bonjour Client') && str_contains($page['body'], 'Quartier Plateau'), 'connecté après l\'inscription, coordonnées enregistrées');
    // Commande passée connecté : rattachée au compte, coordonnées proposées
    $shop = $v->req('GET', "$B/boutique/panier");
    ok(str_contains($shop['body'], '"account":{"name":"Client Test"'), 'coordonnées du compte fournies au panier');
    preg_match('/"csrf":"([a-f0-9]+)"/', $shop['body'], $m);
    $pid = (int) DB::value("SELECT p.id FROM products p JOIN stock_levels sl ON sl.product_id = p.id WHERE p.status = 'active' AND p.sellable = 1 AND sl.quantity >= 3 AND p.requires_prescription = 0 AND p.sales_channel <> 'restaurant' ORDER BY p.id LIMIT 1");
    $r = $v->req('POST', "$B/boutique/commande", json_encode(['_csrf' => $m[1], 'name' => 'Client Test', 'phone' => '+227 96 11 22 33', 'delivery' => 'pickup', 'items' => [['id' => $pid, 'qty' => 1]]]), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]]);
    $j = json_decode($r['body'], true);
    ok(!empty($j['ok']), 'commande : ' . $r['body']);
    eq((int) $acc['id'], (int) DB::value('SELECT account_id FROM shop_orders WHERE number = ?', [$j['number']]), 'commande rattachée au compte');
    $page = $v->req('GET', "$B/boutique/compte");
    ok(str_contains($page['body'], $j['number']) && str_contains($page['body'], 'Voir le reçu'), 'historique et reçu dans le compte');
    // Un autre visiteur ne voit pas ce compte ; mauvais mot de passe : blocage après 5 essais
    $w = new Http();
    $p2 = $w->req('GET', "$B/boutique/compte");
    ok(!str_contains($p2['body'], $j['number']), 'compte invisible sans connexion');
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $p2['body'], $m2);
    for ($i = 0; $i < 5; $i++) {
        $w->req('POST', "$B/boutique/compte/connexion", ['_csrf' => $m2[1], 'phone' => '96112233', 'password' => 'mauvais']);
    }
    ok((string) DB::value('SELECT locked_until FROM shop_accounts WHERE id = ?', [$acc['id']]) > date('Y-m-d H:i:s'), 'compte bloqué après 5 échecs');
    DB::update('shop_accounts', ['locked_until' => null], 'id = ?', [$acc['id']]);
    $w->req('POST', "$B/boutique/compte/connexion", ['_csrf' => $m2[1], 'phone' => '0022796112233', 'password' => 'Marche-2026']);
    ok(str_contains($w->req('GET', "$B/boutique/compte")['body'], $j['number']), 'connexion par le numéro, avec ou sans indicatif');
    // Suppression du compte par le client : la commande reste, sans lien
    $form('/boutique/compte/supprimer', ['password' => 'Marche-2026']);
    eq([0, 1], [(int) DB::value('SELECT COUNT(*) FROM shop_accounts'), (int) DB::value('SELECT COUNT(*) FROM shop_orders WHERE number = ? AND account_id IS NULL', [$j['number']])], 'compte supprimé, commande conservée');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    eq(200, $a->req('GET', "$B/boutique-admin/clients")['code'], 'liste des clients côté gestion');
});

test('Module Archives : dépôt contrôlé, versions, intégrité, confidentiel, corbeille, suppression confirmée', function () use ($B) {
    actingAs('admin');
    eq(['0001_archives'], App\Core\Addons::install('archives', false), 'installation');
    App\Core\Addons::reset();
    $A = Addons\Archives\ArchiveService::class;
    $tmp = sys_get_temp_dir() . '/arch-' . bin2hex(random_bytes(4));
    $pdf = "$tmp.pdf"; file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    $pdf2 = "$tmp-2.pdf"; file_put_contents($pdf2, "%PDF-1.4\n1 0 obj<</Type/Catalog/V 2>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    $php = "$tmp.php"; file_put_contents($php, '<?php echo 1;');
    $fake = "$tmp-faux.pdf"; file_put_contents($fake, '<?php system($_GET["c"]); ?>');
    $dos = $A::saveDossier(['name' => 'Juridique']);
    $sub = $A::saveDossier(['name' => 'Statuts', 'parent_id' => $dos]);
    throws(fn () => $A::saveDossier(['name' => 'Statuts', 'parent_id' => $dos]), 'déjà ce nom');
    throws(fn () => $A::saveDossier(['name' => 'Juridique', 'parent_id' => $sub], $dos), 'dans lui-même');
    throws(fn () => $A::deposit($php, 'script.php', filesize($php), [], false), 'type de fichier non accepté');
    throws(fn () => $A::deposit($fake, 'statuts.pdf', filesize($fake), [], false), 'ne correspond pas à son extension');
    $r = $A::deposit($pdf, 'Statuts 2026.pdf', filesize($pdf), ['dossier_id' => $sub, 'category' => 'juridique', 'reference' => 'ST-01', 'tags' => 'Statuts, ONG , statuts', 'expires_on' => date('Y-m-d', strtotime('+10 days'))], false);
    $id = $r['id'];
    $d = $A::document($id);
    eq(['Statuts 2026', 'statuts, ong', 1], [$d['title'], $d['tags'], (int) $d['versions']], 'titre tiré du nom du fichier, étiquettes dédoublées');
    $v1 = DB::one('SELECT * FROM arch_versions WHERE document_id = ?', [$id]);
    ok(preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', $v1['stored_path']) && is_file($A::root() . '/' . $v1['stored_path']) && !str_starts_with($A::root(), PUBLIC_PATH), 'fichier rangé hors du dossier public, sous un nom aléatoire');
    eq(hash_file('sha256', $pdf), $v1['sha256'], 'empreinte enregistrée');
    ok($A::verify((int) $v1['id']), 'intégrité vérifiée');
    eq('Statuts 2026', $A::deposit($pdf, 'copie.pdf', filesize($pdf), [], false)['duplicate'], 'doublon de contenu signalé');
    throws(fn () => $A::addVersion($id, $pdf, 'Statuts 2026.pdf', filesize($pdf), ''), 'identique à une version');
    $A::addVersion($id, $pdf2, 'Statuts 2026 v2.pdf', filesize($pdf2), 'Avenant', false);
    eq(2, (int) $A::document($id)['versions'], 'deuxième version, la première conservée');
    file_put_contents($A::root() . '/' . $v1['stored_path'], 'altéré');
    ok(!$A::verify((int) $v1['id']), 'fichier altéré détecté');
    eq(1, $A::stats()['expiring'], 'échéance proche signalée');
    throws(fn () => $A::deleteDossier($sub), 'pas vide');
    // Confidentiel : invisible sans le droit
    $A::update($id, ['title' => 'Statuts 2026', 'category' => 'juridique', 'dossier_id' => $sub, 'confidential' => 1]);
    actingAs('gerant');
    throws(fn () => $A::document($id), 'introuvable');
    throws(fn () => $A::file((int) $v1['id']), 'introuvable');
    actingAs('admin');
    // Corbeille, restauration, suppression définitive confirmée par le titre
    throws(fn () => $A::purge($id, 'Statuts 2026'), 'd\'abord le document à la corbeille');
    $A::trash($id);
    $A::restore($id);
    $A::trash($id);
    throws(fn () => $A::purge($id, 'autre titre'), 'retapez exactement le titre');
    $files = array_column(DB::all('SELECT stored_path FROM arch_versions WHERE document_id = ?', [$id]), 'stored_path');
    $A::purge($id, 'statuts 2026');
    ok(!DB::value('SELECT id FROM arch_documents WHERE id = ?', [$id]) && !is_file($A::root() . '/' . $files[0]) && !is_file($A::root() . '/' . $files[1]), 'document et fichiers effacés');
    // Pages et téléchargement
    $keep = $A::deposit($pdf2, 'Bail du siège.pdf', filesize($pdf2), ['dossier_id' => $dos, 'category' => 'contrat'], false)['id'];
    $vid = (int) DB::value('SELECT id FROM arch_versions WHERE document_id = ?', [$keep]);
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    foreach (['/archives', "/archives?dossier=$dos", "/archives?dossier=$dos&modifier=1", '/archives?q=bail', '/archives?vue=echeances', '/archives?vue=corbeille', '/archives?export=csv', "/archives/documents/$keep", "/archives/documents/$keep?verifier=1"] as $u) {
        $x = $a->req('GET', $B . $u);
        ok($x['code'] === 200 && !preg_match('/Fatal error|Warning:|Notice:|Deprecated:|Une erreur interne/', $x['body']), "$u ({$x['code']})");
    }
    $f = $a->req('GET', "$B/archives/fichiers/$vid");
    ok($f['code'] === 200 && $f['body'] === file_get_contents($pdf2) && str_contains(strtolower($f['headers']), 'attachment') && str_contains(strtolower($f['headers']), 'nosniff'), 'téléchargement à l\'identique, en pièce jointe');
    ok(str_contains(strtolower($a->req('GET', "$B/archives/fichiers/$vid?voir=1")['headers']), 'application/pdf'), 'affichage du PDF dans le navigateur');
    ok(DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'archives.telecharge' AND entity_id = ?", [$keep]) >= 1, 'téléchargement inscrit au journal');
    array_map('unlink', [$pdf, $pdf2, $php, $fake]);
});

test('Hébergement en ligne / en local et synchronisation vers une base MySQL en ligne (copie exacte, sens unique)', function () use ($B, $env) {
    actingAs('admin');
    $Y = S\SyncService::class;
    setting_set('hosting_mode', '');
    ok(!$Y::guessOnline() && $Y::hostingMode() === 'local', 'localhost : détecté en local');
    $_SERVER['HTTP_HOST'] = 'caisse.exemple.ne';
    ok($Y::guessOnline(), 'nom de domaine public : détecté en ligne');
    $_SERVER['HTTP_HOST'] = '192.168.1.10';
    ok(!$Y::guessOnline(), 'adresse du réseau local : en local');
    unset($_SERVER['HTTP_HOST']);
    $Y::setHostingMode('online');
    ok($Y::isOnline() && !in_array('/printers', array_column(S\Menu::visible()['Configuration'] ?? [], 2), true), 'en ligne : imprimantes réseau masquées');
    throws(fn () => $Y::run(), 'part du poste local');
    $Y::setHostingMode('local');
    ok(in_array('/printers', array_column(S\Menu::visible()['Configuration'] ?? [], 2), true), 'en local : imprimantes affichées');
    // Base « en ligne » de test : une seconde base sur le même serveur
    $remoteDb = $env['DB_DATABASE'] . '_enligne';
    $root = new PDO("mysql:host={$env['DB_HOST']};charset=utf8mb4", $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("DROP DATABASE IF EXISTS `$remoteDb`");
    $root->exec("CREATE DATABASE `$remoteDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    throws(fn () => $Y::saveConfig(['host' => '127.0.0.1', 'database' => $env['DB_DATABASE'], 'username' => 'x', 'password' => 'x']), 'base locale elle-même');
    throws(fn () => $Y::saveConfig(['host' => 'bad host;', 'database' => $remoteDb, 'username' => 'x']), 'invalide');
    $Y::saveConfig(['host' => '127.0.0.1', 'port' => 3306, 'database' => $remoteDb, 'username' => $env['DB_USERNAME'], 'password' => 'mauvais-mot-de-passe', 'enabled' => 1, 'auto_minutes' => 15]);
    $stored = (string) setting('sync_db_password');
    ok(str_starts_with($stored, 'E1.') && !str_contains($stored, 'mauvais'), 'mot de passe chiffré dans les réglages');
    throws(fn () => $Y::test(), 'Connexion à la base en ligne impossible');
    $Y::saveConfig(['host' => '127.0.0.1', 'port' => 3306, 'database' => $remoteDb, 'username' => $env['DB_USERNAME'], 'password' => $env['DB_PASSWORD'], 'enabled' => 1, 'auto_minutes' => 15]);
    eq(0, $Y::test()['tables'], 'base en ligne vide');
    ok($Y::due(), 'synchronisation automatique due');
    $r = $Y::run();
    ok($r['finished'] && $r['rows'] > 100, 'première synchronisation : ' . $r['rows'] . ' lignes');
    ok(!$Y::due(), 'plus due juste après');
    $rdb = new PDO("mysql:host={$env['DB_HOST']};dbname=$remoteDb;charset=utf8mb4", $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cnt = fn (PDO $p, string $t) => (int) $p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    foreach (['sales', 'sale_items', 'products', 'stock_movements', 'users', 'cash_sessions', 'ong_depenses', 'grh_bulletins'] as $t) {
        eq($cnt(DB::connection(), $t), $cnt($rdb, $t), "table $t : même nombre de lignes");
    }
    eq((string) DB::value('SELECT SUM(total) FROM sales'), (string) $rdb->query('SELECT SUM(total) FROM sales')->fetchColumn(), 'mêmes montants de ventes');
    eq(0, (int) $rdb->query("SELECT COUNT(*) FROM settings WHERE skey LIKE 'sync\\_db%'")->fetchColumn(), 'accès à la base en ligne jamais envoyés en ligne');
    eq('online', $rdb->query("SELECT svalue FROM settings WHERE skey = 'hosting_mode'")->fetchColumn(), 'la copie se sait en ligne');
    eq('local', $Y::hostingMode(), 'le poste local reste local');
    // Deuxième passage sans changement : rien n'est renvoyé
    $again = $Y::run();
    eq(0, $again['rows'], 'rien à renvoyer quand rien n\'a changé : ' . implode(' ; ', $again['tables']));
    // Modification, ajout et suppression en local ; saisie directe en ligne : la copie redevient exacte
    $pid = (int) DB::value('SELECT id FROM products ORDER BY id LIMIT 1');
    DB::update('products', ['name' => 'Article renommé en local'], 'id = ?', [$pid]);
    $new = DB::insert('brands', ['name' => 'Marque synchro']);
    $gone = (int) DB::value('SELECT MAX(id) FROM audit_logs');
    DB::run('DELETE FROM audit_logs WHERE id = ?', [$gone]);
    $rdb->exec("UPDATE products SET name = 'Modifié en ligne' WHERE id = " . ((int) DB::value('SELECT id FROM products ORDER BY id DESC LIMIT 1')));
    $r = $Y::run();
    ok($r['rows'] > 0 && $r['rows'] < 9000, 'seuls les blocs modifiés sont renvoyés (' . $r['rows'] . ' lignes)');
    eq('Article renommé en local', $rdb->query("SELECT name FROM products WHERE id = $pid")->fetchColumn(), 'modification locale reçue');
    eq('Marque synchro', $rdb->query("SELECT name FROM brands WHERE id = $new")->fetchColumn(), 'ajout reçu');
    eq(0, (int) $rdb->query("SELECT COUNT(*) FROM audit_logs WHERE id = $gone")->fetchColumn(), 'suppression répercutée');
    eq(0, (int) $rdb->query("SELECT COUNT(*) FROM products WHERE name = 'Modifié en ligne'")->fetchColumn(), 'saisie faite en ligne remplacée par la référence locale');
    // Reprise par étapes (comme l'écran) ; base étrangère : confirmation exigée
    $step = $Y::run(0, 1);
    ok($step['finished'] || $step['next'] > 0, 'synchronisation par étapes');
    $rdb->exec('DELETE FROM `_posmarwa_sync`');
    $rdb->exec("INSERT INTO `_posmarwa_sync` (install_id) VALUES ('PW-AUTR-EPOS-TE00-0000')");
    setting_set('sync_overwrite_ok', '');
    throws(fn () => $Y::run(), 'ne viennent pas de ce poste');
    ok($Y::run(0, 0, true)['finished'], 'après confirmation explicite');
    // Écran et étapes HTTP
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $x = $a->req('GET', "$B/settings/hosting");
    ok($x['code'] === 200 && str_contains($x['body'], 'Synchroniser maintenant') && !str_contains($x['body'], $env['DB_PASSWORD'] ?: '##aucun##') && !preg_match('/Warning:|Notice:|Fatal error/', $x['body']), 'écran Hébergement et synchronisation, sans mot de passe affiché');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $x['body'], $m);
    $j = json_decode($a->req('POST', "$B/settings/hosting/test", '{}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]])['body'], true);
    ok(!empty($j['ok']) && str_contains($j['message'], 'Connexion réussie'), 'test de connexion depuis l\'écran');
    $j = json_decode($a->req('POST', "$B/settings/hosting/sync", '{"from":0}', ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $m[1]])['body'], true);
    ok(!empty($j['ok']) && isset($j['total']), 'étape de synchronisation depuis l\'écran');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    ok(in_array($c->req('GET', "$B/settings/hosting")['code'], [302, 403], true), 'écran réservé aux paramètres');
    setting_set('sync_enabled', '0');
    $root->exec("DROP DATABASE IF EXISTS `$remoteDb`");
});

test('Modules : fichiers CSS/JS recopiés après une réinstallation, repli servi depuis addons/', function () use ($B) {
    // Réinstallation sur o2switch : base conservée (module déjà installé), dossier public/assets/addons absent
    $dst = PUBLIC_PATH . '/assets/addons/pressing';
    foreach (glob($dst . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($dst);
    @unlink(STORAGE_PATH . '/cache/addon_assets_pressing');
    ok(!is_file($dst . '/pressing.js'), 'fichiers absents (situation après réinstallation)');
    // Repli : servis depuis addons/pressing/public même avant la copie
    $a = new Http();
    $r = $a->req('GET', "$B/assets/addons/pressing/pressing.js");
    eq(200, $r['code'], 'pressing.js servi par le repli');
    ok(str_contains($r['headers'], 'javascript') && $r['body'] === file_get_contents(BASE_PATH . '/addons/pressing/public/pressing.js'), 'contenu et type exacts');
    eq(404, $a->req('GET', "$B/assets/addons/pressing/../routes.php")['code'], 'aucune sortie du dossier public du module');
    eq(404, $a->req('GET', "$B/assets/addons/pressing/manifest.php")['code'], 'jamais de PHP');
    // Copie automatique au chargement d'une page
    $a->req('GET', "$B/login");
    ok(is_file($dst . '/pressing.js') && is_file($dst . '/pressing-ecran.js') && is_file($dst . '/pressing.css'), 'fichiers recopiés automatiquement');
    // Fichier modifié par une mise à jour (même version du module) : recopié
    file_put_contents($dst . '/pressing.css', '/* ancienne version */');
    touch(BASE_PATH . '/addons/pressing/public/pressing.css');
    clearstatcache();
    App\Core\Addons::syncAssets();
    eq(file_get_contents(BASE_PATH . '/addons/pressing/public/pressing.css'), file_get_contents($dst . '/pressing.css'), 'fichier modifié recopié');
    // Formulaire de dépôt : le script est bien référencé
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $f = $a->req('GET', "$B/pressing/create")['body'];
    ok(str_contains($f, 'assets/addons/pressing/pressing.js') && str_contains($f, 'assets/addons/pressing/pressing.css'), 'formulaire : CSS et JS du module');
    // Sauvegarde : encodage déclaré (restauration phpMyAdmin / mysql sans accents abîmés)
    $bk = S\BackupService::create('test_encodage', false);
    $head = gzread($gz = gzopen(S\BackupService::dir() . '/' . $bk['sql'], 'rb'), 300);
    gzclose($gz);
    ok(str_contains($head, 'SET NAMES utf8mb4'), 'sauvegarde : SET NAMES utf8mb4');
});

test('Comptabilité générale : mise en place SYSCOHADA, écritures de la caisse, saisie, validation, extourne, lettrage, états, clôture', function () use ($B) {
    $C = Addons\Comptabilite\Compta::class;
    $R = Addons\Comptabilite\Reports::class;
    $I = Addons\Comptabilite\Integration::class;
    ok(!App\Core\Addons::isActive('comptabilite'), 'module non installé d\'office (activé par l\'utilisateur)');
    App\Core\Addons::install('comptabilite', false);
    App\Core\Addons::reset();
    ok(App\Core\Addons::isActive('comptabilite'), 'module actif après installation');
    $hasPerm = fn(string $role, string $perm) => (bool) DB::value('SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.code = ? AND p.code = ?', [$role, $perm]);
    ok($hasPerm('comptable', 'compta.admin') && $hasPerm('owner', 'compta.valider') && $hasPerm('auditor', 'compta.view') && !$hasPerm('auditor', 'compta.saisie') && !$hasPerm('cashier', 'compta.view'), 'droits : rôle Comptable créé, auditeur en lecture, caissier sans accès');
    actingAs('admin');
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    ok(str_contains($a->req('GET', "$B/compta")['body'], 'Mise en place de la comptabilité'), 'première ouverture : assistant de mise en place');
    ok(str_contains($a->req('GET', "$B/compta/saisie")['headers'], '/compta'), 'saisie impossible avant la mise en place');
    ok(str_contains($a->req('GET', "$B/compta")['body'], 'data-cat="Comptabilité"'), 'menu : catégorie « Comptabilité » ajoutée');

    // L'activité du jour (créée par les tests précédents) devient celle d'hier : seules les journées terminées sont reprises
    foreach (['sales' => ['created_at', 'cancelled_at'], 'sale_returns' => ['created_at'], 'customer_transactions' => ['created_at'], 'supplier_transactions' => ['created_at'],
              'cash_movements' => ['created_at'], 'bank_transactions' => ['op_date', 'cancelled_at']] as $t => $cols) {
        foreach ($cols as $col) {
            DB::run("UPDATE `$t` SET `$col` = `$col` - INTERVAL 1 DAY WHERE `$col` IS NOT NULL");
        }
    }
    DB::run('UPDATE expenses SET expense_date = expense_date - INTERVAL 1 DAY');
    $y = date('Y', strtotime('-1 day'));
    $yday = date('Y-m-d', strtotime('-1 day'));
    throws(fn() => $C::setup('inconnu', []), 'plan');
    throws(fn() => $C::setup('syscohada', ['code' => $y, 'date_debut' => "$y-12-31", 'date_fin' => "$y-01-01"]), 'date de fin');
    $ex = $C::setup('syscohada', ['code' => $y, 'date_debut' => "$y-01-01", 'date_fin' => "$y-12-31", 'generation_depuis' => "$y-01-01"]);
    ok((int) DB::value('SELECT COUNT(*) FROM cpt_comptes') > 600 && DB::value("SELECT libelle FROM cpt_comptes WHERE numero = '4431'") === 'TVA facturée sur ventes', 'plan SYSCOHADA révisé chargé');
    eq(['AC', 'AN', 'BQ', 'CA', 'MM', 'OD', 'VT'], array_column(DB::all('SELECT code FROM cpt_journaux ORDER BY code'), 'code'), 'journaux par défaut');
    eq('client', DB::value("SELECT tiers_type FROM cpt_comptes WHERE numero = '4111'"), '4111 : compte collectif clients');
    throws(fn() => $C::setup('syscohada', ['code' => 'X', 'date_debut' => "$y-01-01", 'date_fin' => "$y-12-31"]), 'déjà');
    throws(fn() => $C::saveExercice(['code' => 'CHEV', 'date_debut' => "$y-06-01", 'date_fin' => ($y + 1) . '-05-31']), 'chevauchent');

    // ------------------------------------------------------------- écritures de la caisse
    $st = $I::status();
    foreach (['ventes', 'reglements', 'achats', 'recettes', 'depenses', 'banques'] as $src) {
        ok(in_array($yday, $st['sources'][$src]['pending'], true), "source « $src » : la journée d'hier est à passer");
    }
    ok(!in_array(date('Y-m-d'), $st['sources']['ventes']['pending'], true), 'la journée en cours n\'est jamais reprise');
    $r = $I::run(array_keys($I::SOURCES), false);
    eq([], $r['errors'], 'génération sans erreur');
    eq(array_sum(array_map(fn($x) => count($x['pending']), $st['sources'])), $r['created'], 'une écriture par source et par journée d\'activité');
    eq(0, $I::run(array_keys($I::SOURCES), false)['created'], 'seconde génération : rien en double');
    eq($r['created'], (int) DB::value("SELECT COUNT(*) FROM cpt_ecritures WHERE statut = 'brouillard' AND origine LIKE 'pos\\_%'"), 'écritures au brouillard pour contrôle');
    [$okN, $errs] = $C::validateMany(array_column(DB::all('SELECT id FROM cpt_ecritures'), 'id'));
    eq([$r['created'], []], [$okN, $errs], 'validation de toutes les écritures');
    $solde = fn(string $num, ?int $tiers = null) => round((float) DB::value('SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM cpt_lignes l JOIN cpt_comptes c ON c.id = l.compte_id WHERE c.numero = ?' . ($tiers ? ' AND l.tiers_id = ' . $tiers : ''), [$num]), 2);
    // Ventes : produits HT + TVA = ventes − annulations − retours de la journée
    $per = ["$y-01-01", "$yday 23:59:59"];
    $exp = (float) DB::value('SELECT COALESCE(SUM(total), 0) FROM sales WHERE created_at BETWEEN ? AND ?', $per) - (float) DB::value('SELECT COALESCE(SUM(total), 0) FROM sales WHERE cancelled_at BETWEEN ? AND ?', $per)
        - (float) DB::value('SELECT COALESCE(SUM(total), 0) FROM sale_returns WHERE created_at BETWEEN ? AND ?', $per);
    eq(round($exp, 2), -($solde('7011') + $solde('4431')), 'ventes HT + TVA facturée = ventes − annulations − retours');
    ok($solde('4431') < 0, 'TVA facturée au crédit');
    // Chaque client et chaque fournisseur : même solde en comptabilité que dans la caisse
    foreach (DB::all('SELECT id, balance FROM customers WHERE id IN (SELECT customer_id FROM customer_transactions)') as $cu) {
        $tid = (int) DB::value("SELECT id FROM cpt_tiers WHERE source = 'customer' AND source_id = ?", [$cu['id']]);
        eq(round((float) $cu['balance'], 2), $solde('4111', $tid), 'client ' . $cu['id'] . ' : solde comptable = solde de la caisse');
    }
    foreach (DB::all('SELECT supplier_id, SUM(amount) AS bal FROM supplier_transactions GROUP BY supplier_id') as $su) {
        $tid = (int) DB::value("SELECT id FROM cpt_tiers WHERE source = 'supplier' AND source_id = ?", [$su['supplier_id']]);
        eq(-round((float) $su['bal'], 2), $solde('4011', $tid), 'fournisseur ' . $su['supplier_id'] . ' : dette comptable = dette de la caisse');
    }
    $soldeBq = fn(string $num) => round((float) DB::value("SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM cpt_lignes l JOIN cpt_comptes c ON c.id = l.compte_id JOIN cpt_ecritures e ON e.id = l.ecriture_id WHERE c.numero = ? AND e.origine = 'pos_banques'", [$num]), 2);
    eq(round((float) DB::value("SELECT COALESCE(SUM(balance), 0) FROM bank_accounts WHERE kind = 'banque'"), 2), $soldeBq('521'), 'opérations du module Banques : banque 521 = soldes des comptes bancaires');
    eq(round((float) DB::value("SELECT COALESCE(SUM(balance), 0) FROM bank_accounts WHERE kind IN ('coffre', 'proprietaire')"), 2), $soldeBq('5712'), 'coffre 5712 = coffres et fonds gardés par le propriétaire');
    ok((bool) DB::value("SELECT 1 FROM cpt_lignes l JOIN cpt_comptes c ON c.id = l.compte_id JOIN cpt_ecritures e ON e.id = l.ecriture_id WHERE c.numero = '521' AND e.origine = 'pos_reglements' AND l.credit = 5000"), 'paiement fournisseur par virement : sortie de la banque');
    ok($solde('7061') < 0, 'recettes clinique / pressing / établissement en prestations de services');
    eq(-round((float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM cash_movements WHERE type LIKE 'ecole\\_salaire%'"), 2), $solde('6611'), 'salaires payés en caisse (annulations déduites) en charges de personnel');
    $bal = $R::balance($ex, "$y-01-01", "$y-12-31", '', '', true);
    eq(round($bal['total']['md'], 2), round($bal['total']['mc'], 2), 'balance : total débit = total crédit');
    eq(round($bal['total']['fd'], 2), round($bal['total']['fc'], 2), 'balance : soldes débiteurs = soldes créditeurs');

    // ------------------------------------------------------------- saisie manuelle
    $od = (int) DB::value("SELECT id FROM cpt_journaux WHERE code = 'OD'");
    $ac = (int) DB::value("SELECT id FROM cpt_journaux WHERE code = 'AC'");
    $fourn = $C::saveTiers(['type' => 'fournisseur', 'nom' => 'NIGELEC', 'compte' => '4011 — Fournisseurs', 'telephone' => '20 72 26 92']);
    $fcode = DB::value('SELECT code FROM cpt_tiers WHERE id = ?', [$fourn]);
    ok(str_starts_with($fcode, 'F'), 'code tiers automatique');
    $entry = fn(array $lines, int $j = 0, string $date = '') => ['journal_id' => $j ?: $ac, 'date_ecriture' => $date ?: "$y-03-15", 'piece' => 'F-125', 'libelle' => 'Facture électricité mars', 'lignes' => $lines];
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '50 000'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '45 000']])), 'déséquilibrée');
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '50000'], ['compte' => '4011', 'credit' => '50000']])), 'collectif');
    throws(fn() => $C::save($entry([['compte' => '605', 'debit' => '50000'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '50000']])), 'sous-comptes');
    throws(fn() => $C::save($entry([['compte' => '6999', 'debit' => '50000'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '50000']])), 'n\'existe pas');
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '50000', 'credit' => '1'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '50000']])), 'pas les deux');
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '50000']])), 'deux lignes');
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '1'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '1']], 0, ($y + 3) . '-01-01')), 'Aucun exercice');
    $e1 = $C::save($entry([['compte' => '6052 — Fournitures non stockables – électricité', 'debit' => '42 372,88', 'libelle' => 'Électricité HT'], ['compte' => '4454', 'debit' => '7 627,12'],
        ['compte' => '4011 — Fournisseurs', 'tiers' => $fcode . ' — NIGELEC', 'credit' => '50000', 'echeance' => "$y-04-15"]]));
    eq(['brouillard', null], array_values(DB::one('SELECT statut, numero FROM cpt_ecritures WHERE id = ?', [$e1])), 'brouillard sans numéro');
    eq(3, (int) DB::value('SELECT COUNT(*) FROM cpt_lignes WHERE ecriture_id = ?', [$e1]), 'trois lignes');
    eq('Facture électricité mars', DB::value('SELECT libelle FROM cpt_lignes WHERE ecriture_id = ? AND ordre = 2', [$e1]), 'libellé de ligne repris de l\'écriture');
    $C::save($entry([['compte' => '6052', 'debit' => '42372.88'], ['compte' => '4454', 'debit' => '7627.12'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '50000']]), $e1);
    $C::validate($e1);
    $no1 = (int) DB::value('SELECT numero FROM cpt_ecritures WHERE id = ?', [$e1]);
    $pay = $C::save(['journal_id' => (int) DB::value("SELECT id FROM cpt_journaux WHERE code = 'BQ'"), 'date_ecriture' => "$y-04-10", 'libelle' => 'Règlement NIGELEC',
        'lignes' => [['compte' => '4011', 'tiers' => $fcode, 'debit' => '30000'], ['compte' => '521', 'credit' => '30000']]], null, true);
    $pay2 = $C::save(['journal_id' => (int) DB::value("SELECT id FROM cpt_journaux WHERE code = 'BQ'"), 'date_ecriture' => "$y-04-20", 'libelle' => 'Solde NIGELEC',
        'lignes' => [['compte' => '4011', 'tiers' => $fcode, 'debit' => '20000'], ['compte' => '521', 'credit' => '20000']]], null, true);
    $e2 = $C::save($entry([['compte' => '6052', 'debit' => '1000'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '1000']], 0, "$y-03-16"), null, true);
    eq($no1 + 1, (int) DB::value('SELECT numero FROM cpt_ecritures WHERE id = ?', [$e2]), 'numérotation chronologique dans le journal');
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '1'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '1']]), $e1), 'validée ne se modifie plus');
    throws(fn() => $C::deleteDraft($e1), 'ne se supprime pas');
    // Extourne
    throws(fn() => $C::extourne($e2, "$y-03-20", ''), 'Motif');
    $x2 = $C::extourne($e2, "$y-03-20", 'Doublon de saisie');
    $xl = DB::one('SELECT SUM(debit) AS d, SUM(credit) AS c FROM cpt_lignes l JOIN cpt_comptes c ON c.id = l.compte_id WHERE ecriture_id = ? AND c.numero = \'6052\'', [$x2]);
    eq([0.0, 1000.0], [(float) $xl['d'], (float) $xl['c']], 'extourne : débit et crédit inversés');
    eq($x2, (int) DB::value('SELECT extournee_par FROM cpt_ecritures WHERE id = ?', [$e2]), 'écriture d\'origine liée à son extourne');
    throws(fn() => $C::extourne($e2, "$y-03-21", 'Encore'), 'déjà été extournée');
    throws(fn() => $C::extourne($x2, "$y-03-21", 'Encore'), 'ne s\'extourne pas');
    // Brouillard supprimable
    $d = $C::save($entry([['compte' => '6052', 'debit' => '5'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '5']], $od));
    $C::deleteDraft($d);
    eq(null, DB::one('SELECT id FROM cpt_ecritures WHERE id = ?', [$d]), 'brouillard supprimé');

    // ------------------------------------------------------------- lettrage
    $lid = fn(int $e) => (int) DB::value("SELECT l.id FROM cpt_lignes l JOIN cpt_comptes c ON c.id = l.compte_id WHERE l.ecriture_id = ? AND c.numero = '4011'", [$e]);
    throws(fn() => $C::lettrer([$lid($e1), $lid($pay)]), 'débit');
    throws(fn() => $C::lettrer([$lid($e1), $lid($pay)]), '50 000');
    $code = $C::lettrer([$lid($e1), $lid($pay), $lid($pay2)]);
    eq('A', $code, 'lettrage A');
    eq(3, (int) DB::value('SELECT COUNT(*) FROM cpt_lignes WHERE lettrage = ?', ['A']), 'trois lignes lettrées');
    $code2 = $C::lettrer([$lid($e2), $lid($x2)]);
    eq('B', $code2, 'code suivant B');
    eq(2, $C::delettrer((int) DB::value("SELECT id FROM cpt_comptes WHERE numero = '4011'"), 'B'), 'délettrage');
    throws(fn() => $C::lettrer([$lid($e1), $lid($e2)]), 'déjà lettrée');

    // ------------------------------------------------------------- verrouillage de période
    $dr = $C::save($entry([['compte' => '6052', 'debit' => '9'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '9']], $od, "$y-02-10"));
    throws(fn() => $C::setVerrou($ex, "$y-03-31"), 'brouillard');
    $C::deleteDraft($dr);
    $C::setVerrou($ex, "$y-03-31");
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '1'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '1']], $od, "$y-03-31")), 'verrouillée');
    throws(fn() => $C::extourne($e1, "$y-03-30", 'Test'), 'verrouillée');
    $C::setVerrou($ex, null);

    // ------------------------------------------------------------- états de synthèse
    $bl = $R::bilan($ex, "$y-12-31", true);
    eq(0.0, $bl['ecart'], 'bilan SYSCOHADA : total actif = total passif');
    eq([], $bl['non_classes'], 'tous les comptes de bilan sont classés');
    $res = $R::resultat($ex, "$y-12-31", true);
    eq(round($bl['resultat'], 2), round($res['valeurs']['XI'], 2), 'résultat net du compte de résultat = résultat du bilan');
    eq(round(-$solde('7011'), 2), round($res['valeurs']['TA'], 2), 'TA ventes de marchandises');
    eq(round($res['valeurs']['TA'] + $res['valeurs']['RA'] + $res['valeurs']['RB'], 2), round($res['valeurs']['XA'], 2), 'XA marge commerciale = TA + RA + RB');
    $gl = $R::grandLivre($ex, "$y-01-01", "$y-12-31", '4011', '4011', true);
    $acc = array_values($gl['comptes'])[0];
    eq($solde('4011'), round($acc['solde'], 2), 'grand livre : solde final = solde du compte');
    $bt = $R::balanceTiers($ex, 'fournisseur', "$y-12-31", true);
    ok(in_array('NIGELEC', array_column($bt, 'nom'), true), 'balance des fournisseurs');

    // ------------------------------------------------------------- import de plan CSV
    $imp = $C::importCsv("Numéro;Libellé\n5211;Banque BIA Niger\n5212;Ecobank\n4011;Ne doit pas remplacer\nabc;Mauvais\n", false);
    eq([2, 0, 1], [$imp['added'], $imp['updated'], count($imp['errors'])], 'import CSV : 2 ajoutés, existants conservés, ligne invalide signalée');
    eq('Fournisseurs', DB::value("SELECT libelle FROM cpt_comptes WHERE numero = '4011'"));
    throws(fn() => $C::save($entry([['compte' => '521', 'debit' => '1'], ['compte' => '5711', 'credit' => '1']], $od)), 'sous-comptes');

    // ------------------------------------------------------------- écrans
    $tok = $a->token("$B/compta/saisie");
    $resp = $a->req('POST', "$B/compta/ecritures", http_build_query(['_csrf' => $tok, 'journal_id' => $od, 'date_ecriture' => "$y-05-02", 'libelle' => 'Apport en caisse', 'action' => 'valider',
        'lignes' => [['compte' => '5711 — Caisse en monnaie nationale', 'debit' => '100 000'], ['compte' => '1042', 'credit' => '100 000'], ['compte' => '', 'debit' => '']]]));
    ok(str_contains($resp['headers'], 'derniere='), 'saisie par le formulaire, validée');
    ok((bool) DB::value("SELECT id FROM cpt_ecritures WHERE libelle = 'Apport en caisse' AND statut = 'valide'"), 'écriture du formulaire enregistrée');
    $bad = $a->req('POST', "$B/compta/ecritures", http_build_query(['_csrf' => $tok, 'journal_id' => $od, 'date_ecriture' => "$y-05-02", 'libelle' => 'Faux',
        'lignes' => [['compte' => '5711', 'debit' => '10'], ['compte' => '1042', 'credit' => '9']]]));
    ok(str_contains($a->req('GET', "$B/compta/saisie")['body'], 'déséquilibrée'), 'écriture déséquilibrée refusée avec message');
    $dp = $C::save($entry([['compte' => '6052', 'debit' => '7'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '7']], $od, "$y-06-02"));
    ok(str_contains($a->req('GET', "$B/compta/saisie?id=$e1")['headers'], "/compta/ecritures/$e1"), 'écriture validée : pas d\'écran de modification');
    foreach (['/compta', '/compta/saisie', "/compta/saisie?id=$dp", "/compta/ecritures/$dp", "/compta/saisie?copie=$e1", '/compta/ecritures', '/compta/ecritures?statut=brouillard&origine=auto', "/compta/ecritures/$e1", "/compta/ecritures/$e1?print=1",
              '/compta/lettrage', '/compta/lettrage?compte=4011&mode=non_lettrees', "/compta/lettrage?compte=4011&tiers=$fcode", '/compta/etats', '/compta/etats/journal', '/compta/etats/journal?print=1', '/compta/etats/centralisateur',
              '/compta/etats/grand-livre?du_compte=4&au_compte=5', '/compta/etats/balance', '/compta/etats/balance?niveau=classe&statut=valide', '/compta/etats/balance-tiers', '/compta/etats/bilan', '/compta/etats/bilan?print=1',
              '/compta/etats/resultat', '/compta/generation', '/compta/comptes', '/compta/comptes?classe=6&edit=1', '/compta/comptes?nouveau=1', '/compta/tiers', "/compta/tiers?edit=$fourn", '/compta/journaux', '/compta/exercices', '/compta/parametres'] as $u) {
        $resp = $a->req('GET', $B . $u);
        eq(200, $resp['code'], "page $u");
        ok(!str_contains($resp['body'], 'Fatal') && !str_contains($resp['body'], 'Warning:'), "page $u sans erreur PHP");
    }
    foreach (['/compta/ecritures', '/compta/etats/journal', '/compta/etats/grand-livre', '/compta/etats/balance', '/compta/etats/balance-tiers', '/compta/etats/bilan', '/compta/etats/resultat', '/compta/comptes', '/compta/etats/centralisateur'] as $u) {
        $csv = $a->req('GET', "$B$u?export=csv");
        ok($csv['code'] === 200 && str_contains($csv['headers'], 'text/csv'), "export CSV $u");
    }
    ok(str_contains($a->req('GET', "$B/compta/saisie?id=$dp")['body'], 'value="7"'), 'brouillard rechargé dans la grille de saisie');
    $C::deleteDraft($dp);
    $pdf = $a->req('GET', "$B/compta/etats/balance?export=pdf");
    ok($pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'balance en PDF');
    ok(str_contains($a->req('GET', "$B/compta/etats/bilan")['body'], 'TOTAL GÉNÉRAL'), 'bilan affiché');
    $c = new Http();
    $c->login($B, 'caissier', 'Mot-de-passe-2026');
    eq(403, $c->req('GET', "$B/compta")['code'], 'caissier : pas d\'accès à la comptabilité');
    $au = new Http();
    $au->login($B, 'auditeur', 'Mot-de-passe-2026');
    eq(200, $au->req('GET', "$B/compta/etats/balance")['code'], 'auditeur : consultation');
    eq(403, $au->req('GET', "$B/compta/saisie")['code'], 'auditeur : pas de saisie');
    eq(403, $au->req('POST', "$B/compta/ecritures/$e1/extourner", http_build_query(['_csrf' => $au->token("$B/compta"), 'date' => "$y-06-01", 'motif' => 'test']))['code'], 'auditeur : pas d\'extourne');

    // ------------------------------------------------------------- clôture et à-nouveaux
    $br = $C::save($entry([['compte' => '6052', 'debit' => '3'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '3']], $od, "$y-06-01"));
    ok((bool) array_filter($C::clotureChecks($C::exercice($ex)), fn($i) => str_contains($i, 'brouillard')), 'clôture refusée tant qu\'il reste du brouillard');
    throws(fn() => $C::cloturer($ex), 'brouillard');
    $C::validate($br);
    $before = $R::balance($ex, "$y-01-01", "$y-12-31", '', '', true);
    $resultat = $R::resultat($ex, "$y-12-31", true)['valeurs']['XI'];
    $bk = fn() => glob(App\Services\BackupService::dir() . '/*avant_cloture_compta*.sql.gz') ?: [];
    $backupsBefore = $bk();
    $cl = $C::cloturer($ex);
    // Une nouvelle sauvegarde est apparue (la rotation peut avoir supprimé la plus ancienne)
    ok((bool) array_diff($bk(), $backupsBefore), 'sauvegarde complète avant la clôture');
    eq(round($resultat, 2), round($cl['resultat'], 2), 'résultat de clôture = résultat net');
    eq('cloture', DB::value('SELECT statut FROM cpt_exercices WHERE id = ?', [$ex]), 'exercice clôturé');
    $next = (int) $cl['next']['id'];
    eq(($y + 1) . '-01-01', $cl['next']['date_debut'], 'exercice suivant créé');
    $an = DB::one('SELECT * FROM cpt_ecritures WHERE id = ?', [$cl['an']]);
    eq(['valide', $next, ($y + 1) . '-01-01'], [$an['statut'], (int) $an['exercice_id'], $an['date_ecriture']], 'à-nouveaux validés au premier jour de l\'exercice suivant');
    $nb = $R::balance($next, ($y + 1) . '-01-01', ($y + 1) . '-12-31', '', '', true);
    $closing = [];
    foreach ($before['lignes'] as $l) {
        if ($l['classe'] <= 5 && abs($l['fd'] - $l['fc']) >= 0.01) { $closing[$l['numero']] = round($l['fd'] - $l['fc'], 2); }
    }
    $opening = [];
    foreach ($nb['lignes'] as $l) {
        if ($l['classe'] <= 5 && !in_array($l['numero'], ['131', '139'], true)) { $opening[$l['numero']] = round($l['od'] - $l['oc'], 2); }
    }
    ksort($closing); ksort($opening);
    eq($closing, $opening, 'soldes d\'ouverture N+1 = soldes de clôture N (classes 1 à 5)');
    eq(round($resultat, 2), -$solde($resultat >= 0 ? '131' : '139'), 'résultat reporté au compte 131 / 139');
    $unl = (int) DB::value("SELECT COUNT(*) FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_comptes c ON c.id = l.compte_id WHERE e.exercice_id = ? AND c.numero = '4011' AND l.tiers_id = ? AND l.lettrage IS NULL", [$ex, $fourn]);
    eq($unl, (int) DB::value("SELECT COUNT(*) FROM cpt_lignes l JOIN cpt_comptes c ON c.id = l.compte_id WHERE l.ecriture_id = ? AND c.numero = '4011' AND l.tiers_id = ?", [$cl['an'], $fourn]), 'à-nouveaux détaillés : une ligne par ligne non lettrée du fournisseur');
    throws(fn() => $C::save($entry([['compte' => '6052', 'debit' => '1'], ['compte' => '4011', 'tiers' => $fcode, 'credit' => '1']], $od, "$y-12-01")), 'clôturé');
    throws(fn() => $C::cloturer($ex), 'déjà clôturé');
    eq(200, $a->req('GET', "$B/compta/etats/bilan?exercice=$next")['code'], 'bilan N+1 avec colonne N-1');
    eq(0.0, $R::bilan($next, ($y + 1) . '-12-31', true)['ecart'], 'bilan d\'ouverture N+1 équilibré');
});

test('Module Hôtel : réservation, acompte en caisse, arrivée, folio, audit de nuit, départ, annulations, ménage', function () {
    $HS = Addons\Hotel\HotelService::class;
    actingAs('admin');
    eq(['0001_hotel'], App\Core\Addons::install('hotel', false), 'installation');
    eq([], App\Core\Addons::install('hotel', false), 'réinstallation idempotente');
    eq(12, (int) DB::value('SELECT COUNT(*) FROM hotel_rooms'), 'chambres de départ');
    $room = fn(string $n) => (int) DB::value('SELECT id FROM hotel_rooms WHERE number = ?', [$n]);
    $today = date('Y-m-d');
    $d = fn(int $n) => date('Y-m-d', strtotime("$n day"));
    // Tarif week-end : nuits du vendredi et du samedi
    DB::update('hotel_room_types', ['weekend_price' => 30000], 'id = 2');
    $fri = date('Y-m-d', strtotime('next friday'));
    $q = $HS::quote(2, $fri, date('Y-m-d', strtotime($fri . ' +3 day')));
    eq([3, 85000.0], [$q['nights'], $q['total']], 'devis : vendredi et samedi au tarif week-end, dimanche au tarif normal');
    DB::update('hotel_room_types', ['weekend_price' => null], 'id = 2');

    actingAs('caissier');
    ok(Auth::can('hotel.reception') && !Auth::can('hotel.manage'), 'caissier : réception sans gestion');
    $session = (int) S\CashService::currentSession(Auth::user())['id'];
    $cash0 = S\CashService::expectedCash($session);
    throws(fn() => $HS::createStay(['guest' => ['last_name' => 'Issa', 'phone' => '+227 96 11 22 33'], 'room_id' => $room('103'), 'arrival' => $d(1), 'departure' => $d(4), 'rate' => '20000']), 'réservée au responsable');
    throws(fn() => $HS::createStay(['guest' => ['last_name' => 'Issa'], 'type_id' => 2, 'arrival' => $d(3), 'departure' => $d(3)]), 'postérieure');
    throws(fn() => $HS::createStay(['guest' => ['last_name' => 'Issa'], 'type_id' => 2, 'arrival' => $d(-2), 'departure' => $d(1)]), 'passée');
    throws(fn() => $HS::createStay(['guest' => ['last_name' => ''], 'type_id' => 2, 'arrival' => $d(1), 'departure' => $d(2)]), 'nom du client');
    $r = $HS::createStay(['guest' => ['last_name' => 'Issa', 'first_name' => 'Moussa', 'phone' => '+227 96 11 22 33'], 'room_id' => $room('103'), 'arrival' => $d(1), 'departure' => $d(4),
        'adults' => 2, 'source' => 'whatsapp', 'deposit' => ['method' => 'cash', 'amount' => '10 000']]);
    $s = DB::one('SELECT * FROM hotel_stays WHERE id = ?', [$r['id']]);
    eq(['reservee', 25000.0, 10000.0, 2], [$s['status'], (float) $s['rate'], (float) $s['total_paid'], (int) $s['type_id']], 'réservation avec acompte');
    ok(str_starts_with($r['number'], 'RES' . date('Y')), 'numéro de réservation');
    eq($cash0 + 10000, S\CashService::expectedCash($session), 'acompte en espèces dans la caisse');
    eq('hotel', DB::value("SELECT type FROM cash_movements WHERE ref_type = 'hotel_stay' AND ref_id = ? ORDER BY id DESC LIMIT 1", [$r['id']]), 'mouvement de caisse « hotel »');
    throws(fn() => $HS::createStay(['guest' => ['last_name' => 'Garba'], 'room_id' => $room('103'), 'arrival' => $d(2), 'departure' => $d(6)]), 'déjà prise');
    ok(!in_array($room('103'), array_map('intval', array_column($HS::availableRooms($d(2), $d(3)), 'id')), true), 'chambre réservée absente des disponibilités');
    ok(in_array($room('103'), array_map('intval', array_column($HS::availableRooms($d(4), $d(6)), 'id')), true), 'libre à partir du jour de départ');
    $g2 = (int) DB::value('SELECT guest_id FROM hotel_stays WHERE id = ?', [$r['id']]);
    $r2 = $HS::createStay(['guest' => ['last_name' => 'issa', 'phone' => '96 11 22 33'], 'type_id' => 3, 'arrival' => $d(10), 'departure' => $d(12)]);
    eq($g2, (int) DB::value('SELECT guest_id FROM hotel_stays WHERE id = ?', [$r2['id']]), 'client reconnu (même nom et téléphone) : pas de doublon');
    $HS::updateReservation($r2['id'], ['arrival' => $d(11), 'departure' => $d(13), 'room_id' => $room('106')]);
    eq([$d(11), $room('106')], [DB::value('SELECT arrival FROM hotel_stays WHERE id = ?', [$r2['id']]), (int) DB::value('SELECT room_id FROM hotel_stays WHERE id = ?', [$r2['id']])], 'réservation modifiée');
    $HS::confirm($r2['id']);
    eq('confirmee', DB::value('SELECT status FROM hotel_stays WHERE id = ?', [$r2['id']]));

    // Arrivée anticipée : pièce d'identité exigée, chambre propre exigée
    throws(fn() => $HS::checkIn($r['id']), 'Pièce d\'identité');
    $HS::saveGuest(['last_name' => 'Issa', 'first_name' => 'Moussa', 'phone' => '+227 96 11 22 33', 'id_type' => 'cni', 'id_number' => 'NE-123456', 'nationality' => 'Nigérienne'], $g2);
    $HS::setHousekeeping($room('103'), 'sale', 'test');
    throws(fn() => $HS::checkIn($r['id']), 'pas encore propre');
    $HS::setHousekeeping($room('103'), 'propre');
    $HS::checkIn($r['id']);
    $s = DB::one('SELECT * FROM hotel_stays WHERE id = ?', [$r['id']]);
    eq(['en_cours', $today, $today], [$s['status'], $s['arrival'], $s['charged_until']], 'arrivée anticipée : séjour démarré aujourd\'hui');
    throws(fn() => $HS::checkIn($r['id']), 'impossible');

    // Client sans réservation
    throws(fn() => $HS::createStay(['walk_in' => true, 'guest' => ['last_name' => 'Abdou', 'id_type' => 'passeport', 'id_number' => 'P998877'], 'room_id' => $room('201'), 'departure' => $d(2), 'adults' => 3]), 'Capacité');
    $w = $HS::createStay(['walk_in' => true, 'guest' => ['last_name' => 'Abdou', 'first_name' => 'Rabi', 'id_type' => 'passeport', 'id_number' => 'P998877'], 'room_id' => $room('201'), 'departure' => $d(2), 'adults' => 2]);
    eq('en_cours', DB::value('SELECT status FROM hotel_stays WHERE id = ?', [$w['id']]), 'arrivée directe');
    $bf = (int) DB::value("SELECT id FROM hotel_extras WHERE name = 'Petit-déjeuner'");
    $HS::addCharge($w['id'], ['extra_id' => $bf, 'quantity' => 2]);
    throws(fn() => $HS::addCharge($w['id'], ['extra_id' => $bf, 'unit_price' => '100']), 'responsable');
    throws(fn() => $HS::addCharge($w['id'], ['kind' => 'nuitee', 'label' => 'x', 'unit_price' => '1']), 'automatiquement');
    $HS::addCharge($w['id'], ['kind' => 'remise', 'label' => 'Geste commercial', 'unit_price' => '1000']);
    eq(5000.0, (float) DB::value('SELECT total_charges FROM hotel_stays WHERE id = ?', [$w['id']]), 'folio : 2 petits-déjeuners − remise');
    throws(fn() => $HS::addCharge($r2['id'], ['extra_id' => $bf]), 'séjour en cours');

    // Deux nuits écoulées : l'audit de nuit les porte au folio (avec la taxe de séjour), une seule fois
    setting_set('hotel_taxe_sejour', '500');
    DB::update('hotel_stays', ['arrival' => $d(-2), 'charged_until' => $d(-2), 'departure' => $d(1)], 'id = ?', [$w['id']]);
    $a = $HS::nightAudit();
    ok($a['nights'] >= 2, 'audit de nuit');
    eq(2, (int) DB::value("SELECT COUNT(*) FROM hotel_charges WHERE stay_id = ? AND kind = 'nuitee'", [$w['id']]), 'deux nuitées');
    eq(2000.0, (float) DB::value("SELECT SUM(amount) FROM hotel_charges WHERE stay_id = ? AND kind = 'taxe'", [$w['id']]), 'taxe de séjour : 2 nuits × 2 adultes × 500');
    eq(0, $HS::nightAudit()['nights'], 'audit relancé : rien en double');
    $st = DB::one('SELECT * FROM hotel_stays WHERE id = ?', [$w['id']]);
    eq(5000.0 + 2 * 25000 + 2000, (float) $st['total_charges'], 'folio après audit');
    $b = $HS::balance($st);
    eq([1, 26000.0], [$b['pending']['nights'], $b['pending']['amount']], 'nuitée restante estimée (avec taxe)');
    $board = $HS::board();
    $tile = array_values(array_filter($board['rooms'], fn($x) => $x['number'] === '201'))[0];
    eq(['occupee', 3], [$tile['state'], $tile['stay']['night_no']], 'plan : chambre occupée, 3e nuit');
    eq((int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status = 'en_cours'"), $board['totals']['occupied'], 'plan : chambres occupées = séjours en cours');

    // Changement de chambre : tarif conservé, ancienne chambre à nettoyer
    $HS::changeRoom($w['id'], $room('202'), true, 'climatisation');
    eq([$room('202'), 'sale'], [(int) DB::value('SELECT room_id FROM hotel_stays WHERE id = ?', [$w['id']]), DB::value('SELECT housekeeping FROM hotel_rooms WHERE id = ?', [$room('201')])], 'changement de chambre');
    throws(fn() => $HS::changeRoom($w['id'], $room('103'), true), 'déjà prise');
    $HS::changeDeparture($w['id'], $d(3));
    eq($d(3), DB::value('SELECT departure FROM hotel_stays WHERE id = ?', [$w['id']]), 'prolongation');

    // Départ : solde exigé, facture numérotée, chambre à nettoyer
    $due = (float) DB::value('SELECT total_charges - total_paid FROM hotel_stays WHERE id = ?', [$w['id']]);
    throws(fn() => $HS::checkOut($w['id']), 'Reste à payer');
    throws(fn() => $HS::checkOut($w['id'], ['method' => 'mobile_money', 'amount' => (string) ($due + 5000)]), 'électronique');
    $cashBefore = S\CashService::expectedCash($session);
    $change = $HS::checkOut($w['id'], ['method' => 'cash', 'amount' => (string) ($due + 3000)]);
    eq(3000.0, $change, 'monnaie rendue');
    $st = DB::one('SELECT * FROM hotel_stays WHERE id = ?', [$w['id']]);
    eq(['terminee', $today, 0.0], [$st['status'], $st['departure'], round((float) $st['total_charges'] - (float) $st['total_paid'], 2)], 'départ soldé, date de départ = aujourd\'hui');
    ok(str_starts_with((string) $st['invoice_number'], 'FH' . date('Y')), 'facture numérotée');
    eq('sale', DB::value('SELECT housekeeping FROM hotel_rooms WHERE id = ?', [$room('202')]), 'chambre libérée à nettoyer');
    eq($cashBefore + $due, S\CashService::expectedCash($session), 'solde encaissé en caisse');
    throws(fn() => $HS::pay($w['id'], ['method' => 'cash', 'amount' => '100']), 'entièrement payé');

    // Départ le jour même de l'arrivée : une nuit facturée ; départ avec solde réservé au responsable
    $x = $HS::createStay(['walk_in' => true, 'guest_id' => $g2, 'room_id' => $room('104'), 'departure' => $d(1)]);
    throws(fn() => $HS::checkOut($x['id'], [], false, 'Entreprise paiera'), 'Reste à payer');
    actingAs('admin');
    $HS::checkOut($x['id'], [], true, 'Facture envoyée à l\'employeur');
    eq([1, 12500.0], [(int) DB::value("SELECT COUNT(*) FROM hotel_charges WHERE stay_id = ? AND kind = 'nuitee'", [$x['id']]), (float) DB::value("SELECT SUM(amount) FROM hotel_charges WHERE stay_id = ? AND kind = 'depart_tardif'", [$x['id']])], 'une nuit au moins + départ tardif 50 %');
    ok($HS::stats($today)['receivable'] >= 25000 + 500 + 12500, 'solde à recouvrer');
    actingAs('caissier');
    $HS::settle($x['id'], ['method' => 'cash', 'amount' => '38000']);
    eq(0.0, round((float) DB::value('SELECT total_charges - total_paid FROM hotel_stays WHERE id = ?', [$x['id']]), 2), 'solde réglé après le départ');

    // Annulations : acompte remboursé ou conservé ; non-présentation
    $c1 = $HS::createStay(['guest_id' => $g2, 'type_id' => 1, 'arrival' => $d(5), 'departure' => $d(6), 'deposit' => ['method' => 'cash', 'amount' => '5000']]);
    $HS::cancel($c1['id'], 'Changement de programme', false);
    eq(['annulee', 0.0], [DB::value('SELECT status FROM hotel_stays WHERE id = ?', [$c1['id']]), (float) DB::value('SELECT total_paid FROM hotel_stays WHERE id = ?', [$c1['id']])], 'acompte remboursé');
    eq(-5000.0, (float) DB::value("SELECT amount FROM cash_movements WHERE type = 'hotel_refund' AND ref_id = ? ORDER BY id DESC LIMIT 1", [$c1['id']]), 'remboursement sorti de la caisse');
    $c2 = $HS::createStay(['guest_id' => $g2, 'type_id' => 1, 'arrival' => $d(5), 'departure' => $d(6), 'deposit' => ['method' => 'cash', 'amount' => '5000']]);
    throws(fn() => $HS::cancel($c2['id'], 'x', true, true), 'Non présenté');
    $HS::cancel($c2['id'], 'Hors délai', true);
    eq([5000.0, 5000.0], [(float) DB::value('SELECT total_charges FROM hotel_stays WHERE id = ?', [$c2['id']]), (float) DB::value('SELECT total_paid FROM hotel_stays WHERE id = ?', [$c2['id']])], 'acompte conservé en frais d\'annulation');
    throws(fn() => $HS::cancel($r['id'], 'x', false), 'avant l\'arrivée');
    throws(fn() => $HS::cancel($r2['id'], '', false), 'motif');

    // Maintenance : chambre occupée refusée ; chambre hors service exclue des disponibilités
    throws(fn() => $HS::setOutOfOrder($room('103'), true, 'Peinture'), 'occupée');
    $HS::setOutOfOrder($room('105'), true, 'Climatisation en panne', $d(3));
    ok(!in_array($room('105'), array_map('intval', array_column($HS::availableRooms($today, $d(1)), 'id')), true), 'hors service : non proposée');
    throws(fn() => $HS::createStay(['guest_id' => $g2, 'room_id' => $room('105'), 'arrival' => $d(1), 'departure' => $d(2)]), 'hors service');
    eq('hors_service', array_values(array_filter($HS::board()['rooms'], fn($x) => $x['number'] === '105'))[0]['state'], 'plan : hors service');
    $HS::setOutOfOrder($room('105'), false);
    eq(0, (int) DB::value('SELECT out_of_order FROM hotel_rooms WHERE id = ?', [$room('105')]), 'remise en service');
    $lg = DB::one("SELECT from_state, to_state FROM hotel_room_log WHERE room_id = ? AND event = 'menage' ORDER BY id DESC LIMIT 1", [$room('103')]);
    eq(['sale', 'propre'], [$lg['from_state'], $lg['to_state']], 'historique du ménage');

    // Rapport et registre des voyageurs
    $rep = $HS::report($d(-3), $today);
    ok($rep['totals']['occupied'] >= 2 && $rep['totals']['room'] >= 50000 && $rep['totals']['adr'] > 0, 'rapport : nuitées, CA, prix moyen');
    $reg = $HS::policeRegister($today, $today);
    ok(in_array('NE-123456', array_column($reg, 'id_number'), true), 'registre des voyageurs : pièce d\'identité');
    actingAs('auditeur');
    ok(Auth::can('hotel.view') && !Auth::can('hotel.reception'), 'auditeur : lecture seule');
    actingAs('admin');
});

test('Module Hôtel (HTTP) : écrans, plan des chambres, formulaires, droits, mode Hôtel', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $sid = (int) DB::value("SELECT id FROM hotel_stays WHERE status = 'en_cours' ORDER BY id LIMIT 1");
    $done = (int) DB::value("SELECT id FROM hotel_stays WHERE status = 'terminee' ORDER BY id LIMIT 1");
    $gid = (int) DB::value('SELECT guest_id FROM hotel_stays WHERE id = ?', [$sid]);
    $rid = (int) DB::value("SELECT id FROM hotel_rooms WHERE number = '101'");
    foreach (['/hotel', '/hotel/plan', '/hotel/planning', '/hotel/planning?n=31', '/hotel/reservations', '/hotel/reservations?f=tous&q=Issa', '/hotel/reservations?f=soldes', '/hotel/reservations/nouvelle', '/hotel/reservations/nouvelle?walkin=1&room=' . $rid,
        "/hotel/sejours/$sid", "/hotel/sejours/$done", "/hotel/sejours/$sid/facture", "/hotel/sejours/$done/facture", "/hotel/sejours/$sid/fiche", '/hotel/menage', '/hotel/clients', "/hotel/clients/$gid", "/hotel/chambres/$rid",
        '/hotel/rapport', '/hotel/registre', '/hotel/configuration', '/hotel/configuration?tab=chambres', '/hotel/configuration?tab=prestations', '/hotel/configuration?tab=reglages'] as $u) {
        $r = $a->req('GET', $B . $u);
        eq(200, $r['code'], "GET $u");
        ok(!str_contains($r['body'], 'Fatal') && !str_contains($r['body'], 'Warning:') && !str_contains($r['body'], 'Notice:'), "aucune erreur PHP sur $u");
    }
    ok(str_contains($a->req('GET', "$B/hotel")['body'], 'data-cat="Hôtel"'), 'menu : catégorie « Hôtel »');
    $feed = json_decode($a->req('GET', "$B/hotel/plan/feed", null, ['Accept: application/json'])['body'], true);
    ok($feed['ok'] && count($feed['rooms']) === 12 && isset($feed['totals']['occupancy']), 'plan : flux JSON');
    $av = json_decode($a->req('GET', "$B/hotel/disponibilites?arrival=" . date('Y-m-d', strtotime('+20 day')) . '&departure=' . date('Y-m-d', strtotime('+22 day')), null, ['Accept: application/json'])['body'], true);
    ok($av['ok'] && $av['nights'] === 2 && count($av['types']) === 4 && count($av['rooms']) >= 10, 'disponibilités et devis');
    $gs = json_decode($a->req('GET', "$B/hotel/clients/recherche?q=issa", null, ['Accept: application/json'])['body'], true);
    ok($gs['ok'] && $gs['guests'][0]['last_name'] === 'ISSA', 'recherche du client');
    ok(str_contains($a->req('GET', "$B/hotel/rapport?export=csv")['body'], 'Chambres occupées'), 'export CSV du rapport');
    // Réservation par le formulaire
    $t = $a->token("$B/hotel/reservations/nouvelle");
    $arr = date('Y-m-d', strtotime('+30 day'));
    $r = $a->req('POST', "$B/hotel/reservations", http_build_query(['_csrf' => $t, 'walk_in' => '0', 'guest_id' => '0', 'g_form' => '1', 'g_last_name' => 'Souley', 'g_first_name' => 'Aïcha', 'g_phone' => '+227 90 00 11 22',
        'type_id' => 4, 'room_id' => (int) DB::value("SELECT id FROM hotel_rooms WHERE number = '205'"), 'arrival' => $arr, 'departure' => date('Y-m-d', strtotime('+32 day')), 'adults' => 2, 'children' => 1,
        'source' => 'telephone', 'rate' => '', 'pay_method' => 'cash', 'pay_amount' => '', 'pay_reference' => '']));
    eq(302, $r['code'], 'réservation enregistrée');
    $new = DB::one("SELECT s.*, g.last_name FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id WHERE g.last_name = 'SOULEY'");
    ok($new && $new['arrival'] === $arr && (float) $new['rate'] === 60000.0 && (int) $new['children'] === 1, 'réservation créée depuis le formulaire');
    ok(str_contains($r['headers'], '/hotel/sejours/' . $new['id']), 'redirection vers le séjour');
    // Ménage depuis le plan (JSON) ; mise hors service réservée au responsable
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $a->token("$B/hotel/menage")];
    $j = json_decode($a->req('POST', "$B/hotel/chambres/$rid/menage", json_encode(['state' => 'nettoyage']), $hdr)['body'], true);
    ok($j['ok'] && DB::value('SELECT housekeeping FROM hotel_rooms WHERE id = ?', [$rid]) === 'nettoyage', 'ménage depuis le plan');
    $j = json_decode($a->req('POST', "$B/hotel/chambres/$rid/menage", json_encode(['state' => 'xyz']), $hdr)['body'], true);
    ok(!$j['ok'], 'état de ménage inconnu refusé');
    $c = new Http();
    $c->login($B, 'auditeur', 'Mot-de-passe-2026');
    eq(200, $c->req('GET', "$B/hotel/plan")['code'], 'auditeur : plan visible');
    eq(403, $c->req('POST', "$B/hotel/chambres/$rid/menage", json_encode(['state' => 'propre']), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $c->token("$B/hotel")])['code'], 'auditeur : pas de ménage');
    eq(403, $c->req('GET', "$B/hotel/registre")['code'], 'auditeur : registre des voyageurs refusé');
    // Configuration : nouveau type, nouvelle chambre, réglages
    $t = $a->token("$B/hotel/configuration");
    $a->req('POST', "$B/hotel/configuration/types", http_build_query(['_csrf' => $t, 'id' => 0, 'name' => 'Familiale', 'code' => 'FAM', 'capacity' => 5, 'base_price' => '45 000', 'weekend_price' => '', 'color' => '#ff8800', 'active' => '1']));
    $fam = (int) DB::value("SELECT id FROM hotel_room_types WHERE name = 'Familiale'");
    ok($fam > 0 && (float) DB::value('SELECT base_price FROM hotel_room_types WHERE id = ?', [$fam]) === 45000.0, 'type de chambre ajouté');
    $a->req('POST', "$B/hotel/configuration/chambres", http_build_query(['_csrf' => $t, 'id' => 0, 'number' => '301', 'floor' => '3e étage', 'type_id' => $fam, 'beds' => '2 lits doubles', 'active' => '1']));
    eq(13, (int) DB::value('SELECT COUNT(*) FROM hotel_rooms'), 'chambre ajoutée');
    $a->req('POST', "$B/hotel/configuration/parametres", http_build_query(['_csrf' => $t, 'hotel_heure_arrivee' => '13:00', 'hotel_heure_depart' => '11:00', 'hotel_taxe_sejour' => '0', 'hotel_depart_tardif_pourcent' => '30', 'hotel_piece_obligatoire' => '1', 'hotel_exiger_propre' => '1', 'hotel_conditions' => 'Merci']));
    eq(['11:00', '30'], [DB::value("SELECT svalue FROM settings WHERE skey = 'hotel_heure_depart'"), DB::value("SELECT svalue FROM settings WHERE skey = 'hotel_depart_tardif_pourcent'")], 'réglages enregistrés');
    // Mode Hôtel : menus des autres activités masqués, données conservées
    $before = S\BusinessMode::current();
    S\BusinessMode::set('hotel');
    ok(S\BusinessMode::hides('/pressing') && S\BusinessMode::hides('/clinique') && !S\BusinessMode::hides('/hotel/plan') && !S\BusinessMode::hides('/pos'), 'mode Hôtel : menus adaptés, caisse conservée (bar, restaurant)');
    S\BusinessMode::set('pressing');
    ok(S\BusinessMode::hides('/hotel/plan'), 'mode Pressing : menus de l\'hôtel masqués');
    S\BusinessMode::set($before);
    ok(!S\BusinessMode::hides('/hotel') || !in_array($before, ['commerce', 'restaurant'], true), 'commerce et restaurant : menus de l\'hôtel visibles (hôtel avec bar ou restaurant)');
});

test('Installation : messages MySQL clairs, base d\'une autre application refusée, reprise sans perte', function () use ($env) {
    $err = function (int $code, string $msg) { $e = new PDOException($msg); $e->errorInfo = ['HY000', $code, $msg]; return $e; };
    ok(str_contains(S\InstallService::explainDbError(new PDOException("SQLSTATE[HY000] [1049] Unknown database 'posmarwa'"), 'posmarwa'), 'moncompte_posmarwa'), '1049 : nom complet avec le préfixe cPanel');
    ok(str_contains(S\InstallService::explainDbError(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'x'@'localhost' (using password: YES)"), 'b'), 'mot de passe de la base refusé'), '1045 : utilisateur ou mot de passe');
    ok(str_contains(S\InstallService::explainDbError($err(1142, 'SQLSTATE[42000]: Syntax error or access violation: 1142 CREATE command denied'), 'b'), 'TOUS LES PRIVILÈGES'), '1142 : droits à accorder dans cPanel');
    ok(str_contains(S\InstallService::explainDbError(new PDOException('SQLSTATE[HY000] [2002] Connection refused'), 'b'), 'localhost'), '2002 : hôte');
    // Migration rejouée (installation interrompue) : colonnes et index déjà présents ignorés
    S\InstallService::runSqlFile(BASE_PATH . '/database/migrations/0002_restaurant.sql');
    ok(true, 'migration rejouée sans erreur (colonnes déjà créées)');
    S\InstallService::checkDatabase();
    ok(!DB::value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pm_install_test'"), 'vérification des droits sans table laissée');
    // Base d'une autre application : refusée, rien de modifié
    $main = DB::connection();
    $main->exec('DROP DATABASE IF EXISTS poswan_test_autre');
    $main->exec('CREATE DATABASE poswan_test_autre');
    $main->exec('CREATE TABLE poswan_test_autre.wp_posts (id INT)');
    DB::setConnection(new PDO("mysql:host={$env['DB_HOST']};dbname=poswan_test_autre;charset=utf8mb4", $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
    throws(fn() => S\InstallService::checkDatabase(), 'autre application');
    eq(['wp_posts'], array_column(DB::all('SHOW TABLES'), 'Tables_in_poswan_test_autre'), 'tables de l\'autre application intactes');
    DB::setConnection($main);
    $main->exec('DROP DATABASE poswan_test_autre');
    // Fichiers réinstallés sur une base POS Marwa existante : données conservées, sauvegarde faite
    $sales = (int) DB::value('SELECT COUNT(*) FROM sales');
    rename(S\InstallService::lockFile(), S\InstallService::lockFile() . '.reprise');
    try {
        $r = S\InstallService::install(['company' => 'Autre', 'business_type' => 'boutique', 'admin_username' => 'nouveau', 'admin_name' => '', 'admin_password' => 'Nouveau-2026-xx', 'store_name' => '', 'demo' => false]);
    } finally {
        @unlink(S\InstallService::lockFile());
        rename(S\InstallService::lockFile() . '.reprise', S\InstallService::lockFile());
    }
    eq('existing', $r);
    eq($sales, (int) DB::value('SELECT COUNT(*) FROM sales'), 'ventes conservées');
    eq(null, DB::value("SELECT id FROM users WHERE username = 'nouveau'"), 'aucun compte ajouté');
    ok((bool) glob(STORAGE_PATH . '/backups/*avant_reprise_installation*.sql.gz'), 'sauvegarde avant reprise');
});

test('Pressing (HTTP) : « Livré » depuis l\'écran, ticket de livraison, réglages réservés', function () use ($B) {
    $r = ['id' => $GLOBALS['PR_LIVRAISON']];
    $P = Addons\Pressing\PressingService::class;
    // Depuis l'écran : refusé tant que ce n'est pas payé, puis livré
    $h = new Http(); $h->login($B, 'caissier', 'Mot-de-passe-2026');
    preg_match('/name="csrf-token" content="([^"]+)"/', $h->req('GET', "$B/pressing/ecran")['body'], $m);
    $hdr = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . ($m[1] ?? '')];
    $d = json_decode($h->req('POST', "$B/pressing/ecran/{$r['id']}/livrer", json_encode(['_csrf' => $m[1] ?? '']), $hdr)['body'], true);
    ok(!$d['ok'] && str_contains($d['error'], 'Reste à payer'), 'livraison refusée tant que le solde n\'est pas encaissé');
    $P::pay($r['id'], ['method' => 'cash', 'amount' => '3000']);
    $d = json_decode($h->req('POST', "$B/pressing/ecran/{$r['id']}/livrer", json_encode(['_csrf' => $m[1] ?? '']), $hdr)['body'], true);
    ok($d['ok'] && DB::value('SELECT status FROM pressing_orders WHERE id = ?', [$r['id']]) === 'delivered', 'livré depuis l\'écran');
    $t = $h->req('GET', "$B/pressing/{$r['id']}/ticket")['body'];
    ok(str_contains($t, 'porte bleue') && str_contains($t, 'LIVRAISON'), 'ticket : adresse de livraison');
    eq(403, $h->req('POST', "$B/pressing/reglages", http_build_query(['_csrf' => $m[1] ?? '', 'express_pourcent' => '10']))['code'], 'réglages réservés au responsable');
});
test('Fiscal Connect (phase 3) : migration sûre, pays sans règle inventée, réinstallation sans effet', function () {
    actingAs('admin');
    $sales = (int) DB::value('SELECT COUNT(*) FROM sales');
    eq(['0001_fiscal'], App\Core\Addons::install('fiscal', false), 'installation');
    App\Core\Addons::reset();
    foreach (['fiscal_connectors', 'fiscal_countries', 'fiscal_profiles', 'fiscal_devices', 'fiscal_api_credentials', 'fiscal_certifications', 'fiscal_invoice_logs', 'fiscal_pending_queue', 'fiscal_certificates', 'fiscal_tax_rates', 'fiscal_webhooks', 'fiscal_audit_logs'] as $t) {
        ok((int) DB::value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) === 1, "table $t");
    }
    eq(11, (int) DB::value('SELECT COUNT(*) FROM fiscal_countries'), '11 pays listés');
    eq(0, (int) DB::value('SELECT COUNT(*) FROM fiscal_countries WHERE configured = 1 OR homologated = 1 OR certification_mode <> "unknown"'), 'aucune règle fiscale préremplie');
    eq(0, (int) DB::value('SELECT COUNT(*) FROM fiscal_tax_rates'), 'aucun taux inventé');
    eq([], App\Core\Addons::install('fiscal', false), 'réinstallation : rien à rejouer');
    eq($sales, (int) DB::value('SELECT COUNT(*) FROM sales'), 'ventes intactes');
    ok(is_file(BASE_PATH . '/addons/fiscal/sql/down.sql') && !glob(BASE_PATH . '/addons/fiscal/migrations/*down*'), 'retour arrière fourni hors des migrations automatiques');
});

test('Fiscal Connect (phase 4) : moteur et simulation — certification, refus, panne, réponse perdue, doublon, secrets masqués', function () {
    actingAs('admin');
    $E = Addons\Fiscal\FiscalEngine::class;
    $ne = (int) DB::value("SELECT id FROM fiscal_countries WHERE code = 'NE'");
    $store = (int) DB::value('SELECT id FROM stores ORDER BY id LIMIT 1');
    $sales = array_map('intval', array_column(DB::all("SELECT id FROM sales WHERE status = 'completed' AND store_id = ? ORDER BY id LIMIT 8", [$store]), 'id'));
    ok(count($sales) >= 6, 'ventes disponibles');
    eq('not_required', $E::certifySale($sales[0])['status'], 'sans profil actif : rien n\'est envoyé');
    $cid = DB::insert('fiscal_connectors', ['name' => 'Simu', 'kind' => 'simulation', 'driver' => 'simulation', 'environment' => 'test', 'is_default' => 1, 'active' => 1, 'max_retries' => 3, 'config_json' => '{"scenario":"accept"}']);
    $pid = DB::insert('fiscal_profiles', ['name' => 'Test NE', 'country_id' => $ne, 'environment' => 'test', 'seller_nif' => '123456/P', 'active' => 1]);
    Addons\Fiscal\Secrets::put($cid, 'api_key', 'CLE-SECRETE-XYZ-987654');
    ok(!str_contains((string) DB::value('SELECT value_enc FROM fiscal_api_credentials WHERE connector_id = ?', [$cid]), 'CLE-SECRETE'), 'secret chiffré en base');
    eq('CLE-SECRETE-XYZ-987654', Addons\Fiscal\Secrets::get($cid, 'api_key'));
    eq(['api_key' => '…7654'], Addons\Fiscal\Secrets::hints($cid), 'seul un repère est montré');

    // 1. Réponse positive
    $r = $E::certifySale($sales[0]);
    eq('certified', $r['status'], $r['message']);
    $c = $r['certification'];
    ok(str_starts_with($c['fiscal_number'], 'SIMU-') && (int) $c['is_simulation'] === 1 && $c['signature'] && $c['proof_json'], 'preuve enregistrée, marquée simulation');
    // 2. Double envoi : aucun second envoi
    $logs = (int) DB::value('SELECT COUNT(*) FROM fiscal_invoice_logs WHERE certification_id = ?', [$c['id']]);
    $r2 = $E::certifySale($sales[0]);
    eq(['certified', $c['fiscal_number']], [$r2['status'], $r2['certification']['fiscal_number']], 'même numéro');
    eq($logs, (int) DB::value('SELECT COUNT(*) FROM fiscal_invoice_logs WHERE certification_id = ?', [$c['id']]), 'aucun nouvel appel au dispositif');
    // 3. Ligne certifiée non modifiable par le moteur
    eq(0, DB::run("UPDATE fiscal_certifications SET status = 'pending' WHERE id = ? AND status <> 'certified'", [$c['id']])->rowCount(), 'garde « status <> certified »');

    // 4. Réponse négative → rejetée, jamais certifiée
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"reject"}'], 'id = ?', [$cid]);
    $r = $E::certifySale($sales[1]);
    eq('rejected', $r['status']);
    ok($r['certification']['fiscal_number'] === null, 'aucun numéro');
    // 5. Dispositif injoignable → file d'attente, puis épuisement des tentatives
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"unreachable"}'], 'id = ?', [$cid]);
    $r = $E::certifySale($sales[2]);
    eq('pending', $r['status']);
    ok(str_contains($r['message'], 'NON CERTIFIÉE'), 'message clair');
    $q = $r['certification']['id'];
    eq(1, (int) DB::value('SELECT COUNT(*) FROM fiscal_pending_queue WHERE certification_id = ?', [$q]), 'en file d\'attente');
    DB::run('UPDATE fiscal_pending_queue SET next_attempt_at = NOW() - INTERVAL 1 MINUTE');
    eq(['processed' => 1, 'certified' => 0], $E::processQueue(), 'file traitée, toujours en panne');
    DB::run('UPDATE fiscal_pending_queue SET next_attempt_at = NOW() - INTERVAL 1 MINUTE');
    $E::processQueue();
    eq('failed', DB::value('SELECT status FROM fiscal_certifications WHERE id = ?', [$q]), 'tentatives épuisées → échec, action manuelle');
    eq(0, (int) DB::value('SELECT COUNT(*) FROM fiscal_pending_queue WHERE certification_id = ?', [$q]));
    // Relance manuelle après retour du réseau
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"accept"}'], 'id = ?', [$cid]);
    eq('certified', $E::attempt($q, true)['status'], 'relance manuelle réussie');
    // 6. Réponse perdue : le dispositif a certifié mais la caisse n'a rien reçu → au second essai, on demande l'état, pas de nouvel envoi
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"lost_response"}'], 'id = ?', [$cid]);
    $r = $E::certifySale($sales[3]);
    eq('pending', $r['status'], 'réponse perdue → non certifiée');
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"accept"}'], 'id = ?', [$cid]);
    DB::run('UPDATE fiscal_pending_queue SET next_attempt_at = NOW() - INTERVAL 1 MINUTE');
    $E::processQueue();
    $c3 = $E::forSale($sales[3]);
    eq('certified', $c3['status']);
    $acts = array_column(DB::all('SELECT action FROM fiscal_invoice_logs WHERE certification_id = ? ORDER BY id', [$c3['id']]), 'action');
    eq(['certifyInvoice', 'getInvoiceStatus'], $acts, 'état demandé au dispositif, aucun second envoi');
    $sim = json_decode((string) file_get_contents(STORAGE_PATH . "/fiscal/simulation-$cid.json"), true);
    ok(!isset($sim['invoices'][$c3['idempotency_key']]['duplicate_hits']), 'le dispositif n\'a reçu la facture qu\'une fois');
    // 7. Réponse positive incomplète → refusée par le contrôle
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"invalid"}', 'max_retries' => 1], 'id = ?', [$cid]);
    $r = $E::certifySale($sales[4]);
    ok($r['status'] !== 'certified' && str_contains($r['message'], 'Numéro de certification absent'), 'réponse incomplète refusée : ' . $r['message']);
    // 8. Authentification refusée → échec, pas de nouvel essai automatique
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"auth_fail"}', 'max_retries' => 3], 'id = ?', [$cid]);
    eq('failed', $E::certifySale($sales[5])['status']);
    // 9. Séparation TEST / PROD : un profil de production n'utilise jamais la simulation
    DB::update('fiscal_profiles', ['environment' => 'prod'], 'id = ?', [$pid]);
    eq(null, $E::connectorFor(DB::one('SELECT * FROM fiscal_profiles WHERE id = ?', [$pid]), $store, null), 'simulation interdite en production');
    DB::update('fiscal_profiles', ['environment' => 'test'], 'id = ?', [$pid]);
    // 10. Taux non rattaché à un groupe fiscal configuré → non envoyé
    DB::insert('fiscal_tax_rates', ['country_id' => $ne, 'group_code' => 'Z', 'label' => 'Taux test', 'rate' => 99, 'official_source' => 'test automatisé']);
    DB::update('fiscal_connectors', ['config_json' => '{"scenario":"accept"}'], 'id = ?', [$cid]);
    $r = $E::certifySale($sales[6] ?? $sales[5]);
    ok($r['status'] !== 'certified', 'taux non configuré : non certifiée');
    DB::run('DELETE FROM fiscal_tax_rates WHERE group_code = ?', ['Z']);
    // 11. Annulation : bloquée pour une facture certifiée
    ok(str_contains((string) $E::cancelBlocker($sales[0]), 'avoir'), 'annulation locale refusée');
    // Secrets jamais dans les journaux
    eq(0, (int) DB::value("SELECT COUNT(*) FROM fiscal_invoice_logs WHERE request_redacted LIKE '%CLE-SECRETE%' OR response_redacted LIKE '%CLE-SECRETE%'"));
    eq('{"api_key":"***","a":"Authorization: ***"}', json_encode(Addons\Fiscal\Secrets::redact(['api_key' => 'x', 'a' => 'Authorization: Bearer abc'])));
    $GLOBALS['FISCAL_SIMU'] = $cid;
});

test('Fiscal Connect (phase 5) : pilotes REST, OAuth2, SOAP, TCP, série, squelettes pays (sur une fausse API locale)', function () use ($port) {
    actingAs('admin');
    $E = Addons\Fiscal\FiscalEngine::class;
    $R = Addons\Fiscal\DriverResult::class;
    $fport = $port + 11;
    @unlink(sys_get_temp_dir() . "/fake-fiscal-$fport.json");
    $api = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $fport, escapeshellarg(BASE_PATH . '/tests/fake-fiscal-api.php')), [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $ap);
    usleep(500000);
    try {
        $profile = DB::one('SELECT * FROM fiscal_profiles WHERE active = 1 ORDER BY id LIMIT 1');
        $free = array_map('intval', array_column(DB::all("SELECT id FROM sales WHERE status = 'completed' AND id NOT IN (SELECT sale_id FROM fiscal_certifications) ORDER BY id LIMIT 6"), 'id'));
        ok(count($free) >= 5, 'ventes non certifiées disponibles');
        DB::run('UPDATE fiscal_connectors SET active = 0, is_default = 0');
        $cfg = fn(string $mode, array $extra = []) => json_encode($extra + ['base_url' => "http://127.0.0.1:$fport/$mode", 'auth' => ['type' => 'api_key', 'header' => 'X-Api-Key'],
            'endpoints' => ['test' => ['method' => 'GET', 'path' => '/status'], 'certify' => ['method' => 'POST', 'path' => '/invoices'], 'status' => ['method' => 'GET', 'path' => '/invoices/{{idempotency_key}}']],
            'response_map' => ['fiscal_number' => 'data.code', 'device_id' => 'data.mcf', 'signature' => 'data.sig', 'qr_payload' => 'data.qr'], 'success' => ['path' => 'result', 'values' => ['ACCEPTED']]]);
        $rid = DB::insert('fiscal_connectors', ['name' => 'API REST test', 'kind' => 'api', 'driver' => 'generic_rest', 'transport' => 'rest', 'environment' => 'test', 'is_default' => 1, 'active' => 1, 'timeout_s' => 2, 'max_retries' => 3, 'config_json' => $cfg('ok')]);
        $conn = fn() => DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$rid]);
        $drv = fn() => Addons\Fiscal\DriverRegistry::make($conn());
        eq($R::CONFIG_ERROR, $drv()->testConnection()->code, 'clé absente : erreur de configuration explicite');
        Addons\Fiscal\Secrets::put($rid, 'api_key', 'BAD-KEY-000000');
        eq($R::AUTH_FAILED, $drv()->testConnection()->code, 'mauvaise clé : authentification refusée');
        Addons\Fiscal\Secrets::put($rid, 'api_key', 'GOOD-KEY-123456');
        ok($drv()->testConnection()->isOk(), 'connexion et authentification');
        // Certification réelle par le moteur via HTTP
        $r = $E::certifySale($free[0]);
        eq('certified', $r['status'], $r['message']);
        ok(str_starts_with($r['certification']['fiscal_number'], 'API-') && $r['certification']['device_id'] === 'DEV-01' && (int) $r['certification']['is_simulation'] === 0, 'données officielles de la (fausse) API stockées');
        eq(0, (int) DB::value("SELECT COUNT(*) FROM fiscal_invoice_logs WHERE connector_id = ? AND (request_redacted LIKE '%GOOD-KEY%' OR request_redacted LIKE '%BAD-KEY%')", [$rid]), 'clé absente des journaux');
        ok(str_contains((string) DB::value("SELECT request_redacted FROM fiscal_invoice_logs WHERE connector_id = ? AND action = 'certifyInvoice'", [$rid]), 'X-Api-Key: ***'), 'en-tête masqué');
        ok(!str_contains((string) $r['certification']['proof_json'], 'GOOD-KEY'), 'clé absente de la preuve');
        // État d'une facture : connue / inconnue
        eq($R::CERTIFIED, $drv()->getInvoiceStatus($r['certification']['idempotency_key'])->code, 'facture retrouvée');
        eq($R::NOT_FOUND, $drv()->getInvoiceStatus(str_repeat('a', 64))->code, 'facture inconnue');
        eq($R::UNSUPPORTED, $drv()->closeDay(date('Y-m-d'))->code, 'point d\'accès non configuré : non pris en charge');
        // Refus, panne, réponse illisible, délai
        DB::update('fiscal_connectors', ['config_json' => $cfg('reject')], 'id = ?', [$rid]);
        eq('rejected', $E::certifySale($free[1])['status'], 'HTTP 422 → rejetée');
        DB::update('fiscal_connectors', ['config_json' => $cfg('down')], 'id = ?', [$rid]);
        eq('pending', $E::certifySale($free[2])['status'], 'HTTP 503 → file d\'attente');
        DB::update('fiscal_connectors', ['config_json' => $cfg('garbage')], 'id = ?', [$rid]);
        eq($R::INVALID_RESPONSE, $drv()->testConnection()->code, 'réponse non JSON');
        DB::update('fiscal_connectors', ['config_json' => $cfg('slow'), 'timeout_s' => 1], 'id = ?', [$rid]);
        eq($R::TIMEOUT, $drv()->testConnection()->code, 'délai dépassé');
        // Retour du service : la facture en attente est certifiée par la file
        DB::update('fiscal_connectors', ['config_json' => $cfg('ok'), 'timeout_s' => 2], 'id = ?', [$rid]);
        DB::run('UPDATE fiscal_pending_queue SET next_attempt_at = NOW() - INTERVAL 1 MINUTE');
        $E::processQueue();
        eq('certified', $E::forSale($free[2])['status'], 'certifiée au retour du service');
        // Production : HTTPS obligatoire, vérification SSL non désactivable
        DB::update('fiscal_connectors', ['environment' => 'prod', 'config_json' => $cfg('ok', ['verify_ssl' => false])], 'id = ?', [$rid]);
        eq($R::CONFIG_ERROR, $drv()->testConnection()->code, 'http refusé en production');
        DB::update('fiscal_connectors', ['environment' => 'test'], 'id = ?', [$rid]);

        // OAuth2 client credentials : jeton obtenu, chiffré, réutilisé
        $oid = DB::insert('fiscal_connectors', ['name' => 'API OAuth2', 'kind' => 'api', 'driver' => 'generic_rest', 'environment' => 'test', 'active' => 0, 'timeout_s' => 3,
            'config_json' => $cfg('oa', ['auth' => ['type' => 'oauth2', 'token_url' => "http://127.0.0.1:$fport/oauth/token"]])]);
        Addons\Fiscal\Secrets::put($oid, 'client_id', 'client-1');
        Addons\Fiscal\Secrets::put($oid, 'client_secret', 'SECRET-CLIENT-777');
        $od = Addons\Fiscal\DriverRegistry::make(DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$oid]));
        $inv = Addons\Fiscal\InvoiceNormalizer::fromSale($free[3], $profile);
        $c = $od->certifyInvoice($inv);
        eq($R::CERTIFIED, $c->code, $c->message);
        ok($od->testConnection()->isOk(), 'second appel');
        $hits = json_decode(file_get_contents(sys_get_temp_dir() . "/fake-fiscal-$fport.json"), true)['hits'];
        eq(1, count(array_filter($hits, fn($h) => $h === 'POST /oauth/token')), 'jeton OAuth2 demandé une seule fois (cache)');
        ok(!str_contains((string) DB::value("SELECT value_enc FROM fiscal_api_credentials WHERE connector_id = ? AND ckey = 'oauth_token'", [$oid]), 'TOKEN-OAUTH'), 'jeton chiffré');
        ok(!str_contains((string) $c->request, 'SECRET-CLIENT'), 'secret client absent de la requête rapportée');

        // SOAP / XML
        $tpl = '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><Certify><Number>{{local_number}}</Number><Buyer>{{buyer.name}}</Buyer><Total>{{totals.ttc}}</Total></Certify></s:Body></s:Envelope>';
        $sid = DB::insert('fiscal_connectors', ['name' => 'SOAP', 'kind' => 'api', 'driver' => 'generic_soap', 'environment' => 'test', 'active' => 0, 'config_json' => json_encode([
            'base_url' => "http://127.0.0.1:$fport/soap", 'endpoints' => ['certify' => ['action' => 'urn:Certify', 'template' => $tpl]],
            'response_map' => ['fiscal_number' => "//*[local-name()='Code']", 'signature' => "//*[local-name()='Signature']"], 'success' => ['xpath' => "//*[local-name()='Status']", 'values' => ['OK']]])]);
        $sd = Addons\Fiscal\DriverRegistry::make(DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$sid]));
        $inv['buyer'] = ['name' => 'Société <A&B>'];
        $c = $sd->certifyInvoice($inv);
        eq([$R::CERTIFIED, 'SOAP-' . $inv['local_number'], 'SIG-SOAP'], [$c->code, $c->data['fiscal_number'] ?? null, $c->data['signature'] ?? null], $c->message);
        ok(str_contains((string) $c->request, 'Société &lt;A&amp;B&gt;'), 'valeurs échappées en XML');
        DB::update('fiscal_connectors', ['config_json' => json_encode(['base_url' => "http://127.0.0.1:$fport/soap", 'endpoints' => ['certify' => ['template' => str_replace('<Certify>', '<Certify><Reject>1</Reject>', $tpl)]]])], 'id = ?', [$sid]);
        eq($R::REJECTED, Addons\Fiscal\DriverRegistry::make(DB::one('SELECT * FROM fiscal_connectors WHERE id = ?', [$sid]))->certifyInvoice($inv)->code, 'SOAP Fault → rejetée');
    } finally {
        proc_terminate($api);
    }

    // TCP (JSON par ligne) : faux dispositif réseau
    $tport = 22000 + random_int(0, 80);
    $script = '$l=stream_socket_server("tcp://127.0.0.1:' . $tport . '");$c=stream_socket_accept($l,10);$m=json_decode(fgets($c),true);'
        . 'fwrite($c,json_encode(["ok"=>"1","n"=>"TCP-".$m["local_number"],"sig"=>"S1","mcf"=>"MCF-TCP"])."\n");fclose($c);';
    $srv = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pp);
    usleep(400000);
    $tc = ['id' => 0, 'name' => 'TCP', 'kind' => 'mcf', 'driver' => 'generic_tcp', 'environment' => 'test', 'timeout_s' => 3, 'config_json' => json_encode(['host' => '127.0.0.1', 'port' => $tport,
        'endpoints' => ['certify' => ['template' => '{"op":"certify","local_number":"{{local_number}}","ttc":{{json:totals.ttc}}}']], 'response_map' => ['fiscal_number' => 'n', 'signature' => 'sig', 'device_id' => 'mcf'], 'success' => ['path' => 'ok', 'values' => ['1']]])];
    $c = Addons\Fiscal\DriverRegistry::make($tc)->certifyInvoice($inv);
    proc_terminate($srv);
    eq([$R::CERTIFIED, 'TCP-' . $inv['local_number'], 'MCF-TCP'], [$c->code, $c->data['fiscal_number'] ?? null, $c->data['device_id'] ?? null], $c->message);
    eq($R::UNREACHABLE, Addons\Fiscal\DriverRegistry::make($tc)->certifyInvoice($inv)->code, 'dispositif TCP éteint → injoignable');
    // Série : validation stricte du port ; port absent → injoignable (pas de matériel ici)
    $se = ['id' => 0, 'kind' => 'mcf', 'driver' => 'generic_serial', 'environment' => 'test', 'config_json' => json_encode(['port' => '/dev/sda; rm -rf /', 'endpoints' => ['test' => []]])];
    eq($R::CONFIG_ERROR, Addons\Fiscal\DriverRegistry::make($se)->testConnection()->code, 'port série invalide refusé');
    $se['config_json'] = json_encode(['port' => PHP_OS_FAMILY === 'Windows' ? 'COM98' : '/dev/ttyUSB97', 'endpoints' => ['test' => []]]);
    eq($R::UNREACHABLE, Addons\Fiscal\DriverRegistry::make($se)->testConnection()->code, 'port série absent → injoignable');
    // Squelettes pays : jamais de certification
    foreach (['ne_secef', 'bj_skeleton', 'tg_skeleton'] as $code) {
        $d = Addons\Fiscal\DriverRegistry::make(['id' => 0, 'kind' => 'mcf', 'driver' => $code, 'environment' => 'test']);
        eq([$R::UNSUPPORTED, $R::UNSUPPORTED], [$d->testConnection()->code, $d->certifyInvoice($inv)->code], "$code non pris en charge");
    }
    foreach (Addons\Fiscal\DriverRegistry::all() as $code => $i) {
        ok($i['verified'] === false, "$code non déclaré vérifié");
    }
    DB::run('UPDATE fiscal_connectors SET active = 0, is_default = 0');
    DB::update('fiscal_connectors', ['active' => 1, 'is_default' => 1, 'config_json' => '{"scenario":"accept"}'], 'id = ?', [$GLOBALS['FISCAL_SIMU']]);
});

test('Fiscal Connect (phase 6) : interface — 11 écrans, permissions, secrets jamais affichés, validations, webhook signé, OpenAPI, certificat', function () use ($B) {
    $a = new Http();
    $a->login($B, 'admin', 'Essai-Niamey-2026');
    $sim = $GLOBALS['FISCAL_SIMU'];
    $cert = (int) DB::value("SELECT id FROM fiscal_certifications WHERE status = 'certified' ORDER BY id LIMIT 1");
    $ne = (int) DB::value("SELECT id FROM fiscal_countries WHERE code = 'NE'");
    $log = (int) DB::value('SELECT MAX(id) FROM fiscal_invoice_logs');
    $pages = ['/fiscal', '/fiscal/connecteurs?type=mcf', '/fiscal/connecteurs?type=api', '/fiscal/pays', '/fiscal/parametres', '/fiscal/certificats', '/fiscal/historique', '/fiscal/attente',
        '/fiscal/rapports', '/fiscal/journaux', '/fiscal/journaux?vue=audit', '/fiscal/documentation', '/fiscal/connecteurs/nouveau?type=api', "/fiscal/connecteurs/$sim", "/fiscal/pays/$ne",
        "/fiscal/certifications/$cert", "/fiscal/journaux/$log", '/fiscal/historique?export=csv', '/fiscal/documentation?doc=CONFIGURATION'];
    foreach ($pages as $u) {
        $r = $a->req('GET', $B . $u);
        eq(200, $r['code'], $u);
        ok(!str_contains($r['body'], 'Fatal') && !str_contains($r['body'], 'Warning:'), "$u sans erreur PHP");
    }
    $menu = $a->req('GET', "$B/fiscal")['body'];
    ok(str_contains($menu, 'FISCAL CONNECT') && str_contains($menu, 'Documentation des connecteurs'), 'menu FISCAL CONNECT');
    // Permissions : le caissier n'a pas accès
    $k = new Http(); $k->login($B, 'caissier', 'Mot-de-passe-2026');
    foreach (['/fiscal', '/fiscal/parametres', '/fiscal/journaux'] as $u) {
        eq(403, $k->req('GET', $B . $u)['code'], "caissier : $u interdit");
    }
    // Les secrets ne sont jamais renvoyés au navigateur
    $rest = (int) DB::value("SELECT id FROM fiscal_connectors WHERE driver = 'generic_rest' ORDER BY id LIMIT 1");
    $html = $a->req('GET', "$B/fiscal/connecteurs/$rest")['body'];
    ok(str_contains($html, 'enregistré') && !str_contains($html, 'GOOD-KEY-123456'), 'clé d\'API : repère seulement');
    eq(0, (int) DB::value("SELECT COUNT(*) FROM fiscal_audit_logs WHERE after_json LIKE '%GOOD-KEY%' OR after_json LIKE '%SECRET-CLIENT%'"), 'secrets absents du journal d\'audit');
    // Création d'un connecteur par le formulaire
    $t = $a->token("$B/fiscal/connecteurs/nouveau?type=api");
    $a->req('POST', "$B/fiscal/connecteurs", http_build_query(['_csrf' => $t, 'name' => 'API formulaire', 'kind' => 'api', 'driver' => 'generic_rest', 'environment' => 'test', 'timeout_s' => 5, 'max_retries' => 3,
        'config_json' => '{"base_url":"https://api.exemple.test","auth":{"type":"bearer"}}']));
    $nid = (int) DB::value("SELECT id FROM fiscal_connectors WHERE name = 'API formulaire'");
    ok($nid > 0 && (int) DB::value('SELECT active FROM fiscal_connectors WHERE id = ?', [$nid]) === 0, 'créé inactif');
    $a->req('POST', "$B/fiscal/connecteurs/$nid", http_build_query(['_csrf' => $t, 'name' => 'API formulaire', 'kind' => 'api', 'driver' => 'generic_rest', 'environment' => 'test', 'config_json' => '{"base_url":"https://x","password":"abc"}']));
    ok(!str_contains((string) DB::value('SELECT config_json FROM fiscal_connectors WHERE id = ?', [$nid]), 'abc'), 'secret glissé dans la configuration refusé');
    $a->req('POST', "$B/fiscal/connecteurs", http_build_query(['_csrf' => $t, 'name' => 'Simu prod', 'kind' => 'simulation', 'driver' => 'simulation', 'environment' => 'prod']));
    eq(0, (int) DB::value("SELECT COUNT(*) FROM fiscal_connectors WHERE name = 'Simu prod'"), 'simulation en production refusée');
    $a->req('POST', "$B/fiscal/connecteurs/$nid/secrets", http_build_query(['_csrf' => $t, 'secret' => ['bearer_token' => 'JETON-FORMULAIRE-555']]));
    eq('JETON-FORMULAIRE-555', Addons\Fiscal\Secrets::get($nid, 'bearer_token'), 'secret enregistré par le formulaire');
    // Squelette : activation impossible
    $sk = Addons\Fiscal\FiscalAdmin::saveConnector(['name' => 'Niger squelette', 'kind' => 'mcf', 'driver' => 'ne_secef', 'environment' => 'test']);
    $a->req('POST', "$B/fiscal/connecteurs/$sk/activer", http_build_query(['_csrf' => $t]));
    eq(0, (int) DB::value('SELECT active FROM fiscal_connectors WHERE id = ?', [$sk]), 'squelette non activable');
    // Test de connexion depuis l'écran (simulation)
    $a->req('POST', "$B/fiscal/connecteurs/$sim/tester", http_build_query(['_csrf' => $t]));
    eq('ok', DB::value('SELECT status FROM fiscal_connectors WHERE id = ?', [$sim]), 'test enregistré');
    eq('SIMU-MCF-0001', DB::value('SELECT device_id FROM fiscal_devices WHERE connector_id = ?', [$sim]), 'informations du dispositif lues');
    // Suppression refusée pour un connecteur ayant servi
    $a->req('POST', "$B/fiscal/connecteurs/$sim/supprimer", http_build_query(['_csrf' => $t]));
    ok((bool) DB::value('SELECT 1 FROM fiscal_connectors WHERE id = ?', [$sim]), 'connecteur utilisé conservé');
    $a->req('POST', "$B/fiscal/connecteurs/$sk/supprimer", http_build_query(['_csrf' => $t]));
    ok(!DB::value('SELECT 1 FROM fiscal_connectors WHERE id = ?', [$sk]), 'connecteur inutilisé supprimé');
    // Pays : aucune règle sans source officielle
    $a->req('POST', "$B/fiscal/pays/$ne", http_build_query(['_csrf' => $t, 'currency' => 'XOF', 'certification_mode' => 'mcf', 'configured' => 1]));
    eq(0, (int) DB::value('SELECT configured FROM fiscal_countries WHERE id = ?', [$ne]), 'règles sans source refusées');
    $a->req('POST', "$B/fiscal/pays/$ne/taux", http_build_query(['_csrf' => $t, 'group_code' => 'T', 'label' => 'Test', 'rate' => '5']));
    eq(0, (int) DB::value("SELECT COUNT(*) FROM fiscal_tax_rates WHERE group_code = 'T'"), 'taux sans source refusé');
    // Profil de production refusé tant que le pays n'est pas configuré
    $pid = Addons\Fiscal\FiscalAdmin::saveProfile(['name' => 'Prod NE', 'country_id' => $ne, 'environment' => 'prod', 'seller_nif' => '1']);
    $a->req('POST', "$B/fiscal/parametres/$pid/activer", http_build_query(['_csrf' => $t]));
    eq(0, (int) DB::value('SELECT active FROM fiscal_profiles WHERE id = ?', [$pid]), 'production refusée : pays non configuré');
    DB::run('DELETE FROM fiscal_profiles WHERE id = ?', [$pid]);
    // Import OpenAPI
    $spec = sys_get_temp_dir() . '/openapi-' . getmypid() . '.json';
    file_put_contents($spec, json_encode(['openapi' => '3.0.0', 'info' => ['title' => 'API Test', 'version' => '1'], 'servers' => [['url' => 'https://api.exemple.test/v2']], 'paths' => ['/invoices' => ['post' => ['summary' => 'Certifier']], '/invoices/{id}' => ['get' => ['operationId' => 'status']]]]));
    $a->req('POST', "$B/fiscal/connecteurs/$nid/openapi", ['_csrf' => $t, 'openapi' => new CURLFile($spec, 'application/json', 'api.json')]);
    $cfg = json_decode((string) DB::value('SELECT config_json FROM fiscal_connectors WHERE id = ?', [$nid]), true);
    eq(2, count($cfg['openapi']['operations'] ?? []), 'opérations lues');
    // Certificat CA (auto-signé de test)
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'ca.test'], $key), null, $key, 30);
    openssl_x509_export($x509, $pem);
    $pf = sys_get_temp_dir() . '/ca-' . getmypid() . '.pem';
    file_put_contents($pf, $pem);
    $a->req('POST', "$B/fiscal/certificats", ['_csrf' => $t, 'connector_id' => $nid, 'kind' => 'ca', 'file' => new CURLFile($pf, 'application/x-pem-file', 'ca.pem')]);
    $c = DB::one('SELECT * FROM fiscal_certificates WHERE connector_id = ?', [$nid]);
    ok($c && str_contains((string) $c['subject'], 'ca.test') && !str_starts_with($c['path'], PUBLIC_PATH) && is_file($c['path']), 'certificat stocké hors du dossier public');
    // Webhook : signature exigée, contenu non cru sur parole
    $w = Addons\Fiscal\FiscalAdmin::createWebhook($sim);
    $pending = DB::one("SELECT * FROM fiscal_certifications WHERE status IN ('pending','failed') AND connector_id = ? ORDER BY id LIMIT 1", [$sim]);
    $body = json_encode(['idempotency_key' => $pending['idempotency_key'] ?? str_repeat('0', 64), 'status' => 'certified', 'fiscal_number' => 'FAUX-123']);
    $hook = "$B/fiscal/webhook/{$w['id']}?t={$w['token']}";
    eq(401, (new Http())->req('POST', $hook, $body, ['Content-Type: application/json', 'X-Fiscal-Signature: sha256=00'])['code'], 'signature invalide refusée');
    eq(404, (new Http())->req('POST', "$B/fiscal/webhook/{$w['id']}?t=mauvais", $body, ['Content-Type: application/json'])['code'], 'jeton invalide');
    $r = (new Http())->req('POST', $hook, $body, ['Content-Type: application/json', 'X-Fiscal-Signature: sha256=' . hash_hmac('sha256', $body, $w['secret'])]);
    eq(200, $r['code'], $r['body']);
    eq(0, (int) DB::value("SELECT COUNT(*) FROM fiscal_certifications WHERE fiscal_number = 'FAUX-123'"), 'numéro du webhook jamais recopié');
    if ($pending) {
        ok(DB::value('SELECT status FROM fiscal_certifications WHERE id = ?', [$pending['id']]) !== 'certified', 'webhook sans confirmation du dispositif : non certifiée');
    }
    // Relance manuelle depuis l'écran
    $f = (int) DB::value("SELECT id FROM fiscal_certifications WHERE status = 'failed' AND connector_id = ? ORDER BY id LIMIT 1", [$sim]);
    if ($f) {
        $a->req('POST', "$B/fiscal/certifications/$f/relancer", http_build_query(['_csrf' => $t]));
        eq('certified', DB::value('SELECT status FROM fiscal_certifications WHERE id = ?', [$f]), 'relancée et certifiée');
    }
});

test('Fiscal Connect (phase 7) : intégration ventes, tickets, factures A4, ESC/POS, avoirs, annulation, panne, blocage', function () use ($B) {
    $E = Addons\Fiscal\FiscalEngine::class;
    $sim = $GLOBALS['FISCAL_SIMU'];
    App\Core\Addons::reset();
    App\Core\Events::reset();
    actingAs('caissier');
    $reg = (int) DB::value('SELECT id FROM cash_registers ORDER BY id LIMIT 1');
    if (!S\CashService::openSessionForRegister($reg)) {
        S\CashService::open($reg, 0);
    }
    $wh = (int) DB::value('SELECT warehouse_id FROM cash_registers WHERE id = ?', [$reg]);
    $cands = array_column(DB::all("SELECT p.id FROM products p JOIN stock_levels l ON l.product_id = p.id AND l.warehouse_id = ? WHERE p.status = 'active' AND l.quantity > 30 ORDER BY l.quantity DESC LIMIT 15", [$wh]), 'id');
    $pid = 0;
    foreach ($cands as $cand) {
        try {
            S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => (int) $cand, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000000]]]);
            $pid = (int) $cand;
            break;
        } catch (Throwable) {
        }
    }
    ok($pid > 0, 'produit vendable trouvé');
    $sell = fn() => S\SaleService::create(['client_uuid' => uuid(), 'items' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000000]]]);
    $setSc = fn(string $sc) => DB::update('fiscal_connectors', ['config_json' => json_encode(['scenario' => $sc])], 'id = ?', [$sim]);
    $setSc('accept');
    DB::update('fiscal_profiles', ['print_uncertified' => 'mark'], 'active = 1');
    $prof = DB::one('SELECT * FROM fiscal_profiles WHERE active = 1 LIMIT 1');
    ok($prof && $prof['environment'] === 'test', 'profil de test actif');

    // 1. Vente → certification immédiate (simulation)
    $r = $sell();
    eq('certified', $r['fiscal_status'] ?? null, $r['fiscal_message'] ?? 'aucun statut fiscal');
    $c = $E::forSale($r['sale_id']);
    ok(str_starts_with((string) $c['fiscal_number'], 'SIMU-'), 'numéro de simulation');
    // 2. Ticket et facture A4 : mention « NON CERTIFIÉ – TEST » + données reçues
    $h = new Http(); $h->login($B, 'admin', 'Essai-Niamey-2026');
    $tk = $h->req('GET', "$B/sales/{$r['sale_id']}/ticket")['body'];
    ok(str_contains($tk, 'NON CERTIFIÉ – TEST') && str_contains($tk, $c['fiscal_number']), 'ticket : simulation clairement marquée');
    $inv = $h->req('GET', "$B/sales/{$r['sale_id']}/invoice")['body'];
    ok(str_contains($inv, 'NON CERTIFIÉ – TEST') && str_contains($inv, $c['fiscal_number']) && str_contains($inv, '123456/P'), 'facture A4 : bloc fiscal et NIF');
    $esc = S\PrintService::receipt($r['sale_id'], ['paper' => '80', 'open_drawer' => 0]);
    ok(str_contains($esc, $c['fiscal_number']) && str_contains($esc, 'NON CERTIFI'), 'ticket ESC/POS : bloc fiscal');
    // 3. Annulation locale refusée pour une facture certifiée
    actingAs('admin');
    throws(fn() => S\SaleService::cancel($r['sale_id'], 'erreur'), 'avoir');
    eq('completed', DB::value('SELECT status FROM sales WHERE id = ?', [$r['sale_id']]), 'vente intacte');
    // 4. Retour → avoir certifié, rattaché à la facture d'origine
    $item = (int) DB::value('SELECT id FROM sale_items WHERE sale_id = ?', [$r['sale_id']]);
    $ret = S\SaleService::createReturn($r['sale_id'], [$item => 1], 'cash', 'Produit défectueux', true);
    $cn = $E::forReturn($ret);
    eq('certified', $cn['status'] ?? null, 'avoir certifié');
    ok(str_contains((string) DB::value("SELECT request_redacted FROM fiscal_invoice_logs WHERE certification_id = ? AND action = 'issueCreditNote'", [$cn['id']]), $c['fiscal_number']), 'l\'avoir référence la facture certifiée');

    // 5. Panne : la vente est enregistrée, le ticket porte « NON CERTIFIÉE », rien n'est inventé
    actingAs('caissier');
    $setSc('unreachable');
    $r2 = $sell();
    ok($r2['sale_id'] > 0 && ($r2['fiscal_status'] ?? '') === 'pending', 'vente enregistrée malgré la panne, facture en attente');
    $tk2 = $h->req('GET', "$B/sales/{$r2['sale_id']}/ticket")['body'];
    ok(str_contains($tk2, 'FACTURE NON CERTIFIÉE') && !str_contains($tk2, 'SIMU-'), 'ticket non certifié : aucun numéro fiscal');
    // Retour du dispositif : la file certifie, le ticket est alors complet
    $setSc('accept');
    DB::run('UPDATE fiscal_pending_queue SET next_attempt_at = NOW() - INTERVAL 1 MINUTE');
    $E::processQueue();
    $c2 = $E::forSale($r2['sale_id']);
    eq('certified', $c2['status']);
    ok(str_contains($h->req('GET', "$B/sales/{$r2['sale_id']}/ticket")['body'], $c2['fiscal_number']), 'réimpression : numéro reçu');
    // 6. Mode « bloquer l'impression » d'une facture non certifiée
    DB::update('fiscal_profiles', ['print_uncertified' => 'block'], 'id = ?', [$prof['id']]);
    $setSc('unreachable');
    $r3 = $sell();
    eq(409, $h->req('GET', "$B/sales/{$r3['sale_id']}/ticket")['code'], 'ticket bloqué');
    eq(409, $h->req('GET', "$B/sales/{$r3['sale_id']}/invoice")['code'], 'facture bloquée');
    $pr = S\PrintService::printReceipt($r3['sale_id']);
    ok($pr['printed'] === 0 && str_contains(implode(' ', $pr['errors']), 'pas encore certifiée'), 'impression serveur bloquée');
    DB::update('fiscal_profiles', ['print_uncertified' => 'mark'], 'id = ?', [$prof['id']]);
    // 7. Vente annulée avant toute transmission : demande retirée (rien n'avait été envoyé)
    DB::update('fiscal_connectors', ['active' => 0], 'id = ?', [$sim]);
    $r4 = $sell();
    eq(0, (int) $E::forSale($r4['sale_id'])['attempts'], 'aucun connecteur : rien transmis');
    actingAs('admin');
    S\SaleService::cancel($r4['sale_id'], 'erreur de saisie');
    eq('failed', $E::forSale($r4['sale_id'])['status'], 'demande retirée');
    eq(0, (int) DB::value('SELECT COUNT(*) FROM fiscal_pending_queue WHERE certification_id = ?', [$E::forSale($r4['sale_id'])['id']]));
    $E::attempt((int) $E::forSale($r4['sale_id'])['id'], true);
    ok($E::forSale($r4['sale_id'])['status'] !== 'certified' && (int) $E::forSale($r4['sale_id'])['attempts'] === 0, 'vente annulée : jamais transmise, même en relance manuelle');
    DB::update('fiscal_connectors', ['active' => 1], 'id = ?', [$sim]);
    $setSc('accept');
    // 8. Un module en erreur n'empêche jamais la vente
    actingAs('caissier');
    App\Core\Events::on('sale.created', function () { throw new RuntimeException('module défaillant'); });
    $r5 = $sell();
    ok($r5['sale_id'] > 0 && DB::value('SELECT id FROM sales WHERE id = ?', [$r5['sale_id']]), 'vente enregistrée malgré un écouteur en erreur');
    // 9. Module désactivé : ventes et documents inchangés
    App\Core\Addons::setActive('fiscal', false);
    App\Core\Addons::reset();
    App\Core\Events::reset();
    $r6 = $sell();
    ok(!isset($r6['fiscal_status']) && $E::forSale($r6['sale_id']) === null, 'module inactif : aucune certification');
    $tk6 = $h->req('GET', "$B/sales/{$r6['sale_id']}/ticket")['body'];
    ok(!str_contains($tk6, 'CERTIFI'), 'module inactif : ticket inchangé');
    App\Core\Addons::setActive('fiscal', true);
    App\Core\Addons::reset();
    App\Core\Events::reset();
});

test('Fiscal Connect (phase 8) : sécurité — XSS, CSRF, droits, traversée de chemin, masquage, intégrité des certifiées', function () use ($B) {
    $a = new Http(); $a->login($B, 'admin', 'Essai-Niamey-2026');
    $t = $a->token("$B/fiscal/connecteurs/nouveau?type=api");
    // XSS : un nom piégé est affiché échappé
    $x = Addons\Fiscal\FiscalAdmin::saveConnector(['name' => '<script>alert(1)</script>', 'kind' => 'api', 'driver' => 'generic_rest', 'environment' => 'test']);
    $html = $a->req('GET', "$B/fiscal/connecteurs?type=api")['body'];
    ok(!str_contains($html, '<script>alert(1)</script>') && str_contains($html, '&lt;script&gt;'), 'nom échappé');
    // CSRF : POST sans jeton refusé
    eq(419, $a->req('POST', "$B/fiscal/connecteurs/$x/activer", '')['code'], 'jeton CSRF exigé');
    // Droits : le gérant voit, mais ne saisit pas de secret ni ne configure
    $g = new Http(); $g->login($B, 'gerant', 'Mot-de-passe-2026');
    eq(200, $g->req('GET', "$B/fiscal")['code'], 'gérant : tableau de bord');
    $gt = $g->token("$B/fiscal/attente");
    eq(403, $g->req('POST', "$B/fiscal/connecteurs/$x/secrets", http_build_query(['_csrf' => $gt, 'secret' => ['api_key' => 'PIRATE']]))['code'], 'gérant : secrets interdits');
    eq(403, $g->req('GET', "$B/fiscal/connecteurs/$x")['code'], 'gérant : configuration interdite');
    eq(null, Addons\Fiscal\Secrets::get($x, 'api_key'));
    // Traversée de chemin dans la documentation
    $d = $a->req('GET', "$B/fiscal/documentation?doc=" . urlencode('../../../.env'))['body'];
    ok(!str_contains($d, 'APP_KEY') && !str_contains($d, 'DB_PASSWORD'), 'aucun fichier hors liste');
    // Masquage : Basic, Bearer, JSON, formulaire
    $red = Addons\Fiscal\Secrets::redact("Authorization: Basic dXNlcjpwYXNz\nX-Api-Key: abc\n{\"client_secret\":\"s3\",\"password\":\"p4\"}\ngrant_type=x&client_secret=zz&token=tt", ['MONSECRET']);
    foreach (['dXNlcjpwYXNz', 'abc', 's3', 'p4', 'zz', 'tt'] as $leak) {
        ok(!preg_match('/\b' . preg_quote($leak, '/') . '\b/', $red), "masqué : $leak");
    }
    eq('***', Addons\Fiscal\Secrets::redact('MONSECRET', ['MONSECRET']), 'valeur connue masquée');
    // Intégrité : une facture certifiée n'est ni renvoyée ni modifiée par une relance
    $c = DB::one("SELECT * FROM fiscal_certifications WHERE status = 'certified' ORDER BY id LIMIT 1");
    $r = Addons\Fiscal\FiscalEngine::attempt((int) $c['id'], true);
    $after = DB::one('SELECT * FROM fiscal_certifications WHERE id = ?', [$c['id']]);
    eq([$c['fiscal_number'], $c['signature'], $c['attempts'], $c['proof_json']], [$after['fiscal_number'], $after['signature'], $after['attempts'], $after['proof_json']], 'certification inchangée');
    ok(str_contains($r['message'], 'Déjà certifiée'), $r['message']);
    // Clé de chiffrement : un secret chiffré ne se lit pas avec une autre APP_KEY
    $enc = Addons\Fiscal\Secrets::encrypt('valeur');
    $old = (string) App\Core\Env::get('APP_KEY', '');
    App\Core\Env::set('APP_KEY', str_repeat('z', 40));
    throws(fn() => Addons\Fiscal\Secrets::decrypt($enc), 'illisible');
    App\Core\Env::set('APP_KEY', (string) $old);
    eq('valeur', Addons\Fiscal\Secrets::decrypt($enc));
    // Certificats hors du dossier public
    ok(!str_starts_with(Addons\Fiscal\FiscalAdmin::certDir(), PUBLIC_PATH), 'certificats hors public/');
    DB::run('DELETE FROM fiscal_connectors WHERE id = ?', [$x]);
    // Rapports du dispositif : rapport et clôture (simulation), clôture tracée
    $sim = $GLOBALS['FISCAL_SIMU'];
    $page = $a->req('GET', "$B/fiscal/rapports")['body'];
    ok(str_contains($page, 'Rapport du jour') && str_contains($page, 'Clôture journalière') && str_contains($page, 'data-confirm'), 'boutons proposés seulement si pris en charge, clôture confirmée');
    $r = $a->req('POST', "$B/fiscal/rapports/dispositif/$sim", http_build_query(['_csrf' => $t, 'action' => 'report', 'date' => date('Y-m-d')]));
    eq(302, $r['code']);
    ok(str_contains($a->req('GET', "$B/fiscal/rapports")['body'], 'invoices'), 'rapport du dispositif affiché');
    $a->req('POST', "$B/fiscal/rapports/dispositif/$sim", http_build_query(['_csrf' => $t, 'action' => 'close', 'date' => date('Y-m-d')]));
    eq(1, (int) DB::value("SELECT COUNT(*) FROM fiscal_audit_logs WHERE action = 'device.close_day' AND entity_id = ?", [$sim]), 'clôture inscrite au journal d\'audit');
});

test('Installateur désactivé après installation', function () use ($B) {
    eq(404, (new Http())->req('GET', "$B/install")['code']);
});

proc_terminate($proc);
if (is_file(S\InstallService::lockFile() . '.bak')) {
    rename(S\InstallService::lockFile() . '.bak', S\InstallService::lockFile());
}

echo "\n" . str_repeat('=', 60) . "\n";
printf("Résultat : %d réussi(s), %d échec(s)\n", $results['pass'], $results['fail']);
foreach ($results['failures'] as $f) {
    echo " - $f\n";
}
exit($results['fail'] > 0 ? 1 : 0);
