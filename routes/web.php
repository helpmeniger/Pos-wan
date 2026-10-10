<?php
/**
 * Table des routes : méthode, chemin, action, permission requise.
 * Toute route non publique exige une session valide ; toute route POST
 * exige un jeton CSRF (contrôlé par le Router).
 *
 * @var App\Core\Router $router
 */
declare(strict_types=1);

use App\Controllers as C;

// --- Installation & authentification (publiques) ---
$router->get('/install', [C\InstallController::class, 'form'], null, true);
$router->post('/install', [C\InstallController::class, 'submit'], null, true);
$router->get('/login', [C\AuthController::class, 'form'], null, true);
$router->post('/login', [C\AuthController::class, 'login'], null, true);
$router->post('/login/pin', [C\AuthController::class, 'loginPin'], null, true);
$router->post('/login/badge', [C\AuthController::class, 'loginBadge'], null, true);
$router->post('/logout', [C\AuthController::class, 'logout']);
$router->get('/account/password', [C\AuthController::class, 'passwordForm']);
$router->post('/account/password', [C\AuthController::class, 'passwordUpdate']);
$router->post('/account/pin', [C\AuthController::class, 'pinUpdate']);
$router->post('/account/locale', [C\AuthController::class, 'localeUpdate']);
$router->get('/session/ping', [C\AuthController::class, 'ping']);

// --- Accueil ---
$router->get('/', [C\DashboardController::class, 'home']);
$router->get('/dashboard', [C\DashboardController::class, 'index'], 'dashboard.view');
$router->get('/menu', [C\DashboardController::class, 'menu']);

// --- Caisse (POS) ---
$router->get('/pos', [C\PosController::class, 'index'], 'pos.sell');
$router->get('/pos/products', [C\PosController::class, 'products'], 'pos.sell');
$router->get('/pos/lookup', [C\PosController::class, 'lookup'], 'pos.sell');
$router->get('/pos/customers', [C\PosController::class, 'customers'], 'pos.sell');
$router->post('/pos/customers', [C\PosController::class, 'quickCustomer'], 'customers.manage');
$router->post('/pos/checkout', [C\PosController::class, 'checkout'], 'pos.sell');
$router->get('/pos/held', [C\PosController::class, 'heldList'], 'pos.sell');
$router->post('/pos/hold', [C\PosController::class, 'hold'], 'pos.sell');
$router->post('/pos/held/{id}/resume', [C\PosController::class, 'resume'], 'pos.sell');
$router->get('/pos/recent', [C\PosController::class, 'recent'], 'pos.sell');

// --- Ventes, tickets, retours ---
$router->get('/sales', [C\SaleController::class, 'index'], 'sales.view');
$router->get('/sales/{id}', [C\SaleController::class, 'show'], 'sales.view');
$router->get('/sales/{id}/ticket', [C\SaleController::class, 'ticket'], 'sales.view');
$router->get('/sales/{id}/invoice', [C\SaleController::class, 'invoice'], 'sales.view');
$router->get('/sales/{id}/delivery', [C\SaleController::class, 'delivery'], 'sales.view');
$router->post('/sales/{id}/print', [C\SaleController::class, 'printServer'], 'sales.view');
$router->get('/quotes', [C\QuoteController::class, 'index'], 'quotes.manage');
$router->get('/quotes/create', [C\QuoteController::class, 'create'], 'quotes.manage');
$router->post('/quotes', [C\QuoteController::class, 'store'], 'quotes.manage');
$router->get('/quotes/{id}', [C\QuoteController::class, 'show'], 'quotes.manage');
$router->get('/quotes/{id}/edit', [C\QuoteController::class, 'edit'], 'quotes.manage');
$router->post('/quotes/{id}', [C\QuoteController::class, 'update'], 'quotes.manage');
$router->post('/quotes/{id}/status', [C\QuoteController::class, 'status'], 'quotes.manage');
$router->post('/quotes/{id}/duplicate', [C\QuoteController::class, 'duplicate'], 'quotes.manage');
$router->get('/quotes/{id}/print', [C\QuoteController::class, 'print'], 'quotes.manage');
$router->get('/quotes/{id}/convert', [C\QuoteController::class, 'convertForm'], 'quotes.manage');
$router->post('/quotes/{id}/convert', [C\QuoteController::class, 'convert'], 'quotes.manage');
$router->get('/verify', [C\SaleController::class, 'verify'], 'sales.view');
$router->post('/sales/{id}/cancel', [C\SaleController::class, 'cancel'], 'pos.cancel_sale');
$router->get('/sales/{id}/return', [C\SaleController::class, 'returnForm'], 'returns.create');
$router->post('/sales/{id}/return', [C\SaleController::class, 'returnStore'], 'returns.create');
$router->get('/returns', [C\SaleController::class, 'returns'], 'sales.view');
$router->get('/returns/{id}', [C\SaleController::class, 'returnReceipt'], 'sales.view');

