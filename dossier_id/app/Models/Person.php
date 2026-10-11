<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Services\Access;
use App\Support\Labels;
use App\Support\Normalizer;
use App\Support\Paginator;
use App\Support\Validator;

final class Person
{
    /** Champs modifiables par formulaire. */
    public const FIELDS = [
        'last_name', 'first_name', 'other_names', 'birth_date', 'declared_age', 'birth_place', 'sex',
        'nationality', 'profession', 'admin_id_type', 'admin_id_number', 'admin_id_verified',
        'registration_date', 'registration_place', 'registering_service', 'access_level',
    ];

    public static function validate(array $input): array
    {
        $v = Validator::make($input)
            ->name('last_name', 'Nom')
            ->name('first_name', 'Prénom')
            ->text('other_names', 'Autres noms déclarés', false, 255)
            ->date('birth_date', 'Date de naissance', false, true)
            ->int('declared_age', 'Âge déclaré', false, 0, 120)
            ->text('birth_place', 'Lieu de naissance', false, 120)
            ->in('sex', 'Sexe', array_keys(Labels::SEX), false)
            ->text('nationality', 'Nationalité', false, 80)
            ->text('profession', 'Profession', false, 120)
            ->text('admin_id_type', "Type d'identifiant", false, 60)
            ->text('admin_id_number', "Numéro d'identifiant", false, 80)
            ->bool('admin_id_verified')
            ->date('registration_date', "Date d'enregistrement", true, true)
            ->text('registration_place', "Lieu d'enregistrement", true, 120)
            ->text('registering_service', 'Service ayant créé la fiche', true, 120)
            ->in('access_level', "Niveau d'accès", array_keys(Labels::ACCESS_LEVEL), false, 'standard');
        $d = $v->data();
        if ($d['admin_id_number'] !== null && $d['admin_id_type'] === null) {
            $v->addError('admin_id_type', "Précisez le type de l'identifiant administratif.");
        }
        if ($d['admin_id_verified'] && $d['admin_id_number'] === null) {
            $v->addError('admin_id_verified', 'Un identifiant ne peut être marqué « vérifié » sans numéro.');
        }
        $data = $v->validated();
        $data['last_name'] = mb_strtoupper($data['last_name']);
        return $data;
    }

    private static function derived(array $data): array
    {
        return [
            'last_name_norm'   => Normalizer::name($data['last_name']),
            'first_name_norm'  => Normalizer::name($data['first_name']),
            'other_names_norm' => Normalizer::name($data['other_names'] ?? '') ?: null,
            'last_name_phon'   => Normalizer::phonetic($data['last_name']),
            'first_name_phon'  => Normalizer::phonetic($data['first_name']),
            'admin_id_norm'    => Normalizer::adminId($data['admin_id_number'] ?? null),
        ];
    }

