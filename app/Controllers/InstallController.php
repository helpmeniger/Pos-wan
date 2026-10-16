<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Services\Catalog;
use App\Services\InstallService;

/**
 * Assistant d'installation. Automatiquement désactivé dès que
 * storage/installed.lock existe (toute requête renvoie alors 404).
 */
final class InstallController extends Controller
{
    private function guard(): void
    {
        if (InstallService::isInstalled()) {
            throw new HttpException(404);
        }
    }

    private function checks(): array
    {
        return [
            ['PHP ≥ 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION],
            ['Extension pdo_mysql', extension_loaded('pdo_mysql'), ''],
            ['Extension mbstring', extension_loaded('mbstring'), ''],
            ['Extension fileinfo', extension_loaded('fileinfo'), ''],
            ['Extension gd (images)', extension_loaded('gd'), 'recommandée'],
            ['Extension zip (sauvegarde des fichiers)', extension_loaded('zip'), 'recommandée'],
            ['Extension zlib (compression des sauvegardes)', extension_loaded('zlib'), ''],
            ['Dossier storage/ inscriptible', is_writable(STORAGE_PATH), STORAGE_PATH],
            ['Dossier public/uploads inscriptible', is_writable(PUBLIC_PATH . '/uploads'), ''],
            ['Racine du projet inscriptible (création du .env)', is_file(BASE_PATH . '/.env') || is_writable(BASE_PATH), ''],
        ];
    }

    public function form(): void
    {
        $this->guard();
        view('install/form', ['checks' => $this->checks(), 'types' => Catalog::BUSINESS_TYPES, 'flash' => Session::pullFlash(), 'hasEnv' => is_file(BASE_PATH . '/.env')], null);
    }

    public function submit(): void
    {
        $this->guard();
        $data = [
            'company' => Request::str('company'),
            'business_type' => Request::str('business_type'),
            'admin_username' => Request::str('admin_username'),
            'admin_name' => Request::str('admin_name'),
            'admin_password' => (string) Request::input('admin_password', ''),
            'store_name' => Request::str('store_name'),
            'demo' => Request::input('demo') === '1',
        ];
        try {
            if ($data['company'] === '') {
                throw new BusinessException('Le nom du commerce est obligatoire.');
            }
            if (Request::input('accept_contract') !== '1') {
                throw new BusinessException('Acceptez le contrat de licence d\'utilisation pour installer POS Marwa.');
            }
            if ($data['admin_password'] !== (string) Request::input('admin_password_confirm', '')) {
                throw new BusinessException('La confirmation du mot de passe ne correspond pas.');
            }
            if (!is_file(BASE_PATH . '/.env')) {
                $this->writeEnv();
            }
            DB::connection(); // vérifie l'accès à la base
            InstallService::install($data);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/install');
        } catch (\PDOException $e) {
            \App\Core\Logger::error('Installation : ' . $e->getMessage());
            $this->fail('Connexion à la base impossible : vérifiez l\'hôte, le nom de la base (créée au préalable), l\'utilisateur et le mot de passe.', '/install');
        }
        flash('success', 'Installation terminée. Connectez-vous avec le compte administrateur créé.');
        redirect('/login');
    }

    private function writeEnv(): void
    {
        $host = Request::str('db_host', '127.0.0.1');
        $port = (string) max(1, Request::int('db_port', 3306));
        $name = Request::str('db_name', 'poswan');
        $user = Request::str('db_user');
        $pass = (string) Request::input('db_pass', '');
        foreach ([$host, $name, $user] as $v) {
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $v)) {
                throw new BusinessException('Paramètres de base de données invalides.');
            }
        }
        if (preg_match('/[\r\n"]/', $pass)) {
            throw new BusinessException('Le mot de passe de la base contient des caractères non pris en charge.');
        }
        $key = bin2hex(random_bytes(32));
        $env = "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={$key}\nAPP_BASE_URL=\nAPP_TIMEZONE=Africa/Niamey\n\nDB_HOST={$host}\nDB_PORT={$port}\nDB_DATABASE={$name}\nDB_USERNAME={$user}\nDB_PASSWORD=\"{$pass}\"\n\nSESSION_IDLE_MINUTES=30\nSESSION_MAX_HOURS=12\nSESSION_SECURE=false\n";
        if (file_put_contents(BASE_PATH . '/.env', $env, LOCK_EX) === false) {
            throw new BusinessException('Impossible d\'écrire le fichier .env : vérifiez les droits du dossier.');
        }
        @chmod(BASE_PATH . '/.env', 0640);
        foreach (['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $name, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass, 'APP_KEY' => $key] as $k => $v) {
            Env::set($k, $v);
        }
        \App\Core\Config::load(BASE_PATH . '/config');
        DB::setConnection(null);
    }
}
