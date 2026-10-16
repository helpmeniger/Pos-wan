<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Montant en francs CFA (XOF), manipulé en ENTIER de francs : jamais de float.
 * Les pourcentages sont exprimés en points de base (10 % = 1000 pb) pour un calcul exact.
 */
final class Money
{
    private function __construct(private readonly int $amount, private readonly string $currency = 'XOF')
    {
    }

    public static function xof(int $francs): self
    {
        return new self($francs);
    }

    /** Depuis une valeur DECIMAL de la base ("10000.00") ou une saisie ("10 000"). */
    public static function parse(string|int $value): self
    {
        if (is_int($value)) {
            return new self($value);
        }
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($value));
        if (!preg_match('/^-?\d+(?:[.,](\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("Montant invalide : {$value}");
        }
        $decimals = $m[1] ?? '';
        if ($decimals !== '' && (int) $decimals !== 0) {
            throw new InvalidArgumentException('Le franc CFA ne comporte pas de centimes.');
        }
        return new self((int) preg_replace('/[.,]\d+$/', '', $clean));
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSame($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSame($other);
        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int|string $factor): self
    {
        // Facteur décimal (ex : « 2.5 » jours, 3 décimales max), arrondi demi-supérieur au franc
        if (is_int($factor)) {
            return new self($this->amount * $factor, $this->currency);
        }
        if (!preg_match('/^\d+(?:\.(\d{1,3}))?$/', $factor, $m)) {
            throw new InvalidArgumentException("Facteur invalide : {$factor}");
        }
        $scale = 10 ** strlen($m[1] ?? '');
        $scaled = (int) str_replace('.', '', $factor);
        return new self(self::roundHalfUp($this->amount * $scaled, $scale), $this->currency);
    }

    /** Applique un pourcentage exprimé en points de base (1000 = 10 %), arrondi demi-supérieur au franc. */
    public function percentBps(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException('Pourcentage négatif');
        }
        return new self(self::roundHalfUp($this->amount * $basisPoints, 10000), $this->currency);
    }

    /** "10.00" (DECIMAL(5,2) en base) → 1000 points de base */
    public static function rateToBps(string|int|float $rate): int
    {
        $s = is_string($rate) ? $rate : number_format((float) $rate, 2, '.', '');
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $s, $m)) {
            throw new InvalidArgumentException("Taux invalide : {$s}");
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSame($other);
        return $this->amount > $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    /** Pour l'enregistrement en DECIMAL(15,2). */
    public function toDecimal(): string
    {
        return $this->amount . '.00';
    }

    /** 10000 → « 10 000 FCFA » (espace insécable fine comme séparateur de milliers). */
    public function format(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        return $sign . number_format(abs($this->amount), 0, ',', "\u{202F}") . "\u{00A0}FCFA";
    }

    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        $q = intdiv($numerator, $denominator);
        $r = $numerator % $denominator;
        if ($r * 2 >= $denominator) {
            $q++;
        } elseif ($r * 2 <= -$denominator) {
            $q--;
        }
        return $q;
    }

    private function assertSame(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Devises différentes');
        }
    }
}
