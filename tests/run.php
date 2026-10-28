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
    eq(['0001_pressing', '0002_suivi_retraits', '0003_articles_au_poids'], App\Core\Addons::install('pressing', false), 'installation');
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
    $res = S\UpdateService::apply($t);
    ok($res['files'] > 200 && is_file(BASE_PATH . '/docs/TEST_MISE_A_JOUR.txt'), 'fichiers copiés');
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
    ok(!in_array('Doublon', array_column($P::recentClients(), 'customer_name'), true), 'numéro doublé jamais reproposé à la saisie');
    $js = (string) file_get_contents(BASE_PATH . '/addons/pressing/public/pressing.js');
    ok(str_contains($js, "closest('.pressing-tile[data-article]')") && !str_contains($js, 'type="number" min="1" max="200"'), 'saisie : un clic dans une ligne n\'ajoute pas de pièce ; virgule jamais avalée par un champ numérique');
    DB::run("DELETE FROM pressing_orders WHERE number = 'PR-TEST-DOUBLON'");
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
