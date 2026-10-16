<?php
declare(strict_types=1);

/** @var App\Core\Router $router */

use App\Controllers\AlertController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\CameraController;
use App\Controllers\DashboardController;
use App\Controllers\DetectionController;
use App\Controllers\LiveController;
use App\Controllers\MaintenanceController;
use App\Controllers\MediaController;
use App\Controllers\PosController;
use App\Controllers\ProductController;
use App\Controllers\ReportController;
use App\Controllers\SettingsController;
use App\Controllers\StockController;
use App\Controllers\TrainingController;
use App\Controllers\UserController;

$A = ['auth'];

// Authentification
$router->get('/', [DashboardController::class, 'home']);
$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout'], $A);
$router->get('/account/password', [AuthController::class, 'showPassword'], $A);
$router->post('/account/password', [AuthController::class, 'updatePassword'], $A);
$router->post('/store/switch', [AuthController::class, 'switchStore'], $A);

// Tableau de bord & notifications
$router->get('/dashboard', [DashboardController::class, 'index'], ['auth', 'perm:dashboard.view']);
$router->get('/dashboard/data', [DashboardController::class, 'data'], ['auth', 'perm:dashboard.view']);
$router->get('/notifications', [DashboardController::class, 'notifications'], $A);
$router->post('/notifications/read', [DashboardController::class, 'markRead'], $A);

// Surveillance en direct
$router->get('/live', [LiveController::class, 'index'], ['auth', 'perm:live.view']);
$router->get('/live/{id:\d+}/frame', [LiveController::class, 'frame'], ['auth', 'perm:live.view']);
$router->get('/live/{id:\d+}/state', [LiveController::class, 'state'], ['auth', 'perm:live.view']);
$router->post('/live/{id:\d+}/analysis', [LiveController::class, 'toggleAnalysis'], ['auth', 'perm:cameras.control']);
$router->post('/live/{id:\d+}/capture', [LiveController::class, 'capture'], ['auth', 'perm:live.view']);

// Caméras
$router->get('/cameras', [CameraController::class, 'index'], ['auth', 'perm:cameras.view']);
$router->get('/cameras/create', [CameraController::class, 'create'], ['auth', 'perm:cameras.manage']);
$router->post('/cameras', [CameraController::class, 'store'], ['auth', 'perm:cameras.manage']);
$router->get('/cameras/devices', [CameraController::class, 'devices'], ['auth', 'perm:cameras.manage']);
$router->get('/cameras/{id:\d+}', [CameraController::class, 'show'], ['auth', 'perm:cameras.view']);
$router->get('/cameras/{id:\d+}/edit', [CameraController::class, 'edit'], ['auth', 'perm:cameras.manage']);
$router->post('/cameras/{id:\d+}', [CameraController::class, 'update'], ['auth', 'perm:cameras.manage']);
$router->post('/cameras/{id:\d+}/test', [CameraController::class, 'test'], ['auth', 'perm:cameras.manage']);
$router->post('/cameras/{id:\d+}/start', [CameraController::class, 'start'], ['auth', 'perm:cameras.control']);
$router->post('/cameras/{id:\d+}/stop', [CameraController::class, 'stop'], ['auth', 'perm:cameras.control']);
$router->post('/cameras/{id:\d+}/lines', [CameraController::class, 'saveLine'], ['auth', 'perm:cameras.manage']);
$router->post('/cameras/{id:\d+}/lines/{line:\d+}/delete', [CameraController::class, 'deleteLine'], ['auth', 'perm:cameras.manage']);
$router->post('/cameras/{id:\d+}/delete', [CameraController::class, 'destroy'], ['auth', 'perm:cameras.manage']);

// Produits
$router->get('/products', [ProductController::class, 'index'], ['auth', 'perm:products.view']);
$router->get('/products/create', [ProductController::class, 'create'], ['auth', 'perm:products.manage']);
$router->post('/products', [ProductController::class, 'store'], ['auth', 'perm:products.manage']);
$router->get('/products/search', [ProductController::class, 'search'], ['auth', 'perm:products.view']);
$router->get('/products/import', [ProductController::class, 'importForm'], ['auth', 'perm:products.manage']);
$router->post('/products/import', [ProductController::class, 'import'], ['auth', 'perm:products.manage']);
$router->get('/products/{id:\d+}', [ProductController::class, 'show'], ['auth', 'perm:products.view']);
$router->get('/products/{id:\d+}/edit', [ProductController::class, 'edit'], ['auth', 'perm:products.manage']);
$router->post('/products/{id:\d+}', [ProductController::class, 'update'], ['auth', 'perm:products.manage']);
$router->post('/products/{id:\d+}/delete', [ProductController::class, 'destroy'], ['auth', 'perm:products.manage']);
$router->post('/products/{id:\d+}/photos', [ProductController::class, 'uploadPhotos'], ['auth', 'perm:products.manage']);
$router->post('/products/{id:\d+}/photos/{photo:\d+}', [ProductController::class, 'updatePhoto'], ['auth', 'perm:products.manage']);
$router->post('/products/{id:\d+}/photos/{photo:\d+}/delete', [ProductController::class, 'deletePhoto'], ['auth', 'perm:products.manage']);
$router->post('/products/{id:\d+}/prepare', [ProductController::class, 'prepare'], ['auth', 'perm:ai.manage']);

