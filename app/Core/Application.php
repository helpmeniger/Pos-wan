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
        $this->container->instance(Request::class, $request);
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $e) {
            return $this->renderValidation($request, $e);
        } catch (HttpException $e) {
            return $this->renderHttpError($request, $e->status(), $e->getMessage(), $e->headers());
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
