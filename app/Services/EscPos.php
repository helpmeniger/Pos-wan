<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Construction de documents ESC/POS (imprimantes thermiques Epson, Xprinter,
 * Rongta, Sunmi… compatibles ESC/POS). Le texte est converti en page de codes
 * PC850 (accents français) ; les écritures non latines (arabe) ne sont pas
 * prises en charge en mode texte : utiliser l'impression navigateur pour celles-ci.
 */
final class EscPos
{
    private string $buf = '';
    public readonly int $cols;

    public function __construct(string $paper = '80')
    {
        $this->cols = $paper === '58' ? 32 : 48;
        $this->buf .= "\x1B\x40";          // ESC @ : initialisation
        $this->buf .= "\x1B\x74\x02";      // ESC t 2 : page de codes PC850
    }

    public static function encode(string $text): string
    {
        $text = str_replace(["\u{202F}", "\u{00A0}", '’', '«', '»', '—', '…', '€', '₦', '₵'], [' ', ' ', "'", '"', '"', '-', '...', 'EUR', 'N', 'C'], $text);
        $out = @iconv('UTF-8', 'CP850//TRANSLIT', $text);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $out;
    }

    public function text(string $s): self
    {
        $this->buf .= self::encode($s);
        return $this;
    }

    public function line(string $s = ''): self
    {
        return $this->text($s)->raw("\n");
    }

    public function raw(string $bytes): self
    {
        $this->buf .= $bytes;
        return $this;
    }

    public function align(string $a): self
    {
        return $this->raw("\x1B\x61" . chr(['left' => 0, 'center' => 1, 'right' => 2][$a] ?? 0));
    }

    public function bold(bool $on = true): self
    {
        return $this->raw("\x1B\x45" . chr($on ? 1 : 0));
    }

    /** Taille : 1 = normale, 2 = double hauteur et largeur. */
    public function size(int $w = 1, int $h = 1): self
    {
        return $this->raw("\x1D\x21" . chr((($w - 1) << 4) | ($h - 1)));
    }

    public function sep(string $ch = '-'): self
    {
        return $this->line(str_repeat($ch, $this->cols));
    }

    /** Ligne à deux colonnes : libellé à gauche, montant à droite. */
    public function pair(string $left, string $right, int $cols = 0): self
    {
        $cols = $cols ?: $this->cols;
        $right = mb_substr($right, 0, $cols);
        $maxLeft = $cols - mb_strlen($right) - 1;
        $left = mb_strlen($left) > $maxLeft ? mb_substr($left, 0, max(1, $maxLeft)) : $left;
        return $this->line($left . str_repeat(' ', max(1, $cols - mb_strlen($left) - mb_strlen($right))) . $right);
    }

    /** Texte long coupé proprement sur plusieurs lignes. */
    public function wrap(string $s, int $cols = 0): self
    {
        $cols = $cols ?: $this->cols;
        foreach (explode("\n", wordwrap($s, $cols, "\n", true)) as $l) {
            $this->line($l);
        }
        return $this;
    }

    /** QR code natif (GS ( k) : modèle 2, correction M. */
    public function qr(string $data, int $module = 6): self
    {
        $len = strlen($data) + 3;
        $this->raw("\x1D\x28\x6B\x04\x00\x31\x41\x32\x00");                    // modèle 2
        $this->raw("\x1D\x28\x6B\x03\x00\x31\x43" . chr(max(1, min(16, $module)))); // taille du module
        $this->raw("\x1D\x28\x6B\x03\x00\x31\x45\x31");                        // correction M
        $this->raw("\x1D\x28\x6B" . chr($len % 256) . chr(intdiv($len, 256)) . "\x31\x50\x30" . $data);
        return $this->raw("\x1D\x28\x6B\x03\x00\x31\x51\x30");                 // impression
    }

    public function feed(int $n = 1): self
    {
        return $this->raw("\x1B\x64" . chr(max(0, min(10, $n))));
    }

    public function cut(): self
    {
        return $this->feed(3)->raw("\x1D\x56\x42\x00");
    }

    /** Impulsion d'ouverture du tiroir-caisse branché sur l'imprimante. */
    public function drawer(): self
    {
        return $this->raw("\x1B\x70\x00\x19\xFA");
    }

    public function bytes(): string
    {
        return $this->buf;
    }
}
