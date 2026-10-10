<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Téléversement sécurisé : contrôle de taille, d'extension ET du type réel
 * (finfo + getimagesize), ré-encodage des images (supprime tout contenu caché),
 * nom aléatoire, stockage dans un dossier où PHP n'est pas exécutable.
 */
final class Upload
{
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public static function image(array $file, string $subdir): string
    {
        self::checkError($file);
        $max = (int) config('app.uploads.max_image_bytes');
        if ($file['size'] > $max) {
            throw new BusinessException('Image trop volumineuse (max ' . round($max / 1048576, 1) . ' Mo).');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            throw new BusinessException('Format d\'image non autorisé (JPG, PNG, WEBP, GIF).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!isset(self::IMAGE_TYPES[$mime]) || @getimagesize($file['tmp_name']) === false) {
            throw new BusinessException('Le fichier n\'est pas une image valide.');
        }
        $dir = PUBLIC_PATH . '/uploads/' . trim($subdir, '/');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Dossier de téléversement inaccessible.');
        }
        $name = bin2hex(random_bytes(16));
        // Ré-encodage avec GD lorsqu'il est disponible (neutralise les fichiers polyglottes)
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
            if ($img === false) {
                throw new BusinessException('Image illisible.');
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $maxDim = 800;
            if ($w > $maxDim || $h > $maxDim) {
                $ratio = min($maxDim / $w, $maxDim / $h);
                $nw = (int) round($w * $ratio);
                $nh = (int) round($h * $ratio);
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
            }
            imagesavealpha($img, true);
            $target = $dir . '/' . $name . '.png';
            imagepng($img, $target, 7);
            imagedestroy($img);
            return trim($subdir, '/') . '/' . $name . '.png';
        }
        $target = $dir . '/' . $name . '.' . self::IMAGE_TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('Échec de l\'enregistrement du fichier.');
        }
        return trim($subdir, '/') . '/' . $name . '.' . self::IMAGE_TYPES[$mime];
    }

    /** Fichier d'import CSV : renvoie le chemin temporaire validé. */
    public static function csv(array $file): string
    {
        self::checkError($file);
        if ($file['size'] > (int) config('app.uploads.max_import_bytes')) {
            throw new BusinessException('Fichier trop volumineux (max 5 Mo).');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            throw new BusinessException('Seuls les fichiers CSV sont acceptés (Excel : Fichier > Enregistrer sous > CSV).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'application/octet-stream'], true)) {
            throw new BusinessException('Le contenu du fichier n\'est pas un CSV valide.');
        }
        return $file['tmp_name'];
    }

    private static function checkError(array $file): void
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new BusinessException('Fichier invalide.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new BusinessException($file['error'] === UPLOAD_ERR_NO_FILE ? 'Aucun fichier envoyé.' : 'Erreur lors de l\'envoi du fichier.');
        }
        if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new BusinessException('Fichier invalide.');
        }
    }

    public static function delete(?string $relative): void
    {
        if (!$relative || str_contains($relative, '..')) {
            return;
        }
        $path = PUBLIC_PATH . '/uploads/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
