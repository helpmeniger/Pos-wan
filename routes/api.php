<?php
/**
 * Routes de l'API REST MARWA (préfixe /api). Documentation : docs/API.md
 * Les droits sont vérifiés côté serveur par les middlewares ET dans les services
 * (propriété de la commande, rôle vis-à-vis de la commande, permissions d'administration).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AdminController;
use App\Controllers\Admin\ConfigController;
use App\Controllers\Admin\FinanceController;
use App\Controllers\Admin\SupportController;
use App\Controllers\Api\AddressController;
use App\Controllers\Api\AuthController;
use App\Controllers\Api\BusinessController;
use App\Controllers\Api\CatalogController;
use App\Controllers\Api\CommunicationController;
use App\Controllers\Api\GeoController;
use App\Controllers\Api\MerchantController;
use App\Controllers\Api\OrderController;
use App\Controllers\Api\PaymentController;
use App\Controllers\Api\ProviderController;

$router->group('/api', [], function ($router) {
    $router->get('/health', fn () => App\Core\Response::json(['status' => 'ok', 'time' => date('c')]));

    // --- Authentification -------------------------------------------------------------
    $router->get('/auth/csrf', [AuthController::class, 'csrf']);
    $router->post('/auth/register', [AuthController::class, 'register'], ['throttle:register,10,3600']);
    $router->post('/auth/login', [AuthController::class, 'login'], ['throttle:login,20,300']);
    $router->post('/auth/token', [AuthController::class, 'token'], ['throttle:login,20,300']);
    $router->post('/auth/logout', [AuthController::class, 'logout']);
    $router->post('/auth/password/forgot', [AuthController::class, 'forgot'], ['throttle:forgot,10,3600']);
    $router->post('/auth/password/reset', [AuthController::class, 'reset'], ['throttle:reset,10,3600']);

    // --- Public : carte, estimation, catalogue -----------------------------------------
    $router->get('/geo/config', [GeoController::class, 'config']);
    $router->get('/geo/search', [GeoController::class, 'search'], ['throttle:geo,60,60']);
    $router->get('/geo/reverse', [GeoController::class, 'reverse'], ['throttle:geo,60,60']);
    $router->get('/geo/zones', [GeoController::class, 'zones']);
    $router->get('/geo/landmarks', [GeoController::class, 'landmarks']);
    $router->post('/estimate', [GeoController::class, 'estimate'], ['throttle:estimate,40,60']);
    $router->get('/nearby', [GeoController::class, 'nearby'], ['auth', 'throttle:nearby,40,60']);
    $router->get('/merchants', [CatalogController::class, 'merchants']);
    $router->get('/merchants/{id}', [CatalogController::class, 'show']);
    $router->get('/promotions', [CatalogController::class, 'promotions']);

    // --- Paiements : notifications des prestataires (signature HMAC vérifiée) ----------
    $router->post('/webhooks/payments/{gateway}', [PaymentController::class, 'webhook'], ['throttle:webhook,120,60']);

    // --- Espace connecté (tous rôles) -------------------------------------------------
    $router->group('', ['auth'], function ($router) {
        $router->get('/auth/me', [AuthController::class, 'me']);
        $router->post('/auth/otp/send', [AuthController::class, 'sendOtp']);
        $router->post('/auth/otp/verify', [AuthController::class, 'verifyOtp'], ['throttle:otp_verify,10,600']);
        $router->put('/account/profile', [AuthController::class, 'updateProfile']);
        $router->post('/account/password', [AuthController::class, 'changePassword'], ['throttle:pwd,10,3600']);
        $router->post('/account/photo', [AuthController::class, 'photo'], ['throttle:upload,20,3600']);
        $router->post('/account/delete', [AuthController::class, 'deleteAccount'], ['throttle:pwd,10,3600']);

        $router->get('/notifications', [CommunicationController::class, 'notifications']);
        $router->post('/notifications/read', [CommunicationController::class, 'markRead']);
        $router->get('/events', [CommunicationController::class, 'events']);
        $router->get('/push/key', [CommunicationController::class, 'pushKey']);
        $router->post('/push/subscribe', [CommunicationController::class, 'pushSubscribe'], ['throttle:push,20,3600']);
        $router->post('/push/unsubscribe', [CommunicationController::class, 'pushUnsubscribe']);
        $router->post('/push/test', [CommunicationController::class, 'pushTest'], ['throttle:pushtest,5,600']);
        $router->get('/complaints', [CommunicationController::class, 'complaints']);
        $router->post('/complaints', [CommunicationController::class, 'createComplaint']);
        $router->get('/complaints/{id}', [CommunicationController::class, 'complaint']);
        $router->post('/complaints/{id}/messages', [CommunicationController::class, 'complaintMessage']);
        $router->post('/emergency', [CommunicationController::class, 'emergency']);

        // Commandes : l'accès à chaque commande est vérifié par OrderPolicy
        $router->get('/orders', [OrderController::class, 'index']);
        $router->get('/orders/{id}', [OrderController::class, 'show']);
        $router->get('/orders/{id}/tracking', [OrderController::class, 'tracking']);
        $router->get('/orders/{id}/messages', [OrderController::class, 'messages']);
        $router->post('/orders/{id}/messages', [OrderController::class, 'sendMessage']);
        $router->get('/orders/{id}/receipt', [OrderController::class, 'receipt']);
        $router->post('/payments/demo/{uuid}/simulate', [PaymentController::class, 'simulateDemo'], ['throttle:pay,20,60']);
    });

    // --- Clients et entreprises -------------------------------------------------------
    $router->group('', ['auth', 'role:client,business'], function ($router) {
        $router->get('/addresses', [AddressController::class, 'index']);
        $router->post('/addresses', [AddressController::class, 'store']);
        $router->put('/addresses/{id}', [AddressController::class, 'update']);
        $router->delete('/addresses/{id}', [AddressController::class, 'destroy']);
        $router->post('/orders', [OrderController::class, 'store']);
        $router->post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
        $router->post('/orders/{id}/offer', [OrderController::class, 'updateOffer']);
        $router->post('/orders/{id}/negotiations/{nid}/accept', [OrderController::class, 'acceptCounter']);
        $router->post('/orders/{id}/negotiations/{nid}/reject', [OrderController::class, 'rejectCounter']);
        $router->post('/orders/{id}/pay', [OrderController::class, 'pay'], ['throttle:pay,20,60']);
        $router->post('/orders/{id}/rate', [OrderController::class, 'rate']);

        $router->get('/business', [BusinessController::class, 'show']);
        $router->put('/business', [BusinessController::class, 'update']);
        $router->post('/business/members', [BusinessController::class, 'addMember']);
        $router->put('/business/members/{id}', [BusinessController::class, 'updateMember']);
        $router->get('/business/orders', [BusinessController::class, 'orders']);
        $router->get('/business/statement', [BusinessController::class, 'statement']);
        $router->post('/business/addresses', [BusinessController::class, 'saveAddress']);
        $router->delete('/business/addresses/{id}', [BusinessController::class, 'deleteAddress']);
        $router->post('/business/logo', [BusinessController::class, 'logo'], ['throttle:upload,20,3600']);
    });

    // --- Chauffeurs et livreurs -------------------------------------------------------
    $router->group('/provider', ['auth', 'role:driver,courier'], function ($router) {
        $router->get('/profile', [ProviderController::class, 'show']);
        $router->put('/profile', [ProviderController::class, 'update']);
        $router->post('/vehicle', [ProviderController::class, 'saveVehicle']);
        $router->post('/vehicle/photo', [ProviderController::class, 'vehiclePhoto'], ['throttle:upload,20,3600']);
        $router->post('/documents', [ProviderController::class, 'uploadDocument']);
        $router->get('/earnings', [ProviderController::class, 'earnings']);
        $router->get('/history', [ProviderController::class, 'history']);
        $router->post('/payouts', [ProviderController::class, 'requestPayout']);
        $router->post('/online', [ProviderController::class, 'setOnline'], ['verified_provider']);
        $router->post('/location', [ProviderController::class, 'location'], ['verified_provider', 'throttle:gps,40,60']);
        $router->get('/requests', [ProviderController::class, 'requests'], ['verified_provider']);
        $router->post('/requests/{id}/accept', [ProviderController::class, 'accept'], ['verified_provider', 'throttle:accept,30,60']);
        $router->post('/requests/{id}/refuse', [ProviderController::class, 'refuse'], ['verified_provider']);
        $router->post('/requests/{id}/counter', [ProviderController::class, 'counter'], ['verified_provider']);
        $router->get('/current', [ProviderController::class, 'current']);
        $router->post('/orders/{id}/action', [ProviderController::class, 'action']);
        $router->get('/orders/{id}/navigation', [ProviderController::class, 'navigation'], ['throttle:navigation,30,60']);
        $router->post('/orders/{id}/cash', [ProviderController::class, 'declareCash']);
        $router->post('/proof', function (App\Core\Request $r) {
            $f = App\Services\UploadService::store($r->file('file'), (int) App\Core\Auth::id(), 'delivery_proof', true);
            return App\Core\Response::json(['data' => ['file_id' => (int) $f['id']]], 201);
        }, ['throttle:upload,30,3600']);
    });

    // --- Restaurants et commerces -----------------------------------------------------
    $router->group('/merchant', ['auth', 'role:merchant'], function ($router) {
        $router->get('/store', [MerchantController::class, 'store']);
        $router->put('/store', [MerchantController::class, 'updateStore']);
        $router->post('/store/open', [MerchantController::class, 'toggleOpen']);
        $router->post('/store/logo', [MerchantController::class, 'logo'], ['throttle:upload,20,3600']);
        $router->get('/catalog', [MerchantController::class, 'catalog']);
        $router->post('/categories', [MerchantController::class, 'saveCategory']);
        $router->put('/categories/{id}', [MerchantController::class, 'saveCategory']);
        $router->delete('/categories/{id}', [MerchantController::class, 'deleteCategory']);
        $router->post('/products', [MerchantController::class, 'saveProduct']);
        $router->put('/products/{id}', [MerchantController::class, 'saveProduct']);
        $router->delete('/products/{id}', [MerchantController::class, 'deleteProduct']);
        $router->post('/products/{id}/availability', [MerchantController::class, 'availability']);
        $router->post('/products/{id}/photo', [MerchantController::class, 'productPhoto'], ['throttle:upload,60,3600']);
        $router->post('/products/{id}/photos', [MerchantController::class, 'addProductPhoto'], ['throttle:upload,120,3600']);
        $router->delete('/products/{id}/photos/{pid}', [MerchantController::class, 'deleteProductPhoto']);
        $router->post('/products/{id}/photos/{pid}/main', [MerchantController::class, 'mainProductPhoto']);
        $router->post('/store/cover', [MerchantController::class, 'cover'], ['throttle:upload,20,3600']);
        $router->post('/categories/{id}/photo', [MerchantController::class, 'categoryPhoto'], ['throttle:upload,60,3600']);
        $router->post('/products/{id}/options', [MerchantController::class, 'saveOption']);
        $router->delete('/products/{id}/options/{oid}', [MerchantController::class, 'deleteOption']);
        $router->post('/products/import', [MerchantController::class, 'import'], ['throttle:upload,10,3600']);
        $router->get('/orders', [MerchantController::class, 'orders']);
        $router->post('/orders/{id}/action', [MerchantController::class, 'orderAction']);
        $router->get('/finance', [MerchantController::class, 'finance']);
        $router->get('/report', [MerchantController::class, 'report']);
        $router->post('/payouts', [MerchantController::class, 'requestPayout']);
        $router->get('/couriers', [MerchantController::class, 'couriers']);
        $router->post('/couriers', [MerchantController::class, 'linkCourier']);
        $router->delete('/couriers/{id}', [MerchantController::class, 'unlinkCourier']);
    });

    // --- Administration (permissions fines) -------------------------------------------
    $router->group('/admin', ['auth', 'role:admin,support,finance'], function ($router) {
        $router->get('/dashboard', [AdminController::class, 'dashboard'], ['perm:admin.dashboard']);
        $router->get('/map', [AdminController::class, 'map'], ['perm:orders.view']);
        $router->get('/users', [AdminController::class, 'users'], ['perm:users.view']);
        $router->get('/users/{id}', [AdminController::class, 'showUser'], ['perm:users.view']);
        $router->post('/users/{id}/status', [AdminController::class, 'setUserStatus'], ['perm:users.manage']);
        $router->post('/staff', [AdminController::class, 'createStaff'], ['perm:settings.manage']);
        $router->get('/providers', [AdminController::class, 'providers'], ['perm:partners.validate']);
        $router->get('/providers/{id}', [AdminController::class, 'provider'], ['perm:partners.validate']);
        $router->post('/providers/{id}/verify', [AdminController::class, 'verifyProvider'], ['perm:partners.validate']);
        $router->post('/documents/{id}/review', [AdminController::class, 'reviewDocument'], ['perm:partners.validate']);
        $router->get('/merchants', [AdminController::class, 'merchants'], ['perm:partners.validate']);
        $router->post('/merchants/{id}/verify', [AdminController::class, 'verifyMerchant'], ['perm:partners.validate']);
        $router->get('/businesses', [AdminController::class, 'businesses'], ['perm:partners.validate']);
        $router->post('/businesses/{id}/verify', [AdminController::class, 'verifyBusiness'], ['perm:partners.validate']);
        $router->post('/businesses/{id}/payment', [FinanceController::class, 'businessPayment'], ['perm:payments.manage']);
        $router->get('/orders', [AdminController::class, 'orders'], ['perm:orders.view']);
        $router->get('/orders/{id}', [AdminController::class, 'order'], ['perm:orders.view']);
        $router->post('/orders/{id}/action', [AdminController::class, 'orderAction'], ['perm:orders.manage']);

        $router->get('/pricing', [ConfigController::class, 'pricing'], ['perm:pricing.manage']);
        $router->post('/pricing', [ConfigController::class, 'savePricing'], ['perm:pricing.manage']);
        $router->put('/pricing/{id}', [ConfigController::class, 'savePricing'], ['perm:pricing.manage']);
        $router->get('/commissions', [ConfigController::class, 'commissions'], ['perm:commissions.manage']);
        $router->post('/commissions', [ConfigController::class, 'saveCommission'], ['perm:commissions.manage']);
        $router->put('/commissions/{id}', [ConfigController::class, 'saveCommission'], ['perm:commissions.manage']);
        $router->post('/commissions/preview', [ConfigController::class, 'previewCommission'], ['perm:commissions.manage']);
        $router->get('/cities', [ConfigController::class, 'cities'], ['perm:zones.manage']);
        $router->post('/cities', [ConfigController::class, 'saveCity'], ['perm:zones.manage']);
        $router->put('/cities/{id}', [ConfigController::class, 'saveCity'], ['perm:zones.manage']);
        $router->get('/neighborhoods', [ConfigController::class, 'neighborhoods'], ['perm:zones.manage']);
        $router->post('/neighborhoods', [ConfigController::class, 'saveNeighborhood'], ['perm:zones.manage']);
        $router->put('/neighborhoods/{id}', [ConfigController::class, 'saveNeighborhood'], ['perm:zones.manage']);
        $router->get('/zones', [ConfigController::class, 'zones'], ['perm:zones.manage']);
        $router->post('/zones', [ConfigController::class, 'saveZone'], ['perm:zones.manage']);
        $router->put('/zones/{id}', [ConfigController::class, 'saveZone'], ['perm:zones.manage']);
        $router->delete('/zones/{id}', [ConfigController::class, 'deleteZone'], ['perm:zones.manage']);
        $router->get('/landmarks', [ConfigController::class, 'landmarks'], ['perm:zones.manage']);
        $router->post('/landmarks', [ConfigController::class, 'saveLandmark'], ['perm:zones.manage']);
        $router->put('/landmarks/{id}', [ConfigController::class, 'saveLandmark'], ['perm:zones.manage']);
        $router->get('/promotions', [ConfigController::class, 'promotions'], ['perm:promotions.manage']);
        $router->post('/promotions', [ConfigController::class, 'savePromotion'], ['perm:promotions.manage']);
        $router->put('/promotions/{id}', [ConfigController::class, 'savePromotion'], ['perm:promotions.manage']);
        $router->get('/settings', [ConfigController::class, 'settings'], ['perm:settings.manage']);
        $router->post('/settings', [ConfigController::class, 'saveSettings'], ['perm:settings.manage']);
        $router->post('/content', [ConfigController::class, 'saveContent'], ['perm:settings.manage']);
        $router->get('/system', [ConfigController::class, 'system'], ['perm:settings.manage']);
        $router->get('/audit', [ConfigController::class, 'audit'], ['perm:audit.view']);

        $router->get('/payments', [FinanceController::class, 'payments'], ['perm:finance.view']);
        $router->post('/payments/{id}/verify-cash', [FinanceController::class, 'verifyCash'], ['perm:payments.manage']);
        $router->get('/reconciliation', [FinanceController::class, 'reconciliation'], ['perm:finance.view']);
        $router->get('/refunds', [FinanceController::class, 'refunds'], ['perm:finance.view']);
        $router->post('/refunds', [FinanceController::class, 'createRefund'], ['perm:orders.manage']);
        $router->post('/refunds/{id}/review', [FinanceController::class, 'reviewRefund'], ['perm:refunds.manage']);
        $router->get('/payouts', [FinanceController::class, 'payouts'], ['perm:finance.view']);
        $router->post('/payouts/{id}/review', [FinanceController::class, 'reviewPayout'], ['perm:payouts.manage']);
        $router->post('/remittances', [FinanceController::class, 'remittance'], ['perm:payouts.manage']);
        $router->get('/balances', [FinanceController::class, 'balances'], ['perm:finance.view']);
        $router->get('/ledger', [FinanceController::class, 'ledger'], ['perm:finance.view']);
        $router->get('/ledger/transactions/{id}', [FinanceController::class, 'transaction'], ['perm:finance.view']);
        $router->get('/ledger/accounts/{id}', [FinanceController::class, 'accountStatement'], ['perm:finance.view']);
        $router->post('/ledger/adjustments', [FinanceController::class, 'adjustment'], ['perm:payments.manage']);
        $router->post('/ledger/transactions/{id}/reverse', [FinanceController::class, 'reverse'], ['perm:payments.manage']);
        $router->get('/expenses', [FinanceController::class, 'expenses'], ['perm:finance.view']);
        $router->post('/expenses', [FinanceController::class, 'createExpense'], ['perm:expenses.manage']);
        $router->post('/subscriptions', [FinanceController::class, 'subscription'], ['perm:commissions.manage']);
        $router->get('/reports/{type}', [FinanceController::class, 'report'], ['perm:finance.view']);

        $router->get('/complaints', [SupportController::class, 'complaints'], ['perm:complaints.manage']);
        $router->get('/complaints/{id}', [SupportController::class, 'complaint'], ['perm:complaints.manage']);
        $router->post('/complaints/{id}', [SupportController::class, 'updateComplaint'], ['perm:complaints.manage']);
        $router->get('/alerts', [SupportController::class, 'alerts'], ['perm:complaints.manage']);
        $router->post('/alerts/{id}', [SupportController::class, 'handleAlert'], ['perm:complaints.manage']);
        $router->get('/ratings', [SupportController::class, 'ratings'], ['perm:complaints.manage']);
        $router->post('/ratings/{id}', [SupportController::class, 'moderateRating'], ['perm:complaints.manage']);
        $router->post('/broadcast', [SupportController::class, 'broadcast'], ['perm:notifications.send']);
    });
});
