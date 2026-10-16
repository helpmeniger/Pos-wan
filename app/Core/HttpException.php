<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Erreur HTTP contrôlée (403, 404, 419, 429…) rendue avec une page dédiée. */
class HttpException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message = '', private readonly array $headers = [])
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Requête invalide.',
            401 => 'Veuillez vous connecter.',
            403 => 'Accès refusé.',
            404 => 'Page introuvable.',
            405 => 'Méthode non autorisée.',
            419 => 'Votre session a expiré. Veuillez recharger la page et réessayer.',
            422 => 'Les données envoyées sont invalides.',
            429 => 'Trop de tentatives. Veuillez patienter avant de réessayer.',
            503 => 'Service momentanément indisponible.',
            default => 'Une erreur est survenue.',
        };
    }
}
