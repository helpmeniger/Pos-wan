<?php
declare(strict_types=1);

namespace App\Services\Report;

/**
 * Générateur PDF autonome (PDF 1.4, polices standard Helvetica, encodage WinAnsi).
 * Produit un rapport tabulaire paginé au format A4 paysage avec le logo MARWA vectoriel.
 */
final class PdfExporter
{
    private const W = 842.0;
    private const H = 595.0;
    private const MARGIN = 36.0;

    private array $pages = [];
    private string $cur = '';

    public static function export(array $report): string
    {
        return (new self())->build($report);
    }

    private function build(array $report): string
    {
        $cols = array_keys($report['columns']);
        $n = count($cols);
        $tableW = self::W - 2 * self::MARGIN;
        $firstW = $n > 1 ? min(220.0, $tableW * 0.3) : $tableW;
        $otherW = $n > 1 ? ($tableW - $firstW) / ($n - 1) : 0;
        $widths = array_map(static fn ($i) => $i === 0 ? $firstW : $otherW, array_keys($cols));
        $fs = $n > 9 ? 7.0 : 8.5;
        $rowH = $fs + 7;

        $rows = $report['rows'];
        if (!empty($report['totals'])) {
            $rows[] = $report['totals'] + ['__total' => true];
        }
        $page = 0;
        $y = 0.0;
        $newPage = function () use (&$page, &$y, $report, $cols, $widths, $fs, $rowH) {
            if ($this->cur !== '') {
                $this->pages[] = $this->cur;
            }
            $this->cur = '';
            $page++;
            $this->header($report);
            $y = self::H - 118;
            $this->rect(self::MARGIN, $y - 4, self::W - 2 * self::MARGIN, $rowH, [0.043, 0.431, 0.31]);
            $x = self::MARGIN;
            foreach ($cols as $i => $c) {
                $this->text($x + 3, $y + 1, $this->fit($report['columns'][$c], $widths[$i] - 6, $fs), $fs, true, [1, 1, 1], $i > 0 ? $widths[$i] - 6 : null);
                $x += $widths[$i];
            }
            $y -= $rowH;
        };
        $newPage();
        foreach ($rows as $ri => $row) {
            if ($y < self::MARGIN + 24) {
                $newPage();
            }
            $isTotal = !empty($row['__total']);
            if ($isTotal) {
                $this->rect(self::MARGIN, $y - 4, self::W - 2 * self::MARGIN, $rowH, [0.88, 0.95, 0.91]);
            } elseif ($ri % 2 === 1) {
                $this->rect(self::MARGIN, $y - 4, self::W - 2 * self::MARGIN, $rowH, [0.965, 0.965, 0.955]);
            }
            $x = self::MARGIN;
            foreach ($cols as $i => $c) {
                $v = $row[$c] ?? '';
                $txt = is_int($v) || is_float($v) ? number_format((float) $v, is_float($v) && floor($v) != $v ? 2 : 0, ',', ' ') : (string) $v;
                $this->text($x + 3, $y + 1, $this->fit($txt, $widths[$i] - 6, $fs), $fs, $isTotal, [0.1, 0.12, 0.12], (is_int($v) || is_float($v)) ? $widths[$i] - 6 : null);
                $x += $widths[$i];
            }
            $y -= $rowH;
        }
        if (!$report['rows']) {
            $this->text(self::MARGIN, $y - 4, 'Aucune donnée pour la période sélectionnée.', 10, false, [0.4, 0.4, 0.4]);
        }
        $this->pages[] = $this->cur;
        return $this->render(count($this->pages));
    }

    private function header(array $report): void
    {
        // Logo : pastille verte, chemin « M » stylisé, point ambre
        $x = self::MARGIN;
        $top = self::H - self::MARGIN;
        $this->cur .= sprintf("q 0.043 0.431 0.31 rg %.2F %.2F 34 34 re f Q\n", $x, $top - 34);
        $this->cur .= sprintf("q 1 1 1 RG 3.2 w 1 J 1 j %.2F %.2F m %.2F %.2F l %.2F %.2F l %.2F %.2F l %.2F %.2F l S Q\n",
            $x + 7, $top - 26, $x + 7, $top - 9, $x + 17, $top - 20, $x + 27, $top - 9, $x + 27, $top - 26);
        $this->cur .= sprintf("q 0.96 0.65 0.14 rg %.2F %.2F 5 5 re f Q\n", $x + 14.5, $top - 30);
        $this->text($x + 44, $top - 16, 'MARWA', 18, true, [0.043, 0.431, 0.31]);
        $this->text($x + 44, $top - 30, 'Mobilité & livraison — Niger', 8.5, false, [0.35, 0.38, 0.38]);
        $this->text(self::MARGIN, $top - 58, $report['title'], 14, true, [0.1, 0.12, 0.12]);
        $this->text(self::MARGIN, $top - 72, (string) ($report['subtitle'] ?? ''), 9, false, [0.35, 0.38, 0.38]);
        $this->text(self::W - self::MARGIN - 200, $top - 16, 'Édité le ' . date('d/m/Y à H:i'), 8.5, false, [0.35, 0.38, 0.38], 200);
        $this->text(self::W - self::MARGIN - 200, $top - 28, 'Montants en francs CFA (XOF)', 8.5, false, [0.35, 0.38, 0.38], 200);
        $this->cur .= sprintf("q 0.85 0.85 0.82 RG 0.6 w %.2F %.2F m %.2F %.2F l S Q\n", self::MARGIN, $top - 80, self::W - self::MARGIN, $top - 80);
    }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    /** Texte ; si $alignRightWidth est fourni, le texte est aligné à droite dans cette largeur. */
    private function text(float $x, float $y, string $s, float $size, bool $bold = false, array $rgb = [0, 0, 0], ?float $alignRightWidth = null): void
    {
        $enc = $this->encode($s);
        if ($alignRightWidth !== null) {
            $x += max(0, $alignRightWidth - $this->width($s, $size, $bold));
        }
        $this->cur .= sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $y, $enc);
    }

    private function encode(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{00A0}", '’', '—', '–', '…', '•'], [' ', ' ', "'", '-', '-', '...', '-'], $s);
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($c === false) {
            $c = preg_replace('/[^\x20-\x7E]/', '?', $s);
        }
        return strtr((string) $c, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    private function width(string $s, float $size, bool $bold): float
    {
        // Largeur moyenne Helvetica ≈ 0,52 em (0,55 en gras) ; chiffres ≈ 0,556 em
        $len = mb_strlen($s);
        $digits = preg_match_all('/[0-9]/', $s);
        return (($len - $digits) * ($bold ? 0.56 : 0.52) + $digits * 0.556) * $size;
    }

    private function fit(string $s, float $w, float $size): string
    {
        if ($this->width($s, $size, false) <= $w) {
            return $s;
        }
        while (mb_strlen($s) > 1 && $this->width($s . '...', $size, false) > $w) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '...';
    }

    private function render(int $count): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $next = 5;
        $pageObjs = [];
        foreach ($this->pages as $i => $content) {
            $content .= sprintf("BT 0.45 0.45 0.45 rg /F1 8 Tf %.2F %.2F Td (Page %d / %d - MARWA - document confidentiel) Tj ET\n", self::MARGIN, 18.0, $i + 1, $count);
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "$pageId 0 R";
            $pageObjs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contentId);
            $pageObjs[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects += $pageObjs;
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info << /Producer (MARWA) /Title (Rapport MARWA) >> >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}
