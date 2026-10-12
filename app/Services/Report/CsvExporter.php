<?php
declare(strict_types=1);

namespace App\Services\Report;

/** CSV compatible Excel (France/Niger) : UTF-8 avec BOM, séparateur « ; ». */
final class CsvExporter
{
    public static function export(array $report): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [$report['title']], ';');
        fputcsv($fh, [$report['subtitle'] ?? ''], ';');
        fputcsv($fh, array_values($report['columns']), ';');
        foreach ($report['rows'] as $row) {
            fputcsv($fh, array_map(static fn ($k) => self::cell($row[$k] ?? ''), array_keys($report['columns'])), ';');
        }
        if (!empty($report['totals'])) {
            fputcsv($fh, array_map(static fn ($k) => self::cell($report['totals'][$k] ?? ''), array_keys($report['columns'])), ';');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    /** Neutralise l'injection de formules dans les tableurs. */
    public static function cell(mixed $v): string
    {
        $s = (string) $v;
        return ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true) && !is_numeric($s)) ? "'" . $s : $s;
    }
}
