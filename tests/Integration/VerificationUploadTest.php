<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Kernel;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\Verification\VerificationService;
use Tests\TestCase;

/** Documents : contrôle réel des fichiers, stockage privé, validation par un administrateur. */
final class VerificationUploadTest extends TestCase
{
    private function upload(string $content, string $name): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jn');
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'test' => true];
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(40, 30);
        imagefilledrectangle($img, 0, 0, 39, 29, imagecolorallocate($img, 11, 122, 92));
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    private function moderator(): int
    {
        $phone = Factory::phone();
        ob_start();
        (new Kernel(self::$app))->run(['console', 'admin:create', '--phone=' . $phone, '--first=Modo', '--last=Test', '--role=MODERATOR']);
        ob_end_clean();
        return (int) $this->db()->fetchValue('SELECT id FROM users WHERE phone = ?', [$phone]);
    }

    public function testDangerousFilesAreRejected(): void
    {
        $worker = Factory::user(self::$app);
        $v = $this->service(VerificationService::class);
        foreach ([
            ['<?php system($_GET["c"]); ?>', 'cni.jpg'],
            ["GIF89a<?php phpinfo(); ?>", 'cni.png'],
            ['<html><script>alert(1)</script></html>', 'cni.pdf'],
            ['MZ' . str_repeat("\0", 200), 'cni.exe'],
        ] as [$content, $name]) {
            try {
                $v->submit($worker, 'CNI', $this->upload($content, $name));
                $this->fail("Fichier dangereux accepté : {$name}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('document', $e->errors());
            }
        }
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM verification_requests WHERE user_id = ?', [$worker]));
    }

    public function testImageIsReencodedStoredPrivatelyAndApprovedByModerator(): void
    {
        $worker = Factory::user(self::$app);
        $requestId = $this->service(VerificationService::class)->submit($worker, 'CNI', $this->upload($this->png() . '<?php echo 1; ?>', 'ma-carte.png'), 'AB123456');
        $file = $this->db()->fetch('SELECT f.storage_path, f.disk, f.mime_type, d.document_number_last4 FROM verification_documents d JOIN stored_files f ON f.id = d.file_id WHERE d.request_id = ?', [$requestId]);
        $this->assertSame('PRIVATE', $file['disk']);
        $this->assertSame('image/png', $file['mime_type']);
        $this->assertSame('3456', $file['document_number_last4'], 'Seuls les 4 derniers caractères du numéro sont conservés');
        $stored = (string) file_get_contents(dirname(__DIR__, 2) . '/storage/private/' . $file['storage_path']);
        $this->assertStringNotContainsString('<?php', $stored, 'Image ré-encodée : contenu caché supprimé');

        $moderator = $this->moderator();
        $v = $this->service(VerificationService::class);
        try {
            $v->decide($moderator, $requestId, false, null);
            $this->fail('Refus sans motif accepté');
        } catch (ValidationException) {
        }
        $v->decide($moderator, $requestId, true, 'Document lisible et conforme');
        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT verification_level FROM user_profiles WHERE user_id = ?', [$worker]));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'verification.approved' AND entity_id = ?", [$requestId]));
    }

    public function testBusinessDocumentVerifiesTheOrganisation(): void
    {
        $recruiter = Factory::user(self::$app, 'BUSINESS');
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";
        $requestId = $this->service(VerificationService::class)->submit($recruiter, 'RCCM', $this->upload($pdf, 'rccm.pdf'));
        $this->assertSame('EN_COURS', $this->db()->fetchValue('SELECT b.verification_status FROM businesses b WHERE b.owner_user_id = ?', [$recruiter]));
        $this->service(VerificationService::class)->decide($this->moderator(), $requestId, true, null);
        $this->assertSame('VERIFIEE', $this->db()->fetchValue('SELECT b.verification_status FROM businesses b WHERE b.owner_user_id = ?', [$recruiter]));
    }

    public function testNobodyValidatesTheirOwnDocuments(): void
    {
        $moderator = $this->moderator();
        $requestId = $this->service(VerificationService::class)->submit($moderator, 'CNI', $this->upload($this->png(), 'moi.png'));
        $this->expectException(HttpException::class);
        $this->service(VerificationService::class)->decide($moderator, $requestId, true, null);
    }
}
