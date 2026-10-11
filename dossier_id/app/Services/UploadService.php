<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Validation stricte des fichiers importés.
 *  - Photographies : JPEG/PNG uniquement, type réel vérifié (finfo + getimagesize),
 *    dimensions contrôlées, puis RÉ-ENCODAGE complet via GD : les métadonnées EXIF
 *    (géolocalisation, appareil…) et tout contenu caché sont supprimés.
 *  - Documents : PDF, JPEG, PNG. Les images sont ré-encodées, les PDF vérifiés
 *    par signature et rejetés s'ils contiennent du JavaScript ou des actions automatiques.
 */
final class UploadService
{
    public const PHOTO_MIMES = ['image/jpeg', 'image/png'];
    public const DOC_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** Lit un fichier issu de $_FILES et vérifie l'origine HTTP. */
    public static function fromUpload(?array $file): array
    {
        if (!$file || !isset($file['error']) || is_array($file['error'])) {
            throw new ValidationException('Aucun fichier reçu.');
        }
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'Le fichier dépasse la taille autorisée par le serveur.',
            UPLOAD_ERR_FORM_SIZE  => 'Le fichier dépasse la taille autorisée.',
            UPLOAD_ERR_PARTIAL    => 'Le fichier n\'a été que partiellement reçu.',
            UPLOAD_ERR_NO_FILE    => 'Aucun fichier sélectionné.',
            UPLOAD_ERR_NO_TMP_DIR => 'Répertoire temporaire manquant sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Écriture impossible sur le serveur.',
        ];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException($messages[$file['error']] ?? 'Erreur lors de l\'envoi du fichier.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ValidationException('Fichier non valide.');
        }
        return ['path' => $file['tmp_name'], 'name' => (string) ($file['name'] ?? 'fichier')];
    }

    /**
     * @return array{data: string, mime: string, width: int, height: int, sha256: string}
     */
    public static function processPhoto(string $path): array
    {
        $max = (int) Config::get('uploads.photo_max_bytes', 5 * 1024 * 1024);
        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ValidationException('Le fichier est vide.');
        }
        if ($size > $max) {
            throw new ValidationException(sprintf('La photographie dépasse la taille maximale (%d Mo).', intdiv($max, 1048576)));
        }
        $mime = self::detectMime($path);
        if (!in_array($mime, self::PHOTO_MIMES, true)) {
            throw new ValidationException('Format refusé : seules les photographies JPEG et PNG sont acceptées.');
        }
        return self::reencodeImage($path, $mime);
    }

    /**
     * @return array{data: string, mime: string, size: int, sha256: string}
     */
    public static function processDocument(string $path): array
    {
        $max = (int) Config::get('uploads.document_max_bytes', 10 * 1024 * 1024);
        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ValidationException('Le fichier est vide.');
        }
        if ($size > $max) {
            throw new ValidationException(sprintf('Le document dépasse la taille maximale (%d Mo).', intdiv($max, 1048576)));
        }
        $mime = self::detectMime($path);
        if (!in_array($mime, self::DOC_MIMES, true)) {
            throw new ValidationException('Format refusé : seuls les fichiers PDF, JPEG et PNG sont acceptés.');
        }
        if ($mime === 'application/pdf') {
            $data = (string) file_get_contents($path);
            if (!str_starts_with($data, '%PDF-')) {
                throw new ValidationException('Le fichier PDF est invalide.');
            }
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b#', $data)) {
                throw new ValidationException('Ce PDF contient du contenu actif (scripts, actions automatiques ou fichiers incorporés) et a été refusé.');
            }
            return ['data' => $data, 'mime' => $mime, 'size' => strlen($data), 'sha256' => hash('sha256', $data)];
        }
        $img = self::reencodeImage($path, $mime, 2400, 50);
        return ['data' => $img['data'], 'mime' => $img['mime'], 'size' => strlen($img['data']), 'sha256' => $img['sha256']];
    }

    public static function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return (string) $finfo->file($path);
    }

    private static function reencodeImage(string $path, string $mime, ?int $maxSide = null, ?int $minSide = null): array
    {
        $info = @getimagesize($path);
        $expected = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if ($info === false || $info[2] !== $expected) {
            throw new ValidationException('Le fichier image est invalide ou corrompu.');
        }
        [$w, $h] = $info;
        $minSide ??= (int) Config::get('uploads.photo_min_side', 200);
        $maxSide ??= (int) Config::get('uploads.photo_max_side', 1600);
        if ($w < $minSide || $h < $minSide) {
            throw new ValidationException("Qualité insuffisante : l'image mesure {$w}×{$h} px, minimum requis {$minSide}×{$minSide} px.");
        }
        if ($w * $h > 40_000_000 || $w > 10000 || $h > 10000) {
            throw new ValidationException("L'image est trop grande ({$w}×{$h} px).");
        }
        $raw = (string) file_get_contents($path);
        $sha = hash('sha256', $raw);
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw new ValidationException("Impossible de lire l'image.");
        }
        // Orientation EXIF (JPEG) appliquée avant suppression des métadonnées.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $angle = match ((int) ($exif['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($angle !== 0) {
                $rot = imagerotate($src, $angle, 0);
                if ($rot !== false) {
                    imagedestroy($src);
                    $src = $rot;
                    [$w, $h] = [imagesx($src), imagesy($src)];
                }
            }
        }
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, 90);
        $data = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return ['data' => $data, 'mime' => 'image/jpeg', 'width' => $nw, 'height' => $nh, 'sha256' => $sha];
    }

    /** Nom d'origine nettoyé, utilisé uniquement pour l'affichage et le téléchargement. */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N} ._-]/u', '_', $name) ?? 'fichier';
        return mb_substr(trim($name, '. ') ?: 'fichier', 0, 150);
    }
}
