<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    protected function view(string $view, array $data = []): void
    {
        View::render($view, $data);
    }

    protected function storeId(): int
    {
        return Auth::storeId();
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    protected function back(string $type, string $message, string $fallback = 'dashboard'): never
    {
        Session::flash($type, $message);
        Response::back($fallback);
    }

    protected function require(string $permission): void
    {
        if (!Auth::can($permission)) {
            throw new HttpException(403);
        }
    }

    /** Pagination simple : renvoie [limit, offset, page]. */
    protected function paginate(int $perPage = 25): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        return [$perPage, ($page - 1) * $perPage, $page];
    }

    protected function dateRange(string $defaultFrom = 'today'): array
    {
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        $valid = static fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        $from = $valid($from) ? $from : date('Y-m-d', strtotime($defaultFrom));
        $to = $valid($to) ? $to : date('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) > 400 * 86400) {
            $from = date('Y-m-d', strtotime($to) - 400 * 86400);
        }
        return [$from, $to];
    }
}
