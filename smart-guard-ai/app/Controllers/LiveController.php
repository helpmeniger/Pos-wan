<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiClient;
use App\Services\CameraService;

/** Les images en direct sont relayées par PHP (contrôle des droits) : le service IA n'est jamais exposé. */
final class LiveController extends Controller
{
    private function camera(Request $r): array
    {
        $cam = CameraService::find((int) $r->param('id'), $this->storeId());
        if (!$cam || !$cam['is_active']) {
            throw new HttpException(404, 'Caméra introuvable.');
        }
        return $cam;
    }

    public function index(Request $r): void
    {
        $cams = Database::fetchAll('SELECT id, name, location, status, analysis_enabled FROM cameras WHERE store_id = :s AND is_active = 1 ORDER BY name', ['s' => $this->storeId()]);
        $this->view('live/index', ['title' => 'Surveillance en direct', 'cameras' => $cams, 'scripts' => ['js/live.js']]);
    }

    public function frame(Request $r): void
    {
        $cam = $this->camera($r);
        $overlay = $r->query('overlay', '1') === '0' ? 0 : 1;
        $res = AiClient::request('GET', '/cameras/' . $cam['id'] . '/frame?overlay=' . $overlay, null, ['raw' => true, 'timeout' => 6]);
        if (!$res['ok'] || !str_starts_with((string) ($res['content_type'] ?? ''), 'image/jpeg')) {
            http_response_code(204);
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $res['raw'];
        exit;
    }

    public function state(Request $r): void
    {
        $cam = $this->camera($r);
        $res = AiClient::request('GET', '/cameras/' . $cam['id'] . '/state', null, ['timeout' => 4]);
        if (!$res['ok']) {
            Response::json(['ok' => false, 'error' => $res['error']]);
        }
        $d = $res['data'];
        unset($d['source']); // ne jamais renvoyer l'URL de flux au navigateur
        Response::json(['ok' => true, 'state' => $d]);
    }

    public function toggleAnalysis(Request $r): void
    {
        $cam = $this->camera($r);
        $enable = (bool) $r->input('enabled');
        Database::update('cameras', ['analysis_enabled' => $enable ? 1 : 0], 'id = :id', ['id' => $cam['id']]);
        $cam['analysis_enabled'] = $enable ? 1 : 0;
        $state = AiClient::request('GET', '/cameras/' . $cam['id'] . '/state', null, ['timeout' => 4]);
        $running = $state['ok'] && ($state['data']['status'] ?? 'arretee') !== 'arretee';
        try {
            // l'acquisition continue (aperçu) ; seule l'analyse est activée / suspendue
            $res = $running ? CameraService::pushConfig($cam) : CameraService::start($cam);
        } catch (\RuntimeException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        Audit::log($enable ? 'analyse_activee' : 'analyse_suspendue', 'camera', $cam['id']);
        Response::json(['ok' => $res['ok'], 'error' => $res['error']]);
    }

    /** Capture manuelle d'une image (conservée selon la politique de rétention). */
    public function capture(Request $r): void
    {
        $cam = $this->camera($r);
        $res = AiClient::request('GET', '/cameras/' . $cam['id'] . '/frame?overlay=0', null, ['raw' => true, 'timeout' => 6]);
        if (!$res['ok']) {
            Response::json(['ok' => false, 'error' => 'Aucune image disponible.'], 503);
        }
        $rel = 'snapshots/cam' . $cam['id'] . '/' . date('Y-m-d');
        if (!is_dir(STORAGE_PATH . '/' . $rel)) {
            mkdir(STORAGE_PATH . '/' . $rel, 0775, true);
        }
        $uuid = uuid4();
        file_put_contents(STORAGE_PATH . "/{$rel}/capture-{$uuid}.jpg", $res['raw']);
        $id = Database::insert('video_records', ['uuid' => $uuid, 'camera_id' => $cam['id'], 'kind' => 'snapshot', 'file_path' => "{$rel}/capture-{$uuid}.jpg",
            'size_bytes' => strlen($res['raw']), 'started_at' => now_ms(), 'expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400)]);
        Audit::log('capture_image', 'video_record', $id);
        Response::json(['ok' => true, 'url' => url('media/record/' . $id)]);
    }
}
