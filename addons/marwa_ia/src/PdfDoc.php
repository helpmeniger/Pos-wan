<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Services\Pdf;

/**
 * Document PDF rédigé (guides, plans) : titres, paragraphes avec retour à la ligne, listes,
 * encadrés, tableaux simples, saut de page automatique, en-tête et pied de page.
 * S'appuie sur le générateur PDF interne (aucune bibliothèque externe, fonctionne hors ligne).
 */
final class PdfDoc
{
    public const NAVY = [0.078, 0.129, 0.239];
    public const VIOLET = [0.435, 0.259, 0.757];
    public const ORANGE = [0.761, 0.255, 0.047];
    public const GREY = [0.42, 0.45, 0.53];
    public const SOFT = [0.957, 0.949, 1.0];
    public const SAND = [1.0, 0.957, 0.902];

    private Pdf $pdf;
    private float $y = 0;
    private int $page = 0;
    private const M = 50.0;

    public function __construct(private string $header, private string $footer)
    {
        $this->pdf = new Pdf();
    }

    private function w(): float
    {
        return $this->pdf->width() - 2 * self::M;
    }

    private function bottom(): float
    {
        return $this->pdf->height() - 60;
    }

    public function newPage(bool $chrome = true): void
    {
        $this->pdf->addPage();
        $this->page++;
        $this->y = 60;
        if ($chrome) {
            $this->pdf->rect(0, 0, $this->pdf->width(), 6, self::VIOLET);
            $this->pdf->text(self::M, 30, $this->header, 8, true, 'left', $this->w() - 60, self::GREY);
            $this->pdf->text(self::M, 30, 'Page ' . $this->page . ' / {nb}', 8, false, 'right', $this->w(), self::GREY);
            $this->pdf->line(self::M, 38, self::M + $this->w(), 38, 0.5, [0.85, 0.85, 0.9]);
            $this->pdf->text(self::M, $this->pdf->height() - 28, $this->footer, 7.5, false, 'center', $this->w(), self::GREY);
        }
    }

    private function need(float $h): void
    {
        if ($this->y + $h > $this->bottom()) {
            $this->newPage();
        }
    }

    /** Coupe un texte (UTF-8) en lignes tenant dans $width. */
    public static function wrap(string $text, float $size, bool $bold, float $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && Pdf::textWidth(Pdf::encode($try), $size, $bold) > $width) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function cover(string $kicker, string $title, string $subtitle, array $lines, ?array $quote = null, array $toc = []): void
    {
        $this->newPage(false);
        $W = $this->pdf->width();
        $this->pdf->rect(0, 0, $W, 330, self::NAVY);
        $this->pdf->rect(0, 330, $W, 8, self::ORANGE);
        $this->pdf->text(self::M, 90, mb_strtoupper($kicker), 11, true, 'left', 0, [0.96, 0.76, 0.42]);
        $y = 140;
        foreach (self::wrap($title, 30, true, $this->w()) as $l) {
            $this->pdf->text(self::M, $y, $l, 30, true, 'left', 0, [1, 1, 1]);
            $y += 36;
        }
        foreach (self::wrap($subtitle, 13, false, $this->w()) as $l) {
            $this->pdf->text(self::M, $y + 8, $l, 13, false, 'left', 0, [0.85, 0.87, 0.95]);
            $y += 18;
        }
        $y = 390;
        foreach ($lines as [$label, $value]) {
            $this->pdf->text(self::M, $y, $label, 9, true, 'left', 0, self::GREY);
            foreach (self::wrap($value, 11, false, $this->w() - 140) as $i => $l) {
                $y += $i > 0 ? 15 : 0;
                $this->pdf->text(self::M + 140, $y, $l, 11, false, 'left', 0, self::NAVY);
            }
            $y += 22;
        }
        if ($quote) {
            $y += 30;
            $this->pdf->rect(self::M, $y - 4, 4, 70, self::ORANGE);
            $yy = $y + 12;
            foreach (self::wrap('« ' . $quote[0] . ' »', 12, false, $this->w() - 30) as $l) {
                $this->pdf->text(self::M + 18, $yy, $l, 12, false, 'left', 0, [0.35, 0.18, 0.05]);
                $yy += 16;
            }
            $this->pdf->text(self::M + 18, $yy + 4, mb_strtoupper($quote[1]), 8.5, true, 'left', 0, self::ORANGE);
            $y = max($y + 80, $yy + 30);
        }
        if ($toc) {
            $y += 10;
            $this->pdf->text(self::M, $y, 'SOMMAIRE', 9, true, 'left', 0, self::VIOLET);
            $y += 18;
            $half = (int) ceil(count($toc) / 2);
            foreach (array_values($toc) as $i => $t) {
                $col = $i < $half ? 0 : 1;
                $row = $col ? $i - $half : $i;
                $x = self::M + $col * ($this->w() / 2);
                $this->pdf->text($x, $y + $row * 17, ($i + 1) . '.', 9.5, true, 'left', 0, self::VIOLET);
                $this->pdf->text($x + 16, $y + $row * 17, $t, 9.5, false, 'left', $this->w() / 2 - 24, self::NAVY);
            }
        }
        $this->pdf->text(self::M, $this->pdf->height() - 40, $this->footer, 8, false, 'center', $this->w(), self::GREY);
        $this->newPage();
    }

    public function h1(string $t, string $num = ''): void
    {
        $this->need(70);
        $this->y += 10;
        if ($num !== '') {
            $this->pdf->rect(self::M, $this->y - 15, 26, 22, self::VIOLET);
            $this->pdf->text(self::M, $this->y, $num, 12, true, 'center', 26, [1, 1, 1]);
        }
        $x = self::M + ($num !== '' ? 36 : 0);
        foreach (self::wrap($t, 17, true, $this->w() - 36) as $l) {
            $this->pdf->text($x, $this->y, $l, 17, true, 'left', 0, self::NAVY);
            $this->y += 22;
        }
        $this->pdf->line(self::M, $this->y - 12, self::M + $this->w(), $this->y - 12, 0.8, [0.85, 0.82, 0.96]);
        $this->y += 4;
    }

