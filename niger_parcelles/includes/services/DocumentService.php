<?php
/**
 * Documents justificatifs : validation stricte des fichiers, stockage privé
 * sous un nom interne aléatoire, téléchargement contrôlé.
 */
declare(strict_types=1);

final class DocumentService
{
    /**
     * Valide un fichier téléversé. Retourne [extension, mime] ou lève une exception.
     * $checkUploaded=false uniquement pour les tests automatisés.
     */
    public static function validateFile(array $file, bool $checkUploaded = true): array
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée.',
                UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement envoyé.',
                UPLOAD_ERR_NO_FILE => 'Aucun fichier sélectionné.',
            ];
            throw new UserFacingException($messages[$err] ?? 'Erreur lors de l\'envoi du fichier.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($checkUploaded && !is_uploaded_file($tmp)) {
            throw new UserFacingException('Fichier non valide.');
        }
        $size = (int) filesize($tmp);
        $max = (int) setting('max_upload_mb', 10) * 1024 * 1024;
        if ($size <= 0) {
            throw new UserFacingException('Le fichier est vide.');
        }
        if ($size > $max) {
            throw new UserFacingException('Le fichier dépasse la taille maximale de ' . (int) setting('max_upload_mb', 10) . ' Mo.');
        }
        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = (array) config('upload_allowed', []);
        if (!isset($allowed[$ext])) {
            throw new UserFacingException('Type de fichier non autorisé. Formats acceptés : ' . implode(', ', array_keys($allowed)) . '.');
        }
        // Double extension dangereuse (ex. fichier.php.pdf)
        if (preg_match('/\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|exe|bat|cmd|js|html?|svg|htaccess)\./i', $name)) {
            throw new UserFacingException('Nom de fichier suspect refusé.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!in_array($mime, $allowed[$ext], true)) {
            throw new UserFacingException("Le contenu du fichier ($mime) ne correspond pas à son extension .$ext.");
        }
        $head = (string) file_get_contents($tmp, false, null, 0, 8);
        if ($ext === 'pdf' && strncmp($head, '%PDF-', 5) !== 0) {
            throw new UserFacingException('Le fichier PDF est invalide.');
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($tmp) === false) {
            throw new UserFacingException('L\'image est invalide ou corrompue.');
        }
        // Contenu exécutable dissimulé (polyglotte)
        $fh = fopen($tmp, 'rb');
        while ($fh && !feof($fh)) {
            $chunk = (string) fread($fh, 1048576);
            if (preg_match('/<\?php|<\?=|<script\b/i', $chunk)) {
                fclose($fh);
                throw new UserFacingException('Le fichier contient du code exécutable et a été refusé.');
            }
            if (!feof($fh)) {
                fseek($fh, -16, SEEK_CUR); // chevauchement entre blocs
            }
        }
        if ($fh) {
            fclose($fh);
        }
        return [$ext, $mime, $size];
    }

    /**
     * Enregistre un document lié à une parcelle et/ou un propriétaire.
     * @return int identifiant du document
     */
    public static function store(array $file, array $meta, int $userId, bool $checkUploaded = true): int
    {
        [$ext, $mime, $size] = self::validateFile($file, $checkUploaded);
        $parcelId = !empty($meta['parcel_id']) ? (int) $meta['parcel_id'] : null;
        $ownerId = !empty($meta['owner_id']) ? (int) $meta['owner_id'] : null;
        if (!$parcelId && !$ownerId) {
            throw new UserFacingException('Le document doit être associé à une parcelle ou à un propriétaire.');
        }
        if ($parcelId) {
            ParcelService::getOrFail($parcelId);
        }
        if ($ownerId) {
            OwnerService::getOrFail($ownerId);
        }
        $type = (string) ($meta['document_type'] ?? 'autre');
        if (!array_key_exists($type, labels('document_type'))) {
            throw new UserFacingException('Catégorie de document invalide.');
        }
        $title = trim((string) ($meta['title'] ?? ''));
        if ($title === '') {
            $title = label('document_type', $type);
        }
        $docDate = !empty($meta['document_date']) ? (string) $meta['document_date'] : null;
        if ($docDate !== null) {
            $d = DateTime::createFromFormat('!Y-m-d', $docDate);
            if (!$d || $d->format('Y-m-d') !== $docDate) {
                throw new UserFacingException('Date du document invalide.');
            }
        }
        $replaces = !empty($meta['replaces_id']) ? (int) $meta['replaces_id'] : null;

        // Nom interne aléatoire, sans l'extension d'origine : jamais exécutable
        $sub = date('Y/m');
        $dir = storage_path('documents/' . $sub);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossible de créer le dossier de stockage des documents.');
        }
        $storageName = $sub . '/' . bin2hex(random_bytes(16)) . '.bin';
        $dest = storage_path('documents/' . $storageName);
        $ok = $checkUploaded ? move_uploaded_file($file['tmp_name'], $dest) : copy($file['tmp_name'], $dest);
        if (!$ok) {
            throw new RuntimeException('Échec de l\'enregistrement du fichier.');
        }
        @chmod($dest, 0640);
        $sha = hash_file('sha256', $dest);
        $original = mb_substr(preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', basename((string) $file['name'])) ?: 'document.' . $ext, 0, 255);

        try {
            return db_transaction(static function () use ($parcelId, $ownerId, $type, $title, $meta, $docDate, $original, $storageName, $mime, $size, $sha, $replaces, $userId) {
                db_query('INSERT INTO documents (parcel_id, owner_id, document_type, title, document_reference, document_date, original_filename,
                          storage_filename, mime_type, file_size, sha256, replaces_id, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $parcelId, $ownerId, $type, mb_substr($title, 0, 200),
                    !empty($meta['document_reference']) ? mb_substr(trim((string) $meta['document_reference']), 0, 100) : null,
                    $docDate, $original, $storageName, $mime, $size, $sha, $replaces, $userId,
                ]);
                $id = (int) db()->lastInsertId();
                if ($replaces) {
                    db_query('UPDATE documents SET is_archived = 1 WHERE id = ?', [$replaces]);
                }
                if ($parcelId) {
                    ParcelService::history($parcelId, $userId, 'document_ajout', $replaces ? ['document_remplace' => $replaces] : null,
                        ['document_id' => $id, 'type' => $type, 'titre' => $title, 'fichier' => $original]);
                }
                audit_log('document_ajout', 'document', $id, ['parcel_id' => $parcelId, 'owner_id' => $ownerId]);
                return $id;
            });
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }
    }

    public static function get(int $id): ?array
    {
        return db_one('SELECT d.*, p.parcel_reference, u.full_name AS uploaded_by_name,
                              o.first_name, o.last_name, o.organization_name, o.owner_type
                       FROM documents d LEFT JOIN parcels p ON p.id = d.parcel_id
                       LEFT JOIN owners o ON o.id = d.owner_id
                       LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.id = ?', [$id]);
    }

    public static function setStatus(int $id, string $status, int $userId): void
    {
        if (!array_key_exists($status, labels('document_status'))) {
            throw new UserFacingException('Statut de document invalide.');
        }
        $doc = self::get($id);
        if (!$doc) {
            throw new UserFacingException('Document introuvable.', 404);
        }
        db_query('UPDATE documents SET verification_status = ?, verified_by = ?, verified_at = NOW() WHERE id = ?', [$status, $userId, $id]);
        if ($doc['parcel_id']) {
            ParcelService::history((int) $doc['parcel_id'], $userId, 'document_statut', ['document_id' => $id, 'statut' => $doc['verification_status']],
                ['document_id' => $id, 'statut' => $status]);
        }
        audit_log('document_statut', 'document', $id, ['statut' => $status]);
    }

    public static function archive(int $id, int $userId): void
    {
        $doc = self::get($id);
        if (!$doc) {
            throw new UserFacingException('Document introuvable.', 404);
        }
        db_query('UPDATE documents SET is_archived = 1 WHERE id = ?', [$id]);
        if ($doc['parcel_id']) {
            ParcelService::history((int) $doc['parcel_id'], $userId, 'document_archive', null, ['document_id' => $id]);
        }
        audit_log('document_archive', 'document', $id);
    }

    /** Envoie le fichier au navigateur après contrôle des droits (appelant). */
    public static function stream(array $doc, bool $inline = false): void
    {
        $path = storage_path('documents/' . $doc['storage_filename']);
        $real = realpath($path);
        $base = realpath(storage_path('documents'));
        if ($real === false || $base === false || strpos($real, $base) !== 0 || !is_file($real)) {
            throw new UserFacingException('Fichier introuvable sur le serveur.', 404);
        }
        $inline = $inline && in_array($doc['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
        $name = str_replace(['"', "\r", "\n"], '', (string) $doc['original_filename']);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $doc['mime_type']);
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: 'document', '\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        if ($doc['mime_type'] === 'application/pdf') {
            // La visionneuse PDF intégrée du navigateur ne fonctionne pas en mode « sandbox »
            header_remove('Content-Security-Policy');
        } else {
            header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        }
        header('Cache-Control: private, no-store');
        readfile($real);
        exit;
    }
}
