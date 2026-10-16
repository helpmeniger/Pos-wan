<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;

/** Liste de contrôle de sécurité (console : bin/security-check.php ; administration : Paramètres). */
final class SecurityCheck
{
    public static function run(?string $url = null): array
    {
        $out = [];
        $prod = Config::get('app.env') === 'production';
        $add = function (string $label, bool $ok, string $detail = '', bool $critical = true) use (&$out) {
            $out[] = ['label' => $label, 'status' => $ok ? 'ok' : ($critical ? 'fail' : 'warn'), 'detail' => $ok ? '' : $detail];
        };
        $add('Mode production (APP_ENV=production)', $prod, 'En ligne, mettez APP_ENV=production dans .env.', false);
        $add('Messages d\'erreur détaillés masqués (APP_DEBUG=false)', !Config::get('app.debug'), 'APP_DEBUG=true affiche des détails techniques aux visiteurs.', $prod);
        $add('Bandeau et données de démonstration désactivés', !Config::get('app.demo_mode'), 'APP_DEMO=false en production.', $prod);
        $add('Passerelle de paiement de démonstration désactivée', !Config::get('services.payments.demo.enabled', Env::get('PAYMENT_DEMO_ENABLED', false)), 'PAYMENT_DEMO_ENABLED=false en production.', $prod);
        $appUrl = (string) Config::get('app.url');
        $add('Adresse en HTTPS (APP_URL)', str_starts_with($appUrl, 'https://'), 'Obtenez un certificat (Let\'s Encrypt) et utilisez https://.', $prod);
        $add('Cookies de session sécurisés (SESSION_SECURE_COOKIE=true)', (bool) Config::get('security.cookie_secure'), 'Obligatoire en HTTPS : SESSION_SECURE_COOKIE=true.', $prod);
        $dbUser = (string) Config::get('database.user');
        $dbPass = (string) Config::get('database.password');
        $add('Utilisateur de base de données dédié (pas root)', $dbUser !== 'root', 'Créez un utilisateur MySQL « marwa » (voir DEPLOIEMENT_VPS.md).', $prod);
        $add('Mot de passe de base de données robuste', strlen($dbPass) >= 16, 'Au moins 16 caractères aléatoires.', $prod);
        $secret = (string) Env::get('PAYMENT_DEMO_SECRET', '');
        $add('Secret de paiement non laissé par défaut', $secret !== 'changez-moi' && strlen($secret) >= 32 || !Env::get('PAYMENT_DEMO_ENABLED', false), 'Générez une valeur aléatoire de 64 caractères.', false);

        $envFile = MARWA_ROOT . '/.env';
        if (is_file($envFile) && DIRECTORY_SEPARATOR === '/') {
            $perm = fileperms($envFile) & 0777;
            $add('Fichier .env non lisible par tous', ($perm & 0007) === 0, sprintf('Droits actuels %o : chmod 640 .env', $perm), $prod);
        }
        $add('Dossier storage/ accessible en écriture', is_writable(MARWA_ROOT . '/storage'), 'Le serveur web doit pouvoir écrire dans storage/.');
        $add('Clé secrète de l\'application présente', strlen(\App\Core\Secrets::key()) >= 32, 'APP_KEY ou storage/app.key.');
        $add('OpenSSL disponible (chiffrement, notifications push)', function_exists('openssl_pkey_derive'), 'Activez l\'extension openssl de PHP.');
        $add('PHP à jour (8.2 ou plus recommandé)', PHP_VERSION_ID >= 80200, 'Version actuelle ' . PHP_VERSION . '.', false);
        $add('PHP n\'affiche pas les erreurs (display_errors)', !in_array(strtolower((string) ini_get('display_errors')), ['1', 'on', 'stdout'], true) || PHP_SAPI === 'cli', 'display_errors = Off dans php.ini.', $prod);

        try {
            $demo = (int) Database::value('SELECT COUNT(*) FROM users WHERE is_demo = 1 AND status <> \'deleted\'');
            $add('Aucun compte de démonstration actif', $demo === 0, "$demo compte(s) de démonstration (mot de passe public) : supprimez-les avant l'ouverture.", $prod);
            $staff = Database::select("SELECT u.email, u.totp_enabled_at FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_staff = 1 AND u.status = 'active' AND u.is_demo = 0");
            $without = array_filter($staff, fn ($s) => !$s['totp_enabled_at']);
            $add('Double authentification activée pour tout le personnel', !$without, count($without) . ' compte(s) du personnel sans double authentification.', false);
            $add('Double authentification obligatoire pour le personnel', filter_var(\App\Core\Settings::get('security.staff_2fa_required', false), FILTER_VALIDATE_BOOLEAN), 'Administration → Paramètres → « Double authentification obligatoire ».', false);
            $add('Au moins un administrateur réel', (bool) Database::value("SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.status = 'active' AND u.is_demo = 0 LIMIT 1"), 'php bin/create-admin.php', $prod);
            $add('Base de données accessible', true);
        } catch (\Throwable $e) {
            $add('Base de données accessible', false, $e->getMessage());
        }
        $backups = glob(MARWA_ROOT . '/storage/backups/*.sql.gz') ?: [];
        $last = $backups ? max(array_map('filemtime', $backups)) : 0;
        $add('Sauvegarde de moins de 48 h', $last > time() - 172800, $last ? 'Dernière : ' . date('d/m/Y H:i', $last) . '. Planifiez bin/backup.php chaque jour.' : 'Aucune sauvegarde : planifiez bin/backup.php chaque jour.', false);
        $add('Liste d\'adresses IP autorisées pour l\'administration', (string) Env::get('ADMIN_ALLOWED_IPS', '') !== '', 'Facultatif : ADMIN_ALLOWED_IPS=ip1,ip2 limite l\'administration à vos bureaux.', false);

        if ($url) {
            $base = rtrim($url, '/');
            foreach (['/.env', '/storage/logs/', '/config/app.php', '/storage/app.key', '/storage/vapid.json', '/database/marwa_install.sql', '/.git/config', '/composer.json', '/assets/i18n/_source.json'] as $p) {
                $code = self::status($base . $p);
                $add("Fichier interne protégé : $p", $code === 403 || $code === 404, "Réponse HTTP $code : ce fichier ne doit pas être accessible.");
            }
            $h = self::headers($base . '/');
            foreach (['content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy'] as $hd) {
                $add("En-tête de sécurité $hd", isset($h[$hd]), 'En-tête absent de la page d\'accueil.');
            }
            if (str_starts_with($base, 'https://')) {
                $add('HSTS (Strict-Transport-Security)', isset($h['strict-transport-security']), 'Activez SESSION_SECURE_COOKIE=true (ajoute HSTS).');
                $add('Redirection HTTP → HTTPS', in_array(self::status('http://' . substr($base, 8) . '/'), [301, 302, 308], true), 'Le port 80 doit rediriger vers HTTPS.', false);
            }
        }
        return $out;
    }

    private static function status(string $url): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    private static function headers(string $url): array
    {
        $h = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$h) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $h[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        }]);
        curl_exec($ch);
        curl_close($ch);
        return $h;
    }
}
