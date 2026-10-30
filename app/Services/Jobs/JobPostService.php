<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\SettingRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PermissionService;
use App\Services\Notifications\NotificationService;
use App\Support\Money;
use App\Support\References;
use App\Support\Uuid;

/** Offres d'emploi / de mission publiées par les recruteurs. */
class JobPostService
{
    public const OPEN_STATUSES = ['PUBLIEE', 'CANDIDATURES_OUVERTES'];
    public const PAY_UNITS = ['HEURE' => 'par heure', 'JOUR' => 'par jour', 'TACHE' => 'par tâche', 'FORFAIT' => 'forfait', 'MOIS' => 'par mois'];

    public function __construct(
        private readonly Database $db,
        private readonly SettingRepository $settings,
        private readonly PermissionService $permissions,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $d données validées
     * @return array{uuid: string, status: string}
     */
    public function create(int $userId, array $d): array
    {
        if (!$this->permissions->hasRole($userId, 'CLIENT')) {
            throw new HttpException(403, 'Seuls les comptes recruteurs peuvent publier une offre.');
        }
        if ($this->settings->get('jobs.require_verified_recruiter', false) && !$this->isVerifiedRecruiter($userId)) {
            throw new ValidationException(['title' => ['Vos documents doivent être vérifiés par l\'équipe avant de publier une offre (menu « Vérification »).']], $d);
        }
        $sub = $this->db->fetch('SELECT id, category_id, risk_level, min_age, required_verification_level FROM job_subcategories WHERE id = ? AND is_active = 1', [(int) $d['subcategory_id']]);
        if ($sub === null) {
            throw new ValidationException(['subcategory_id' => ['Choisissez un métier dans la liste.']], $d);
        }
        if (!$this->db->fetchValue('SELECT COUNT(*) FROM cities WHERE id = ? AND is_active = 1', [(int) $d['city_id']])) {
            throw new ValidationException(['city_id' => ['Choisissez une ville dans la liste.']], $d);
        }
        // « par mois » est stocké comme un forfait mensuel (unité JOUR × 30 serait trompeuse)
        $payUnit = $d['pay_unit'] === 'MOIS' ? 'FORFAIT' : $d['pay_unit'];
        $rate = Money::parse((string) $d['pay_rate']);
        $min = Money::parse((string) $this->settings->get('finance.min_mission_amount', '1000'));
        $max = Money::parse((string) $this->settings->get('finance.max_mission_amount', '5000000'));
        if ($rate->amount() < $min->amount() || $rate->amount() > $max->amount()) {
            throw new ValidationException(['pay_rate' => ['La rémunération doit être comprise entre ' . $min->format() . ' et ' . $max->format() . '.']], $d);
        }
        $start = (string) $d['start_date'];
        if ($start < date('Y-m-d')) {
            throw new ValidationException(['start_date' => ['La date de début ne peut pas être dans le passé.']], $d);
        }
        $end = $d['end_date'] ?: date('Y-m-d', strtotime($start . ' +1 year'));
        if ($end < $start) {
            throw new ValidationException(['end_date' => ['La date de fin doit être après la date de début.']], $d);
        }
        $deadline = $d['application_deadline'] ?: min($start, date('Y-m-d', strtotime('+30 days')));
        if ($deadline < date('Y-m-d')) {
            $deadline = date('Y-m-d');
        }

        [$status, $flags] = $this->moderate($d['title'] . ' ' . $d['description'], (string) $sub['risk_level']);
        $businessId = $this->db->fetchValue(
            "SELECT business_id FROM business_members WHERE user_id = ? AND status = 'ACTIVE' AND member_role IN ('OWNER','MANAGER','RECRUITER') ORDER BY business_id LIMIT 1",
            [$userId]
        );
        $missionType = (int) ($this->db->fetchValue('SELECT id FROM mission_types WHERE code = ?', [$d['contract_type']]) ?? 0);
        if ($missionType === 0) {
            throw new ValidationException(['contract_type' => ['Type de contrat inconnu.']], $d);
        }

        $uuid = Uuid::v4();
        $postId = $this->db->transaction(function (Database $db) use ($d, $sub, $uuid, $userId, $businessId, $payUnit, $rate, $start, $end, $deadline, $status, $flags, $missionType) {
            $id = $db->insert('job_posts', [
                'uuid' => $uuid,
                'reference' => References::next($db, 'A'),
                'client_user_id' => $userId,
                'business_id' => $businessId !== null ? (int) $businessId : null,
                'category_id' => (int) $sub['category_id'],
                'subcategory_id' => (int) $sub['id'],
                'mission_type_id' => $missionType,
                'title' => $d['title'],
                'description' => $d['description'],
                'city_id' => (int) $d['city_id'],
                'neighborhood_id' => $d['neighborhood_id'] ?: null,
                'address_private' => $d['address'] ?: null,
                'start_date' => $start,
                'end_date' => $end,
                'daily_start_time' => $d['daily_start_time'] ?: null,
                'daily_end_time' => $d['daily_end_time'] ?: null,
                'schedule_notes' => $d['schedule_notes'] ?: null,
                'application_deadline' => $deadline . ' 23:59:59',
                'positions_total' => (int) $d['positions'],
                'pay_unit' => $payUnit,
                'pay_rate' => $rate->toDecimal(),
                'estimated_units' => '1',
                'estimated_amount' => $rate->toDecimal(),
                'is_negotiable' => 0,
                'payment_mode' => 'DIRECT',
                'payment_terms' => $d['pay_unit'] === 'MOIS' ? 'Salaire mensuel' : null,
                'cancellation_terms' => 'Annulation possible jusqu\'à 24 heures avant le début, en prévenant via l\'application.',
                'min_age' => max(18, (int) $sub['min_age']),
                'required_verification_level' => (int) $sub['required_verification_level'],
                'status' => $status,
                'moderation_flags' => $flags ?: null,
                'published_at' => $status === 'CANDIDATURES_OUVERTES' ? date('Y-m-d H:i:s') : null,
            ]);
            $db->insert('job_post_status_history', ['job_post_id' => $id, 'to_status' => $status, 'actor_user_id' => $userId]);
            return $id;
        });
        $this->audit->record($userId, 'job_post.created', 'job_post', $postId, null, ['status' => $status]);
        return ['uuid' => $uuid, 'status' => $status];
    }

    /** @return array{0: string, 1: string} statut initial et mots-clés détectés */
    private function moderate(string $text, string $risk): array
    {
        $flags = [];
        $mandatory = in_array($risk, ['HIGH', 'REGULATED'], true) || (bool) $this->settings->get('jobs.moderation_required', false);
        foreach ($this->db->fetchAll("SELECT pattern, action, reason FROM moderation_rules WHERE applies_to = 'ANNONCE' AND is_active = 1") as $rule) {
            if (mb_stripos($text, (string) $rule['pattern']) !== false) {
                if ($rule['action'] === 'BLOQUER') {
                    throw new ValidationException(['description' => ['Annonce refusée : ' . ($rule['reason'] ?: 'contenu interdit') . '. Aucune offre ne doit demander d\'argent aux candidats.']]);
                }
                $flags[] = (string) $rule['pattern'];
                $mandatory = true;
            }
        }
        return [$mandatory ? 'EN_MODERATION' : 'CANDIDATURES_OUVERTES', implode(', ', $flags)];
    }

    public function isVerifiedRecruiter(int $userId): bool
    {
        $level = (int) $this->db->fetchValue('SELECT verification_level FROM user_profiles WHERE user_id = ?', [$userId]);
        $business = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM businesses b JOIN business_members m ON m.business_id = b.id WHERE m.user_id = ? AND b.verification_status = 'VERIFIEE'",
            [$userId]
        );
        return $level >= 2 || $business > 0;
    }

