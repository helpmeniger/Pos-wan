<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\AiClient;
use App\Services\CameraService;
use App\Services\TrainingService;

final class TrainingController extends Controller
{
    public function index(Request $r): void
    {
        $s = $this->storeId();
        $model = TrainingService::modelFor($s);
        $versions = Database::fetchAll('SELECT v.*, u.full_name FROM ai_model_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.ai_model_id = :m ORDER BY v.version_number DESC', ['m' => $model['id']]);
        $jobs = Database::fetchAll('SELECT * FROM ai_training_jobs WHERE ai_model_id = :m ORDER BY id DESC LIMIT 10', ['m' => $model['id']]);
        foreach ($jobs as &$j) {
            if (in_array($j['status'], ['en_attente', 'en_cours'], true)) {
                $j = TrainingService::poll($j) ?? $j;
            }
        }
        unset($j);
        $readiness = Database::fetchAll("SELECT p.id, p.name, p.recognition_status, p.detection_enabled,
              (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id AND i.usage_split <> 'exclue') photos
            FROM products p WHERE p.store_id = :s AND p.deleted_at IS NULL AND p.is_active = 1 ORDER BY p.name", ['s' => $s]);
        $cams = Database::fetchAll('SELECT id, name FROM cameras WHERE store_id = :s AND is_active = 1', ['s' => $s]);
        $health = AiClient::health();
        $this->view('training/index', ['title' => 'Apprentissage IA', 'model' => $model, 'versions' => $versions, 'jobs' => $jobs, 'readiness' => $readiness,
            'health' => $health, 'minImages' => (int) Settings::get('ai.min_images_per_product', 8), 'cameras' => $cams, 'scripts' => ['js/training.js']]);
    }

    public function start(Request $r): void
    {
        $res = TrainingService::start($this->storeId(), mb_substr((string) $r->input('notes', ''), 0, 500) ?: null);
        if (!$res['ok']) {
            $this->back('error', (string) $res['error'], 'training');
        }
        Response::redirect('training/jobs/' . $res['job_id']);
    }

    private function findJob(int $id): array
    {
        $job = Database::fetch('SELECT j.* FROM ai_training_jobs j JOIN ai_models m ON m.id = j.ai_model_id WHERE j.id = :id AND m.store_id = :s', ['id' => $id, 's' => $this->storeId()]);
        if (!$job) {
            throw new HttpException(404, 'Tâche introuvable.');
        }
        return $job;
    }

    /** GET /training/jobs/{id} : page de suivi (HTML) ou état (JSON pour l'actualisation). */
    public function job(Request $r): void
    {
        $job = TrainingService::poll($this->findJob((int) $r->param('id')));
        $version = $job['model_version_id'] ? Database::fetch('SELECT * FROM ai_model_versions WHERE id = :id', ['id' => $job['model_version_id']]) : null;
        if ($r->wantsJson()) {
            Response::json(['ok' => true, 'job' => ['status' => $job['status'], 'progress' => (int) $job['progress'], 'message' => $job['message']]]);
        }
        $this->view('training/job', ['title' => 'Préparation de la reconnaissance', 'job' => $job, 'version' => $version] + ($version ? $this->versionData($version) : [])
            + ['minAccuracy' => (float) Settings::get('ai.min_validation_accuracy', 0.8), 'scripts' => ['js/training.js']]);
    }

    public function versionData(array $v): array
    {
        $metrics = json_decode((string) $v['metrics_json'], true) ?: [];
        $results = Database::fetchAll('SELECT r.*, p.name, c.name confused FROM ai_validation_results r LEFT JOIN products p ON p.id = r.product_id
            LEFT JOIN products c ON c.id = r.most_confused_with_product_id WHERE r.model_version_id = :v ORDER BY p.name', ['v' => $v['id']]);
        return ['metrics' => $metrics, 'results' => $results];
    }

    public function version(Request $r): void
    {
        $v = Database::fetch('SELECT v.* FROM ai_model_versions v JOIN ai_models m ON m.id = v.ai_model_id WHERE v.id = :id AND m.store_id = :s',
            ['id' => (int) $r->param('id'), 's' => $this->storeId()]);
        if (!$v) {
            throw new HttpException(404, 'Version introuvable.');
        }
        $this->view('training/version', ['title' => 'Version ' . $v['version_number'] . ' de la reconnaissance', 'v' => $v] + $this->versionData($v)
            + ['minAccuracy' => (float) Settings::get('ai.min_validation_accuracy', 0.8)]);
    }

    public function activate(Request $r): void
    {
        $res = TrainingService::activate($this->storeId(), (int) $r->param('id'));
        $res['ok'] ? $this->flash('success', 'Version activée : la détection utilise désormais cette galerie de référence.') : $this->flash('error', (string) $res['error']);
        Response::redirect('training');
    }

    /** Mode test : reconnaissance sur une image importée. */
    public function testImage(Request $r): void
    {
        $f = $r->file('image');
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > Env::int('UPLOAD_MAX_IMAGE_MB', 8) * 1048576) {
            Response::json(['ok' => false, 'error' => 'Image manquante ou trop volumineuse.'], 422);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            Response::json(['ok' => false, 'error' => 'Format non accepté.'], 422);
        }
        $res = AiClient::request('POST', '/detection/image', null, ['multipart' => ['file' => new \CURLFile($f['tmp_name'], $mime, 'test.jpg')], 'timeout' => 30]);
        if (!$res['ok']) {
            Response::json(['ok' => false, 'error' => $res['error']]);
        }
        $d = $res['data'];
        foreach ($d['results'] as $res1) {
            Database::insert('detection_results', ['uuid' => uuid4(), 'camera_id' => null, 'product_id' => $res1['product_id'] && Database::value('SELECT 1 FROM products WHERE id = :id AND store_id = :s', ['id' => $res1['product_id'], 's' => $this->storeId()]) ? $res1['product_id'] : null,
                'label' => mb_substr($res1['label'], 0, 150), 'detector_label' => $res1['detector_label'], 'confidence' => max(0, min(1, (float) $res1['confidence'])),
                'bbox_x' => $res1['bbox'][0], 'bbox_y' => $res1['bbox'][1], 'bbox_w' => $res1['bbox'][2], 'bbox_h' => $res1['bbox'][3], 'is_test' => 1, 'detected_at' => now_ms()]);
        }
        Audit::log('ia_test_image', 'detection', null, ['resultats' => count($d['results'])]);
        Response::json(['ok' => true] + $d);
    }

    /** Mode test : analyse d'une courte vidéo (comptage sur une ligne). */
    public function testVideo(Request $r): void
    {
        $f = $r->file('video');
        $max = Env::int('UPLOAD_MAX_VIDEO_MB', 200) * 1048576;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > $max) {
            Response::json(['ok' => false, 'error' => 'Vidéo manquante ou trop volumineuse (vérifiez aussi upload_max_filesize dans php.ini).'], 422);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['video/mp4' => 'mp4', 'video/x-msvideo' => 'avi', 'video/avi' => 'avi', 'video/quicktime' => 'mov', 'video/x-matroska' => 'mkv', 'video/webm' => 'webm'][$mime] ?? null;
        if (!$ext) {
            Response::json(['ok' => false, 'error' => 'Format vidéo non accepté (' . $mime . ').'], 422);
        }
        $name = 'test-' . bin2hex(random_bytes(8)) . '.' . $ext;
        move_uploaded_file($f['tmp_name'], STORAGE_PATH . '/cache/' . $name);
        $lines = [];
        if ($cid = (int) $r->input('camera_id')) {
            if (CameraService::find($cid, $this->storeId())) {
                $lines = CameraService::lines($cid);
            }
        }
        if (!$lines) {
            $lines = [['id' => 0, 'name' => 'Ligne de test', 'x1' => 0.5, 'y1' => 0.0, 'x2' => 0.5, 'y2' => 1.0, 'exit_direction' => 'gauche_vers_droite', 'min_track_frames' => 3, 'hysteresis' => 0.02]];
        }
        $res = AiClient::request('POST', '/detection/video', ['path' => 'cache/' . $name, 'analysis_fps' => (float) ($r->input('analysis_fps') ?: 5), 'lines' => $lines]);
        Audit::log('ia_test_video', 'detection', null, ['fichier' => $name]);
        Response::json($res['ok'] ? ['ok' => true, 'job' => $res['data']['job_id'], 'lines' => $lines] : ['ok' => false, 'error' => $res['error']]);
    }

    public function testVideoStatus(Request $r): void
    {
        $job = preg_replace('/[^a-f0-9]/', '', (string) $r->param('job'));
        $res = AiClient::request('GET', '/detection/video/' . $job);
        Response::json($res['ok'] ? ['ok' => true] + $res['data'] : ['ok' => false, 'error' => $res['error']]);
    }
}
