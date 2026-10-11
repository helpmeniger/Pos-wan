<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Services\BackupService;
use App\Services\UploadService;
use App\Services\ValidationException;

final class BackupController extends Controller
{
    public function index(): string
    {
        Auth::require('backups.manage');
        return $this->view('backups/index', [
            'title' => 'Sauvegardes', 'backups' => BackupService::list(),
            'inspect' => Session::get('_backup_inspect'),
        ]);
    }

    public function create(): never
    {
        Auth::require('backups.manage');
        @set_time_limit(600);
        $name = BackupService::create();
        Audit::log('backup.create', null, null, ['fichier' => $name]);
        $this->back('backups.index', [], 'success', "Sauvegarde chiffrée créée : $name");
    }

    public function verify(): never
    {
        Auth::require('backups.manage');
        try {
            $m = BackupService::inspect(BackupService::pathFor($this->input('name')));
            Session::set('_backup_inspect', ['name' => $this->input('name'), 'ok' => true, 'manifest' => $m]);
            Session::flash('success', 'Sauvegarde vérifiée : déchiffrement et empreintes conformes.');
        } catch (ValidationException | \RuntimeException $e) {
            Session::set('_backup_inspect', ['name' => $this->input('name'), 'ok' => false, 'error' => $e->getMessage()]);
            Session::flash('danger', 'Sauvegarde invalide : ' . $e->getMessage());
        }
        $this->redirect('backups.index');
    }

    public function restore(): never
    {
        Auth::require('backups.manage');
        if ($this->input('confirm') !== 'RESTAURER') {
            $this->back('backups.index', [], 'danger', 'Saisissez RESTAURER pour confirmer la restauration.');
        }
        @set_time_limit(900);
        $user = Auth::user();
        $tmp = null;
        try {
            if (!empty($_FILES['backup_file']['name'])) {
                $up = UploadService::fromUpload($_FILES['backup_file']);
                $name = basename($up['name']);
                $path = $up['path'];
            } else {
                $name = $this->input('name');
                $path = BackupService::pathFor($name);
            }
            $result = BackupService::restore($path);
        } catch (ValidationException | \RuntimeException $e) {
            $this->back('backups.index', [], 'danger', 'Restauration refusée : ' . $e->getMessage());
        }
        BackupService::logRestore($name, $result, (int) $user['id'], (string) $user['username']);
        // Les comptes ont pu changer : reconnexion obligatoire.
        Auth::logout('auth.logout', 'Restauration effectuée (sauvegarde du ' . ($result['manifest']['created_at'] ?? '?')
            . '). Sauvegarde de sécurité préalable : ' . $result['safety'] . '. Veuillez vous reconnecter.');
        $this->redirect('auth.login');
    }

    public function download(): Response
    {
        Auth::require('backups.manage');
        $name = $this->query('name');
        try {
            $path = BackupService::pathFor($name);
        } catch (ValidationException $e) {
            $this->back('backups.index', [], 'danger', $e->getMessage());
        }
        Audit::log('backup.download', null, null, ['fichier' => $name]);
        return Response::file((string) file_get_contents($path), 'application/octet-stream', $name);
    }
}
