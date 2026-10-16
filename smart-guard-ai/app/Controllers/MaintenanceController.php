<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiClient;
use App\Services\BackupService;
use App\Services\MaintenanceService;

final class MaintenanceController extends Controller
{
    public function index(Request $r): void
    {
        $checks = [
            ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')],
            ['Extension pdo_mysql', extension_loaded('pdo_mysql')], ['Extension gd', extension_loaded('gd')],
            ['Extension sodium (chiffrement)', extension_loaded('sodium')], ['Extension curl', extension_loaded('curl')],
            ['Extension zip (export Excel)', class_exists(\ZipArchive::class)], ['Extension fileinfo', extension_loaded('fileinfo')],
            ['Dossier storage accessible en écriture', is_writable(STORAGE_PATH)],
            ['Fichier .env présent', is_file(BASE_PATH . '/.env')],
        ];
        $audit = Auth::can('audit.view') ? Database::fetchAll('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 100') : [];
        $logins = Auth::can('audit.view') ? Database::fetchAll('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 30') : [];
        $logFile = STORAGE_PATH . '/logs/app-' . date('Y-m-d') . '.log';
        $tail = is_file($logFile) ? array_slice(file($logFile, FILE_IGNORE_NEW_LINES) ?: [], -40) : [];
        $this->view('maintenance/index', ['title' => 'Maintenance', 'checks' => $checks, 'health' => AiClient::health(), 'disk' => MaintenanceService::diskStatus(),
            'backups' => Database::fetchAll('SELECT * FROM backup_logs ORDER BY id DESC LIMIT 20'), 'audit' => $audit, 'logins' => $logins, 'tail' => $tail,
            'media' => Database::fetch('SELECT COUNT(*) n, COALESCE(SUM(size_bytes),0) s, SUM(is_protected) p FROM video_records WHERE deleted_at IS NULL')]);
    }

    public function backup(Request $r): void
    {
        $res = BackupService::backup('manuel', Auth::id());
        Audit::log('sauvegarde', 'backup', null, $res);
        $this->back($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Sauvegarde créée : ' . $res['file'] . ' (' . human_bytes($res['size']) . ')' : 'Échec : ' . $res['error'], 'maintenance');
    }

    public function purge(Request $r): void
    {
        $res = MaintenanceService::purgeExpired();
        Audit::log('purge_medias', 'maintenance', null, $res);
        $this->back('success', "{$res['media_records']} média(s) expiré(s) supprimé(s), {$res['detections_deleted']} détection(s) anciennes purgées.", 'maintenance');
    }

    public function downloadBackup(Request $r): void
    {
        $b = Database::fetch("SELECT * FROM backup_logs WHERE id = :id AND status = 'succes' AND file_path IS NOT NULL", ['id' => (int) $r->param('id')]);
        $file = $b ? realpath(STORAGE_PATH . '/' . $b['file_path']) : false;
        if (!$file || !str_starts_with($file, realpath(STORAGE_PATH . '/backups'))) {
            throw new HttpException(404, 'Sauvegarde introuvable.');
        }
        Audit::log('sauvegarde_telechargee', 'backup', $b['id']);
        Response::file($file, 'application/gzip', basename($file));
    }
}
