<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/** Rétention des médias, surveillance du disque, rapports automatiques, sauvegardes. */
final class MaintenanceService
{
    public static function purgeExpired(): array
    {
        $rows = Database::fetchAll('SELECT id, file_path, kind FROM video_records WHERE deleted_at IS NULL AND is_protected = 0 AND expires_at < NOW() LIMIT 2000');
        $files = 0;
        foreach ($rows as $r) {
            $files += self::deletePath(STORAGE_PATH . '/' . $r['file_path']);
            Database::update('video_records', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $r['id']]);
        }
        $days = (int) Settings::get('retention.detections_days', 90);
        $det = Database::execute('DELETE FROM detection_results WHERE detected_at < DATE_SUB(NOW(), INTERVAL ' . max(7, $days) . ' DAY) LIMIT 50000');
        Database::execute('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
        // fichiers de cache temporaires (> 1 jour)
        foreach (glob(STORAGE_PATH . '/cache/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400 && basename($f) !== '.gitkeep') {
                @unlink($f);
            }
        }
        return ['media_records' => count($rows), 'files_deleted' => $files, 'detections_deleted' => $det];
    }

    private static function deletePath(string $path): int
    {
        $real = realpath($path);
        $root = realpath(STORAGE_PATH);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return 0;
        }
        if (is_file($real)) {
            return @unlink($real) ? 1 : 0;
        }
        $n = 0;
        if (is_dir($real)) {
            foreach (glob($real . '/*') ?: [] as $f) {
                $n += is_file($f) && @unlink($f) ? 1 : 0;
            }
            @rmdir($real);
        }
        return $n;
    }

    public static function diskStatus(): array
    {
        $free = (float) @disk_free_space(STORAGE_PATH);
        $total = (float) @disk_total_space(STORAGE_PATH);
        $media = (float) Database::value('SELECT COALESCE(SUM(size_bytes),0) FROM video_records WHERE deleted_at IS NULL');
        $minFree = (float) Settings::get('storage.min_free_gb', 5) * 1e9;
        $maxMedia = (float) Settings::get('storage.max_media_gb', 50) * 1e9;
        return ['free' => $free, 'total' => $total, 'media' => $media, 'low' => $free > 0 && $free < $minFree, 'media_over' => $media > $maxMedia];
    }

    /** Si le quota média est dépassé, supprime les plus anciens médias non protégés. */
    public static function enforceQuota(): int
    {
        $status = self::diskStatus();
        $deleted = 0;
        if (!$status['media_over'] && !$status['low']) {
            return 0;
        }
        $rows = Database::fetchAll('SELECT id, file_path FROM video_records WHERE deleted_at IS NULL AND is_protected = 0 ORDER BY started_at ASC LIMIT 500');
        foreach ($rows as $r) {
            self::deletePath(STORAGE_PATH . '/' . $r['file_path']);
            Database::update('video_records', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $r['id']]);
            $deleted++;
        }
        return $deleted;
    }

    /** Tâche planifiée (scripts\maintenance.bat via le Planificateur de tâches Windows). */
    public static function runScheduled(): array
    {
        $out = ['purge' => self::purgeExpired(), 'quota_deleted' => self::enforceQuota()];
        $disk = self::diskStatus();
        if ($disk['low']) {
            NotificationService::notify(null, 'disque', 'Espace disque insuffisant', 'danger',
                'Espace libre : ' . human_bytes($disk['free']), 'maintenance', 'disk_low', 360);
        }
        $health = AiClient::health();
        if (!$health['ok']) {
            NotificationService::notify(null, 'service_ia', 'Service IA indisponible', 'danger', (string) $health['error'], 'maintenance', 'ai_down', 60);
        }
        if (Settings::get('reports.auto_generate', true)) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            foreach (Database::fetchAll('SELECT id FROM stores WHERE is_active = 1') as $s) {
                if (!Database::value('SELECT 1 FROM daily_reports WHERE store_id = :s AND report_date = :d', ['s' => $s['id'], 'd' => $yesterday])) {
                    ReportService::generateDaily((int) $s['id'], $yesterday);
                    $out['reports'][] = $s['id'];
                }
            }
        }
        Logger::info('Maintenance planifiée exécutée', $out);
        return $out;
    }
}
