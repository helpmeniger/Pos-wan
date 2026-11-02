<?php
/** @var App\Core\Router $router */
use App\Controllers\AiController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\BackupController;
use App\Controllers\DashboardController;
use App\Controllers\HotspotController;
use App\Controllers\MonitoringController;
use App\Controllers\NotificationController;
use App\Controllers\OfferController;
use App\Controllers\PosController;
use App\Controllers\ProfileController;
use App\Controllers\PublicController;
use App\Controllers\ResellerController;
use App\Controllers\ResellerPortalController;
use App\Controllers\RoleController;
use App\Controllers\RouterController;
use App\Controllers\SaleController;
use App\Controllers\SettingsController;
use App\Controllers\TicketController;
use App\Controllers\UserController;

// Webhooks des prestataires de paiement : authentifiés par signature HMAC (pas de session ni de CSRF).
$router->group(['middleware' => ['throttle:120,1']], static function ($r) {
    $r->post('/webhooks/payments/{provider}', [PublicController::class, 'webhook'], 'webhook.payments');
});

// Page publique d'achat en ligne
$router->group(['middleware' => ['csrf']], static function ($r) {
    $r->get('/buy', [PublicController::class, 'buy'], 'buy');
    $r->post('/buy', [PublicController::class, 'order'], 'buy.order');
    $r->get('/buy/order/{reference}', [PublicController::class, 'status'], 'buy.status');
});

// Visiteurs
$router->group(['middleware' => ['csrf', 'guest']], static function ($r) {
    $r->get('/login', [AuthController::class, 'showLogin'], 'login');
    $r->post('/login', [AuthController::class, 'login'], 'login.attempt');
});

