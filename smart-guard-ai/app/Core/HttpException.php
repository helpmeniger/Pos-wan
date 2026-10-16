<?php
declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message ?: match ($status) {
            400 => 'Requête invalide.',
            401 => 'Authentification requise.',
            403 => 'Accès refusé : vous n\'avez pas les droits nécessaires.',
            404 => 'Page introuvable.',
            413 => 'Fichier trop volumineux.',
            419 => 'Jeton de sécurité expiré. Rechargez la page et réessayez.',
            422 => 'Données invalides.',
            429 => 'Trop de requêtes. Réessayez plus tard.',
            503 => 'Service indisponible.',
            default => 'Erreur.',
        });
    }
}