    public static function create(array $data, ?int $userId, bool $isDemo = false): int
    {
        return Database::transaction(function () use ($data, $userId, $isDemo) {
            $now = date('Y-m-d H:i:s');
            $id = Database::insert('persons', array_intersect_key($data, array_flip(self::FIELDS)) + self::derived($data) + [
                'file_number' => 'TMP-' . bin2hex(random_bytes(8)),
                'is_demo'     => $isDemo ? 1 : 0,
                'created_by'  => $userId,
                'updated_by'  => $userId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $number = sprintf('DID-%s-%06d', substr((string) $data['registration_date'], 0, 4), $id);
            Database::update('persons', ['file_number' => $number], 'id = :id', ['id' => $id]);
            return $id;
        });
    }

    /** @return string[] noms des champs modifiés */
    public static function update(int $id, array $data, ?int $userId): array
    {
        $current = Database::one('SELECT * FROM persons WHERE id = :id', ['id' => $id]);
        $changes = [];
        foreach (self::FIELDS as $f) {
            if ((string) ($current[$f] ?? '') !== (string) ($data[$f] ?? '')) {
                $changes[] = $f;
            }
        }
        if ($changes) {
            Database::update('persons', array_intersect_key($data, array_flip(self::FIELDS)) + self::derived($data) + [
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $id]);
        }
        return $changes;
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM persons WHERE id = :id', ['id' => $id]);
    }

    public static function primaryPhoto(int $personId): ?array
    {
        return Database::one(
            "SELECT * FROM photos WHERE person_id = :p AND status = 'active' ORDER BY is_primary DESC, uploaded_at DESC LIMIT 1",
            ['p' => $personId]
        );
    }

    public static function like(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /**
     * Recherche multicritère paginée, filtrée selon les droits de l'utilisateur.
     * @return array{0: array, 1: Paginator}
     */
    public static function search(array $f, int $perPage = 20, bool $all = false): array
    {
        $where = [Access::personSqlFilter('p')];
        $params = [];

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $tokens = array_slice(array_filter(explode(' ', Normalizer::name($q))), 0, 5);
            $parts = [];
            foreach (array_values($tokens) as $i => $t) {
                $parts[] = "CONCAT_WS(' ', p.last_name_norm, p.first_name_norm, IFNULL(p.other_names_norm, '')) LIKE :q$i";
                $params["q$i"] = '%' . self::like($t) . '%';
            }
            $cond = $parts ? '(' . implode(' AND ', $parts) . ')' : '1=0';
            if (!empty($f['approx']) && $tokens) {
                $phon = [];
                foreach (array_values($tokens) as $i => $t) {
                    $phon[] = "p.last_name_phon = :ph$i OR p.first_name_phon = :fph$i";
                    $params["ph$i"] = $params["fph$i"] = Normalizer::phonetic($t);
                }
                $cond = "($cond OR " . implode(' OR ', $phon) . ')';
            }
            $where[] = $cond;
        }
        if (($fn = trim((string) ($f['file_number'] ?? ''))) !== '') {
            $where[] = 'p.file_number LIKE :fn';
            $params['fn'] = self::like(strtoupper($fn)) . '%';
        }
        if (($aid = Normalizer::adminId($f['admin_id'] ?? null)) !== null) {
            $where[] = 'p.admin_id_norm LIKE :aid';
            $params['aid'] = self::like($aid) . '%';
        }
        foreach (['birth_from' => 'p.birth_date >=', 'birth_to' => 'p.birth_date <=',
                  'reg_from' => 'p.registration_date >=', 'reg_to' => 'p.registration_date <='] as $k => $sql) {
            $val = (string) ($f[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $where[] = "$sql :$k";
                $params[$k] = $val;
            }
        }
        $status = (string) ($f['status'] ?? 'actif');
        if ($status === 'archive' || $status === 'tous') {
            if ($status === 'archive') {
                $where[] = "p.status = 'archive'";
            }
        } else {
            $where[] = "p.status = 'actif'";
        }
        $ps = (string) ($f['procedure_status'] ?? '');
        if ($ps !== '' && isset(Labels::PROCEDURE_STATUS[$ps]) && Auth::can('procedures.view')) {
            $where[] = 'EXISTS (SELECT 1 FROM procedures pr WHERE pr.person_id = p.id AND pr.status = :ps)';
            $params['ps'] = $ps;
        }
        if (($pn = trim((string) ($f['procedure_number'] ?? ''))) !== '' && Auth::can('procedures.view')) {
            $where[] = 'EXISTS (SELECT 1 FROM procedures pr2 WHERE pr2.person_id = p.id AND (pr2.procedure_number LIKE :pn OR pr2.pv_reference LIKE :pn2))';
            $params['pn'] = '%' . self::like($pn) . '%';
            $params['pn2'] = '%' . self::like($pn) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM persons p WHERE $sqlWhere", $params);
        $pager = $all ? new Paginator($total, 1, max(1, $total)) : Paginator::fromRequest($total, $perPage);
        $order = match ((string) ($f['sort'] ?? '')) {
            'name'   => 'p.last_name_norm, p.first_name_norm',
            'number' => 'p.file_number',
            default  => 'p.updated_at DESC',
        };
        $rows = Database::all(
            "SELECT p.*, (SELECT COUNT(*) FROM procedures x WHERE x.person_id = p.id) AS procedure_count,
                    (SELECT COUNT(*) FROM visits y WHERE y.person_id = p.id) AS visit_count
             FROM persons p WHERE $sqlWhere ORDER BY $order LIMIT " . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset,
            $params
        );
        return [$rows, $pager];
    }
}
