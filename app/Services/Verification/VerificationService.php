<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\Audit\AuditLogger;
use App\Services\Files\FileStorageService;
use App\Services\Notifications\NotificationService;

/**
 * Documents d'identité et documents professionnels : envoi par l'utilisateur, contrôle par un
 * modérateur / administrateur, qui approuve ou refuse avec un motif. Les fichiers restent privés.
 */
class VerificationService
{
    public const DOC_TYPES = [
        'CNI' => 'Carte d\'identité nationale', 'PASSEPORT' => 'Passeport', 'CARTE_CONSULAIRE' => 'Carte consulaire',
        'CARTE_ETUDIANT' => 'Carte d\'étudiant', 'PERMIS_CONDUIRE' => 'Permis de conduire',
        'ATTESTATION' => 'Carte ou attestation professionnelle', 'DIPLOME' => 'Diplôme / certificat',
        'RCCM' => 'Registre du commerce (RCCM)', 'NIF' => 'Numéro d\'identification fiscale (NIF)', 'AUTORISATION' => 'Autorisation / agrément',
    ];
    private const KIND_BY_DOC = [
        'CNI' => 'IDENTITE', 'PASSEPORT' => 'IDENTITE', 'CARTE_CONSULAIRE' => 'IDENTITE', 'CARTE_ETUDIANT' => 'IDENTITE', 'PERMIS_CONDUIRE' => 'IDENTITE',
        'ATTESTATION' => 'QUALIFICATION', 'DIPLOME' => 'QUALIFICATION', 'AUTORISATION' => 'QUALIFICATION',
        'RCCM' => 'ENTREPRISE', 'NIF' => 'ENTREPRISE',
    ];
    private const TARGET_LEVEL = ['IDENTITE' => 2, 'QUALIFICATION' => 3, 'ENTREPRISE' => 2];