// Apprentissage IA
$router->get('/training', [TrainingController::class, 'index'], ['auth', 'perm:ai.manage|ai.test']);
$router->post('/training/start', [TrainingController::class, 'start'], ['auth', 'perm:ai.manage']);
$router->get('/training/jobs/{id:\d+}', [TrainingController::class, 'job'], ['auth', 'perm:ai.manage']);
$router->get('/training/versions/{id:\d+}', [TrainingController::class, 'version'], ['auth', 'perm:ai.manage']);
$router->post('/training/versions/{id:\d+}/activate', [TrainingController::class, 'activate'], ['auth', 'perm:ai.manage']);
$router->post('/training/test-image', [TrainingController::class, 'testImage'], ['auth', 'perm:ai.test']);
$router->post('/training/test-video', [TrainingController::class, 'testVideo'], ['auth', 'perm:ai.test']);
$router->get('/training/test-video/{job}', [TrainingController::class, 'testVideoStatus'], ['auth', 'perm:ai.test']);

// Détections & sorties
$router->get('/detections', [DetectionController::class, 'index'], ['auth', 'perm:detections.view']);
$router->get('/exits', [DetectionController::class, 'exits'], ['auth', 'perm:detections.view']);

// Alertes
$router->get('/alerts', [AlertController::class, 'index'], ['auth', 'perm:alerts.view']);
$router->get('/alerts/{id:\d+}', [AlertController::class, 'show'], ['auth', 'perm:alerts.view']);
$router->post('/alerts/{id:\d+}/review', [AlertController::class, 'review'], ['auth', 'perm:alerts.review']);

// Caisse
$router->get('/pos', [PosController::class, 'index'], ['auth', 'perm:pos.sell|sales.view']);
$router->post('/pos/sales', [PosController::class, 'store'], ['auth', 'perm:pos.sell']);
$router->get('/pos/sales', [PosController::class, 'sales'], ['auth', 'perm:pos.sell|sales.view']);
$router->get('/pos/sales/{id:\d+}', [PosController::class, 'show'], ['auth', 'perm:pos.sell|sales.view']);
$router->post('/pos/sales/{id:\d+}/cancel', [PosController::class, 'cancel'], ['auth', 'perm:pos.cancel']);
$router->post('/pos/sales/{id:\d+}/refund', [PosController::class, 'refund'], ['auth', 'perm:pos.refund']);
$router->get('/pos/import', [PosController::class, 'importForm'], ['auth', 'perm:sales.import']);
$router->post('/pos/import', [PosController::class, 'import'], ['auth', 'perm:sales.import']);

// Stocks
$router->get('/stock', [StockController::class, 'index'], ['auth', 'perm:stock.view']);
$router->get('/stock/movements', [StockController::class, 'movements'], ['auth', 'perm:stock.view']);
$router->post('/stock/movements', [StockController::class, 'storeMovement'], ['auth', 'perm:stock.manage']);
$router->post('/stock/inventory', [StockController::class, 'inventory'], ['auth', 'perm:stock.inventory']);
$router->get('/stock/reconciliation', [StockController::class, 'reconciliation'], ['auth', 'perm:stock.view']);

// Rapports
$router->get('/reports', [ReportController::class, 'index'], ['auth', 'perm:reports.view']);
$router->get('/reports/daily', [ReportController::class, 'dailyList'], ['auth', 'perm:reports.view']);
$router->get('/reports/daily/{id:\d+}', [ReportController::class, 'dailyShow'], ['auth', 'perm:reports.view']);
$router->post('/reports/daily/generate', [ReportController::class, 'generate'], ['auth', 'perm:reports.generate']);
$router->get('/reports/export', [ReportController::class, 'export'], ['auth', 'perm:reports.export']);

// Utilisateurs & magasins
$router->get('/users', [UserController::class, 'index'], ['auth', 'perm:users.manage']);
$router->get('/users/create', [UserController::class, 'create'], ['auth', 'perm:users.manage']);
$router->post('/users', [UserController::class, 'store'], ['auth', 'perm:users.manage']);
$router->get('/users/{id:\d+}/edit', [UserController::class, 'edit'], ['auth', 'perm:users.manage']);
$router->post('/users/{id:\d+}', [UserController::class, 'update'], ['auth', 'perm:users.manage']);
$router->post('/users/roles', [UserController::class, 'saveRoles'], ['auth', 'perm:users.manage']);
$router->post('/stores', [UserController::class, 'saveStore'], ['auth', 'perm:stores.manage']);

// Paramètres & maintenance
$router->get('/settings', [SettingsController::class, 'index'], ['auth', 'perm:settings.manage']);
$router->post('/settings', [SettingsController::class, 'update'], ['auth', 'perm:settings.manage']);
$router->get('/maintenance', [MaintenanceController::class, 'index'], ['auth', 'perm:maintenance.manage|audit.view']);
$router->post('/maintenance/backup', [MaintenanceController::class, 'backup'], ['auth', 'perm:maintenance.manage']);
$router->post('/maintenance/purge', [MaintenanceController::class, 'purge'], ['auth', 'perm:maintenance.manage']);
$router->get('/maintenance/backups/{id:\d+}', [MaintenanceController::class, 'downloadBackup'], ['auth', 'perm:maintenance.manage']);

// Médias protégés (jamais servis directement par Apache)
$router->get('/media/product-image/{id:\d+}', [MediaController::class, 'productImage'], ['auth', 'perm:products.view']);
$router->get('/media/record/{id:\d+}', [MediaController::class, 'record'], ['auth', 'perm:video.view']);
$router->get('/media/clip/{id:\d+}/{frame:\d+}', [MediaController::class, 'clipFrame'], ['auth', 'perm:video.view']);

// API
$router->get('/api/health', [ApiController::class, 'health']);
$router->post('/api/ai/events', [ApiController::class, 'aiEvents'], ['ai']);
$router->post('/api/v1/sales', [ApiController::class, 'sales'], ['sales_api']);
$router->get('/api/v1/products', [ApiController::class, 'products'], ['sales_api']);
