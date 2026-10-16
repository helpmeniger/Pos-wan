<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

/**
 * Installation et mise à jour automatiques.
 *
 * Au premier lancement (et après chaque mise à jour du code), l'application :
 *  1. trouve le serveur MySQL/MariaDB (paramètres du .env, sinon réglages par défaut de
 *     WampServer : utilisateur root sans mot de passe, ports 3306, 3307, 3308) ;
 *  2. crée la base si nécessaire et applique les migrations manquantes ;
 *  3. charge les comptes de démonstration (hors production, base vide uniquement) ;
 *  4. crée le fichier .env s'il n'existe pas.
 * Un fichier storage/installed.lock mémorise les migrations appliquées : tant qu'aucune
 * nouvelle migration n'est livrée, plus rien n'est exécuté.
 */
final class Installer
{
    public static function lockFile(): string
    {
        return MARWA_ROOT . '/storage/installed.lock';
    }

    /** Versions des migrations livrées avec le code. */
    public static function shippedMigrations(): array
    {
        $v = array_map(fn ($f) => basename($f, '.sql'), glob(MARWA_ROOT . '/database/migrations/*.sql') ?: []);
        sort($v);
        return $v;
    }

    /** Vrai si la base est à jour par rapport au code (vérification très légère, sans requête SQL). */
    public static function upToDate(): bool
    {
        $lock = self::lockFile();
        if (!is_file($lock)) {
            return false;
        }
        $data = json_decode((string) @file_get_contents($lock), true);
        return is_array($data) && ($data['migrations'] ?? null) === self::shippedMigrations();
    }

    /**
     * Paramètres de connexion à essayer, dans l'ordre. Ceux du .env d'abord ;
     * les réglages par défaut de WampServer uniquement en local.
     *
     * @return list<array{host:string,port:int,user:string,password:string,name:string}>
     */
    public static function candidates(): array
    {
        $c = Config::get('database');
        $list = [['host' => (string) $c['host'], 'port' => (int) $c['port'], 'user' => (string) $c['user'], 'password' => (string) $c['password'], 'name' => (string) $c['name']]];
        if (Config::get('app.env') !== 'production') {
            foreach ([3306, 3307, 3308] as $port) {
                foreach (['127.0.0.1', 'localhost'] as $host) {
                    $list[] = ['host' => $host, 'port' => $port, 'user' => 'root', 'password' => '', 'name' => (string) $c['name']];
                }
            }
        }
        $unique = [];
        foreach ($list as $cand) {
            $unique[implode('|', $cand)] = $cand;
        }
        return array_values($unique);
    }

