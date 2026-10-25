<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Logger;
use Throwable;

/**
 * Noyau HTTP : distribue la requête au routeur et transforme toute exception
 * en réponse propre (page d'erreur HTML ou JSON), sans fuite d'information en production.
 */
final class Application
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function handle(Request $request): Response
    {
        $response = $this->dispatch($request);
        // En-têtes de sécurité sur TOUTES les réponses, y compris les pages d'erreur
        return $this->container->get(\App\Middleware\SecurityHeaders::class)->apply($request, $response);
    }

    private function dispatch(Request $request): Response
    {
        $this->container->instance(Request::class, $request);
        // Sous-dossier détecté à partir de l'URL (/job-niger, /job-niger/public…) : tous les liens générés l'utilisent
        $this->config->set('app.base_path', $request->basePath());
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $e) {
            return $this->renderValidation($request, $e);
        } catch (HttpException $e) {
            return $this->renderHttpError($request, $e->status(), $e->getMessage(), $e->headers());
        } catch (\PDOException $e) {
            $this->logger->error('Erreur base de données', ['exception' => $e, 'path' => $request->path()]);
            return $this->renderHttpError($request, 503, $this->databaseHint($e));
        } catch (Throwable $e) {
            $this->logger->error('Exception non gérée', [
                'exception' => $e,
                'path' => $request->path(),
                'method' => $request->method(),
            ]);
            $message = $this->config->get('app.debug') ? $e::class . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : HttpException::defaultMessage(500);
            return $this->renderHttpError($request, 500, $message);
        }
    }

    private function renderValidation(Request $request, ValidationException $e): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['message' => 'Les données envoyées sont invalides.', 'errors' => $e->errors()], 422);
        }
        $session = $this->container->get(Session::class);
        $session->flash('_errors', $e->errors());
        $session->flashInput($e->old() ?: $request->all());
        $back = $this->safeBack($request);
        return Response::redirect($back, 303);
    }

    /** @param array<string, string> $headers */
    private function renderHttpError(Request $request, int $status, string $message, array $headers = []): Response
    {
        if ($request->wantsJson()) {
            $response = Response::json(['message' => $message], $status);
        } else {
            try {
                $view = $this->container->get(View::class);
                $html = $view->render('errors/error', ['status' => $status, 'message' => $message, 'title' => 'Erreur ' . $status]);
            } catch (Throwable $e) {
                $this->logger->error('Échec du rendu de la page d\'erreur', ['exception' => $e]);
                $html = '<!doctype html><meta charset="utf-8"><title>Erreur</title><h1>Erreur ' . $status . '</h1><p>' . e($message) . '</p>';
            }
            $response = Response::html($html, $status);
        }
        foreach ($headers as $k => $v) {
            $response->withHeader($k, $v);
        }
        return $response;
    }

    /** Message compréhensible (sans détail technique en production) pour une base inaccessible. */
    private function databaseHint(\PDOException $e): string
    {
        $base = 'La base de données est inaccessible.';
        if ($this->config->get('app.env') === 'production' && !$this->config->get('app.debug')) {
            return $base . ' Réessayez dans quelques instants.';
        }
        $m = $e->getMessage();
        return match (true) {
            str_contains($m, '[1049]') => $base . ' La base indiquée dans .env n\'existe pas : exécutez « php bin/console migrate », '
                . 'ou supprimez le fichier .env puis rechargez la page pour relancer l\'assistant d\'installation.',
            str_contains($m, '[1146]'), str_contains($m, '42S02') => $base . ' Les tables ne sont pas créées : exécutez « php bin/console migrate », '
                . 'ou supprimez le fichier .env puis rechargez la page pour relancer l\'assistant d\'installation.',
            str_contains($m, '[1045]') => $base . ' Identifiants MySQL refusés : vérifiez DB_USERNAME et DB_PASSWORD dans .env.',
            str_contains($m, '[2002]') => $base . ' Le serveur MySQL ne répond pas : vérifiez que WampServer est démarré (icône verte) et le port DB_PORT.',
            default => $base . ($this->config->get('app.debug') ? ' (' . $m . ')' : ' Consultez storage/logs pour le détail.'),
        };
    }

    /** Retour au formulaire d'origine (même site uniquement). */
    private function safeBack(Request $request): string
    {
        $referer = (string) $request->header('Referer', '');
        $host = (string) $request->header('Host', '');
        $parts = parse_url($referer);
        if ($referer !== '' && isset($parts['path']) && (($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '')) === $host) {
            $base = rtrim((string) $this->config->get('app.base_path', ''), '/');
            $path = $parts['path'];
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base)) ?: '/';
            }
            return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }
        return $request->path();
    }
}
