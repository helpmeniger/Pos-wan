<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

final class PromotionService
{
    /** Valide un code et calcule la réduction ; ne réserve rien (voir redeem). */
    public static function evaluate(string $code, int $userId, string $serviceType, int $baseAmount, ?int $merchantId = null): array
    {
        $p = Database::first('SELECT * FROM promotions WHERE code = :c AND is_active = 1', ['c' => strtoupper(trim($code))]);
        $now = date('Y-m-d H:i:s');
        if (!$p || $p['starts_at'] > $now || $p['ends_at'] < $now) {
            throw new HttpException(422, 'Code promotionnel invalide ou expiré.', 'invalid_promo');
        }
        if ($p['service_type'] && $p['service_type'] !== $serviceType) {
            throw new HttpException(422, 'Ce code ne s\'applique pas à ce service.', 'invalid_promo');
        }
        if ($p['merchant_id'] && (int) $p['merchant_id'] !== (int) $merchantId) {
            throw new HttpException(422, 'Ce code ne s\'applique pas à ce commerce.', 'invalid_promo');
        }
        if ($baseAmount < (int) $p['min_order']) {
            throw new HttpException(422, 'Montant minimum non atteint : ' . fcfa((int) $p['min_order']) . '.', 'invalid_promo');
        }
        if ($p['max_uses'] !== null && (int) $p['used_count'] >= (int) $p['max_uses']) {
            throw new HttpException(422, 'Ce code a atteint son nombre maximal d\'utilisations.', 'invalid_promo');
        }
        $used = (int) Database::value('SELECT COUNT(*) FROM promotion_redemptions WHERE promotion_id = :p AND user_id = :u', ['p' => $p['id'], 'u' => $userId]);
        if ($used >= (int) $p['per_user_limit']) {
            throw new HttpException(422, 'Vous avez déjà utilisé ce code.', 'invalid_promo');
        }
        $discount = $p['discount_type'] === 'percent' ? (int) round($baseAmount * (int) $p['value'] / 10000) : (int) $p['value'];
        if ($p['max_discount'] !== null) {
            $discount = min($discount, (int) $p['max_discount']);
        }
        return ['promotion' => $p, 'discount' => max(0, min($discount, $baseAmount))];
    }

    public static function redeem(int $promotionId, int $userId, int $orderId, int $amount): void
    {
        $n = Database::execute('UPDATE promotions SET used_count = used_count + 1 WHERE id = :id AND (max_uses IS NULL OR used_count < max_uses)', ['id' => $promotionId]);
        if ($n !== 1) {
            throw new HttpException(422, 'Ce code a atteint son nombre maximal d\'utilisations.', 'invalid_promo');
        }
        Database::insert('promotion_redemptions', ['promotion_id' => $promotionId, 'user_id' => $userId, 'order_id' => $orderId, 'amount' => $amount]);
    }

    public static function release(int $orderId): void
    {
        $r = Database::first('SELECT * FROM promotion_redemptions WHERE order_id = :o', ['o' => $orderId]);
        if ($r) {
            Database::execute('DELETE FROM promotion_redemptions WHERE id = :id', ['id' => $r['id']]);
            Database::execute('UPDATE promotions SET used_count = GREATEST(used_count - 1, 0) WHERE id = :id', ['id' => $r['promotion_id']]);
        }
    }
}
