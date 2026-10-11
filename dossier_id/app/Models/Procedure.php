<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\Access;
use App\Support\Labels;
use App\Support\Paginator;
use App\Support\Validator;

/**
 * Procédures. Règles de qualification :
 *  - « condamnée » exige une décision documentée : juridiction, date, texte de la
 *    décision et référence d'un document justificatif ;
 *  - « acquittée » exige la juridiction, la date et la décision ;
 *  - « poursuivie » exige une référence du parquet ou de la juridiction ;
 *  - tout changement de statut exige une justification, conservée dans l'historique.
 */
final class Procedure
{
    public const FIELDS = [
        'procedure_number', 'pv_reference', 'facts_date', 'facts_nature', 'investigating_service', 'status',
        'court_reference', 'decision_text', 'decision_date', 'decision_final', 'justification_ref', 'notes',
    ];

    public static function validate(array $input, ?string $previousStatus = null): array
    {
        $v = Validator::make($input)
            ->text('procedure_number', 'Numéro de procédure', true, 80)
            ->text('pv_reference', 'Référence du procès-verbal', false, 80)
            ->date('facts_date', 'Date des faits allégués', false, true)
            ->text('facts_nature', 'Nature des faits allégués', true, 255)
            ->text('investigating_service', 'Service enquêteur', true, 150)
            ->in('status', 'Statut procédural', array_keys(Labels::PROCEDURE_STATUS))
            ->text('court_reference', 'Référence parquet / juridiction', false, 120)
            ->text('decision_text', 'Décision judiciaire', false, 500)
            ->date('decision_date', 'Date de la décision', false, true)
            ->in('decision_final', 'Caractère définitif', array_keys(Labels::DECISION_FINAL), false, 'inconnu')
            ->text('justification_ref', 'Références des documents justificatifs', false, 255)
            ->text('notes', 'Observations', false, 4000)
            ->text('status_justification', 'Justification du statut', false, 500);
        $d = $v->data();
        $status = $d['status'];

        if ($status === 'condamnee') {
            foreach (['court_reference' => 'la référence de la juridiction', 'decision_text' => 'le texte ou dispositif de la décision',
                      'decision_date' => 'la date de la décision', 'justification_ref' => 'la référence du document justificatif'] as $f => $what) {
                if (empty($d[$f])) {
                    $v->addError($f, "Le statut « condamnée » exige $what.");
                }
            }
        } elseif ($status === 'acquittee') {
            foreach (['court_reference' => 'la référence de la juridiction', 'decision_text' => 'la décision',
                      'decision_date' => 'la date de la décision'] as $f => $what) {
                if (empty($d[$f])) {
                    $v->addError($f, "Le statut « acquittée » exige $what.");
                }
            }
        } elseif ($status === 'poursuivie' && empty($d['court_reference'])) {
            $v->addError('court_reference', 'Le statut « poursuivie » exige la référence du parquet ou de la juridiction.');
        }
        if (!in_array($status, ['condamnee', 'acquittee'], true) && ($d['decision_final'] ?? 'inconnu') !== 'inconnu') {
            $v->addError('decision_final', 'Le caractère définitif ne s\'applique qu\'à une décision de condamnation ou d\'acquittement.');
        }
        if ($d['decision_date'] && $d['facts_date'] && $d['decision_date'] < $d['facts_date']) {
            $v->addError('decision_date', 'La date de la décision ne peut pas précéder la date des faits.');
        }
        if ($previousStatus !== null && $previousStatus !== $status && empty($d['status_justification'])) {
            $v->addError('status_justification', 'Tout changement de statut doit être justifié.');
        }
        return $v->validated();
    }

