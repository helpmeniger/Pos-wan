<?php
declare(strict_types=1);

namespace Verifia\Analysis;

/** SimHash 64 bits sur des trigrammes de mots + distance de Hamming (entiers PHP signés 64 bits). */
final class SimHash
{
    public static function hash(string $text): int
    {
        $v = array_fill(0, 64, 0);
        $shingles = TextNormalizer::shingles($text, 3);
        if (!$shingles) {
            return 0;
        }
        foreach (array_keys($shingles) as $sh) {
            $h = self::hash64((string) $sh);
            for ($i = 0; $i < 64; $i++) {
                $v[$i] += (($h >> $i) & 1) ? 1 : -1;
            }
        }
        $out = 0;
        for ($i = 0; $i < 64; $i++) {
            if ($v[$i] > 0) {
                $out |= (1 << $i);
            }
        }
        return $out;
    }

    public static function hash64(string $s): int
    {
        $b = substr(hash('sha256', $s, true), 0, 8);
        return unpack('J', $b)[1];
    }

    public static function hamming(int $a, int $b): int
    {
        $x = $a ^ $b;
        $count = 0;
        if ($x < 0) {
            $count++;
            $x &= PHP_INT_MAX;
        }
        while ($x !== 0) {
            $x &= $x - 1;
            $count++;
        }
        return $count;
    }
}
