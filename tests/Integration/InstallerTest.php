<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Services\Install\Installer;
use App\Services\Install\InstallState;
use App\Services\Install\Requirements;
use Tests\TestCase;

/** Assistant d'installation : base créée, administrateur, .env écrit, relance possible après échec. */
final class InstallerTest extends TestCase
{
    private string $root;
    private string $dbName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/jn-install-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage', 0777, true);
        symlink(dirname(__DIR__, 2) . '/database', $this->root . '/database');
        copy(dirname(__DIR__, 2) . '/.env.example', $this->root . '/.env.example');
        $this->dbName = Env::get('DB_DATABASE') . '_inst';
        $this->serverExec("DROP DATABASE IF EXISTS `{$this->dbName}`");
    }

    protected function tearDown(): void
    {
        $this->serverExec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        @unlink($this->root . '/.env');
        @unlink($this->root . '/database');
        @unlink($this->root . '/.env.example');
        @rmdir($this->root . '/storage');
        @rmdir($this->root);
    }

    private function serverExec(string $sql): void
    {
        $c = self::$app->get(Config::class)->get('database');
        \App\Services\Install\Migrator::serverPdo($c)->exec($sql);
    }

    private function formData(array $override = []): array
    {
        $c = self::$app->get(Config::class)->get('database');
        return array_merge([
            'db_host' => $c['host'], 'db_port' => (int) $c['port'], 'db_name' => $this->dbName,
            'db_user' => $c['username'], 'db_password' => $c['password'],
            'admin_first_name' => 'Mahamadou', 'admin_last_name' => 'Hamidou', 'admin_phone' => '+22790112233',
            'admin_email' => null, 'admin_password' => 'AdminNiger2026',
        ], $override);
    }

    public function testFullInstallation(): void
    {
        $this->assertFalse(InstallState::isInstalled($this->root));
        $result = (new Installer($this->root, self::$app->get(Config::class)))->install($this->formData(), 'http://localhost', '/job-niger');
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertTrue($result['env_written']);
        $this->assertTrue(InstallState::isInstalled($this->root));

        $env = (string) file_get_contents($this->root . '/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $env);
        $this->assertStringContainsString("DB_DATABASE={$this->dbName}", $env);
        $this->assertStringContainsString('APP_BASE_PATH=/job-niger', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);

        $c = self::$app->get(Config::class)->get('database');
        $db = new Database(array_merge($c, ['database' => $this->dbName]));
        $admin = $db->fetch("SELECT u.must_change_password, r.code FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.phone = '+22790112233'");
        $this->assertSame('SUPER_ADMIN', $admin['code']);
        $this->assertSame(0, (int) $admin['must_change_password'], 'Mot de passe choisi par l\'administrateur : pas de changement imposé');
        $this->assertSame(221, (int) $db->fetchValue('SELECT COUNT(*) FROM job_subcategories'));
    }

    public function testWrongCredentialsGiveAClearMessage(): void
    {
        $result = (new Installer($this->root, self::$app->get(Config::class)))->install($this->formData(['db_password' => 'mauvais-mot-de-passe']), 'http://localhost', '');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Accès refusé', $result['error']);
        $this->assertFalse(InstallState::isInstalled($this->root), 'Un échec ne doit pas marquer l\'application comme installée');
    }

    public function testNonEmptyForeignDatabaseIsRefused(): void
    {
        $this->serverExec("CREATE DATABASE `{$this->dbName}`");
        $this->serverExec("CREATE TABLE `{$this->dbName}`.autre_appli (id INT PRIMARY KEY)");
        $result = (new Installer($this->root, self::$app->get(Config::class)))->install($this->formData(), 'http://localhost', '');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ne proviennent pas de JOB NIGER', $result['error']);
    }

    public function testRequirementsAreSatisfiedOnThisMachine(): void
    {
        $this->assertTrue(Requirements::allRequiredOk((new Requirements($this->root))->check()));
    }
}
