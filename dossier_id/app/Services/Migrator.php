<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Applique les fichiers database/migrations/NNN_*.sql non encore appliqués. */
final class Migrator
{
    public static function files(): array
    {
        $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    public static function applied(): array
    {
        $exists = Database::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'");
        return $exists ? array_column(Database::all('SELECT version FROM schema_migrations'), 'version') : [];
    }

    public static function pending(): array
    {
        $done = array_flip(self::applied());
        return array_values(array_filter(self::files(), fn ($f) => !isset($done[basename($f, '.sql')])));
    }

    /** @return string[] versions appliquées */
    public static function migrate(?callable $log = null): array
    {
        $applied = [];
        foreach (self::pending() as $file) {
            $version = basename($file, '.sql');
            $log && $log("Migration {$version}…");
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                Database::pdo()->exec($sql);
            }
            Database::run('INSERT INTO schema_migrations (version, applied_at) VALUES (:v, NOW())', ['v' => $version]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** Découpe un script SQL en instructions (commentaires « -- » ignorés). */
    public static function statements(string $sql): array
    {
        $lines = array_filter(preg_split('/\R/', $sql) ?: [], fn ($l) => !preg_match('/^\s*--/', $l));
        $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
