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
