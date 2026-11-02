<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Générateur PDF minimaliste (texte Helvetica, rectangles, lignes, couleurs),
 * sans dépendance : utilisé pour l'export PDF des tickets et des reçus.
 * Unités : points (1 mm = 2,8346 pt), origine en haut à gauche.
 */
final class PdfWriter
{
    private array $pages = [];
    private string $current = '';
    private float $w;
    private float $h;
    /** Largeurs Helvetica (1/1000 em) pour le calcul de centrage. */
    private const WIDTHS = [32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015,
        65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611,
        97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500];

    public function __construct(float $widthMm = 210, float $heightMm = 297)
    {
        $this->w = $widthMm * 2.8346;
        $this->h = $heightMm * 2.8346;
    }

    public static function mm(float $v): float
    {
        return $v * 2.8346;
    }

    public function addPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = ' ';
    }

    private static function hex(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    private static function enc(string $text): string
    {
        $t = function_exists('iconv') ? (string) @iconv('UTF-8', 'CP1252//TRANSLIT', $text) : mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $t);
    }

    public function textWidth(string $text, float $size, bool $bold = false): float
    {
        $t = function_exists('iconv') ? (string) @iconv('UTF-8', 'CP1252//TRANSLIT', $text) : $text;
        $w = 0;
        foreach (str_split($t) as $ch) {
            $w += self::WIDTHS[ord($ch)] ?? 556;
        }
        return $w * $size / 1000 * ($bold ? 1.06 : 1);
    }

    /** Texte ; $align = L, C ou R par rapport à $x (C/R : $x est le centre / bord droit). */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, string $color = '#000000', string $align = 'L'): void
    {
        if ($align !== 'L') {
            $tw = $this->textWidth($text, $size, $bold);
            $x -= $align === 'C' ? $tw / 2 : $tw;
        }
        $this->current .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, self::hex($color), $x, $this->h - $y, self::enc($text));
    }

    /** Texte avec retour à la ligne automatique ; renvoie la position Y finale. */
    public function paragraph(float $x, float $y, float $width, string $text, float $size = 8, string $color = '#333333', bool $center = false): float
    {
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($this->textWidth($try, $size) > $width && $line !== '') {
                $this->text($center ? $x + $width / 2 : $x, $y, $line, $size, false, $color, $center ? 'C' : 'L');
                $y += $size * 1.25;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $this->text($center ? $x + $width / 2 : $x, $y, $line, $size, false, $color, $center ? 'C' : 'L');
            $y += $size * 1.25;
        }
        return $y;
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = '#000000', float $lineWidth = 0.6, bool $dashed = false): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->current .= ($dashed ? "[3 2] 0 d\n" : "[] 0 d\n") . sprintf("%.2F w\n", $lineWidth);
        if ($fill) {
            $this->current .= self::hex($fill) . " rg\n";
        }
        if ($stroke) {
            $this->current .= self::hex($stroke) . " RG\n";
        }
        $this->current .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, $this->h - $y - $h, $w, $h, $op);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#999999', float $width = 0.5): void
    {
        $this->current .= sprintf("[] 0 d %.2F w %s RG %.2F %.2F m %.2F %.2F l S\n", $width, self::hex($color), $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    public function output(): string
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
            $this->current = '';
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $stream = function_exists('gzcompress') ? gzcompress($content) : $content;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objs[$n + 1] = "<< /Length " . strlen($stream) . "{$filter} >>\nstream\n{$stream}\nendstream";
            $objs[$n] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, $n + 1);
            $kids[] = "{$n} 0 R";
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    public function heightPt(): float
    {
        return $this->h;
    }

    public function widthPt(): float
    {
        return $this->w;
    }
}
