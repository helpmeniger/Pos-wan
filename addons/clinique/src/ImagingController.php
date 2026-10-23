<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;

final class ImagingController extends Controller
{
    /** Liste des examens, demandes en attente, récepteur et appareils. */
    public function index(): void
    {
        $status = (string) Request::query('statut', '');
        $q = trim((string) Request::query('q', ''));
        $counts = array_column(DB::all('SELECT status, COUNT(*) AS n FROM clinic_imaging_studies GROUP BY status'), 'n', 'status');
        view('clinique::imagerie', ['title' => 'Imagerie médicale', 'studies' => ImagingService::list(['status' => $status, 'q' => $q]), 'status' => $status, 'q' => $q,
            'counts' => $counts, 'requests' => ImagingService::pendingRequests(), 'receiver' => ImagingService::receiverStatus(), 'cfg' => ImagingService::settings(),
            'devices' => can('clinique.imaging') ? ImagingService::devices() : [], 'ips' => \App\Services\NetworkService::localIps(),
            'scripts' => ['addons/clinique/imagerie-import.js']]);
    }

    public function settings(): void
    {
        $this->attempt(fn() => ImagingService::saveSettings($_POST), '/clinique/imagerie?onglet=appareils');
        flash('success', t('Réglages enregistrés. Redémarrez le récepteur d\'images pour appliquer un nouveau port ou titre AE.'));
        redirect('/clinique/imagerie', ['onglet' => 'appareils']);
    }

    public function deviceSave(int $id = 0): void
    {
        $this->attempt(fn() => ImagingService::saveDevice($_POST, $id ?: null), '/clinique/imagerie?onglet=appareils');
        flash('success', t('Appareil enregistré.'));
        redirect('/clinique/imagerie', ['onglet' => 'appareils']);
    }

    // ------------------------------------------------------------------ import depuis le navigateur (morceaux de 1 Mo)

