<?php
/**
 * Générateur de QR codes (ISO/IEC 18004) en PHP pur, sans dépendance.
 * Mode octet, correction d'erreur niveau M (≈ 15 % de la surface lisible
 * même abîmée), versions 1 à 10 (jusqu'à 213 octets : largement assez pour
 * une adresse de vérification).
 *
 * Usage : $m = QrCode::matrix('https://…'); // tableau [ligne][colonne] de booléens
 *         QrCode::svg('https://…');        // image SVG
 */
declare(strict_types=1);

final class QrCode
{
    /** version => [codes de correction par bloc, [[nb blocs, octets de données], …]] (niveau M) */
    private const BLOCKS = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]], 6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]], 10 => [26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34],
        7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];
    private const REMAINDER = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];

    private static array $exp = [];
    private static array $log = [];

    /** @return bool[][] modules (true = noir), sans marge */
    public static function matrix(string $data): array
    {
        $bytes = array_values(unpack('C*', $data) ?: []);
        $version = 0;
        foreach (self::BLOCKS as $v => [$ecc, $groups]) {
            $capacity = 0;
            foreach ($groups as [$n, $k]) {
                $capacity += $n * $k;
            }
            $header = 4 + ($v < 10 ? 8 : 16);
            if ($header + 8 * count($bytes) <= 8 * $capacity) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new InvalidArgumentException('Données trop longues pour le QR code (213 octets maximum).');
        }
        $codewords = self::codewords($bytes, $version);
        $size = 17 + 4 * $version;

        $best = null;
        for ($mask = 0; $mask < 8; $mask++) {
            [$m, $fn] = self::baseMatrix($version);
            self::placeData($m, $fn, $codewords, $version, $mask);
            self::placeFormat($m, $mask);
            if ($version >= 7) {
                self::placeVersion($m, $version);
            }
            $score = self::penalty($m, $size);
            if ($best === null || $score < $best[0]) {
                $best = [$score, $m];
            }
        }
        return array_map(static fn ($row) => array_map(static fn ($c) => $c === 1, $row), $best[1]);
    }

    /** Image SVG (modules carrés, marge de 4 modules). */
    public static function svg(string $data, int $px = 4, string $color = '#000000'): string
    {
        $m = self::matrix($data);
        $n = count($m);
        $q = 4;
        $path = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $on) {
                if ($on) {
                    $path .= 'M' . ($x + $q) . ' ' . ($y + $q) . 'h1v1h-1z';
                }
            }
        }
        $dim = ($n + 2 * $q) * $px;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" viewBox="0 0 ' . ($n + 2 * $q) . ' ' . ($n + 2 * $q)
            . '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#fff"/><path fill="'
            . htmlspecialchars($color, ENT_QUOTES) . '" d="' . $path . '"/></svg>';
    }

    // ------------------------------------------------------------------ données
    private static function codewords(array $bytes, int $version): array
    {
        [$eccLen, $groups] = self::BLOCKS[$version];
        $dataLen = 0;
        foreach ($groups as [$n, $k]) {
            $dataLen += $n * $k;
        }
        $bits = '0100' . str_pad(decbin(count($bytes)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, 8 * $dataLen - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($data) < $dataLen; $pad++) {
            $data[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }
        // Découpage en blocs, correction Reed-Solomon, entrelacement
        $blocks = [];
        $offset = 0;
        foreach ($groups as [$n, $k]) {
            for ($i = 0; $i < $n; $i++) {
                $d = array_slice($data, $offset, $k);
                $offset += $k;
                $blocks[] = [$d, self::reedSolomon($d, $eccLen)];
            }
        }
        $out = [];
        $maxData = max(array_map(static fn ($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[0][$i])) {
                    $out[] = $b[0][$i];
                }
            }
        }
        for ($i = 0; $i < $eccLen; $i++) {
            foreach ($blocks as $b) {
                $out[] = $b[1][$i];
            }
        }
        return $out;
    }

    private static function gfInit(): void
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    private static function reedSolomon(array $data, int $n): array
    {
        self::gfInit();
        $gen = [1];
        for ($i = 0; $i < $n; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $g) {
                $next[$j] ^= $g;
                $next[$j + 1] ^= self::gfMul($g, self::$exp[$i]);
            }
            $gen = $next;
        }
        $res = array_merge($data, array_fill(0, $n, 0));
        for ($i = 0, $len = count($data); $i < $len; $i++) {
            $coef = $res[$i];
            if ($coef !== 0) {
                foreach ($gen as $j => $g) {
                    $res[$i + $j] ^= self::gfMul($g, $coef);
                }
            }
        }
        return array_slice($res, count($data));
    }

    // ------------------------------------------------------------------ matrice
    /** @return array{0: int[][], 1: bool[][]} matrice (0/1/-1 non défini) et modules de fonction */
    private static function baseMatrix(int $version): array
    {
        $size = 17 + 4 * $version;
        $m = array_fill(0, $size, array_fill(0, $size, 0));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = static function (int $x, int $y, int $v) use (&$m, &$fn, $size): void {
            if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                $m[$y][$x] = $v;
                $fn[$y][$x] = true;
            }
        };
        // Motifs de repérage + séparateurs
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fx, $fy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $on = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                        && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                    $set($fx + $dx, $fy + $dy, $on ? 1 : 0);
                }
            }
        }
        // Lignes de synchronisation
        for ($i = 8; $i < $size - 8; $i++) {
            $set($i, 6, $i % 2 === 0 ? 1 : 0);
            $set(6, $i, $i % 2 === 0 ? 1 : 0);
        }
        // Motifs d'alignement
        $pos = self::ALIGN[$version];
        $last = count($pos) - 1;
        foreach ($pos as $iy => $ay) {
            foreach ($pos as $ix => $ax) {
                if (($ix === 0 && $iy === 0) || ($ix === 0 && $iy === $last) || ($ix === $last && $iy === 0)) {
                    continue; // emplacements des trois motifs de repérage
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($ax + $dx, $ay + $dy, (max(abs($dx), abs($dy)) !== 1) ? 1 : 0);
                    }
                }
            }
        }
        // Zones réservées : format, module sombre, version
        for ($i = 0; $i < 9; $i++) {
            $set(8, $i, 0);
            $set($i, 8, 0);
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, 0);
            $set(8, $size - 1 - $i, 0);
        }
        $set(8, $size - 8, 1);
        if ($version >= 7) {
            for ($i = 0; $i < 18; $i++) {
                $set($size - 11 + $i % 3, intdiv($i, 3), 0);
                $set(intdiv($i, 3), $size - 11 + $i % 3, 0);
            }
        }
        return [$m, $fn];
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        switch ($mask) {
            case 0: return ($x + $y) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ($x + $y) % 3 === 0;
            case 4: return (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0;
            case 5: return ($x * $y) % 2 + ($x * $y) % 3 === 0;
            case 6: return (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0;
            default: return (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0;
        }
    }

    private static function placeData(array &$m, array $fn, array $codewords, int $version, int $mask): void
    {
        $size = count($m);
        $bits = '';
        foreach ($codewords as $c) {
            $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', self::REMAINDER[$version]);
        $i = 0;
        $n = strlen($bits);
        $upward = true;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // la colonne de synchronisation verticale est sautée
            }
            for ($k = 0; $k < $size; $k++) {
                $y = $upward ? $size - 1 - $k : $k;
                for ($c = 0; $c < 2; $c++) {
                    $x = $right - $c;
                    if ($fn[$y][$x]) {
                        continue;
                    }
                    $bit = $i < $n ? (int) $bits[$i] : 0;
                    $i++;
                    if (self::maskBit($mask, $x, $y)) {
                        $bit ^= 1;
                    }
                    $m[$y][$x] = $bit;
                }
            }
            $upward = !$upward;
        }
    }

    private static function bch(int $value, int $poly, int $polyDeg, int $dataBits): int
    {
        $v = $value << $polyDeg;
        for ($i = $dataBits + $polyDeg - 1; $i >= $polyDeg; $i--) {
            if (($v >> $i) & 1) {
                $v ^= $poly << ($i - $polyDeg);
            }
        }
        return ($value << $polyDeg) | $v;
    }

    private static function placeFormat(array &$m, int $mask): void
    {
        $size = count($m);
        $bits = self::bch((0b00 << 3) | $mask, 0x537, 10, 5) ^ 0x5412; // niveau M = 00
        $bit = static fn (int $i) => ($bits >> $i) & 1;
        for ($i = 0; $i <= 5; $i++) {
            $m[$i][8] = $bit($i);
        }
        $m[7][8] = $bit(6);
        $m[8][8] = $bit(7);
        $m[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $m[8][14 - $i] = $bit($i);
        }
        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i < 15; $i++) {
            $m[$size - 15 + $i][8] = $bit($i);
        }
        $m[$size - 8][8] = 1;
    }

    private static function placeVersion(array &$m, int $version): void
    {
        $size = count($m);
        $bits = self::bch($version, 0x1F25, 12, 6);
        for ($i = 0; $i < 18; $i++) {
            $b = ($bits >> $i) & 1;
            $a = $size - 11 + $i % 3;
            $c = intdiv($i, 3);
            $m[$c][$a] = $b;
            $m[$a][$c] = $b;
        }
    }

    /** Pénalité ISO (règles 1 à 4) pour le choix du masque. */
    private static function penalty(array $m, int $size): int
    {
        $p = 0;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($i = 0; $i < $size; $i++) {
                $run = 1;
                for ($j = 1; $j < $size; $j++) {
                    $a = $pass ? $m[$j][$i] : $m[$i][$j];
                    $b = $pass ? $m[$j - 1][$i] : $m[$i][$j - 1];
                    if ($a === $b) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $p += $run - 2;
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $p += $run - 2;
                }
                $line = '';
                for ($j = 0; $j < $size; $j++) {
                    $line .= $pass ? $m[$j][$i] : $m[$i][$j];
                }
                $p += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
            }
        }
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dark += $m[$y][$x];
                if ($y + 1 < $size && $x + 1 < $size) {
                    $s = $m[$y][$x] + $m[$y][$x + 1] + $m[$y + 1][$x] + $m[$y + 1][$x + 1];
                    if ($s === 0 || $s === 4) {
                        $p += 3;
                    }
                }
            }
        }
        $p += intdiv(abs(intdiv($dark * 100, $size * $size) - 50), 5) * 10;
        return $p;
    }
}
