<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Paths;
use App\Support\Installer;
use Tests\TestCase;

/** Détection automatique du dossier d'installation (cause des erreurs 404 avec un APP_URL erroné). */
final class PathsTest extends TestCase
{
    public function testWampSubfolderWithRewrite(): void
    {
        Paths::detect(['SCRIPT_NAME' => '/marwa/public/index.php', 'REDIRECT_MARWA_REWRITE' => '1']);
        $this->assertSame('/marwa', Paths::base());
        $this->assertSame('/marwa/connexion', url('connexion'));
        $this->assertSame('/marwa/assets/x.css', public_url('assets/x.css'));
        $this->assertTrue(Paths::rewrite());
        Paths::reset();
    }

    public function testNestedFolderAndWindowsSeparators(): void
    {
        Paths::detect(['SCRIPT_NAME' => '\\projets\\marwa\\public\\index.php', 'MARWA_REWRITE' => '1']);
        $this->assertSame('/projets/marwa/client', url('client'));
        Paths::reset();
    }

    public function testVirtualHostOnPublic(): void
    {
        Paths::detect(['SCRIPT_NAME' => '/index.php', 'REDIRECT_MARWA_REWRITE' => '1']);
        $this->assertSame('', Paths::base());
        $this->assertSame('/connexion', url('connexion'));
        Paths::reset();
    }

    public function testWithoutRewriteModule(): void
    {
        Paths::detect(['SCRIPT_NAME' => '/marwa/index.php'], 'root');
        $this->assertSame('/marwa', Paths::base());
        $this->assertSame('/marwa/index.php/connexion', url('connexion'));
        $this->assertSame('/marwa/public/assets/x.css', public_url('assets/x.css'));
        $this->assertFalse(Paths::rewrite());
        Paths::reset();

        Paths::detect(['SCRIPT_NAME' => '/marwa/public/index.php']);
        $this->assertSame('/marwa/public/index.php/client', url('client'));
        $this->assertSame('/marwa/public/assets/a.js', public_url('assets/a.js'));
        Paths::reset();
    }

    public function testAbsoluteUrlRejectsForgedHost(): void
    {
        Paths::detect(['SCRIPT_NAME' => '/marwa/public/index.php', 'MARWA_REWRITE' => '1']);
        $this->assertSame('http://localhost:8080/marwa', Paths::absolute(['HTTP_HOST' => 'localhost:8080']));
        $this->assertSame('http://localhost/marwa', Paths::absolute(['HTTP_HOST' => 'evil.com/"><script>']));
        Paths::reset();
    }

    public function testInstallerMigrationListAndErrors(): void
    {
        $this->assertTrue(in_array('001_core_schema', Installer::shippedMigrations(), true));
        $this->assertSame('accès refusé (utilisateur ou mot de passe incorrect)', Installer::cleanError("SQLSTATE[HY000] [1045] Access denied for user 'root'"));
        $this->assertTrue(str_contains(Installer::cleanError('SQLSTATE[HY000] [2002] Connection refused'), 'WampServer'));
    }
}
