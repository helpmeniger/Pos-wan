<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AiClient;
use App\Services\CameraService;
use App\Services\NetworkGuard;

final class CameraController extends Controller
{
    private function camera(Request $r): array
    {
        $cam = CameraService::find((int) $r->param('id'), $this->storeId());
        if (!$cam) {
            throw new HttpException(404, 'Caméra introuvable.');
        }
        return $cam;
    }

    public function index(Request $r): void
    {
        $cams = Database::fetchAll('SELECT c.*, (SELECT COUNT(*) FROM virtual_lines v WHERE v.camera_id = c.id AND v.is_active = 1) lines_count
            FROM cameras c WHERE c.store_id = :s AND c.is_active = 1 ORDER BY c.name', ['s' => $this->storeId()]);
        $this->view('cameras/index', ['title' => 'Caméras', 'cameras' => $cams]);
    }

    public function create(Request $r): void
    {
        $this->view('cameras/form', ['title' => 'Nouvelle caméra', 'cam' => ['type' => 'usb', 'device_index' => 0, 'resolution_w' => 1280, 'resolution_h' => 720,
            'target_fps' => 15, 'analysis_fps' => 5, 'role' => 'sortie', 'recording_enabled' => 1, 'analysis_enabled' => 0]]);
    }

    private function validated(Request $r, ?array $existing = null): array
    {
        $v = Validator::make($r->all(), [
            'name' => 'required|string|max:100', 'type' => 'required|in:usb,ip_rtsp,ip_http,nvr',
            'device_index' => 'int|min:0|max:9', 'ip_address' => 'ip', 'port' => 'int|min:1|max:65535',
            'stream_path' => 'string|max:255|regex:#^[A-Za-z0-9/_.\-?=&%]*$#', 'username' => 'string|max:100',
            'location' => 'string|max:120', 'monitored_zone' => 'string|max:120', 'role' => 'required|in:sortie,entree,caisse,rayon,reserve,autre',
            'resolution_w' => 'required|int|min:160|max:3840', 'resolution_h' => 'required|int|min:120|max:2160',
            'target_fps' => 'required|int|min:1|max:60', 'analysis_fps' => 'required|int|min:1|max:30',
            'recording_enabled' => 'bool', 'analysis_enabled' => 'bool',
        ]);
        if ($v->fails()) {
            $this->back('error', 'Formulaire invalide : ' . implode(' ', array_map(static fn ($k, $m) => "[$k] $m", array_keys($v->errors), $v->errors)));
        }
        $d = $v->data;
        if ($d['type'] === 'usb') {
            if ($d['device_index'] === null) {
                $this->back('error', 'Indiquez l\'index du périphérique USB.');
            }
            $d['ip_address'] = $d['port'] = $d['stream_path'] = $d['username'] = null;
        } else {
            if (!$d['ip_address'] || !NetworkGuard::ipAllowed($d['ip_address'])) {
                $this->back('error', 'Adresse IP manquante ou hors des réseaux autorisés (CAMERA_ALLOWED_NETWORKS dans .env).');
            }
            $d['device_index'] = null;
        }
        $pwd = (string) $r->input('password', '');
        if ($pwd !== '') {
            $d['password_enc'] = Crypto::encrypt($pwd);
        } elseif ($r->input('clear_password')) {
            $d['password_enc'] = null;
        }
        return $d;
    }

    public function store(Request $r): void
    {
        $d = $this->validated($r);
        $d['uuid'] = uuid4();
        $d['store_id'] = $this->storeId();
        $id = Database::insert('cameras', $d);
        Audit::log('camera_creee', 'camera', $id, ['nom' => $d['name'], 'type' => $d['type']]);
        $this->flash('success', 'Caméra créée. Testez la connexion puis tracez la ligne virtuelle de sortie.');
        Response::redirect('cameras/' . $id);
    }

    public function edit(Request $r): void
    {
        $cam = $this->camera($r);
        $this->view('cameras/form', ['title' => 'Modifier la caméra', 'cam' => $cam]);
    }

    public function update(Request $r): void
    {
        $cam = $this->camera($r);
        $d = $this->validated($r, $cam);
        Database::update('cameras', $d, 'id = :id', ['id' => $cam['id']]);
        Audit::log('camera_modifiee', 'camera', $cam['id']);
        $running = AiClient::request('GET', '/cameras/' . $cam['id'] . '/state', null, ['timeout' => 4]);
        if ($running['ok'] && ($running['data']['status'] ?? '') !== 'arretee') {
            CameraService::start(CameraService::find((int) $cam['id'], $this->storeId()));
        }
        $this->flash('success', 'Caméra mise à jour.');
        Response::redirect('cameras/' . $cam['id']);
    }

    public function show(Request $r): void
    {
        $cam = $this->camera($r);
        $lines = Database::fetchAll('SELECT * FROM virtual_lines WHERE camera_id = :c AND is_active = 1', ['c' => $cam['id']]);
        $events = Database::fetchAll('SELECT * FROM camera_events WHERE camera_id = :c ORDER BY occurred_at DESC LIMIT 30', ['c' => $cam['id']]);
        $stats = Database::fetch("SELECT SUM(direction='sortie') exits, SUM(direction='entree') entries, SUM(direction IN ('ambigu','perte_suivi')) uncertain
            FROM exit_events WHERE camera_id = :c AND occurred_at >= CURDATE()", ['c' => $cam['id']]);
        $state = AiClient::request('GET', '/cameras/' . $cam['id'] . '/state', null, ['timeout' => 4]);
        $this->view('cameras/show', ['title' => 'Caméra : ' . $cam['name'], 'cam' => $cam, 'lines' => $lines, 'events' => $events,
            'stats' => $stats, 'state' => $state['ok'] ? $state['data'] : null, 'aiError' => $state['error'],
            'urlMasked' => $cam['type'] !== 'usb' ? CameraService::buildUrl($cam, false) : null, 'scripts' => ['js/camera.js']]);
    }

    public function devices(Request $r): void
    {
        $res = AiClient::request('GET', '/cameras/devices', null, ['timeout' => 30]);
        Response::json(['ok' => $res['ok'], 'error' => $res['error'], 'devices' => $res['data']['devices'] ?? [], 'note' => $res['data']['note'] ?? null]);
    }

    public function test(Request $r): void
    {
        $cam = $this->camera($r);
        try {
            $src = CameraService::source($cam);
        } catch (\RuntimeException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        $res = AiClient::request('POST', '/cameras/test', $src, ['timeout' => 25]);
        if (!$res['ok']) {
            Response::json(['ok' => false, 'error' => $res['error']]);
        }
        $d = $res['data'];
        Database::update('cameras', ['status' => $d['ok'] ? 'en_ligne' : 'erreur', 'last_error' => $d['ok'] ? null : mb_substr((string) $d['error'], 0, 255),
            'last_seen_at' => $d['ok'] ? date('Y-m-d H:i:s') : $cam['last_seen_at']], 'id = :id', ['id' => $cam['id']]);
        Response::json($d);
    }

    public function start(Request $r): void
    {
        $cam = $this->camera($r);
        Database::update('cameras', ['analysis_enabled' => 1], 'id = :id', ['id' => $cam['id']]);
        $cam['analysis_enabled'] = 1;
        try {
            $res = CameraService::start($cam);
        } catch (\RuntimeException $e) {
            $this->back('error', $e->getMessage());
        }
        Audit::log('camera_demarree', 'camera', $cam['id']);
        $res['ok'] ? $this->flash('success', 'Analyse démarrée.') : $this->flash('error', (string) $res['error']);
        Response::back('cameras/' . $cam['id']);
    }

    public function stop(Request $r): void
    {
        $cam = $this->camera($r);
        Database::update('cameras', ['analysis_enabled' => 0], 'id = :id', ['id' => $cam['id']]);
        $res = CameraService::stop((int) $cam['id']);
        Audit::log('camera_arretee', 'camera', $cam['id']);
        $res['ok'] ? $this->flash('success', 'Analyse arrêtée.') : $this->flash('error', (string) $res['error']);
        Response::back('cameras/' . $cam['id']);
    }

    public function saveLine(Request $r): void
    {
        $cam = $this->camera($r);
        $v = Validator::make($r->all(), [
            'name' => 'required|string|max:80', 'x1' => 'required|numeric|min:0|max:1', 'y1' => 'required|numeric|min:0|max:1',
            'x2' => 'required|numeric|min:0|max:1', 'y2' => 'required|numeric|min:0|max:1',
            'exit_direction' => 'required|in:gauche_vers_droite,droite_vers_gauche', 'min_track_frames' => 'required|int|min:1|max:50',
            'hysteresis' => 'required|numeric|min:0|max:0.2', 'line_id' => 'int',
        ]);
        if ($v->fails()) {
            Response::json(['ok' => false, 'error' => $v->firstError()], 422);
        }
        $d = $v->data;
        if (abs($d['x1'] - $d['x2']) + abs($d['y1'] - $d['y2']) < 0.02) {
            Response::json(['ok' => false, 'error' => 'Ligne trop courte.'], 422);
        }
        $lineId = $d['line_id'];
        unset($d['line_id']);
        if ($lineId && Database::value('SELECT 1 FROM virtual_lines WHERE id = :l AND camera_id = :c', ['l' => $lineId, 'c' => $cam['id']])) {
            Database::update('virtual_lines', $d, 'id = :id', ['id' => $lineId]);
        } else {
            $lineId = Database::insert('virtual_lines', $d + ['camera_id' => $cam['id']]);
        }
        Audit::log('ligne_virtuelle_enregistree', 'virtual_line', $lineId, $d);
        $push = CameraService::pushConfig($cam);
        Response::json(['ok' => true, 'line_id' => $lineId, 'pushed' => $push['ok']]);
    }

    public function deleteLine(Request $r): void
    {
        $cam = $this->camera($r);
        Database::execute('UPDATE virtual_lines SET is_active = 0 WHERE id = :l AND camera_id = :c', ['l' => (int) $r->param('line'), 'c' => $cam['id']]);
        Audit::log('ligne_virtuelle_supprimee', 'virtual_line', (int) $r->param('line'));
        CameraService::pushConfig($cam);
        $this->back('success', 'Ligne supprimée.', 'cameras/' . $cam['id']);
    }

    public function destroy(Request $r): void
    {
        $cam = $this->camera($r);
        CameraService::stop((int) $cam['id']);
        Database::update('cameras', ['is_active' => 0, 'analysis_enabled' => 0, 'status' => 'desactivee'], 'id = :id', ['id' => $cam['id']]);
        Audit::log('camera_desactivee', 'camera', $cam['id']);
        $this->flash('success', 'Caméra désactivée (l\'historique est conservé).');
        Response::redirect('cameras');
    }
}
