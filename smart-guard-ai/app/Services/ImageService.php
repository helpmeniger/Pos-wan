<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;

/**
 * Téléversement sécurisé des photos : contrôle d'erreur, taille, type réel (finfo +
 * getimagesize), dimensions, puis RÉ-ENCODAGE JPEG par GD (supprime métadonnées EXIF/GPS et
 * tout contenu caché). Les fichiers sont stockés hors du dossier public, avec un nom aléatoire.
 */
final class ImageService
{
    private const MIMES = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** @return array{path:string, sha256:string, width:int, height:int, quality:array} */
    public static function storeUpload(array $file, string $subdir): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'Échec du téléversement (code ' . ($file['error'] ?? '?') . ').');
        }
        $max = Env::int('UPLOAD_MAX_IMAGE_MB', 8) * 1024 * 1024;
        if ($file['size'] <= 0 || $file['size'] > $max) {
            throw new HttpException(413, 'Image trop volumineuse (maximum ' . Env::int('UPLOAD_MAX_IMAGE_MB', 8) . ' Mo).');
        }
        if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new HttpException(422, 'Fichier invalide.');
        }
        return self::storeFile($file['tmp_name'], $subdir);
    }

    public static function storeBinary(string $data, string $subdir): array
    {
        $max = Env::int('UPLOAD_MAX_IMAGE_MB', 8) * 1024 * 1024;
        if (strlen($data) > $max) {
            throw new HttpException(413, 'Image trop volumineuse.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sgi');
        file_put_contents($tmp, $data);
        try {
            return self::storeFile($tmp, $subdir);
        } finally {
            @unlink($tmp);
        }
    }

    private static function storeFile(string $tmp, string $subdir): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::MIMES[$mime])) {
            throw new HttpException(422, 'Format non accepté (JPEG, PNG ou WebP uniquement).');
        }
        $info = @getimagesize($tmp);
        if (!$info || $info[0] < 64 || $info[1] < 64 || $info[0] > 8000 || $info[1] > 8000) {
            throw new HttpException(422, 'Dimensions d\'image invalides (64 à 8000 pixels).');
        }
        $src = match (self::MIMES[$mime]) {
            'jpeg' => @imagecreatefromjpeg($tmp),
            'png' => @imagecreatefrompng($tmp),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        };
        if (!$src) {
            throw new HttpException(422, 'Image illisible ou corrompue.');
        }
        if (self::MIMES[$mime] === 'jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $rot = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($rot) {
                $src = imagerotate($src, $rot, 0);
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, 1600 / max($w, $h));
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $subdir = trim(preg_replace('#[^a-z0-9_/\-]#i', '', $subdir), '/');
        $dir = STORAGE_PATH . '/product_images/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier de stockage des photos.');
        }
        $name = bin2hex(random_bytes(12)) . '.jpg';
        imagejpeg($dst, $dir . '/' . $name, 90);
        $quality = self::quality($dst);
        imagedestroy($dst);
        $path = $subdir . '/' . $name;
        return ['path' => $path, 'sha256' => hash_file('sha256', $dir . '/' . $name), 'width' => $nw, 'height' => $nh, 'quality' => $quality];
    }

    /** Indicateurs simples calculés côté PHP (le service IA refait une analyse complète). */
    public static function quality(\GdImage $img): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sw = 128;
        $sh = max(16, (int) round($h * $sw / $w));
        $s = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($s, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
        $g = [];
        $sum = 0;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $c = imagecolorat($s, $x, $y);
                $l = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
                $g[$y][$x] = $l;
                $sum += $l;
            }
        }
        imagedestroy($s);
        $mean = $sum / ($sw * $sh);
        $lap = [];
        for ($y = 1; $y < $sh - 1; $y++) {
            for ($x = 1; $x < $sw - 1; $x++) {
                $lap[] = $g[$y - 1][$x] + $g[$y + 1][$x] + $g[$y][$x - 1] + $g[$y][$x + 1] - 4 * $g[$y][$x];
            }
        }
        $lm = array_sum($lap) / max(1, count($lap));
        $var = array_sum(array_map(static fn ($v) => ($v - $lm) ** 2, $lap)) / max(1, count($lap));
        $issues = [];
        if (min($w, $h) < 200) {
            $issues[] = 'résolution faible';
        }
        if ($var < 40) {
            $issues[] = 'image floue';
        }
        if ($mean < 45) {
            $issues[] = 'trop sombre';
        } elseif ($mean > 220) {
            $issues[] = 'surexposée';
        }
        $score = min(1, $var / 400) * 0.6 + (1 - abs($mean - 128) / 128) * 0.4;
        return ['score' => round(max(0, $score), 3), 'brightness' => round($mean, 1), 'sharpness' => round($var, 1), 'issues' => $issues];
    }
}
