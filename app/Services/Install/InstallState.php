<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * L'application est « installée » dès que le fichier .env existe (créé par l'assistant ou à la main).
 * Sinon, toute requête mène à l'assistant d'installation.
 * Relancer l'assistant sur une base déjà installée est sans risque : schéma à jour et administrateur existant conservés.
 */
final class InstallState
{
    public static function isInstalled(string $root): bool
    {
        return is_file($root . '/.env');
    }
}
