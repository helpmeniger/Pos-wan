<?php
/**
 * Générateur PDF minimal (PDF 1.4) sans dépendance : texte Helvetica
 * (encodage WinAnsi, accents français), traits, rectangles, polygones.
 * Suffisant pour les fiches et rapports ; évite toute installation Composer.
 */
declare(strict_types=1);

final class SimplePdf
{
    public const W = 595.28; // A4 portrait en points
    public const H = 841.89;

    private array $pages = [];
    private string $buf = '';
    private array $meta;
    private int $pageNo = 0;
    /** @var callable|null */
    private $footer = null;

    private const HELV = [32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015,
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611,
        278, 278, 278, 469, 556, 333,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500,
        334, 260, 334, 584];

    public function __construct(string $title = '', string $author = 'Niger Parcelles')
    {
        $this->meta = ['title' => $title, 'author' => $author];
    }

    /** Fonction appelée en bas de chaque page : fn(SimplePdf $pdf, int $page). */
    public function setFooter(callable $fn): void
    {
        $this->footer = $fn;
    }

    public function addPage(): void
    {
        $this->closePage();
        $this->pageNo++;
        $this->buf = '';
    }

    public function pageNumber(): int
    {
        return $this->pageNo;
    }

    private function closePage(): void
    {
        if ($this->pageNo > count($this->pages)) {
            if ($this->footer) {
                ($this->footer)($this, $this->pageNo);
            }
            $this->pages[] = $this->buf;
        }
    }

    /** Conversion UTF-8 -> WinAnsi (CP1252) et échappement PDF. */
    private static function enc(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{00A0}", '’', '‘', '“', '”', '…', '–', '—', '›', '→'], [' ', ' ', "'", "'", '"', '"', '...', '-', '-', '>', '->'], $s);
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        if ($c === false) {
            $c = preg_replace('/[^\x20-\x7E]/', '?', $s);
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], (string) $c);
    }

    public static function textWidth(string $s, float $size, bool $bold = false): float
    {
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s) ?: $s;
        $w = 0;
        for ($i = 0, $n = strlen($c); $i < $n; $i++) {
            $o = ord($c[$i]);
            $w += self::HELV[$o] ?? ($o >= 192 && $o <= 223 ? 667 : 556);
        }
        return $w * $size / 1000 * ($bold ? 1.06 : 1.0);
    }

    private static function color(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    /** Texte en (x, y) mesurés depuis le coin HAUT gauche. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, array $rgb = [30, 37, 44], string $align = 'L'): void
    {
        if ($align !== 'L') {
            $w = self::textWidth($s, $size, $bold);
            $x -= $align === 'R' ? $w : $w / 2;
        }
        $this->buf .= sprintf("BT %s rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", self::color($rgb), $bold ? 'F2' : 'F1', $size, $x, self::H - $y, self::enc($s));
    }

    /** Découpe un texte en lignes tenant dans $width. */
    public static function wrap(string $s, float $width, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $s) as $para) {
            $words = preg_split('/\s+/u', trim($para)) ?: [];
            $line = '';
            foreach ($words as $w) {
                $try = $line === '' ? $w : $line . ' ' . $w;
                if (self::textWidth($try, $size, $bold) <= $width || $line === '') {
                    $line = $try;
                } else {
                    $lines[] = $line;
                    $line = $w;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Paragraphe multi-lignes ; retourne la nouvelle ordonnée. */
    public function paragraph(float $x, float $y, float $width, string $s, float $size = 10, bool $bold = false, array $rgb = [30, 37, 44], float $leading = 1.35): float
    {
        foreach (self::wrap($s, $width, $size, $bold) as $l) {
            $this->text($x, $y, $l, $size, $bold, $rgb);
            $y += $size * $leading;
        }
        return $y;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [180, 186, 193], float $w = 0.6): void
    {
        $this->buf .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::color($rgb), $w, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lw = 0.6): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->buf .= ($fill ? self::color($fill) . ' rg ' : '') . ($stroke ? self::color($stroke) . ' RG ' : '')
            . sprintf("%.2F w %.2F %.2F %.2F %.2F re %s\n", $lw, $x, self::H - $y - $h, $w, $h, $op);
    }

    /** Polygone : points [[x,y],...] en coordonnées page (haut gauche). */
    public function polygon(array $pts, ?array $fill, array $stroke, float $lw = 1.2, bool $close = true): void
    {
        if (count($pts) < 2) {
            return;
        }
        $s = ($fill ? self::color($fill) . ' rg ' : '') . self::color($stroke) . sprintf(' RG %.2F w ', $lw);
        foreach ($pts as $i => $p) {
            $s .= sprintf('%.2F %.2F %s ', $p[0], self::H - $p[1], $i === 0 ? 'm' : 'l');
        }
        $s .= $close ? ($fill ? 'b' : 's') : 'S';
        $this->buf .= $s . "\n";
    }

    public function circle(float $cx, float $cy, float $r, array $fill): void
    {
        $k = 0.5523 * $r;
        $y = self::H - $cy;
        $this->buf .= self::color($fill) . sprintf(" rg %.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c f\n",
            $cx + $r, $y, $cx + $r, $y + $k, $cx + $k, $y + $r, $cx, $y + $r, $cx - $k, $y + $r, $cx - $r, $y + $k, $cx - $r, $y,
            $cx - $r, $y - $k, $cx - $k, $y - $r, $cx, $y - $r, $cx + $k, $y - $r, $cx + $r, $y - $k, $cx + $r, $y);
    }

    /** Assemble le document et retourne son contenu binaire. */
    public function output(): string
    {
        $this->closePage();
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = "$pageId 0 R";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contentId);
            $z = function_exists('gzcompress') ? gzcompress($content) : null;
            $objs[$contentId] = $z !== null
                ? '<< /Length ' . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream"
                : '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $infoId = $n;
        $objs[$infoId] = '<< /Title (' . self::enc($this->meta['title']) . ') /Author (' . self::enc($this->meta['author']) . ') /Producer (Niger Parcelles) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
