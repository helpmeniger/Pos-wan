<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Paths;
use App\Core\Response;
use App\Support\Installer;
use Throwable;

/**
 * Installation automatique au premier lancement (aucune commande à taper).
 * Si la base de données n'est pas trouvée automatiquement, un formulaire est proposé —
 * uniquement depuis l'ordinateur local (adresse locale ou réseau privé).
 */
final class InstallController
{
    private const COOKIE = 'marwa_install';

    /** Retourne une réponse à afficher, ou null pour poursuivre la requête normalement. */
    public static function handle(array $server, array $post, array $cookies): ?Response
    {
        $isPost = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($post['_install']);
        $wasInstalled = is_file(Installer::lockFile());
        $envFile = getenv('MARWA_ENV_FILE') ?: MARWA_ROOT . '/.env';
        // Comptes de démonstration : premier lancement local sans .env, ou APP_DEMO=true.
        $demoDefault = Config::get('app.env') !== 'production' && (!is_file($envFile) || Config::get('app.demo_mode'));
        $errors = [];

        if ($isPost) {
            if (!self::formAllowed($server)) {
                return self::page('Installation', '<p>Installation à distance refusée.</p>', 403);
            }
            if (!is_string($cookies[self::COOKIE] ?? null) || !is_string($post['_install'] ?? null)
                || !hash_equals($cookies[self::COOKIE], $post['_install'])) {
                $errors[] = 'Le formulaire a expiré, réessayez.';
                $db = null;
            } else {
                $db = [
                    'host' => trim((string) ($post['host'] ?? '127.0.0.1')) ?: '127.0.0.1',
                    'port' => (int) ($post['port'] ?? 3306) ?: 3306,
                    'user' => trim((string) ($post['user'] ?? 'root')),
                    'password' => (string) ($post['password'] ?? ''),
                    'name' => trim((string) ($post['name'] ?? 'marwa')) ?: 'marwa',
                ];
                $demoDefault = !empty($post['demo']);
                try {
                    Installer::connectServer($db, 5);
                } catch (Throwable $e) {
                    $errors[] = 'Connexion impossible : ' . Installer::cleanError($e->getMessage());
                    $db = null;
                }
            }
        } else {
            [$db, $errors] = Installer::findServer();
        }

        if ($db !== null) {
            try {
                $log = Installer::install($db, $demoDefault, Paths::absolute($server));
                $fresh = (bool) array_filter($log, fn ($l) => str_contains($l, '001_'));
                if ($wasInstalled || (!$fresh && !$isPost)) {
                    return null; // simple mise à jour, ou base déjà installée en ligne de commande
                }
                return self::done($log);
            } catch (Throwable $e) {
                \App\Core\Logger::error('Installation : ' . $e->getMessage());
                $errors[] = 'L\'installation a échoué : ' . Installer::cleanError($e->getMessage());
            }
        }

        $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (str_contains($path, '/api/')) {
            return Response::json(['error' => ['code' => 'not_installed', 'message' => 'Application en cours d\'installation.']], 503);
        }
        if (!self::formAllowed($server)) {
            return self::page('Installation en attente', '<p>L\'application n\'est pas encore installée. L\'administrateur doit vérifier les paramètres de base de données du fichier <code>.env</code> puis exécuter <code>php bin/install.php</code>.</p>', 503);
        }
        return self::form($errors, $post, $demoDefault);
    }