// --- Caisse : sessions et mouvements ---
$router->get('/cash', [C\CashController::class, 'index'], 'cash.open_close');
$router->post('/cash/open', [C\CashController::class, 'open'], 'cash.open_close');
$router->post('/cash/close', [C\CashController::class, 'close'], 'cash.open_close');
$router->post('/cash/movement', [C\CashController::class, 'movement'], 'cash.movements');
$router->get('/cash/sessions', [C\CashController::class, 'sessions'], 'cash.view_all');
$router->get('/cash/sessions/{id}', [C\CashController::class, 'show'], 'cash.open_close');

// --- Articles ---
$router->get('/products', [C\ProductController::class, 'index'], 'products.view');
$router->get('/products/create', [C\ProductController::class, 'create'], 'products.manage');
$router->post('/products', [C\ProductController::class, 'store'], 'products.manage');
$router->get('/products/export', [C\ProductController::class, 'export'], 'products.import');
$router->get('/products/import', [C\ProductController::class, 'importForm'], 'products.import');
$router->post('/products/import', [C\ProductController::class, 'import'], 'products.import');
$router->get('/products/template', [C\ProductController::class, 'template'], 'products.import');
$router->get('/products/labels', [C\ProductController::class, 'labels'], 'products.view');
$router->get('/products/bulk-price', [C\ProductController::class, 'bulkPriceForm'], 'products.price_edit');
$router->post('/products/bulk-price', [C\ProductController::class, 'bulkPrice'], 'products.price_edit');
$router->get('/products/duplicates', [C\ProductController::class, 'duplicates'], 'products.view');
$router->get('/products/new-barcode', [C\ProductController::class, 'newBarcode'], 'products.manage');
$router->post('/products/generate-barcodes', [C\ProductController::class, 'bulkBarcodes'], 'products.manage');
$router->post('/products/{id}/barcode', [C\ProductController::class, 'assignBarcode'], 'products.manage');
$router->get('/products/{id}', [C\ProductController::class, 'show'], 'products.view');
$router->get('/products/{id}/edit', [C\ProductController::class, 'edit'], 'products.manage');
$router->post('/products/{id}', [C\ProductController::class, 'update'], 'products.manage');
$router->post('/products/{id}/archive', [C\ProductController::class, 'archive'], 'products.manage');
$router->post('/products/{id}/restore', [C\ProductController::class, 'restore'], 'products.manage');
$router->post('/products/{id}/packaging', [C\ProductController::class, 'addPackaging'], 'products.manage');
$router->post('/products/packaging/{id}/delete', [C\ProductController::class, 'deletePackaging'], 'products.manage');
$router->get('/barcode/{id}', [C\ProductController::class, 'barcode'], 'products.view');

$router->get('/categories', [C\CategoryController::class, 'index'], 'categories.manage');
$router->post('/categories', [C\CategoryController::class, 'store'], 'categories.manage');
$router->post('/categories/{id}', [C\CategoryController::class, 'update'], 'categories.manage');
$router->post('/brands', [C\CategoryController::class, 'storeBrand'], 'categories.manage');
$router->post('/units', [C\CategoryController::class, 'storeUnit'], 'categories.manage');

