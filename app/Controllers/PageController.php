<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Controllers\Api\AuthController;
use App\Services\AuthService;
use App\Services\PaymentService;
use App\Services\UploadService;

final class PageController extends Controller
{
    private function renderPage(string $view, string $title, array $data = []): Response
    {
        $user = Auth::user();
        return Response::html(View::render($view, $data + [
            'title' => $title,
            'user' => $user ? AuthService::present($user) : null,
            'csrf' => Csrf::token(),
        ]));
    }

    public function home(Request $r): Response
    {
        return $this->renderPage('pages/home', 'MARWA — Taxi, livraison et repas au Niger', ['bodyClass' => 'page-home', 'scripts' => ['map.js', 'home.js']]);
    }

    public function login(Request $r): Response
    {
        if (Auth::check()) {
            return Response::redirect(AuthController::home(Auth::user()));
        }
        $back = (string) $r->input('retour', '');
        return $this->renderPage('pages/login', 'Connexion', ['back' => preg_match('#^/[a-z/-]*$#', $back) ? $back : '', 'scripts' => ['auth.js']]);
    }

    public function register(Request $r): Response
    {
        $role = (string) $r->input('profil', 'client');
        return $this->renderPage('pages/register', 'Créer un compte', ['role' => in_array($role, AuthService::SELF_SERVICE_ROLES, true) ? $role : 'client', 'scripts' => ['map.js', 'auth.js']]);
    }

    public function forgot(Request $r): Response
    {
        return $this->renderPage('pages/forgot', 'Mot de passe oublié', ['scripts' => ['auth.js']]);
    }

    public function reset(Request $r): Response
    {
        return $this->renderPage('pages/reset', 'Nouveau mot de passe', ['token' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r->input('jeton', '')), 'scripts' => ['auth.js']]);
    }

    public function offline(Request $r): Response
    {
        return $this->renderPage('pages/offline', 'Hors ligne');
    }

    public function content(Request $r): Response
    {
        $p = Database::first('SELECT * FROM content_pages WHERE slug = :s', ['s' => (string) $r->param('slug')]);
        if (!$p) {
            throw HttpException::notFound('Page introuvable.');
        }
        return $this->renderPage('pages/content', $p['title'], ['pageContent' => $p]);
    }

    public function client(Request $r): Response
    {
        return $this->renderPage('pages/app', 'Mon espace', ['space' => 'client', 'bodyClass' => 'space-client', 'scripts' => ['map.js', 'shared.js', 'client.js']]);
    }

    public function business(Request $r): Response
    {
        return $this->renderPage('pages/app', 'Espace entreprise', ['space' => 'business', 'bodyClass' => 'space-business', 'scripts' => ['shared.js', 'business.js']]);
    }

    public function provider(Request $r): Response
    {
        $type = Auth::role() === 'driver' ? 'chauffeur' : 'livreur';
        return $this->renderPage('pages/app', 'Espace ' . $type, ['space' => 'provider', 'bodyClass' => 'space-provider', 'scripts' => ['map.js', 'navigation.js', 'shared.js', 'provider.js']]);
    }

    public function merchant(Request $r): Response
    {
        return $this->renderPage('pages/app', 'Espace commerce', ['space' => 'merchant', 'bodyClass' => 'space-merchant', 'scripts' => ['map.js', 'shared.js', 'merchant.js']]);
    }

    public function admin(Request $r): Response
    {
        return $this->renderPage('pages/app', 'Administration', ['space' => 'admin', 'bodyClass' => 'space-admin', 'scripts' => ['map.js', 'shared.js', 'charts.js', 'admin.js']]);
    }

    public function demoPayment(Request $r): Response
    {
        if (!PaymentService::demoEnabled()) {
            throw HttpException::notFound();
        }
        $p = Database::first("SELECT pm.*, o.reference, o.id order_id FROM payments pm JOIN orders o ON o.id = pm.order_id WHERE pm.uuid = :u AND pm.gateway = 'demo'", ['u' => (string) $r->param('uuid')]);
        if (!$p || (int) $p['payer_user_id'] !== (int) Auth::id()) {
            throw HttpException::notFound('Paiement introuvable.');
        }
        return $this->renderPage('pages/demo-payment', 'Paiement de démonstration', ['payment' => $p, 'scripts' => ['demo-payment.js']]);
    }

    /** Fichiers téléversés : servis par PHP après contrôle d'accès (jamais exposés directement). */
    public function file(Request $r): Response
    {
        $f = Database::first('SELECT * FROM files WHERE id = :id', ['id' => $r->intParam('id')]);
        if (!$f || !UploadService::canView($f, Auth::user())) {
            throw HttpException::notFound('Fichier introuvable.');
        }
        return Response::file(UploadService::path($f), $f['mime']);
    }
}