    public static function create(int $personId, array $data, ?int $userId): int
    {
        return Database::transaction(function () use ($personId, $data, $userId) {
            $now = date('Y-m-d H:i:s');
            $id = Database::insert('procedures', array_intersect_key($data, array_flip(self::FIELDS)) + [
                'person_id' => $personId, 'created_by' => $userId, 'updated_by' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            Database::insert('procedure_status_history', [
                'procedure_id' => $id, 'old_status' => null, 'new_status' => $data['status'],
                'justification' => $data['status_justification'] ?: 'Enregistrement initial',
                'changed_by' => $userId, 'changed_at' => $now,
            ]);
            Database::update('persons', ['updated_at' => $now], 'id = :id', ['id' => $personId]);
            return $id;
        });
    }

    public static function update(int $id, array $data, ?int $userId): array
    {
        return Database::transaction(function () use ($id, $data, $userId) {
            $cur = Database::one('SELECT * FROM procedures WHERE id = :id FOR UPDATE', ['id' => $id]);
            $changes = [];
            foreach (self::FIELDS as $f) {
                if ((string) ($cur[$f] ?? '') !== (string) ($data[$f] ?? '')) {
                    $changes[] = $f;
                }
            }
            if (!$changes) {
                return [];
            }
            $now = date('Y-m-d H:i:s');
            Database::update('procedures', array_intersect_key($data, array_flip(self::FIELDS)) + [
                'updated_by' => $userId, 'updated_at' => $now,
            ], 'id = :id', ['id' => $id]);
            if ($cur['status'] !== $data['status']) {
                Database::insert('procedure_status_history', [
                    'procedure_id' => $id, 'old_status' => $cur['status'], 'new_status' => $data['status'],
                    'justification' => (string) $data['status_justification'], 'changed_by' => $userId, 'changed_at' => $now,
                ]);
            }
            Database::update('persons', ['updated_at' => $now], 'id = :id', ['id' => (int) $cur['person_id']]);
            return $changes;
        });
    }

    public static function forPerson(int $personId): array
    {
        return Database::all('SELECT * FROM procedures WHERE person_id = :p ORDER BY COALESCE(facts_date, created_at) DESC', ['p' => $personId]);
    }

    public static function history(int $procedureId): array
    {
        return Database::all(
            'SELECT h.*, u.full_name FROM procedure_status_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.procedure_id = :id ORDER BY h.changed_at, h.id',
            ['id' => $procedureId]
        );
    }

    public static function search(array $f, int $perPage = 25): array
    {
        $where = [Access::personSqlFilter('p')];
        $params = [];
        if (($n = trim((string) ($f['number'] ?? ''))) !== '') {
            $where[] = '(pr.procedure_number LIKE :n OR pr.pv_reference LIKE :n2 OR pr.court_reference LIKE :n3)';
            $params['n'] = $params['n2'] = $params['n3'] = '%' . Person::like($n) . '%';
        }
        if (isset(Labels::PROCEDURE_STATUS[$f['status'] ?? ''])) {
            $where[] = 'pr.status = :st';
            $params['st'] = $f['status'];
        }
        if (($s = trim((string) ($f['service'] ?? ''))) !== '') {
            $where[] = 'pr.investigating_service LIKE :sv';
            $params['sv'] = '%' . Person::like($s) . '%';
        }
        foreach (['date_from' => 'pr.facts_date >=', 'date_to' => 'pr.facts_date <='] as $k => $sql) {
            $val = (string) ($f[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $where[] = "$sql :$k";
                $params[$k] = $val;
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM procedures pr JOIN persons p ON p.id = pr.person_id WHERE $sqlWhere", $params);
        $pager = Paginator::fromRequest($total, $perPage);
        $rows = Database::all(
            "SELECT pr.*, p.file_number, p.last_name, p.first_name FROM procedures pr JOIN persons p ON p.id = pr.person_id
             WHERE $sqlWhere ORDER BY pr.updated_at DESC LIMIT " . (int) $pager->perPage . ' OFFSET ' . (int) $pager->offset,
            $params
        );
        return [$rows, $pager];
    }
}
