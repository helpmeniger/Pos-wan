<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MerchantHours
{
    /** Ouvert = statut vérifié + interrupteur « ouvert » + dans un créneau horaire + pas de fermeture exceptionnelle. */
    public static function isOpenNow(array $merchant, ?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable();
        if ($merchant['status'] !== 'verified' || !(int) $merchant['is_open']) {
            return false;
        }
        if (Database::value('SELECT 1 FROM merchant_closures WHERE merchant_id = :m AND closed_on = :d', ['m' => $merchant['id'], 'd' => $at->format('Y-m-d')])) {
            return false;
        }
        $slots = Database::select('SELECT open_time, close_time FROM merchant_hours WHERE merchant_id = :m AND weekday = :w', ['m' => $merchant['id'], 'w' => (int) $at->format('N')]);
        if (!$slots) {
            // Aucun horaire saisi : l'interrupteur « ouvert » fait foi.
            return !Database::value('SELECT 1 FROM merchant_hours WHERE merchant_id = :m LIMIT 1', ['m' => $merchant['id']]);
        }
        $t = $at->format('H:i:s');
        foreach ($slots as $s) {
            $open = $s['open_time'];
            $close = $s['close_time'];
            if ($open <= $close ? ($t >= $open && $t < $close) : ($t >= $open || $t < $close)) {
                return true;
            }
        }
        return false;
    }
}
