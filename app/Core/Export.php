<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\Pdf;

/**
 * Export des listes et rapports : CSV (Excel) ou PDF selon ?export=csv|pdf.
 * Les mêmes lignes servent aux deux formats.
 */
final class Export
{
    public static function requested(): ?string
    {
        $f = (string) Request::query('export', '');
        return in_array($f, ['csv', 'pdf'], true) ? $f : null;
    }

    /**
     * @param string $filename nom du fichier CSV (l'extension est remplacée pour le PDF)
     * @param array{subtitle?:string, footer?:array, landscape?:bool} $opts options PDF
     */
    public static function download(string $filename, string $title, array $headers, iterable $rows, array $opts = []): never
    {
        $rtl = Lang::dir() === 'rtl'; // PDF en polices latines : en arabe, en-têtes conservés en français
        $headers = array_map(fn($h) => $rtl ? (string) $h : t((string) $h), $headers);
        if (self::requested() !== 'pdf') {
            $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
            if (!empty($opts['footer'])) {
                $rows[] = $opts['footer'];
            }
            Csv::download($filename, $headers, array_map(fn($r) => array_values(array_diff_key($r, ['_bold' => 1])), $rows));
        }
        $pdf = Pdf::table($rtl ? $title : t($title), $headers, $rows, $opts);
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', preg_replace('/\.csv$/i', '', $filename)) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }

    /** Sous-titre « Du … au … » d'un rapport. */
    public static function period(string $from, string $to): string
    {
        return t('Du') . ' ' . fdate($from, false) . ' ' . t('au') . ' ' . fdate($to, false);
    }
}
