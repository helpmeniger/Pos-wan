<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Env;
use PDO;

/**
 * Hébergement (local ou en ligne) et synchronisation de la base locale vers une base MySQL en ligne
 * (par exemple chez o2switch, avec « MySQL distant » autorisé pour l'adresse IP de la boutique).
 *
 * Sens unique : le poste local est la référence ; la base en ligne devient sa copie exacte.
 * - le schéma de chaque table est recréé en ligne s'il diffère ;
 * - les données sont comparées par blocs (nombre de lignes + somme de contrôle) : seuls les blocs
 *   différents sont renvoyés, donc une synchronisation courante est rapide ;
 * - le mot de passe de la base en ligne est chiffré avec APP_KEY, jamais écrit en clair ni envoyé en ligne ;
 * - la première synchronisation vers une base qui contient déjà des tables exige une confirmation.
 * Les fichiers (photos, archives) ne sont pas copiés : seule la base de données l'est.
 */
final class SyncService
{
    private const CHUNK = 2000;
    private const BATCH = 200;
    private const MARKER = '_posmarwa_sync';
    /** Tables jamais copiées (propres à chaque installation). */
    private const SKIP = ['login_attempts', self::MARKER];
    /** Lignes exclues : réglages propres au poste local (jamais envoyés ni effacés en ligne). */
    private const FILTERS = ['settings' => "skey NOT LIKE 'sync\\_%' AND skey NOT IN ('hosting_mode')"];

    // ------------------------------------------------------------------ hébergement

    /** « local » (WampServer de la boutique) ou « online » (serveur distant). Sans réglage : déduit de l'adresse. */
    public static function hostingMode(): string
    {
        $m = (string) setting('hosting_mode', '');
        if ($m === 'local' || $m === 'online') {
            return $m;
        }
        return self::guessOnline() ? 'online' : 'local';
    }

