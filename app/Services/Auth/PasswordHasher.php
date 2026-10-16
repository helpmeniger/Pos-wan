<?php

declare(strict_types=1);

namespace App\Services\Auth;

/** Argon2id lorsqu'il est disponible, sinon bcrypt (coût 12). */
final class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, $this->algo(), $this->options());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo(), $this->options());
    }

    /** Hachage factice pour égaliser le temps de réponse quand le compte n'existe pas. */
    public function dummyVerify(string $password): void
    {
        static $dummy = null;
        $dummy ??= password_hash('compte-inexistant', $this->algo(), $this->options());
        password_verify($password, $dummy);
    }

    /** Mot de passe temporaire lisible (sans caractères ambigus), avec lettres et chiffres. */
    public function temporaryPassword(int $length = 14): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (!preg_match('/\d/', $out) || !preg_match('/[a-z]/i', $out));
        return $out;
    }

    private function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, int> */
    private function options(): array
    {
        return defined('PASSWORD_ARGON2ID') ? [] : ['cost' => 12];
    }
}
