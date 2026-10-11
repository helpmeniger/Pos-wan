<?php
declare(strict_types=1);

namespace App\Controllers;

/** Signal interne de redirection, intercepté par le routeur. */
final class RedirectSignal extends \RuntimeException
{
    public function __construct(public readonly string $location)
    {
        parent::__construct('redirect');
    }
}
