<?php
declare(strict_types=1);

namespace App\Controllers\Saas;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\Request;
use App\Core\Session;
use App\Saas\Central;
use App\Saas\Provisioner;
use App\Saas\Saas;
use App\Services\Catalog;

/** Site public du service : offres, inscription avec essai gratuit, retrouver son espace. */
final class PublicController extends Controller
{
    private function page(string $view, array $data): never
    {
        view('saas/' . $view, $data + ['operator' => self::operator()], 'saas/layout');
    }

    public static function operator(): array
    {
        $o = [];
        foreach (['operator_name', 'operator_phone', 'operator_email', 'operator_address', 'payment_instructions'] as $k) {
            $o[$k] = (string) Central::setting($k, '');
        }
        $o['operator_name'] = $o['operator_name'] ?: 'POS Marwa en ligne';
        return $o;
    }

    private function plans(): array
    {
        return Central::all('SELECT * FROM saas_plans WHERE active = 1 AND public = 1 ORDER BY sort, price_month');
    }

    public function home(): void
    {
        $this->page('home', ['title' => 'Logiciel de caisse et de gestion en ligne', 'plans' => $this->plans(),
            'trialDays' => (int) (Central::setting('trial_days') ?: config('saas.trial_days')), 'addonNames' => self::addonNames()]);
    }

    public static function addonNames(): array
    {
        $names = [];
        foreach (\App\Core\Addons::discover() as $code => $m) {
            if (!in_array($code, (array) config('saas.excluded_addons'), true) && empty($m['errors'])) {
                $names[$code] = $m['name'];
            }
        }
        return $names;
    }

    public function signupForm(): void
    {
        $plans = $this->plans();
        $code = Request::str('offre');
        $sel = 0;
        foreach ($plans as $p) {
            if ($p['code'] === $code) {
                $sel = (int) $p['id'];
            }
        }
        $this->page('signup', ['title' => 'Créer mon espace', 'plans' => $plans, 'selected' => $sel ?: (int) ($plans[0]['id'] ?? 0),
            'types' => Catalog::BUSINESS_TYPES, 'old' => Session::get('_old', []), 'domain' => config('saas.domain'),
            'trialDays' => (int) (Central::setting('trial_days') ?: config('saas.trial_days')), 'flash' => Session::pullFlash()]);
    }

    public function signup(): void
    {
        // Champ piège invisible : rempli uniquement par les robots
        if (Request::str('website') !== '') {
            redirect('/');
        }
        if (!Central::throttle('signup', 5, 60)) {
            $this->fail('Trop d\'inscriptions depuis cette connexion. Réessayez dans une heure.', '/inscription');
        }
        try {
            $t = Provisioner::signup($_POST);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/inscription' . (Request::str('plan_code') ? '?offre=' . rawurlencode(Request::str('plan_code')) : ''));
        } catch (\Throwable $e) {
            \App\Core\Logger::error('SaaS : inscription impossible : ' . $e->getMessage());
            $this->fail('L\'inscription n\'a pas pu aboutir pour une raison technique (signalée à l\'équipe). Réessayez plus tard.', '/inscription');
        }
        Session::forget('_old');
        if ($t['state'] === 'provisioning') {
            // Page intermédiaire (et non redirection) : la politique de sécurité « form-action 'self' »
            // interdit à un formulaire de rediriger vers une autre adresse (le sous-domaine du client)
            $this->page('created', ['title' => 'Votre espace est créé', 'tenant' => $t, 'url' => Saas::tenantUrl($t['slug'], '/'), 'refreshTo' => Saas::tenantUrl($t['slug'], '/')]);
        }
        $this->page('waiting', ['title' => 'Inscription enregistrée', 'tenant' => $t]);
    }

    public function find(): void
    {
        $this->page('find', ['title' => 'Retrouver mon espace', 'results' => null, 'q' => '', 'flash' => Session::pullFlash()]);
    }

    public function findPost(): void
    {
        $q = mb_strtolower(trim(Request::str('q')));
        if (!Central::throttle('find', 20, 15)) {
            $this->fail('Trop de recherches. Réessayez dans quelques minutes.', '/trouver');
        }
        $results = [];
        if (mb_strlen($q) >= 3) {
            // Recherche exacte (adresse ou nom du commerce) : pas de liste des clients du service
            $slug = preg_replace('/\.' . preg_quote((string) config('saas.domain'), '/') . '$/', '', preg_replace('#^https?://#', '', $q));
            foreach (Central::all("SELECT slug, company FROM saas_tenants WHERE state <> 'waiting_db' AND (slug = ? OR LOWER(company) = ?) LIMIT 5", [$slug, $q]) as $r) {
                $results[] = ['company' => $r['company'], 'url' => Saas::tenantUrl($r['slug'], '/login'), 'host' => $r['slug'] . '.' . config('saas.domain')];
            }
        }
        $this->page('find', ['title' => 'Retrouver mon espace', 'results' => $results, 'q' => Request::str('q'), 'flash' => []]);
    }

    public function terms(): void
    {
        $this->page('terms', ['title' => 'Conditions du service']);
    }
}
