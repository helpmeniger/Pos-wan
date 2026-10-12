<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function verify(Request $request): bool
    {
        $sent = $request->header('x-csrf-token') ?? (is_string($request->body['_token'] ?? null) ? $request->body['_token'] : null);
        $expected = Session::get('_csrf');
        return is_string($sent) && is_string($expected) && $expected !== '' && hash_equals($expected, $sent);
    }
}
