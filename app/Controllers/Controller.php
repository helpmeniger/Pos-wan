<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;

abstract class Controller
{
    /** UUID v4 : identifiant d'idempotence d'un formulaire d'encaissement. */
    protected function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException(401);
    }

    /** Conserve la saisie pour réafficher un formulaire après erreur. */
    protected function withOld(): void
    {
        $old = $_POST;
        unset($old['_csrf'], $old['password'], $old['password_confirm'], $old['pin'], $old['current_password']);
        Session::set('_old', $old);
    }

    protected function fail(string $message, string $redirect): never
    {
        $this->withOld();
        flash('danger', $message);
        redirect($redirect);
    }

    protected function findOr404(string $table, int $id): array
    {
        $row = DB::one("SELECT * FROM `$table` WHERE id = ?", [$id]);
        if (!$row) {
            throw new HttpException(404);
        }
        return $row;
    }

    /** Exécute une action métier et convertit les erreurs métier en message utilisateur. */
    protected function attempt(callable $fn, string $onErrorRedirect): mixed
    {
        try {
            return $fn();
        } catch (BusinessException $e) {
            $this->fail(preg_replace('/^(AUTH_REQUIRED|PRESCRIPTION_REQUIRED):/', '', $e->getMessage()), $onErrorRedirect);
        }
    }

    protected function jsonAttempt(callable $fn): never
    {
        try {
            json_response(['ok' => true] + (array) $fn());
        } catch (BusinessException $e) {
            $msg = $e->getMessage();
            $code = null;
            if (preg_match('/^(AUTH_REQUIRED|PRESCRIPTION_REQUIRED):(.*)$/s', $msg, $m)) {
                $code = $m[1];
                $msg = $m[2];
            }
            json_response(['ok' => false, 'error' => $msg, 'code' => $code], 422);
        }
    }

    /** Lignes [{product_id, quantity, ...}] envoyées depuis un formulaire tabulaire. */
    protected function rowsFromForm(string $prefix = 'items'): array
    {
        $raw = Request::input($prefix, []);
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $r) {
            if (is_array($r)) {
                $rows[] = array_map(fn($v) => is_string($v) ? trim(str_replace(',', '.', $v)) : $v, $r);
            }
        }
        return $rows;
    }

    protected function warehouses(): array
    {
        return DB::all('SELECT w.*, s.name AS store_name FROM warehouses w LEFT JOIN stores s ON s.id = w.store_id WHERE w.active = 1 ORDER BY w.name');
    }

    protected function defaultWarehouseId(): int
    {
        $u = $this->user();
        if ($u['register_id']) {
            $w = DB::value('SELECT warehouse_id FROM cash_registers WHERE id = ?', [$u['register_id']]);
            if ($w) {
                return (int) $w;
            }
        }
        return (int) DB::value('SELECT id FROM warehouses WHERE active = 1 ORDER BY id LIMIT 1');
    }
}
