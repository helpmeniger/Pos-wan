<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Upload;

/**
 * Écran client et afficheur client.
 *
 * - Écran client : page /afficheur ouverte sur le second écran du poste de caisse (POS double
 *   écran, moniteur tourné vers le client). Elle montre le panier, le total et la monnaie ; au
 *   repos, elle diffuse les images et vidéos choisies dans les paramètres. La caisse lui parle
 *   directement dans le navigateur (aucun aller-retour par le serveur).
 * - Afficheur client (« pole display », 2 lignes de 20 caractères) : branché sur le poste de
 *   caisse (USB / série, piloté par le navigateur) ou joint par le serveur (port COM du PC
 *   serveur, ou adaptateur réseau).
 */
final class CustomerDisplay
{
    /** Effets de passage d'une image à l'autre. */
    public const TRANSITIONS = [
        'kenburns' => 'Ken Burns : fondu et zoom lent (recommandé)',
        'fade' => 'Fondu enchaîné',
        'slide' => 'Glissement',
        'zoom' => 'Zoom avant',
        'blur' => 'Fondu avec flou',
        'wipe' => 'Volet',
        'random' => 'Aléatoire (un effet différent à chaque image)',
    ];
    /** Remplissage autour d'une image qui n'a pas les proportions de l'écran. */
    public const FILLS = [
        'blur' => 'Fond flouté de l\'image (aucune bande noire)',
        'cover' => 'Image recadrée plein écran',
        'theme' => 'Couleur du thème',
    ];

    public const DEFAULTS = [
        'display_enabled' => '0',
        'display_welcome' => 'Bienvenue',
        'display_thanks' => 'Merci de votre visite !',
        'display_thanks_seconds' => '6',
        'display_image_seconds' => '8',
        'display_theme' => 'dark',
        'display_show_lines' => '1',
        'display_video_sound' => '0',
        'display_transition' => 'kenburns',
        'display_fill' => 'blur',
        'display_captions' => '1',
        'display_progress' => '1',
        'vfd_enabled' => '0',
        'vfd_mode' => 'browser',
        'vfd_protocol' => 'escpos',
        'vfd_cols' => '20',
        'vfd_baud' => '9600',
        'vfd_address' => '',
        'vfd_line1' => 'BIENVENUE',
        'vfd_line2' => '',
    ];

    /** Jeux de commandes des afficheurs 2 lignes. */
    public const PROTOCOLS = [
        'escpos' => 'ESC/POS (Epson DM-D, la plupart des afficheurs USB / série)',
        'cd5220' => 'CD5220 (afficheurs génériques « ESC Q A / ESC Q B »)',
        'text' => 'Texte simple (retour à la ligne)',
    ];
    public const MODES = [
        'browser' => 'Branché sur ce poste de caisse (USB / série, via le navigateur)',
        'server' => 'Relié au serveur (port COM du PC serveur ou adaptateur réseau)',
    ];
    public const BAUDS = [2400, 4800, 9600, 19200, 38400, 57600, 115200];

