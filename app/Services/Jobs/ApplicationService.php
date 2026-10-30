<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\SettingRepository;
use App\Services\Auth\PermissionService;
use App\Services\Notifications\NotificationService;
use App\Support\Uuid;

/** Candidatures : postuler en un clic, suivi côté candidat et côté recruteur. */
class ApplicationService
{
    public const ACTIVE = ['SOUMISE', 'VUE', 'PRESELECTIONNEE', 'INFOS_DEMANDEES', 'EN_NEGOCIATION'];
    public const LABELS = [
        'SOUMISE' => 'Envoyée', 'VUE' => 'Vue par le recruteur', 'PRESELECTIONNEE' => 'Entretien',
        'INFOS_DEMANDEES' => 'Informations demandées', 'EN_NEGOCIATION' => 'En discussion', 'ACCEPTEE' => 'Retenue',
        'REFUSEE' => 'Non retenue', 'RETIREE' => 'Retirée', 'EXPIREE' => 'Expirée',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly JobPostService $posts,
        private readonly PermissionService $permissions,
        private readonly SettingRepository $settings,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return string uuid de la candidature */
    public function apply(int $workerId, string $postUuid, ?string $message, ?string $birthDate): string
    {
        $post = $this->posts->findByUuid($postUuid) ?? throw new HttpException(404);
        if (!$this->permissions->hasRole($workerId, 'WORKER')) {
            throw new HttpException(403, 'Seuls les comptes « je cherche du travail » peuvent postuler.');
        }
        if ((int) $post['client_user_id'] === $workerId) {
            throw new ValidationException(['message' => ['Vous ne pouvez pas postuler à votre propre offre.']]);
        }
        if (!$this->posts->isOpen($post)) {
            throw new ValidationException(['message' => ['Cette offre n\'accepte plus de candidatures.']]);
        }
        $user = $this->db->fetch('SELECT u.birth_date, p.verification_level FROM users u JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?', [$workerId]);
        if (empty($user['birth_date'])) {
            if ($birthDate === null || $birthDate === '') {
                throw new ValidationException(['birth_date' => ['Indiquez votre date de naissance (demandée une seule fois, jamais affichée publiquement).']]);
            }
            $this->db->query('UPDATE users SET birth_date = ? WHERE id = ? AND birth_date IS NULL', [$birthDate, $workerId]);
            $user['birth_date'] = $birthDate;
        }
        $age = (new \DateTimeImmutable((string) $user['birth_date']))->diff(new \DateTimeImmutable('today'))->y;
        if ($age < (int) $post['min_age']) {
            throw new ValidationException(['message' => ["Cette offre est réservée aux personnes d'au moins {$post['min_age']} ans."]]);
        }
        $level = (int) $user['verification_level'];
        if ($level < (int) $post['required_verification_level']
            || ($this->settings->get('applications.require_verified_worker', false) && $level < 2)) {
            throw new ValidationException(['message' => ['Cette offre exige un profil vérifié : envoyez votre pièce d\'identité depuis le menu « Vérification ».']]);
        }
        if ($this->db->fetchValue('SELECT id FROM job_applications WHERE job_post_id = ? AND worker_user_id = ?', [(int) $post['id'], $workerId]) !== null) {
            throw new ValidationException(['message' => ['Vous avez déjà postulé à cette offre.']]);
        }
        $uuid = Uuid::v4();
        $this->db->insert('job_applications', [
            'uuid' => $uuid,
            'job_post_id' => (int) $post['id'],
            'worker_user_id' => $workerId,
            'message' => $message !== null && $message !== '' ? mb_substr($message, 0, 2000) : null,
        ]);
        $this->notifications->notify(
            (int) $post['client_user_id'],
            'application.new',
            'Nouvelle candidature : ' . $post['title'],
            'Un candidat vient de postuler à votre offre ' . $post['reference'] . '.',
            '/recruteur/offres/' . $post['uuid']
        );
        return $uuid;
    }

    /** Candidature + offre, accessible seulement au candidat ou au recruteur de l'offre. @return array<string, mixed> */
    public function findForParty(string $uuid, int $userId): array
    {
        $a = $this->db->fetch(
            'SELECT a.*, p.uuid AS post_uuid, p.title AS post_title, p.reference AS post_reference, p.client_user_id,
                    p.positions_total, p.positions_filled, p.id AS post_id
             FROM job_applications a JOIN job_posts p ON p.id = a.job_post_id WHERE a.uuid = ?',
            [$uuid]
        );
        if ($a === null || ((int) $a['worker_user_id'] !== $userId && (int) $a['client_user_id'] !== $userId)) {
            throw new HttpException(404);
        }
        return $a;
    }

    public function withdraw(int $workerId, string $uuid): void
    {
        $a = $this->findForParty($uuid, $workerId);
        if ((int) $a['worker_user_id'] !== $workerId || !in_array($a['status'], self::ACTIVE, true)) {
            throw new ValidationException(['message' => ['Cette candidature ne peut plus être retirée.']]);
        }
        $this->setStatus((int) $a['id'], 'RETIREE', $workerId);
        $this->notifications->notify((int) $a['client_user_id'], 'application.withdrawn', 'Candidature retirée', 'Un candidat a retiré sa candidature à « ' . $a['post_title'] . ' ».', '/recruteur/offres/' . $a['post_uuid']);
    }

    public function refuse(int $recruiterId, string $uuid, ?string $note): void
    {
        $a = $this->findForParty($uuid, $recruiterId);
        if ((int) $a['client_user_id'] !== $recruiterId || !in_array($a['status'], self::ACTIVE, true)) {
            throw new ValidationException(['message' => ['Cette candidature ne peut plus être refusée.']]);
        }
        $this->setStatus((int) $a['id'], 'REFUSEE', $recruiterId, $note);
        $this->notifications->notify((int) $a['worker_user_id'], 'application.refused', 'Candidature non retenue', 'Votre candidature à « ' . $a['post_title'] . ' » n\'a pas été retenue. Ne vous découragez pas : d\'autres offres vous attendent !', '/candidatures');
    }

    public function setStatus(int $applicationId, string $status, ?int $actorId, ?string $note = null): void
    {
        $this->db->query(
            'UPDATE job_applications SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = COALESCE(?, decision_note) WHERE id = ?',
            [$status, $actorId, $note, $applicationId]
        );
    }

    /** Le recruteur ouvre la liste : les nouvelles candidatures passent « vues ». */
    public function markViewed(int $postId): void
    {
        $this->db->query("UPDATE job_applications SET status = 'VUE' WHERE job_post_id = ? AND status = 'SOUMISE'", [$postId]);
    }

    /** @return list<array<string, mixed>> candidatures d'une offre avec le profil des candidats */
    public function forPost(int $postId): array
    {
        return $this->db->fetchAll(
            "SELECT a.uuid, a.status, a.message, a.created_at, u.uuid AS user_uuid, u.first_name, u.last_name, u.phone, u.birth_date,
                    c.name AS city, pr.verification_level, pr.rating_avg, pr.rating_count, pr.missions_completed, pr.bio, pr.headline,
                    (SELECT GROUP_CONCAT(s.name ORDER BY wt.is_primary DESC SEPARATOR ', ') FROM worker_trades wt JOIN job_subcategories s ON s.id = wt.subcategory_id WHERE wt.user_id = u.id) AS trades,
                    (SELECT i.uuid FROM interviews i WHERE i.application_id = a.id ORDER BY i.id DESC LIMIT 1) AS interview_uuid,
                    (SELECT i.status FROM interviews i WHERE i.application_id = a.id ORDER BY i.id DESC LIMIT 1) AS interview_status,
                    (SELECT i.scheduled_at FROM interviews i WHERE i.application_id = a.id ORDER BY i.id DESC LIMIT 1) AS interview_at,
                    (SELECT e.uuid FROM employments e WHERE e.application_id = a.id ORDER BY e.id DESC LIMIT 1) AS employment_uuid,
                    (SELECT e.status FROM employments e WHERE e.application_id = a.id ORDER BY e.id DESC LIMIT 1) AS employment_status
             FROM job_applications a JOIN users u ON u.id = a.worker_user_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id LEFT JOIN cities c ON c.id = u.city_id
             WHERE a.job_post_id = ? ORDER BY FIELD(a.status, 'SOUMISE','VUE','PRESELECTIONNEE','INFOS_DEMANDEES','EN_NEGOCIATION','ACCEPTEE','REFUSEE','RETIREE','EXPIREE'), a.id",
            [$postId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function forWorker(int $workerId): array
    {
        return $this->db->fetchAll(
            "SELECT a.uuid, a.status, a.created_at, a.decision_note, p.uuid AS post_uuid, p.title, p.reference, c.name AS city,
                    COALESCE(b.name, CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.')) AS recruiter,
                    i.uuid AS interview_uuid, i.mode AS interview_mode, i.scheduled_at AS interview_at, i.status AS interview_status,
                    i.meeting_link, i.location AS interview_location, i.duration_minutes, i.notes AS interview_notes,
                    (SELECT e.uuid FROM employments e WHERE e.application_id = a.id ORDER BY e.id DESC LIMIT 1) AS employment_uuid,
                    (SELECT e.status FROM employments e WHERE e.application_id = a.id ORDER BY e.id DESC LIMIT 1) AS employment_status
             FROM job_applications a JOIN job_posts p ON p.id = a.job_post_id JOIN users u ON u.id = p.client_user_id
             LEFT JOIN businesses b ON b.id = p.business_id LEFT JOIN cities c ON c.id = p.city_id
             LEFT JOIN interviews i ON i.id = (SELECT MAX(i2.id) FROM interviews i2 WHERE i2.application_id = a.id)
             WHERE a.worker_user_id = ? ORDER BY a.id DESC",
            [$workerId]
        );
    }

    public function hasApplied(int $workerId, int $postId): ?string
    {
        $v = $this->db->fetchValue('SELECT uuid FROM job_applications WHERE job_post_id = ? AND worker_user_id = ?', [$postId, $workerId]);
        return $v !== null ? (string) $v : null;
    }
}
