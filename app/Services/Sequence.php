<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Numérotation continue et sans doublon (verrou de ligne FOR UPDATE). */
final class Sequence
{
    public const DEFAULTS = [
        'sale' => 'V', 'return' => 'AV', 'purchase_order' => 'BC', 'reception' => 'BR', 'transfer' => 'TR',
        'inventory' => 'INV', 'expense' => 'DEP', 'supplier_return' => 'RF',
    ];

    public static function next(string $name): string
    {
        return DB::transaction(function () use ($name) {
            $year = (int) date('Y');
            $row = DB::one('SELECT * FROM sequences WHERE name = ? FOR UPDATE', [$name]);
            if (!$row) {
                DB::run('INSERT IGNORE INTO sequences (name, prefix, next_value, year) VALUES (?, ?, 1, ?)', [$name, self::DEFAULTS[$name] ?? strtoupper($name), $year]);
                $row = DB::one('SELECT * FROM sequences WHERE name = ? FOR UPDATE', [$name]);
            }
            $value = (int) $row['next_value'];
            if ((int) $row['year'] !== $year) {
                $value = 1; // remise à zéro annuelle, le préfixe contient l'année
            }
            DB::run('UPDATE sequences SET next_value = ?, year = ? WHERE name = ?', [$value + 1, $year, $name]);
            return sprintf('%s%s-%06d', $row['prefix'], $year, $value);
        });
    }
}
