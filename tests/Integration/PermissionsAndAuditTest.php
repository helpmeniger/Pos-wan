<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Kernel;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PermissionService;
use App\Services\Notifications\NotificationService;
use PDOException;
use Tests\TestCase;

final class PermissionsAndAuditTest extends TestCase
{
    private function createStaff(string $role): int
    {
        $phone = self::uniquePhone();
        ob_start();
        $code = (new Kernel(self::$app))->run(['console', 'admin:create', '--phone=' . $phone, '--first=Test', '--last=Staff', '--role=' . $role]);
        $out = (string) ob_get_clean();
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/Mot de passe TEMPORAIRE : \S{14}/', $out);
        return (int) $this->db()->fetchValue('SELECT id FROM users WHERE phone = ?', [$phone]);
    }

    public function testRolePermissionMatrix(): void
    {
        $perm = $this->service(PermissionService::class);
        $super = $this->createStaff('SUPER_ADMIN');
        $finance = $this->createStaff('FINANCE_ADMIN');
        $moderator = $this->createStaff('MODERATOR');
        $support = $this->createStaff('SUPPORT');
        $admin = $this->createStaff('ADMIN');

        $this->assertTrue($perm->allows($super, 'roles.manage'));
        $this->assertFalse($perm->allows($admin, 'roles.manage'), 'ADMIN ne gère pas les rôles');
        $this->assertFalse($perm->allows($admin, 'finance.payouts.approve'), 'ADMIN n\'approuve pas les versements');
        $this->assertTrue($perm->allows($finance, 'finance.payouts.approve'));
        $this->assertFalse($perm->allows($finance, 'jobs.moderate'));
        $this->assertTrue($perm->allows($moderator, 'jobs.moderate'));
        $this->assertFalse($perm->allows($moderator, 'finance.transactions.view'));
        $this->assertTrue($perm->allows($support, 'support.manage'));
        $this->assertFalse($perm->allows($support, 'users.manage'));
        foreach ([$super, $finance, $moderator, $support, $admin] as $id) {
            $this->assertTrue($perm->allows($id, 'admin.dashboard.view'));
        }
    }

    public function testStaffAccountMustChangePassword(): void
    {
        $id = $this->createStaff('SUPPORT');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT must_change_password FROM users WHERE id = ?', [$id]));
    }

    public function testAuditChainIsValidAndLogIsAppendOnly(): void
    {
        $audit = $this->service(AuditLogger::class);
        $audit->record(null, 'test.one', 'user', 1, ['a' => 1], ['a' => 2], 'raison');
        $audit->record(null, 'test.two', 'user', 2);
        $this->assertTrue($audit->verifyChain()['ok']);
        $this->expectException(PDOException::class);
        $this->db()->query("UPDATE admin_audit_logs SET reason = 'falsifié' ORDER BY id DESC LIMIT 1");
    }

    public function testNotificationOwnershipIsEnforced(): void
    {
        $a = $this->createStaff('SUPPORT');
        $b = $this->createStaff('SUPPORT');
        $notifications = $this->service(NotificationService::class);
        $id = $notifications->notify($a, 'test', 'Pour A uniquement');
        $this->assertFalse($notifications->markRead($b, $id), 'B ne peut pas modifier une notification de A');
        $this->assertTrue($notifications->markRead($a, $id));
    }
}
