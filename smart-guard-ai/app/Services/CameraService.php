<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;

/** Construction des sources vidéo et pilotage des caméras sur le service IA. */
final class CameraService
{
    public static function find(int $id, int $storeId): ?array
    {
        return Database::fetch('SELECT * FROM cameras WHERE id = :id AND store_id = :s', ['id' => $id, 's' => $storeId]);
    }

    /** Source transmise au service IA. Le mot de passe est déchiffré uniquement ici, en mémoire. */
    public static function source(array $cam): array
    {
        if ($cam['type'] === 'usb') {
            return ['type' => 'usb', 'index' => (int) $cam['device_index'], 'width' => (int) $cam['resolution_w'],
                'height' => (int) $cam['resolution_h'], 'fps' => (int) $cam['target_fps']];
        }
        if (!NetworkGuard::ipAllowed((string) $cam['ip_address'])) {
            throw new \RuntimeException('Adresse IP de caméra non autorisée (voir CAMERA_ALLOWED_NETWORKS).');
        }
        return ['type' => 'url', 'url' => self::buildUrl($cam)];
    }

    public static function buildUrl(array $cam, bool $withSecret = true): string
    {
        $scheme = $cam['type'] === 'ip_http' ? 'http' : 'rtsp';
        $port = (int) ($cam['port'] ?: ($scheme === 'rtsp' ? 554 : 80));
        $auth = '';
        if (!empty($cam['username'])) {
            if ($withSecret) {
                $pwd = Crypto::decrypt($cam['password_enc'] ?? null) ?? '';
                $auth = rawurlencode($cam['username']) . ($pwd !== '' ? ':' . rawurlencode($pwd) : '') . '@';
            } else {
                $auth = rawurlencode($cam['username']) . (!empty($cam['password_enc']) ? ':***' : '') . '@';
            }
        }
        $path = '/' . ltrim((string) ($cam['stream_path'] ?? ''), '/');
        return "{$scheme}://{$auth}{$cam['ip_address']}:{$port}{$path}";
    }

    public static function lines(int $cameraId): array
    {
        $rows = Database::fetchAll('SELECT * FROM virtual_lines WHERE camera_id = :c AND is_active = 1', ['c' => $cameraId]);
        return array_map(static fn ($l) => [
            'id' => (int) $l['id'], 'name' => $l['name'], 'x1' => (float) $l['x1'], 'y1' => (float) $l['y1'],
            'x2' => (float) $l['x2'], 'y2' => (float) $l['y2'], 'exit_direction' => $l['exit_direction'],
            'min_track_frames' => (int) $l['min_track_frames'], 'hysteresis' => (float) $l['hysteresis'],
        ], $rows);
    }

    public static function start(array $cam): array
    {
        $payload = [
            'source' => self::source($cam),
            'analysis_fps' => (float) $cam['analysis_fps'],
            'analysis_enabled' => (bool) $cam['analysis_enabled'],
            'recording_enabled' => (bool) $cam['recording_enabled'],
            'lines' => self::lines((int) $cam['id']),
        ];
        return AiClient::request('POST', '/cameras/' . (int) $cam['id'] . '/start', $payload, ['timeout' => 15]);
    }

    public static function stop(int $id): array
    {
        return AiClient::request('POST', '/cameras/' . $id . '/stop', [], ['timeout' => 15]);
    }

    public static function pushConfig(array $cam): array
    {
        return AiClient::request('POST', '/cameras/' . (int) $cam['id'] . '/config', [
            'analysis_enabled' => (bool) $cam['analysis_enabled'],
            'recording_enabled' => (bool) $cam['recording_enabled'],
            'lines' => self::lines((int) $cam['id']),
        ]);
    }
}
