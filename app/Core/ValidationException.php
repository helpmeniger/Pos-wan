<?php
declare(strict_types=1);

namespace App\Core;

final class ValidationException extends HttpException
{
    public function __construct(public readonly array $errors, string $message = 'Certaines informations sont invalides.')
    {
        parent::__construct(422, $message, 'validation_failed', ['fields' => $errors]);
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }
}
