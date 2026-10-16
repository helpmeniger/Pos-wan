<?php

declare(strict_types=1);

namespace App\Core;

/** Levée par le Validator ; le noyau la transforme en retour au formulaire (web) ou en 422 (API). */
final class ValidationException extends HttpException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors, private readonly array $old = [])
    {
        parent::__construct(422);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function old(): array
    {
        return $this->old;
    }
}
