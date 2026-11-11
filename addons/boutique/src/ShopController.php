<?php
declare(strict_types=1);

namespace Addons\Boutique;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\HttpException;
use App\Core\Request;

/** Pages publiques de la boutique en ligne (aucune connexion requise). */
final class ShopController extends Controller
{
    private function shared(): array
    {
        return ['shopTitle' => ShopService::title(), 'tagline' => ShopService::setting('tagline'), 'color' => self::color(),
            'logo' => upload_url(setting('company_logo')), 'phone' => (string) (setting('company_phone') ?? ''), 'whatsapp' => preg_replace('/[^\d]/', '', ShopService::setting('whatsapp')),
            'address' => (string) (setting('company_address') ?? ''), 'open' => ShopService::isOpen(), 'account' => ShopAccount::current()];
    }

    private static function color(): string
    {
        $c = ShopService::setting('color', '#0d6efd');
        return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : '#0d6efd';
    }

    private function guard(): void
    {
        if (!ShopService::enabled()) {
            http_response_code(503);
            view('boutique::closed', $this->shared(), null);
        }
    }

    public function index(): void
    {
        $this->guard();
        header('Cache-Control: no-store');
        $catalog = ShopService::catalog();
        view('boutique::shop', $this->shared() + ['catalog' => $catalog, 'categories' => ShopService::categories($catalog), 'config' => $this->config()], null);
    }

    public function checkout(): void
    {
        $this->guard();
        header('Cache-Control: no-store');
        view('boutique::checkout', $this->shared() + ['config' => $this->config()], null);
    }

    private function config(): array
    {
        $c = currency();
        return ['base' => base_path_prefix(), 'csrf' => csrf_token(), 'currency' => $c, 'stock' => ShopService::setting('stock_display', 'badge'),
            'delivery' => ShopService::setting('delivery', '1') === '1', 'fee' => (float) ShopService::setting('delivery_fee', '0'),
            'min' => (float) ShopService::setting('min_order', '0'), 'open' => ShopService::isOpen(),
            'account' => ($a = ShopAccount::current()) ? ['name' => $a['name'], 'phone' => $a['phone'], 'email' => (string) $a['email'], 'address' => (string) $a['address']] : null];
    }

    /** Disponibilités et prix à jour des articles du panier. */
    public function stock(): void
    {
        header('Cache-Control: no-store');
        $ids = array_map('intval', explode(',', (string) Request::query('ids', '')));
        json_response(['ok' => true, 'open' => ShopService::isOpen(), 'items' => ShopService::stockFor($ids)]);
    }

    public function order(): void
    {
        try {
            $r = ShopService::placeOrder(Request::json());
        } catch (BusinessException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        json_response(['ok' => true, 'number' => $r['number'], 'total' => $r['total'], 'url' => url('/boutique/commande/' . $r['id'], ['t' => $r['token']])]);
    }

    // ------------------------------------------------------------------ compte client

    private function msg(string $type, string $text): void
    {
        \App\Core\Session::set('shop_msg', [$type, $text]);
    }

    private function accountAction(callable $fn, string $ok): never
    {
        try {
            $fn();
            $this->msg('success', $ok);
        } catch (BusinessException $e) {
            $this->msg('danger', $e->getMessage());
        }
        redirect('/boutique/compte');
    }

    public function account(): void
    {
        $this->guard();
        header('Cache-Control: no-store');
        $a = ShopAccount::current();
        $msg = \App\Core\Session::get('shop_msg');
        \App\Core\Session::forget('shop_msg');
        view('boutique::account', $this->shared() + ['msg' => $msg, 'orders' => $a ? ShopAccount::orders((int) $a['id']) : [], 'stats' => $a ? ShopAccount::stats((int) $a['id']) : null,
            'statuses' => ShopService::STATUSES], null);
    }

    public function accountRegister(): void
    {
        $this->accountAction(fn () => ShopAccount::register($_POST), 'Votre compte est créé. Vos prochaines commandes apparaîtront ici.');
    }

    public function accountLogin(): void
    {
        $this->accountAction(fn () => ShopAccount::login(Request::str('phone'), (string) Request::input('password', '')), 'Bienvenue !');
    }

    public function accountLogout(): void
    {
        ShopAccount::logout();
        redirect('/boutique');
    }

    public function accountUpdate(): void
    {
        $a = ShopAccount::current();
        if (!$a) {
            redirect('/boutique/compte');
        }
        $this->accountAction(fn () => ShopAccount::update((int) $a['id'], $_POST), 'Vos informations sont enregistrées.');
    }

    public function accountDelete(): void
    {
        $a = ShopAccount::current();
        if (!$a) {
            redirect('/boutique/compte');
        }
        $this->accountAction(fn () => ShopAccount::delete((int) $a['id'], (string) Request::input('password', '')), 'Votre compte a été supprimé.');
    }

    /** Reçu de commande et suivi (lien secret envoyé au client). */
    public function receipt(int $id): void
    {
        $o = ShopService::forCustomer($id, (string) Request::query('t', ''));
        if (!$o) {
            throw new HttpException(404);
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $self = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('/boutique/commande/' . $id, ['t' => $o['token']]);
        view('boutique::receipt', $this->shared() + ['o' => $o, 'self' => $self, 'statuses' => ShopService::STATUSES,
            'scripts' => ['vendor/qrcode/qrcode.js', 'js/qr.js']], null);
    }
}
