<?php
declare(strict_types=1);

namespace Addons\Vision;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Env;
use App\Services\LicenceService;

/**
 * Caméras utilisées par la reconnaissance et la surveillance.
 *
 *  - USB / webcam et carte d'acquisition (HDMI, SDI, table de mixage vidéo) : vues par Windows
 *    comme des caméras ; le navigateur du poste les ouvre directement (choix de l'appareil).
 *  - Caméra IP : le navigateur ne sait pas lire le RTSP ; POS Marwa lit l'image fixe (« snapshot »)
 *    ou le flux MJPEG de la caméra par HTTP, côté serveur, et la transmet au navigateur.
 *    Identifiants chiffrés en base, jamais envoyés au navigateur ; adresses limitées au réseau
 *    local (protection contre l'utilisation du serveur pour joindre d'autres sites).
 */
final class CameraService
{
    public const KINDS = [
        'usb' => 'Caméra USB / webcam',
        'carte' => 'Carte d\'acquisition / table de mixage (HDMI, SDI…)',
        'ip' => 'Caméra IP (réseau)',
    ];
    public const MAX_BYTES = 8 * 1024 * 1024;

    // ------------------------------------------------------------------ secret chiffré
    private static function key(): string
    {
        return hash('sha256', 'pos-marwa|vision-cameras|' . (string) Env::get('APP_KEY', 'poswan'), true);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(24);
        return base64_encode($nonce . LicenceService::sodium('crypto_secretbox', $plain, $nonce, self::key()));
    }

    public static function decrypt(?string $stored): string
    {
        if (!$stored) {
            return '';
        }
        $bin = base64_decode($stored, true);
        if ($bin === false || strlen($bin) < 40) {
            return '';
        }
        $out = LicenceService::sodium('crypto_secretbox_open', substr($bin, 24), substr($bin, 0, 24), self::key());
        return is_string($out) ? $out : '';
    }

