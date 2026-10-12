<?php
declare(strict_types=1);

namespace Verifia;

/** Suppression des données : rétention automatique et effacement complet à la demande. */
final class DataPurge
{
    /** Publications sans alerte, plus anciennes que la durée de rétention. */
    public static function expired(): int
    {
        $days = max(1, Settings::int('retention_days', 90));
        $rows = Database::all(
            'SELECT p.id FROM detected_publications p LEFT JOIN alerts a ON a.publication_id = p.id
              WHERE a.id IS NULL AND p.last_seen_at < UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY LIMIT 10000'
        );
        return self::deletePublications(array_map('intval', array_column($rows, 'id')));
    }

    /** Effacement complet des données collectées (sessions, publications, alertes, rapports, profils navigateur). */
    public static function everything(?int $userId): array
    {
        $pdo = Database::pdo();
        $counts = [];
        foreach (['alert_history', 'reports', 'alerts', 'evidence_sources', 'image_matches', 'image_assets', 'claims', 'analysis_results',
                  'analysis_jobs', 'publication_occurrences', 'publication_content', 'detected_publications', 'session_commands',
                  'system_events', 'monitoring_sessions', 'monitored_sites'] as $t) {
            $counts[$t] = $pdo->exec("DELETE FROM $t");
        }
        foreach (['images', 'thumbs', 'reports', 'profiles'] as $d) {
            self::rrmdir(Config::storagePath($d), false);
        }
        EventLog::audit($userId, 'data_wipe', null, null, $counts);
        return $counts;
    }

    public static function deletePublications(array $ids): int
    {
        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $files = Database::all("SELECT file_path FROM image_assets WHERE publication_id IN ($in)", $chunk);
            $files = array_merge($files, Database::all("SELECT thumbnail_path AS file_path FROM publication_content WHERE publication_id IN ($in) AND thumbnail_path IS NOT NULL", $chunk));
            foreach (Database::all("SELECT media_json FROM publication_content WHERE publication_id IN ($in)", $chunk) as $m) {
                foreach (json_decode((string) $m['media_json'], true) ?: [] as $media) {
                    $files[] = ['file_path' => $media['path']];
                }
            }
            $n += Database::run("DELETE FROM detected_publications WHERE id IN ($in)", $chunk)->rowCount();
            foreach ($files as $f) {
                $p = Config::storagePath($f['file_path']);
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }
        return $n;
    }

    private static function rrmdir(string $dir, bool $self = true): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            if ($f->getFilename() === '.gitkeep') {
                continue;
            }
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        if ($self) {
            @rmdir($dir);
        }
    }
}
