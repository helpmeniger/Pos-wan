<?php
/** @var App\Core\Router $router */
use App\Controllers\InstallController;

$router->group(['prefix' => '/install', 'middleware' => ['not_installed', 'csrf']], static function ($r) {
    $r->get('/', [InstallController::class, 'index'], 'install');
    $r->post('/', [InstallController::class, 'store'], 'install.store');
});
