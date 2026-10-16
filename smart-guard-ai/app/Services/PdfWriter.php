<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Générateur PDF minimal (A4 paysage, Helvetica, encodage WinAnsi pour les accents français).
 * Suffisant pour des rapports tabulaires imprimables, sans bibliothèque externe.
 */
final class PdfWriter
{
    private array $pages = [];
    private string $cur = '';
    private float $y = 0;
    private const W = 842.0;
    private const H = 595.0;
    private const M = 36.0;
    private int $pageNo = 0;

    public function __construct()
    {
        $this->newPage();
    }

    private function newPage(): void
    {
        if ($this->cur !== '') {
            $this->pages[] = $this->cur;
        }
        $this->pageNo++;
        $this->cur = '';
        $this->y = self::H - self::M;
        $this->raw(sprintf('BT /F1 7 Tf %.2f %.2f Td (%s) Tj ET', self::M, 18, $this->esc('SMART GUARD AI — page ' . $this->pageNo . ' — généré le ' . date('d/m/Y H:i'))));
    }

    private function ensure(float $h): void
    {
        if ($this->y - $h < self::M) {
            $this->newPage();
        }
    }

    private function raw(string $s): void
    {
        $this->cur .= $s . "\n";
    }

    private function esc(string $s): string
    {
        $s = iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', str_replace(['—', '’', '…', ' ', ' '], ['-', "'", '...', ' ', ' '], $s)) ?: '';
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    /** Largeur approximative (Helvetica) pour la troncature. */
    private function width(string $s, float $size): float
    {
        return mb_strlen($s) * $size * 0.5;
    }

    private function fit(string $s, float $size, float $w): string
    {
        if ($this->width($s, $size) <= $w) {
            return $s;
        }
        $n = max(1, (int) floor($w / ($size * 0.5)) - 1);
        return mb_substr($s, 0, $n) . '…';
    }

    public function title(string $t): void
    {
        $this->ensure(30);
        $this->raw(sprintf('BT /F2 16 Tf %.2f %.2f Td (%s) Tj ET', self::M, $this->y - 16, $this->esc($t)));
        $this->y -= 26;
    }

    public function heading(string $t): void
    {
        $this->ensure(22);
        $this->raw(sprintf('BT /F2 11 Tf %.2f %.2f Td (%s) Tj ET', self::M, $this->y - 11, $this->esc($t)));
        $this->y -= 18;
    }

    public function text(string $t, float $size = 9): void
    {
        $maxChars = (int) ((self::W - 2 * self::M) / ($size * 0.5));
        foreach (explode("\n", wordwrap($t, $maxChars, "\n", true)) as $line) {
            $this->ensure($size + 4);
            $this->raw(sprintf('BT /F1 %.1f Tf %.2f %.2f Td (%s) Tj ET', $size, self::M, $this->y - $size, $this->esc($line)));
            $this->y -= $size + 4;
        }
    }

    public function space(float $h): void
    {
        $this->y -= $h;
    }

    public function table(array $headers, array $rows): void
    {
        $n = max(1, count($headers));
        $avail = self::W - 2 * self::M;
        // première colonne plus large
        $first = min(220.0, $avail * 0.3);
        $widths = $n > 1 ? array_merge([$first], array_fill(0, $n - 1, ($avail - $first) / ($n - 1))) : [$avail];
        $size = $n > 8 ? 7.0 : 8.0;
        $rowH = $size + 6;
        $drawHeader = function () use ($headers, $widths, $size, $rowH) {
            $this->ensure($rowH * 2);
            $this->raw(sprintf('0.9 0.92 0.96 rg %.2f %.2f %.2f %.2f re f 0 g', self::M, $this->y - $rowH, self::W - 2 * self::M, $rowH));
            $x = self::M;
            foreach ($headers as $i => $h) {
                $this->raw(sprintf('BT /F2 %.1f Tf %.2f %.2f Td (%s) Tj ET', $size, $x + 2, $this->y - $rowH + 4, $this->esc($this->fit((string) $h, $size, $widths[$i] - 4))));
                $x += $widths[$i];
            }
            $this->y -= $rowH;
        };
        $drawHeader();
        foreach ($rows as $ri => $row) {
            if ($this->y - $rowH < self::M) {
                $this->newPage();
                $drawHeader();
            }
            if ($ri % 2 === 1) {
                $this->raw(sprintf('0.97 0.97 0.97 rg %.2f %.2f %.2f %.2f re f 0 g', self::M, $this->y - $rowH, self::W - 2 * self::M, $rowH));
            }
            $x = self::M;
            foreach (array_values($row) as $i => $v) {
                if (!isset($widths[$i])) {
                    break;
                }
                $this->raw(sprintf('BT /F1 %.1f Tf %.2f %.2f Td (%s) Tj ET', $size, $x + 2, $this->y - $rowH + 4, $this->esc($this->fit((string) ($v ?? ''), $size, $widths[$i] - 4))));
                $x += $widths[$i];
            }
            $this->y -= $rowH;
        }
        $this->y -= 4;
    }

    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->cur;
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $next = 5;
        foreach ($pages as $content) {
            $pageId = $next++;
            $contId = $next++;
            $kids[] = $pageId . ' 0 R';
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0f %.0f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $contId);
            $objs[$contId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $out;
    }
}
