<?php

declare(strict_types=1);

namespace App\Core;

/** Jeton anti-CSRF lié à la session, comparé en temps constant. */
final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->put('_csrf', $token);
        }
        return $token;
    }

    public function validate(?string $candidate): bool
    {
        $token = $this->session->get('_csrf');
        return is_string($token) && is_string($candidate) && $candidate !== '' && hash_equals($token, $candidate);
    }
}