    // ------------------------------------------------------------------ validation
    /** Adresse d'une caméra IP : http(s), sans redirection, hôte du réseau local (sauf autorisation). */
    public static function checkUrl(string $url): array
    {
        $p = parse_url($url);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            throw new BusinessException('Adresse de caméra invalide : elle doit commencer par http:// ou https://.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new BusinessException('Ne mettez pas l\'identifiant dans l\'adresse : utilisez les champs « Identifiant » et « Mot de passe » (ils sont chiffrés).');
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            throw new BusinessException('Nom d\'hôte introuvable : « ' . $host . ' ».');
        }
        if (setting('vision_cam_publique', '0') !== '1') {
            foreach ($ips as $ip) {
                $local = \App\Services\NetworkService::isPrivate($ip) || str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.');
                if (!$local) {
                    throw new BusinessException('La caméra « ' . $host . ' » n\'est pas sur le réseau local (' . $ip . '). Par sécurité, seules les adresses locales sont acceptées.');
                }
            }
        }
        return $ips;
    }

    public static function validate(array $in, ?array $current = null): array
    {
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 100));
        if ($name === '') {
            throw new BusinessException('Nom de la caméra obligatoire.');
        }
        $kind = (string) ($in['kind'] ?? 'usb');
        if (!isset(self::KINDS[$kind])) {
            throw new BusinessException('Type de caméra inconnu.');
        }
        $d = [
            'name' => $name, 'kind' => $kind,
            'device_label' => trim(mb_substr((string) ($in['device_label'] ?? ''), 0, 200)) ?: null,
            'url' => null, 'url_kind' => ($in['url_kind'] ?? '') === 'mjpeg' ? 'mjpeg' : 'snapshot', 'username' => null,
            'fps' => max(1, min(10, (int) ($in['fps'] ?? 3))),
            'use_caisse' => !empty($in['use_caisse']) ? 1 : 0, 'use_surveillance' => !empty($in['use_surveillance']) ? 1 : 0,
            'mode' => ($in['mode'] ?? '') === 'zone' ? 'zone' : 'ligne',
            'role' => isset(ParcoursService::ROLES[$in['role'] ?? '']) ? (string) $in['role'] : 'passage',
            'store_id' => (int) ($in['store_id'] ?? 0) ?: null,
            'place' => trim(mb_substr((string) ($in['place'] ?? ''), 0, 100)) ?: null,
            'register_id' => (int) ($in['register_id'] ?? 0) ?: null,
            'sensitivity' => max(1, min(10, (int) ($in['sensitivity'] ?? 5))),
            'active' => !isset($in['active']) || !empty($in['active']) ? 1 : 0,
        ];
        if ($d['store_id'] && !DB::value('SELECT 1 FROM stores WHERE id = ?', [$d['store_id']])) {
            throw new BusinessException('Boutique introuvable.');
        }
        if ($d['register_id']) {
            $rs = DB::value('SELECT store_id FROM cash_registers WHERE id = ?', [$d['register_id']]) ?: throw new BusinessException('Caisse introuvable.');
            if ($d['store_id'] && (int) $rs !== $d['store_id']) {
                throw new BusinessException('Cette caisse appartient à une autre boutique.');
            }
            $d['store_id'] ??= (int) $rs;
        }
        // Une caméra à rôle (étagère, caisse, sortie) est analysée par l'écran de surveillance ; caisse et sortie
        // comptent par zone ou ligne, l'étagère par emplacements.
        if ($d['role'] !== 'passage') {
            $d['use_surveillance'] = 1;
        }
        if ($d['role'] !== 'caisse') {
            $d['register_id'] = null;
        }
        if ($kind === 'ip') {
            $url = trim((string) ($in['url'] ?? ''));
            self::checkUrl($url);
            $d['url'] = mb_substr($url, 0, 500);
            $d['username'] = trim(mb_substr((string) ($in['username'] ?? ''), 0, 100)) ?: null;
            $pwd = (string) ($in['password'] ?? '');
            if ($pwd !== '') {
                $d['secret'] = self::encrypt($pwd);
            } elseif (!empty($in['clear_password']) || !$current) {
                $d['secret'] = null;
            }
        } else {
            $d['secret'] = null;
        }
        return $d;
    }

    /** Ligne de comptage / zone : coordonnées relatives (0 à 1) à l'image. */
    public static function geometry(array $g): string
    {
        $pt = function ($v): float {
            if (!is_numeric($v)) {
                throw new BusinessException('Coordonnées invalides.');
            }
            return round(max(0, min(1, (float) $v)), 4);
        };
        $out = [];
        if (isset($g['line'])) {
            $l = (array) $g['line'];
            $out['line'] = ['x1' => $pt($l['x1'] ?? null), 'y1' => $pt($l['y1'] ?? null), 'x2' => $pt($l['x2'] ?? null), 'y2' => $pt($l['y2'] ?? null)];
            if (abs($out['line']['x1'] - $out['line']['x2']) + abs($out['line']['y1'] - $out['line']['y2']) < 0.05) {
                throw new BusinessException('La ligne est trop courte.');
            }
            $out['invert'] = !empty($g['invert']);
        }
        if (isset($g['zone'])) {
            $z = (array) $g['zone'];
            $x = $pt($z['x'] ?? null); $y = $pt($z['y'] ?? null);
            $out['zone'] = ['x' => $x, 'y' => $y, 'w' => max(0.05, min(1 - $x, $pt($z['w'] ?? null))), 'h' => max(0.05, min(1 - $y, $pt($z['h'] ?? null)))];
        }
        return json_encode($out);
    }

    public static function save(array $in, ?int $id = null): int
    {
        $cur = $id ? (DB::one('SELECT * FROM vision_cameras WHERE id = ?', [$id]) ?? throw new BusinessException('Caméra introuvable.')) : null;
        $d = self::validate($in, $cur);
        if ($id) {
            DB::update('vision_cameras', $d, 'id = ?', [$id]);
        } else {
            $d['geometry'] = json_encode(['line' => ['x1' => 0.5, 'y1' => 0.05, 'x2' => 0.5, 'y2' => 0.95], 'invert' => false]);
            $id = DB::insert('vision_cameras', $d);
        }
        Audit::log($cur ? 'vision.camera_update' : 'vision.camera_create', null, $id, ['name' => $d['name'], 'kind' => $d['kind']]);
        return (int) $id;
    }

    /** Pour le navigateur : jamais l'adresse ni les identifiants. */
    public static function publicRow(array $c): array
    {
        return ['id' => (int) $c['id'], 'name' => $c['name'], 'kind' => $c['kind'], 'device_label' => $c['device_label'], 'fps' => (int) $c['fps'],
            'use_caisse' => (bool) $c['use_caisse'], 'use_surveillance' => (bool) $c['use_surveillance'], 'mode' => $c['mode'],
            'sensitivity' => (int) $c['sensitivity'], 'geometry' => json_decode((string) $c['geometry'], true) ?: new \stdClass(), 'active' => (bool) $c['active'],
            'role' => $c['role'] ?? 'passage', 'place' => $c['place'] ?? null,
            'slots' => ($c['role'] ?? '') === 'etagere' ? self::slots((int) $c['id']) : []];
    }

    /** Emplacements d'une caméra d'étagère (avec le nom de l'article attendu). */
    public static function slots(int $cameraId): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'label' => $r['label'], 'product_id' => $r['product_id'] ? (int) $r['product_id'] : null, 'product' => $r['product'],
            'x' => (float) $r['x'], 'y' => (float) $r['y'], 'w' => (float) $r['w'], 'h' => (float) $r['h']],
            DB::all('SELECT s.*, p.name AS product FROM vision_slots s LEFT JOIN products p ON p.id = s.product_id WHERE s.camera_id = ? AND s.active = 1 ORDER BY s.id', [$cameraId]));
    }

    /**
     * Enregistre les emplacements tracés sur l'image d'une caméra d'étagère (liste complète : ceux qui manquent sont retirés).
     * @param array<int, array{id?: int, label?: string, product_id?: int, x: float, y: float, w: float, h: float}> $list
     */
    public static function saveSlots(int $cameraId, array $list): array
    {
        $cam = DB::one('SELECT * FROM vision_cameras WHERE id = ?', [$cameraId]) ?? throw new BusinessException('Caméra introuvable.');
        if (($cam['role'] ?? '') !== 'etagere') {
            throw new BusinessException('Les emplacements se tracent sur une caméra d\'étagère (rôle « Étagère »).');
        }
        if (count($list) > 40) {
            throw new BusinessException('40 emplacements au plus par caméra.');
        }
        $num = function ($v): float {
            if (!is_numeric($v)) {
                throw new BusinessException('Coordonnées invalides.');
            }
            return round(max(0, min(1, (float) $v)), 4);
        };
        $keep = [];
        DB::transaction(function () use ($list, $cameraId, $num, &$keep) {
            foreach (array_values($list) as $i => $s) {
                $s = (array) $s;
                $x = $num($s['x'] ?? null); $y = $num($s['y'] ?? null);
                $w = min(1 - $x, $num($s['w'] ?? null)); $h = min(1 - $y, $num($s['h'] ?? null));
                if ($w < 0.03 || $h < 0.03) {
                    throw new BusinessException('Emplacement ' . ($i + 1) . ' trop petit.');
                }
                $pid = (int) ($s['product_id'] ?? 0) ?: null;
                if ($pid && !DB::value("SELECT 1 FROM products WHERE id = ? AND status = 'active'", [$pid])) {
                    throw new BusinessException('Article introuvable pour l\'emplacement ' . ($i + 1) . '.');
                }
                $d = ['label' => trim(mb_substr((string) ($s['label'] ?? ''), 0, 80)) ?: 'Emplacement ' . ($i + 1), 'product_id' => $pid, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'active' => 1];
                $id = (int) ($s['id'] ?? 0);
                if ($id && DB::value('SELECT 1 FROM vision_slots WHERE id = ? AND camera_id = ?', [$id, $cameraId])) {
                    DB::update('vision_slots', $d, 'id = ?', [$id]);
                } else {
                    $id = (int) DB::insert('vision_slots', $d + ['camera_id' => $cameraId]);
                }
                $keep[] = $id;
            }
            // Retirés : désactivés (les suivis passés gardent leur emplacement)
            DB::run('UPDATE vision_slots SET active = 0 WHERE camera_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', $keep) . ')' : ''), [$cameraId]);
        });
        Audit::log('vision.slots_save', null, $cameraId, ['count' => count($keep)]);
        return self::slots($cameraId);
    }

    // ------------------------------------------------------------------ image d'une caméra IP
    /**
     * Lit une image de la caméra : réponse JPEG/PNG (snapshot) ou première image d'un flux MJPEG.
     * @return array{0:string,1:string} [octets, type MIME]
     */
    public static function fetchFrame(array $cam): array
    {
        if ($cam['kind'] !== 'ip' || !$cam['url']) {
            throw new BusinessException('Cette caméra n\'est pas une caméra IP : elle est lue directement par le navigateur du poste.');
        }
        $ips = self::checkUrl($cam['url']);
        $u = parse_url($cam['url']);
        $port = (int) ($u['port'] ?? (strtolower($u['scheme']) === 'https' ? 443 : 80));
        $buf = '';
        $mjpeg = $cam['url_kind'] === 'mjpeg';
        $ch = curl_init($cam['url']);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, // certificats autosignés des caméras
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg, image/png, multipart/x-mixed-replace, */*'],
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buf, $mjpeg): int {
                $buf .= $chunk;
                if (strlen($buf) > self::MAX_BYTES) {
                    return 0; // trop gros : on coupe
                }
                if ($mjpeg && ($s = strpos($buf, "\xFF\xD8")) !== false && strpos($buf, "\xFF\xD9", $s + 2) !== false) {
                    return 0; // une image complète du flux suffit
                }
                return strlen($chunk);
            },
        ]);
        if (!filter_var(trim($u['host'], '[]'), FILTER_VALIDATE_IP)) {
            curl_setopt($ch, CURLOPT_RESOLVE, [$u['host'] . ':' . $port . ':' . $ips[0]]); // adresse vérifiée ci-dessus, pas de seconde résolution
        }
        if ($cam['username']) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($ch, CURLOPT_USERPWD, $cam['username'] . ':' . self::decrypt($cam['secret']));
        }
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($code === 401 || $code === 403) {
            throw new BusinessException('La caméra refuse l\'accès (' . $code . ') : vérifiez l\'identifiant et le mot de passe.');
        }
        if ($code >= 300 && $code < 400) {
            throw new BusinessException('La caméra redirige vers une autre adresse : indiquez directement l\'adresse finale de l\'image.');
        }
        if (($s = strpos($buf, "\xFF\xD8")) !== false && ($e = strpos($buf, "\xFF\xD9", $s + 2)) !== false) {
            return [substr($buf, $s, $e + 2 - $s), 'image/jpeg'];
        }
        if (str_starts_with($buf, "\x89PNG")) {
            return [$buf, 'image/png'];
        }
        if ($err && $buf === '') {
            throw new BusinessException('Caméra injoignable (délai dépassé ou adresse incorrecte).');
        }
        throw new BusinessException('La caméra n\'a pas renvoyé d\'image JPEG (code ' . $code . '). Vérifiez l\'adresse de l\'image fixe ou du flux MJPEG.');
    }
}
