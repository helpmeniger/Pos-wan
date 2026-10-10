<?php
declare(strict_types=1);

namespace App\Core;

/** Export/Import CSV compatible Excel (UTF-8 avec BOM, séparateur « ; »). */
final class Csv
{
    public static function download(string $filename, array $headers, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map([self::class, 'safeCell'], array_values($r)), ';', '"', '');
        }
        fclose($out);
        exit;
    }

    /** Neutralise l'injection de formules dans Excel (=, +, -, @). */
    public static function safeCell(mixed $v): mixed
    {
        if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v)) {
            return "'" . $v;
        }
        return $v;
    }

    /** @return array<int, array<string,string>> lignes indexées par en-tête (minuscules) */
    public static function read(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $first = $lines[0] ?? '';
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $rows = [];
        $headers = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $sep, '"', '');
            if ($headers === null) {
                $headers = array_map(fn($h) => mb_strtolower(trim((string) $h)), $cells);
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
