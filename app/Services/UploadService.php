<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Str;

/**
 * Téléversements sécurisés : taille limitée, type MIME vérifié par contenu (finfo),
 * liste blanche, nom aléatoire, stockage hors du dossier public, ré-encodage des images
 * (supprime les métadonnées EXIF dont la géolocalisation).
 */
final class UploadService
{
    public const PURPOSES = ['avatar', 'document', 'vehicle', 'logo', 'product', 'delivery_proof', 'payout_proof', 'receipt'];

    public static function store(?array $file, int $ownerId, string $purpose, bool $imagesOnly = false): array
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw HttpException::badRequest('Type de fichier non prévu.');
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            throw \App\Core\ValidationException::field('file', $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 'Fichier trop volumineux.' : 'Aucun fichier reçu.');
        }
        $max = (int) Config::get('security.upload_max_bytes', 4194304);
        if ((int) $file['size'] > $max || (int) $file['size'] <= 0) {
            throw \App\Core\ValidationException::field('file', 'Fichier trop volumineux (max. ' . round($max / 1048576, 1) . ' Mo).');
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_file($tmp) || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp))) {
            throw \App\Core\ValidationException::field('file', 'Téléversement invalide.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $allowed = (array) Config::get('security.allowed_upload_mimes');
        if (!isset($allowed[$mime]) || ($imagesOnly && !str_starts_with($mime, 'image/'))) {
            throw \App\Core\ValidationException::field('file', $imagesOnly ? 'Formats acceptés : JPEG, PNG, WebP.' : 'Formats acceptés : JPEG, PNG, WebP, PDF.');
        }
        $dir = MARWA_ROOT . '/storage/uploads/' . date('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier de téléversement.');
        }
        $name = Str::token(18) . '.' . $allowed[$mime];
        $dest = $dir . '/' . $name;
        if (str_starts_with($mime, 'image/')) {
            self::reencode($tmp, $dest, $mime);
        } elseif (!(PHP_SAPI === 'cli' ? copy($tmp, $dest) : move_uploaded_file($tmp, $dest))) {
            throw new \RuntimeException('Échec de l\'enregistrement du fichier.');
        }
        $id = Database::insert('files', [
            'owner_id' => $ownerId,
            'purpose' => $purpose,
            'path' => substr($dest, strlen(MARWA_ROOT . '/storage/uploads/')),
            'mime' => $mime,
            'size_bytes' => (int) filesize($dest),
            'visibility' => in_array($purpose, ['logo', 'product', 'avatar', 'vehicle'], true) ? 'public' : 'private',
        ]);
        return Database::first('SELECT id, purpose, mime, size_bytes, visibility FROM files WHERE id = :id', ['id' => $id]);
    }

    private static function reencode(string $src, string $dest, string $mime): void
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/webp' => @imagecreatefromwebp($src),
            default => false,
        };
        if (!$img) {
            throw \App\Core\ValidationException::field('file', 'Image illisible ou corrompue.');
        }
        $maxPx = (int) Config::get('security.upload_image_max_px', 1600);
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) > $maxPx) {
            $ratio = $maxPx / max($w, $h);
            $resized = imagescale($img, (int) round($w * $ratio), (int) round($h * $ratio));
            imagedestroy($img);
            $img = $resized;
        }
        $ok = match ($mime) {
            'image/png' => imagepng($img, $dest, 6),
            'image/webp' => imagewebp($img, $dest, 80),
            default => imagejpeg($img, $dest, 82),
        };
        imagedestroy($img);
        if (!$ok) {
            throw new \RuntimeException('Échec du ré-encodage de l\'image.');
        }
    }

    /** Un fichier privé n'est servi qu'à son propriétaire, au personnel habilité ou aux parties concernées. */
    public static function canView(array $file, ?array $user): bool
    {
        if ($file['visibility'] === 'public') {
            return true;
        }
        if (!$user) {
            return false;
        }
        if ((int) $file['owner_id'] === (int) $user['id'] || \App\Core\Auth::can('partners.validate') || \App\Core\Auth::can('finance.view')) {
            return true;
        }
        if ($file['purpose'] === 'delivery_proof') {
            return (bool) Database::value('SELECT 1 FROM deliveries d JOIN orders o ON o.id = d.order_id WHERE d.proof_file_id = :f AND o.customer_id = :u', ['f' => $file['id'], 'u' => $user['id']]);
        }
        return false;
    }

    public static function path(array $file): string
    {
        $real = realpath(MARWA_ROOT . '/storage/uploads/' . $file['path']);
        $base = realpath(MARWA_ROOT . '/storage/uploads');
        if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw HttpException::notFound();
        }
        return $real;
    }
}
