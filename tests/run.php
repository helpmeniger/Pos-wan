<?php
/**
 * POS Wan — suite de tests automatisés (sans dépendance externe).
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
        ok(str_contains(implode(' ', $all['zz_test_ancien']['errors']), 'Nécessite POS Wan 99.0.0'), 'version requise contrôlée');
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
    eq(['0001_pressing'], App\Core\Addons::install('pressing', false), 'installation');
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
    eq(['0001_clinique'], App\Core\Addons::install('clinique', false), 'installation');
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
$proc = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, BASE_PATH, array_merge(getenv(), $env));
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
