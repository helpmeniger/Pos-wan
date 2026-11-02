<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\MikroTik\RouterService;
use Throwable;

/**
 * Gestion des routeurs MikroTik (connexion réelle via l'API RouterOS).
 */
final class RouterController extends Controller
{
    private const LABELS = [
        'name' => 'Nom', 'host' => 'Adresse IP / hôte', 'api_port' => 'Port API', 'api_ssl_port' => 'Port API-SSL',
        'api_username' => 'Utilisateur API', 'api_password' => 'Mot de passe API', 'connection_type' => 'Type de connexion',
        'sync_interval_minutes' => 'Intervalle de synchronisation',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('routers.view');
        $routers = Database::fetchAll(
            "SELECT r.*, s.name AS site_name,
                    (SELECT COUNT(*) FROM tickets t WHERE t.router_id = r.id AND t.status = 'available' AND t.on_router = 1) AS stock
               FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE r.deleted_at IS NULL ORDER BY r.is_default DESC, r.name"
        );
        return $this->view('routers/index', compact('routers'));
    }

    public function create(Request $request): Response
    {
        $this->authorize('routers.manage');
        return $this->view('routers/form', ['router' => null, 'sites' => $this->sites()]);
    }

    private function sites(): array
    {
        return Database::fetchAll("SELECT id, name FROM sites WHERE status = 'active' ORDER BY name");
    }

    private function rules(bool $creating): array
    {
        return [
            'name' => 'required|max:120',
            'host' => 'required|ip_or_host|max:253',
            'api_port' => 'required|port',
            'api_ssl_port' => 'required|port',
            'api_username' => 'required|max:100',
            'api_password' => ($creating ? 'required|' : '') . 'max:255',
            'connection_type' => 'required|in:lan,public_ip,vpn,cloud,other',
            'model' => 'max:100', 'location' => 'max:255', 'hotspot_server' => 'max:100', 'hotspot_dns_name' => 'max:190',
            'wifi_ssid' => 'max:100', 'wan_interface' => 'max:100', 'new_site' => 'max:150',
            'sync_interval_minutes' => 'required|integer',
            'site_id' => 'integer',
        ];
    }

    private function payload(Request $request, array $data): array
    {
        $siteId = (int) ($data['site_id'] ?? 0) ?: null;
        if (!empty($data['new_site'])) {
            $siteId = Database::insert('sites', ['name' => $data['new_site']]);
        }
        $out = [
            'site_id' => $siteId, 'name' => $data['name'], 'host' => $data['host'],
            'api_port' => (int) $data['api_port'], 'api_ssl_port' => (int) $data['api_ssl_port'],
            'use_ssl' => $request->input('use_ssl') ? 1 : 0, 'api_username' => $data['api_username'],
            'model' => $data['model'] ?: null, 'location' => $data['location'] ?: null,
            'connection_type' => $data['connection_type'], 'hotspot_server' => $data['hotspot_server'] ?: null,
            'hotspot_dns_name' => $data['hotspot_dns_name'] ?: null, 'wifi_ssid' => $data['wifi_ssid'] ?: null,
            'wan_interface' => $data['wan_interface'] ?: null,
            'status' => $request->input('status') === 'active' ? 'active' : 'inactive',
            'is_default' => $request->input('is_default') ? 1 : 0,
            'sync_enabled' => $request->input('sync_enabled') ? 1 : 0,
            'sync_interval_minutes' => max(1, min(1440, (int) $data['sync_interval_minutes'])),
        ];
        if ((string) $data['api_password'] !== '') {
            $out['api_password_enc'] = Crypto::encrypt((string) $data['api_password']);
        }
        return $out;
    }

    private function assertUniqueEndpoint(string $host, int $port, int $ignoreId = 0): void
    {
        if (Database::value('SELECT id FROM routers WHERE host = :h AND api_port = :p AND deleted_at IS NULL AND id <> :i', ['h' => $host, 'p' => $port, 'i' => $ignoreId])) {
            Session::flashErrors(['host' => 'Un routeur avec cette adresse et ce port existe déjà.']);
            throw new ValidationFailed([], Response::redirect($ignoreId ? '/routers/' . $ignoreId . '/edit' : '/routers/create'));
        }
    }

