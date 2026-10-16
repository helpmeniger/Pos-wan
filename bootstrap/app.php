<?php

declare(strict_types=1);

/**
 * Amorçage commun : public/index.php, bin/console et les tests.
 * Retourne le conteneur configuré.
 */

use App\Core\Application;
use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Middleware;
use App\Services\Notifications\MailerInterface;
use App\Services\Notifications\Mail\LogMailer;
use App\Services\Notifications\Mail\PhpMailer;
use App\Services\Notifications\SmsSenderInterface;
use App\Services\Notifications\Sms\LogSmsSender;
use App\Support\Logger;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    exit("Dépendances absentes : exécutez « composer install » à la racine du projet.\n");
}
require_once $root . '/vendor/autoload.php';

Env::load($root . '/.env');
if (Env::get('APP_ENV') === 'testing' && Env::get('DB_TEST_DATABASE')) {
    Env::set('DB_DATABASE', (string) Env::get('DB_TEST_DATABASE'));
}

date_default_timezone_set('Africa/Niamey');
mb_internal_encoding('UTF-8');

$container = new Container();
Container::setInstance($container);

$config = new Config($root . '/config');
$config->set('app.root', $root);
$container->instance(Config::class, $config);

if ($config->get('app.env') === 'production' && $config->get('app.debug')) {
    // Garde-fou : jamais d'affichage d'erreurs détaillées en production
    $config->set('app.debug', false);
}
ini_set('display_errors', $config->get('app.debug') ? '1' : '0');
error_reporting(E_ALL);

$container->singleton(Logger::class, fn () => new Logger($root . '/storage/logs', (string) $config->get('app.log_level', 'info')));
$container->singleton(Database::class, fn () => new Database((array) $config->get('database')));
$container->singleton(Session::class, fn () => new Session((array) $config->get('security.session')));
$container->singleton(Csrf::class, fn (Container $c) => new Csrf($c->get(Session::class)));
$container->singleton(View::class, fn () => new View($root . '/app/Views'));
$container->singleton(MailerInterface::class, fn (Container $c) => match ($config->get('services.mail.driver')) {
    'mail' => new PhpMailer((array) $config->get('services.mail')),
    default => new LogMailer($root . '/storage/logs'),
});
$container->singleton(SmsSenderInterface::class, fn () => new LogSmsSender($root . '/storage/logs'));

$container->singleton(Router::class, function (Container $c) use ($root) {
    $router = new Router($c);
    $router->aliasMiddleware([
        'headers' => Middleware\SecurityHeaders::class,
        'session' => Middleware\StartSession::class,
        'csrf' => Middleware\VerifyCsrf::class,
        'auth' => Middleware\Authenticate::class,
        'guest' => Middleware\RedirectIfAuthenticated::class,
        'permission' => Middleware\RequirePermission::class,
        'role' => Middleware\RequireRole::class,
        'password.fresh' => Middleware\ForcePasswordChange::class,
        'throttle' => Middleware\Throttle::class,
    ]);
    $router->globalMiddleware(['headers', 'session', 'csrf', 'password.fresh']);
    (require $root . '/routes/web.php')($router);
    (require $root . '/routes/api.php')($router);
    return $router;
});

$container->singleton(Application::class, fn (Container $c) => new Application(
    $c,
    $c->get(Router::class),
    $config,
    $c->get(Logger::class),
));

return $container;
