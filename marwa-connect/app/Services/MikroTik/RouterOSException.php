<?php
declare(strict_types=1);

namespace App\Services\MikroTik;

use RuntimeException;

/** Erreur de communication ou réponse !trap/!fatal de RouterOS. */
final class RouterOSException extends RuntimeException
{
}
