<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

abstract class Controller
{
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json(['data' => $data], $status);
    }

    protected function ok(string $message, array $extra = []): Response
    {
        return Response::json(['message' => $message] + $extra);
    }

    protected function validate(Request $request, array $rules): array
    {
        return Validator::make($request->all(), $rules);
    }

    protected function user(): array
    {
        return Auth::requireUser();
    }

    /** @return array{0:int,1:int} [limit, offset] */
    protected function page(Request $request, int $default = 25, int $max = 100): array
    {
        $limit = max(1, min($max, (int) $request->input('limit', $default)));
        $page = max(1, (int) $request->input('page', 1));
        return [$limit, ($page - 1) * $limit];
    }
}
