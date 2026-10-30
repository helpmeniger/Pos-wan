<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Services\Notifications\NotificationService;
use App\Support\Uuid;

/** Entretiens en ligne (lien de visioconférence) ou en présentiel (adresse). */
class InterviewService
{
    public const LABELS = [
        'PROPOSE' => 'Proposé — en attente de votre réponse', 'CONFIRME' => 'Confirmé', 'REPORT_DEMANDE' => 'Report demandé',
        'ANNULE' => 'Annulé', 'CONCLUANT' => 'Concluant', 'NON_CONCLUANT' => 'Non concluant',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ApplicationService $applications,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @param array<string, mixed> $d */
    public function schedule(int $recruiterId, string $applicationUuid, array $d): string
    {
        $a = $this->applications->findForParty($applicationUuid, $recruiterId);
        if ((int) $a['client_user_id'] !== $recruiterId) {
            throw new HttpException(404);
        }
        if (!in_array($a['status'], ApplicationService::ACTIVE, true)) {
            throw new ValidationException(['date' => ['Cette candidature n\'est plus active.']]);
        }
        $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $d['date'] . ' ' . $d['time'], new \DateTimeZone('Africa/Niamey'));
        if ($at === false || $at < new \DateTimeImmutable('now', new \DateTimeZone('Africa/Niamey'))) {
            throw new ValidationException(['date' => ['Choisissez une date et une heure à venir.']], $d);
        }
        $link = null;
        $location = null;
        if ($d['mode'] === 'EN_LIGNE') {
            $link = trim((string) $d['meeting_link']);
            if (!preg_match('#^https://[^\s<>"]{4,490}$#i', $link)) {
                throw new ValidationException(['meeting_link' => ['Indiquez un lien de réunion commençant par https:// (Google Meet, Zoom, WhatsApp…).']], $d);
            }
        } else {
            $location = trim((string) $d['location']);
            if (mb_strlen($location) < 4) {
                throw new ValidationException(['location' => ['Indiquez l\'adresse du rendez-vous.']], $d);
            }
        }
        $uuid = Uuid::v4();
        $this->db->transaction(function (Database $db) use ($a, $recruiterId, $d, $at, $link, $location, $uuid) {
            // Un seul entretien en cours par candidature : le précédent est annulé
            $db->query("UPDATE interviews SET status = 'ANNULE' WHERE application_id = ? AND status IN ('PROPOSE','CONFIRME','REPORT_DEMANDE')", [(int) $a['id']]);
            $db->insert('interviews', [
                'uuid' => $uuid, 'application_id' => (int) $a['id'], 'job_post_id' => (int) $a['post_id'],
                'recruiter_user_id' => $recruiterId, 'candidate_user_id' => (int) $a['worker_user_id'],
                'mode' => $d['mode'], 'scheduled_at' => $at->format('Y-m-d H:i:s'),
                'duration_minutes' => (int) $d['duration'], 'meeting_link' => $link, 'location' => $location,
                'notes' => $d['notes'] ?: null,
            ]);
            $this->applications->setStatus((int) $a['id'], 'PRESELECTIONNEE', $recruiterId);
        });
        $this->notifications->notify(
            (int) $a['worker_user_id'],
            'interview.proposed',
            'Entretien proposé : ' . $a['post_title'],
            ($d['mode'] === 'EN_LIGNE' ? 'Entretien en ligne' : 'Entretien en présentiel') . ' le ' . $at->format('d/m/Y à H:i') . '. Merci de confirmer votre présence.',
            '/candidatures'
        );
        return $uuid;
    }

    /** @return array<string, mixed> */
    private function findForParty(string $uuid, int $userId): array
    {
        $i = $this->db->fetch(
            'SELECT i.*, p.title AS post_title, p.uuid AS post_uuid, a.uuid AS application_uuid FROM interviews i
             JOIN job_posts p ON p.id = i.job_post_id JOIN job_applications a ON a.id = i.application_id WHERE i.uuid = ?',
            [$uuid]
        );
        if ($i === null || ((int) $i['candidate_user_id'] !== $userId && (int) $i['recruiter_user_id'] !== $userId)) {
            throw new HttpException(404);
        }
        return $i;
    }

