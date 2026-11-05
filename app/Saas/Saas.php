<?php
declare(strict_types=1);

namespace App\Saas;

use App\Core\Config;
use App\Core\Env;

/**
 * Mode SaaS : une base de données PAR CLIENT, choisie d'après le sous-domaine.
 *
 *   posmarwa.ne            → site public (offres, inscription) et console de l'exploitant
 *   boutique-awa.posmarwa.ne → espace du client « boutique-awa » (sa base, ses dossiers)
 *
 * Isolement : base MySQL distincte (et, en mode « create », utilisateur MySQL distinct qui
 * n'a de droits que sur sa base), dossier storage/tenants/<espace> (sessions, journaux,
 * sauvegardes), dossier public/uploads/t/<espace> (photos), clé secrète dérivée propre à
 * l'espace, cookie de session limité à son sous-domaine.
 */
final class Saas
{
    private static string $context = 'single'; // single | central | tenant
    private static ?array $tenant = null;

    /** Appelé par app/bootstrap.php, avant toute connexion et tout accès aux dossiers. */
    public static function boot(): void
    {
        if (!config('saas.enabled')) {
            self::paths(BASE_PATH . '/storage', PUBLIC_PATH . '/uploads', 'uploads');
            return;
        }
        if (config('saas.domain') === '') {
            self::halt(500, 'Configuration incomplète', 'SAAS_MODE est activé mais SAAS_DOMAIN est vide dans le fichier .env.');
        }
        if (PHP_SAPI === 'cli') {
            $slug = strtolower((string) (getenv('POSWAN_TENANT') ?: ''));
            if ($slug === '' && !in_array(basename((string) ($_SERVER['argv'][0] ?? '')), ['saas.php'], true)) {
                self::halt(1, 'Espace client non précisé', 'Mode SaaS : indiquez l\'espace, par exemple « POSWAN_TENANT=boutique-awa php bin/migrate.php », ou utilisez php bin/saas.php.');
            }
        } else {
            $slug = self::slugFromHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
            if ($slug === null) {
                self::halt(404, 'Adresse inconnue', 'Cette adresse ne correspond à aucun espace POS Marwa.');
            }
        }
        if ($slug === '') {
            self::$context = 'central';
            Config::set('database', config('saas.central'));
            self::paths(BASE_PATH . '/storage/saas', PUBLIC_PATH . '/uploads/saas', 'uploads/saas');
            return;
        }
        try {
            $t = Central::one('SELECT * FROM saas_tenants WHERE slug = ?', [$slug]);
        } catch (\Throwable) {
            self::halt(503, 'Service momentanément indisponible', 'Le service ne répond pas pour le moment. Réessayez dans quelques minutes.');
        }
        if (!$t) {
            self::halt(404, 'Espace introuvable', 'Aucun espace POS Marwa n\'existe à cette adresse. Vérifiez l\'orthographe ou retrouvez votre espace sur ' . self::centralUrl('/trouver') . '.');
        }
        if ($t['state'] === 'waiting_db') {
            self::halt(503, 'Espace en préparation', 'Votre espace « ' . $t['company'] . ' » est enregistré : son ouverture est en cours de préparation par notre équipe. Vous serez contacté au ' . $t['phone'] . '.');
        }
        self::loadTenant($t);
    }

    /** Bascule le processus sur l'espace d'un client (aussi utilisé par les tests). */
    public static function loadTenant(array $t): void
    {
        self::$context = 'tenant';
        self::$tenant = $t;
        [$user, $pass] = self::dbCredentials($t);
        $c = config('saas.central');
        Config::set('database', ['host' => $c['host'], 'port' => $c['port'], 'database' => $t['db_name'], 'username' => $user, 'password' => $pass, 'charset' => 'utf8mb4']);
        // Clé secrète propre à l'espace (signatures des documents, badges) : un document signé
        // par un client n'est jamais valable chez un autre.
        Env::set('APP_KEY', hash_hmac('sha256', 'tenant|' . $t['id'] . '|' . $t['slug'], (string) Env::get('APP_KEY', '') . '|' . self::masterKey()));
        if (!defined('STORAGE_PATH')) {
            self::paths(BASE_PATH . '/storage/tenants/' . $t['slug'], PUBLIC_PATH . '/uploads/t/' . $t['slug'], 'uploads/t/' . $t['slug']);
        }
    }

    public static function dbCredentials(array $t): array
    {
        if (!empty($t['db_user'])) {
            return [(string) $t['db_user'], self::decrypt((string) $t['db_pass_enc'])];
        }
        $c = config('saas.central');
        return [$c['username'], $c['password']];
    }

