<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Détection du sous-dossier d'installation (WampServer avec ou sans VirtualHost). */
final class BasePathTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function cases(): array
    {
        return [
            'VirtualHost sur public/' => ['/connexion', '/index.php', ''],
            'localhost/job-niger/ (racine → public)' => ['/job-niger/connexion', '/job-niger/public/index.php', '/job-niger'],
            'localhost/job-niger/ accueil' => ['/job-niger/', '/job-niger/public/index.php', '/job-niger'],
            'localhost/job-niger/public/' => ['/job-niger/public/connexion', '/job-niger/public/index.php', '/job-niger/public'],
            'pas de faux préfixe' => ['/job-nigeria/x', '/job-niger/public/index.php', ''],
            'serveur PHP intégré' => ['/aide', '/index.php', ''],
        ];
    }

    #[DataProvider('cases')]
    public function testDetect(string $path, string $script, string $expected): void
    {
        $this->assertSame($expected, Request::detectBasePath($path, $script));
    }
}