    /** Réponse du candidat : confirmer, ou demander un autre créneau. */
    public function respond(int $candidateId, string $uuid, string $action, ?string $note): void
    {
        $i = $this->findForParty($uuid, $candidateId);
        if ((int) $i['candidate_user_id'] !== $candidateId || !in_array($i['status'], ['PROPOSE', 'REPORT_DEMANDE'], true)) {
            throw new ValidationException(['note' => ['Cet entretien ne peut plus être modifié.']]);
        }
        $status = $action === 'confirm' ? 'CONFIRME' : 'REPORT_DEMANDE';
        $this->db->query('UPDATE interviews SET status = ?, candidate_note = ? WHERE id = ?', [$status, $note ?: null, (int) $i['id']]);
        $this->notifications->notify(
            (int) $i['recruiter_user_id'],
            'interview.' . strtolower($status),
            $status === 'CONFIRME' ? 'Entretien confirmé par le candidat' : 'Le candidat demande un autre créneau',
            ($note ? '« ' . $note . ' » — ' : '') . $i['post_title'],
            '/recruteur/offres/' . $i['post_uuid']
        );
    }

    /** Résultat saisi par le recruteur après l'entretien (ou annulation). */
    public function conclude(int $recruiterId, string $uuid, string $result, ?string $note): void
    {
        $i = $this->findForParty($uuid, $recruiterId);
        if ((int) $i['recruiter_user_id'] !== $recruiterId || !in_array($result, ['CONCLUANT', 'NON_CONCLUANT', 'ANNULE'], true)
            || in_array($i['status'], ['ANNULE', 'CONCLUANT', 'NON_CONCLUANT'], true)) {
            throw new ValidationException(['note' => ['Action impossible sur cet entretien.']]);
        }
        $this->db->query('UPDATE interviews SET status = ?, result_note = ? WHERE id = ?', [$result, $note ?: null, (int) $i['id']]);
        $texts = [
            'CONCLUANT' => ['Entretien concluant', 'Bonne nouvelle : votre entretien pour « ' . $i['post_title'] . ' » est concluant. Le recruteur va vous proposer un contrat.'],
            'NON_CONCLUANT' => ['Résultat de l\'entretien', 'Votre entretien pour « ' . $i['post_title'] . ' » n\'a pas abouti cette fois-ci.'],
            'ANNULE' => ['Entretien annulé', 'L\'entretien prévu pour « ' . $i['post_title'] . ' » est annulé.'],
        ];
        $this->notifications->notify((int) $i['candidate_user_id'], 'interview.' . strtolower($result), $texts[$result][0], $texts[$result][1], '/candidatures');
    }

    /** @return list<array<string, mixed>> entretiens à venir d'un utilisateur (candidat ou recruteur) */
    public function upcoming(int $userId, int $limit = 5): array
    {
        return $this->db->fetchAll(
            "SELECT i.uuid, i.mode, i.scheduled_at, i.status, i.meeting_link, i.location, p.title, p.uuid AS post_uuid,
                    (i.recruiter_user_id = ?) AS as_recruiter, CONCAT(c.first_name, ' ', c.last_name) AS candidate_name
             FROM interviews i JOIN job_posts p ON p.id = i.job_post_id JOIN users c ON c.id = i.candidate_user_id
             WHERE (i.candidate_user_id = ? OR i.recruiter_user_id = ?) AND i.status IN ('PROPOSE','CONFIRME','REPORT_DEMANDE')
               AND i.scheduled_at >= NOW() - INTERVAL 2 HOUR
             ORDER BY i.scheduled_at LIMIT " . max(1, min(50, $limit)),
            [$userId, $userId, $userId]
        );
    }
}
