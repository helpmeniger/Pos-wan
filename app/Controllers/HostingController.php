<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\BusinessException;
use App\Core\Request;
use App\Services\SyncService;

/** Configuration → Hébergement et synchronisation : en ligne ou en local, base MySQL en ligne. */
final class HostingController extends Controller
{
    public function index(): void
    {
        view('settings/hosting', ['title' => 'Hébergement et synchronisation', 'mode' => SyncService::hostingMode(), 'explicit' => in_array(setting('hosting_mode', ''), ['local', 'online'], true),
            'guess' => SyncService::guessOnline() ? 'online' : 'local', 'c' => SyncService::config(), 'tables' => count(SyncService::tables()), 'replicaAt' => (string) setting('sync_replica_at', ''),
            'scripts' => ['js/sync.js']]);
    }

    public function mode(): void
    {
        $this->attempt(fn () => SyncService::setHostingMode(Request::str('hosting_mode')), '/settings/hosting');
        flash('success', Request::str('hosting_mode') === 'online' ? 'POS Marwa est déclaré « en ligne » (serveur distant).' : 'POS Marwa est déclaré « en local » (serveur de la boutique).');
        redirect('/settings/hosting');
    }

    public function save(): void
    {
        $this->attempt(fn () => SyncService::saveConfig($_POST), '/settings/hosting');
        flash('success', 'Base de données en ligne enregistrée. Testez la connexion avant la première synchronisation.');
        redirect('/settings/hosting');
    }

    public function test(): void
    {
        $this->jsonAttempt(function () {
            $t = SyncService::test();
            return ['message' => 'Connexion réussie (MySQL ' . $t['version'] . '). ' . ($t['tables'] === 0 ? 'La base en ligne est vide : prête pour la première synchronisation.'
                : ($t['mine'] ? $t['tables'] . ' table(s) déjà synchronisées depuis ce poste.' : 'ATTENTION : elle contient déjà ' . $t['tables'] . ' table(s) qui ne viennent pas de ce poste ; elles seraient remplacées.')), 'foreign' => $t['foreign']];
        });
    }

    /** Une étape de synchronisation (quelques secondes) : l'écran rappelle jusqu'à la fin. */
    public function step(): void
    {
        @set_time_limit(300);
        $this->jsonAttempt(fn () => SyncService::run(max(0, Request::int('from')), 8, Request::str('confirm') === '1'));
    }

    /** Synchronisation automatique, déclenchée en arrière-plan par les pages de gestion quand elle est due. */
    public function tick(): void
    {
        $from = max(0, Request::int('from'));
        if ($from === 0) {
            if (!SyncService::due() || (int) setting('sync_last_try', '0') > time() - 300) {
                json_response(['ok' => true, 'idle' => true]);
            }
            setting_set('sync_last_try', (string) time());
        }
        @set_time_limit(300);
        try {
            json_response(['ok' => true] + SyncService::run($from, 6));
        } catch (BusinessException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()]); // pas d'Internet : nouvel essai plus tard
        }
    }
}
