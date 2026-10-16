<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $errorCode = 'error', public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Ressource introuvable.'): self
    {
        return new self(404, $message, 'not_found');
    }

    public static function forbidden(string $message = 'Accès refusé.'): self
    {
        return new self(403, $message, 'forbidden');
    }

    public static function unauthorized(string $message = 'Authentification requise.'): self
    {
        return new self(401, $message, 'unauthenticated');
    }

    public static function conflict(string $message, string $code = 'conflict'): self
    {
        return new self(409, $message, $code);
    }

    public static function badRequest(string $message, string $code = 'bad_request'): self
    {
        return new self(400, $message, $code);
    }

    public static function tooMany(string $message = 'Trop de requêtes. Réessayez dans quelques instants.'): self
    {
        return new self(429, $message, 'rate_limited');
    }
}
