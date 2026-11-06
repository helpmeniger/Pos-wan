<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Addons;
use App\Core\DB;
use App\Core\Logger;

/**
 * Synchronisation de la structure de la base avec la version installée du logiciel.
 *
 * Les migrations numérotées appliquent les changements une fois. Mais une base peut rester
 * incomplète : migration marquée faite alors qu'elle a été interrompue, ancienne sauvegarde
 * restaurée, base copiée d'un autre poste, module installé avant une mise à jour, fichiers
 * remplacés à la main… Ce service lit la structure ATTENDUE (database/schema.sql, migrations du
 * logiciel et des modules installés), la compare à la base réelle et :
 *   - crée les tables manquantes ;
 *   - ajoute les colonnes manquantes ;
 *   - élargit une liste de valeurs (ENUM) quand la version installée en prévoit de nouvelles.
 * Rien n'est jamais supprimé ni renommé ; une sauvegarde est faite avant toute réparation.
 */
final class SchemaSync
{
    /** Fichiers SQL qui décrivent la structure attendue. */
    public static function sources(): array
    {
        $files = [BASE_PATH . '/database/schema.sql'];
        $mig = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($mig);
        $files = array_merge($files, $mig);
        try {
            $installed = array_keys(Addons::installed());
        } catch (\Throwable) {
            $installed = [];
        }
        foreach ($installed as $code) {
            $am = glob(Addons::dir() . '/' . $code . '/migrations/*.sql') ?: [];
            sort($am);
            $files = array_merge($files, $am);
        }
        return $files;
    }

