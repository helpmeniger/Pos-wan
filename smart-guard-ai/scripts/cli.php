<?php
declare(strict_types=1);

/** Amorçage commun des scripts en ligne de commande. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès refusé.');
}
require dirname(__DIR__) . '/app/bootstrap.php';

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function ask(string $q, bool $hidden = false): string
{
    fwrite(STDOUT, $q);
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
        $v = trim((string) fgets(STDIN));
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $v;
    }
    return trim((string) fgets(STDIN));
}