    public function store(Request $request): Response
    {
        $this->authorize('routers.manage');
        $data = $this->validate($request, $this->rules(true), self::LABELS);
        Session::flashOld($request->all());
        $this->assertUniqueEndpoint($data['host'], (int) $data['api_port']);
        $payload = $this->payload($request, $data) + ['created_by' => Auth::id()];
        $id = Database::transaction(static function () use ($payload) {
            if ($payload['is_default']) {
                Database::query('UPDATE routers SET is_default = 0');
            }
            return Database::insert('routers', $payload);
        });
        AuditService::log('router.create', 'router', $id, "Ajout du routeur {$payload['name']} ({$payload['host']})");
        $router = RouterService::find($id);
        $status = RouterService::checkStatus($router);
        if ($status['online']) {
            try {
                RouterService::syncInterfaces($router);
                RouterService::syncProfiles($router);
            } catch (Throwable) {
            }
            Session::flash('success', "Routeur ajouté et connecté : {$status['identity']} — RouterOS {$status['version']}.");
        } else {
            Session::flash('warning', 'Routeur enregistré mais injoignable pour le moment : ' . $status['error']);
        }
        Session::flashOld([]);
        return $this->redirect('/routers/' . $id);
    }

    public function edit(Request $request, string $id): Response
    {
        $this->authorize('routers.manage');
        $router = RouterService::find((int) $id);
        $this->notFoundUnless($router, 'Routeur introuvable.');
        return $this->view('routers/form', ['router' => $router, 'sites' => $this->sites()]);
    }

