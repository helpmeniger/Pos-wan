<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = []): string
    {
        return View::render($template, $data);
    }

    /** Redirection interne uniquement (pas de redirection ouverte). */
    protected function redirect(string $route, array $params = []): never
    {
        throw new RedirectSignal(url($route, $params));
    }

    protected function back(string $route, array $params, string $type, string $message): never
    {
        Session::flash($type, $message);
        $this->redirect($route, $params);
    }

    protected function input(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function id(string $key = 'id'): int
    {
        $v = $_GET[$key] ?? $_POST[$key] ?? '';
        if (!is_string($v) || !ctype_digit($v) || (int) $v < 1) {
            throw new HttpException(404, 'Identifiant invalide.');
        }
        return (int) $v;
    }

    /** Filtres GET limités aux clés attendues, en chaînes. */
    protected function filters(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $_GET[$k] ?? '';
            $out[$k] = is_string($v) ? mb_substr(trim($v), 0, 100) : '';
        }
        return $out;
    }

    protected function userId(): ?int
    {
        return Auth::id();
    }
}
