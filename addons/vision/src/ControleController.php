<?php
declare(strict_types=1);

namespace Addons\Vision;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;

/** Contrôle « rayon → caisse » : alertes de vérification, suivis, fiabilité des caméras, réglages. */
final class ControleController extends Controller
{
    public function index(): void
    {
        ParcoursService::resolve();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) Request::query('from')) ? (string) Request::query('from') : date('Y-m-d');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) Request::query('to')) ? (string) Request::query('to') : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $show = (string) Request::query('show', 'alerte');
        $where = match ($show) {
            'en_cours' => "j.status IN ('en_cours','vu_caisse')",
            'resolus' => "j.status IN ('encaisse','remis')",
            'verifies' => 'j.reviewed_at IS NOT NULL',
            'tous' => '1 = 1',
            default => "j.status = 'alerte' AND j.reviewed_at IS NULL",
        };
        // Les alertes à vérifier s'affichent quelle que soit leur date ; les autres listes suivent la période
        $params = [];
        if ($show !== 'alerte') {
            $where .= ' AND j.taken_at BETWEEN ? AND ?';
            $params = [$from . ' 00:00:00', $to . ' 23:59:59'];
        }
        view('vision::controles', ['title' => 'Contrôle rayon → caisse', 'rows' => ParcoursService::rows($where, $params, 150), 'show' => $show, 'from' => $from, 'to' => $to,
            'stats' => ParcoursService::stats($from, $to), 'shelves' => self::shelves(), 'cameras' => DB::all("SELECT role, COUNT(*) AS n FROM vision_cameras WHERE active = 1 GROUP BY role"),
            'scripts' => ['addons/vision/vision-controles.js']]);
    }

    /** État des rayons : tous les emplacements avec leur compte vu (pour la page Contrôle). */
    private static function shelves(): array
    {
        return DB::all('SELECT s.id, s.label, s.units, s.min_count, s.seen_count, s.seen_at, p.name AS product, c.name AS camera, c.place
                        FROM vision_slots s JOIN vision_cameras c ON c.id = s.camera_id AND c.active = 1 LEFT JOIN products p ON p.id = s.product_id
                        WHERE s.active = 1 ORDER BY c.name, s.id');
    }

    public function live(): void
    {
        header('Cache-Control: no-store');
        json_response(['ok' => true] + ParcoursService::live((int) Request::query('since', 0)));
    }

    public function review(int $id): void
    {
        $this->jsonAttempt(fn() => ParcoursService::review($id, (string) Request::input('action', ''), Request::input('note') !== null ? (string) Request::input('note') : null));
    }

    /** Rappel au caissier (si activé) : articles vus sur le comptoir de sa caisse, pas encore encaissés. */
    public function cashier(): void
    {
        header('Cache-Control: no-store');
        $rows = ParcoursService::cashierReminders((int) Auth::id());
        json_response(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int) $r['id'], 'product' => $r['product'], 'since' => substr((string) $r['checkout_at'], 11, 5)], $rows)]);
    }

    /** Comptes d'articles vus à leur place, envoyés par l'écran de surveillance. */
    public function counts(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            DB::value("SELECT id FROM vision_cameras WHERE id = ? AND role = 'etagere'", [$id]) ?: throw new \App\Core\BusinessException('Caméra d\'étagère introuvable.');
            return ['saved' => ParcoursService::saveCounts($id, (array) Request::input('slots', [])), 'low' => count(ParcoursService::lowShelves())];
        });
    }

    public function slots(int $id): void
    {
        $this->jsonAttempt(fn() => ['slots' => CameraService::saveSlots($id, (array) Request::input('slots', []))]);
    }

    public function settings(): void
    {
        $this->attempt(function () {
            setting_set('vision_parcours_delai', (string) max(1, min(120, (int) Request::input('vision_parcours_delai', 15))));
            setting_set('vision_parcours_inconnu', Request::input('vision_parcours_inconnu') ? '1' : '0');
            setting_set('vision_parcours_sortie', Request::input('vision_parcours_sortie') ? '1' : '0');
            setting_set('vision_parcours_rappel', Request::input('vision_parcours_rappel') ? '1' : '0');
            setting_set('vision_parcours_rappel_s', (string) max(15, min(600, (int) Request::input('vision_parcours_rappel_s', 60))));
            Audit::log('settings.vision_parcours');
        }, '/vision/cameras');
        flash('success', t('Réglages du contrôle rayon → caisse enregistrés.'));
        redirect('/vision/cameras');
    }
}
