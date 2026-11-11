<?php
declare(strict_types=1);

namespace Addons\Fiscal;

use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Env;
use App\Services\LicenceService;

/**
 * Secrets des connecteurs (clés d'API, jetons, mots de passe de certificats) :
 * chiffrés en base avec une clé dérivée de APP_KEY, jamais renvoyés au navigateur,
 * masqués dans tous les journaux.
 */
final class Secrets
{
    /** Noms de champs considérés comme secrets partout (requêtes, réponses, configuration). */
    public const SENSITIVE = '/(pass(word)?|secret|token|api[_-]?key|apikey|authorization|bearer|private|client_secret|pin|cookie|signature_key)/i';

    private static function key(): string
    {
        $k = (string) Env::get('APP_KEY', '');
        if (strlen($k) < 32) {
            throw new BusinessException('APP_KEY absente du fichier .env : les clés fiscales ne peuvent pas être chiffrées.');
        }
        return hash('sha256', 'fiscal-connect|' . $k, true);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(24);
        return 'F1.' . base64_encode($nonce . LicenceService::sodium('crypto_secretbox', $plain, $nonce, self::key()));
    }

    public static function decrypt(string $enc): string
    {
        $raw = str_starts_with($enc, 'F1.') ? base64_decode(substr($enc, 3), true) : false;
        $plain = $raw !== false && strlen($raw) > 24 ? LicenceService::sodium('crypto_secretbox_open', substr($raw, 24), substr($raw, 0, 24), self::key()) : false;
        if ($plain === false) {
            throw new BusinessException('Secret fiscal illisible (APP_KEY a changé ?) : saisissez-le de nouveau.');
        }
        return (string) $plain;
    }

    /** Enregistre (ou efface si vide) un secret d'un connecteur. Seuls les 4 derniers caractères sont gardés en clair comme repère. */
    public static function put(int $connectorId, string $ckey, string $value, ?int $userId = null): void
    {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $ckey)) {
            throw new BusinessException('Nom de secret invalide.');
        }
        if ($value === '') {
            DB::run('DELETE FROM fiscal_api_credentials WHERE connector_id = ? AND ckey = ?', [$connectorId, $ckey]);
            return;
        }
        $hint = mb_strlen($value) >= 12 ? '…' . mb_substr($value, -4) : '…';
        DB::run('INSERT INTO fiscal_api_credentials (connector_id, ckey, value_enc, hint, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE value_enc = VALUES(value_enc), hint = VALUES(hint), updated_by = VALUES(updated_by), updated_at = NOW()',
            [$connectorId, $ckey, self::encrypt($value), $hint, $userId]);
    }

    /** Valeur en clair, pour le pilote uniquement (côté serveur). */
    public static function get(int $connectorId, string $ckey): ?string
    {
        $enc = DB::value('SELECT value_enc FROM fiscal_api_credentials WHERE connector_id = ? AND ckey = ?', [$connectorId, $ckey]);
        return $enc === null ? null : self::decrypt((string) $enc);
    }

    /** @return array<string, string> ckey => repère masqué (pour l'interface). */
    public static function hints(int $connectorId): array
    {
        $out = [];
        foreach (DB::all('SELECT ckey, hint FROM fiscal_api_credentials WHERE connector_id = ? ORDER BY ckey', [$connectorId]) as $r) {
            $out[$r['ckey']] = (string) ($r['hint'] ?: '…');
        }
        return $out;
    }

    /**
     * Masque les secrets dans un texte ou une structure avant journalisation :
     * champs au nom sensible, valeurs connues du connecteur, en-têtes Authorization.
     * @param string[] $known valeurs en clair à effacer partout
     */
    public static function redact(mixed $data, array $known = []): mixed
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $out[$k] = is_string($k) && preg_match(self::SENSITIVE, $k) && $v !== null && $v !== '' ? '***' : self::redact($v, $known);
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
        $data = preg_replace('/(Authorization|X-Api-Key|Api-Key|Cookie)\s*:\s*[^\r\n]+/i', '$1: ***', $data) ?? $data;
        // JSON ou formulaire : "password":"x" / password=x
        $data = preg_replace('/("(?:[^"]*(?:pass|secret|token|api_?key|authorization)[^"]*)"\s*:\s*)"[^"]*"/i', '$1"***"', $data) ?? $data;
        $data = preg_replace('/((?:pass(?:word)?|secret|token|api_?key|client_secret)=)[^&\s]+/i', '$1***', $data) ?? $data;
        return $data;
    }
}