// --- Stocks ---
$router->get('/stock', [C\StockController::class, 'levels'], 'stock.view');
$router->get('/stock/movements', [C\StockController::class, 'movements'], 'stock.view');
$router->get('/stock/adjust', [C\StockController::class, 'adjustForm'], 'stock.adjust');
$router->post('/stock/adjust', [C\StockController::class, 'adjust'], 'stock.adjust');
$router->get('/stock/transfers', [C\StockController::class, 'transfers'], 'stock.view');
$router->get('/stock/transfers/create', [C\StockController::class, 'transferForm'], 'stock.transfer');
$router->post('/stock/transfers', [C\StockController::class, 'transferStore'], 'stock.transfer');
$router->get('/stock/transfers/{id}', [C\StockController::class, 'transferShow'], 'stock.view');
$router->get('/stock/expiry', [C\StockController::class, 'expiry'], 'stock.view');
$router->get('/stock/lots', [C\StockController::class, 'lots'], 'stock.view');
$router->post('/stock/lots/{id}/recall', [C\StockController::class, 'recall'], 'pharmacy.manage');
$router->get('/inventories', [C\StockController::class, 'inventories'], 'stock.inventory');
$router->post('/inventories', [C\StockController::class, 'inventoryStart'], 'stock.inventory');
$router->get('/inventories/{id}', [C\StockController::class, 'inventoryShow'], 'stock.inventory');
$router->post('/inventories/{id}', [C\StockController::class, 'inventorySave'], 'stock.inventory');
$router->post('/inventories/{id}/validate', [C\StockController::class, 'inventoryValidate'], 'stock.inventory_validate');
$router->post('/inventories/{id}/cancel', [C\StockController::class, 'inventoryCancel'], 'stock.inventory');

// --- Achats & fournisseurs ---
$router->get('/suppliers', [C\SupplierController::class, 'index'], 'purchases.view');
$router->get('/suppliers/create', [C\SupplierController::class, 'create'], 'suppliers.manage');
$router->post('/suppliers', [C\SupplierController::class, 'store'], 'suppliers.manage');
$router->get('/suppliers/{id}', [C\SupplierController::class, 'show'], 'purchases.view');
$router->get('/suppliers/{id}/edit', [C\SupplierController::class, 'edit'], 'suppliers.manage');
$router->post('/suppliers/{id}', [C\SupplierController::class, 'update'], 'suppliers.manage');
$router->post('/suppliers/{id}/pay', [C\SupplierController::class, 'pay'], 'suppliers.pay');
$router->get('/purchases', [C\PurchaseController::class, 'index'], 'purchases.view');
$router->get('/purchases/create', [C\PurchaseController::class, 'create'], 'purchases.manage');
$router->post('/purchases', [C\PurchaseController::class, 'store'], 'purchases.manage');
$router->get('/purchases/{id}', [C\PurchaseController::class, 'show'], 'purchases.view');
$router->post('/purchases/{id}/status', [C\PurchaseController::class, 'status'], 'purchases.manage');
$router->get('/purchases/{id}/receive', [C\PurchaseController::class, 'receiveForm'], 'purchases.manage');
$router->get('/receptions', [C\PurchaseController::class, 'receptions'], 'purchases.view');
$router->get('/receptions/create', [C\PurchaseController::class, 'receiveDirectForm'], 'purchases.manage');
$router->post('/receptions', [C\PurchaseController::class, 'receiveStore'], 'purchases.manage');
$router->get('/receptions/{id}', [C\PurchaseController::class, 'receptionShow'], 'purchases.view');
$router->get('/supplier-returns', [C\PurchaseController::class, 'supplierReturns'], 'purchases.view');
$router->get('/supplier-returns/create', [C\PurchaseController::class, 'supplierReturnForm'], 'purchases.manage');
$router->post('/supplier-returns', [C\PurchaseController::class, 'supplierReturnStore'], 'purchases.manage');
$router->get('/api/products', [C\PurchaseController::class, 'productSearch'], 'products.view');

// --- Clients ---
$router->get('/customers', [C\CustomerController::class, 'index'], 'customers.view');
$router->get('/customers/create', [C\CustomerController::class, 'create'], 'customers.manage');
$router->post('/customers', [C\CustomerController::class, 'store'], 'customers.manage');
$router->get('/customers/{id}', [C\CustomerController::class, 'show'], 'customers.view');
$router->get('/customers/{id}/edit', [C\CustomerController::class, 'edit'], 'customers.manage');
$router->post('/customers/{id}', [C\CustomerController::class, 'update'], 'customers.manage');
$router->post('/customers/{id}/payment', [C\CustomerController::class, 'payment'], 'customers.payments');
$router->get('/customers/{id}/statement', [C\CustomerController::class, 'statement'], 'customers.view');
$router->get('/customer-payments/{id}', [C\CustomerController::class, 'receipt'], 'customers.view');

