<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use RuntimeException;

/** Interrompt un contrôleur et renvoie la réponse d'erreur de validation. */
final class ValidationFailed extends RuntimeException
{
    public function __construct(public readonly array $errors, public readonly Response $response)
    {
        parent::__construct('Validation échouée');
    }
}
