<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Url;
use App\Services\InstallerService;
use Throwable;

final class InstallController extends Controller
{
    public function index(Request $request): Response
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        return $this->view('install/index', [
            'checks' => InstallerService::requirements(),
            'ready' => InstallerService::requirementsMet(),
            'suggestedUrl' => $scheme . '://' . ($request->header('Host') ?? 'localhost') . Url::basePath(),
        ], 'layouts/auth');
    }

    public function store(Request $request): Response
    {
        if (!InstallerService::requirementsMet()) {
            Session::flash('danger', 'Les prérequis obligatoires ne sont pas satisfaits.');
            return $this->redirect('/install');
        }
        $data = $this->validate($request, [
            'db_host' => 'required|ip_or_host',
            'db_port' => 'required|port',
            'db_name' => 'required|regex:/^[A-Za-z0-9_]+$/|max:64',
            'db_user' => 'required|max:80',
            'db_password' => 'max:255',
            'app_url' => 'required|regex:#^https?://#|max:255',
            'company_name' => 'required|max:150',
            'admin_name' => 'required|max:150',
            'admin_username' => 'required|alpha_dash|min:3|max:60',
            'admin_email' => 'required|email|max:190',
            'admin_password' => 'required|confirmed',
        ], [
            'db_host' => 'Serveur MySQL', 'db_port' => 'Port', 'db_name' => 'Nom de la base', 'db_user' => 'Utilisateur MySQL',
            'app_url' => "URL de l'application", 'company_name' => "Nom de l'entreprise", 'admin_name' => 'Nom complet',
            'admin_username' => 'Identifiant', 'admin_email' => 'E-mail', 'admin_password' => 'Mot de passe',
        ]);
        if ($err = Auth::passwordPolicyError((string) $data['admin_password'])) {
            Session::flashErrors(['admin_password' => $err]);
            Session::flashOld($request->all());
            return $this->redirect('/install');
        }
        $data['db_port'] = (int) $data['db_port'];
        $data['db_password'] = (string) $request->input('db_password', '');

        try {
            InstallerService::install($data);
        } catch (Throwable $e) {
            Logger::error('Échec installation : ' . $e->getMessage());
            Session::flash('danger', "L'installation a échoué : " . $e->getMessage());
            Session::flashOld($request->all());
            return $this->redirect('/install');
        }
        Session::flash('success', 'Installation réussie ! Connectez-vous avec le compte super administrateur.');
        return $this->redirect('/login');
    }
}