// --- Dépenses & recettes ---
$router->get('/expenses', [C\ExpenseController::class, 'index'], 'expenses.manage');
$router->post('/expenses', [C\ExpenseController::class, 'store'], 'expenses.manage');
$router->post('/expenses/{id}/reverse', [C\ExpenseController::class, 'reverse'], 'expenses.reverse');
$router->post('/expense-categories', [C\ExpenseController::class, 'storeCategory'], 'expenses.manage');

// --- Rapports ---
$router->get('/reports', [C\ReportController::class, 'index'], 'reports.view');
$router->get('/reports/sales', [C\ReportController::class, 'sales'], 'reports.view');
$router->get('/reports/products', [C\ReportController::class, 'products'], 'reports.view');
$router->get('/reports/profit', [C\ReportController::class, 'profit'], 'reports.financial');
$router->get('/reports/stock', [C\ReportController::class, 'stock'], 'reports.financial');
$router->get('/reports/debts', [C\ReportController::class, 'debts'], 'reports.financial');
$router->get('/reports/cash', [C\ReportController::class, 'cash'], 'reports.financial');
$router->get('/reports/prescriptions', [C\ReportController::class, 'prescriptions'], 'reports.view');

// --- Administration ---
$router->get('/users', [C\UserController::class, 'index'], 'users.manage');
$router->get('/users/create', [C\UserController::class, 'create'], 'users.manage');
$router->post('/users', [C\UserController::class, 'store'], 'users.manage');
$router->get('/users/{id}/edit', [C\UserController::class, 'edit'], 'users.manage');
$router->post('/users/{id}', [C\UserController::class, 'update'], 'users.manage');
$router->post('/users/{id}/toggle', [C\UserController::class, 'toggle'], 'users.manage');
$router->post('/users/{id}/unlock', [C\UserController::class, 'unlock'], 'users.manage');
$router->get('/roles', [C\UserController::class, 'roles'], 'roles.manage');
$router->post('/roles', [C\UserController::class, 'roleStore'], 'roles.manage');
$router->get('/roles/{id}', [C\UserController::class, 'roleEdit'], 'roles.manage');
$router->post('/roles/{id}', [C\UserController::class, 'roleUpdate'], 'roles.manage');

$router->get('/settings', [C\SettingsController::class, 'index'], 'settings.manage');
$router->post('/settings', [C\SettingsController::class, 'update'], 'settings.manage');
$router->post('/settings/business', [C\SettingsController::class, 'business'], 'settings.manage');
$router->get('/settings/template-preview', [C\SettingsController::class, 'templatePreview'], 'settings.manage');
$router->post('/settings/currency', [C\SettingsController::class, 'currency'], 'settings.manage');
$router->post('/settings/mobile-money', [C\SettingsController::class, 'mobileMoney'], 'settings.manage');
$router->post('/settings/logo', [C\SettingsController::class, 'logo'], 'settings.manage');
$router->post('/settings/demo/archive', [C\SettingsController::class, 'archiveDemo'], 'settings.manage');
$router->get('/stores', [C\SettingsController::class, 'stores'], 'stores.manage');
$router->post('/stores', [C\SettingsController::class, 'storeStore'], 'stores.manage');
$router->post('/warehouses', [C\SettingsController::class, 'warehouseStore'], 'stores.manage');
$router->post('/registers', [C\SettingsController::class, 'registerStore'], 'stores.manage');
$router->get('/audit', [C\SettingsController::class, 'audit'], 'audit.view');
$router->get('/printers', [C\PrinterController::class, 'index'], 'settings.manage');
$router->post('/printers', [C\PrinterController::class, 'store'], 'settings.manage');
$router->post('/printers/{id}', [C\PrinterController::class, 'update'], 'settings.manage');
$router->post('/printers/{id}/delete', [C\PrinterController::class, 'delete'], 'settings.manage');
$router->post('/printers/{id}/test', [C\PrinterController::class, 'test'], 'settings.manage');
$router->get('/backups', [C\BackupController::class, 'index'], 'backup.manage');
$router->post('/backups', [C\BackupController::class, 'create'], 'backup.manage');
$router->post('/backups/download', [C\BackupController::class, 'download'], 'backup.manage');
$router->post('/backups/restore', [C\BackupController::class, 'restore'], 'backup.manage');

