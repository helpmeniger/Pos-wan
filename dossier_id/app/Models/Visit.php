<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Access;
use App\Support\Labels;
use App\Support\Paginator;
use App\Support\Validator;

final class Visit
{
    public const FIELDS = ['visit_date', 'location', 'service', 'reason', 'reference', 'responsible_agent', 'observations', 'closure_status'];

    public static function validate(array $input): array
    {
        return Validator::make($input)
            ->datetime('visit_date', 'Date et heure du passage', true)
            ->text('location', 'Lieu', true, 150)
            ->text('service', 'Service concerné', true, 120)
            ->text('reason', 'Motif administratif', true, 255)
            ->text('reference', 'Référence du dossier', true, 80)
            ->text('responsible_agent', 'Agent responsable', true, 150)
            ->text('observations', 'Observations', false, 4000)
            ->in('closure_status', 'Statut', array_keys(Labels::VISIT_CLOSURE), false, 'ouvert')
            ->validated();
    }

    public static function create(int $personId, array $data, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $id = Database::insert('visits', array_intersect_key($data, array_flip(self::FIELDS)) + [
            'person_id' => $personId, 'created_by' => $userId, 'updated_by' => $userId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Database::update('persons', ['updated_at' => $now], 'id = :id', ['id' => $personId]);
        return $id;
    }

    public static function update(int $id, array $data, ?int $userId): array
    {
        $cur = Database::one('SELECT * FROM visits WHERE id = :id', ['id' => $id]);
        $changes = [];
        foreach (self::FIELDS as $f) {
            if ((string) ($cur[$f] ?? '') !== (string) ($data[$f] ?? '')) {
                $changes[] = $f;
            }
        }
        if ($changes) {
            $now = date('Y-m-d H:i:s');
            Database::update('visits', array_intersect_key($data, array_flip(self::FIELDS)) + [
                'updated_by' => $userId, 'updated_at' => $now,
            ], 'id = :id', ['id' => $id]);
            Database::update('persons', ['updated_at' => $now], 'id = :id', ['id' => (int) $cur['person_id']]);
        }
        return $changes;
    }

    public static function forPerson(int $personId): array
    {
        return Database::all('SELECT * FROM visits WHERE person_id = :p ORDER BY visit_date DESC', ['p' => $personId]);
    }

    /** Recherche parmi les passages, limitée aux fiches accessibles. */
    public static function search(array $f, int $perPage = 25, bool $all = false): array
    {
        $where = [Access::personSqlFilter('p')];
        $params = [];
        foreach (['date_from' => 'v.visit_date >=', 'date_to' => 'v.visit_date <'] as $k => $sql) {
            $val = (string) ($f[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $where[] = "$sql :$k";
                $params[$k] = $k === 'date_to' ? date('Y-m-d', strtotime($val . ' +1 day')) : $val;
            }
        }
        foreach (['service' => 'v.service', 'reference' => 'v.reference', 'reason' => 'v.reason', 'location' => 'v.location'] as $k => $col) {
            if (($val = trim((string) ($f[$k] ?? ''))) !== '') {
                $where[] = "$col LIKE :$k";
                $params[$k] = '%' . Person::like($val) . '%';
            }
        }
        if (in_array($f['closure_status'] ?? '', ['ouvert', 'cloture'], true)) {
            $where[] = 'v.closure_status = :cs';
            $params['cs'] = $f['closure_status'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM visits v JOIN persons p ON p.id = v.person_id WHERE $sqlWhere", $params);
        $pager = $all ? new Paginator($total, 1, max(1, $total)) : Paginator::fromRequest($total, $perPage);
        $rows = Database::all(
            "SELECT v.*, p.file_number, p.last_name, p.first_name FROM visits v JOIN persons p ON p.id = v.person_id
             WHERE $sqlWhere ORDER BY v.visit_date DESC LIMIT " . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset,
            $params
        );
        return [$rows, $pager];
    }
}