    public function importChunk(): void
    {
        $this->jsonAttempt(function () {
            $uid = (string) Request::input('upload', '');
            $index = (int) Request::input('index', -1);
            $total = (int) Request::input('total', 0);
            if (!preg_match('/^[a-f0-9]{16,40}$/', $uid) || $index < 0 || $total < 1 || $total > 220 || $index >= $total) {
                throw new BusinessException('Envoi invalide.');
            }
            $f = $_FILES['chunk'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw new BusinessException('Morceau de fichier non reçu (limite d\'envoi du serveur ?).');
            }
            if ((int) $f['size'] > 2 * 1024 * 1024) {
                throw new BusinessException('Morceau trop grand.');
            }
            $dir = ImagingService::root('envois/' . $uid);
            move_uploaded_file($f['tmp_name'], sprintf('%s/%05d', $dir, $index));
            $have = count(glob($dir . '/[0-9][0-9][0-9][0-9][0-9]') ?: []);
            if ($have < $total) {
                return ['done' => false, 'received' => $have];
            }
            // Dernier morceau : reconstitution puis classement
            $file = $dir . '/fichier';
            $out = fopen($file, 'wb');
            for ($i = 0; $i < $total; $i++) {
                $part = sprintf('%s/%05d', $dir, $i);
                if (!is_file($part)) {
                    fclose($out);
                    throw new BusinessException('Morceau manquant : recommencez l\'envoi.');
                }
                $in = fopen($part, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);
            try {
                $r = ImagingService::ingest($file, ['source' => 'import', 'patient_id' => Request::int('patient_id') ?: null, 'request_id' => Request::int('request_id') ?: null,
                    'study_id' => Request::int('study_id') ?: null, 'description' => (string) Request::input('description', '')]);
            } finally {
                foreach (glob($dir . '/*') ?: [] as $x) {
                    @unlink($x);
                }
                @rmdir($dir);
            }
            Audit::log('clinique.imagerie.import', 'clinic_imaging_study', $r['study_id'], ['image' => $r['image_id'], 'doublon' => $r['duplicate']]);
            return ['done' => true] + $r;
        });
    }

    // ------------------------------------------------------------------ demandes d'examen

    public function requestStore(): void
    {
        $pid = Request::int('patient_id');
        $id = $this->attempt(fn() => ImagingService::createRequest($_POST), Request::int('visit_id') ? '/clinique/consultations/' . Request::int('visit_id') : '/clinique/patients/' . $pid);
        flash('success', t('Demande d\'examen enregistrée : remettez le bon au patient.'));
        redirect('/clinique/imagerie/demandes/' . $id . '/imprimer');
    }

    public function requestCancel(int $id): void
    {
        $this->attempt(fn() => ImagingService::cancelRequest($id), '/clinique/imagerie');
        flash('success', t('Demande annulée.'));
        back('/clinique/imagerie');
    }

    public function requestPrint(int $id): void
    {
        $r = DB::one('SELECT r.*, p.number AS patient_number, p.last_name, p.first_name, p.sex, p.birth_date, u.full_name AS requester FROM clinic_imaging_requests r
                JOIN clinic_patients p ON p.id = r.patient_id JOIN users u ON u.id = r.requested_by WHERE r.id = ?', [$id]);
        if (!$r) {
            throw new HttpException(404);
        }
        view('clinique::imagerie_demande', ['title' => 'Demande ' . $r['number'], 'r' => $r, 'backUrl' => url('/clinique/patients/' . $r['patient_id']), 'bodyClass' => 'a5'], 'layouts/print');
    }

    // ------------------------------------------------------------------ examen : visionneuse, rattachement, compte rendu

    private function studyOr404(int $id): array
    {
        try {
            return ImagingService::study($id);
        } catch (BusinessException) {
            throw new HttpException(404);
        }
    }

    public function show(int $id): void
    {
        $s = $this->studyOr404($id);
        Audit::log('clinique.imagerie.view', 'clinic_imaging_study', $id, null);
        $candidates = [];
        if (!$s['patient_id'] && can('clinique.imaging')) {
            // Patients proches (nom et/ou date de naissance du fichier) : proposés, jamais rattachés automatiquement
            $words = array_filter(preg_split('/\s+/', (string) $s['dicom_patient_name']), fn($w) => mb_strlen($w) >= 3);
            $cond = [];
            $params = [];
            foreach (array_slice($words, 0, 3) as $w) {
                $cond[] = '(last_name LIKE ? OR first_name LIKE ?)';
                array_push($params, '%' . $w . '%', '%' . $w . '%');
            }
            if ($s['dicom_birth_date']) {
                $cond[] = 'birth_date = ?';
                $params[] = $s['dicom_birth_date'];
            }
            if ($cond) {
                $candidates = DB::all('SELECT id, number, last_name, first_name, sex, birth_date FROM clinic_patients WHERE active = 1 AND (' . implode(' OR ', $cond) . ') ORDER BY last_name LIMIT 10', $params);
            }
        }
        view('clinique::imagerie_examen', ['title' => 'Examen d\'imagerie', 's' => $s, 'images' => ImagingService::images($id), 'candidates' => $candidates,
            'requests' => $s['patient_id'] ? ImagingService::pendingRequests((int) $s['patient_id']) : [],
            'history' => $s['patient_id'] ? DB::all('SELECT id, modality, description, study_date, status FROM clinic_imaging_studies WHERE patient_id = ? AND id <> ? ORDER BY COALESCE(study_date, created_at) DESC LIMIT 10', [$s['patient_id'], $id]) : [],
            'scripts' => ['addons/clinique/imagerie.js', 'addons/clinique/imagerie-import.js']]);
    }

    public function attach(int $id): void
    {
        $this->studyOr404($id);
        $pid = Request::int('patient_id');
        if (!$pid && ($num = trim(Request::str('patient_number'))) !== '') {
            $pid = (int) DB::value('SELECT id FROM clinic_patients WHERE number = ?', [$num]);
        }
        if (!$pid) {
            $this->fail(t('Patient introuvable : vérifiez le n° de dossier.'), '/clinique/imagerie/examens/' . $id);
        }
        $this->attempt(fn() => ImagingService::attach($id, $pid, Request::int('request_id') ?: null), '/clinique/imagerie/examens/' . $id);
        flash('success', t('Examen rattaché au patient.'));
        redirect('/clinique/imagerie/examens/' . $id);
    }

    public function report(int $id): void
    {
        $this->studyOr404($id);
        $validate = Request::str('action') === 'valider';
        $this->attempt(fn() => ImagingService::saveReport($id, $_POST, $validate), '/clinique/imagerie/examens/' . $id);
        flash('success', $validate ? t('Compte rendu validé.') : t('Compte rendu enregistré.'));
        redirect('/clinique/imagerie/examens/' . $id);
    }

    public function reopen(int $id): void
    {
        $this->attempt(fn() => ImagingService::reopen($id, Request::str('reason')), '/clinique/imagerie/examens/' . $id);
        flash('success', t('Compte rendu rouvert pour correction.'));
        redirect('/clinique/imagerie/examens/' . $id);
    }

    public function printReport(int $id): void
    {
        $s = $this->studyOr404($id);
        $imgs = array_values(array_filter(ImagingService::images($id), fn($i) => (int) $i['renderable']));
        view('clinique::imagerie_cr', ['title' => 'Compte rendu d\'imagerie', 's' => $s, 'images' => array_slice($imgs, 0, 4),
            'backUrl' => url('/clinique/imagerie/examens/' . $id), 'bodyClass' => 'a4'], 'layouts/print');
    }

    // ------------------------------------------------------------------ images

    private function imageOr404(int $id): array
    {
        try {
            return ImagingService::image($id);
        } catch (BusinessException) {
            throw new HttpException(404);
        }
    }

    public function thumbnail(int $id): void
    {
        $img = $this->imageOr404($id);
        $size = in_array((int) Request::query('t', 256), [256, 512], true) ? (int) Request::query('t', 256) : 256;
        try {
            $f = ImagingService::thumbnail($img, $size);
        } catch (BusinessException $e) {
            header('Content-Type: image/svg+xml');
            header('Cache-Control: private, max-age=60');
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256" fill="#222"/><text x="128" y="120" fill="#bbb" font-size="14" text-anchor="middle" font-family="sans-serif">Aperçu indisponible</text><text x="128" y="145" fill="#888" font-size="11" text-anchor="middle" font-family="sans-serif">' . e(mb_substr($img['transfer_syntax'] ?? $img['format'], 0, 30)) . '</text></svg>';
            exit;
        }
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    /** Paramètres de l'image pour la visionneuse (fenêtrage, calibrage, annotations). */
    public function infos(int $id): void
    {
        $img = $this->imageOr404($id);
        $this->jsonAttempt(function () use ($img) {
            $out = ['id' => (int) $img['id'], 'format' => $img['format'], 'renderable' => (bool) $img['renderable'], 'note' => $img['render_note'],
                'w' => (int) $img['width'], 'h' => (int) $img['height'], 'frames' => (int) $img['frames'], 'annotations' => ImagingService::annotations((int) $img['id'])];
            if ($img['format'] !== 'dicom') {
                return $out + ['mode' => 'image', 'samples' => 3, 'modality' => 'OT', 'spacing' => null];
            }
            $p = Dicom::parse(ImagingService::path($img));
            $i = Dicom::info($p);
            $sp = $i['pixel_spacing'] ? array_map('floatval', explode('\\', $i['pixel_spacing'])) : null;
            return $out + ['mode' => $p['ts'] === Dicom::TS_JPEG_BASELINE ? 'image' : 'raw', 'samples' => $i['samples'], 'photometric' => $i['photometric'],
                'planar' => $i['planar'], 'bits' => $i['bits_allocated'], 'bits_stored' => $i['bits_stored'], 'signed' => $i['signed'],
                'slope' => $i['slope'], 'intercept' => $i['intercept'], 'wc' => $i['window_center'], 'ww' => $i['window_width'], 'modality' => $i['modality'],
                'spacing' => $sp && $sp[0] > 0 && $sp[1] > 0 ? ['row' => $sp[0], 'col' => $sp[1]] : null, 'body_part' => $i['body_part'],
                'manufacturer' => $i['manufacturer'], 'series' => $img['series_description'], 'instance' => $img['instance_number']];
        });
    }

    /** Pixels d'une image (binaire brut non compressé, ou JPEG/PNG) pour la visionneuse. */
    public function pixels(int $id): void
    {
        $img = $this->imageOr404($id);
        if (!(int) $img['renderable']) {
            throw new HttpException(415, (string) $img['render_note']);
        }
        $path = ImagingService::path($img);
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        if ($img['format'] !== 'dicom') {
            header('Content-Type: ' . ($img['format'] === 'png' ? 'image/png' : 'image/jpeg'));
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        $p = Dicom::parse($path);
        $i = Dicom::info($p);
        $frame = max(0, min($i['frames'] - 1, (int) Request::query('frame', 0)));
        if ($p['ts'] === Dicom::TS_JPEG_BASELINE) {
            $jpg = Dicom::frameEncoded($path, $p['pixel'], $i['frames'], $frame);
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($jpg));
            echo $jpg;
            exit;
        }
        header('Content-Type: application/octet-stream');
        $px = $p['pixel'];
        $frameSize = $i['rows'] * $i['cols'] * $i['samples'] * intdiv($i['bits_allocated'], 8);
        if (!$px['encapsulated']) {
            // Lecture directe dans le fichier, sans tout charger en mémoire
            header('Content-Length: ' . $frameSize);
            $fh = fopen($path, 'rb');
            $out = fopen('php://output', 'wb');
            stream_copy_to_stream($fh, $out, $frameSize, $px['offset'] + $frame * $frameSize);
            fclose($fh);
            exit;
        }
        $bytes = Dicom::frameBytes($path, $p, $i, $frame);
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public function download(int $id): void
    {
        $img = $this->imageOr404($id);
        $path = ImagingService::path($img);
        Audit::log('clinique.imagerie.telechargement', 'clinic_imaging_image', $id, null);
        $ext = ['dicom' => 'dcm', 'jpeg' => 'jpg', 'png' => 'png'][$img['format']];
        header('Content-Type: ' . ($img['format'] === 'dicom' ? 'application/dicom' : 'image/' . $img['format']));
        header('Content-Disposition: attachment; filename="image-' . (int) $img['id'] . '.' . $ext . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function annotationStore(int $id): void
    {
        $this->imageOr404($id);
        $this->jsonAttempt(function () use ($id) {
            $in = Request::json() ?: $_POST;
            return ['id' => ImagingService::addAnnotation($id, $in), 'annotations' => ImagingService::annotations($id)];
        });
    }

    public function annotationDelete(int $id): void
    {
        $a = DB::one('SELECT * FROM clinic_imaging_annotations WHERE id = ?', [$id]);
        if (!$a) {
            throw new HttpException(404);
        }
        if ((int) $a['user_id'] !== (int) \App\Core\Auth::id() && !can('clinique.imaging_report')) {
            throw new HttpException(403);
        }
        ImagingService::deleteAnnotation($id);
        json_response(['ok' => true, 'annotations' => ImagingService::annotations((int) $a['image_id'])]);
    }
}