    /**
     * Recherche publique des offres ouvertes.
     * @param array{q?: string, city?: int, category?: int} $f
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function search(array $f, int $page = 1, int $perPage = 15): array
    {
        $where = ["p.status IN ('PUBLIEE','CANDIDATURES_OUVERTES')", 'p.application_deadline >= NOW()', 'p.positions_filled < p.positions_total'];
        $params = [];
        if (!empty($f['q'])) {
            $where[] = '(p.title LIKE ? OR p.description LIKE ? OR s.name LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $f['q']) . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($f['city'])) {
            $where[] = 'p.city_id = ?';
            $params[] = (int) $f['city'];
        }
        if (!empty($f['category'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int) $f['category'];
        }
        $sqlWhere = implode(' AND ', $where);
        $from = 'FROM job_posts p JOIN job_subcategories s ON s.id = p.subcategory_id JOIN users u ON u.id = p.client_user_id
                 LEFT JOIN businesses b ON b.id = p.business_id LEFT JOIN cities c ON c.id = p.city_id
                 LEFT JOIN neighborhoods n ON n.id = p.neighborhood_id JOIN mission_types mt ON mt.id = p.mission_type_id';
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from} WHERE {$sqlWhere}", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $rows = $this->db->fetchAll(
            "SELECT p.uuid, p.reference, p.title, p.description, p.pay_unit, p.pay_rate, p.payment_terms, p.start_date, p.end_date,
                    p.positions_total, p.positions_filled, p.published_at, p.application_deadline, p.is_sponsored,
                    s.name AS trade, c.name AS city, n.name AS neighborhood, mt.name AS contract_type,
                    COALESCE(b.name, CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.')) AS recruiter,
                    (b.verification_status = 'VERIFIEE' OR (SELECT verification_level FROM user_profiles WHERE user_id = u.id) >= 2) AS recruiter_verified
             {$from} WHERE {$sqlWhere}
             ORDER BY p.is_sponsored DESC, p.published_at DESC, p.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array<string, mixed>|null offre complète (adresse privée incluse : à n'afficher qu'aux parties) */
    public function findByUuid(string $uuid): ?array
    {
        return $this->db->fetch(
            "SELECT p.*, s.name AS trade, s.risk_level, cat.name AS category, c.name AS city, n.name AS neighborhood, mt.name AS contract_type, mt.code AS contract_code,
                    u.first_name AS recruiter_first_name, u.last_name AS recruiter_last_name, b.name AS business_name,
                    COALESCE(b.name, CONCAT(u.first_name, ' ', LEFT(u.last_name, 1), '.')) AS recruiter,
                    (b.verification_status = 'VERIFIEE' OR (SELECT verification_level FROM user_profiles WHERE user_id = u.id) >= 2) AS recruiter_verified
             FROM job_posts p JOIN job_subcategories s ON s.id = p.subcategory_id JOIN job_categories cat ON cat.id = p.category_id
             JOIN users u ON u.id = p.client_user_id LEFT JOIN businesses b ON b.id = p.business_id
             LEFT JOIN cities c ON c.id = p.city_id LEFT JOIN neighborhoods n ON n.id = p.neighborhood_id
             JOIN mission_types mt ON mt.id = p.mission_type_id
             WHERE p.uuid = ?",
            [$uuid]
        );
    }

