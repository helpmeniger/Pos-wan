<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Logger;
use App\Services\Sequence;

/**
 * Imagerie médicale de la clinique :
 * - réception des images (réseau DICOM, dossier d'arrivée partagé, import depuis le navigateur) ;
 * - classement par examen (Study Instance UID) et par patient : n° de demande (Accession Number)
 *   ou n° de dossier du patient saisi dans l'appareil (Patient ID) ; sinon examen « à rattacher » ;
 * - demandes d'examen, compte rendu (technique, résultats, conclusion), validation par le médecin,
 *   annotations et mesures enregistrées.
 * Les fichiers d'origine sont conservés intacts (empreinte SHA-256) hors du dossier public.
 */
final class ImagingService
{
    public const MODALITIES = ['CR' => 'Radiographie (CR)', 'DX' => 'Radiographie numérique (DR/DX)', 'US' => 'Échographie', 'CT' => 'Scanner (TDM)', 'MR' => 'IRM',
        'MG' => 'Mammographie', 'XA' => 'Angiographie', 'RF' => 'Radioscopie', 'IO' => 'Radio dentaire', 'PX' => 'Panoramique dentaire', 'ES' => 'Endoscopie', 'OT' => 'Autre / photo'];
    public const STATUS = ['a_rattacher' => 'À rattacher à un patient', 'a_lire' => 'À lire', 'lu' => 'Compte rendu rédigé', 'valide' => 'Compte rendu validé'];
    public const MAX_UPLOAD = 200 * 1024 * 1024;

    public static function root(string $sub = ''): string
    {
        $d = STORAGE_PATH . '/imagerie' . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function settings(): array
    {
        return [
            'aet' => strtoupper((string) setting('clinique_imagerie_aet', 'POSMARWA')) ?: 'POSMARWA',
            'port' => max(104, min(65535, (int) setting('clinique_imagerie_port', '11112'))),
            'unknown' => setting('clinique_imagerie_appareils_inconnus', '1') === '1',
        ];
    }

    public static function saveSettings(array $in): void
    {
        $aet = strtoupper(trim((string) ($in['aet'] ?? '')));
        if (!preg_match('/^[A-Z0-9_\-]{1,16}$/', $aet)) {
            throw new BusinessException('Titre AE : 1 à 16 caractères (lettres, chiffres, _ ou -), ex. POSMARWA.');
        }
        $port = (int) ($in['port'] ?? 0);
        if ($port < 104 || $port > 65535) {
            throw new BusinessException('Port : entre 104 et 65535 (11112 conseillé).');
        }
        setting_set('clinique_imagerie_aet', $aet);
        setting_set('clinique_imagerie_port', (string) $port);
        setting_set('clinique_imagerie_appareils_inconnus', empty($in['unknown']) ? '0' : '1');
        Audit::log('clinique.imagerie.reglages', null, null, ['aet' => $aet, 'port' => $port, 'inconnus' => !empty($in['unknown'])]);
    }

    /** État du récepteur (fichier de présence écrit par bin/imagerie.php serveur). */
    public static function receiverStatus(): array
    {
        $f = self::root() . '/recepteur.json';
        $s = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $s['running'] = !empty($s['beat']) && strtotime((string) $s['beat']) > time() - 30;
        return $s;
    }

    // ------------------------------------------------------------------ appareils

    public static function devices(): array
    {
        return DB::all('SELECT * FROM clinic_imaging_devices ORDER BY active DESC, name');
    }

    public static function saveDevice(array $in, ?int $id = null): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        $ae = strtoupper(trim((string) ($in['ae_title'] ?? '')));
        $ip = trim((string) ($in['ip'] ?? ''));
        $mod = strtoupper((string) ($in['modality'] ?? 'OT'));
        if (mb_strlen($name) < 2) {
            throw new BusinessException('Nom de l\'appareil obligatoire (ex. « Radio salle 1 »).');
        }
        if ($ae !== '' && !preg_match('/^[A-Z0-9_\- ]{1,16}$/', $ae)) {
            throw new BusinessException('Titre AE de l\'appareil invalide (16 caractères au plus).');
        }
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new BusinessException('Adresse IP invalide.');
        }
        $data = ['name' => mb_substr($name, 0, 100), 'modality' => isset(self::MODALITIES[$mod]) ? $mod : 'OT', 'ae_title' => $ae ?: null, 'ip' => $ip ?: null,
            'active' => array_key_exists('active', $in) ? (empty($in['active']) ? 0 : 1) : 1, 'auto_detected' => 0];
        if ($id) {
            DB::update('clinic_imaging_devices', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('clinic_imaging_devices', $data);
        }
        Audit::log('clinique.imagerie.appareil', 'clinic_imaging_device', $id, ['nom' => $name, 'ae' => $ae, 'ip' => $ip, 'actif' => $data['active']]);
        return $id;
    }

