<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Request;
use App\Services\BackupService;

final class BackupController extends Controller
{
    public function index(): void
    {
        $supports = \App\Services\SupportExterne::list();
        $found = [];
        foreach ($supports as $s) {
            try {
                $found[$s['racine']] = \App\Services\SupportExterne::found($s['racine']);
            } catch (\Throwable) {
                $found[$s['racine']] = [];
            }
        }
        view('backups/index', ['title' => 'Sauvegardes & restauration', 'backups' => BackupService::list(), 'dir' => BackupService::dir(),
            'supports' => $supports, 'found' => $found]);
    }

    /** Copie de la dernière sauvegarde (ou de toutes) sur une clé USB ou un disque externe. */
    public function copyToSupport(): void
    {
        @set_time_limit(900);
        $r = $this->attempt(fn() => \App\Services\SupportExterne::copy(Request::str('support'), Request::str('quoi') === 'toutes', Request::str('nouvelle') === '1'), '/backups');
        flash('success', $r['fichiers'] . ' fichier(s) copié(s) et contrôlé(s) (' . round($r['octets'] / 1048576, 1) . ' Mo) dans « ' . $r['dossier'] . ' ». Vous pouvez retirer le support en toute sécurité depuis Windows.');
        redirect('/backups');
    }

    /** Reprise d'une sauvegarde du support dans la liste (sans la restaurer). */
    public function importFromSupport(): void
    {
        $name = $this->attempt(fn() => \App\Services\SupportExterne::import(Request::str('support'), Request::str('fichier')), '/backups');
        flash('success', 'Sauvegarde « ' . $name . ' » reprise depuis le support et contrôlée. Pour l\'utiliser, cliquez sur « Restaurer » dans la liste.');
        redirect('/backups');
    }

    public function create(): void
    {
        $r = BackupService::create('manuel');
        flash('success', "Sauvegarde créée : {$r['sql']} ({$r['tables']} tables, {$r['rows']} lignes).");
        redirect('/backups');
    }

    /** Téléchargement en POST (jeton CSRF) : les archives ne sont jamais accessibles par une URL publique. */
    public function download(): void
    {
        $name = Request::str('name');
        $path = $this->attempt(fn() => BackupService::path($name), '/backups');
        Audit::log('backup.download', null, null, ['file' => $name]);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function restore(): void
    {
        $u = $this->user();
        // Re-saisie du mot de passe administrateur pour cette opération critique
        if (!password_verify((string) Request::input('password', ''), $u['password_hash'])) {
            Audit::log('backup.restore_denied', null, null, ['reason' => 'bad_password']);
            $this->fail('Mot de passe incorrect : restauration refusée.', '/backups');
        }
        if (Request::str('confirm') !== 'RESTAURER') {
            $this->fail('Tapez RESTAURER pour confirmer.', '/backups');
        }
        $r = $this->attempt(fn() => BackupService::restore(Request::str('name')), '/backups');
        flash('success', "Restauration terminée ({$r['statements']} instructions). Sauvegarde de sécurité préalable : {$r['safety']}. Reconnectez-vous si nécessaire.");
        redirect('/backups');
    }
}