    public function h2(string $t): void
    {
        $this->need(40);
        $this->y += 6;
        $this->pdf->text(self::M, $this->y, $t, 12, true, 'left', $this->w(), self::VIOLET);
        $this->y += 16;
    }

    public function p(string $t, float $size = 10, array $rgb = [0.15, 0.17, 0.22], bool $bold = false): void
    {
        foreach (self::wrap($t, $size, $bold, $this->w()) as $l) {
            $this->need($size + 5);
            $this->pdf->text(self::M, $this->y, $l, $size, $bold, 'left', 0, $rgb);
            $this->y += $size + 4.5;
        }
        $this->y += 4;
    }

    /** Liste à puces ; un élément « Titre : texte » met le titre en gras. */
    public function bullets(array $items, bool $numbered = false): void
    {
        foreach (array_values($items) as $i => $it) {
            $it = strip_tags(html_entity_decode((string) $it, ENT_QUOTES, 'UTF-8'));
            $lines = self::wrap($it, 10, false, $this->w() - 18);
            $this->need(14.5 * min(3, count($lines)));
            if ($numbered) {
                $this->pdf->text(self::M, $this->y, ($i + 1) . '.', 10, true, 'left', 0, self::VIOLET);
            } else {
                $this->pdf->rect(self::M + 3, $this->y - 6, 4, 4, self::VIOLET);
            }
            foreach ($lines as $l) {
                $this->need(14.5);
                $this->pdf->text(self::M + 18, $this->y, $l, 10, false, 'left', 0, [0.15, 0.17, 0.22]);
                $this->y += 14.5;
            }
            $this->y += 2.5;
        }
        $this->y += 4;
    }

    /** Encadré coloré (conseil, exemple, attention). */
    public function box(string $title, string $text, array $bg = self::SOFT, array $accent = self::VIOLET): void
    {
        $lines = self::wrap(strip_tags($text), 9.5, false, $this->w() - 28);
        $h = 26 + 13.5 * count($lines);
        $this->need($h + 8);
        $this->pdf->rect(self::M, $this->y - 4, $this->w(), $h, $bg);
        $this->pdf->rect(self::M, $this->y - 4, 4, $h, $accent);
        $this->pdf->text(self::M + 14, $this->y + 10, $title, 10, true, 'left', $this->w() - 28, $accent);
        $yy = $this->y + 25;
        foreach ($lines as $l) {
            $this->pdf->text(self::M + 14, $yy, $l, 9.5, false, 'left', 0, [0.2, 0.2, 0.25]);
            $yy += 13.5;
        }
        $this->y += $h + 10;
    }

    /** Tableau simple ; $widths en proportions. */
    public function table(array $headers, array $rows, array $widths): void
    {
        $W = $this->w();
        $sum = array_sum($widths);
        $cols = array_map(fn($x) => $x / $sum * $W, $widths);
        $head = function () use ($headers, $cols) {
            $this->need(40);
            $this->pdf->rect(self::M, $this->y - 11, $this->w(), 17, self::NAVY);
            $x = self::M;
            foreach ($headers as $i => $h) {
                $this->pdf->text($x + 5, $this->y + 1, $h, 8.5, true, 'left', $cols[$i] - 10, [1, 1, 1]);
                $x += $cols[$i];
            }
            $this->y += 14;
        };
        $head();
        foreach ($rows as $r => $row) {
            $cells = [];
            $n = 1;
            foreach (array_values($row) as $i => $c) {
                $cells[$i] = self::wrap(strip_tags((string) $c), 8.5, $i === 0, $cols[$i] - 10);
                $n = max($n, count($cells[$i]));
            }
            $h = 6 + 11 * $n;
            if ($this->y + $h > $this->bottom()) {
                $this->newPage();
                $head();
            }
            if ($r % 2 === 1) {
                $this->pdf->rect(self::M, $this->y - 8, $W, $h, [0.97, 0.97, 0.99]);
            }
            $x = self::M;
            foreach ($cells as $i => $lines) {
                $yy = $this->y + 2;
                foreach ($lines as $l) {
                    $this->pdf->text($x + 5, $yy, $l, 8.5, $i === 0, 'left', 0, [0.15, 0.17, 0.22]);
                    $yy += 11;
                }
                $x += $cols[$i];
            }
            $this->y += $h;
            $this->pdf->line(self::M, $this->y - 8, self::M + $W, $this->y - 8, 0.3, [0.88, 0.88, 0.92]);
        }
        $this->y += 10;
    }

    /** Chiffres clés en tuiles. */
    public function kpis(array $items): void
    {
        if (!$items) {
            return;
        }
        $n = count($items);
        $gap = 8;
        $w = ($this->w() - $gap * ($n - 1)) / $n;
        $this->need(58);
        foreach (array_values($items) as $i => [$label, $value]) {
            $x = self::M + $i * ($w + $gap);
            $this->pdf->rect($x, $this->y - 6, $w, 48, self::SOFT);
            $this->pdf->text($x + 8, $this->y + 8, mb_strtoupper($label), 7, true, 'left', $w - 16, self::GREY);
            $this->pdf->text($x + 8, $this->y + 28, $value, 13, true, 'left', $w - 16, self::NAVY);
        }
        $this->y += 56;
    }

    public function space(float $h = 8): void
    {
        $this->y += $h;
    }

    public function output(): string
    {
        return $this->pdf->output();
    }
}
