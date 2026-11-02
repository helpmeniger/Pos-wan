<?php
/** @var App\Core\Router $router */
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ProfileController;
use App\Controllers\RoleController;
use App\Controllers\UserController;

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

    // Journal d'audit
    $r->get('/admin/audit', [AuditController::class, 'index'], 'audit.index');
});
