<?php
declare(strict_types=1);

namespace Addons\Grh;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;

/**
 * Ressources humaines. Règles tenues ici :
 * - un employé n'a qu'un contrat en cours ; un nouveau contrat termine le précédent la veille ;
 * - congés : jours ouvrés (hors samedi et dimanche), pas de chevauchement, solde annuel contrôlé à l'approbation ;
 * - paie : brouillon modifiable, puis validation qui verrouille et retient les avances ; jamais de net négatif ;
 * - rien n'est effacé : un employé sort (date, motif), un congé s'annule, une avance s'annule tant qu'elle n'a pas été retenue.
 * Les taux de cotisations et d'impôt sont des réglages : à vérifier avec le comptable de l'entreprise.
 */
final class GrhService
{
    public const CIVILITIES = ['M.' => 'M.', 'Mme' => 'Mme', 'Mlle' => 'Mlle'];
    public const MARITAL = ['celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'divorce' => 'Divorcé(e)', 'veuf' => 'Veuf / veuve'];
    public const EMP_STATUS = ['actif' => 'Actif', 'suspendu' => 'Suspendu', 'sorti' => 'Sorti'];
    public const EMP_COLORS = ['actif' => 'success', 'suspendu' => 'warning', 'sorti' => 'secondary'];
    public const CONTRACT_KINDS = ['cdi' => 'CDI', 'cdd' => 'CDD', 'essai' => 'Période d\'essai', 'stage' => 'Stage', 'prestation' => 'Prestation de services'];
    public const LEAVE_KINDS = ['annuel' => 'Congé annuel', 'maladie' => 'Congé maladie', 'maternite' => 'Congé de maternité', 'exceptionnel' => 'Permission exceptionnelle', 'sans_solde' => 'Congé sans solde'];
    public const LEAVE_STATUS = ['demande' => 'Demandé', 'approuve' => 'Approuvé', 'refuse' => 'Refusé', 'annule' => 'Annulé'];
    public const LEAVE_COLORS = ['demande' => 'warning', 'approuve' => 'success', 'refuse' => 'danger', 'annule' => 'secondary'];
    public const PRESENCE = ['present' => 'Présent', 'retard' => 'En retard', 'absent' => 'Absent', 'mission' => 'En mission', 'repos' => 'Repos'];
    public const PRESENCE_COLORS = ['present' => 'success', 'retard' => 'warning', 'absent' => 'danger', 'mission' => 'primary', 'repos' => 'secondary'];
    public const PAIE_STATUS = ['brouillon' => 'Brouillon', 'validee' => 'Validée', 'payee' => 'Payée'];
    public const DOSSIER_KINDS = ['evaluation' => 'Évaluation', 'sanction' => 'Sanction', 'promotion' => 'Promotion', 'formation' => 'Formation', 'note' => 'Note'];
    public const DOSSIER_COLORS = ['evaluation' => 'primary', 'sanction' => 'danger', 'promotion' => 'success', 'formation' => 'info', 'note' => 'secondary'];
    public const AVANCE_STATUS = ['en_cours' => 'En cours', 'soldee' => 'Soldée', 'annulee' => 'Annulée'];

    public static function num(mixed $v): float
    {
        if (is_string($v)) {
            $v = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], $v);
        }
        return is_numeric($v) ? round((float) $v, 2) : 0.0;
    }

    private static function date(mixed $v, string $label, bool $required = false): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            if ($required) {
                throw new BusinessException("$label : date obligatoire.");
            }
            return null;
        }
        if (!Validator::isDate($v)) {
            throw new BusinessException("$label : date invalide.");
        }
        return $v;
    }

    private static function txt(mixed $v, int $max = 250): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function rate(string $key, string $default): float
    {
        return max(0.0, (float) str_replace(',', '.', (string) setting($key, $default)));
    }

    // ------------------------------------------------------------------ structure

    public static function saveDepartement(array $in, ?int $id = null): int
    {
        $name = self::txt($in['name'] ?? '', 120);
        if (!$name) {
            throw new BusinessException('Nom du département obligatoire.');
        }
        if (DB::value('SELECT id FROM grh_departements WHERE name = ? AND id <> ?', [$name, (int) $id])) {
            throw new BusinessException('Un département porte déjà ce nom.');
        }
        $parent = (int) ($in['parent_id'] ?? 0) ?: null;
        if ($parent && $id) {
            // Un département ne peut pas dépendre de lui-même ni d'un de ses sous-départements
            for ($p = $parent, $n = 0; $p && $n < 50; $n++) {
                if ($p === $id) {
                    throw new BusinessException('Un département ne peut pas dépendre de lui-même.');
                }
                $p = (int) DB::value('SELECT parent_id FROM grh_departements WHERE id = ?', [$p]);
            }
        }
        $data = ['name' => $name, 'parent_id' => $parent, 'manager_id' => (int) ($in['manager_id'] ?? 0) ?: null];
        if ($id) {
            DB::update('grh_departements', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('grh_departements', $data);
        }
        Audit::log('grh.departement.save', 'grh_departements', $id, ['name' => $name]);
        return $id;
    }

    public static function savePoste(array $in, ?int $id = null): int
    {
        $title = self::txt($in['title'] ?? '', 120);
        if (!$title) {
            throw new BusinessException('Intitulé du poste obligatoire.');
        }
        if (DB::value('SELECT id FROM grh_postes WHERE title = ? AND id <> ?', [$title, (int) $id])) {
            throw new BusinessException('Ce poste existe déjà.');
        }
        $data = ['title' => $title, 'departement_id' => (int) ($in['departement_id'] ?? 0) ?: null, 'grade' => self::txt($in['grade'] ?? '', 40), 'base_salary' => max(0, self::num($in['base_salary'] ?? 0))];
        if ($id) {
            DB::update('grh_postes', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('grh_postes', $data);
        }
        return $id;
    }

    // ------------------------------------------------------------------ employés

    public static function employe(int $id): array
    {
        $e = DB::one('SELECT e.*, d.name AS departement, p.title AS poste, p.grade, CONCAT(m.first_name, \' \', m.last_name) AS manager
            FROM grh_employes e LEFT JOIN grh_departements d ON d.id = e.departement_id LEFT JOIN grh_postes p ON p.id = e.poste_id LEFT JOIN grh_employes m ON m.id = e.manager_id WHERE e.id = ?', [$id]);
        if (!$e) {
            throw new BusinessException('Employé introuvable.');
        }
        return $e;
    }

    public static function saveEmploye(array $in, ?int $id = null): int
    {
        $mat = self::txt($in['matricule'] ?? '', 30);
        $last = self::txt($in['last_name'] ?? '', 80);
        $first = self::txt($in['first_name'] ?? '', 80);
        if (!$mat || !$last || !$first) {
            throw new BusinessException('Matricule, nom et prénom obligatoires.');
        }
        if (DB::value('SELECT id FROM grh_employes WHERE matricule = ? AND id <> ?', [$mat, (int) $id])) {
            throw new BusinessException('Ce matricule est déjà utilisé.');
        }
        $hire = self::date($in['hire_date'] ?? '', 'Date d\'embauche', true);
        $birth = self::date($in['birth_date'] ?? '', 'Date de naissance');
        if ($birth && $birth > date('Y-m-d', strtotime('-14 years'))) {
            throw new BusinessException('Date de naissance invalide (moins de 14 ans).');
        }
        $email = self::txt($in['email'] ?? '', 150);
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('Adresse e-mail invalide.');
        }
        $civ = (string) ($in['civility'] ?? 'M.');
        $marital = (string) ($in['marital'] ?? 'celibataire');
        if (!isset(self::CIVILITIES[$civ]) || !isset(self::MARITAL[$marital])) {
            throw new BusinessException('Civilité ou situation familiale inconnue.');
        }
        $manager = (int) ($in['manager_id'] ?? 0) ?: null;
        if ($manager && $manager === $id) {
            throw new BusinessException('Un employé ne peut pas être son propre responsable.');
        }
        $data = ['matricule' => $mat, 'civility' => $civ, 'last_name' => $last, 'first_name' => $first, 'sex' => ($in['sex'] ?? 'M') === 'F' ? 'F' : 'M', 'birth_date' => $birth,
            'birth_place' => self::txt($in['birth_place'] ?? '', 100), 'nationality' => self::txt($in['nationality'] ?? '', 60), 'marital' => $marital, 'children' => min(40, max(0, (int) ($in['children'] ?? 0))),
            'phone' => self::txt($in['phone'] ?? '', 40), 'email' => $email, 'address' => self::txt($in['address'] ?? '', 200), 'id_number' => self::txt($in['id_number'] ?? '', 60),
            'cnss_number' => self::txt($in['cnss_number'] ?? '', 40), 'bank_account' => self::txt($in['bank_account'] ?? '', 80), 'emergency_contact' => self::txt($in['emergency_contact'] ?? '', 150),
            'departement_id' => (int) ($in['departement_id'] ?? 0) ?: null, 'poste_id' => (int) ($in['poste_id'] ?? 0) ?: null, 'manager_id' => $manager, 'hire_date' => $hire];
        if ($id) {
            self::employe($id);
            DB::update('grh_employes', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('grh_employes', $data);
        }
        Audit::log('grh.employe.save', 'grh_employes', $id, ['matricule' => $mat]);
        return $id;
    }

    /** Sortie d'un employé (démission, fin de contrat, licenciement…) : la fiche et l'historique restent. */
    public static function exitEmploye(int $id, string $date, string $reason): void
    {
        $date = self::date($date, 'Date de sortie', true);
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Motif de la sortie obligatoire.');
        }
        DB::transaction(function () use ($id, $date, $reason) {
            $e = self::employe($id);
            if ($e['status'] === 'sorti') {
                throw new BusinessException('Cet employé est déjà sorti.');
            }
            if ($date < $e['hire_date']) {
                throw new BusinessException('La date de sortie est avant la date d\'embauche.');
            }
            DB::run("UPDATE grh_contrats SET status = 'termine', end_date = COALESCE(LEAST(end_date, ?), ?) WHERE employe_id = ? AND status = 'en_cours'", [$date, $date, $id]);
            DB::run("UPDATE grh_conges SET status = 'annule', decision_note = 'Sortie de l''employé' WHERE employe_id = ? AND status = 'demande'", [$id]);
            DB::update('grh_employes', ['status' => 'sorti', 'exit_date' => $date, 'exit_reason' => mb_substr(trim($reason), 0, 255)], 'id = ?', [$id]);
            Audit::log('grh.employe.sortie', 'grh_employes', $id, ['date' => $date, 'reason' => $reason]);
        });
    }

    public static function setStatus(int $id, string $status): void
    {
        $e = self::employe($id);
        if ($e['status'] === 'sorti' || !in_array($status, ['actif', 'suspendu'], true)) {
            throw new BusinessException('Changement d\'état impossible.');
        }
        DB::update('grh_employes', ['status' => $status], 'id = ?', [$id]);
        Audit::log('grh.employe.etat', 'grh_employes', $id, ['status' => $status]);
    }

    // ------------------------------------------------------------------ contrats

    public static function currentContract(int $employeId): ?array
    {
        return DB::one("SELECT * FROM grh_contrats WHERE employe_id = ? AND status = 'en_cours' ORDER BY start_date DESC, id DESC LIMIT 1", [$employeId]);
    }

    public static function saveContrat(int $employeId, array $in): int
    {
        $e = self::employe($employeId);
        if ($e['status'] === 'sorti') {
            throw new BusinessException('Cet employé est sorti : pas de nouveau contrat.');
        }
        $kind = (string) ($in['kind'] ?? 'cdi');
        if (!isset(self::CONTRACT_KINDS[$kind])) {
            throw new BusinessException('Type de contrat inconnu.');
        }
        $start = self::date($in['start_date'] ?? '', 'Début du contrat', true);
        $end = self::date($in['end_date'] ?? '', 'Fin du contrat');
        if ($kind !== 'cdi' && !$end) {
            throw new BusinessException('Un ' . self::CONTRACT_KINDS[$kind] . ' a une date de fin.');
        }
        if ($end && $end < $start) {
            throw new BusinessException('La fin du contrat est avant son début.');
        }
        if ($start < $e['hire_date']) {
            throw new BusinessException('Le contrat commence avant la date d\'embauche (' . fdate($e['hire_date'], false) . ').');
        }
        $base = self::num($in['base_salary'] ?? 0);
        $allow = self::num($in['allowances'] ?? 0);
        if ($base <= 0 || $allow < 0) {
            throw new BusinessException('Salaire de base obligatoire ; primes positives.');
        }
        return DB::transaction(function () use ($employeId, $kind, $start, $end, $base, $allow, $in) {
            $cur = DB::one("SELECT * FROM grh_contrats WHERE employe_id = ? AND status = 'en_cours' FOR UPDATE", [$employeId]);
            if ($cur) {
                if ($start <= $cur['start_date']) {
                    throw new BusinessException('Le nouveau contrat doit commencer après le début du contrat en cours (' . fdate($cur['start_date'], false) . ').');
                }
                DB::update('grh_contrats', ['status' => 'termine', 'end_date' => date('Y-m-d', strtotime($start . ' -1 day'))], 'id = ?', [$cur['id']]);
            }
            $id = DB::insert('grh_contrats', ['employe_id' => $employeId, 'kind' => $kind, 'start_date' => $start, 'end_date' => $end, 'base_salary' => $base, 'allowances' => $allow, 'note' => self::txt($in['note'] ?? '')]);
            Audit::log('grh.contrat.nouveau', 'grh_contrats', $id, ['employe' => $employeId, 'kind' => $kind, 'base' => $base]);
            return $id;
        });
    }

    // ------------------------------------------------------------------ congés

    /** Jours ouvrés entre deux dates incluses (samedi et dimanche exclus ; jours fériés non décomptés). */
    public static function workingDays(string $start, string $end): float
    {
        $n = 0;
        for ($t = strtotime($start), $e = strtotime($end); $t <= $e; $t = strtotime('+1 day', $t)) {
            if ((int) date('N', $t) < 6) {
                $n++;
            }
        }
        return (float) $n;
    }

    /** Solde de congé annuel : jours acquis depuis l'embauche (mois complets × taux) − jours approuvés. */
    public static function leaveBalance(int $employeId, ?string $at = null): array
    {
        $e = DB::one('SELECT hire_date, exit_date FROM grh_employes WHERE id = ?', [$employeId]);
        $at = $at ?? ($e['exit_date'] ?? date('Y-m-d'));
        $d1 = new \DateTimeImmutable((string) $e['hire_date']);
        $d2 = new \DateTimeImmutable($at);
        $months = $d2 < $d1 ? 0 : $d1->diff($d2)->y * 12 + $d1->diff($d2)->m;
        $acquired = round($months * self::rate('grh_conges_par_mois', '2.5'), 1);
        $taken = (float) DB::value("SELECT COALESCE(SUM(days), 0) FROM grh_conges WHERE employe_id = ? AND kind = 'annuel' AND status = 'approuve'", [$employeId]);
        return ['months' => $months, 'acquired' => $acquired, 'taken' => $taken, 'balance' => round($acquired - $taken, 1)];
    }

    public static function requestLeave(array $in): int
    {
        $emp = self::employe((int) ($in['employe_id'] ?? 0));
        if ($emp['status'] === 'sorti') {
            throw new BusinessException('Cet employé est sorti.');
        }
        $kind = (string) ($in['kind'] ?? 'annuel');
        if (!isset(self::LEAVE_KINDS[$kind])) {
            throw new BusinessException('Type de congé inconnu.');
        }
        $start = self::date($in['start_date'] ?? '', 'Début', true);
        $end = self::date($in['end_date'] ?? '', 'Fin', true);
        if ($end < $start) {
            throw new BusinessException('La date de fin est avant la date de début.');
        }
        if ($start < $emp['hire_date']) {
            throw new BusinessException('Le congé commence avant la date d\'embauche.');
        }
        $days = self::workingDays($start, $end);
        if ($days <= 0) {
            throw new BusinessException('Cette période ne contient aucun jour ouvré.');
        }
        if (DB::value("SELECT id FROM grh_conges WHERE employe_id = ? AND status IN ('demande','approuve') AND start_date <= ? AND end_date >= ?", [$emp['id'], $end, $start])) {
            throw new BusinessException('Un autre congé de cet employé couvre déjà une partie de cette période.');
        }
        $id = DB::insert('grh_conges', ['employe_id' => $emp['id'], 'kind' => $kind, 'start_date' => $start, 'end_date' => $end, 'days' => $days, 'reason' => self::txt($in['reason'] ?? ''), 'created_by' => Auth::id()]);
        Audit::log('grh.conge.demande', 'grh_conges', $id, ['employe' => $emp['id'], 'days' => $days]);
        return $id;
    }

    public static function decideLeave(int $id, bool $approve, string $note = ''): void
    {
        DB::transaction(function () use ($id, $approve, $note) {
            $c = DB::one('SELECT * FROM grh_conges WHERE id = ? FOR UPDATE', [$id]);
            if (!$c || $c['status'] !== 'demande') {
                throw new BusinessException('Cette demande a déjà été traitée.');
            }
            if (!$approve && mb_strlen(trim($note)) < 3) {
                throw new BusinessException('Motif du refus obligatoire.');
            }
            if ($approve && $c['kind'] === 'annuel') {
                $b = self::leaveBalance((int) $c['employe_id'], (string) $c['start_date']);
                if ((float) $c['days'] > $b['balance'] + 0.001) {
                    throw new BusinessException('Solde de congé insuffisant : ' . qty($b['balance']) . ' jour(s) disponibles au ' . fdate($c['start_date'], false) . ', ' . qty($c['days']) . ' demandés. Proposez un congé sans solde ou réduisez la période.');
                }
            }
            DB::update('grh_conges', ['status' => $approve ? 'approuve' : 'refuse', 'decided_by' => Auth::id(), 'decided_at' => date('Y-m-d H:i:s'), 'decision_note' => self::txt($note)], 'id = ?', [$id]);
            Audit::log('grh.conge.' . ($approve ? 'approuve' : 'refuse'), 'grh_conges', $id, ['days' => $c['days']]);
        });
    }

    /** Annulation d'un congé approuvé non encore commencé (ou d'une demande) : les jours reviennent au solde. */
    public static function cancelLeave(int $id, string $note): void
    {
        $c = DB::one('SELECT * FROM grh_conges WHERE id = ?', [$id]);
        if (!$c || !in_array($c['status'], ['demande', 'approuve'], true)) {
            throw new BusinessException('Ce congé ne peut pas être annulé.');
        }
        if ($c['status'] === 'approuve' && $c['end_date'] < date('Y-m-d')) {
            throw new BusinessException('Ce congé est déjà terminé.');
        }
        DB::update('grh_conges', ['status' => 'annule', 'decision_note' => self::txt($note) ?? 'Annulé'], 'id = ?', [$id]);
        Audit::log('grh.conge.annule', 'grh_conges', $id);
    }

    // ------------------------------------------------------------------ présences

    /** @param array<int|string, string> $statuses employé => état ('' = effacer le pointage) */
    public static function markDay(string $day, array $statuses): int
    {
        $day = self::date($day, 'Jour', true);
        if ($day > date('Y-m-d')) {
            throw new BusinessException('On ne pointe pas un jour à venir.');
        }
        if (DB::value("SELECT id FROM grh_paies WHERE period = ? AND status <> 'brouillon'", [substr($day, 0, 7)])) {
            throw new BusinessException('La paie de ce mois est validée : les présences ne se modifient plus.');
        }
        $n = 0;
        foreach ($statuses as $eid => $st) {
            $eid = (int) $eid; $st = (string) $st;
            if ($st === '') {
                DB::run('DELETE FROM grh_presences WHERE employe_id = ? AND day = ?', [$eid, $day]);
                continue;
            }
            if (!isset(self::PRESENCE[$st]) || !DB::value('SELECT id FROM grh_employes WHERE id = ? AND hire_date <= ? AND (exit_date IS NULL OR exit_date >= ?)', [$eid, $day, $day])) {
                continue;
            }
            DB::run('INSERT INTO grh_presences (employe_id, day, status, user_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), user_id = VALUES(user_id)', [$eid, $day, $st, Auth::id()]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ avances sur salaire

    public static function grantAvance(array $in): int
    {
        $emp = self::employe((int) ($in['employe_id'] ?? 0));
        $c = self::currentContract((int) $emp['id']);
        if ($emp['status'] !== 'actif' || !$c) {
            throw new BusinessException('Avance réservée à un employé actif avec un contrat en cours.');
        }
        $amount = self::num($in['amount'] ?? 0);
        $monthly = self::num($in['monthly'] ?? 0) ?: $amount;
        if ($amount <= 0 || $monthly <= 0 || $monthly > $amount) {
            throw new BusinessException('Montant de l\'avance et retenue mensuelle obligatoires (retenue ≤ avance).');
        }
        $due = (float) DB::value("SELECT COALESCE(SUM(LEAST(monthly, remaining)), 0) FROM grh_avances WHERE employe_id = ? AND status = 'en_cours'", [$emp['id']]);
        $salary = (float) $c['base_salary'] + (float) $c['allowances'];
        if ($due + $monthly > $salary / 3 + 0.004) {
            throw new BusinessException('Les retenues mensuelles (' . money($due + $monthly) . ') dépasseraient le tiers du salaire (' . money($salary / 3) . ').');
        }
        $id = DB::insert('grh_avances', ['employe_id' => $emp['id'], 'amount' => $amount, 'monthly' => $monthly, 'remaining' => $amount, 'granted_on' => self::date($in['granted_on'] ?? date('Y-m-d'), 'Date', true),
            'reason' => self::txt($in['reason'] ?? ''), 'created_by' => Auth::id()]);
        Audit::log('grh.avance.accordee', 'grh_avances', $id, ['employe' => $emp['id'], 'amount' => $amount]);
        return $id;
    }

    public static function cancelAvance(int $id): void
    {
        $a = DB::one('SELECT * FROM grh_avances WHERE id = ?', [$id]);
        if (!$a || $a['status'] !== 'en_cours' || (float) $a['remaining'] < (float) $a['amount']) {
            throw new BusinessException('Cette avance a déjà été retenue en partie : elle ne s\'annule plus.');
        }
        DB::update('grh_avances', ['status' => 'annulee', 'remaining' => 0], 'id = ?', [$id]);
        Audit::log('grh.avance.annulee', 'grh_avances', $id);
    }

    // ------------------------------------------------------------------ paie

    /** Recalcule un bulletin à partir de ses éléments (brut, cotisation, impôt, net, charges patronales). */
    private static function compute(array $b): array
    {
        $b['absence_deduction'] = round((float) $b['base'] / max(1, self::rate('grh_jours_mois', '30')) * (float) $b['absence_days'], 0);
        $b['gross'] = max(0, round((float) $b['base'] + (float) $b['allowances'] + (float) $b['bonus'] + (float) $b['overtime'] - $b['absence_deduction'], 2));
        $cap = self::rate('grh_cnss_plafond', '500000');
        $assiette = $cap > 0 ? min($b['gross'], $cap) : $b['gross'];
        $b['cnss'] = round($assiette * self::rate('grh_cnss_salarie', '5.25') / 100, 0);
        $b['employer_charges'] = round($assiette * self::rate('grh_cnss_employeur', '16.5') / 100, 0);
        if (empty($b['tax_manual'])) {
            $b['tax'] = round(($b['gross'] - $b['cnss']) * self::rate('grh_impot_taux', '0') / 100, 0);
        }
        $b['net'] = round($b['gross'] - $b['cnss'] - (float) $b['tax'] - (float) $b['advance'] - (float) $b['other_deduction'], 2);
        return $b;
    }

    /** Prépare (ou recalcule) la paie d'un mois : un bulletin par employé sous contrat pendant ce mois. */
    public static function generatePaie(string $period): int
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new BusinessException('Période invalide (AAAA-MM).');
        }
        if ($period > date('Y-m')) {
            throw new BusinessException('On ne prépare pas la paie d\'un mois à venir.');
        }
        $first = $period . '-01';
        $last = date('Y-m-t', strtotime($first));
        return DB::transaction(function () use ($period, $first, $last) {
            $p = DB::one('SELECT * FROM grh_paies WHERE period = ? FOR UPDATE', [$period]);
            if ($p && $p['status'] !== 'brouillon') {
                throw new BusinessException('La paie de ' . $period . ' est déjà validée.');
            }
            $pid = $p ? (int) $p['id'] : DB::insert('grh_paies', ['period' => $period, 'created_by' => Auth::id()]);
            $rows = DB::all("SELECT c.*, e.status AS emp_status FROM grh_contrats c JOIN grh_employes e ON e.id = c.employe_id
                WHERE c.start_date <= ? AND (c.end_date IS NULL OR c.end_date >= ?) AND e.hire_date <= ? AND (e.exit_date IS NULL OR e.exit_date >= ?) AND e.status <> 'suspendu'
                ORDER BY c.employe_id, c.start_date DESC", [$last, $first, $last, $first]);
            $seen = [];
            foreach ($rows as $c) {
                $eid = (int) $c['employe_id'];
                if (isset($seen[$eid])) {
                    continue; // le contrat le plus récent du mois fait foi
                }
                $seen[$eid] = true;
                $old = DB::one('SELECT * FROM grh_bulletins WHERE paie_id = ? AND employe_id = ?', [$pid, $eid]) ?? [];
                $abs = (float) DB::value("SELECT COUNT(*) FROM grh_presences WHERE employe_id = ? AND status = 'absent' AND day BETWEEN ? AND ?", [$eid, $first, $last]);
                foreach (DB::all("SELECT start_date, end_date FROM grh_conges WHERE employe_id = ? AND kind = 'sans_solde' AND status = 'approuve' AND start_date <= ? AND end_date >= ?", [$eid, $last, $first]) as $l) {
                    $abs += self::workingDays(max($l['start_date'], $first), min($l['end_date'], $last));
                }
                $adv = (float) DB::value("SELECT COALESCE(SUM(LEAST(monthly, remaining)), 0) FROM grh_avances WHERE employe_id = ? AND status = 'en_cours' AND granted_on <= ?", [$eid, $last]);
                $b = self::compute(['base' => (float) $c['base_salary'], 'allowances' => (float) $c['allowances'], 'bonus' => (float) ($old['bonus'] ?? 0), 'overtime' => (float) ($old['overtime'] ?? 0),
                    'absence_days' => $abs, 'tax' => (float) ($old['tax'] ?? 0), 'tax_manual' => (int) ($old['tax_manual'] ?? 0), 'advance' => $adv, 'other_deduction' => (float) ($old['other_deduction'] ?? 0), 'note' => $old['note'] ?? null]);
                if ($old) {
                    DB::update('grh_bulletins', $b, 'id = ?', [$old['id']]);
                } else {
                    DB::insert('grh_bulletins', ['paie_id' => $pid, 'employe_id' => $eid] + $b);
                }
            }
            if ($seen) {
                DB::run('DELETE FROM grh_bulletins WHERE paie_id = ? AND employe_id NOT IN (' . implode(',', array_keys($seen)) . ')', [$pid]);
            } else {
                DB::run('DELETE FROM grh_bulletins WHERE paie_id = ?', [$pid]);
            }
            if (!$seen) {
                throw new BusinessException('Aucun employé sous contrat en ' . $period . ' : créez d\'abord les contrats.');
            }
            Audit::log('grh.paie.preparee', 'grh_paies', $pid, ['period' => $period, 'bulletins' => count($seen)]);
            return $pid;
        });
    }

    /** Éléments variables d'un bulletin (primes, heures supplémentaires, impôt, autres retenues), en brouillon seulement. */
    public static function updateBulletin(int $id, array $in): void
    {
        DB::transaction(function () use ($id, $in) {
            $b = DB::one("SELECT b.*, p.status FROM grh_bulletins b JOIN grh_paies p ON p.id = b.paie_id WHERE b.id = ? FOR UPDATE", [$id]);
            if (!$b || $b['status'] !== 'brouillon') {
                throw new BusinessException('Bulletin introuvable ou paie déjà validée.');
            }
            $b['bonus'] = max(0, self::num($in['bonus'] ?? 0));
            $b['overtime'] = max(0, self::num($in['overtime'] ?? 0));
            $b['other_deduction'] = max(0, self::num($in['other_deduction'] ?? 0));
            $tax = trim((string) ($in['tax'] ?? ''));
            $b['tax_manual'] = $tax === '' ? 0 : 1;
            $b['tax'] = $tax === '' ? 0 : max(0, self::num($tax));
            $b['note'] = self::txt($in['note'] ?? '');
            $c = self::compute($b);
            DB::update('grh_bulletins', array_intersect_key($c, array_flip(['bonus', 'overtime', 'absence_deduction', 'gross', 'cnss', 'tax', 'tax_manual', 'other_deduction', 'net', 'employer_charges', 'note'])), 'id = ?', [$id]);
        });
    }

    /** Validation : verrouille la paie et retient les avances. */
    public static function validatePaie(int $id): void
    {
        DB::transaction(function () use ($id) {
            $p = DB::one('SELECT * FROM grh_paies WHERE id = ? FOR UPDATE', [$id]);
            if (!$p || $p['status'] !== 'brouillon') {
                throw new BusinessException('Cette paie n\'est pas en brouillon.');
            }
            $bs = DB::all('SELECT b.*, e.matricule, e.last_name, e.first_name FROM grh_bulletins b JOIN grh_employes e ON e.id = b.employe_id WHERE b.paie_id = ?', [$id]);
            if (!$bs) {
                throw new BusinessException('Aucun bulletin à valider.');
            }
            foreach ($bs as $b) {
                if ((float) $b['net'] < 0) {
                    throw new BusinessException('Net négatif pour ' . $b['first_name'] . ' ' . $b['last_name'] . ' (' . money($b['net']) . ') : réduisez les retenues.');
                }
                $left = (float) $b['advance'];
                foreach (DB::all("SELECT * FROM grh_avances WHERE employe_id = ? AND status = 'en_cours' ORDER BY granted_on, id FOR UPDATE", [$b['employe_id']]) as $a) {
                    if ($left <= 0) {
                        break;
                    }
                    $take = min($left, (float) $a['monthly'], (float) $a['remaining']);
                    $rem = round((float) $a['remaining'] - $take, 2);
                    DB::update('grh_avances', ['remaining' => $rem, 'status' => $rem <= 0 ? 'soldee' : 'en_cours'], 'id = ?', [$a['id']]);
                    $left -= $take;
                }
            }
            DB::update('grh_paies', ['status' => 'validee', 'validated_by' => Auth::id(), 'validated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            Audit::log('grh.paie.validee', 'grh_paies', $id, ['period' => $p['period'], 'net' => array_sum(array_column($bs, 'net'))]);
        });
    }

    public static function payPaie(int $id): void
    {
        $p = DB::one('SELECT * FROM grh_paies WHERE id = ?', [$id]);
        if (!$p || $p['status'] !== 'validee') {
            throw new BusinessException('La paie doit être validée avant d\'être marquée payée.');
        }
        DB::update('grh_paies', ['status' => 'payee', 'paid_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        Audit::log('grh.paie.payee', 'grh_paies', $id, ['period' => $p['period']]);
    }

    public static function paieTotals(int $id): array
    {
        return DB::one('SELECT COUNT(*) AS n, COALESCE(SUM(gross), 0) AS gross, COALESCE(SUM(cnss), 0) AS cnss, COALESCE(SUM(tax), 0) AS tax, COALESCE(SUM(advance), 0) AS advance,
            COALESCE(SUM(other_deduction), 0) AS other_deduction, COALESCE(SUM(net), 0) AS net, COALESCE(SUM(employer_charges), 0) AS employer_charges FROM grh_bulletins WHERE paie_id = ?', [$id]);
    }

    // ------------------------------------------------------------------ dossier, tableau de bord

    public static function addDossier(int $employeId, array $in): int
    {
        self::employe($employeId);
        $kind = (string) ($in['kind'] ?? 'note');
        $title = self::txt($in['title'] ?? '', 180);
        if (!isset(self::DOSSIER_KINDS[$kind]) || !$title) {
            throw new BusinessException('Type et intitulé obligatoires.');
        }
        $score = trim((string) ($in['score'] ?? '')) === '' ? null : self::num($in['score']);
        if ($score !== null && ($score < 0 || $score > 20)) {
            throw new BusinessException('Note d\'évaluation entre 0 et 20.');
        }
        $id = DB::insert('grh_dossier', ['employe_id' => $employeId, 'kind' => $kind, 'event_date' => self::date($in['event_date'] ?? date('Y-m-d'), 'Date', true), 'title' => $title,
            'detail' => self::txt($in['detail'] ?? '', 5000), 'score' => $kind === 'evaluation' ? $score : null, 'created_by' => Auth::id()]);
        Audit::log('grh.dossier.' . $kind, 'grh_dossier', $id, ['employe' => $employeId]);
        return $id;
    }

    public static function dashboard(): array
    {
        $today = date('Y-m-d');
        $days = max(1, (int) setting('grh_alerte_contrat_jours', '30'));
        return [
            'actifs' => (int) DB::value("SELECT COUNT(*) FROM grh_employes WHERE status = 'actif'"),
            'femmes' => (int) DB::value("SELECT COUNT(*) FROM grh_employes WHERE status = 'actif' AND sex = 'F'"),
            'suspendus' => (int) DB::value("SELECT COUNT(*) FROM grh_employes WHERE status = 'suspendu'"),
            'masse' => (float) DB::value("SELECT COALESCE(SUM(c.base_salary + c.allowances), 0) FROM grh_contrats c JOIN grh_employes e ON e.id = c.employe_id WHERE c.status = 'en_cours' AND e.status = 'actif'"),
            'sansContrat' => DB::all("SELECT e.id, e.matricule, e.first_name, e.last_name FROM grh_employes e WHERE e.status = 'actif' AND NOT EXISTS (SELECT 1 FROM grh_contrats c WHERE c.employe_id = e.id AND c.status = 'en_cours') ORDER BY e.last_name"),
            'finContrats' => DB::all("SELECT c.*, e.matricule, e.first_name, e.last_name FROM grh_contrats c JOIN grh_employes e ON e.id = c.employe_id WHERE c.status = 'en_cours' AND e.status <> 'sorti' AND c.end_date IS NOT NULL AND c.end_date <= ? ORDER BY c.end_date", [date('Y-m-d', strtotime("+$days days"))]),
            'alertDays' => $days,
            'demandes' => (int) DB::value("SELECT COUNT(*) FROM grh_conges WHERE status = 'demande'"),
            'enConge' => DB::all("SELECT g.*, e.first_name, e.last_name FROM grh_conges g JOIN grh_employes e ON e.id = g.employe_id WHERE g.status = 'approuve' AND g.start_date <= ? AND g.end_date >= ? ORDER BY g.end_date", [$today, $today]),
            'parDept' => DB::all("SELECT COALESCE(d.name, 'Sans département') AS name, COUNT(*) AS n FROM grh_employes e LEFT JOIN grh_departements d ON d.id = e.departement_id WHERE e.status = 'actif' GROUP BY d.id, d.name ORDER BY n DESC"),
            'parContrat' => DB::all("SELECT c.kind, COUNT(*) AS n FROM grh_contrats c JOIN grh_employes e ON e.id = c.employe_id WHERE c.status = 'en_cours' AND e.status = 'actif' GROUP BY c.kind ORDER BY n DESC"),
            'pointage' => DB::all('SELECT status, COUNT(*) AS n FROM grh_presences WHERE day = ? GROUP BY status', [$today]),
            'avances' => (float) DB::value("SELECT COALESCE(SUM(remaining), 0) FROM grh_avances WHERE status = 'en_cours'"),
            'dernierePaie' => DB::one('SELECT p.*, (SELECT COALESCE(SUM(net), 0) FROM grh_bulletins b WHERE b.paie_id = p.id) AS net FROM grh_paies p ORDER BY p.period DESC LIMIT 1'),
        ];
    }

    /** Organigramme : départements en arbre, avec responsable et effectif. */
    public static function tree(): array
    {
        $deps = DB::all("SELECT d.*, CONCAT(m.first_name, ' ', m.last_name) AS manager, pm.title AS manager_poste,
              (SELECT COUNT(*) FROM grh_employes e WHERE e.departement_id = d.id AND e.status = 'actif') AS n FROM grh_departements d
              LEFT JOIN grh_employes m ON m.id = d.manager_id LEFT JOIN grh_postes pm ON pm.id = m.poste_id ORDER BY d.name");
        $by = [];
        foreach ($deps as $d) {
            $by[(int) ($d['parent_id'] ?? 0)][] = $d;
        }
        $build = function (int $parent, int $depth) use (&$build, $by): array {
            $out = [];
            foreach ($by[$parent] ?? [] as $d) {
                $d['depth'] = $depth;
                $d['staff'] = DB::all("SELECT e.id, e.first_name, e.last_name, p.title AS poste FROM grh_employes e LEFT JOIN grh_postes p ON p.id = e.poste_id WHERE e.departement_id = ? AND e.status = 'actif' ORDER BY e.last_name", [$d['id']]);
                $d['children'] = $depth < 8 ? $build((int) $d['id'], $depth + 1) : [];
                $out[] = $d;
            }
            return $out;
        };
        return $build(0, 0);
    }
}