    public function __construct(
        private readonly Database $db,
        private readonly FileStorageService $files,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string, mixed>|null $upload */
    public function submit(int $userId, string $docType, ?array $upload, ?string $numberLast4 = null): int
    {
        if (!isset(self::DOC_TYPES[$docType])) {
            throw new ValidationException(['doc_type' => ['Type de document inconnu.']]);
        }
        $kind = self::KIND_BY_DOC[$docType];
        $businessId = null;
        if ($kind === 'ENTREPRISE') {
            $businessId = $this->db->fetchValue("SELECT business_id FROM business_members WHERE user_id = ? AND member_role IN ('OWNER','MANAGER') AND status = 'ACTIVE' LIMIT 1", [$userId]);
            if ($businessId === null) {
                throw new ValidationException(['doc_type' => ['Ce document concerne une organisation : votre compte n\'est rattaché à aucune organisation.']]);
            }
        }
        $pending = (int) $this->db->fetchValue("SELECT COUNT(*) FROM verification_requests WHERE user_id = ? AND status IN ('SOUMISE','EN_EXAMEN')", [$userId]);
        if ($pending >= 5) {
            throw new ValidationException(['document' => ['Vous avez déjà plusieurs documents en attente de vérification.']]);
        }
        $fileId = $this->files->storePrivate($upload, $userId, $kind === 'ENTREPRISE' ? 'BUSINESS_DOC' : ($kind === 'IDENTITE' ? 'IDENTITY' : 'QUALIFICATION'));
        $requestId = $this->db->transaction(function (Database $db) use ($userId, $businessId, $kind, $docType, $fileId, $numberLast4) {
            $id = $db->insert('verification_requests', [
                'user_id' => $userId, 'business_id' => $businessId !== null ? (int) $businessId : null,
                'target_level' => self::TARGET_LEVEL[$kind], 'kind' => $kind,
            ]);
            $db->insert('verification_documents', [
                'request_id' => $id, 'doc_type' => $docType, 'file_id' => $fileId,
                'document_number_last4' => $numberLast4 !== null && $numberLast4 !== '' ? mb_substr(preg_replace('/\s+/', '', $numberLast4) ?? '', -4) : null,
            ]);
            if ($businessId !== null) {
                $db->query("UPDATE businesses SET verification_status = 'EN_COURS' WHERE id = ? AND verification_status <> 'VERIFIEE'", [(int) $businessId]);
            }
            return $id;
        });
        foreach ($this->db->fetchAll(
            "SELECT DISTINCT ur.user_id FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id WHERE p.code = 'users.verify'"
        ) as $staff) {
            $this->notifications->notify((int) $staff['user_id'], 'admin.verification_pending', 'Document à vérifier', self::DOC_TYPES[$docType] . ' envoyé par un utilisateur.', '/admin/verifications');
        }
        return $requestId;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.kind, r.status, r.decision_note, r.submitted_at, r.decided_at, d.doc_type, f.uuid AS file_uuid
             FROM verification_requests r JOIN verification_documents d ON d.request_id = r.id JOIN stored_files f ON f.id = d.file_id
             WHERE r.user_id = ? ORDER BY r.id DESC',
            [$userId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function queue(string $status = 'pending', int $limit = 100): array
    {
        $where = $status === 'pending' ? "r.status IN ('SOUMISE','EN_EXAMEN','INFOS_DEMANDEES')" : "r.status IN ('APPROUVEE','REFUSEE')";
        return $this->db->fetchAll(
            "SELECT r.id, r.kind, r.status, r.submitted_at, r.decided_at, r.decision_note, d.doc_type, d.document_number_last4,
                    f.uuid AS file_uuid, f.mime_type, u.uuid AS user_uuid, u.first_name, u.last_name, u.phone, u.primary_type,
                    b.name AS business_name, pr.verification_level, CONCAT(rv.first_name, ' ', rv.last_name) AS reviewer
             FROM verification_requests r JOIN verification_documents d ON d.request_id = r.id JOIN stored_files f ON f.id = d.file_id
             JOIN users u ON u.id = r.user_id LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN businesses b ON b.id = r.business_id LEFT JOIN users rv ON rv.id = r.reviewer_id
             WHERE {$where} ORDER BY r.submitted_at " . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(500, $limit))
        );
    }

    public function decide(int $adminId, int $requestId, bool $approve, ?string $note): void
    {
        if (!$approve && ($note === null || mb_strlen(trim($note)) < 5)) {
            throw new ValidationException(['note' => ['Indiquez le motif du refus (il sera communiqué à l\'utilisateur).']]);
        }
        $r = $this->db->fetch('SELECT * FROM verification_requests WHERE id = ?', [$requestId]) ?? throw new HttpException(404);
        if (!in_array($r['status'], ['SOUMISE', 'EN_EXAMEN', 'INFOS_DEMANDEES'], true)) {
            throw new ValidationException(['note' => ['Cette demande a déjà été traitée.']]);
        }
        if ((int) $r['user_id'] === $adminId) {
            throw new HttpException(403, 'Vous ne pouvez pas valider vos propres documents.');
        }
        $this->db->transaction(function (Database $db) use ($r, $approve, $note, $adminId) {
            $status = $approve ? 'APPROUVEE' : 'REFUSEE';
            $db->query('UPDATE verification_requests SET status = ?, reviewer_id = ?, decision_note = ?, decided_at = NOW() WHERE id = ?', [$status, $adminId, $note ?: null, (int) $r['id']]);
            $db->query("UPDATE verification_documents SET status = ?, purge_after = CURDATE() + INTERVAL 90 DAY WHERE request_id = ?", [$approve ? 'ACCEPTE' : 'REFUSE', (int) $r['id']]);
            if ($approve) {
                if ($r['kind'] === 'ENTREPRISE' && $r['business_id'] !== null) {
                    $db->query("UPDATE businesses SET verification_status = 'VERIFIEE', verified_at = NOW() WHERE id = ?", [(int) $r['business_id']]);
                } else {
                    $db->query('UPDATE user_profiles SET verification_level = GREATEST(verification_level, ?) WHERE user_id = ?', [(int) $r['target_level'], (int) $r['user_id']]);
                    if ($r['kind'] === 'IDENTITE') {
                        $db->query('UPDATE users SET age_verified_at = NOW() WHERE id = ?', [(int) $r['user_id']]);
                    }
                }
            } elseif ($r['kind'] === 'ENTREPRISE' && $r['business_id'] !== null) {
                $db->query("UPDATE businesses SET verification_status = 'REFUSEE' WHERE id = ? AND verification_status <> 'VERIFIEE'", [(int) $r['business_id']]);
            }
        });
        $this->audit->record($adminId, $approve ? 'verification.approved' : 'verification.rejected', 'verification_request', (int) $r['id'], ['status' => $r['status']], ['status' => $approve ? 'APPROUVEE' : 'REFUSEE'], $note);
        $this->notifications->notify(
            (int) $r['user_id'],
            $approve ? 'verification.approved' : 'verification.rejected',
            $approve ? 'Document vérifié ✔' : 'Document refusé',
            $approve ? 'Votre document a été vérifié par l\'équipe JOB NIGER : le badge « Profil vérifié » apparaît sur votre profil.'
                     : 'Motif : ' . $note . '. Vous pouvez envoyer un nouveau document.',
            '/verification'
        );
    }
}