    public function update(Request $request, string $id): Response
    {
        $this->authorize('routers.manage');
        $router = RouterService::find((int) $id);
        $this->notFoundUnless($router, 'Routeur introuvable.');
        $data = $this->validate($request, $this->rules(false), self::LABELS);
        $this->assertUniqueEndpoint($data['host'], (int) $data['api_port'], (int) $router['id']);
        $payload = $this->payload($request, $data);
        Database::transaction(static function () use ($payload, $router) {
            if ($payload['is_default']) {
                Database::query('UPDATE routers SET is_default = 0');
            }
            Database::update('routers', $payload, 'id = :id', ['id' => $router['id']]);
        });
        [$old, $new] = AuditService::diff($router, $payload);
        AuditService::log('router.update', 'router', (int) $router['id'], "Modification du routeur {$router['name']}", $old, $new);
        Session::flash('success', 'Routeur mis à jour.');
        return $this->redirect('/routers/' . $router['id']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->authorize('routers.manage');
        $router = RouterService::find((int) $id);
        $this->notFoundUnless($router, 'Routeur introuvable.');
        Database::update('routers', ['deleted_at' => date('Y-m-d H:i:s'), 'status' => 'inactive', 'is_default' => 0], 'id = :id', ['id' => $router['id']]);
        AuditService::log('router.delete', 'router', (int) $router['id'], "Suppression du routeur {$router['name']} (historique conservé)");
        Session::flash('success', 'Routeur retiré. Ses ventes et tickets restent dans l\'historique.');
        return $this->redirect('/routers');
    }

    public function show(Request $request, string $id): Response
    {
        $this->authorize('routers.view');
        $router = Database::fetch('SELECT r.*, s.name AS site_name FROM routers r LEFT JOIN sites s ON s.id = r.site_id WHERE r.id = :id AND r.deleted_at IS NULL', ['id' => (int) $id]);
        $this->notFoundUnless($router, 'Routeur introuvable.');
        $tab = $request->str('tab', 'overview');
        $data = ['router' => $router, 'tab' => $tab, 'live' => null, 'liveError' => null];
        $data['interfaces'] = Database::fetchAll('SELECT * FROM router_interfaces WHERE router_id = :r ORDER BY name', ['r' => $router['id']]);
        $data['profiles'] = Database::fetchAll('SELECT * FROM hotspot_profiles WHERE router_id = :r ORDER BY name', ['r' => $router['id']]);
        $data['syncs'] = Database::fetchAll('SELECT * FROM router_sync_logs WHERE router_id = :r ORDER BY id DESC LIMIT 15', ['r' => $router['id']]);
        $data['ticketStats'] = array_column(Database::fetchAll('SELECT status, COUNT(*) n FROM tickets WHERE router_id = :r GROUP BY status', ['r' => $router['id']]), 'n', 'status');
        try {
            if ($router['status'] === 'active') {
                $data['live'] = match ($tab) {
                    'sessions' => RouterService::activeSessions($router),
                    'users' => RouterService::hotspotUsers($router),
                    'logs' => RouterService::logs($router, 150),
                    'overview' => RouterService::info($router),
                    default => null,
                };
            }
        } catch (Throwable $e) {
            RouterService::close((int) $router['id']);
            $data['liveError'] = $e->getMessage();
        }
        return $this->view('routers/show', $data);
    }

    /** Test de connexion (formulaire ou routeur existant). Réponse JSON. */
    public function test(Request $request): Response
    {
        $this->authorize('routers.manage');
        $id = $request->int('router_id');
        $password = (string) $request->input('api_password', '');
        if ($id && $password === '') {
            $r = RouterService::find($id);
            $password = $r ? Crypto::decrypt($r['api_password_enc']) : '';
        }
        $ssl = (bool) $request->input('use_ssl');
        $port = $ssl ? $request->int('api_ssl_port', 8729) : $request->int('api_port', 8728);
        try {
            $info = RouterService::testCredentials($request->str('host'), $port, $ssl, $request->str('api_username'), $password);
            return $this->json(['success' => true, 'message' => "Connexion réussie à « {$info['identity']} » ({$info['model']}, RouterOS {$info['version']}) en {$info['latency_ms']} ms.", 'info' => $info]);
        } catch (Throwable $e) {
            return $this->json(['success' => false, 'message' => 'Échec : ' . $e->getMessage()], 422);
        }
    }

    public function sync(Request $request, string $id): Response
    {
        $this->authorize('routers.sync', 'routers.manage');
        $router = RouterService::find((int) $id);
        $this->notFoundUnless($router);
        $r = \App\Services\SyncService::syncRouter($router, Auth::id(), 'manual');
        Session::flash($r['ok'] ? 'success' : 'danger', ($r['ok'] ? 'Synchronisation terminée : ' : 'Synchronisation impossible : ') . $r['message']);
        return $this->back($request, '/routers/' . $id);
    }

    public function reboot(Request $request, string $id): Response
    {
        $this->authorize('routers.reboot');
        $router = RouterService::find((int) $id);
        $this->notFoundUnless($router);
        if ($request->str('confirm_name') !== $router['name']) {
            Session::flash('danger', 'Redémarrage annulé : le nom saisi ne correspond pas.');
            return $this->redirect('/routers/' . $id);
        }
        try {
            RouterService::reboot($router);
            Database::update('routers', ['connection_state' => 'unknown'], 'id = :id', ['id' => $router['id']]);
            AuditService::log('router.reboot', 'router', (int) $router['id'], "Redémarrage du routeur {$router['name']}");
            Session::flash('success', 'Commande de redémarrage envoyée. Le routeur sera de nouveau joignable dans 1 à 2 minutes.');
        } catch (Throwable $e) {
            Session::flash('danger', 'Redémarrage impossible : ' . $e->getMessage());
        }
        return $this->redirect('/routers/' . $id);
    }

    public function disconnect(Request $request, string $id): Response
    {
        $this->authorize('hotspot.manage');
        $router = RouterService::find((int) $id);
        $this->notFoundUnless($router);
        try {
            RouterService::disconnectActive($router, $request->str('active_id'));
            AuditService::log('hotspot.disconnect', 'router', (int) $router['id'], 'Déconnexion de ' . $request->str('user'));
            Session::flash('success', 'Utilisateur déconnecté.');
        } catch (Throwable $e) {
            Session::flash('danger', $e->getMessage());
        }
        return $this->back($request, '/routers/' . $id . '?tab=sessions');
    }
}
