<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

/**
 * Contrôleur de base.
 */
abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'layouts/app'): Response
    {
        return Response::html(View::render($view, $data, $layout));
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        $ref = (string) $request->header('Referer');
        $host = (string) $request->header('Host');
        // Redirection uniquement vers le même hôte (anti open-redirect).
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) . (parse_url($ref, PHP_URL_PORT) ? ':' . parse_url($ref, PHP_URL_PORT) : '') === $host) {
            $path = (string) parse_url($ref, PHP_URL_PATH);
            $query = parse_url($ref, PHP_URL_QUERY);
            return Response::redirect(($path ?: '/') . ($query ? '?' . $query : ''));
        }
        return Response::redirect($fallback);
    }

    /**
     * Valide la requête ; en cas d'échec, redirige (ou renvoie JSON 422) avec les erreurs.
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        $v = Validator::make($request->all(), $rules, $labels);
        if ($v->fails()) {
            if ($request->isAjax()) {
                throw new ValidationFailed($v->errors(), Response::json(['success' => false, 'message' => 'Données invalides.', 'errors' => $v->errors()], 422));
            }
            Session::flashErrors($v->errors());
            Session::flashOld($request->all());
            throw new ValidationFailed($v->errors(), $this->back($request));
        }
        return $v->validated();
    }

    protected function authorize(string ...$permissions): void
    {
        Auth::authorize(...$permissions);
    }

    protected function notFoundUnless(mixed $value, string $message = 'Ressource introuvable.'): void
    {
        if (!$value) {
            throw new HttpException(404, $message);
        }
    }
}
