<?php
declare(strict_types=1);

namespace Addons\Archives;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;

/**
 * Archives : documents rangés dans des dossiers, fichiers gardés hors du dossier public.
 * - types autorisés par liste blanche (extension ET contenu réel) ; jamais de fichier exécutable ;
 * - chaque fichier garde son empreinte SHA-256 (intégrité, détection des doublons) ;
 * - un nouveau fichier pour un même document = nouvelle version, les anciennes restent ;
 * - suppression en deux temps : corbeille (restaurable), puis suppression définitive réservée.
 */
final class ArchiveService
{
    public const CATEGORIES = ['administratif' => 'Administratif', 'juridique' => 'Juridique (statuts, agréments)', 'contrat' => 'Contrats et conventions', 'finance' => 'Finances et comptabilité',
        'rh' => 'Ressources humaines', 'projet' => 'Projets', 'courrier' => 'Courriers', 'rapport' => 'Rapports', 'technique' => 'Technique', 'photo' => 'Photos', 'autre' => 'Autre'];

    /** extension => types réels acceptés (finfo) */
    public const TYPES = [
        'pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'], 'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'], 'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'], 'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
    /** Affichés dans le navigateur ; tout le reste est téléchargé. */
    public const INLINE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public static function root(): string
    {
        $dir = STORAGE_PATH . '/archives';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    public static function maxBytes(): int
    {
        $mb = max(1, (int) setting('archives_taille_max_mo', '20'));
        $ini = function (string $k): int {
            $v = trim((string) ini_get($k));
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n ?: PHP_INT_MAX };
        };
        return min($mb * 1048576, $ini('upload_max_filesize'), $ini('post_max_size'));
    }

    public static function canSee(array $row): bool
    {
        return !(int) ($row['confidential'] ?? 0) || can('archives.confidential');
    }

    // ------------------------------------------------------------------ dossiers

    public static function dossier(int $id): array
    {
        $d = DB::one('SELECT * FROM arch_dossiers WHERE id = ?', [$id]);
        if (!$d || !self::canSee($d)) {
            throw new BusinessException('Dossier introuvable.');
        }
        return $d;
    }

    /** Chemin du dossier, de la racine jusqu'à lui. */
    public static function path(?int $id): array
    {
        $out = [];
        for ($n = 0; $id && $n < 30; $n++) {
            $d = DB::one('SELECT id, parent_id, name, confidential FROM arch_dossiers WHERE id = ?', [$id]);
            if (!$d) {
                break;
            }
            array_unshift($out, $d);
            $id = (int) $d['parent_id'];
        }
        return $out;
    }

    public static function saveDossier(array $in, ?int $id = null): int
    {
        $name = trim(preg_replace('/[\/\\\\]+/', ' ', (string) ($in['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw new BusinessException('Nom du dossier obligatoire (120 caractères au plus).');
        }
        $parent = (int) ($in['parent_id'] ?? 0) ?: null;
        if ($parent) {
            self::dossier($parent);
            foreach (self::path($parent) as $p) {
                if ($id && (int) $p['id'] === $id) {
                    throw new BusinessException('Un dossier ne peut pas être rangé dans lui-même.');
                }
            }
        }
        if (DB::value('SELECT id FROM arch_dossiers WHERE name = ? AND COALESCE(parent_id, 0) = ? AND id <> ?', [$name, (int) $parent, (int) $id])) {
            throw new BusinessException('Un dossier porte déjà ce nom à cet endroit.');
        }
        $data = ['name' => $name, 'parent_id' => $parent, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255) ?: null,
            'confidential' => !empty($in['confidential']) && can('archives.confidential') ? 1 : 0];
        if ($id) {
            self::dossier($id);
            if (!can('archives.confidential')) {
                unset($data['confidential']);
            }
            DB::update('arch_dossiers', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('arch_dossiers', $data + ['created_by' => Auth::id()]);
        }
        Audit::log('archives.dossier.save', 'arch_dossiers', $id, ['name' => $name]);
        return $id;
    }

    /** Un dossier ne se supprime que vide (ni sous-dossier, ni document, même en corbeille). */
    public static function deleteDossier(int $id): void
    {
        $d = self::dossier($id);
        if (DB::value('SELECT id FROM arch_dossiers WHERE parent_id = ? LIMIT 1', [$id]) || DB::value('SELECT id FROM arch_documents WHERE dossier_id = ? LIMIT 1', [$id])) {
            throw new BusinessException('Ce dossier n\'est pas vide : déplacez ou supprimez d\'abord son contenu (corbeille comprise).');
        }
        DB::run('DELETE FROM arch_dossiers WHERE id = ?', [$id]);
        Audit::log('archives.dossier.supprime', 'arch_dossiers', $id, ['name' => $d['name']]);
    }

    // ------------------------------------------------------------------ fichiers

    /** Contrôle un fichier envoyé : @return array{ext:string, mime:string, size:int, sha:string} */
    public static function inspect(string $tmp, string $originalName, int $size): array
    {
        if (!is_file($tmp) || $size <= 0) {
            throw new BusinessException('Fichier vide ou non reçu.');
        }
        if ($size > self::maxBytes()) {
            throw new BusinessException('« ' . $originalName . ' » dépasse la taille autorisée (' . round(self::maxBytes() / 1048576, 1) . ' Mo).');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw new BusinessException('« ' . $originalName . ' » : type de fichier non accepté. Acceptés : ' . implode(', ', array_keys(self::TYPES)) . '.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::TYPES[$ext], true)) {
            throw new BusinessException('« ' . $originalName . ' » : le contenu du fichier ne correspond pas à son extension (' . $mime . ').');
        }
        // Un document bureautique ou texte ne doit pas cacher un script
        $head = (string) file_get_contents($tmp, false, null, 0, 2048);
        if (preg_match('/<\?php|<script\b|<html\b/i', $head) && !in_array($ext, ['pdf', 'zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods'], true)) {
            throw new BusinessException('« ' . $originalName . ' » contient du code : fichier refusé.');
        }
        return ['ext' => $ext, 'mime' => self::INLINE[$ext] ?? $mime, 'size' => $size, 'sha' => (string) hash_file('sha256', $tmp)];
    }

    /** Range le fichier dans storage/archives/AAAA/MM sous un nom aléatoire et l'enregistre comme version. */
    private static function store(int $documentId, string $tmp, string $originalName, array $info, string $note, bool $uploaded): int
    {
        $rel = date('Y') . '/' . date('m');
        $dir = self::root() . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new BusinessException('Impossible de créer le dossier de stockage des archives.');
        }
        $stored = $rel . '/' . bin2hex(random_bytes(16)) . '.bin';
        $ok = $uploaded ? move_uploaded_file($tmp, self::root() . '/' . $stored) : copy($tmp, self::root() . '/' . $stored);
        if (!$ok) {
            throw new BusinessException('Enregistrement du fichier impossible (espace disque ou droits du dossier storage).');
        }
        try {
            $v = (int) DB::value('SELECT COALESCE(MAX(version), 0) FROM arch_versions WHERE document_id = ?', [$documentId]) + 1;
            $id = DB::insert('arch_versions', ['document_id' => $documentId, 'version' => $v, 'original_name' => mb_substr(basename($originalName), -200), 'stored_path' => $stored, 'extension' => $info['ext'],
                'mime' => $info['mime'], 'size' => $info['size'], 'sha256' => $info['sha'], 'note' => mb_substr(trim($note), 0, 255) ?: null, 'uploaded_by' => Auth::id()]);
            DB::update('arch_documents', ['versions' => $v], 'id = ?', [$documentId]);
            return $id;
        } catch (\Throwable $e) {
            @unlink(self::root() . '/' . $stored);
            throw $e;
        }
    }

    private static function meta(array $in): array
    {
        $cat = (string) ($in['category'] ?? 'autre');
        if (!isset(self::CATEGORIES[$cat])) {
            throw new BusinessException('Catégorie inconnue.');
        }
        $d = [];
        foreach (['doc_date' => 'Date du document', 'expires_on' => 'Échéance'] as $k => $l) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && !Validator::isDate($v)) {
                throw new BusinessException("$l : date invalide.");
            }
            $d[$k] = $v ?: null;
        }
        $tags = implode(', ', array_slice(array_unique(array_filter(array_map(fn ($t) => mb_strtolower(trim($t)), explode(',', (string) ($in['tags'] ?? ''))))), 0, 15));
        return $d + ['category' => $cat, 'reference' => mb_substr(trim((string) ($in['reference'] ?? '')), 0, 80) ?: null, 'tags' => mb_substr($tags, 0, 255) ?: null,
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 5000) ?: null, 'confidential' => !empty($in['confidential']) && can('archives.confidential') ? 1 : 0];
    }

    /**
     * Dépôt d'un fichier comme nouveau document.
     * @return array{id:int, duplicate:?string} duplicate = titre d'un document déjà archivé avec le même contenu
     */
    public static function deposit(string $tmp, string $originalName, int $size, array $in, bool $uploaded = true): array
    {
        $info = self::inspect($tmp, $originalName, $size);
        $dossier = (int) ($in['dossier_id'] ?? 0) ?: null;
        $dos = $dossier ? self::dossier($dossier) : null;
        $meta = self::meta($in);
        if ($dos && (int) $dos['confidential']) {
            $meta['confidential'] = 1; // un document déposé dans un dossier confidentiel l'est aussi
        }
        $title = trim((string) ($in['title'] ?? '')) ?: pathinfo($originalName, PATHINFO_FILENAME);
        $dup = DB::value("SELECT d.title FROM arch_versions v JOIN arch_documents d ON d.id = v.document_id WHERE v.sha256 = ? AND d.status = 'actif' LIMIT 1", [$info['sha']]);
        return DB::transaction(function () use ($tmp, $originalName, $info, $dossier, $meta, $title, $dup, $uploaded) {
            $id = DB::insert('arch_documents', $meta + ['dossier_id' => $dossier, 'title' => mb_substr($title, 0, 200), 'created_by' => Auth::id()]);
            self::store($id, $tmp, $originalName, $info, '', $uploaded);
            Audit::log('archives.depot', 'arch_documents', $id, ['title' => $title, 'size' => $info['size'], 'sha256' => $info['sha']]);
            return ['id' => $id, 'duplicate' => $dup ? (string) $dup : null];
        });
    }

    public static function document(int $id): array
    {
        $d = DB::one('SELECT d.*, u.full_name AS author, x.full_name AS deleter FROM arch_documents d LEFT JOIN users u ON u.id = d.created_by LEFT JOIN users x ON x.id = d.deleted_by WHERE d.id = ?', [$id]);
        if (!$d || !self::canSee($d)) {
            throw new BusinessException('Document introuvable.');
        }
        return $d;
    }

    public static function addVersion(int $documentId, string $tmp, string $originalName, int $size, string $note, bool $uploaded = true): int
    {
        $d = self::document($documentId);
        if ($d['status'] !== 'actif') {
            throw new BusinessException('Ce document est dans la corbeille.');
        }
        $info = self::inspect($tmp, $originalName, $size);
        if (DB::value('SELECT id FROM arch_versions WHERE document_id = ? AND sha256 = ?', [$documentId, $info['sha']])) {
            throw new BusinessException('Ce fichier est identique à une version déjà archivée de ce document.');
        }
        return DB::transaction(function () use ($documentId, $tmp, $originalName, $info, $note, $uploaded) {
            $id = self::store($documentId, $tmp, $originalName, $info, $note, $uploaded);
            Audit::log('archives.version', 'arch_documents', $documentId, ['sha256' => $info['sha']]);
            return $id;
        });
    }

    public static function update(int $id, array $in): void
    {
        $d = self::document($id);
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') {
            throw new BusinessException('Titre du document obligatoire.');
        }
        $meta = self::meta($in);
        if (!can('archives.confidential')) {
            unset($meta['confidential']);
        }
        $dossier = (int) ($in['dossier_id'] ?? 0) ?: null;
        if ($dossier) {
            self::dossier($dossier);
        }
        DB::update('arch_documents', $meta + ['title' => mb_substr($title, 0, 200), 'dossier_id' => $dossier], 'id = ?', [$id]);
        Audit::log('archives.modifie', 'arch_documents', $id, ['title' => $title, 'deplace' => (int) $d['dossier_id'] !== (int) $dossier]);
    }

    public static function trash(int $id): void
    {
        $d = self::document($id);
        if ($d['status'] !== 'actif') {
            throw new BusinessException('Ce document est déjà dans la corbeille.');
        }
        DB::update('arch_documents', ['status' => 'corbeille', 'deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => Auth::id()], 'id = ?', [$id]);
        Audit::log('archives.corbeille', 'arch_documents', $id, ['title' => $d['title']]);
    }

    public static function restore(int $id): void
    {
        $d = self::document($id);
        if ($d['status'] !== 'corbeille') {
            throw new BusinessException('Ce document n\'est pas dans la corbeille.');
        }
        $dossier = $d['dossier_id'] && DB::value('SELECT id FROM arch_dossiers WHERE id = ?', [$d['dossier_id']]) ? $d['dossier_id'] : null;
        DB::update('arch_documents', ['status' => 'actif', 'deleted_at' => null, 'deleted_by' => null, 'dossier_id' => $dossier], 'id = ?', [$id]);
        Audit::log('archives.restaure', 'arch_documents', $id, ['title' => $d['title']]);
    }

    /** Suppression définitive : seulement depuis la corbeille, en retapant le titre du document. */
    public static function purge(int $id, string $confirmTitle): void
    {
        $d = self::document($id);
        if ($d['status'] !== 'corbeille') {
            throw new BusinessException('Mettez d\'abord le document à la corbeille.');
        }
        if (mb_strtolower(trim($confirmTitle)) !== mb_strtolower(trim($d['title']))) {
            throw new BusinessException('Pour confirmer, retapez exactement le titre du document.');
        }
        $files = DB::all('SELECT stored_path, sha256 FROM arch_versions WHERE document_id = ?', [$id]);
        DB::transaction(function () use ($id) {
            DB::run('DELETE FROM arch_versions WHERE document_id = ?', [$id]);
            DB::run('DELETE FROM arch_documents WHERE id = ?', [$id]);
        });
        foreach ($files as $f) {
            if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', $f['stored_path'])) {
                @unlink(self::root() . '/' . $f['stored_path']);
            }
        }
        Audit::log('archives.supprime_definitivement', 'arch_documents', $id, ['title' => $d['title'], 'fichiers' => array_column($files, 'sha256')]);
    }

    /** Version à servir + contrôle d'intégrité (le fichier sur disque doit avoir l'empreinte enregistrée). */
    public static function file(int $versionId): array
    {
        $v = DB::one('SELECT v.*, d.title, d.confidential, d.status FROM arch_versions v JOIN arch_documents d ON d.id = v.document_id WHERE v.id = ?', [$versionId]);
        if (!$v || !self::canSee($v) || !preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', $v['stored_path'])) {
            throw new BusinessException('Fichier introuvable.');
        }
        $v['abs'] = self::root() . '/' . $v['stored_path'];
        if (!is_file($v['abs'])) {
            throw new BusinessException('Le fichier n\'est plus sur le disque du serveur (restaurez une sauvegarde du dossier storage/archives).');
        }
        return $v;
    }

    public static function verify(int $versionId): bool
    {
        $v = self::file($versionId);
        return hash_equals($v['sha256'], (string) hash_file('sha256', $v['abs']));
    }

    public static function stats(): array
    {
        $days = max(1, (int) setting('archives_alerte_jours', '30'));
        $conf = can('archives.confidential') ? '' : ' AND d.confidential = 0';
        return [
            'documents' => (int) DB::value("SELECT COUNT(*) FROM arch_documents d WHERE d.status = 'actif'$conf"),
            'size' => (int) DB::value('SELECT COALESCE(SUM(size), 0) FROM arch_versions'),
            'trash' => (int) DB::value("SELECT COUNT(*) FROM arch_documents d WHERE d.status = 'corbeille'$conf"),
            'expiring' => (int) DB::value("SELECT COUNT(*) FROM arch_documents d WHERE d.status = 'actif' AND d.expires_on IS NOT NULL AND d.expires_on <= ?$conf", [date('Y-m-d', strtotime("+$days days"))]),
            'alertDays' => $days,
        ];
    }

    public static function humanSize(int|float $b): string
    {
        foreach (['octets', 'Ko', 'Mo', 'Go'] as $i => $u) {
            if ($b < 1024 || $u === 'Go') {
                return ($i ? number_format($b, 1, ',', ' ') : (string) (int) $b) . ' ' . $u;
            }
            $b /= 1024;
        }
        return '';
    }
}
