<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** Redirige vers une route interne (chemin relatif à l'application). */
    public static function redirect(string $path, array $query = []): never
    {
        header('Location: ' . url($path, $query), true, 303);
        exit;
    }

    public static function back(string $fallback = 'dashboard'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($ref && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
            header('Location: ' . $ref, true, 303);
            exit;
        }
        self::redirect($fallback);
    }

    /** Envoie un fichier local (déjà validé) en streaming. */
    public static function file(string $path, string $mime, ?string $downloadName = null, bool $private = true): never
    {
        if (!is_file($path)) {
            throw new HttpException(404, 'Fichier introuvable.');
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($private ? 'private, max-age=300' : 'public, max-age=86400'));
        if ($downloadName !== null) {
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) . '"');
        }
        readfile($path);
        exit;
    }

    public static function download(string $content, string $mime, string $filename): never
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($content));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        echo $content;
        exit;
    }
}
