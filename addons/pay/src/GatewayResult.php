<?php
declare(strict_types=1);

namespace Addons\Pay;

/** Réponse normalisée d'un connecteur ou d'un terminal. Seul SUCCEEDED (vérifié par le moteur) vaut encaissement. */
final class GatewayResult
{
    // États d'un paiement chez le prestataire
    public const SUCCEEDED = 'succeeded';
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const DECLINED = 'declined';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const TO_VERIFY = 'to_verify';
    public const REFUNDED = 'refunded';
    // Résultats techniques
    public const OK = 'ok';
    public const UNSUPPORTED = 'unsupported';
    public const UNREACHABLE = 'unreachable';
    public const TIMEOUT = 'timeout';
    public const AUTH_FAILED = 'auth_failed';
    public const INVALID_RESPONSE = 'invalid_response';
    public const CONFIG_ERROR = 'config_error';
    public const NOT_FOUND = 'not_found';

    public function __construct(
        public readonly string $code,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly ?string $request = null,
        public readonly ?string $response = null,
        public readonly ?int $httpStatus = null,
    ) {
    }

    public static function make(string $code, string $message = '', array $data = [], ?string $req = null, ?string $resp = null, ?int $http = null): self
    {
        return new self($code, $message, $data, $req, $resp, $http);
    }

    public static function unsupported(string $fn, string $driver): self
    {
        return new self(self::UNSUPPORTED, "Fonction « $fn » non prise en charge par « $driver ». Aucune opération n'a été effectuée.");
    }

    public function isOk(): bool
    {
        return in_array($this->code, [self::OK, self::SUCCEEDED, self::REFUNDED], true);
    }
}
