<?php
declare(strict_types=1);

namespace Addons\Pay;

use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Env;
use App\Services\LicenceService;

/**
 * Secrets des prestataires et terminaux (clés d'API, secrets clients, jetons, secrets de pont local) :
 * chiffrés avec une clé dérivée de APP_KEY, jamais renvoyés au navigateur, masqués dans les journaux.
 */
final class Secrets
{
    public const SENSITIVE = '/(pass(word)?|secret|token|api[_-]?key|apikey|authorization|bearer|private|client_secret|pin|cvv|cvc|pan|card_?number|cookie|signature_key)/i';

    private static function key(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        if (strlen($k) < 32) {
            throw new BusinessException('APP_KEY absente du fichier .env : les clés de paiement ne peuvent pas être chiffrées.');
        }
        return hash('sha256', 'pay-connect|' . $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(24);
        return 'P1.' . base64_encode($nonce . LicenceService::sodium('crypto_secretbox', $plain, $nonce, self::key()));
    }

    public static function decrypt(string $enc): string
    {
        $raw = str_starts_with($enc, 'P1.') ? base64_decode(substr($enc, 3), true) : false;
        $plain = $raw !== false && strlen($raw) > 24 ? LicenceService::sodium('crypto_secretbox_open', substr($raw, 24), substr($raw, 0, 24), self::key()) : false;
        if ($plain === false) {
            throw new BusinessException('Secret de paiement illisible (APP_KEY a changé ?) : saisissez-le de nouveau.');
        }
        return (string) $plain;
    }

    private static function owner(string $type): string
    {
        return $type === 'terminal' ? 'terminal_id' : 'connector_id';
    }

    public static function put(string $type, int $id, string $ckey, string $value, ?int $userId = null, ?string $expiresAt = null): void
    {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $ckey)) {
            throw new BusinessException('Nom de secret invalide.');
        }
        $col = self::owner($type);
        DB::run("DELETE FROM payment_api_credentials WHERE $col = ? AND ckey = ?", [$id, $ckey]);
        if ($value === '') {
            return;
        }
        DB::insert('payment_api_credentials', [$col => $id, 'ckey' => $ckey, 'value_enc' => self::encrypt($value),
            'hint' => mb_strlen($value) >= 12 ? '…' . mb_substr($value, -4) : '…', 'updated_by' => $userId, 'expires_at' => $expiresAt]);
    }

    public static function get(string $type, int $id, string $ckey, bool $unexpiredOnly = false): ?string
    {
        $col = self::owner($type);
        $enc = DB::value("SELECT value_enc FROM payment_api_credentials WHERE $col = ? AND ckey = ?" . ($unexpiredOnly ? ' AND (expires_at IS NULL OR expires_at > NOW() + INTERVAL 30 SECOND)' : ''), [$id, $ckey]);
        return $enc === null ? null : self::decrypt((string) $enc);
    }

    public static function hints(string $type, int $id): array
    {
        $col = self::owner($type);
        $out = [];
        foreach (DB::all("SELECT ckey, hint FROM payment_api_credentials WHERE $col = ? ORDER BY ckey", [$id]) as $r) {
            $out[$r['ckey']] = (string) ($r['hint'] ?: '…');
        }
        return $out;
    }

    /** Toutes les valeurs en clair d'un connecteur ou terminal (pour les effacer des journaux). */
    public static function all(string $type, int $id): array
    {
        $col = self::owner($type);
        $out = [];
        foreach (DB::all("SELECT value_enc FROM payment_api_credentials WHERE $col = ?", [$id]) as $r) {
            try {
                $out[] = self::decrypt((string) $r['value_enc']);
            } catch (\Throwable) {
            }
        }
        return $out;
    }

    /** Masque secrets, en-têtes d'authentification et toute suite de chiffres ressemblant à un numéro de carte. */
    public static function redact(mixed $data, array $known = []): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = is_string($k) && preg_match(self::SENSITIVE, $k) && !preg_match('/(_url|_hint|^pan_last4)$/i', $k) && $v !== null && $v !== '' ? '***' : self::redact($v, $known);
            }
            return $out;
        }
        if (!is_string($data)) {
            return $data;
        }
        foreach ($known as $s) {
            if (is_string($s) && strlen($s) >= 4) {
                $data = str_replace($s, '***', $data);
            }
        }
        $data = preg_replace('/(Authorization|X-Api-Key|Api-Key|Cookie|X-Bridge-Signature)\s*:\s*[^\r\n]+/i', '$1: ***', $data) ?? $data;
        $data = preg_replace('/("(?:[^"]*(?:pass|secret|token|api_?key|authorization|cvv|cvc|pin)[^"]*)"\s*:\s*)"[^"]*"/i', '$1"***"', $data) ?? $data;
        $data = preg_replace('/((?:pass(?:word)?|secret|token|api_?key|client_secret|cvv|pin)=)[^&\s]+/i', '$1***', $data) ?? $data;
        // Numéros de carte (13 à 19 chiffres, espaces ou tirets) : jamais conservés
        $data = preg_replace('/\b(?:\d[ -]?){12,18}\d\b/', '[NUMÉRO MASQUÉ]', $data) ?? $data;
        return $data;
    }
}