    public static function guessOnline(): bool
    {
        if (\App\Saas\Saas::isTenant()) {
            return true;
        }
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?? '');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.lan') || !str_contains($host, '.')) {
            return false;
        }
        return !(filter_var($host, FILTER_VALIDATE_IP) && NetworkService::isPrivate($host)) && $host !== '127.0.0.1';
    }

    public static function isOnline(): bool
    {
        return self::hostingMode() === 'online';
    }

    public static function setHostingMode(string $mode): void
    {
        if (!in_array($mode, ['local', 'online'], true)) {
            throw new BusinessException('Choix d\'hébergement inconnu.');
        }
        $before = self::hostingMode();
        setting_set('hosting_mode', $mode);
        Audit::log('settings.hosting_mode', null, null, ['from' => $before, 'to' => $mode]);
    }

    // ------------------------------------------------------------------ configuration de la base en ligne

    private static function key(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        if (strlen($k) < 32) {
            throw new BusinessException('APP_KEY absente du fichier .env : le mot de passe de la base en ligne ne peut pas être chiffré.');
        }
        return hash('sha256', 'sync-db|' . $k, true);
    }

    private static function encrypt(string $plain): string
    {
        $nonce = random_bytes(24);
        return 'E1.' . base64_encode($nonce . LicenceService::sodium('crypto_secretbox', $plain, $nonce, self::key()));
    }

    private static function decrypt(string $enc): string
    {
        $raw = str_starts_with($enc, 'E1.') ? base64_decode(substr($enc, 3), true) : false;
        $plain = $raw !== false && strlen($raw) > 24 ? LicenceService::sodium('crypto_secretbox_open', substr($raw, 24), substr($raw, 0, 24), self::key()) : false;
        if ($plain === false) {
            throw new BusinessException('Mot de passe de la base en ligne illisible (APP_KEY a changé ?) : saisissez-le de nouveau.');
        }
        return (string) $plain;
    }

    public static function config(): array
    {
        return ['host' => (string) setting('sync_db_host', ''), 'port' => (int) (setting('sync_db_port', '3306') ?: 3306), 'database' => (string) setting('sync_db_name', ''),
            'username' => (string) setting('sync_db_user', ''), 'has_password' => (string) setting('sync_db_password', '') !== '', 'enabled' => setting('sync_enabled', '0') === '1',
            'auto_minutes' => max(0, (int) setting('sync_auto_minutes', '0')), 'last_at' => (string) setting('sync_last_at', ''), 'last_result' => (string) setting('sync_last_result', '')];
    }

    public static function saveConfig(array $in): void
    {
        $host = trim((string) ($in['host'] ?? ''));
        $db = trim((string) ($in['database'] ?? ''));
        $user = trim((string) ($in['username'] ?? ''));
        $port = (int) ($in['port'] ?? 3306) ?: 3306;
        if ($host === '' || $db === '' || $user === '') {
            throw new BusinessException('Serveur, nom de la base et utilisateur de la base en ligne obligatoires.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,190}$/', $host) || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $db) || $port < 1 || $port > 65535) {
            throw new BusinessException('Serveur, base ou port invalide.');
        }
        $local = config('database');
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', strtolower((string) $local['host'])], true) && $db === $local['database'] && $port === (int) $local['port']) {
            throw new BusinessException('La base en ligne ne peut pas être la base locale elle-même.');
        }
        setting_set('sync_db_host', $host);
        setting_set('sync_db_port', (string) $port);
        setting_set('sync_db_name', $db);
        setting_set('sync_db_user', $user);
        $pwd = (string) ($in['password'] ?? '');
        if ($pwd !== '') {
            setting_set('sync_db_password', self::encrypt($pwd));
        }
        setting_set('sync_enabled', !empty($in['enabled']) ? '1' : '0');
        setting_set('sync_auto_minutes', (string) min(1440, max(0, (int) ($in['auto_minutes'] ?? 0))));
        Audit::log('settings.sync_config', null, null, ['host' => $host, 'database' => $db, 'user' => $user]);
    }

    /** Connexion à la base en ligne (délai court : Internet peut manquer). */
    public static function remote(): PDO
    {
        $c = self::config();
        if ($c['host'] === '' || !$c['has_password']) {
            throw new BusinessException('Renseignez d\'abord la base de données en ligne et son mot de passe.');
        }
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['database']), $c['username'], self::decrypt((string) setting('sync_db_password', '')), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 12, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true]);
        } catch (\PDOException $e) {
            $m = $e->getMessage();
            $why = match (true) {
                str_contains($m, '[2002]') || str_contains($m, 'getaddrinfo') || str_contains($m, 'timed out') => 'serveur injoignable : pas de connexion Internet, adresse incorrecte, ou l\'adresse IP de ce poste n\'est pas autorisée (cPanel → MySQL distant).',
                str_contains($m, '[1045]') => 'utilisateur ou mot de passe refusé (ou adresse IP non autorisée dans cPanel → MySQL distant).',
                str_contains($m, '[1044]') || str_contains($m, '[1049]') => 'base introuvable ou utilisateur sans droit sur cette base.',
                default => $m,
            };
            throw new BusinessException('Connexion à la base en ligne impossible : ' . $why);
        }
        $offset = (new \DateTime())->format('P');
        $pdo->exec("SET time_zone = '{$offset}', sql_mode = 'NO_AUTO_VALUE_ON_ZERO', FOREIGN_KEY_CHECKS = 0, UNIQUE_CHECKS = 0, NAMES utf8mb4");
        return $pdo;
    }

    /** Essai de connexion : version du serveur, tables présentes, propriétaire de la copie. */
    public static function test(): array
    {
        $r = self::remote();
        $tables = $r->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $names = array_column($tables, 0);
        $owner = in_array(self::MARKER, $names, true) ? (string) ($r->query('SELECT install_id FROM `' . self::MARKER . '` LIMIT 1')->fetchColumn() ?: '') : '';
        return ['version' => (string) $r->query('SELECT VERSION()')->fetchColumn(), 'tables' => count($names), 'owner' => $owner, 'mine' => $owner !== '' && hash_equals($owner, LicenceService::installId()),
            'foreign' => count($names) > 0 && ($owner === '' || !hash_equals($owner, LicenceService::installId()))];
    }

    // ------------------------------------------------------------------ synchronisation

    /** Tables locales à copier, dans l'ordre alphabétique. */
    public static function tables(): array
    {
        $out = [];
        foreach (DB::connection()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t) {
            if (!in_array($t[0], self::SKIP, true)) {
                $out[] = (string) $t[0];
            }
        }
        sort($out);
        return $out;
    }

    private static function normalizeCreate(string $sql): string
    {
        return trim((string) preg_replace(['/ AUTO_INCREMENT=\d+/', '/\s+/'], ['', ' '], $sql));
    }

    /** Colonnes réelles (hors colonnes calculées) et clé primaire entière unique, s'il y en a une. */
    private static function describe(string $table): array
    {
        $cols = []; $pk = [];
        foreach (DB::all('SELECT COLUMN_NAME, COLUMN_KEY, EXTRA, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]) as $c) {
            if (stripos((string) $c['EXTRA'], 'GENERATED') !== false) {
                continue;
            }
            $cols[] = $c['COLUMN_NAME'];
            if ($c['COLUMN_KEY'] === 'PRI') {
                $pk[] = [$c['COLUMN_NAME'], $c['DATA_TYPE']];
            }
        }
        $int = count($pk) === 1 && in_array(strtolower($pk[0][1]), ['int', 'bigint', 'mediumint', 'smallint', 'tinyint'], true) ? $pk[0][0] : null;
        return ['cols' => $cols, 'pk' => $int];
    }

    private static function sumSql(string $table, array $cols, string $where): string
    {
        $parts = implode(', ', array_map(fn ($c) => "IFNULL(CAST(`$c` AS CHAR CHARACTER SET utf8mb4), '~N~')", $cols));
        return "SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', $parts))), 0) AS s FROM `$table` WHERE $where";
    }

    private static function copyRows(PDO $remote, string $table, array $cols, string $where, array $params): int
    {
        $list = '`' . implode('`, `', $cols) . '`';
        $st = DB::connection()->prepare("SELECT $list FROM `$table` WHERE $where");
        $st->execute($params);
        $n = 0; $batch = [];
        $flush = function () use (&$batch, $remote, $table, $list, $cols) {
            if (!$batch) {
                return;
            }
            $row = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $ins = $remote->prepare("INSERT INTO `$table` ($list) VALUES " . implode(', ', array_fill(0, count($batch), $row)));
            $ins->execute(array_merge(...$batch));
            $batch = [];
        };
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $batch[] = array_map(fn ($v) => is_bool($v) ? (int) $v : $v, $r);
            $n++;
            if (count($batch) >= self::BATCH) {
                $flush();
            }
        }
        $flush();
        return $n;
    }

    /** Synchronise une table. @return array{schema:bool, rows:int} */
    private static function syncTable(PDO $remote, string $table): array
    {
        $local = DB::connection();
        $create = (string) $local->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        $rc = null;
        try {
            $rc = (string) $remote->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        } catch (\PDOException) {
            // table absente en ligne
        }
        $schema = false;
        if ($rc === null || self::normalizeCreate($rc) !== self::normalizeCreate($create)) {
            $remote->exec("DROP TABLE IF EXISTS `$table`");
            $remote->exec($create);
            $schema = true;
        }
        $d = self::describe($table);
        $filter = self::FILTERS[$table] ?? '1';
        $rows = 0;
        $sum = fn (PDO $pdo, string $where, array $p) => (function () use ($pdo, $table, $d, $where, $p) {
            $st = $pdo->prepare(self::sumSql($table, $d['cols'], $where));
            $st->execute($p);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return (int) $r['n'] . ':' . (string) $r['s'];
        })();
        if ($d['pk'] === null) {
            if ($schema || $sum($local, $filter, []) !== $sum($remote, $filter, [])) {
                $remote->beginTransaction();
                $remote->exec("DELETE FROM `$table` WHERE $filter");
                $rows = self::copyRows($remote, $table, $d['cols'], $filter, []);
                $remote->commit();
            }
            return ['schema' => $schema, 'rows' => $rows];
        }
        $pk = $d['pk'];
        $max = (int) $local->query("SELECT COALESCE(MAX(`$pk`), -1) FROM `$table` WHERE $filter")->fetchColumn();
        $min = (int) $local->query("SELECT COALESCE(MIN(`$pk`), 0) FROM `$table` WHERE $filter")->fetchColumn();
        $rmin = (int) $remote->query("SELECT COALESCE(MIN(`$pk`), 0) FROM `$table` WHERE $filter")->fetchColumn();
        // Lignes en ligne hors de la plage locale : supprimées localement depuis la dernière fois
        $del = $remote->prepare("DELETE FROM `$table` WHERE $filter AND (`$pk` > ? OR `$pk` < ?)");
        $del->execute([$max, $min]);
        for ($a = min($min, $rmin); $a <= $max; $a += self::CHUNK) {
            $b = $a + self::CHUNK - 1;
            $w = "$filter AND `$pk` BETWEEN ? AND ?";
            if (!$schema && $sum($local, $w, [$a, $b]) === $sum($remote, $w, [$a, $b])) {
                continue;
            }
            $remote->beginTransaction();
            $x = $remote->prepare("DELETE FROM `$table` WHERE $w");
            $x->execute([$a, $b]);
            $rows += self::copyRows($remote, $table, $d['cols'], $w, [$a, $b]);
            $remote->commit();
        }
        return ['schema' => $schema, 'rows' => $rows];
    }

    /**
     * Exécute la synchronisation à partir de la table n° $from, pendant $budget secondes au plus.
     * @return array{next:?int, total:int, done:int, rows:int, tables:array<int,string>, finished:bool}
     */
    public static function run(int $from = 0, int $budget = 0, bool $confirmOverwrite = false): array
    {
        if (self::isOnline()) {
            throw new BusinessException('Cette installation est déclarée « en ligne » : la synchronisation part du poste local.');
        }
        $local = DB::connection();
        if (!(int) $local->query("SELECT GET_LOCK('posmarwa_sync', 0)")->fetchColumn()) {
            throw new BusinessException('Une synchronisation est déjà en cours.');
        }
        $start = microtime(true);
        try {
            $remote = self::remote();
            $me = LicenceService::installId();
            if ($from === 0) {
                $t = self::test();
                if ($t['foreign'] && !$confirmOverwrite && setting('sync_overwrite_ok', '') !== $me . '|' . setting('sync_db_host', '') . '|' . setting('sync_db_name', '')) {
                    throw new BusinessException('La base en ligne contient déjà ' . $t['tables'] . ' table(s) qui ne viennent pas de ce poste. La synchronisation les remplacerait : sauvegardez la base en ligne, puis cochez la confirmation.');
                }
                if ($confirmOverwrite) {
                    setting_set('sync_overwrite_ok', $me . '|' . setting('sync_db_host', '') . '|' . setting('sync_db_name', ''));
                    Audit::log('sync.overwrite_confirmed', null, null, ['host' => setting('sync_db_host', ''), 'database' => setting('sync_db_name', ''), 'tables' => $t['tables']]);
                }
                $remote->exec('CREATE TABLE IF NOT EXISTS `' . self::MARKER . '` (install_id VARCHAR(80) NOT NULL, started_at DATETIME NULL, finished_at DATETIME NULL, app_version VARCHAR(20) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                $remote->exec('DELETE FROM `' . self::MARKER . '`');
                $remote->prepare('INSERT INTO `' . self::MARKER . '` (install_id, started_at, app_version) VALUES (?, NOW(), ?)')->execute([$me, (string) config('app.version')]);
            }
            $tables = self::tables();
            $rows = 0; $done = []; $i = $from;
            for (; $i < count($tables); $i++) {
                if ($budget > 0 && $i > $from && microtime(true) - $start > $budget) {
                    break;
                }
                $r = self::syncTable($remote, $tables[$i]);
                $rows += $r['rows'];
                if ($r['rows'] || $r['schema']) {
                    $done[] = $tables[$i] . ($r['schema'] ? ' (structure)' : '') . ' : ' . $r['rows'];
                }
            }
            $finished = $i >= count($tables);
            if ($finished) {
                // Tables supprimées localement (module retiré) : retirées aussi en ligne
                foreach ($remote->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $t) {
                    if (!in_array($t[0], $tables, true) && !in_array($t[0], self::SKIP, true)) {
                        $remote->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $t[0]) . '`');
                    }
                }
                // La copie en ligne sait qu'elle est en ligne et qu'elle est alimentée par ce poste
                $up = $remote->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
                $up->execute(['hosting_mode', 'online']);
                $up->execute(['sync_replica_at', date('Y-m-d H:i:s')]);
                $remote->exec('UPDATE `' . self::MARKER . '` SET finished_at = NOW()');
                setting_set('sync_last_at', date('Y-m-d H:i:s'));
                setting_set('sync_last_result', 'ok');
                // (pas de ligne au journal d'audit à chaque passage : elle rendrait la copie différente de la source)
                \App\Core\Logger::info('Synchronisation en ligne terminée', ['tables' => count($tables), 'rows' => $rows]);
            }
            return ['next' => $finished ? null : $i, 'total' => count($tables), 'done' => $i, 'rows' => $rows, 'tables' => $done, 'finished' => $finished];
        } catch (\Throwable $e) {
            setting_set('sync_last_result', mb_substr($e->getMessage(), 0, 240));
            throw $e instanceof BusinessException ? $e : new BusinessException('Synchronisation interrompue : ' . $e->getMessage());
        } finally {
            $local->query("SELECT RELEASE_LOCK('posmarwa_sync')");
        }
    }

    /** Synchronisation automatique due ? (activée, intervalle écoulé) */
    public static function due(): bool
    {
        $c = self::config();
        return !self::isOnline() && $c['enabled'] && $c['auto_minutes'] > 0 && $c['has_password']
            && ($c['last_at'] === '' || strtotime($c['last_at']) < time() - $c['auto_minutes'] * 60);
    }
}
