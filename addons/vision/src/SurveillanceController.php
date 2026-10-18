<?php
declare(strict_types=1);

namespace Addons\Vision;

use App\Controllers\Controller;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\Request;

/** Caméras (USB, carte d'acquisition, IP), surveillance des passages et rapport de comparaison. */
final class SurveillanceController extends Controller
{
    // ------------------------------------------------------------------ caméras
    public function cameras(): void
    {
        $edit = (int) Request::query('edit', 0);
        view('vision::cameras', ['title' => 'Caméras', 'cameras' => DB::all('SELECT * FROM vision_cameras ORDER BY active DESC, name'),
            'edit' => $edit ? DB::one('SELECT * FROM vision_cameras WHERE id = ?', [$edit]) : null,
            'counts' => array_column(DB::all('SELECT camera_id, COUNT(*) AS n FROM vision_events GROUP BY camera_id'), 'n', 'camera_id'),
            'scripts' => ['addons/vision/vision-cameras.js']]);
    }

    public function saveCamera(int $id = 0): void
    {
        $this->attempt(fn() => CameraService::save($_POST, $id ?: null), $id ? '/vision/cameras?edit=' . $id : '/vision/cameras');
        flash('success', t('Caméra enregistrée.'));
        redirect('/vision/cameras');
    }

    public function createCamera(): void
    {
        $this->saveCamera(0);
    }

    /** Supprimée si elle n'a enregistré aucun passage, sinon désactivée (historique conservé). */
    public function deleteCamera(int $id): void
    {
        $this->attempt(function () use ($id) {
            $c = DB::one('SELECT * FROM vision_cameras WHERE id = ?', [$id]) ?? throw new BusinessException('Caméra introuvable.');
            if (DB::value('SELECT 1 FROM vision_events WHERE camera_id = ? LIMIT 1', [$id])) {
                DB::update('vision_cameras', ['active' => 0], 'id = ?', [$id]);
                flash('success', t('Caméra désactivée (ses passages enregistrés sont conservés).'));
            } else {
                DB::run('DELETE FROM vision_cameras WHERE id = ?', [$id]);
                flash('success', t('Caméra supprimée.'));
            }
            \App\Core\Audit::log('vision.camera_delete', null, $id, ['name' => $c['name']]);
        }, '/vision/cameras');
        redirect('/vision/cameras');
    }

