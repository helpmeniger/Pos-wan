<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\Install\Installer;
use App\Services\Install\InstallState;
use App\Services\Install\Requirements;

/**
 * Assistant de première installation. Disponible uniquement tant que l'application
 * n'est pas installée, et seulement depuis le poste local ou le réseau local.
 */
final class InstallController extends Controller
{
    public function __construct(View $view, Session $session, private readonly Config $config)
    {
        parent::__construct($view, $session);
    }

    public function show(Request $request): Response
    {
        $this->guard($request);
        $requirements = (new Requirements($this->root()))->check();
        return $this->render('install/index', [
            'title' => 'Installation',
            'requirements' => $requirements,
            'ready' => Requirements::allRequiredOk($requirements),
            'appUrl' => $this->origin($request),
            'basePath' => $request->basePath(),
        ]);
    }

    public function install(Request $request): Response
    {
        $this->guard($request);
        if (!Requirements::allRequiredOk((new Requirements($this->root()))->check())) {
            return $this->back('/installation', 'Certains prérequis ne sont pas remplis : corrigez-les puis réessayez.');
        }
        $data = Validator::validate($request->all(), [
            'db_host' => 'required|string|max:120',
            'db_port' => 'required|integer|between:1,65535',
            'db_name' => 'required|string|max:64',
            'db_user' => 'required|string|max:80',
            'db_password' => 'nullable|string|max:200',
            'admin_first_name' => 'required|string|min:2|max:80',
            'admin_last_name' => 'required|string|min:2|max:80',
            'admin_phone' => 'required|phone',
            'admin_email' => 'nullable|email',
            'admin_password' => 'required|password|confirmed',
        ], [
            'db_host' => 'serveur', 'db_port' => 'port', 'db_name' => 'nom de la base', 'db_user' => 'utilisateur MySQL',
            'admin_first_name' => 'prénom', 'admin_last_name' => 'nom', 'admin_phone' => 'téléphone',
            'admin_email' => 'e-mail', 'admin_password' => 'mot de passe',
        ]);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $data['db_name'])) {
            throw new \App\Core\ValidationException(['db_name' => ['Lettres, chiffres et « _ » uniquement (ex : job_niger).']], $request->all());
        }
        $data['db_password'] = (string) $request->input('db_password', '');
        $data['admin_password'] = (string) $request->input('admin_password');

        $result = (new Installer($this->root(), $this->config))->install($data, $this->origin($request), $request->basePath());
        if (!$result['ok']) {
            $this->session->flashInput($request->all());
            return $this->back('/installation', (string) $result['error']);
        }
        if (!$result['env_written']) {
            // Base installée mais .env non inscriptible : on affiche son contenu à copier manuellement
            return $this->render('install/manual-env', ['title' => 'Installation presque terminée', 'env' => $result['env']]);
        }
        return $this->redirect('/connexion', 'Installation terminée ! Connectez-vous avec le compte administrateur que vous venez de créer.');
    }

    private function guard(Request $request): void
    {
        if (InstallState::isInstalled($this->root())) {
            throw new HttpException(404);
        }
        $ip = $request->ip();
        $isLocal = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if (!$isLocal && !\App\Core\Env::get('INSTALLER_ALLOW_REMOTE', false)) {
            throw new HttpException(403, 'L\'installation ne peut être lancée que depuis l\'ordinateur serveur ou le réseau local.');
        }
    }

    private function origin(Request $request): string
    {
        $host = (string) $request->header('Host', 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            $host = 'localhost';
        }
        return ($request->isSecure() ? 'https' : 'http') . '://' . $host;
    }

    private function root(): string
    {
        return (string) $this->config->get('app.root');
    }
}
