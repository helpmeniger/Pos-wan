<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Générateur PDF minimal, sans bibliothèque externe (fonctionne hors ligne sur WampServer).
 * Polices standard Helvetica / Helvetica-Bold (encodage WinAnsi : accents français pris en
 * charge ; les écritures non latines comme l'arabe ne le sont pas — utiliser alors
 * « Imprimer → Enregistrer au format PDF » du navigateur).
 * Usage principal : Pdf::table() pour les rapports et exports (en-tête, pied de page
 * « Page x / n », répétition des titres de colonnes à chaque page).
 */
final class Pdf
{
    private const W_REGULAR = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const W_BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    private float $w;
    private float $h;
    /** @var string[] */
    private array $pages = [];
    private string $cur = '';

    public function __construct(bool $landscape = false)
    {
        [$this->w, $this->h] = $landscape ? [841.89, 595.28] : [595.28, 841.89];
    }

    public function width(): float
    {
        return $this->w;
    }

    public function height(): float
    {
        return $this->h;
    }

    public function addPage(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->cur = "\n";
    }

    /** Texte UTF-8 → WinAnsi (cp1252) ; caractères non représentables remplacés. */
    public static function encode(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{00A0}", "\u{2066}", "\u{2069}", '−'], [' ', ' ', '', '', '-'], $s);
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $out === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    /** Largeur d'un texte (déjà encodé cp1252) en points. */
    public static function textWidth(string $enc, float $size, bool $bold = false): float
    {
        $t = $bold ? self::W_BOLD : self::W_REGULAR;
        $sum = 0;
        $len = strlen($enc);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($enc[$i]);
            $sum += $c >= 32 && $c <= 126 ? $t[$c - 32] : ($c >= 192 ? 600 : 556);
        }
        return $sum * $size / 1000;
    }

