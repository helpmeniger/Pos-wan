<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Numéros de téléphone nigériens au format E.164 (+227 suivi de 8 chiffres).
 * Accepte : « 90 12 34 56 », « 90-12-34-56 », « 0022790123456 », « +227 90123456 ».
 */
final class Phone
{
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $input) ?? '';
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 8) {
            $digits = '227' . $digits;
        }
        if (!preg_match('/^227([2-9]\d{7})$/', $digits)) {
            return null;
        }
        return '+' . $digits;
    }

    /** +22790123456 → +227 90 12 34 56 */
    public static function format(string $e164): string
    {
        if (!preg_match('/^\+227(\d{2})(\d{2})(\d{2})(\d{2})$/', $e164, $m)) {
            return $e164;
        }
        return "+227 {$m[1]} {$m[2]} {$m[3]} {$m[4]}";
    }

    /** +22790123456 → +227 90 xx xx 56 (affichage partiel) */
    public static function mask(string $e164): string
    {
        if (!preg_match('/^\+227(\d{2})\d{4}(\d{2})$/', $e164, $m)) {
            return '••••';
        }
        return "+227 {$m[1]} •• •• {$m[2]}";
    }
}
