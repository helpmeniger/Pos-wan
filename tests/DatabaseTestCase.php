<?php
declare(strict_types=1);

namespace Verifia\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Verifia\Config;
use Verifia\Database;
use Verifia\Settings;

/** Recrée une base de test vierge avant chaque test (schéma réel de database/schema.sql). */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $schemaLoaded = false;

    protected function setUp(): void
    {
        $dsn = (string) Config::get('db.dsn');
        preg_match('/dbname=([A-Za-z0-9_]+)/', $dsn, $m);
        if (!str_contains($m[1], 'test')) {
            $this->fail('Par sécurité, la base de test doit contenir « test » dans son nom.');
        }
        Database::reset();
        Settings::clearCache();
        if (!self::$schemaLoaded) {
            $server = new PDO(preg_replace('/dbname=[^;]+;?/', '', $dsn), (string) Config::get('db.user'), (string) Config::get('db.password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $server->exec("DROP DATABASE IF EXISTS `{$m[1]}`");
            $server->exec("CREATE DATABASE `{$m[1]}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $server->exec("USE `{$m[1]}`");
            foreach (preg_split('/;\s*$/m', (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql')) as $stmt) {
                $stmt = trim(preg_replace('/^--.*$/m', '', $stmt));
                if ($stmt !== '') {
                    $server->exec($stmt);
                }
            }
            self::$schemaLoaded = true;
        }
        // Vide toutes les tables de données (plus rapide que recréer la base)
        $pdo = Database::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['alert_history', 'reports', 'alerts', 'evidence_sources', 'image_matches', 'image_assets', 'claims', 'analysis_results', 'analysis_jobs',
                  'publication_occurrences', 'publication_content', 'detected_publications', 'session_commands', 'system_events', 'monitoring_sessions',
                  'monitored_sites', 'audit_logs', 'users', 'service_heartbeats'] as $t) {
            $pdo->exec("TRUNCATE TABLE $t");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->exec("UPDATE application_settings SET setting_value = '0' WHERE setting_key = 'emergency_stop'");
        $pdo->exec("UPDATE application_settings SET setting_value = 'high' WHERE setting_key = 'alert_min_priority'");
        $pdo->exec("UPDATE application_settings SET setting_value = '4' WHERE setting_key = 'max_concurrent_sessions'");
    }

    /** Image PNG de test (motif déterministe) encodée en base64. */
    protected static function pngBase64(int $seed, int $w = 320, int $h = 240): string
    {
        $img = imagecreatetruecolor($w, $h);
        mt_srand($seed);
        for ($i = 0; $i < 25; $i++) {
            $c = imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($img, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), $c);
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return base64_encode((string) ob_get_clean());
    }

    /** Même image, redimensionnée et recompressée en JPEG (simule une republication). */
    protected static function resizedJpegBase64(string $pngB64, int $w, int $h): string
    {
        $src = imagecreatefromstring(base64_decode($pngB64));
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        ob_start();
        imagejpeg($dst, null, 70);
        return base64_encode((string) ob_get_clean());
    }
}
