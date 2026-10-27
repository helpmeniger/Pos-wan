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
        @set_time_limit(300);
        ignore_user_abort(true); // une fenêtre fermée pendant la création des tables n'interrompt pas l'installation
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
            $this->configureDatabase();
            $result = InstallService::install($data);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage(), '/install');
        } catch (\PDOException $e) {
            \App\Core\Logger::error('Installation : ' . $e->getMessage());
            $this->fail(InstallService::explainDbError($e, (string) config('database.database')), '/install');
        }
        flash('success', $result === 'existing'
            ? 'Cette base contenait déjà POS Marwa : ses données sont conservées (sauvegarde faite dans storage/backups). Connectez-vous avec un compte existant.'
            : 'Installation terminée. Connectez-vous avec le compte administrateur créé.');
        redirect('/login');
    }

    /**
     * Paramètres de la base : ceux saisis dans le formulaire (corrigent un .env existant) ou,
     * champs laissés vides, ceux du .env. Le .env n'est écrit qu'APRÈS une connexion réussie
     * et une vérification des droits : une erreur de saisie ne bloque plus l'assistant.
     */
    private function configureDatabase(): void
    {
        $hasEnv = is_file(BASE_PATH . '/.env');
        $typed = Request::str('db_name') !== '' || Request::str('db_user') !== '';
        if (!$typed && !$hasEnv) {
            throw new BusinessException('Renseignez la base de données : nom, utilisateur et mot de passe créés dans cPanel (ou phpMyAdmin).');
        }
        if ($typed) {
            $host = Request::str('db_host') ?: 'localhost';
            $port = (string) max(1, Request::int('db_port', 3306));
            $name = Request::str('db_name');
            $user = Request::str('db_user');
            $pass = (string) Request::input('db_pass', '');
            if ($pass === '' && $hasEnv) {
                $pass = (string) Env::get('DB_PASSWORD', ''); // champ vide : mot de passe du .env conservé
            }
            foreach (['hôte' => $host, 'nom de la base' => $name, 'utilisateur' => $user] as $label => $v) {
                if (!preg_match('/^[A-Za-z0-9._-]+$/', $v)) {
                    throw new BusinessException("Paramètre de base de données invalide ($label) : lettres, chiffres, point, tiret et souligné uniquement.");
                }
            }
            if (preg_match('/[\r\n"]/', $pass)) {
                throw new BusinessException('Le mot de passe de la base contient un guillemet ou un retour à la ligne : choisissez-en un autre dans cPanel.');
            }
            foreach (['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $name, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass] as $k => $v) {
                Env::set($k, $v);
            }
            \App\Core\Config::load(BASE_PATH . '/config');
            DB::setConnection(null);
        }
        InstallService::checkDatabase(); // connexion, droits, base vide ou installation interrompue
        if ($typed) {
            $this->saveEnv([
                'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_DATABASE' => $name, 'DB_USERNAME' => $user, 'DB_PASSWORD' => '"' . $pass . '"',
            ]);
        }
    }

    /** Crée le .env, ou n'y remplace que les lignes de la base (les autres réglages sont conservés). */
    private function saveEnv(array $db): void
    {
        $file = BASE_PATH . '/.env';
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($db as $k => $v) {
                $found = false;
                foreach ($lines as $i => $line) {
                    if (preg_match('/^\s*' . $k . '\s*=/', $line)) {
                        $lines[$i] = "$k=$v";
                        $found = true;
                    }
                }
                if (!$found) {
                    $lines[] = "$k=$v";
                }
            }
            if (!preg_match('/^\s*APP_KEY\s*=\s*\S{32,}/m', implode("\n", $lines))) {
                $lines = array_values(array_filter($lines, fn($l) => !preg_match('/^\s*APP_KEY\s*=/', $l)));
                $key = bin2hex(random_bytes(32));
                array_unshift($lines, "APP_KEY=$key");
                Env::set('APP_KEY', $key);
            }
            $env = implode("\n", $lines) . "\n";
        } else {
            $key = bin2hex(random_bytes(32));
            Env::set('APP_KEY', $key);
            $env = "APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY={$key}\nAPP_BASE_URL=\nAPP_TIMEZONE=Africa/Niamey\n\n"
                . "DB_HOST={$db['DB_HOST']}\nDB_PORT={$db['DB_PORT']}\nDB_DATABASE={$db['DB_DATABASE']}\nDB_USERNAME={$db['DB_USERNAME']}\nDB_PASSWORD={$db['DB_PASSWORD']}\n\n"
                . "SESSION_IDLE_MINUTES=30\nSESSION_MAX_HOURS=12\nSESSION_SECURE=false\n";
        }
        if (file_put_contents($file, $env, LOCK_EX) === false) {
            throw new BusinessException('Connexion à la base réussie, mais impossible d\'écrire le fichier .env : vérifiez les droits du dossier du logiciel (755).');
        }
        @chmod($file, 0640);
    }
}