    private static function paths(string $storage, string $uploads, string $uploadsUrl): void
    {
        define('STORAGE_PATH', $storage);
        define('UPLOADS_PATH', $uploads);
        define('UPLOADS_URL', $uploadsUrl);
        if ($storage !== BASE_PATH . '/storage') {
            foreach (['', '/sessions', '/logs', '/cache', '/backups', '/tmp'] as $d) {
                if (!is_dir($storage . $d)) {
                    @mkdir($storage . $d, 0750, true);
                }
            }
            if (!is_dir($uploads)) {
                @mkdir($uploads, 0755, true);
            }
        }
    }

    public static function enabled(): bool
    {
        return self::$context !== 'single';
    }

    public static function isCentral(): bool
    {
        return self::$context === 'central';
    }

    public static function isTenant(): bool
    {
        return self::$context === 'tenant';
    }

    public static function tenant(): ?array
    {
        return self::$tenant;
    }

    /** Recharge la fiche du client depuis la base centrale (après un paiement, par ex.). */
    public static function refreshTenant(): ?array
    {
        if (self::$tenant) {
            self::$tenant = Central::one('SELECT * FROM saas_tenants WHERE id = ?', [self::$tenant['id']]) ?? self::$tenant;
        }
        return self::$tenant;
    }

    /** '' = site central, 'slug' = espace client, null = hôte étranger au service. */
    public static function slugFromHost(string $host): ?string
    {
        $host = strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? '');
        $domain = (string) config('saas.domain');
        if ($host === $domain || $host === 'www.' . $domain) {
            return '';
        }
        if (!str_ends_with($host, '.' . $domain)) {
            return null;
        }
        $slug = substr($host, 0, -strlen('.' . $domain));
        return self::validSlug($slug) ? $slug : null;
    }

    public static function validSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug) && !str_contains($slug, '--');
    }

    public static function reserved(string $slug): bool
    {
        return in_array($slug, (array) config('saas.reserved'), true);
    }

    private static function origin(string $host): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (bool) config('app.session.secure');
        $port = '';
        if (preg_match('/:(\d+)$/', (string) ($_SERVER['HTTP_HOST'] ?? ''), $m) && !in_array($m[1], ['80', '443'], true)) {
            $port = ':' . $m[1];
        }
        return ($https ? 'https' : 'http') . '://' . $host . $port;
    }

    public static function tenantUrl(string $slug, string $path = '/'): string
    {
        return self::origin($slug . '.' . config('saas.domain')) . '/' . ltrim($path, '/');
    }

    public static function centralUrl(string $path = '/'): string
    {
        return self::origin((string) config('saas.domain')) . '/' . ltrim($path, '/');
    }

    // ------------------------------------------------------------ chiffrement

    private static function masterKey(): string
    {
        $k = base64_decode((string) config('saas.key'), true);
        if ($k !== false && strlen($k) === 32) {
            return $k;
        }
        // Repli : dérivée de APP_KEY (définir SAAS_KEY est recommandé, voir SAAS.md)
        return hash('sha256', 'pos-marwa-saas|' . (string) Env::get('APP_KEY', ''), true);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(24);
        return 'S1.' . base64_encode($nonce . \App\Services\LicenceService::sodium('crypto_secretbox', $plain, $nonce, self::masterKey()));
    }

    public static function decrypt(string $enc): string
    {
        $raw = str_starts_with($enc, 'S1.') ? base64_decode(substr($enc, 3), true) : false;
        $plain = $raw !== false && strlen($raw) > 24 ? \App\Services\LicenceService::sodium('crypto_secretbox_open', substr($raw, 24), substr($raw, 0, 24), self::masterKey()) : false;
        if (!is_string($plain)) {
            throw new \RuntimeException('Donnée chiffrée illisible (SAAS_KEY modifiée ?).');
        }
        return $plain;
    }

    // ------------------------------------------------------------ pages d'arrêt

    public static function halt(int $status, string $title, string $message): never
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $title . ' : ' . $message . PHP_EOL);
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'");
        }
        echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . ' · POS Marwa</title></head>'
            . '<body style="margin:0;font-family:system-ui,Segoe UI,sans-serif;background:#f4f5fb;color:#14213d;display:flex;min-height:100vh;align-items:center;justify-content:center">'
            . '<div style="max-width:560px;margin:24px;background:#fff;border-radius:14px;padding:32px;box-shadow:0 6px 24px rgba(20,33,61,.08)">'
            . '<div style="font-weight:700;color:#6f42c1;letter-spacing:.06em;font-size:13px">POS MARWA</div>'
            . '<h1 style="font-size:24px;margin:8px 0 12px">' . e($title) . '</h1><p style="line-height:1.55;margin:0">' . e($message) . '</p></div></body></html>';
        exit;
    }
}
