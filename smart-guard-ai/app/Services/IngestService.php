<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Réception des événements envoyés par le service IA. Idempotent (uuid unique) :
 * un même événement renvoyé après une coupure n'est jamais compté deux fois.
 */
final class IngestService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @return array{accepted:int, duplicates:int, rejected:int, errors:array} */
    public static function handle(array $events): array
    {
        $res = ['accepted' => 0, 'duplicates' => 0, 'rejected' => 0, 'errors' => []];
        foreach (array_slice($events, 0, 200) as $i => $ev) {
            try {
                if (!is_array($ev) || !isset($ev['type'], $ev['uuid'], $ev['camera_id']) || !preg_match(self::UUID_RE, (string) $ev['uuid'])) {
                    throw new \InvalidArgumentException('événement mal formé');
                }
                $cam = Database::fetch('SELECT id, store_id, name FROM cameras WHERE id = :id', ['id' => (int) $ev['camera_id']]);
                if (!$cam) {
                    throw new \InvalidArgumentException('caméra inconnue');
                }
                $done = match ($ev['type']) {
                    'camera_status' => self::cameraStatus($cam, $ev),
                    'track_closed' => self::trackClosed($cam, $ev),
                    'line_event' => self::lineEvent($cam, $ev),
                    default => throw new \InvalidArgumentException('type inconnu'),
                };
                $done ? $res['accepted']++ : $res['duplicates']++;
            } catch (\Throwable $e) {
                $res['rejected']++;
                $res['errors'][] = ['index' => $i, 'error' => $e->getMessage()];
                Logger::warning('Événement IA rejeté : ' . $e->getMessage(), ['uuid' => $ev['uuid'] ?? null]);
            }
        }
        return $res;
    }

    /** Accepte ISO 8601 avec fuseau (envoyé par le service IA, en UTC) ou « AAAA-MM-JJ HH:MM:SS[.mmm] » (heure locale). */
    public static function ts(mixed $v): string
    {
        $s = (string) $v;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $s)) {
            $d = new \DateTimeImmutable($s);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $s)) {
            $d = new \DateTimeImmutable($s, new \DateTimeZone(date_default_timezone_get()));
        } else {
            throw new \InvalidArgumentException('horodatage invalide');
        }
        $d = $d->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        if ($d->getTimestamp() > time() + 600) {
            throw new \InvalidArgumentException('horodatage dans le futur (horloge du service IA déréglée ?)');
        }
        return $d->format('Y-m-d H:i:s.v');
    }

    private static function conf(mixed $v): ?float
    {
        return $v === null ? null : max(0.0, min(1.0, (float) $v));
    }

    private static function cameraStatus(array $cam, array $ev): bool
    {
        $allowed = ['connexion', 'deconnexion', 'reconnexion', 'flux_inutilisable', 'analyse_demarree', 'analyse_arretee', 'erreur'];
        if (!in_array($ev['event_type'] ?? '', $allowed, true)) {
            throw new \InvalidArgumentException('statut caméra invalide');
        }
        if (Database::value('SELECT 1 FROM camera_events WHERE uuid = :u', ['u' => $ev['uuid']])) {
            return false;
        }
        $at = self::ts($ev['occurred_at'] ?? '');
        Database::insert('camera_events', ['uuid' => $ev['uuid'], 'camera_id' => $cam['id'], 'event_type' => $ev['event_type'],
            'message' => mb_substr((string) ($ev['message'] ?? ''), 0, 255), 'occurred_at' => $at]);
        $status = match ($ev['event_type']) {
            'connexion', 'reconnexion', 'analyse_demarree' => 'en_ligne',
            'deconnexion' => 'hors_ligne',
            'erreur', 'flux_inutilisable' => 'erreur',
            default => null,
        };
        $upd = ['last_seen_at' => substr($at, 0, 19)];
        if ($status) {
            $upd['status'] = $status;
        }
        if (in_array($ev['event_type'], ['erreur', 'flux_inutilisable', 'deconnexion'], true)) {
            $upd['last_error'] = mb_substr((string) ($ev['message'] ?? ''), 0, 255);
        }
        Database::update('cameras', $upd, 'id = :id', ['id' => $cam['id']]);
        $notif = match ($ev['event_type']) {
            'deconnexion' => ['Caméra déconnectée : ' . $cam['name'], 'danger'],
            'reconnexion' => ['Caméra reconnectée : ' . $cam['name'], 'success'],
            'flux_inutilisable' => ['Flux vidéo inutilisable : ' . $cam['name'], 'warning'],
            'erreur' => ['Erreur caméra : ' . $cam['name'], 'danger'],
            default => null,
        };
        if ($notif) {
            NotificationService::notify((int) $cam['store_id'], 'camera', $notif[0], $notif[1], (string) ($ev['message'] ?? ''),
                'cameras/' . $cam['id'], 'cam_' . $cam['id'] . '_' . $ev['event_type'], 10);
        }
        return true;
    }

    /** Crée ou complète la piste (object_tracks) correspondant à un événement. */
    private static function upsertTrack(array $cam, array $ev, string $endReason): int
    {
        $session = substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) ($ev['session_id'] ?? 'na')), 0, 40);
        $num = (int) ($ev['track_number'] ?? 0);
        $productId = self::validProduct($cam, $ev['product_id'] ?? null);
        $existing = Database::fetch('SELECT id, frames_seen FROM object_tracks WHERE camera_id = :c AND session_id = :s AND track_number = :n',
            ['c' => $cam['id'], 's' => $session, 'n' => $num]);
        $first = isset($ev['first_seen_at']) ? self::ts($ev['first_seen_at']) : self::ts($ev['occurred_at']);
        $last = isset($ev['last_seen_at']) ? self::ts($ev['last_seen_at']) : self::ts($ev['occurred_at']);
        if ($existing) {
            Database::update('object_tracks', [
                'product_id' => $productId, 'best_confidence' => self::conf($ev['confidence'] ?? null),
                'frames_seen' => max((int) $existing['frames_seen'], (int) ($ev['frames_seen'] ?? $ev['track_frames'] ?? 0)),
                'last_seen_at' => $last, 'end_reason' => $endReason,
            ], 'id = :id', ['id' => $existing['id']]);
            return (int) $existing['id'];
        }
        return Database::insert('object_tracks', [
            'uuid' => uuid4(), 'camera_id' => $cam['id'], 'session_id' => $session, 'track_number' => $num, 'product_id' => $productId,
            'best_confidence' => self::conf($ev['confidence'] ?? null), 'frames_seen' => (int) ($ev['frames_seen'] ?? $ev['track_frames'] ?? 0),
            'first_seen_at' => $first, 'last_seen_at' => $last, 'end_reason' => $endReason,
        ]);
    }

    private static function validProduct(array $cam, mixed $pid): ?int
    {
        if ($pid === null || $pid === '') {
            return null;
        }
        $ok = Database::value('SELECT id FROM products WHERE id = :id AND store_id = :s', ['id' => (int) $pid, 's' => $cam['store_id']]);
        return $ok ? (int) $ok : null;
    }

    private static function modelVersionId(mixed $mv): ?int
    {
        if (!is_array($mv) || !isset($mv['model_id'], $mv['version'])) {
            return null;
        }
        $id = Database::value('SELECT id FROM ai_model_versions WHERE ai_model_id = :m AND version_number = :v', ['m' => (int) $mv['model_id'], 'v' => (int) $mv['version']]);
        return $id ? (int) $id : null;
    }

    private static function detection(array $cam, array $ev, int $trackId, string $at, ?string $uuid = null): void
    {
        $bbox = array_map('floatval', array_slice(array_pad((array) ($ev['bbox'] ?? []), 4, 0), 0, 4));
        $bbox = array_map(static fn ($v) => max(0.0, min(1.0, $v)), $bbox);
        Database::insert('detection_results', [
            'uuid' => $uuid ?? uuid4(), 'camera_id' => $cam['id'], 'track_id' => $trackId, 'product_id' => self::validProduct($cam, $ev['product_id'] ?? null),
            'model_version_id' => self::modelVersionId($ev['model_version'] ?? null), 'label' => mb_substr((string) ($ev['label'] ?? 'Produit non identifié'), 0, 150),
            'detector_label' => mb_substr((string) ($ev['detector_label'] ?? ''), 0, 60) ?: null,
            'confidence' => self::conf($ev['confidence'] ?? 0) ?? 0, 'detector_confidence' => self::conf($ev['detector_confidence'] ?? null),
            'bbox_x' => $bbox[0], 'bbox_y' => $bbox[1], 'bbox_w' => $bbox[2], 'bbox_h' => $bbox[3], 'detected_at' => $at,
        ]);
    }

    private static function trackClosed(array $cam, array $ev): bool
    {
        // déduplication par uuid via detection_results
        if (Database::value('SELECT 1 FROM detection_results WHERE uuid = :u', ['u' => $ev['uuid']])) {
            return false;
        }
        $end = in_array($ev['end_reason'] ?? '', ['sorti_du_champ', 'perdu'], true) ? $ev['end_reason'] : 'perdu';
        return (bool) Database::transaction(static function () use ($cam, $ev, $end) {
            $trackId = self::upsertTrack($cam, $ev, $end);
            $at = self::ts($ev['last_seen_at'] ?? $ev['occurred_at'] ?? '');
            self::detection($cam, $ev, $trackId, $at, $ev['uuid']); // uuid de l'événement = idempotence
            return true;
        });
    }

    private static function lineEvent(array $cam, array $ev): bool
    {
        if (Database::value('SELECT 1 FROM exit_events WHERE uuid = :u', ['u' => $ev['uuid']])) {
            return false;
        }
        $direction = $ev['direction'] ?? '';
        if (!in_array($direction, ['sortie', 'entree', 'ambigu', 'perte_suivi'], true)) {
            throw new \InvalidArgumentException('direction invalide');
        }
        $at = self::ts($ev['occurred_at'] ?? '');
        $lineId = isset($ev['line_id']) ? (int) Database::value('SELECT id FROM virtual_lines WHERE id = :l AND camera_id = :c', ['l' => (int) $ev['line_id'], 'c' => $cam['id']]) : 0;

        $exit = Database::transaction(static function () use ($cam, $ev, $at, $direction, $lineId) {
            $trackId = self::upsertTrack($cam, $ev, 'franchissement');
            self::detection($cam, $ev, $trackId, $at);
            $snapshotId = self::media($cam, $ev, 'snapshot', (string) ($ev['snapshot_path'] ?? ''), 1, (int) ($ev['snapshot_size'] ?? 0), $at);
            $clipId = self::media($cam, $ev, 'extrait', (string) ($ev['clip_path'] ?? ''), (int) ($ev['clip_frames'] ?? 0), (int) ($ev['clip_size'] ?? 0), $at);
            $data = [
                'uuid' => $ev['uuid'], 'store_id' => $cam['store_id'], 'camera_id' => $cam['id'], 'virtual_line_id' => $lineId ?: null,
                'track_id' => $trackId, 'track_number' => (int) ($ev['track_number'] ?? 0), 'product_id' => self::validProduct($cam, $ev['product_id'] ?? null),
                'confidence' => self::conf($ev['confidence'] ?? null), 'direction' => $direction, 'snapshot_id' => $snapshotId, 'clip_id' => $clipId,
                'reconciliation_status' => 'en_attente', 'occurred_at' => $at,
            ];
            $data['id'] = Database::insert('exit_events', $data);
            return $data;
        });

        $recon = ReconciliationService::matchExit($exit);
        Database::update('exit_events', ['reconciliation_status' => $recon['status'], 'matched_sale_item_id' => $recon['sale_item_id'],
            'reconciliation_note' => mb_substr($recon['note'], 0, 255)], 'id = :id', ['id' => $exit['id']]);
        AlertService::createForExit($exit, $recon, (int) ($ev['track_frames'] ?? 0));
        return true;
    }

    /** Enregistre une image/un extrait écrit par le service IA, après validation stricte du chemin. */
    private static function media(array $cam, array $ev, string $kind, string $rel, int $frames, int $size, string $at): ?int
    {
        if ($rel === '') {
            return null;
        }
        $prefix = $kind === 'snapshot' ? 'snapshots/cam' . $cam['id'] . '/' : 'videos/cam' . $cam['id'] . '/';
        if (!str_starts_with($rel, $prefix) || str_contains($rel, '..') || !preg_match('#^[A-Za-z0-9/_.\-]+$#', $rel)) {
            throw new \InvalidArgumentException('chemin de média invalide');
        }
        $full = STORAGE_PATH . '/' . $rel;
        if (!file_exists($full)) {
            Logger::warning('Média annoncé introuvable', ['path' => $rel]);
            return null;
        }
        $days = (int) Settings::get($kind === 'snapshot' ? 'retention.snapshots_days' : 'retention.videos_days', 30);
        return Database::insert('video_records', [
            'uuid' => uuid4(), 'camera_id' => $cam['id'], 'kind' => $kind, 'file_path' => $rel, 'frame_count' => max(1, $frames),
            'size_bytes' => max(0, $size), 'started_at' => $at, 'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
        ]);
    }
}
