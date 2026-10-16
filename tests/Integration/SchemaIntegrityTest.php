<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/** Vérifie que la base migrée contient bien le référentiel et les garde-fous financiers. */
final class SchemaIntegrityTest extends TestCase
{
    public function testReferenceDataIsLoaded(): void
    {
        $db = $this->db();
        $this->assertSame(8, (int) $db->fetchValue('SELECT COUNT(*) FROM cities'));
        $this->assertSame(21, (int) $db->fetchValue('SELECT COUNT(*) FROM job_categories'));
        $this->assertGreaterThan(200, (int) $db->fetchValue('SELECT COUNT(*) FROM job_subcategories'));
        $this->assertSame(7, (int) $db->fetchValue('SELECT COUNT(*) FROM roles'));
        $this->assertSame('10.00', (string) $db->fetchValue("SELECT worker_rate FROM commission_rules WHERE scope = 'GLOBAL'"));
        $this->assertSame(22, (int) $db->fetchValue('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()'));
    }

    public function testNoIntegratedGatewayIsLiveWithoutContract(): void
    {
        $this->assertSame(0, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM payment_gateways WHERE holds_funds = 1 AND is_active = 1 AND environment = 'PRODUCTION'"
        ));
    }

    public function testMigrationsAreRecorded(): void
    {
        $versions = array_column($this->db()->fetchAll('SELECT version FROM schema_migrations ORDER BY version'), 'version');
        $this->assertContains('2026_10_16_000001_initial_schema', $versions);
        $this->assertContains('2026_10_17_000001_password_changed_at_precision', $versions);
        $this->assertSame('datetime(3)', strtolower((string) $this->db()->fetchValue(
            "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password_changed_at'"
        )));
    }
}