    /** @return array<string, mixed> offre appartenant au recruteur, sinon 404 (aucune fuite d'existence) */
    public function ownedBy(string $uuid, int $userId): array
    {
        $post = $this->findByUuid($uuid);
        if ($post === null || (int) $post['client_user_id'] !== $userId) {
            throw new HttpException(404);
        }
        return $post;
    }

    /** @return list<array<string, mixed>> */
    public function forRecruiter(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT p.uuid, p.reference, p.title, p.status, p.positions_total, p.positions_filled, p.created_at, p.application_deadline,
                    c.name AS city,
                    (SELECT COUNT(*) FROM job_applications a WHERE a.job_post_id = p.id) AS applications,
                    (SELECT COUNT(*) FROM job_applications a WHERE a.job_post_id = p.id AND a.status IN ('SOUMISE')) AS new_applications
             FROM job_posts p LEFT JOIN cities c ON c.id = p.city_id
             WHERE p.client_user_id = ? ORDER BY p.id DESC",
            [$userId]
        );
    }

    public function isOpen(array $post): bool
    {
        return in_array($post['status'], self::OPEN_STATUSES, true)
            && strtotime((string) $post['application_deadline']) >= time()
            && (int) $post['positions_filled'] < (int) $post['positions_total'];
    }

    public function setStatus(int $postId, string $status, ?int $actorId, ?string $reason = null): void
    {
        $this->db->transaction(function (Database $db) use ($postId, $status, $actorId, $reason) {
            $old = (string) $db->fetchValue('SELECT status FROM job_posts WHERE id = ? FOR UPDATE', [$postId]);
            $extra = $status === 'CANDIDATURES_OUVERTES' ? ', published_at = COALESCE(published_at, NOW()), closed_at = NULL' : (in_array($status, ['ANNULEE', 'TERMINEE', 'REFUSEE', 'EXPIREE'], true) ? ', closed_at = NOW()' : '');
            $db->query("UPDATE job_posts SET status = ?{$extra} WHERE id = ?", [$status, $postId]);
            $db->insert('job_post_status_history', ['job_post_id' => $postId, 'from_status' => $old, 'to_status' => $status, 'actor_user_id' => $actorId, 'reason' => $reason]);
        });
    }

    public static function payLabel(array $post): string
    {
        $unit = ($post['payment_terms'] ?? '') === 'Salaire mensuel' ? 'MOIS' : (string) $post['pay_unit'];
        return money((string) $post['pay_rate']) . ' ' . (self::PAY_UNITS[$unit] ?? '');
    }
}
