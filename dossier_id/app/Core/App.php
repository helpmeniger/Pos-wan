<?php
declare(strict_types=1);

namespace App\Core;

use App\Controllers\RedirectSignal;
use App\Services\ValidationException;

/** Point d'entrée HTTP : en-têtes de sécurité, session, routage, gestion des erreurs. */
final class App
{
    public static function securityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cache-Control: no-store, private');
        header_remove('X-Powered-By');
    }

    public static function run(): void
    {
        self::securityHeaders();
        $routes = require BASE_PATH . '/app/routes.php';
        $name = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            Session::start();
            if (!isset($routes[$name])) {
                throw new HttpException(404, 'Page introuvable.');
            }
            [$class, $action, $verb, $public] = $routes[$name];
            if ($method !== $verb) {
                throw new HttpException(405, 'Méthode non autorisée.');
            }
            $logged = Auth::check();
            if (!$public && !$logged) {
                throw new RedirectSignal(url('auth.login'));
            }
            if ($verb === 'POST' && !Csrf::validate($_POST['_csrf'] ?? null)) {
                if ($logged) {
                    Audit::log('csrf.invalid', null, null, ['route' => $name]);
                }
                throw new HttpException(419, 'Le formulaire a expiré ou est invalide. Rechargez la page et recommencez.');
            }
            if ($logged && (int) (Auth::user()['must_change_password'] ?? 0)
                && !in_array($name, ['auth.password', 'auth.password.save', 'auth.logout'], true)) {
                throw new RedirectSignal(url('auth.password'));
            }
            $result = (new $class())->$action();
            self::send($result);
        } catch (RedirectSignal $r) {
            header('Location: ' . $r->location, true, 303);
        } catch (HttpException $e) {
            self::error($e->status, $e->getMessage());
        } catch (ValidationException $e) {
            self::error(422, $e->getMessage());
        } catch (\Throwable $e) {
            self::logError($e);
            self::error(500, 'Une erreur interne est survenue. L\'incident a été enregistré.');
        }
    }

    private static function send(mixed $result): void
    {
        if ($result instanceof Response) {
            http_response_code($result->status);
            foreach ($result->headers as $k => $v) {
                header("$k: $v");
            }
            echo $result->body;
            return;
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo (string) $result;
    }

    public static function error(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        $titles = [403 => 'Accès refusé', 404 => 'Page introuvable', 405 => 'Méthode non autorisée',
                   419 => 'Session ou formulaire expiré', 422 => 'Données invalides', 500 => 'Erreur interne'];
        try {
            $layout = Auth::user() ? 'layout/main' : 'layout/guest';
            echo View::render('errors/error', [
                'title' => $titles[$status] ?? 'Erreur', 'status' => $status, 'message' => $message,
            ], $layout);
        } catch (\Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>Erreur</title><p>' . e($message) . '</p>';
        }
    }

    /** Journal technique : message et emplacement uniquement, jamais de données de requête. */
    public static function logError(\Throwable $e): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf("[%s] %s: %s in %s:%d\n", date('c'), $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
        @file_put_contents($dir . '/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
        if (Config::isDemo() && PHP_SAPI === 'cli-server') {
            error_log($line . $e->getTraceAsString());
        }
    }
}
