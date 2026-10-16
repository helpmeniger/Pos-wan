<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Env;

/**
 * Contenu des QR codes imprimés en bas des tickets, factures, devis et additions :
 * références du document + signature (HMAC avec la clé secrète APP_KEY) qui permet
 * de vérifier qu'un ticket présenté (retour, réclamation) n'a pas été falsifié.
 * Exemple : POSWAN|V|V2026-000123|123|20261010-1245|13500XOF|9f2c41ab07de
 */
final class DocumentRef
{
    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, (string) Env::get('APP_KEY', 'poswan')), 0, 12);
    }

    private static function build(string $type, string $number, int $id, string $date, float $total): string
    {
        $payload = implode('|', ['POSWAN', $type, $number, $id, date('Ymd-Hi', strtotime($date)), round($total, 2) . (string) setting('currency_code', 'XOF')]);
        return $payload . '|' . self::sign($payload);
    }

    public static function saleQr(array $sale): string
    {
        return self::build('V', $sale['number'], (int) $sale['id'], $sale['created_at'], (float) $sale['total']);
    }

    public static function quoteQr(array $q): string
    {
        return self::build('D', $q['number'], (int) $q['id'], $q['created_at'], (float) $q['total']);
    }

    public static function orderQr(array $o, float $total): string
    {
        return self::build('C', $o['number'], (int) $o['id'], $o['opened_at'], $total);
    }

    /**
     * Vérifie un contenu de QR code.
     * @return array{valid:bool, type?:string, number?:string, id?:int, doc?:array|null, message:string}
     */
    public static function verify(string $code): array
    {
        $parts = explode('|', trim($code));
        if (count($parts) !== 7 || $parts[0] !== 'POSWAN') {
            return ['valid' => false, 'message' => 'Ce code n\'est pas un QR code POS Marwa.'];
        }
        $sig = array_pop($parts);
        if (!hash_equals(self::sign(implode('|', $parts)), $sig)) {
            return ['valid' => false, 'message' => 'Signature invalide : document falsifié ou émis par une autre installation.'];
        }
        [, $type, $number, $id] = $parts;
        $table = ['V' => 'sales', 'D' => 'quotes', 'C' => 'restaurant_orders'][$type] ?? null;
        $doc = $table ? DB::one("SELECT * FROM `$table` WHERE id = ? AND number = ?", [(int) $id, $number]) : null;
        return ['valid' => $doc !== null, 'type' => $type, 'number' => $number, 'id' => (int) $id, 'doc' => $doc,
            'message' => $doc ? 'Document authentique.' : 'Signature valide mais document introuvable dans cette base.'];
    }
}
