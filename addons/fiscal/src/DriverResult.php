<?php
declare(strict_types=1);

namespace Addons\Fiscal;

/** Réponse normalisée d'un pilote. Seul « certified » avec des données vérifiées vaut certification. */
final class DriverResult
{
    public const CERTIFIED = 'certified';
    public const OK = 'ok';
    public const REJECTED = 'rejected';
    public const UNSUPPORTED = 'unsupported';
    public const UNREACHABLE = 'unreachable';
    public const TIMEOUT = 'timeout';
    public const AUTH_FAILED = 'auth_failed';
    public const INVALID_RESPONSE = 'invalid_response';
    public const NOT_FOUND = 'not_found';
    public const CONFIG_ERROR = 'config_error';

    /** Codes après lesquels un nouvel essai a un sens (panne, délai). */
    public const RETRYABLE = [self::UNREACHABLE, self::TIMEOUT, self::INVALID_RESPONSE];

    public function __construct(
        public readonly string $code,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly ?string $request = null,
        public readonly ?string $response = null,
        public readonly ?int $httpStatus = null,
    ) {
    }

    public static function ok(array $data = [], string $message = 'OK', ?string $request = null, ?string $response = null, ?int $http = null): self
    {
        return new self(self::OK, $message, $data, $request, $response, $http);
    }

    public static function fail(string $code, string $message, array $data = [], ?string $request = null, ?string $response = null, ?int $http = null): self
    {
        return new self($code, $message, $data, $request, $response, $http);
    }

    public static function unsupported(string $function, string $driver): self
    {
        return new self(self::UNSUPPORTED, "Fonction « $function » non prise en charge par le pilote « $driver ». Aucune opération n'a été effectuée.");
    }

    public function isOk(): bool
    {
        return $this->code === self::OK || $this->code === self::CERTIFIED;
    }

    public function retryable(): bool
    {
        return in_array($this->code, self::RETRYABLE, true);
    }
}