// Espace authentifié
$router->group(['middleware' => ['csrf', 'auth']], static function ($r) {
    $r->post('/logout', [AuthController::class, 'logout'], 'logout');
    $r->get('/', [DashboardController::class, 'index'], 'dashboard');

    $r->get('/profile', [ProfileController::class, 'index'], 'profile');
    $r->post('/profile', [ProfileController::class, 'update'], 'profile.update');
    $r->post('/profile/password', [ProfileController::class, 'password'], 'profile.password');

    // Routeurs MikroTik
    $r->get('/routers', [RouterController::class, 'index'], 'routers.index');
    $r->get('/routers/create', [RouterController::class, 'create'], 'routers.create');
    $r->post('/routers/test', [RouterController::class, 'test'], 'routers.test');
    $r->post('/routers', [RouterController::class, 'store'], 'routers.store');
    $r->get('/routers/{id}', [RouterController::class, 'show'], 'routers.show');
    $r->get('/routers/{id}/edit', [RouterController::class, 'edit'], 'routers.edit');
    $r->put('/routers/{id}', [RouterController::class, 'update'], 'routers.update');
    $r->delete('/routers/{id}', [RouterController::class, 'destroy'], 'routers.destroy');
    $r->post('/routers/{id}/sync', [RouterController::class, 'sync'], 'routers.sync');
    $r->post('/routers/{id}/reboot', [RouterController::class, 'reboot'], 'routers.reboot');
    $r->post('/routers/{id}/disconnect', [RouterController::class, 'disconnect'], 'routers.disconnect');

    // Offres
    $r->get('/offers', [OfferController::class, 'index'], 'offers.index');
    $r->get('/offers/create', [OfferController::class, 'create'], 'offers.create');
    $r->post('/offers', [OfferController::class, 'store'], 'offers.store');
    $r->get('/offers/{id}/edit', [OfferController::class, 'edit'], 'offers.edit');
    $r->put('/offers/{id}', [OfferController::class, 'update'], 'offers.update');
    $r->post('/offers/{id}/push', [OfferController::class, 'push'], 'offers.push');
    $r->delete('/offers/{id}', [OfferController::class, 'destroy'], 'offers.destroy');

    // Tickets
    $r->get('/tickets', [TicketController::class, 'index'], 'tickets.index');
    $r->get('/tickets/generate', [TicketController::class, 'generateForm'], 'tickets.generate');
    $r->post('/tickets/generate', [TicketController::class, 'generate'], 'tickets.generate.store');
    $r->get('/tickets/batches', [TicketController::class, 'batches'], 'tickets.batches');
    $r->get('/tickets/batches/{id}', [TicketController::class, 'batch'], 'tickets.batch');
    $r->post('/tickets/batches/{id}/push', [TicketController::class, 'pushBatch'], 'tickets.batch.push');
    $r->get('/tickets/print', [TicketController::class, 'print'], 'tickets.print');
    $r->get('/tickets/export', [TicketController::class, 'export'], 'tickets.export');
    $r->post('/tickets/bulk', [TicketController::class, 'bulk'], 'tickets.bulk');
    $r->get('/tickets/templates', [TicketController::class, 'templates'], 'tickets.templates');
    $r->post('/tickets/templates/{id}', [TicketController::class, 'saveTemplate'], 'tickets.templates.save');
    $r->get('/tickets/{id}', [TicketController::class, 'show'], 'tickets.show');
    $r->post('/tickets/{id}/action', [TicketController::class, 'action'], 'tickets.action');

    // Caisse et ventes
    $r->get('/pos', [PosController::class, 'index'], 'pos');
    $r->post('/pos/sell', [PosController::class, 'sell'], 'pos.sell');
    $r->post('/pos/open', [PosController::class, 'open'], 'pos.open');
    $r->post('/pos/close', [PosController::class, 'close'], 'pos.close');
    $r->get('/pos/sessions', [PosController::class, 'sessions'], 'pos.sessions');
    $r->get('/pos/sessions/{id}', [PosController::class, 'session'], 'pos.session');
    $r->get('/sales', [SaleController::class, 'index'], 'sales.index');
    $r->get('/sales/export', [SaleController::class, 'export'], 'sales.export');
    $r->post('/sales/online/{id}/confirm', [SaleController::class, 'confirmOnline'], 'sales.online.confirm');
    $r->get('/sales/{id}', [SaleController::class, 'show'], 'sales.show');
    $r->get('/sales/{id}/receipt', [SaleController::class, 'receipt'], 'sales.receipt');
    $r->post('/sales/{id}/cancel', [SaleController::class, 'cancel'], 'sales.cancel');
    $r->post('/sales/{id}/refund', [SaleController::class, 'refund'], 'sales.refund');

    // Revendeurs
    $r->get('/resellers', [ResellerController::class, 'index'], 'resellers.index');
    $r->get('/resellers/create', [ResellerController::class, 'create'], 'resellers.create');
    $r->post('/resellers', [ResellerController::class, 'store'], 'resellers.store');
    $r->get('/resellers/{id}', [ResellerController::class, 'show'], 'resellers.show');
    $r->get('/resellers/{id}/edit', [ResellerController::class, 'edit'], 'resellers.edit');
    $r->put('/resellers/{id}', [ResellerController::class, 'update'], 'resellers.update');
    $r->post('/resellers/{id}/topup', [ResellerController::class, 'topup'], 'resellers.topup');
    $r->post('/resellers/{id}/stock', [ResellerController::class, 'stock'], 'resellers.stock');
    $r->post('/resellers/{id}/toggle', [ResellerController::class, 'toggle'], 'resellers.toggle');
    $r->get('/reseller', [ResellerPortalController::class, 'index'], 'portal');
    $r->post('/reseller/sell', [ResellerPortalController::class, 'sell'], 'portal.sell');

    // Clients Wi-Fi et monitoring
    $r->get('/hotspot', [HotspotController::class, 'index'], 'hotspot');
    $r->post('/hotspot/disconnect', [HotspotController::class, 'disconnect'], 'hotspot.disconnect');
    $r->get('/monitoring', [MonitoringController::class, 'index'], 'monitoring');
    $r->get('/monitoring/{id}/live', [MonitoringController::class, 'live'], 'monitoring.live');
    $r->get('/monitoring/{id}/history', [MonitoringController::class, 'history'], 'monitoring.history');

    // Notifications
    $r->get('/notifications', [NotificationController::class, 'index'], 'notifications');
    $r->post('/notifications/read-all', [NotificationController::class, 'readAll'], 'notifications.readall');
    $r->post('/notifications/rules', [NotificationController::class, 'saveRules'], 'notifications.rules');
    $r->post('/notifications/channels/{channel}', [NotificationController::class, 'saveChannel'], 'notifications.channel');

    // Optimus IA
    $r->get('/ai', [AiController::class, 'index'], 'ai');
    $r->post('/ai/ask', [AiController::class, 'ask'], 'ai.ask');
    $r->post('/ai/actions/{id}', [AiController::class, 'confirm'], 'ai.confirm');
    $r->post('/ai/reset', [AiController::class, 'reset'], 'ai.reset');

    // Paramètres et sauvegardes
    $r->get('/settings', [SettingsController::class, 'index'], 'settings');
    $r->post('/settings/payments/{id}', [SettingsController::class, 'updatePayment'], 'settings.payment');
    $r->post('/settings/{tab}', [SettingsController::class, 'update'], 'settings.update');
    $r->get('/admin/backups', [BackupController::class, 'index'], 'backups');
    $r->post('/admin/backups', [BackupController::class, 'create'], 'backups.create');
    $r->get('/admin/backups/{id}/download', [BackupController::class, 'download'], 'backups.download');
    $r->delete('/admin/backups/{id}', [BackupController::class, 'destroy'], 'backups.destroy');

    // Administration : utilisateurs
    $r->group(['prefix' => '/admin/users'], static function ($r) {
        $r->get('/', [UserController::class, 'index'], 'users.index');
        $r->get('/create', [UserController::class, 'create'], 'users.create');
        $r->post('/', [UserController::class, 'store'], 'users.store');
        $r->get('/{id}/edit', [UserController::class, 'edit'], 'users.edit');
        $r->put('/{id}', [UserController::class, 'update'], 'users.update');
        $r->post('/{id}/status', [UserController::class, 'toggleStatus'], 'users.status');
        $r->post('/{id}/reset-password', [UserController::class, 'resetPassword'], 'users.reset');
        $r->delete('/{id}', [UserController::class, 'destroy'], 'users.destroy');
    });

    // Administration : rôles & permissions
    $r->group(['prefix' => '/admin/roles', 'middleware' => ['can:roles.manage']], static function ($r) {
        $r->get('/', [RoleController::class, 'index'], 'roles.index');
        $r->post('/', [RoleController::class, 'store'], 'roles.store');
        $r->get('/{id}', [RoleController::class, 'edit'], 'roles.edit');
        $r->put('/{id}', [RoleController::class, 'update'], 'roles.update');
        $r->delete('/{id}', [RoleController::class, 'destroy'], 'roles.destroy');
    });

    $r->get('/admin/audit', [AuditController::class, 'index'], 'audit.index');
});
