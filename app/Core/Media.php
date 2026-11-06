<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Photos et vidéos téléversées servies PAR L'APPLICATION (adresse /media/…), au lieu d'un accès
 * direct au dossier public/uploads. Ainsi une image s'affiche dès que les pages s'affichent,
 * quelle que soit la configuration du serveur : règles .htaccess refusées, module Apache absent,
 * type de fichier inconnu, sous-dossier ou réécriture d'adresse (WampServer, o2switch, VPS).
 * Seuls les fichiers image / vidéo du dossier des téléversements sont lisibles.
 */
final class Media
{
    public const TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];

    /** Chemin relatif valide (aucune remontée de dossier, extension autorisée) → fichier, sinon null. */
    public static function resolve(string $rel): ?string
    {
        $rel = ltrim(rawurldecode($rel), '/');
        if (!preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*\.([A-Za-z0-9]{2,5})$#', $rel, $m) || !isset(self::TYPES[strtolower($m[2])])) {
            return null;
        }
        $file = UPLOADS_PATH . '/' . $rel;
        $root = realpath(UPLOADS_PATH);
        $real = realpath($file);
        if ($root === false || $real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    public static function serve(string $rel): never
    {
        $file = self::resolve($rel);
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Fichier introuvable.';
            exit;
        }
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))];
        $size = (int) filesize($file);
        $mtime = (int) filemtime($file);
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('Set-Cookie');
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'");
        header('Accept-Ranges: bytes');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        // Adresse versionnée (?v=date du fichier) : conservée longtemps par le navigateur
        header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600'));
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        $start = 0;
        $end = $size - 1;
        // Lecture partielle : nécessaire aux vidéos (avance rapide, lecture en continu)
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $r) && $size > 0) {
            if ($r[1] === '' && $r[2] !== '') {
                $start = max(0, $size - (int) $r[2]);
            } else {
                $start = (int) $r[1];
                $end = $r[2] !== '' ? min((int) $r[2], $size - 1) : $size - 1;
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        $fh = fopen($file, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = (string) fread($fh, (int) min(1048576, $left));
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }
}
