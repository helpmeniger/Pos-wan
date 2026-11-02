<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuditService;
use App\Services\MikroTik\RouterService;
use App\Services\TicketService;
use Throwable;

/**
 * Offres Internet et profils Hotspot associés.
 */
final class OfferController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('offers.view');
        $status = $request->str('status');
        $where = $status !== '' ? 'WHERE o.status = :s' : "WHERE o.status <> 'archived'";
        $offers = Database::fetchAll(
            "SELECT o.*,
                (SELECT COUNT(*) FROM tickets t WHERE t.offer_id = o.id AND t.status = 'available' AND t.on_router = 1 AND t.reseller_id IS NULL) AS stock,
                (SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.offer_id = o.id AND s.status = 'completed') AS sold,
                (SELECT GROUP_CONCAT(r.name SEPARATOR ', ') FROM offer_router orr JOIN routers r ON r.id = orr.router_id AND r.deleted_at IS NULL WHERE orr.offer_id = o.id) AS routers
               FROM offers o {$where} ORDER BY o.sort_order, o.price",
            $status !== '' ? ['s' => $status] : []
        );
        return $this->view('offers/index', compact('offers', 'status'));
    }

    public function create(Request $request): Response
    {
        $this->authorize('offers.manage');
        return $this->view('offers/form', ['offer' => null, 'routers' => $this->routers(), 'linked' => []]);
    }

    private function routers(): array
    {
        return Database::fetchAll("SELECT id, name, connection_state FROM routers WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $d = $this->validate($request, [
            'name' => 'required|max:120',
            'code' => 'required|regex:/^[A-Za-z0-9_-]+$/|max:40|unique:offers,code' . ($id ? ',' . $id : ''),
            'description' => 'max:500',
            'price' => 'required|numeric',
            'validity_value' => 'integer', 'validity_unit' => 'in:minutes,hours,days,weeks,months',
            'time_limit_value' => 'numeric', 'time_limit_unit' => 'in:minutes,hours,days',
            'data_limit_value' => 'numeric', 'data_limit_unit' => 'in:MB,GB',
            'rate_down' => 'regex:/^\d+(\.\d+)?[kKmMgG]?$/', 'rate_up' => 'regex:/^\d+(\.\d+)?[kKmMgG]?$/',
            'shared_users' => 'required|integer',
            'activation_mode' => 'required|in:first_login,first_auth,on_generation',
            'validity_mode' => 'required|in:elapsed_time,cumulative_uptime,data_quota,fixed_date',
            'mikrotik_profile' => 'regex:/^[A-Za-z0-9_.-]+$/|max:100',
            'start_date' => 'regex:/^\d{4}-\d{2}-\d{2}$/', 'end_date' => 'regex:/^\d{4}-\d{2}-\d{2}$/',
            'status' => 'required|in:active,inactive,archived', 'sort_order' => 'integer',
        ], ['name' => 'Nom', 'code' => 'Code', 'price' => 'Prix', 'shared_users' => 'Connexions simultanées', 'rate_down' => 'Débit descendant', 'rate_up' => 'Débit montant']);
        if ((float) $d['price'] < 0) {
            Session::flashErrors(['price' => 'Le prix doit être positif.']);
            throw new ValidationFailed([], $this->back($request));
        }
        $time = $d['time_limit_value'] !== null && $d['time_limit_value'] !== '' ? (int) round((float) $d['time_limit_value'] * ['minutes' => 60, 'hours' => 3600, 'days' => 86400][$d['time_limit_unit'] ?? 'hours']) : null;
        $data = $d['data_limit_value'] !== null && $d['data_limit_value'] !== '' ? (int) round((float) $d['data_limit_value'] * (($d['data_limit_unit'] ?? 'MB') === 'GB' ? 1073741824 : 1048576)) : null;
        if ($d['validity_mode'] === 'cumulative_uptime' && !$time) {
            Session::flashErrors(['time_limit_value' => 'Indiquez le temps de connexion autorisé pour ce mode.']);
            throw new ValidationFailed([], $this->back($request));
        }
        if ($d['validity_mode'] === 'data_quota' && !$data) {
            Session::flashErrors(['data_limit_value' => 'Indiquez le quota de données pour ce mode.']);
            throw new ValidationFailed([], $this->back($request));
        }
        return [
            'name' => $d['name'], 'code' => strtoupper($d['code']), 'description' => $d['description'] ?: null, 'price' => (float) $d['price'],
            'validity_value' => $d['validity_value'] ? (int) $d['validity_value'] : null,
            'validity_unit' => $d['validity_value'] ? ($d['validity_unit'] ?: 'hours') : null,
            'time_limit_seconds' => $time ?: null, 'data_limit_bytes' => $data ?: null,
            'rate_down' => $d['rate_down'] ? strtoupper($d['rate_down']) : null, 'rate_up' => $d['rate_up'] ? strtoupper($d['rate_up']) : null,
            'shared_users' => max(1, (int) $d['shared_users']), 'mac_binding' => $request->input('mac_binding') ? 1 : 0,
            'activation_mode' => $d['activation_mode'], 'validity_mode' => $d['validity_mode'],
            'mikrotik_profile' => $d['mikrotik_profile'] ?: null, 'start_date' => $d['start_date'] ?: null, 'end_date' => $d['end_date'] ?: null,
            'is_public' => $request->input('is_public') ? 1 : 0, 'status' => $d['status'], 'sort_order' => (int) ($d['sort_order'] ?? 0),
        ];
    }

    private function syncRouters(int $offerId, array $routerIds): void
    {
        $routerIds = array_values(array_unique(array_filter(array_map('intval', $routerIds))));
        Database::query('DELETE FROM offer_router WHERE offer_id = :o' . ($routerIds ? ' AND router_id NOT IN (' . implode(',', $routerIds) . ')' : ''), ['o' => $offerId]);
        foreach ($routerIds as $rid) {
            Database::query('INSERT IGNORE INTO offer_router (offer_id, router_id) VALUES (:o, :r)', ['o' => $offerId, 'r' => $rid]);
        }
    }

    /** Crée/met à jour le profil Hotspot de l'offre sur chaque routeur associé. */
    private function pushProfiles(int $offerId): array
    {
        $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => $offerId]);
        $ok = [];
        $ko = [];
        foreach (Database::fetchAll("SELECT r.* FROM offer_router orr JOIN routers r ON r.id = orr.router_id WHERE orr.offer_id = :o AND r.deleted_at IS NULL AND r.status = 'active'", ['o' => $offerId]) as $router) {
            try {
                RouterService::ensureProfile($router, $offer);
                $ok[] = $router['name'];
            } catch (Throwable $e) {
                $ko[] = $router['name'] . ' (' . $e->getMessage() . ')';
            }
        }
        return [$ok, $ko];
    }

    private function flashPush(array $ok, array $ko): void
    {
        if ($ok) {
            Session::flash('success', 'Profil Hotspot appliqué sur : ' . implode(', ', $ok) . '.');
        }
        if ($ko) {
            Session::flash('warning', 'Profil non appliqué (sera réessayé à la génération de tickets) : ' . implode(' ; ', $ko));
        }
    }

    public function store(Request $request): Response
    {
        $this->authorize('offers.manage');
        $data = $this->validated($request);
        $id = Database::insert('offers', $data + ['created_by' => Auth::id()]);
        $this->syncRouters($id, (array) $request->input('routers', []));
        AuditService::log('offer.create', 'offer', $id, "Création de l'offre {$data['name']} (" . money($data['price']) . ')');
        [$ok, $ko] = $this->pushProfiles($id);
        Session::flash('info', 'Offre créée.');
        $this->flashPush($ok, $ko);
        return $this->redirect('/offers');
    }

    public function edit(Request $request, string $id): Response
    {
        $this->authorize('offers.manage');
        $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($offer, 'Offre introuvable.');
        $linked = array_map('intval', array_column(Database::fetchAll('SELECT router_id FROM offer_router WHERE offer_id = :o', ['o' => $offer['id']]), 'router_id'));
        return $this->view('offers/form', ['offer' => $offer, 'routers' => $this->routers(), 'linked' => $linked]);
    }

    public function update(Request $request, string $id): Response
    {
        $this->authorize('offers.manage');
        $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($offer, 'Offre introuvable.');
        $data = $this->validated($request, (int) $offer['id']);
        Database::update('offers', $data, 'id = :id', ['id' => $offer['id']]);
        $this->syncRouters((int) $offer['id'], (array) $request->input('routers', []));
        [$old, $new] = AuditService::diff($offer, $data);
        AuditService::log('offer.update', 'offer', (int) $offer['id'], "Modification de l'offre {$offer['name']}", $old, $new);
        Session::flash('info', 'Offre mise à jour. Les tickets déjà générés conservent leurs paramètres.');
        $this->flashPush(...$this->pushProfiles((int) $offer['id']));
        return $this->redirect('/offers');
    }

    public function push(Request $request, string $id): Response
    {
        $this->authorize('offers.manage');
        [$ok, $ko] = $this->pushProfiles((int) $id);
        if (!$ok && !$ko) {
            Session::flash('warning', 'Associez d\'abord cette offre à au moins un routeur actif.');
        }
        $this->flashPush($ok, $ko);
        return $this->redirect('/offers');
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->authorize('offers.manage');
        $offer = Database::fetch('SELECT * FROM offers WHERE id = :id', ['id' => (int) $id]);
        $this->notFoundUnless($offer);
        $used = (int) Database::value('SELECT COUNT(*) FROM tickets WHERE offer_id = :o', ['o' => $offer['id']]);
        if ($used > 0) {
            Database::update('offers', ['status' => 'archived'], 'id = :id', ['id' => $offer['id']]);
            Session::flash('success', "Offre archivée ({$used} ticket(s) y sont rattachés : l'historique est conservé).");
        } else {
            Database::query('DELETE FROM offers WHERE id = :id', ['id' => $offer['id']]);
            Session::flash('success', 'Offre supprimée.');
        }
        AuditService::log('offer.delete', 'offer', (int) $offer['id'], "Suppression/archivage de l'offre {$offer['name']}");
        return $this->redirect('/offers');
    }
}
