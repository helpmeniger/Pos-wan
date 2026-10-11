<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = []
    ) {
    }

    /** Fichier binaire servi sans mise en cache et sans interprétation par le navigateur. */
    public static function file(string $data, string $mime, string $filename, bool $inline = false): self
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'fichier';
        return new self($data, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($data),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function csv(array $header, iterable $rows, string $filename): self
    {
        $fh = fopen('php://temp', 'w+b');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($fh, $header, ';', '"', '');
        foreach ($rows as $row) {
            // Neutralisation des formules (injection CSV).
            $row = array_map(static function ($v) {
                $v = (string) ($v ?? '');
                return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            }, $row);
            fputcsv($fh, $row, ';', '"', '');
        }
        rewind($fh);
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        return self::file($data, 'text/csv; charset=UTF-8', $filename);
    }
}
