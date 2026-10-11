<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\CashService;
use App\Services\Sequence;

/** Patients, rendez-vous, consultations, constantes et facturation de la clinique. */
final class ClinicService
{
    public const APPT_STATUSES = ['planned' => 'Prévu', 'arrived' => 'Arrivé', 'done' => 'Vu', 'no_show' => 'Absent', 'cancelled' => 'Annulé'];
    public const VISIT_STATUSES = ['waiting' => 'En attente', 'in_progress' => 'En consultation', 'done' => 'Terminée', 'cancelled' => 'Annulée'];

    // ------------------------------------------------------------------ patients

    public static function savePatient(array $d, ?int $id = null): int
    {
        $last = mb_strtoupper(trim((string) ($d['last_name'] ?? '')));
        $first = trim((string) ($d['first_name'] ?? ''));
        $sex = (string) ($d['sex'] ?? '');
        if ($last === '' || $first === '' || !in_array($sex, ['M', 'F'], true)) {
            throw new BusinessException('Nom, prénom et sexe du patient sont obligatoires.');
        }
        $birth = (string) ($d['birth_date'] ?? '');
        if ($birth !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) || $birth > date('Y-m-d'))) {
            throw new BusinessException('Date de naissance invalide.');
        }
        $cov = (float) str_replace(',', '.', (string) ($d['coverage_pct'] ?? '0'));
        if ($cov < 0 || $cov > 100) {
            throw new BusinessException('Le taux de prise en charge doit être entre 0 et 100 %.');
        }
        $blood = (string) ($d['blood_group'] ?? '');
        $row = [
            'last_name' => mb_substr($last, 0, 100), 'first_name' => mb_substr($first, 0, 100), 'sex' => $sex, 'birth_date' => $birth ?: null,
            'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 50) ?: null, 'address' => mb_substr(trim((string) ($d['address'] ?? '')), 0, 255) ?: null,
            'blood_group' => in_array($blood, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true) ? $blood : null,
            'allergies' => mb_substr(trim((string) ($d['allergies'] ?? '')), 0, 500) ?: null, 'history' => trim((string) ($d['history'] ?? '')) ?: null,
            'emergency_contact' => mb_substr(trim((string) ($d['emergency_contact'] ?? '')), 0, 150) ?: null,
            'insurer' => mb_substr(trim((string) ($d['insurer'] ?? '')), 0, 100) ?: null, 'coverage_pct' => round($cov, 2),
        ];
        if ($id) {
            DB::update('clinic_patients', $row, 'id = ?', [$id]);
            Audit::log('clinique.patient.update', 'clinic_patient', $id);
            return $id;
        }
        $id = DB::insert('clinic_patients', $row + ['number' => Sequence::next('clinic_patient'), 'user_id' => Auth::id()]);
        Audit::log('clinique.patient.create', 'clinic_patient', $id);
        return $id;
    }

    public static function age(?string $birth): string
    {
        if (!$birth) {
            return '';
        }
        $d = (new \DateTime($birth))->diff(new \DateTime());
        return $d->y >= 2 ? $d->y . ' ans' : ($d->y * 12 + $d->m) . ' mois';
    }

    public static function patient(int $id): array
    {
        $p = DB::one('SELECT * FROM clinic_patients WHERE id = ?', [$id]);
        if (!$p) {
            throw new BusinessException('Patient introuvable.');
        }
        return $p;
    }

    // ------------------------------------------------------------------ rendez-vous

    public static function addAppointment(array $d): int
    {
        $pid = (int) ($d['patient_id'] ?? 0);
        self::patient($pid);
        $start = str_replace('T', ' ', (string) ($d['start_at'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $start)) {
            throw new BusinessException('Date et heure du rendez-vous obligatoires.');
        }
        $doc = (int) ($d['practitioner_id'] ?? 0) ?: null;
        $dur = max(5, min(240, (int) ($d['duration_min'] ?? 20)));
        if ($doc && DB::value("SELECT 1 FROM clinic_appointments WHERE practitioner_id = ? AND status IN ('planned','arrived') AND start_at < ? + INTERVAL ? MINUTE AND start_at + INTERVAL duration_min MINUTE > ?", [$doc, $start, $dur, $start])) {
            throw new BusinessException('Ce praticien a déjà un rendez-vous sur ce créneau.');
        }
        $id = DB::insert('clinic_appointments', ['patient_id' => $pid, 'practitioner_id' => $doc, 'start_at' => substr($start, 0, 16) . ':00', 'duration_min' => $dur,
            'reason' => mb_substr(trim((string) ($d['reason'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id()]);
        Audit::log('clinique.rdv.create', 'clinic_appointment', $id);
        return $id;
    }

    public static function setAppointmentStatus(int $id, string $status): ?int
    {
        if (!isset(self::APPT_STATUSES[$status])) {
            throw new BusinessException('Statut invalide.');
        }
        return DB::transaction(function () use ($id, $status) {
            $a = DB::one('SELECT * FROM clinic_appointments WHERE id = ? FOR UPDATE', [$id]);
            if (!$a || in_array($a['status'], ['done', 'cancelled'], true)) {
                throw new BusinessException('Rendez-vous introuvable ou déjà clôturé.');
            }
            $visit = null;
            if ($status === 'arrived' && !$a['visit_id']) {
                // Arrivée du patient : il entre dans la file d'attente de consultation
                $visit = self::openVisit(['patient_id' => $a['patient_id'], 'practitioner_id' => $a['practitioner_id'], 'reason' => $a['reason']], $id);
            }
            DB::update('clinic_appointments', ['status' => $status] + ($visit ? ['visit_id' => $visit] : []), 'id = ?', [$id]);
            return $visit;
        });
    }

    // ------------------------------------------------------------------ consultations

    /** Admission à l'accueil : ouvre une consultation « en attente » (avec constantes éventuelles). */
    public static function openVisit(array $d, ?int $appointmentId = null): int
    {
        $pid = (int) ($d['patient_id'] ?? 0);
        self::patient($pid);
        if (DB::value("SELECT id FROM clinic_visits WHERE patient_id = ? AND status IN ('waiting','in_progress')", [$pid])) {
            throw new BusinessException('Ce patient est déjà dans la file d\'attente.');
        }
        $id = DB::insert('clinic_visits', ['number' => Sequence::next('clinic_visit'), 'patient_id' => $pid, 'practitioner_id' => (int) ($d['practitioner_id'] ?? 0) ?: null,
            'priority' => ($d['priority'] ?? '') === 'urgent' ? 'urgent' : 'normal', 'reason' => mb_substr(trim((string) ($d['reason'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id()]);
        if (!empty($d['vitals']) && array_filter((array) $d['vitals'], fn($v) => $v !== '' && $v !== null)) {
            self::addVitals(['patient_id' => $pid, 'visit_id' => $id] + (array) $d['vitals']);
        }
        Audit::log('clinique.visit.open', 'clinic_visit', $id, ['appointment' => $appointmentId]);
        return $id;
    }

    /** Constantes : contrôle des valeurs plausibles (évite les erreurs de saisie). */
    public static function addVitals(array $d): int
    {
        $limits = ['temperature' => [30, 45], 'systolic' => [40, 300], 'diastolic' => [20, 200], 'pulse' => [20, 250], 'spo2' => [40, 100],
            'resp_rate' => [4, 80], 'glycemia' => [0.1, 8], 'weight' => [0.3, 400], 'height' => [20, 250], 'pain' => [0, 10]];
        $row = [];
        foreach ($limits as $k => [$min, $max]) {
            $v = trim(str_replace(',', '.', (string) ($d[$k] ?? '')));
            if ($v === '') {
                continue;
            }
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                throw new BusinessException('Valeur invalide pour « ' . $k . ' » (entre ' . $min . ' et ' . $max . ').');
            }
            $row[$k] = $v + 0;
        }
        if (!$row) {
            throw new BusinessException('Saisissez au moins une constante.');
        }
        $id = DB::insert('clinic_vitals', $row + ['patient_id' => (int) $d['patient_id'], 'visit_id' => $d['visit_id'] ?? null, 'stay_id' => $d['stay_id'] ?? null,
            'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id()]);
        return $id;
    }

    /** Alertes d'un relevé selon les seuils des paramètres : [t|ta|p|spo2|gly => message]. */
    public static function alerts(?array $v): array
    {
        if (!$v) {
            return [];
        }
        $s = fn(string $k, string $d) => (float) setting('clinique_alerte_' . $k, $d);
        $a = [];
        if ($v['temperature'] !== null && (float) $v['temperature'] >= $s('temp_haute', '38.5')) {
            $a['t'] = 'Fièvre ' . (float) $v['temperature'] . ' °C';
        }
        if ($v['temperature'] !== null && (float) $v['temperature'] <= $s('temp_basse', '35.5')) {
            $a['t'] = 'Hypothermie ' . (float) $v['temperature'] . ' °C';
        }
        if ($v['systolic'] !== null && (int) $v['systolic'] >= $s('tas_haute', '160')) {
            $a['ta'] = 'TA élevée ' . $v['systolic'] . '/' . $v['diastolic'];
        }
        if ($v['systolic'] !== null && (int) $v['systolic'] <= $s('tas_basse', '90')) {
            $a['ta'] = 'TA basse ' . $v['systolic'] . '/' . $v['diastolic'];
        }
        if ($v['pulse'] !== null && ((int) $v['pulse'] >= $s('pouls_haut', '120') || (int) $v['pulse'] <= $s('pouls_bas', '50'))) {
            $a['p'] = 'Pouls ' . $v['pulse'] . '/min';
        }
        if ($v['spo2'] !== null && (int) $v['spo2'] < $s('spo2', '92')) {
            $a['spo2'] = 'SpO₂ ' . $v['spo2'] . ' %';
        }
        if ($v['glycemia'] !== null && ((float) $v['glycemia'] >= $s('glycemie_haute', '2.5') || (float) $v['glycemia'] <= $s('glycemie_basse', '0.6'))) {
            $a['gly'] = 'Glycémie ' . (float) $v['glycemia'] . ' g/L';
        }
        return $a;
    }

    public static function startVisit(int $id): void
    {
        DB::transaction(function () use ($id) {
            $v = DB::one('SELECT * FROM clinic_visits WHERE id = ? FOR UPDATE', [$id]);
            if (!$v || $v['status'] !== 'waiting') {
                throw new BusinessException('Consultation introuvable ou déjà commencée.');
            }
            DB::update('clinic_visits', ['status' => 'in_progress', 'started_at' => date('Y-m-d H:i:s'), 'practitioner_id' => Auth::id()], 'id = ?', [$id]);
        });
    }

    /** Enregistre l'observation médicale ; $close = terminer la consultation. */
    public static function saveVisit(int $id, array $d, bool $close): void
    {
        DB::transaction(function () use ($id, $d, $close) {
            $v = DB::one('SELECT * FROM clinic_visits WHERE id = ? FOR UPDATE', [$id]);
            if (!$v || in_array($v['status'], ['done', 'cancelled'], true)) {
                throw new BusinessException('Cette consultation est clôturée.');
            }
            if ($close && trim((string) ($d['diagnosis'] ?? '')) === '') {
                throw new BusinessException('Indiquez le diagnostic avant de terminer la consultation.');
            }
            DB::update('clinic_visits', [
                'anamnesis' => trim((string) ($d['anamnesis'] ?? '')) ?: null, 'exam' => trim((string) ($d['exam'] ?? '')) ?: null,
                'diagnosis' => mb_substr(trim((string) ($d['diagnosis'] ?? '')), 0, 500) ?: null, 'conclusion' => trim((string) ($d['conclusion'] ?? '')) ?: null,
                'status' => $close ? 'done' : 'in_progress', 'closed_at' => $close ? date('Y-m-d H:i:s') : null,
                'started_at' => $v['started_at'] ?? date('Y-m-d H:i:s'), 'practitioner_id' => $v['practitioner_id'] ?: Auth::id(),
            ], 'id = ?', [$id]);
            DB::run("UPDATE clinic_appointments SET status = 'done' WHERE visit_id = ? AND ? = 1", [$id, $close ? 1 : 0]);
            Audit::log($close ? 'clinique.visit.close' : 'clinique.visit.save', 'clinic_visit', $id);
        });
    }

    // ------------------------------------------------------------------ facturation

    /**
     * Facture : lignes [{act_id, quantity}] (prix du catalogue) ou [{label, quantity, unit_price}]
     * (lignes libres, droit clinique.manage). Prise en charge selon le dossier du patient.
     */
    public static function createBill(int $patientId, array $lines, ?int $visitId = null, ?int $stayId = null): int
    {
        $p = self::patient($patientId);
        $acts = array_column(DB::all('SELECT * FROM clinic_acts WHERE active = 1'), null, 'id');
        $items = [];
        foreach ($lines as $l) {
            $q = round((float) str_replace(',', '.', (string) ($l['quantity'] ?? '1')), 2);
            if ($q <= 0 || $q > 1000) {
                continue;
            }
            if (!empty($l['act_id'])) {
                $a = $acts[(int) $l['act_id']] ?? null;
                if (!$a) {
                    throw new BusinessException('Acte inconnu ou désactivé.');
                }
                $items[] = ['act_id' => (int) $a['id'], 'label' => $a['name'], 'quantity' => $q, 'unit_price' => (float) $a['price'], 'total' => round($q * (float) $a['price'], 2)];
            } elseif (trim((string) ($l['label'] ?? '')) !== '') {
                if (empty($l['system']) && !Auth::can('clinique.manage')) {
                    throw new BusinessException('Les lignes libres sont réservées au responsable.');
                }
                $price = round((float) $l['unit_price'], 2);
                $items[] = ['act_id' => null, 'label' => mb_substr(trim((string) $l['label']), 0, 150), 'quantity' => $q, 'unit_price' => $price, 'total' => round($q * $price, 2)];
            }
        }
        if (!$items) {
            throw new BusinessException('Ajoutez au moins un acte à facturer.');
        }
        $sub = round(array_sum(array_column($items, 'total')), 2);
        $cov = round($sub * (float) $p['coverage_pct'] / 100, 2);
        return DB::transaction(function () use ($p, $items, $sub, $cov, $visitId, $stayId) {
            $id = DB::insert('clinic_bills', ['number' => Sequence::next('clinic_bill'), 'patient_id' => $p['id'], 'visit_id' => $visitId, 'stay_id' => $stayId,
                'subtotal' => $sub, 'coverage_pct' => $p['coverage_pct'], 'insurer' => (float) $p['coverage_pct'] > 0 ? $p['insurer'] : null,
                'coverage_amount' => $cov, 'patient_amount' => round($sub - $cov, 2), 'status' => $sub - $cov <= 0 ? 'paid' : 'open', 'user_id' => Auth::id()]);
            foreach ($items as $it) {
                DB::insert('clinic_bill_items', $it + ['bill_id' => $id]);
            }
            if ($visitId) {
                DB::update('clinic_visits', ['bill_id' => $id], 'id = ?', [$visitId]);
            }
            Audit::log('clinique.bill.create', 'clinic_bill', $id, ['total' => $sub]);
            return $id;
        });
    }

    /** Encaissement de la part patient (caisse ouverte). Retourne la monnaie. */
    public static function payBill(int $id, array $pay): float
    {
        return DB::transaction(function () use ($id, $pay) {
            $b = DB::one('SELECT * FROM clinic_bills WHERE id = ? FOR UPDATE', [$id]);
            if (!$b || $b['status'] !== 'open') {
                throw new BusinessException('Facture introuvable, réglée ou annulée.');
            }
            [$method, $provider] = array_pad(explode('|', (string) ($pay['method'] ?? 'cash'), 2), 2, null);
            if (!isset(payment_methods()[$method])) {
                throw new BusinessException('Mode de paiement non autorisé.');
            }
            $due = round((float) $b['patient_amount'] - (float) $b['paid'], 2);
            $tendered = round((float) str_replace([' ', ','], ['', '.'], (string) ($pay['amount'] ?? '0')), 2);
            if ($tendered <= 0) {
                throw new BusinessException('Montant invalide.');
            }
            if ($tendered > $due && $method !== 'cash') {
                throw new BusinessException('Un paiement électronique ne peut pas dépasser le reste dû.');
            }
            $session = CashService::currentSession(Auth::user());
            if (!$session && setting('require_cash_session', '1') === '1') {
                throw new BusinessException('La caisse est fermée : ouvrez votre caisse (Ma caisse) avant d\'encaisser.');
            }
            $amount = min($tendered, $due);
            DB::insert('clinic_payments', ['bill_id' => $id, 'method' => $method, 'provider' => $method === 'mobile_money' ? mobile_money_provider($provider) : null, 'amount' => $amount,
                'reference' => mb_substr(trim((string) ($pay['reference'] ?? '')), 0, 100) ?: null, 'cash_session_id' => $session['id'] ?? null, 'user_id' => Auth::id()]);
            CashService::movement($session['id'] ?? null, 'clinique', $method, $amount, 'clinic_bill', $id, 'Clinique ' . $b['number']);
            $paid = round((float) $b['paid'] + $amount, 2);
            DB::update('clinic_bills', ['paid' => $paid, 'status' => $paid >= (float) $b['patient_amount'] - 0.009 ? 'paid' : 'open'], 'id = ?', [$id]);
            Audit::log('clinique.bill.pay', 'clinic_bill', $id, ['amount' => $amount]);
            return round($tendered - $amount, 2);
        });
    }

    public static function cancelBill(int $id, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        DB::transaction(function () use ($id, $reason) {
            $b = DB::one('SELECT * FROM clinic_bills WHERE id = ? FOR UPDATE', [$id]);
            if (!$b || $b['status'] === 'cancelled') {
                throw new BusinessException('Facture introuvable ou déjà annulée.');
            }
            if ((float) $b['paid'] > 0) {
                throw new BusinessException('Facture déjà encaissée : enregistrez un remboursement en caisse (retrait) puis annulez.');
            }
            DB::update('clinic_bills', ['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 255)], 'id = ?', [$id]);
            DB::run('UPDATE clinic_visits SET bill_id = NULL WHERE bill_id = ?', [$id]);
            DB::run('UPDATE clinic_stays SET bill_id = NULL WHERE bill_id = ?', [$id]);
            Audit::log('clinique.bill.cancel', 'clinic_bill', $id, ['reason' => $reason]);
        });
    }

    /** Médecins / praticiens : utilisateurs ayant le droit de consulter. */
    public static function practitioners(): array
    {
        return DB::all("SELECT DISTINCT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
                WHERE u.active = 1 AND (p.code = 'clinique.doctor' OR r.code = 'admin') ORDER BY u.full_name");
    }
}
