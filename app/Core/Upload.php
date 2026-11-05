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

    public static function image(array $file, string $subdir, int $maxDim = 800, ?int $maxBytes = null): string
    {
        self::checkError($file);
        $max = $maxBytes ?? (int) config('app.uploads.max_image_bytes');
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
        $dir = UPLOADS_PATH . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Dossier de téléversement inaccessible.');
        }
        $name = bin2hex(random_bytes(16));
        // Ré-encodage avec GD lorsqu'il est disponible (neutralise les fichiers polyglottes).
        // Format conservé : JPEG → JPEG (photos légères), WEBP → WEBP, PNG / GIF → PNG (transparence).
        if (function_exists('imagecreatefromstring')) {
            [$sw, $sh] = @getimagesize($file['tmp_name']) ?: [0, 0];
            self::ensureMemory((int) $sw, (int) $sh);
            $img = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
            if ($img === false) {
                throw new BusinessException('Image illisible.');
            }
            if ($mime === 'image/jpeg') {
                $img = self::orient($img, $file['tmp_name']); // photo de téléphone : sens réel
            }
            $w = imagesx($img);
            $h = imagesy($img);
            if ($w > $maxDim || $h > $maxDim) {
                $ratio = min($maxDim / $w, $maxDim / $h);
                $nw = max(1, (int) round($w * $ratio));
                $nh = max(1, (int) round($h * $ratio));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, (int) imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
            }
            $ext = match (true) {
                $mime === 'image/jpeg' => 'jpg',
                $mime === 'image/webp' && function_exists('imagewebp') => 'webp',
                default => 'png',
            };
            $target = $dir . '/' . $name . '.' . $ext;
            $ok = match ($ext) {
                'jpg' => imagejpeg($img, $target, 86),
                'webp' => imagewebp($img, $target, 85),
                default => (imagesavealpha($img, true) && imagepng($img, $target, 6)),
            };
            imagedestroy($img);
            if (!$ok || !is_file($target)) {
                throw new \RuntimeException('Échec de l\'enregistrement de l\'image.');
            }
            return trim($subdir, '/') . '/' . $name . '.' . $ext;
        }
        $target = $dir . '/' . $name . '.' . self::IMAGE_TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new \RuntimeException('Échec de l\'enregistrement du fichier.');
        }
        return trim($subdir, '/') . '/' . $name . '.' . self::IMAGE_TYPES[$mime];
    }

    /**
     * Vidéo MP4 ou WEBM (écran client) : extension, type réel et signature du fichier contrôlés,
     * nom aléatoire. La vidéo n'est pas ré-encodée : elle est seulement lue par le navigateur.
     */
    public static function video(array $file, string $subdir, int $maxBytes): string
    {
        self::checkError($file);
        if ($file['size'] > $maxBytes) {
            throw new BusinessException('Vidéo trop volumineuse (max ' . round($maxBytes / 1048576) . ' Mo).');
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'webm'], true)) {
            throw new BusinessException('Format vidéo non autorisé (MP4 ou WEBM).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 16);
        $isMp4 = substr($head, 4, 4) === 'ftyp';
        $isWebm = str_starts_with($head, "\x1A\x45\xDF\xA3");
        if (!($isMp4 && in_array($mime, ['video/mp4', 'video/quicktime', 'application/octet-stream'], true)) && !($isWebm && in_array($mime, ['video/webm', 'application/octet-stream', 'video/x-matroska'], true))) {
            throw new BusinessException('Le fichier n\'est pas une vidéo MP4 ou WEBM valide.');
        }
        $dir = UPLOADS_PATH . '/' . trim($subdir, '/');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Dossier de téléversement inaccessible.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . ($isMp4 ? 'mp4' : 'webm');
        $ok = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $dir . '/' . $name) : move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
        if (!$ok) {
            throw new \RuntimeException('Échec de l\'enregistrement du fichier.');
        }
        return trim($subdir, '/') . '/' . $name;
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

    /** Mémoire suffisante pour décoder une grande photo (≈ 5 octets par pixel avec la copie). */
    private static function ensureMemory(int $w, int $h): void
    {
        $need = (int) ($w * $h * 5 + 32 * 1048576);
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1' || $need <= 0) {
            return;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        if ($bytes < $need) {
            @ini_set('memory_limit', (string) (int) ceil($need / 1048576) . 'M');
        }
    }

    /** Applique l'orientation EXIF d'une photo JPEG (prise de vue en portrait au téléphone). */
    private static function orient(\GdImage $img, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $r = match ($o) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => false };
        if ($r instanceof \GdImage) {
            imagedestroy($img);
            return $r;
        }
        return $img;
    }

    /** Taille maximale d'un envoi acceptée par PHP (upload_max_filesize et post_max_size), en octets. */
    public static function phpMaxBytes(): int
    {
        $b = function (string $v): int {
            $v = trim($v);
            return $v === '' ? 0 : (int) $v * match (strtolower(substr($v, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        };
        $vals = array_filter([$b((string) ini_get('upload_max_filesize')), $b((string) ini_get('post_max_size'))]);
        return $vals ? min($vals) : 0;
    }

    private static function checkError(array $file): void
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new BusinessException('Fichier invalide.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new BusinessException(match ((int) $file['error']) {
                UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux pour ce serveur (limite PHP : ' . ini_get('upload_max_filesize') . ' par fichier). Réduisez l\'image ou augmentez « upload_max_filesize » dans php.ini (WampServer : icône → PHP → Réglages PHP).',
                UPLOAD_ERR_PARTIAL => 'Envoi interrompu avant la fin : réessayez.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Le serveur ne peut pas enregistrer le fichier temporaire (dossier temporaire de PHP absent ou plein).',
                default => 'Erreur lors de l\'envoi du fichier (code ' . (int) $file['error'] . ').',
            });
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
        $path = UPLOADS_PATH . '/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
