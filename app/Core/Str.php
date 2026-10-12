<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Référence lisible sans caractères ambigus, ex. TX-7K3MQ9. */
    public static function reference(string $prefix, int $length = 6): string
    {
        $s = '';
        for ($i = 0; $i < $length; $i++) {
            $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $prefix . '-' . $s;
    }

    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function digits(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= (string) random_int(0, 9);
        }
        return $s;
    }

    /** Normalise un numéro : 8 chiffres nationaux → +227XXXXXXXX ; accepte aussi le format international. */
    public static function normalizePhone(?string $raw, string $defaultCountry = '227'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $d = preg_replace('/[^\d+]/', '', $raw);
        if ($d === '' || $d === null) {
            return null;
        }
        if (str_starts_with($d, '00')) {
            $d = '+' . substr($d, 2);
        }
        if (preg_match('/^\d{8}$/', $d)) {
            return '+' . $defaultCountry . $d;
        }
        if (preg_match('/^' . $defaultCountry . '\d{8}$/', $d)) {
            return '+' . $d;
        }
        if (preg_match('/^\+\d{8,15}$/', $d)) {
            return $d;
        }
        return null;
    }

    /** Masque un numéro pour l'affichage : +227 •• •• 45 67 */
    public static function maskPhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        return substr($phone, 0, 4) . ' •• •• ' . substr($phone, -4, 2) . ' ' . substr($phone, -2);
    }

    public static function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [$fullName];
        return $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '');
    }
}
