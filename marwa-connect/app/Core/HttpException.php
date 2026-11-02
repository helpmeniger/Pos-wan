<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Exception HTTP portant un code de statut (403, 404, 419, 429…). */
class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly array $headers = [])
    {
        parent::__construct($message, $status);
    }
}