    public static function connectServer(array $db, int $timeout = 3): PDO
    {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeout,
        ]);
        return $pdo;
    }

    /**
     * Cherche un serveur accessible. Préfère celui qui contient déjà la base MARWA
     * (WampServer peut faire tourner MySQL et MariaDB en même temps).
     *
     * @return array{0: ?array, 1: list<string>} [paramètres retenus, erreurs rencontrées]
     */
    public static function findServer(): array
    {
        $errors = [];
        $first = null;
        foreach (self::candidates() as $db) {
            try {
                $pdo = self::connectServer($db);
            } catch (Throwable $e) {
                $errors[] = sprintf('%s@%s:%d — %s', $db['user'], $db['host'], $db['port'], self::cleanError($e->getMessage()));
                continue;
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $st->execute([$db['name']]);
            if ((int) $st->fetchColumn() > 0) {
                return [$db, $errors];
            }
            $first ??= $db;
        }
        return [$first, $errors];
    }

    /**
     * Installe ou met à jour. Retourne le journal des opérations.
     *
     * @param array{host:string,port:int,user:string,password:string,name:string} $db
     */
    public static function install(array $db, bool $demo, ?string $appUrl = null): array
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db['name'])) {
            throw new \InvalidArgumentException('Nom de base invalide (lettres, chiffres et « _ » uniquement).');
        }
        @set_time_limit(600);
        $dir = MARWA_ROOT . '/storage';
        $lockHandle = @fopen($dir . '/install.running', 'c');
        if ($lockHandle) {
            flock($lockHandle, LOCK_EX); // une seule installation à la fois
        }
        try {
            if (self::upToDate()) {
                return ['Déjà installé.'];
            }
            foreach (['host', 'port', 'user', 'password', 'name'] as $k) {
                Config::set('database.' . $k, $db[$k]);
            }
            Database::setPdo(null);
            $log = SqlRunner::migrate($db['name']);

            $users = (int) Database::value('SELECT COUNT(*) FROM users');
            if ($demo && $users === 0 && Config::get('app.env') !== 'production') {
                require_once MARWA_ROOT . '/database/seeds/DemoSeeder.php';
                \Database\Seeds\DemoSeeder::run();
                $log[] = 'Comptes de démonstration créés (mot de passe : ' . \Database\Seeds\DemoSeeder::PASSWORD . ').';
            }

            self::writeEnv($db, $demo, $appUrl);
            $log[] = 'Fichier .env enregistré.';

            $written = @file_put_contents(self::lockFile(), json_encode([
                'installed_at' => date('c'),
                'migrations' => self::shippedMigrations(),
            ], JSON_PRETTY_PRINT));
            if ($written === false) {
                throw new \RuntimeException('Impossible d\'écrire dans le dossier storage/. Vérifiez les droits d\'écriture.');
            }
            return $log;
        } finally {
            if ($lockHandle) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    /**
     * Crée .env à partir de .env.example s'il n'existe pas ; sinon ne met à jour que
     * les paramètres de base de données s'ils ont dû être découverts automatiquement.
     */
    public static function writeEnv(array $db, bool $demo, ?string $appUrl): void
    {
        $file = getenv('MARWA_ENV_FILE') ?: MARWA_ROOT . '/.env';
        $values = [
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['name'],
            'DB_USERNAME' => $db['user'], 'DB_PASSWORD' => $db['password'],
        ];
        if (is_file($file)) {
            $same = (string) Env::get('DB_HOST', '') === $db['host'] && (int) Env::get('DB_PORT', 0) === (int) $db['port']
                && (string) Env::get('DB_USERNAME', '') === $db['user'] && (string) Env::get('DB_PASSWORD', '') === $db['password']
                && (string) Env::get('DB_DATABASE', '') === $db['name'];
            if ($same || !is_writable($file)) {
                return;
            }
            $content = (string) file_get_contents($file);
        } else {
            $content = (string) @file_get_contents(MARWA_ROOT . '/.env.example');
            $values += [
                'APP_DEMO' => $demo ? 'true' : 'false',
                'PAYMENT_DEMO_SECRET' => bin2hex(random_bytes(32)),
            ];
            if ($appUrl) {
                $values['APP_URL'] = $appUrl;
            }
        }
        foreach ($values as $key => $value) {
            $line = $key . '=' . self::quote($value);
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            $content = preg_match($pattern, $content)
                ? (string) preg_replace_callback($pattern, fn () => $line, $content)
                : rtrim($content) . "\n" . $line . "\n";
        }
        if (@file_put_contents($file, $content) === false) {
            throw new \RuntimeException('Impossible de créer le fichier .env à la racine du projet (droits d\'écriture).');
        }
    }

    private static function quote(string $v): string
    {
        return $v === '' || preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $v) ? $v : '"' . str_replace('"', '', $v) . '"';
    }

    /** Messages d'erreur MySQL compréhensibles. */
    public static function cleanError(string $msg): string
    {
        return match (true) {
            str_contains($msg, '2002'), str_contains($msg, 'refused'), str_contains($msg, 'No such file') => 'serveur injoignable (MySQL/MariaDB est-il démarré ? icône WampServer verte ?)',
            str_contains($msg, '1045'), str_contains($msg, '1698') => 'accès refusé (utilisateur ou mot de passe incorrect)',
            str_contains($msg, '1044') => 'droits insuffisants sur la base',
            default => preg_replace('/^SQLSTATE\[[^\]]+\]\s*(\[\d+\])?\s*/', '', $msg),
        };
    }
}