    /** Découpe une liste SQL sur les virgules de premier niveau (hors parenthèses et guillemets). */
    public static function splitTop(string $s): array
    {
        $out = [];
        $depth = 0;
        $quote = null;
        $cur = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                $cur .= $c;
                if ($c === $quote && ($s[$i - 1] ?? '') !== '\\') {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        if (trim($cur) !== '') {
            $out[] = trim($cur);
        }
        return $out;
    }

    private static function isColumn(string $item): ?array
    {
        if (!preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+(.+)$/s', trim($item), $m)) {
            return null;
        }
        if (in_array(strtoupper($m[1]), ['PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN', 'FULLTEXT', 'SPATIAL', 'CHECK'], true)) {
            return null;
        }
        return [$m[1], trim($m[2])];
    }

    /** Structure attendue : [table => ['create' => SQL, 'columns' => [colonne => définition]]] */
    public static function expected(): array
    {
        $tables = [];
        foreach (self::sources() as $file) {
            // Commentaires SQL retirés, y compris ceux en fin de ligne (« colonne …, -- explication »)
            $sql = preg_replace(['/^\s*--.*$/m', '/(?<=\s)--(\s[^\n]*)?$/m', '#/\*.*?\*/#s'], '', (string) file_get_contents($file));
            foreach (preg_split('/;\s*$/m', (string) $sql) ?: [] as $stmt) {
                $stmt = trim($stmt);
                if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*)\)([^()]*)$/is', $stmt, $m)) {
                    $name = strtolower($m[1]);
                    $cols = [];
                    foreach (self::splitTop($m[2]) as $item) {
                        if ($c = self::isColumn($item)) {
                            $cols[strtolower($c[0])] = $c[1];
                        }
                    }
                    $create = preg_replace('/^CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $stmt);
                    if (!isset($tables[$name])) {
                        $tables[$name] = ['create' => $create, 'columns' => $cols, 'file' => basename($file)];
                    } else {
                        $tables[$name]['columns'] += $cols;
                    }
                } elseif (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $stmt, $m)) {
                    $name = strtolower($m[1]);
                    foreach (self::splitTop($m[2]) as $clause) {
                        if (preg_match('/^(ADD|MODIFY)(?:\s+COLUMN)?\s+(.+)$/is', $clause, $a) && ($c = self::isColumn($a[2]))) {
                            $def = trim((string) preg_replace('/\s+(AFTER\s+`?\w+`?|FIRST)\s*$/i', '', $c[1]));
                            if (isset($tables[$name])) {
                                $tables[$name]['columns'][strtolower($c[0])] = $def; // la dernière définition l'emporte
                            }
                        }
                    }
                }
            }
        }
        return $tables;
    }

    /** Écarts entre la structure attendue et la base. */
    public static function check(): array
    {
        $expected = self::expected();
        $have = [];
        foreach (DB::all('SELECT TABLE_NAME t, COLUMN_NAME c, COLUMN_TYPE ty FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $have[strtolower($r['t'])][strtolower($r['c'])] = (string) $r['ty'];
        }
        $missingTables = [];
        $missingColumns = [];
        $enums = [];
        foreach ($expected as $t => $info) {
            if (!isset($have[$t])) {
                $missingTables[] = $t;
                continue;
            }
            foreach ($info['columns'] as $c => $def) {
                if (!isset($have[$t][$c])) {
                    $missingColumns[] = ['table' => $t, 'column' => $c, 'definition' => $def];
                } elseif (preg_match('/^ENUM\s*\((.*?)\)/is', $def, $e) && str_starts_with(strtolower($have[$t][$c]), 'enum(')) {
                    $want = array_map(fn($v) => trim($v, " '\""), self::splitTop($e[1]));
                    $cur = array_map(fn($v) => trim($v, " '\""), self::splitTop(substr($have[$t][$c], 5, -1)));
                    // Élargissement seulement : toutes les valeurs actuelles restent permises
                    if (array_diff($want, $cur) && !array_diff($cur, $want)) {
                        $enums[] = ['table' => $t, 'column' => $c, 'definition' => $def, 'added' => array_values(array_diff($want, $cur))];
                    }
                }
            }
        }
        return ['tables' => count($expected), 'missing_tables' => $missingTables, 'missing_columns' => $missingColumns, 'enums' => $enums,
            'pending_migrations' => InstallService::pendingMigrations(), 'ok' => !$missingTables && !$missingColumns && !$enums];
    }

    /** Répare la base (sauvegarde d'abord). @return array rapport */
    public static function repair(bool $backup = true): array
    {
        $before = self::check();
        $report = ['at' => date('Y-m-d H:i:s'), 'created' => [], 'columns' => [], 'enums' => [], 'errors' => [], 'migrations' => [], 'backup' => null];
        if ($before['pending_migrations']) {
            $report['migrations'] = InstallService::migrate();
            $before = self::check();
        }
        if ($before['ok']) {
            self::saveReport($report);
            return $report;
        }
        if ($backup) {
            $report['backup'] = BackupService::create('avant_synchro_base')['sql'] ?? null;
        }
        $expected = self::expected();
        $pdo = DB::connection();
        $pdo->exec('SET SESSION foreign_key_checks = 0');
        try {
            foreach ($before['missing_tables'] as $t) {
                try {
                    $pdo->exec($expected[$t]['create']);
                    $report['created'][] = $t;
                } catch (\PDOException $e) {
                    $report['errors'][] = "Table $t : " . $e->getMessage();
                }
            }
            // Colonnes : celles des tables tout juste créées (ajoutées par des migrations ultérieures) incluses
            foreach (self::check()['missing_columns'] as $mc) {
                try {
                    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $mc['table'], $mc['column'], $mc['definition']));
                    $report['columns'][] = $mc['table'] . '.' . $mc['column'];
                } catch (\PDOException $e) {
                    $report['errors'][] = "Colonne {$mc['table']}.{$mc['column']} : " . $e->getMessage();
                }
            }
            foreach (self::check()['enums'] as $en) {
                try {
                    $pdo->exec(sprintf('ALTER TABLE `%s` MODIFY `%s` %s', $en['table'], $en['column'], $en['definition']));
                    $report['enums'][] = $en['table'] . '.' . $en['column'] . ' (+' . implode(', ', $en['added']) . ')';
                } catch (\PDOException $e) {
                    $report['errors'][] = "Valeurs de {$en['table']}.{$en['column']} : " . $e->getMessage();
                }
            }
        } finally {
            $pdo->exec('SET SESSION foreign_key_checks = 1');
        }
        InstallService::seedPermissions();
        self::saveReport($report);
        Logger::info('Synchronisation de la base', $report);
        \App\Core\Audit::log('system.schema_sync', null, null, ['created' => $report['created'], 'columns' => $report['columns'], 'enums' => $report['enums'], 'errors' => count($report['errors'])]);
        return $report;
    }

    private static function saveReport(array $r): void
    {
        setting_set('schema_sync_report', (string) json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public static function lastReport(): ?array
    {
        $r = json_decode((string) setting('schema_sync_report', ''), true);
        return is_array($r) ? $r : null;
    }

    /** Empreinte des fichiers de structure (version + taille + date de chaque fichier). */
    public static function signature(): string
    {
        $parts = [(string) config('app.version')];
        foreach (self::sources() as $f) {
            $parts[] = basename(dirname(dirname($f))) . '/' . basename($f) . ':' . @filesize($f) . ':' . @filemtime($f);
        }
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Après chaque mise à jour (fichiers changés, nouvelle version, module installé) : contrôle
     * automatique et réparation si nécessaire. Ensuite plus rien jusqu'au prochain changement.
     */
    public static function auto(): ?array
    {
        if (!InstallService::isInstalled()) {
            return null;
        }
        $flag = STORAGE_PATH . '/cache/schema_sync';
        $sig = self::signature();
        if (is_file($flag) && trim((string) file_get_contents($flag)) === $sig) {
            return null;
        }
        if ((int) DB::value("SELECT GET_LOCK(CONCAT('poswan_schema:', DATABASE()), 20)") !== 1) {
            return null;
        }
        try {
            $r = self::check()['ok'] ? null : self::repair();
            if ($r === null || !$r['errors']) {
                @file_put_contents($flag, $sig);
            }
            return $r;
        } catch (\Throwable $e) {
            Logger::error('Synchronisation automatique de la base impossible : ' . $e->getMessage());
            return null;
        } finally {
            DB::value("SELECT RELEASE_LOCK(CONCAT('poswan_schema:', DATABASE()))");
        }
    }
}