    private static function esc(string $enc): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $enc);
    }

    /** Texte tronqué (…) pour tenir dans $maxWidth. */
    public static function fit(string $enc, float $size, bool $bold, float $maxWidth): string
    {
        if (self::textWidth($enc, $size, $bold) <= $maxWidth) {
            return $enc;
        }
        while ($enc !== '' && self::textWidth($enc . "\x85", $size, $bold) > $maxWidth) {
            $enc = substr($enc, 0, -1);
        }
        return $enc . "\x85";
    }

    /** Écrit un texte. $y = position de la ligne de base depuis le HAUT de la page. */
    public function text(float $x, float $y, string $utf8, float $size = 9, bool $bold = false, string $align = 'left', float $width = 0, array $rgb = [0, 0, 0]): void
    {
        $enc = self::encode($utf8);
        if ($width > 0) {
            $enc = self::fit($enc, $size, $bold, $width);
            $tw = self::textWidth($enc, $size, $bold);
            if ($align === 'right') {
                $x += $width - $tw;
            } elseif ($align === 'center') {
                $x += ($width - $tw) / 2;
            }
        }
        $this->cur .= sprintf("BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $this->h - $y, self::esc($enc));
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $this->h - $y - $h, $w, $h);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.75, 0.75, 0.75]): void
    {
        $this->cur .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $rgb[0], $rgb[1], $rgb[2], $width, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    /** Document PDF complet (le marqueur {nb} est remplacé par le nombre de pages). */
    public function output(): string
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
            $this->cur = '';
        }
        if (!$this->pages) {
            $this->pages[] = "\n";
        }
        $n = count($this->pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $first = 5;
        foreach ($this->pages as $i => $content) {
            $pageId = $first + $i * 2;
            $kids[] = "$pageId 0 R";
            $content = str_replace('{nb}', (string) $n, $content);
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, $pageId + 1);
            $objs[$pageId + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= 'xref' . "\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info << /Producer (POS Wan) /Creator (POS Wan) >> >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }

    /**
     * Tableau paginé.
     * @param string[] $headers
     * @param array<int, array> $rows lignes (valeurs scalaires) ; une ligne avec la clé '_bold' => true est en gras
     * @param array{subtitle?:string, numeric?:bool[], footer?:array, landscape?:bool} $opts
     */
    public static function table(string $title, array $headers, iterable $rows, array $opts = []): string
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
        $cols = count($headers);
        $pdf = new self($opts['landscape'] ?? $cols > 6);
        $margin = 32.0;
        $usable = $pdf->w - 2 * $margin;
        $size = $cols > 9 ? 7.0 : 8.0;
        $lineH = $size + 6;
        $numeric = $opts['numeric'] ?? self::detectNumeric($headers, $rows);
        $cell = static fn($v, int $c): string => self::formatCell($v, $numeric[$c] ?? false);

        // Largeurs : selon le contenu (plafonné), puis ajustées à la largeur utile
        $widths = [];
        foreach ($headers as $c => $h) {
            $max = self::textWidth(self::encode((string) $h), $size, true);
            foreach (array_slice($rows, 0, 300) as $r) {
                $vals = array_values(array_diff_key($r, ['_bold' => 1]));
                $max = max($max, self::textWidth(self::encode($cell($vals[$c] ?? '', $c)), $size));
            }
            $widths[$c] = min(max($max + 8, 30), 260);
        }
        $scale = $usable / array_sum($widths);
        $widths = array_map(fn($w) => $w * $scale, $widths);

        $company = (string) setting('company_name', 'POS Wan');
        $printed = date('d/m/Y H:i');
        $y = 0.0;
        $page = static function () use ($pdf, $margin, $usable, $title, $opts, $company, $printed, $headers, $widths, $size, $lineH, $numeric, &$y): void {
            $pdf->addPage();
            $pdf->text($margin, 36, $company, 9, true, 'left', $usable / 2, [0.08, 0.13, 0.24]);
            $pdf->text($margin + $usable / 2, 36, $printed, 8, false, 'right', $usable / 2, [0.4, 0.4, 0.4]);
            $pdf->text($margin, 58, $title, 15, true, 'left', $usable, [0.08, 0.13, 0.24]);
            $y = 58;
            if (!empty($opts['subtitle'])) {
                $pdf->text($margin, 74, (string) $opts['subtitle'], 9, false, 'left', $usable, [0.35, 0.35, 0.35]);
                $y = 74;
            }
            $y += 12;
            $pdf->rect($margin, $y, $usable, $lineH + 2, [0.08, 0.13, 0.24]);
            $x = $margin;
            foreach ($headers as $c => $h) {
                $pdf->text($x + 3, $y + $lineH - 3, (string) $h, $size, true, ($numeric[$c] ?? false) ? 'right' : 'left', $widths[$c] - 6, [1, 1, 1]);
                $x += $widths[$c];
            }
            $y += $lineH + 2;
            $pdf->text($margin, $pdf->h - 20, 'POS Wan', 7, false, 'left', 100, [0.5, 0.5, 0.5]);
            $pdf->text($margin, $pdf->h - 20, 'Page ' . (count($pdf->pages) + 1) . ' / {nb}', 7, false, 'right', $usable, [0.5, 0.5, 0.5]);
        };
        $page();
        $all = $rows;
        if (!empty($opts['footer'])) {
            $all[] = $opts['footer'] + ['_bold' => true];
        }
        if (!$all) {
            $pdf->text($margin, $y + $lineH, '—', $size);
        }
        foreach ($all as $i => $r) {
            if ($y + $lineH > $pdf->h - 40) {
                $page();
            }
            $bold = !empty($r['_bold']);
            $vals = array_values(array_diff_key($r, ['_bold' => 1]));
            if ($bold) {
                $pdf->rect($margin, $y, $usable, $lineH, [0.93, 0.93, 0.93]);
            } elseif ($i % 2 === 1) {
                $pdf->rect($margin, $y, $usable, $lineH, [0.97, 0.97, 0.98]);
            }
            $x = $margin;
            foreach ($headers as $c => $h) {
                $pdf->text($x + 3, $y + $lineH - 4, $cell($vals[$c] ?? '', $c), $size, $bold, ($numeric[$c] ?? false) ? 'right' : 'left', $widths[$c] - 6);
                $x += $widths[$c];
            }
            $y += $lineH;
            $pdf->line($margin, $y, $margin + $usable, $y, 0.3);
        }
        return $pdf->output();
    }

    /** Colonnes numériques : valeurs nombres (hors références, téléphones, codes, n°). */
    private static function detectNumeric(array $headers, array $rows): array
    {
        $res = [];
        foreach ($headers as $c => $h) {
            if (preg_match('/t[ée]l[ée]phone|r[ée]f|code|n°|num[ée]ro|lot|date|mois|jour/iu', (string) $h)) {
                $res[$c] = false;
                continue;
            }
            $num = 0;
            $total = 0;
            foreach (array_slice($rows, 0, 200) as $r) {
                $vals = array_values(array_diff_key($r, ['_bold' => 1]));
                $v = $vals[$c] ?? '';
                if ($v === '' || $v === null) {
                    continue;
                }
                $total++;
                if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,12}(\.\d+)?$/', $v))) {
                    $num++;
                }
            }
            $res[$c] = $total > 0 && $num === $total;
        }
        return $res;
    }

    private static function formatCell(mixed $v, bool $numeric): string
    {
        if ($v === null) {
            return '';
        }
        if ($numeric && is_numeric($v)) {
            $f = (float) $v;
            $dec = abs($f - round($f)) > 0.0001 ? 2 : 0;
            return number_format($f, $dec, ',', ' ');
        }
        return (string) $v;
    }
}