    /** Formulaire réservé à l'ordinateur local et au réseau local (jamais depuis Internet). */
    private static function formAllowed(array $server): bool
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function form(array $errors, array $post, bool $demo): Response
    {
        $token = bin2hex(random_bytes(16));
        $c = Config::get('database');
        $v = fn (string $k, $d) => e((string) ($post[$k] ?? $d));
        $err = $errors ? '<div class="err"><strong>La base de données n\'a pas été trouvée automatiquement.</strong><ul>'
            . implode('', array_map(fn ($m) => '<li>' . e($m) . '</li>', array_slice($errors, 0, 6))) . '</ul></div>' : '';
        $body = $err . '
<ol class="tips">
  <li>Vérifiez que l\'icône <strong>WampServer</strong> est <strong>verte</strong> (MySQL ou MariaDB démarré).</li>
  <li>Avec WampServer, l\'utilisateur est en général <code>root</code> et le mot de passe est <strong>vide</strong>.
      Port : <code>3306</code> (ou <code>3307</code>/<code>3308</code> si MySQL et MariaDB sont installés ensemble).</li>
</ol>
<form method="post">
  <input type="hidden" name="_install" value="' . $token . '">
  <label>Serveur <input name="host" value="' . $v('host', $c['host']) . '" required></label>
  <label>Port <input name="port" type="number" value="' . $v('port', $c['port']) . '" required></label>
  <label>Utilisateur <input name="user" value="' . $v('user', $c['user']) . '" required autocomplete="off"></label>
  <label>Mot de passe <input name="password" type="password" value="" autocomplete="off"></label>
  <label>Nom de la base (créée automatiquement) <input name="name" value="' . $v('name', $c['name']) . '" pattern="[A-Za-z0-9_]+" required></label>
  <label class="check"><input type="checkbox" name="demo" value="1"' . ($demo ? ' checked' : '') . '> Ajouter les comptes de démonstration</label>
  <button type="submit">Installer MARWA</button>
</form>';
        $r = self::page('Installation de MARWA', $body, 200);
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::COOKIE, $token, ['expires' => 0, 'path' => Paths::base() . '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => $secure]);
        return $r;
    }

    private static function done(array $log): Response
    {
        $demo = array_filter($log, fn ($l) => str_contains($l, 'démonstration'));
        $accounts = $demo ? '<h2>Comptes de démonstration</h2><p>Mot de passe pour tous : <code>' . e(\Database\Seeds\DemoSeeder::PASSWORD) . '</code></p>
<table><tr><th>Profil</th><th>Identifiant</th></tr>
<tr><td>Client</td><td>client@marwa.demo</td></tr>
<tr><td>Chauffeur taxi</td><td>chauffeur@marwa.demo</td></tr>
<tr><td>Livreur</td><td>livreur@marwa.demo</td></tr>
<tr><td>Restaurant</td><td>restaurant@marwa.demo</td></tr>
<tr><td>Entreprise</td><td>entreprise@marwa.demo</td></tr>
<tr><td>Administrateur (superviseur)</td><td>admin@marwa.demo</td></tr>
</table>' : '<p>Créez le compte administrateur : <code>php bin/create-admin.php</code></p>';
        $body = '<p class="ok">✔ MARWA est installé et prêt.</p><ul class="log">'
            . implode('', array_map(fn ($l) => '<li>' . e($l) . '</li>', $log)) . '</ul>' . $accounts
            . '<p><a class="btn" href="' . e(url('')) . '">Ouvrir MARWA</a> <a class="btn ghost" href="' . e(url('connexion')) . '">Se connecter</a></p>';
        return self::page('Installation terminée', $body, 200);
    }

    private static function page(string $title, string $body, int $status): Response
    {
        $logo = e(public_url('assets/img/logo.svg'));
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($title) . '</title><style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#fbf8f2;color:#1d2a24;margin:0;padding:24px 16px}
main{max-width:560px;margin:0 auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 24px rgba(0,0,0,.07)}
h1{font-size:1.4rem;margin:0 0 12px;display:flex;gap:10px;align-items:center}h2{font-size:1.1rem;margin-top:20px}
label{display:block;margin:10px 0;font-weight:600;font-size:.92rem}input:not([type=checkbox]){display:block;width:100%;box-sizing:border-box;padding:10px;margin-top:4px;border:1px solid #cfd8d3;border-radius:10px;font-size:1rem}
.check{display:flex;gap:8px;align-items:center;font-weight:400}
button,.btn{display:inline-block;background:#0b6e4f;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-size:1rem;font-weight:600;text-decoration:none;cursor:pointer;margin-top:8px}
.btn.ghost{background:#e8f1ec;color:#0b6e4f}.err{background:#fdecea;border:1px solid #f5c2bd;border-radius:10px;padding:12px;margin-bottom:12px}
.ok{background:#e6f4ea;border-radius:10px;padding:12px;font-weight:700;color:#0b6e4f}.log{color:#55645c;font-size:.9rem}
table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #eee;padding:6px;text-align:left;font-size:.92rem}code{background:#f1f3f2;padding:1px 5px;border-radius:5px}
.tips{font-size:.92rem;color:#3b4a43;padding-left:18px}
</style></head><body><main><h1><img src="' . $logo . '" alt="" width="36" height="36">' . e($title) . '</h1>' . $body . '</main></body></html>';
        return new Response($status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'self'; script-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'",
        ], $html);
    }
}
