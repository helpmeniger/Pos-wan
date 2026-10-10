<?php
declare(strict_types=1);

namespace App\Core;

/** Erreur métier dont le message est destiné à l'utilisateur (sûr à afficher). */
class BusinessException extends \RuntimeException
{
}