    public static function settings(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $v) {
            $out[$k] = (string) setting($k, $v);
        }
        return $out;
    }

    public static function saveSettings(array $d): void
    {
        $int = function (string $k, int $min, int $max, string $label) use ($d): string {
            $v = trim((string) ($d[$k] ?? ''));
            if (!preg_match('/^\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
                throw new BusinessException($label . ' : nombre entre ' . $min . ' et ' . $max . '.');
            }
            return (string) (int) $v;
        };
        $text = fn(string $k, int $max) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $max);
        $mode = (string) ($d['vfd_mode'] ?? 'browser');
        $protocol = (string) ($d['vfd_protocol'] ?? 'escpos');
        if (!isset(self::MODES[$mode]) || !isset(self::PROTOCOLS[$protocol])) {
            throw new BusinessException('Type d\'afficheur invalide.');
        }
        $baud = (int) ($d['vfd_baud'] ?? 9600);
        if (!in_array($baud, self::BAUDS, true)) {
            throw new BusinessException('Vitesse de l\'afficheur invalide.');
        }
        $vfdOn = !empty($d['vfd_enabled']);
        $address = trim((string) ($d['vfd_address'] ?? ''));
        if ($vfdOn && $mode === 'server') {
            $address = self::validateAddress($address);
        }
        $values = [
            'display_enabled' => !empty($d['display_enabled']) ? '1' : '0',
            'display_welcome' => $text('display_welcome', 120),
            'display_thanks' => $text('display_thanks', 120),
            'display_thanks_seconds' => $int('display_thanks_seconds', 2, 60, 'Durée du message de remerciement'),
            'display_image_seconds' => $int('display_image_seconds', 3, 300, 'Durée d\'affichage d\'une image'),
            'display_theme' => ($d['display_theme'] ?? '') === 'light' ? 'light' : 'dark',
            'display_show_lines' => !empty($d['display_show_lines']) ? '1' : '0',
            'display_video_sound' => !empty($d['display_video_sound']) ? '1' : '0',
            'display_transition' => isset(self::TRANSITIONS[$d['display_transition'] ?? '']) ? (string) $d['display_transition'] : 'kenburns',
            'display_fill' => isset(self::FILLS[$d['display_fill'] ?? '']) ? (string) $d['display_fill'] : 'blur',
            'display_captions' => !empty($d['display_captions']) ? '1' : '0',
            'display_progress' => !empty($d['display_progress']) ? '1' : '0',
            'vfd_enabled' => $vfdOn ? '1' : '0',
            'vfd_mode' => $mode,
            'vfd_protocol' => $protocol,
            'vfd_cols' => $int('vfd_cols', 16, 40, 'Caractères par ligne'),
            'vfd_baud' => (string) $baud,
            'vfd_address' => $address,
            'vfd_line1' => $text('vfd_line1', 40),
            'vfd_line2' => $text('vfd_line2', 40),
        ];
        foreach ($values as $k => $v) {
            setting_set($k, $v);
        }
        Audit::log('settings.customer_display', null, null, $values);
    }

    /**
     * Port de l'afficheur relié au serveur : COM1…COM99 (Windows), /dev/ttyUSB0, /dev/ttyACM0,
     * /dev/ttyS0 (Linux), ou adresse réseau locale « 192.168.1.60:9100 ». Rien d'autre : le
     * serveur n'écrit jamais ailleurs.
     */
    public static function validateAddress(string $address): string
    {
        $a = trim($address);
        if (preg_match('/^COM([1-9]\d?)$/i', $a, $m)) {
            return 'COM' . $m[1];
        }
        if (preg_match('#^/dev/tty(USB|ACM|S)\d{1,2}$#', $a)) {
            return $a;
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::(\d{1,5}))?$/', $a, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $priv = !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) || str_starts_with($m[1], '127.');
            $port = (int) ($m[2] ?? 9100);
            if (!$priv || $port < 1 || $port > 65535) {
                throw new BusinessException('L\'afficheur réseau doit être sur le réseau local (192.168.x.x, 10.x.x.x…).');
            }
            return $m[1] . ':' . $port;
        }
        throw new BusinessException('Port de l\'afficheur invalide : COM3, /dev/ttyUSB0 ou 192.168.1.60:9100.');
    }

    /** Texte affichable sur un afficheur (ASCII : les afficheurs n'ont pas tous les accents). */
    public static function ascii(string $s): string
    {
        $t = strtr($s, ['œ' => 'oe', 'Œ' => 'OE', 'æ' => 'ae', 'Æ' => 'AE', '€' => 'EUR', '—' => '-', '–' => '-', '’' => "'", '«' => '"', '»' => '"', "\u{202f}" => ' ', "\u{a0}" => ' ']);
        $t = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($t, \Normalizer::FORM_D) : $t;
        $t = (string) preg_replace('/\p{Mn}+/u', '', $t);
        return (string) preg_replace('/[^\x20-\x7E]/u', '?', $t); // un caractère inconnu = un seul « ? »
    }

    /** Octets à envoyer pour afficher deux lignes (mêmes règles que le pilote du navigateur). */
    public static function vfdBytes(string $line1, string $line2, string $protocol, int $cols): string
    {
        $fit = fn(string $s) => str_pad(substr(self::ascii($s), 0, $cols), $cols);
        return match ($protocol) {
            'cd5220' => "\x1B\x40\x0C" . "\x1BQA" . $fit($line1) . "\r" . "\x1BQB" . $fit($line2) . "\r",
            'text' => "\r\n" . $fit($line1) . "\r\n" . $fit($line2),
            default => "\x1B\x40\x0C\x0B" . $fit($line1) . "\x1F\x24\x01\x02" . $fit($line2),
        };
    }

    /** Afficheur relié au serveur : écrit les deux lignes. */
    public static function vfdSend(string $line1, string $line2): void
    {
        $s = self::settings();
        if ($s['vfd_enabled'] !== '1' || $s['vfd_mode'] !== 'server') {
            throw new BusinessException('Aucun afficheur relié au serveur n\'est configuré.');
        }
        $address = self::validateAddress($s['vfd_address']);
        $bytes = self::vfdBytes($line1, $line2, $s['vfd_protocol'], (int) $s['vfd_cols']);
        if (preg_match('/^\d/', $address)) {
            [$host, $port] = explode(':', $address);
            $fp = @fsockopen($host, (int) $port, $errno, $errstr, 2);
            if (!$fp) {
                throw new BusinessException("Afficheur injoignable ($address) : $errstr");
            }
            stream_set_timeout($fp, 2);
            fwrite($fp, $bytes);
            fclose($fp);
            return;
        }
        // Port série : la vitesse se règle avec la commande du système (Windows : mode, Linux : stty)
        $fp = @fopen(PHP_OS_FAMILY === 'Windows' ? '\\\\.\\' . $address : $address, 'wb');
        if (!$fp) {
            throw new BusinessException("Port $address inaccessible : afficheur débranché, port occupé ou droits insuffisants.");
        }
        fwrite($fp, $bytes);
        fclose($fp);
    }

    // ------------------------------------------------------------------ images et vidéos
    public static function media(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM display_media' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, id');
    }

    /** Liste publiée sur l'écran client (adresses des fichiers, durées). */
    /** Fichier encore présent sur le disque (absent après une réinstallation sans le dossier uploads). */
    public static function fileExists(array $m): bool
    {
        return !str_contains((string) $m['path'], '..') && is_file(UPLOADS_PATH . '/' . $m['path']);
    }

    /**
     * Liste diffusée : seulement les fichiers actifs ET présents (un fichier manquant donnait un
     * écran noir). L'adresse porte la date du fichier : un fichier remplacé n'est pas pris en cache.
     */
    public static function playlist(): array
    {
        $default = (int) setting('display_image_seconds', '8');
        $out = [];
        foreach (self::media(true) as $m) {
            if (!self::fileExists($m)) {
                continue;
            }
            $out[] = ['id' => (int) $m['id'], 'kind' => $m['kind'], 'url' => upload_url($m['path']) . '?v=' . (int) @filemtime(UPLOADS_PATH . '/' . $m['path']),
                'seconds' => (int) $m['duration_s'] ?: $default, 'title' => (string) $m['title']];
        }
        return $out;
    }

    public static function addMedia(array $file, string $title = ''): int
    {
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $video = in_array($ext, ['mp4', 'webm'], true);
        if (DB::value('SELECT COUNT(*) FROM display_media') >= 50) {
            throw new BusinessException('50 images ou vidéos au plus : supprimez-en avant d\'en ajouter.');
        }
        $path = $video
            ? Upload::video($file, 'display', (int) config('app.uploads.max_display_video_bytes'))
            : Upload::image($file, 'display', 1920, (int) config('app.uploads.max_display_image_bytes'));
        $id = DB::insert('display_media', [
            'kind' => $video ? 'video' : 'image', 'path' => $path, 'title' => mb_substr(trim($title), 0, 150) ?: null,
            'duration_s' => (int) setting('display_image_seconds', '8'), 'size_bytes' => (int) @filesize(UPLOADS_PATH . '/' . $path),
            'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM display_media'), 'created_by' => Auth::id(),
        ]);
        Audit::log('display.media_add', 'display_media', $id, ['kind' => $video ? 'video' : 'image']);
        return $id;
    }

    public static function updateMedia(int $id, array $d): void
    {
        if (!DB::value('SELECT 1 FROM display_media WHERE id = ?', [$id])) {
            throw new BusinessException('Fichier introuvable.');
        }
        $dur = (int) ($d['duration_s'] ?? 8);
        if ($dur < 3 || $dur > 300) {
            throw new BusinessException('Durée d\'affichage : 3 à 300 secondes.');
        }
        DB::update('display_media', ['title' => mb_substr(trim((string) ($d['title'] ?? '')), 0, 150) ?: null, 'duration_s' => $dur, 'active' => !empty($d['active']) ? 1 : 0], 'id = ?', [$id]);
    }

    /** Monte ou descend un fichier dans l'ordre de diffusion. */
    public static function move(int $id, int $dir): void
    {
        $list = array_map('intval', array_column(self::media(), 'id'));
        $i = array_search($id, $list, true);
        $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($list)) {
            return;
        }
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        foreach ($list as $n => $mid) {
            DB::update('display_media', ['sort_order' => $n + 1], 'id = ?', [$mid]);
        }
    }

    /** Supprime un fichier de la diffusion (demande de confirmation dans la page). */
    public static function deleteMedia(int $id): void
    {
        $m = DB::one('SELECT * FROM display_media WHERE id = ?', [$id]);
        if (!$m) {
            throw new BusinessException('Fichier introuvable.');
        }
        DB::run('DELETE FROM display_media WHERE id = ?', [$id]);
        Upload::delete($m['path']);
        Audit::log('display.media_delete', 'display_media', $id, ['kind' => $m['kind'], 'title' => $m['title']]);
    }

    /** Réglages transmis à la caisse et à l'écran client (aucune donnée sensible). */
    public static function clientConfig(): array
    {
        $s = self::settings();
        return [
            'enabled' => $s['display_enabled'] === '1',
            'welcome' => $s['display_welcome'], 'thanks' => $s['display_thanks'],
            'thanksSeconds' => (int) $s['display_thanks_seconds'], 'theme' => $s['display_theme'],
            'showLines' => $s['display_show_lines'] === '1', 'sound' => $s['display_video_sound'] === '1',
            'transition' => $s['display_transition'], 'fill' => $s['display_fill'],
            'captions' => $s['display_captions'] === '1', 'progress' => $s['display_progress'] === '1',
            'vfd' => ['enabled' => $s['vfd_enabled'] === '1', 'mode' => $s['vfd_mode'], 'protocol' => $s['vfd_protocol'], 'cols' => (int) $s['vfd_cols'],
                'baud' => (int) $s['vfd_baud'], 'line1' => $s['vfd_line1'], 'line2' => $s['vfd_line2'] !== '' ? $s['vfd_line2'] : (string) setting('company_name', '')],
        ];
    }
}
