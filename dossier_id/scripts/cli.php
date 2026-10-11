<?php
declare(strict_types=1);

/** Utilitaires communs aux scripts en ligne de commande. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}

function cli_bootstrap(): void
{
    require_once dirname(__DIR__) . '/app/bootstrap.php';
}

function cli_args(): array
{
    $out = [];
    foreach (array_slice($_SERVER['argv'], 1) as $a) {
        if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $a, $m)) {
            $out[$m[1]] = $m[2] ?? true;
        }
    }
    return $out;
}

function out(string $msg = ''): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function ok(string $msg): void
{
    out('  [OK]   ' . $msg);
}

function warn(string $msg): void
{
    out('  [ATTENTION] ' . $msg);
}

function fail(string $msg): void
{
    out('  [ERREUR] ' . $msg);
}

function ask(string $question, string $default = ''): string
{
    fwrite(STDOUT, $question . ($default !== '' ? " [$default]" : '') . ' : ');
    $line = fgets(STDIN);
    $line = $line === false ? '' : trim($line);
    return $line === '' ? $default : $line;
}