    /**
     * Appareil qui se présente au récepteur : connu (AE / IP) → autorisé s'il est actif ;
     * inconnu → enregistré automatiquement « détecté » et autorisé si le réglage le permet.
     */
    public static function deviceFor(string $ae, string $ip, bool $register): ?array
    {
        $d = DB::one('SELECT * FROM clinic_imaging_devices WHERE (ae_title = ? AND (ip IS NULL OR ip = ?)) OR (ae_title IS NULL AND ip = ?) ORDER BY ae_title IS NULL, id LIMIT 1', [$ae, $ip, $ip]);
        if ($d) {
            return (int) $d['active'] ? $d : null;
        }
        if (!self::settings()['unknown']) {
            return null;
        }
        if ($register) {
            $id = DB::insert('clinic_imaging_devices', ['name' => 'Appareil ' . ($ae ?: $ip), 'modality' => 'OT', 'ae_title' => $ae ?: null, 'ip' => $ip ?: null, 'auto_detected' => 1]);
            return DB::one('SELECT * FROM clinic_imaging_devices WHERE id = ?', [$id]);
        }
        return ['id' => null];
    }

    // ------------------------------------------------------------------ réception / classement

    /**
     * Classe un fichier reçu (DICOM, JPEG ou PNG) : copie dans storage/imagerie, examen, image, rattachement.
     * @param array{source?:string, device_id?:?int, patient_id?:?int, request_id?:?int, study_id?:?int, description?:string} $ctx
     * @return array{image_id:int, study_id:int, duplicate:bool, matched:bool}
     */
    public static function ingest(string $path, array $ctx = []): array
    {
        $size = (int) @filesize($path);
        if ($size <= 0) {
            throw new BusinessException('Fichier vide.');
        }
        if ($size > self::MAX_UPLOAD) {
            throw new BusinessException('Fichier trop volumineux (200 Mo au plus).');
        }
        $sha = hash_file('sha256', $path);
        $source = in_array($ctx['source'] ?? '', ['reseau', 'dossier', 'import'], true) ? $ctx['source'] : 'import';
        if (Dicom::looks($path)) {
            $info = Dicom::info(Dicom::parse($path));
            if (!$info['sop_uid'] || !$info['study_uid']) {
                throw new BusinessException('Fichier DICOM sans identifiant d\'examen ou d\'image.');
            }
            $format = 'dicom';
            $ext = 'dcm';
        } else {
            $gi = @getimagesize($path);
            if (!$gi || !in_array($gi[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                throw new BusinessException('Format non reconnu : DICOM, JPEG ou PNG uniquement.');
            }
            $format = $gi[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
            $ext = $format === 'png' ? 'png' : 'jpg';
            $info = ['sop_uid' => Dicom::uid(), 'study_uid' => null, 'series_uid' => null, 'series_number' => null, 'instance_number' => null, 'series_description' => null,
                'study_description' => null, 'modality' => 'OT', 'accession' => null, 'study_date' => date('Y-m-d H:i:s'), 'patient_name' => null, 'patient_id' => null,
                'birth_date' => null, 'sex' => null, 'body_part' => null, 'cols' => $gi[0], 'rows' => $gi[1], 'frames' => 1, 'pixel_spacing' => null,
                'transfer_syntax' => null, 'renderable' => true, 'render_note' => null];
        }
        return DB::transaction(function () use ($path, $sha, $size, $format, $ext, $info, $ctx, $source) {
            if ($dup = DB::one('SELECT id, study_id FROM clinic_imaging_images WHERE sop_uid = ? OR sha256 = ?', [$info['sop_uid'], $sha])) {
                return ['image_id' => (int) $dup['id'], 'study_id' => (int) $dup['study_id'], 'duplicate' => true, 'matched' => true];
            }
            // Examen : celui demandé par l'appelant, sinon le Study Instance UID, sinon un nouvel examen
            $study = null;
            if (!empty($ctx['study_id'])) {
                $study = DB::one('SELECT * FROM clinic_imaging_studies WHERE id = ? FOR UPDATE', [(int) $ctx['study_id']]);
            } elseif ($info['study_uid']) {
                $study = DB::one('SELECT * FROM clinic_imaging_studies WHERE study_uid = ? FOR UPDATE', [$info['study_uid']]);
            }
            $matched = true;
            if (!$study) {
                [$patientId, $requestId] = self::match($info, (int) ($ctx['patient_id'] ?? 0), (int) ($ctx['request_id'] ?? 0));
                $matched = $patientId !== null;
                $sid = DB::insert('clinic_imaging_studies', [
                    'study_uid' => $info['study_uid'] ?: Dicom::uid(), 'patient_id' => $patientId, 'request_id' => $requestId, 'device_id' => $ctx['device_id'] ?? null,
                    'source' => $source, 'modality' => $info['modality'], 'description' => mb_substr((string) ($ctx['description'] ?? '') ?: (string) $info['study_description'], 0, 150) ?: null,
                    'accession' => $info['accession'], 'study_date' => $info['study_date'], 'dicom_patient_name' => $info['patient_name'], 'dicom_patient_id' => $info['patient_id'],
                    'dicom_birth_date' => $info['birth_date'], 'dicom_sex' => $info['sex'], 'status' => $patientId ? 'a_lire' : 'a_rattacher',
                ]);
                if ($requestId) {
                    DB::update('clinic_imaging_requests', ['status' => 'realise', 'study_id' => $sid], 'id = ?', [$requestId]);
                }
                $study = DB::one('SELECT * FROM clinic_imaging_studies WHERE id = ?', [$sid]);
            }
            $sid = (int) $study['id'];
            if (!$study['patient_id'] && !empty($ctx['patient_id'])) {
                // Images importées depuis le dossier d'un patient pour un examen encore « à rattacher »
                DB::update('clinic_imaging_studies', ['patient_id' => (int) $ctx['patient_id'], 'status' => 'a_lire'], 'id = ?', [$sid]);
            }
            $rel = date('Y/m') . '/' . $sha . '.' . $ext;
            $dest = self::root(date('Y/m')) . '/' . $sha . '.' . $ext;
            if (!is_file($dest) && !@copy($path, $dest)) {
                throw new BusinessException('Écriture impossible dans storage/imagerie (droits ou disque plein).');
            }
            $iid = DB::insert('clinic_imaging_images', [
                'study_id' => $sid, 'series_uid' => $info['series_uid'], 'series_number' => $info['series_number'], 'series_description' => $info['series_description'],
                'sop_uid' => $info['sop_uid'], 'instance_number' => $info['instance_number'], 'format' => $format, 'file_path' => $rel, 'file_size' => $size, 'sha256' => $sha,
                'transfer_syntax' => $info['transfer_syntax'], 'width' => $info['cols'], 'height' => $info['rows'], 'frames' => $info['frames'],
                'pixel_spacing' => $info['pixel_spacing'], 'body_part' => $info['body_part'], 'renderable' => $info['renderable'] ? 1 : 0, 'render_note' => $info['render_note'],
            ]);
            DB::run('UPDATE clinic_imaging_studies SET images = images + 1, modality = COALESCE(modality, ?) WHERE id = ?', [$info['modality'], $sid]);
            if (!empty($ctx['device_id'])) {
                DB::run('UPDATE clinic_imaging_devices SET images_count = images_count + 1, last_seen = NOW() WHERE id = ?', [(int) $ctx['device_id']]);
            }
            return ['image_id' => $iid, 'study_id' => $sid, 'duplicate' => false, 'matched' => $matched];
        });
    }

    /**
     * Patient de l'examen : choisi par l'utilisateur, sinon n° de demande (Accession), sinon n° de dossier
     * saisi dans l'appareil (Patient ID). Jamais par le nom seul (homonymes).
     * @return array{0:?int,1:?int} [patient_id, request_id]
     */
    public static function match(array $info, int $patientId = 0, int $requestId = 0): array
    {
        if ($requestId) {
            $r = DB::one("SELECT id, patient_id FROM clinic_imaging_requests WHERE id = ? AND status <> 'annule'", [$requestId]);
            return $r ? [(int) $r['patient_id'], (int) $r['id']] : [$patientId ?: null, null];
        }
        if ($info['accession']) {
            $r = DB::one("SELECT id, patient_id FROM clinic_imaging_requests WHERE number = ? AND status = 'demande'", [trim((string) $info['accession'])]);
            if ($r && (!$patientId || (int) $r['patient_id'] === $patientId)) {
                return [(int) $r['patient_id'], (int) $r['id']];
            }
        }
        if ($patientId) {
            return [$patientId, null];
        }
        if ($info['patient_id']) {
            $norm = strtoupper(preg_replace('/\s+/', '', (string) $info['patient_id']));
            $p = DB::value('SELECT id FROM clinic_patients WHERE UPPER(REPLACE(number, \' \', \'\')) = ? AND active = 1', [$norm]);
            if ($p) {
                return [(int) $p, null];
            }
        }
        return [null, null];
    }

    /** Dossier d'arrivée : chaque fichier déposé est classé puis déplacé (traités / erreurs). */
    public static function scanFolder(?callable $log = null): int
    {
        $in = self::root('arrivee');
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($in, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getMTime() > time() - 3) {
                continue; // fichier encore en cours de copie
            }
            $path = $f->getPathname();
            $name = $f->getFilename();
            if (str_starts_with($name, '.') || preg_match('/\.(tmp|part|crdownload|lnk|ini|db)$/i', $name)) {
                continue;
            }
            try {
                $r = self::ingest($path, ['source' => 'dossier']);
                @rename($path, self::root('arrivee-traites/' . date('Y-m-d')) . '/' . $name);
                $n++;
                $log && $log('Dossier : ' . $name . ($r['duplicate'] ? ' (déjà reçu)' : ' → examen n° ' . $r['study_id'] . ($r['matched'] ? '' : ' (à rattacher)')));
            } catch (\Throwable $e) {
                @rename($path, self::root('arrivee-erreurs') . '/' . date('Ymd-His') . '-' . $name);
                $log && $log('Dossier : ' . $name . ' refusé : ' . $e->getMessage());
                Logger::warning('Imagerie : fichier refusé', ['fichier' => $name, 'erreur' => $e->getMessage()]);
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ examens

    public static function study(int $id): array
    {
        $s = DB::one('SELECT s.*, p.number AS patient_number, p.last_name, p.first_name, p.sex, p.birth_date, d.name AS device_name, r.number AS request_number, r.exam AS request_exam,
                r.clinical_info, ru.full_name AS requester, rp.full_name AS reporter, vu.full_name AS validator
                FROM clinic_imaging_studies s LEFT JOIN clinic_patients p ON p.id = s.patient_id LEFT JOIN clinic_imaging_devices d ON d.id = s.device_id
                LEFT JOIN clinic_imaging_requests r ON r.id = s.request_id LEFT JOIN users ru ON ru.id = r.requested_by
                LEFT JOIN users rp ON rp.id = s.reported_by LEFT JOIN users vu ON vu.id = s.validated_by WHERE s.id = ?', [$id]);
        if (!$s) {
            throw new BusinessException('Examen introuvable.');
        }
        return $s;
    }

    public static function images(int $studyId): array
    {
        return DB::all('SELECT * FROM clinic_imaging_images WHERE study_id = ? ORDER BY series_number IS NULL, series_number, series_uid, instance_number IS NULL, instance_number, id', [$studyId]);
    }

    public static function image(int $id): array
    {
        $i = DB::one('SELECT i.*, s.patient_id, s.status AS study_status FROM clinic_imaging_images i JOIN clinic_imaging_studies s ON s.id = i.study_id WHERE i.id = ?', [$id]);
        if (!$i) {
            throw new BusinessException('Image introuvable.');
        }
        return $i;
    }

    public static function path(array $img): string
    {
        $p = self::root() . '/' . $img['file_path'];
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{64}\.(dcm|jpg|png)$#', (string) $img['file_path']) || !is_file($p)) {
            throw new BusinessException('Fichier image absent du disque (storage/imagerie).');
        }
        return $p;
    }

    /** Liste filtrée (à lire, à rattacher, par patient…). */
    public static function list(array $f, int $limit = 200): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['status']) && isset(self::STATUS[$f['status']])) {
            $where[] = 's.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['patient_id'])) {
            $where[] = 's.patient_id = ?';
            $params[] = (int) $f['patient_id'];
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
            $where[] = '(p.last_name LIKE ? OR p.first_name LIKE ? OR p.number LIKE ? OR s.dicom_patient_name LIKE ? OR s.dicom_patient_id LIKE ? OR s.accession LIKE ? OR s.description LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        return DB::all('SELECT s.*, p.number AS patient_number, p.last_name, p.first_name, d.name AS device_name,
                (SELECT MIN(i.id) FROM clinic_imaging_images i WHERE i.study_id = s.id AND i.renderable = 1) AS first_image
                FROM clinic_imaging_studies s LEFT JOIN clinic_patients p ON p.id = s.patient_id LEFT JOIN clinic_imaging_devices d ON d.id = s.device_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(s.status, \'a_rattacher\', \'a_lire\', \'lu\', \'valide\'), COALESCE(s.study_date, s.created_at) DESC LIMIT ' . max(1, min(1000, $limit)), $params);
    }

    /** Rattachement manuel d'un examen à un patient (vérifié à l'écran par l'utilisateur). */
    public static function attach(int $studyId, int $patientId, ?int $requestId = null): void
    {
        DB::transaction(function () use ($studyId, $patientId, $requestId) {
            $s = DB::one('SELECT * FROM clinic_imaging_studies WHERE id = ? FOR UPDATE', [$studyId]);
            if (!$s) {
                throw new BusinessException('Examen introuvable.');
            }
            if (!DB::value('SELECT id FROM clinic_patients WHERE id = ?', [$patientId])) {
                throw new BusinessException('Patient introuvable.');
            }
            $data = ['patient_id' => $patientId, 'status' => in_array($s['status'], ['lu', 'valide'], true) ? $s['status'] : 'a_lire'];
            if ($requestId) {
                $r = DB::one("SELECT * FROM clinic_imaging_requests WHERE id = ? AND patient_id = ? AND status <> 'annule'", [$requestId, $patientId]);
                if (!$r) {
                    throw new BusinessException('Demande d\'examen introuvable pour ce patient.');
                }
                $data['request_id'] = $requestId;
                DB::update('clinic_imaging_requests', ['status' => 'realise', 'study_id' => $studyId], 'id = ?', [$requestId]);
            }
            DB::update('clinic_imaging_studies', $data, 'id = ?', [$studyId]);
            Audit::log('clinique.imagerie.rattachement', 'clinic_imaging_study', $studyId, ['avant' => $s['patient_id'], 'patient' => $patientId, 'demande' => $requestId]);
        });
    }

    /** Compte rendu : enregistrer (brouillon « rédigé ») ou valider (signé, modifiable seulement par un nouveau compte rendu). */
    public static function saveReport(int $studyId, array $in, bool $validate): void
    {
        DB::transaction(function () use ($studyId, $in, $validate) {
            $s = DB::one('SELECT * FROM clinic_imaging_studies WHERE id = ? FOR UPDATE', [$studyId]);
            if (!$s) {
                throw new BusinessException('Examen introuvable.');
            }
            if (!$s['patient_id']) {
                throw new BusinessException('Rattachez d\'abord l\'examen à un patient.');
            }
            if ($s['status'] === 'valide' && empty($in['reopen'])) {
                throw new BusinessException('Compte rendu déjà validé : utilisez « Rouvrir » (motif tracé) pour le corriger.');
            }
            $findings = trim((string) ($in['findings'] ?? ''));
            $conclusion = trim((string) ($in['conclusion'] ?? ''));
            if ($validate && ($findings === '' || $conclusion === '')) {
                throw new BusinessException('Résultats et conclusion obligatoires pour valider.');
            }
            DB::update('clinic_imaging_studies', [
                'technique' => mb_substr(trim((string) ($in['technique'] ?? '')), 0, 500) ?: null, 'findings' => mb_substr($findings, 0, 20000) ?: null,
                'conclusion' => mb_substr($conclusion, 0, 5000) ?: null, 'status' => $validate ? 'valide' : 'lu',
                'reported_by' => Auth::id(), 'reported_at' => date('Y-m-d H:i:s'),
                'validated_by' => $validate ? Auth::id() : null, 'validated_at' => $validate ? date('Y-m-d H:i:s') : null,
            ], 'id = ?', [$studyId]);
            Audit::log($validate ? 'clinique.imagerie.cr_valide' : 'clinique.imagerie.cr', 'clinic_imaging_study', $studyId, null);
        });
    }

    public static function reopen(int $studyId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Indiquez le motif de la correction.');
        }
        $n = DB::update('clinic_imaging_studies', ['status' => 'lu', 'validated_by' => null, 'validated_at' => null], "id = ? AND status = 'valide'", [$studyId]);
        if (!$n) {
            throw new BusinessException('Compte rendu non validé.');
        }
        Audit::log('clinique.imagerie.cr_rouvert', 'clinic_imaging_study', $studyId, ['motif' => mb_substr($reason, 0, 200)]);
    }

    // ------------------------------------------------------------------ demandes d'examen

    public static function createRequest(array $in): int
    {
        $pid = (int) ($in['patient_id'] ?? 0);
        $exam = trim((string) ($in['exam'] ?? ''));
        $mod = strtoupper((string) ($in['modality'] ?? 'CR'));
        if (!DB::value('SELECT id FROM clinic_patients WHERE id = ?', [$pid])) {
            throw new BusinessException('Patient introuvable.');
        }
        if (mb_strlen($exam) < 3) {
            throw new BusinessException('Indiquez l\'examen demandé (ex. « Radiographie du thorax de face »).');
        }
        if (!isset(self::MODALITIES[$mod])) {
            throw new BusinessException('Type d\'appareil inconnu.');
        }
        $visit = (int) ($in['visit_id'] ?? 0);
        if ($visit && !DB::value('SELECT id FROM clinic_visits WHERE id = ? AND patient_id = ?', [$visit, $pid])) {
            $visit = 0;
        }
        $id = DB::insert('clinic_imaging_requests', ['number' => Sequence::next('imagerie'), 'patient_id' => $pid, 'visit_id' => $visit ?: null, 'modality' => $mod,
            'exam' => mb_substr($exam, 0, 150), 'clinical_info' => mb_substr(trim((string) ($in['clinical_info'] ?? '')), 0, 500) ?: null,
            'priority' => ($in['priority'] ?? '') === 'urgent' ? 'urgent' : 'normal', 'requested_by' => Auth::id()]);
        Audit::log('clinique.imagerie.demande', 'clinic_imaging_request', $id, ['patient' => $pid, 'examen' => $exam]);
        return $id;
    }

    public static function cancelRequest(int $id): void
    {
        if (!DB::update('clinic_imaging_requests', ['status' => 'annule'], "id = ? AND status = 'demande'", [$id])) {
            throw new BusinessException('Demande déjà réalisée ou annulée.');
        }
        Audit::log('clinique.imagerie.demande_annulee', 'clinic_imaging_request', $id, null);
    }

    public static function pendingRequests(?int $patientId = null): array
    {
        return DB::all("SELECT r.*, p.number AS patient_number, p.last_name, p.first_name, u.full_name AS requester FROM clinic_imaging_requests r
                JOIN clinic_patients p ON p.id = r.patient_id JOIN users u ON u.id = r.requested_by WHERE r.status = 'demande'" . ($patientId ? ' AND r.patient_id = ' . (int) $patientId : '') . '
                ORDER BY r.priority = \'urgent\' DESC, r.id', []);
    }

    // ------------------------------------------------------------------ annotations et mesures

    public const ANNOTATION_KINDS = ['distance', 'angle', 'roi', 'fleche', 'texte'];

    public static function annotations(int $imageId): array
    {
        return array_map(fn($a) => ['id' => (int) $a['id'], 'kind' => $a['kind'], 'data' => json_decode($a['data'], true), 'label' => $a['label'], 'user' => $a['user_name'], 'at' => $a['created_at']],
            DB::all('SELECT a.*, u.full_name AS user_name FROM clinic_imaging_annotations a JOIN users u ON u.id = a.user_id WHERE a.image_id = ? AND a.deleted_at IS NULL ORDER BY a.id', [$imageId]));
    }

    public static function addAnnotation(int $imageId, array $in): int
    {
        self::image($imageId);
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::ANNOTATION_KINDS, true)) {
            throw new BusinessException('Type d\'annotation inconnu.');
        }
        $pts = (array) ($in['points'] ?? []);
        $need = ['distance' => 2, 'angle' => 3, 'roi' => 2, 'fleche' => 2, 'texte' => 1][$kind];
        if (count($pts) !== $need) {
            throw new BusinessException('Points de l\'annotation invalides.');
        }
        $clean = [];
        foreach ($pts as $p) {
            if (!is_array($p) || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) {
                throw new BusinessException('Points de l\'annotation invalides.');
            }
            $clean[] = [round((float) $p[0], 2), round((float) $p[1], 2)];
        }
        $data = ['points' => $clean, 'frame' => max(0, (int) ($in['frame'] ?? 0))];
        if (isset($in['value']) && is_string($in['value'])) {
            $data['value'] = mb_substr($in['value'], 0, 120);
        }
        $id = DB::insert('clinic_imaging_annotations', ['image_id' => $imageId, 'kind' => $kind, 'data' => json_encode($data),
            'label' => mb_substr(trim((string) ($in['label'] ?? '')), 0, 150) ?: null, 'user_id' => Auth::id()]);
        Audit::log('clinique.imagerie.annotation', 'clinic_imaging_image', $imageId, ['type' => $kind]);
        return $id;
    }

    public static function deleteAnnotation(int $id): void
    {
        DB::update('clinic_imaging_annotations', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ? AND deleted_at IS NULL', [$id]);
    }

    /** Miniature PNG en cache (générée à la première demande). */
    public static function thumbnail(array $img, int $size = 256): string
    {
        $cache = self::root('cache') . '/t' . $size . '_' . $img['sha256'] . '.png';
        if (is_file($cache)) {
            return $cache;
        }
        $path = self::path($img);
        if ($img['format'] === 'dicom') {
            $png = Dicom::png($path, $size);
        } else {
            $src = @imagecreatefromstring((string) file_get_contents($path));
            if (!$src) {
                throw new BusinessException('Image illisible.');
            }
            $r = min(1, $size / max(imagesx($src), imagesy($src)));
            $dst = imagecreatetruecolor(max(1, (int) (imagesx($src) * $r)), max(1, (int) (imagesy($src) * $r)));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), imagesx($src), imagesy($src));
            ob_start();
            imagepng($dst);
            $png = (string) ob_get_clean();
        }
        file_put_contents($cache, $png);
        return $cache;
    }
}
