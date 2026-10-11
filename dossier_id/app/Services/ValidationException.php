<?php
declare(strict_types=1);

namespace App\Services;

/** Erreur de saisie destinée à l'utilisateur (message affichable). */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
