<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Génération de codes-barres : Code 128 (B) en SVG, EAN-13 internes (préfixe 2 = usage magasin). */
final class Barcode
{
    private const C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

    public static function svg(string $code, int $height = 50): string
    {
        $code = preg_replace('/[^\x20-\x7E]/', '', $code) ?? '';
        $values = [104]; // START B
        foreach (str_split($code) as $ch) {
            $values[] = ord($ch) - 32;
        }
        $sum = 104;
        foreach (array_slice($values, 1) as $i => $v) {
            $sum += $v * ($i + 1);
        }
        $values[] = $sum % 103;
        $values[] = 106; // STOP
        $x = 10;
        $bars = '';
        foreach ($values as $v) {
            $pattern = self::C128[$v];
            foreach (str_split($pattern) as $i => $w) {
                if ($i % 2 === 0) {
                    $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
                }
                $x += (int) $w;
            }
        }
        $width = $x + 10;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . ($height + 14) . '" preserveAspectRatio="none"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $bars . '</g><text x="' . ($width / 2) . '" y="' . ($height + 12) . '" font-family="monospace" font-size="11" text-anchor="middle">' . htmlspecialchars($code, ENT_XML1) . '</text></svg>';
    }

    private const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    private const EAN_G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
    private const EAN_R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
    private const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    public static function isEan13(string $code): bool
    {
        if (!preg_match('/^\d{13}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10 === (int) $code[12];
    }

    /** EAN-13 (symbologie standard des articles de commerce), chiffres sous les barres. */
    public static function ean13Svg(string $code, int $height = 50): string
    {
        $bits = '101';
        $parity = self::EAN_PARITY[(int) $code[0]];
        for ($i = 1; $i <= 6; $i++) {
            $bits .= ($parity[$i - 1] === 'L' ? self::EAN_L : self::EAN_G)[(int) $code[$i]];
        }
        $bits .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::EAN_R[(int) $code[$i]];
        }
        $bits .= '101';
        $q = 11; // zone de silence (modules)
        $rects = '';
        $guards = [0, 1, 2, 45, 46, 47, 48, 49, 92, 93, 94];
        foreach (str_split($bits) as $i => $b) {
            if ($b === '1') {
                $h = in_array($i, $guards, true) ? $height + 5 : $height;
                $rects .= '<rect x="' . ($q + $i) . '" y="0" width="1" height="' . $h . '"/>';
            }
        }
        $w = 95 + 2 * $q;
        $t = fn(float $x, string $s) => '<text x="' . $x . '" y="' . ($height + 11) . '" font-family="monospace" font-size="10" text-anchor="middle">' . $s . '</text>';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . ($height + 13) . '" preserveAspectRatio="none"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $rects . '</g>'
            . $t($q - 5, $code[0]) . $t($q + 3 + 21, substr($code, 1, 6)) . $t($q + 50 + 21, substr($code, 7, 6)) . '</svg>';
    }

    /** EAN-13 si le code en est un (somme de contrôle valide), sinon Code 128. */
    public static function auto(string $code, int $height = 50): string
    {
        return self::isEan13($code) ? self::ean13Svg($code, $height) : self::svg($code, $height);
    }

    /** Nouvel EAN-13 interne unique (préfixe 21). */
    public static function newInternalEan(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $twelve = '21' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $code = $twelve . DemoSeeder::ean13Check($twelve);
            $exists = DB::value('SELECT 1 FROM products WHERE barcode = ? UNION SELECT 1 FROM product_packagings WHERE barcode = ?', [$code, $code]);
            if (!$exists) {
                return $code;
            }
        }
        throw new \RuntimeException('Impossible de générer un code-barres unique.');
    }

    public static function newReference(): string
    {
        $max = (int) DB::value("SELECT MAX(CAST(SUBSTRING(reference, 4) AS UNSIGNED)) FROM products WHERE reference REGEXP '^ART[0-9]+$'");
        return sprintf('ART%05d', $max + 1);
    }
}