    /** Essai de connexion d'une caméra IP (taille de l'image, durée). */
    public function testCamera(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            $c = DB::one('SELECT * FROM vision_cameras WHERE id = ?', [$id]) ?? throw new BusinessException('Caméra introuvable.');
            $t = microtime(true);
            [$img, $mime] = CameraService::fetchFrame($c);
            $size = @getimagesizefromstring($img);
            return ['bytes' => strlen($img), 'ms' => (int) round((microtime(true) - $t) * 1000), 'mime' => $mime, 'width' => $size[0] ?? null, 'height' => $size[1] ?? null];
        });
    }

    /** Image actuelle d'une caméra IP, relayée par le serveur (même origine : analysable par le navigateur). */
    public function frame(int $id): void
    {
        $c = DB::one('SELECT * FROM vision_cameras WHERE id = ? AND active = 1', [$id]);
        if (!$c || !(can('vision.watch') || ($c['use_caisse'] && can('vision.use')))) {
            http_response_code(404);
            exit;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // ne bloque pas les autres requêtes de la même session pendant la lecture
        }
        try {
            [$img, $mime] = CameraService::fetchFrame($c);
        } catch (BusinessException $e) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo $e->getMessage();
            exit;
        }
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($img));
        echo $img;
        exit;
    }

    /** Caméras utilisables sur cet écran (sans adresse ni identifiants). */
    public function sources(): void
    {
        $watch = can('vision.watch');
        $rows = DB::all('SELECT * FROM vision_cameras WHERE active = 1 ' . ($watch ? '' : 'AND use_caisse = 1') . ' ORDER BY name');
        json_response(['ok' => true, 'items' => array_map([CameraService::class, 'publicRow'], $rows)]);
    }

    public function geometry(int $id): void
    {
        $this->jsonAttempt(function () use ($id) {
            DB::value('SELECT id FROM vision_cameras WHERE id = ?', [$id]) ?: throw new BusinessException('Caméra introuvable.');
            $g = CameraService::geometry(['line' => Request::input('line'), 'invert' => Request::input('invert')] + (Request::input('zone') ? ['zone' => Request::input('zone')] : []));
            $mode = Request::input('mode') === 'zone' ? 'zone' : 'ligne';
            DB::update('vision_cameras', ['geometry' => $g, 'mode' => $mode], 'id = ?', [$id]);
            return ['geometry' => json_decode($g, true), 'mode' => $mode];
        });
    }

    // ------------------------------------------------------------------ surveillance
    public function surveillance(): void
    {
        SurveillanceService::purge();
        $today = date('Y-m-d');
        $cmp = SurveillanceService::compare($today, $today, [], (int) setting('vision_surv_fenetre', '20'));
        view('vision::surveillance', ['title' => 'Surveillance des articles', 'cameras' => array_map([CameraService::class, 'publicRow'],
            DB::all('SELECT * FROM vision_cameras WHERE active = 1 AND use_surveillance = 1 ORDER BY name')), 'today' => $cmp['totals'],
            'scripts' => ['addons/vision/vision-surv.js']]);
    }

    public function addEvent(): void
    {
        $this->jsonAttempt(fn() => SurveillanceService::addEvent(array_combine($k = ['camera_id', 'direction', 'product_id', 'score', 'margin', 'thumb'], array_map(fn($x) => Request::input($x), $k))));
    }

    /** Compteurs du jour par caméra (rafraîchissement de l'écran de surveillance). */
    public function today(): void
    {
        $rows = DB::all("SELECT camera_id, direction, COUNT(*) AS n, SUM(product_id IS NULL) AS unknown FROM vision_events WHERE seen_at >= CURDATE() AND status <> 'ignore' GROUP BY camera_id, direction");
        $cmp = SurveillanceService::compare(date('Y-m-d'), date('Y-m-d'), [], (int) setting('vision_surv_fenetre', '20'));
        json_response(['ok' => true, 'rows' => $rows, 'totals' => $cmp['totals']]);
    }

    public function review(int $id): void
    {
        $this->jsonAttempt(fn() => SurveillanceService::review($id, (string) Request::input('action', ''), (int) Request::input('product_id', 0) ?: null));
    }

    // ------------------------------------------------------------------ rapport
    public function report(): void
    {
        SurveillanceService::purge();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) Request::query('from')) ? (string) Request::query('from') : date('Y-m-d');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) Request::query('to')) ? (string) Request::query('to') : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $cams = array_values(array_filter(array_map('intval', (array) Request::query('camera', []))));
        $window = (int) Request::query('window', setting('vision_surv_fenetre', '20'));
        $cmp = SurveillanceService::compare($from, $to, $cams, $window);
        if (Export::requested()) {
            $lvl = ['alerte' => 'À vérifier en priorité', 'verifier' => 'À vérifier', 'ok' => 'Conforme'];
            Export::download("passages_ventes_{$from}_{$to}.csv", 'Passages vus / ventes / sorties de stock', ['Référence', 'Article', 'Passages vus', 'dont identifiés sûrs', 'Ventes', 'Sorties de stock', 'Passages justifiés', 'Non justifiés', 'Écart vus − vendus', 'Appréciation'],
                array_map(fn($r) => [$r['reference'], $r['name'], $r['seen'], $r['seen_sure'], $r['sold'], $r['stock_out'], $r['matched'], $r['unmatched'], $r['gap'], $lvl[$r['level']]], $cmp['rows']),
                ['subtitle' => "Du $from au $to · fenêtre ± {$cmp['window']} min · indications à vérifier, pas une preuve", 'landscape' => true]);
        }
        $ids = $cmp['unmatched_ids'];
        $show = (string) Request::query('show', 'unmatched');
        $where = match ($show) {
            'unknown' => 'e.product_id IS NULL AND e.status <> \'ignore\'',
            'all' => '1 = 1',
            default => $ids ? 'e.id IN (' . implode(',', $ids) . ')' : '0 = 1',
        };
        $events = DB::all("SELECT e.*, p.name AS product, c.name AS camera FROM vision_events e JOIN vision_cameras c ON c.id = e.camera_id LEFT JOIN products p ON p.id = e.product_id
                           WHERE e.seen_at BETWEEN ? AND ? AND $where" . ($cams ? ' AND e.camera_id IN (' . implode(',', $cams) . ')' : '') . ' ORDER BY e.seen_at DESC LIMIT 120', [$from . ' 00:00:00', $to . ' 23:59:59']);
        view('vision::rapport', ['title' => 'Passages vus / ventes', 'cmp' => $cmp, 'from' => $from, 'to' => $to, 'cams' => $cams, 'show' => $show, 'events' => $events,
            'cameras' => DB::all('SELECT id, name FROM vision_cameras ORDER BY name'), 'scripts' => ['addons/vision/vision-report.js']]);
    }

    public function settings(): void
    {
        $this->attempt(function () {
            $f = fn($k, $min, $max, $def) => (string) max($min, min($max, round((float) str_replace(',', '.', (string) Request::input($k, $def)), 2)));
            setting_set('vision_surv_seuil', $f('vision_surv_seuil', 0.1, 0.99, '0.45'));
            setting_set('vision_surv_marge', $f('vision_surv_marge', 0.0, 0.5, '0.04'));
            setting_set('vision_surv_fenetre', (string) max(1, min(240, (int) Request::input('vision_surv_fenetre', 20))));
            setting_set('vision_surv_conservation', (string) max(1, min(365, (int) Request::input('vision_surv_conservation', 30))));
            setting_set('vision_cam_publique', Request::input('vision_cam_publique') ? '1' : '0');
            \App\Core\Audit::log('settings.vision_surveillance');
        }, '/vision/cameras');
        flash('success', t('Réglages de la surveillance enregistrés.'));
        redirect('/vision/cameras');
    }
}
