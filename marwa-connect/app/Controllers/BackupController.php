<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\BackupService;
use Throwable;

final class BackupController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('backups.manage');
        $backups = Database::fetchAll('SELECT b.*, u.full_name FROM backups b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC');
        foreach ($backups as &$b) {
            $b['exists'] = is_file(BackupService::dir() . '/' . basename($b['filename']));
        }
        $free = @disk_free_space(BackupService::dir());
        return $this->view('backups/index', compact('backups', 'free'));
    }

    public function create(Request $request): Response
    {
        $this->authorize('backups.manage');
        @set_time_limit(600);
        try {
            $r = BackupService::create('manual', (int) Auth::id());
            Session::flash('success', 'Sauvegarde créée : ' . basename($r['file']) . ' (' . bytes_human($r['size']) . ').');
        } catch (Throwable $e) {
            Session::flash('danger', 'Échec de la sauvegarde : ' . $e->getMessage());
        }
        return $this->redirect('/admin/backups');
    }

    public function download(Request $request, string $id): Response
    {
        $this->authorize('backups.manage');
        $b = Database::fetch('SELECT * FROM backups WHERE id = :id', ['id' => (int) $id]);
        $file = $b ? BackupService::dir() . '/' . basename($b['filename']) : '';
        $this->notFoundUnless($b && is_file($file), 'Fichier de sauvegarde introuvable.');
        AuditService::log('backup.download', 'backup', (int) $b['id'], 'Téléchargement de ' . $b['filename']);
        return Response::download($file, $b['filename'], 'application/gzip');
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->authorize('backups.manage');
        $b = Database::fetch('SELECT * FROM backups WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($b);
        @unlink(BackupService::dir() . '/' . basename($b['filename']));
        Database::query('DELETE FROM backups WHERE id = :id', ['id' => $b['id']]);
        AuditService::log('backup.delete', 'backup', (int) $b['id'], 'Suppression de ' . $b['filename']);
        Session::flash('success', 'Sauvegarde supprimée.');
        return $this->redirect('/admin/backups');
    }
}
