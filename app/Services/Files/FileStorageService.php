<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Core\Config;
use App\Core\Database;
use App\Core\ValidationException;
use App\Repositories\SettingRepository;
use App\Support\Uuid;

/**
 * Téléversements sécurisés :
 *  - taille maximale, type RÉEL détecté par finfo (l'extension et le type annoncé sont ignorés),
 *  - liste blanche (JPEG, PNG, WebP, PDF), contrôle de la signature PDF,
 *  - images ré-encodées avec GD (supprime métadonnées et contenus cachés),
 *  - nom aléatoire, stockage PRIVÉ hors du dossier web, empreinte SHA-256.
 */
class FileStorageService
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * @param array<string, mixed>|null $upload élément de $_FILES
     * @return int identifiant stored_files
     */
    public function storePrivate(?array $upload, int $ownerId, string $purpose, string $field = 'document'): int
    {
        if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException([$field => ['Veuillez choisir un fichier.']]);
        }
        $error = (int) $upload['error'];
        $maxMb = (int) $this->settings->get('uploads.max_size_mb', 5);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ValidationException([$field => ["Fichier trop volumineux (maximum {$maxMb} Mo)."]]);
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name']) && !$this->isTestUpload($upload)) {
            throw new ValidationException([$field => ['Le fichier n\'a pas pu être reçu, réessayez.']]);
        }
        $tmp = (string) $upload['tmp_name'];
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $maxMb * 1024 * 1024) {
            throw new ValidationException([$field => ["Fichier vide ou trop volumineux (maximum {$maxMb} Mo)."]]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $allowed = (array) $this->settings->get('uploads.allowed_mimes', array_keys(self::EXTENSIONS));
        if (!isset(self::EXTENSIONS[$mime]) || !in_array($mime, $allowed, true)) {
            throw new ValidationException([$field => ['Format refusé : envoyez une photo (JPG, PNG, WebP) ou un PDF.']]);
        }
        $content = (string) file_get_contents($tmp);
        if ($mime === 'application/pdf' && !str_starts_with($content, '%PDF-')) {
            throw new ValidationException([$field => ['Ce PDF semble invalide.']]);
        }
        if ($mime !== 'application/pdf') {
            $content = $this->reencodeImage($content, $mime) ?? $content;
            if (@getimagesizefromstring($content) === false) {
                throw new ValidationException([$field => ['Cette image semble invalide.']]);
            }
        }

        $relative = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];
        $absolute = $this->privateRoot() . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !@mkdir(dirname($absolute), 0750, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Dossier de stockage privé non inscriptible');
        }
        if (file_put_contents($absolute, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Écriture du fichier impossible');
        }
        $original = mb_substr(preg_replace('/[^\pL\pN ._-]/u', '_', basename((string) ($upload['name'] ?? 'document'))) ?? 'document', 0, 200);
        return $this->db->insert('stored_files', [
            'uuid' => Uuid::v4(),
            'owner_user_id' => $ownerId,
            'disk' => 'PRIVATE',
            'purpose' => $purpose,
            'storage_path' => $relative,
            'original_name' => $original !== '' ? $original : 'document',
            'mime_type' => $mime,
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'retention_until' => in_array($purpose, ['IDENTITY', 'BUSINESS_DOC'], true)
                ? date('Y-m-d', strtotime('+' . (int) $this->settings->get('privacy.identity_doc_retention_days', 90) . ' days'))
                : null,
        ]);
    }

    /** @return array{path: string, mime: string, name: string}|null */
    public function resolve(int $fileId): ?array
    {
        $f = $this->db->fetch('SELECT storage_path, mime_type, original_name, disk FROM stored_files WHERE id = ? AND deleted_at IS NULL', [$fileId]);
        if ($f === null || $f['disk'] !== 'PRIVATE' || str_contains((string) $f['storage_path'], '..')) {
            return null;
        }
        $path = $this->privateRoot() . '/' . $f['storage_path'];
        return is_file($path) ? ['path' => $path, 'mime' => (string) $f['mime_type'], 'name' => (string) $f['original_name']] : null;
    }

    private function reencodeImage(string $content, string $mime): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($content);
        if ($img === false) {
            return null;
        }
        ob_start();
        match ($mime) {
            'image/png' => imagepng($img, null, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($img, null, 85) : imagejpeg($img, null, 88),
            default => imagejpeg($img, null, 88),
        };
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    private function privateRoot(): string
    {
        return (string) $this->config->get('app.root') . '/storage/private';
    }

    /** Les tests fournissent des fichiers temporaires qui ne viennent pas d'un vrai envoi HTTP. */
    private function isTestUpload(array $upload): bool
    {
        return $this->config->get('app.env') === 'testing' && !empty($upload['test']) && is_file((string) $upload['tmp_name']);
    }
}
