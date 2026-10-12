<?php
declare(strict_types=1);

namespace Verifia\Analysis;

/**
 * Empreintes d'image :
 * - SHA-256 : identité exacte du fichier ;
 * - dHash / aHash 64 bits : empreintes perceptuelles robustes au redimensionnement
 *   et à la recompression (GD, aucune dépendance externe).
 */
final class ImageHasher
{
    public static function fromFile(string $path): array
    {
        $bin = file_get_contents($path);
        if ($bin === false) {
            throw new \RuntimeException("Image illisible : $path");
        }
        return self::fromString($bin);
    }

    public static function fromString(string $bin): array
    {
        $img = @imagecreatefromstring($bin);
        if ($img === false) {
            throw new \RuntimeException('Format d\'image non pris en charge par GD');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $result = [
            'sha256' => hash('sha256', $bin),
            'dhash'  => self::dhash($img),
            'ahash'  => self::ahash($img),
            'width'  => $w,
            'height' => $h,
        ];
        imagedestroy($img);
        return $result;
    }

    private static function gray(\GdImage $src, int $w, int $h): array
    {
        $small = imagecreatetruecolor($w, $h);
        imagecopyresampled($small, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        $px = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($small, $x, $y);
                $px[$y][$x] = 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
            }
        }
        imagedestroy($small);
        return $px;
    }

    public static function dhash(\GdImage $img): int
    {
        $px = self::gray($img, 9, 8);
        $hash = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $hash = ($hash << 1) | ($px[$y][$x] > $px[$y][$x + 1] ? 1 : 0);
            }
        }
        return $hash;
    }

    public static function ahash(\GdImage $img): int
    {
        $px = self::gray($img, 8, 8);
        $flat = array_merge(...$px);
        $avg = array_sum($flat) / 64;
        $hash = 0;
        foreach ($flat as $v) {
            $hash = ($hash << 1) | ($v >= $avg ? 1 : 0);
        }
        return $hash;
    }

    /** Métadonnées EXIF utiles (JPEG uniquement ; les captures d'écran n'en contiennent pas). */
    public static function exif(string $path): ?array
    {
        if (!function_exists('exif_read_data') || !preg_match('/\.jpe?g$/i', $path)) {
            return null;
        }
        $data = @exif_read_data($path, null, true);
        if (!$data) {
            return null;
        }
        $keep = [];
        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime', 'Make', 'Model', 'Software', 'Artist', 'Copyright', 'ImageDescription'] as $k) {
            foreach (['EXIF', 'IFD0'] as $sec) {
                if (isset($data[$sec][$k]) && is_string($data[$sec][$k])) {
                    $keep[$k] = mb_substr($data[$sec][$k], 0, 200);
                }
            }
        }
        if (isset($data['GPS'])) {
            $keep['GPS'] = 'présent (coordonnées non affichées)';
        }
        return $keep ?: null;
    }
}