// --- Restaurant (module activé par le type d'activité « restaurant ») ---
$router->get('/restaurant', [C\RestaurantController::class, 'floor'], 'restaurant.order');
$router->get('/restaurant/floor', [C\RestaurantController::class, 'floorJson'], 'restaurant.order');
$router->get('/restaurant/products', [C\PosController::class, 'restaurantProducts'], 'restaurant.order');
$router->get('/restaurant/customers', [C\PosController::class, 'customers'], 'restaurant.order');
$router->get('/restaurant/orders', [C\RestaurantController::class, 'orders'], 'restaurant.order');
$router->post('/restaurant/orders', [C\RestaurantController::class, 'open'], 'restaurant.order');
$router->get('/restaurant/orders/{id}', [C\RestaurantController::class, 'show'], 'restaurant.order');
$router->get('/restaurant/orders/{id}/data', [C\RestaurantController::class, 'data'], 'restaurant.order');
$router->post('/restaurant/orders/{id}/items', [C\RestaurantController::class, 'addItem'], 'restaurant.order');
$router->post('/restaurant/orders/{id}/send', [C\RestaurantController::class, 'send'], 'restaurant.order');
$router->post('/restaurant/orders/{id}/serve', [C\RestaurantController::class, 'serve'], 'restaurant.order');
$router->post('/restaurant/orders/{id}/update', [C\RestaurantController::class, 'update'], 'restaurant.order');
$router->post('/restaurant/orders/{id}/transfer', [C\RestaurantController::class, 'transfer'], 'restaurant.transfer');
$router->post('/restaurant/orders/{id}/cancel', [C\RestaurantController::class, 'cancel'], 'restaurant.order');
$router->get('/restaurant/orders/{id}/bill', [C\RestaurantController::class, 'bill'], 'restaurant.order');
$router->get('/restaurant/orders/{id}/pay', [C\RestaurantController::class, 'payForm'], 'pos.sell');
$router->post('/restaurant/orders/{id}/pay', [C\RestaurantController::class, 'pay'], 'pos.sell');
$router->post('/restaurant/items/{id}', [C\RestaurantController::class, 'updateItem'], 'restaurant.order');
$router->post('/restaurant/items/{id}/cancel', [C\RestaurantController::class, 'cancelItem'], 'restaurant.order');
$router->get('/restaurant/setup', [C\RestaurantController::class, 'setup'], 'restaurant.manage');
$router->post('/restaurant/areas', [C\RestaurantController::class, 'areaStore'], 'restaurant.manage');
$router->post('/restaurant/tables', [C\RestaurantController::class, 'tableStore'], 'restaurant.manage');
$router->post('/restaurant/tables/{id}', [C\RestaurantController::class, 'tableUpdate'], 'restaurant.manage');
$router->get('/restaurant/recipes', [C\RestaurantController::class, 'recipes'], 'restaurant.manage');
$router->get('/restaurant/recipes/{id}', [C\RestaurantController::class, 'recipeEdit'], 'restaurant.manage');
$router->post('/restaurant/recipes/{id}', [C\RestaurantController::class, 'recipeSave'], 'restaurant.manage');
$router->get('/restaurant/report', [C\RestaurantController::class, 'report'], 'reports.view');
$router->get('/kitchen', [C\RestaurantController::class, 'kitchen'], 'restaurant.kitchen');
$router->get('/kitchen/feed', [C\RestaurantController::class, 'kitchenFeed'], 'restaurant.kitchen');
$router->post('/kitchen/tickets/{id}', [C\RestaurantController::class, 'ticketStatus'], 'restaurant.kitchen');
$router->get('/kitchen/tickets/{id}/print', [C\RestaurantController::class, 'ticketPrint']);
$router->post('/kitchen/tickets/{id}/print', [C\RestaurantController::class, 'ticketPrintNow']);
$router->post('/restaurant/orders/{id}/print-kitchen', [C\RestaurantController::class, 'printKitchen'], 'restaurant.order');
