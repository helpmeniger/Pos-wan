<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\Sequence;

/** Hospitalisation et mise en observation : lits, plan de soins, surveillance, sortie. */
final class HospitalService
{
    public const DISCHARGE = ['gueri' => 'Guéri', 'ameliore' => 'Amélioré', 'transfert' => 'Transféré / évacué', 'contre_avis' => 'Sortie contre avis médical', 'deces' => 'Décédé', 'autre' => 'Autre'];
    public const CARE_KINDS = ['medication' => 'Médicament', 'care' => 'Soin', 'monitoring' => 'Surveillance'];

    public static function admit(array $d): int
    {
        $pid = (int) ($d['patient_id'] ?? 0);
        ClinicService::patient($pid);
        $bed = (int) ($d['bed_id'] ?? 0);
        $reason = trim((string) ($d['reason'] ?? ''));
        if ($reason === '') {
            throw new BusinessException('Le motif d\'hospitalisation est obligatoire.');
        }
        return DB::transaction(function () use ($pid, $bed, $reason, $d) {
            if (!DB::value('SELECT 1 FROM clinic_beds WHERE id = ? AND active = 1 FOR UPDATE', [$bed])) {
                throw new BusinessException('Lit introuvable.');
            }
            if (DB::value("SELECT 1 FROM clinic_stays WHERE bed_id = ? AND status = 'admitted'", [$bed])) {
                throw new BusinessException('Ce lit est déjà occupé.');
            }
            if (DB::value("SELECT 1 FROM clinic_stays WHERE patient_id = ? AND status = 'admitted'", [$pid])) {
                throw new BusinessException('Ce patient est déjà hospitalisé.');
            }
            $id = DB::insert('clinic_stays', ['number' => Sequence::next('clinic_stay'), 'patient_id' => $pid, 'bed_id' => $bed, 'doctor_id' => Auth::id(),
                'visit_id' => (int) ($d['visit_id'] ?? 0) ?: null, 'kind' => ($d['kind'] ?? '') === 'observation' ? 'observation' : 'hospitalisation',
                'reason' => mb_substr($reason, 0, 255), 'diagnosis' => mb_substr(trim((string) ($d['diagnosis'] ?? '')), 0, 500) ?: null,
                'admitted_at' => date('Y-m-d H:i:s'), 'user_id' => Auth::id()]);
            Audit::log('clinique.stay.admit', 'clinic_stay', $id, ['bed' => $bed]);
            return $id;
        });
    }

    public static function stay(int $id, bool $mustBeOpen = false): array
    {
        $s = DB::one('SELECT * FROM clinic_stays WHERE id = ?', [$id]);
        if (!$s) {
            throw new BusinessException('Hospitalisation introuvable.');
        }
        if ($mustBeOpen && $s['status'] !== 'admitted') {
            throw new BusinessException('Ce patient est déjà sorti.');
        }
        return $s;
    }

    public static function addCare(int $stayId, array $d): int
    {
        self::stay($stayId, true);
        $label = trim((string) ($d['label'] ?? ''));
        $every = (int) ($d['every_hours'] ?? 8);
        if ($label === '' || $every < 1 || $every > 168) {
            throw new BusinessException('Libellé et fréquence (1 à 168 h) obligatoires.');
        }
        $start = str_replace('T', ' ', (string) ($d['start_at'] ?? ''));
        $start = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $start) ? substr($start, 0, 16) . ':00' : date('Y-m-d H:i:s');
        $days = (int) ($d['days'] ?? 0);
        $id = DB::insert('clinic_care_plan', ['stay_id' => $stayId, 'kind' => isset(self::CARE_KINDS[$d['kind'] ?? '']) ? $d['kind'] : 'medication',
            'label' => mb_substr($label, 0, 150), 'dose' => mb_substr(trim((string) ($d['dose'] ?? '')), 0, 100) ?: null, 'route' => mb_substr(trim((string) ($d['route'] ?? '')), 0, 50) ?: null,
            'every_hours' => $every, 'next_due_at' => $start, 'end_at' => $days > 0 ? date('Y-m-d H:i:s', strtotime($start . ' +' . $days . ' days')) : null, 'prescriber_id' => Auth::id()]);
        Audit::log('clinique.care.add', 'clinic_stay', $stayId, ['plan' => $id, 'label' => $label]);
        return $id;
    }

    /** Soin effectué (ou refusé / non fait) : prochaine échéance recalculée. */
    public static function logCare(int $planId, string $status = 'done', ?string $note = null): void
    {
        if (!in_array($status, ['done', 'refused', 'skipped'], true)) {
            throw new BusinessException('Statut de soin invalide.');
        }
        DB::transaction(function () use ($planId, $status, $note) {
            $p = DB::one('SELECT * FROM clinic_care_plan WHERE id = ? FOR UPDATE', [$planId]);
            if (!$p || !(int) $p['active']) {
                throw new BusinessException('Soin introuvable ou arrêté.');
            }
            self::stay((int) $p['stay_id'], true);
            DB::insert('clinic_care_log', ['plan_id' => $planId, 'stay_id' => $p['stay_id'], 'status' => $status, 'note' => $note !== null ? mb_substr(trim($note), 0, 255) ?: null : null, 'user_id' => Auth::id()]);
            $next = strtotime($p['next_due_at']) + (int) $p['every_hours'] * 3600;
            if ($next < time()) {
                $next = time() + (int) $p['every_hours'] * 3600; // très en retard : on repart de maintenant
            }
            $active = $p['end_at'] === null || $next <= strtotime($p['end_at']) ? 1 : 0;
            DB::update('clinic_care_plan', ['next_due_at' => date('Y-m-d H:i:s', $next), 'active' => $active], 'id = ?', [$planId]);
        });
    }

    public static function stopCare(int $planId): void
    {
        DB::update('clinic_care_plan', ['active' => 0], 'id = ?', [$planId]);
        Audit::log('clinique.care.stop', 'clinic_care_plan', $planId);
    }

    public static function addNote(int $stayId, string $note, string $kind): void
    {
        self::stay($stayId, true);
        if (trim($note) === '') {
            throw new BusinessException('Note vide.');
        }
        DB::insert('clinic_notes', ['stay_id' => $stayId, 'kind' => $kind === 'doctor' ? 'doctor' : 'nurse', 'note' => mb_substr(trim($note), 0, 5000), 'user_id' => Auth::id()]);
    }

    public static function transfer(int $stayId, int $bedId): void
    {
        DB::transaction(function () use ($stayId, $bedId) {
            $s = DB::one("SELECT * FROM clinic_stays WHERE id = ? AND status = 'admitted' FOR UPDATE", [$stayId]);
            if (!$s) {
                throw new BusinessException('Hospitalisation introuvable ou clôturée.');
            }
            if (DB::value("SELECT 1 FROM clinic_stays WHERE bed_id = ? AND status = 'admitted'", [$bedId]) || !DB::value('SELECT 1 FROM clinic_beds WHERE id = ? AND active = 1', [$bedId])) {
                throw new BusinessException('Lit indisponible.');
            }
            DB::update('clinic_stays', ['bed_id' => $bedId], 'id = ?', [$stayId]);
            self::addNote($stayId, 'Transfert du lit ' . DB::value('SELECT name FROM clinic_beds WHERE id = ?', [$s['bed_id']]) . ' vers ' . DB::value('SELECT name FROM clinic_beds WHERE id = ?', [$bedId]), 'nurse');
            Audit::log('clinique.stay.transfer', 'clinic_stay', $stayId, ['from' => $s['bed_id'], 'to' => $bedId]);
        });
    }

    /** Nombre de journées facturables (toute journée commencée est due, minimum 1). */
    public static function days(array $s): int
    {
        $end = $s['discharged_at'] ? strtotime($s['discharged_at']) : time();
        return max(1, (int) ceil(($end - strtotime($s['admitted_at'])) / 86400));
    }

    /** Sortie du patient + facture du séjour (journées × prix du lit + actes). */
    public static function discharge(int $stayId, string $mode, string $note, array $acts = []): int
    {
        if (!isset(self::DISCHARGE[$mode])) {
            throw new BusinessException('Mode de sortie invalide.');
        }
        return DB::transaction(function () use ($stayId, $mode, $note, $acts) {
            $s = DB::one("SELECT st.*, b.name AS bed, b.daily_price FROM clinic_stays st JOIN clinic_beds b ON b.id = st.bed_id WHERE st.id = ? AND st.status = 'admitted' FOR UPDATE", [$stayId]);
            if (!$s) {
                throw new BusinessException('Hospitalisation introuvable ou déjà clôturée.');
            }
            $now = date('Y-m-d H:i:s');
            DB::update('clinic_stays', ['status' => 'discharged', 'discharged_at' => $now, 'discharge_mode' => $mode, 'discharge_note' => mb_substr(trim($note), 0, 500) ?: null], 'id = ?', [$stayId]);
            DB::run('UPDATE clinic_care_plan SET active = 0 WHERE stay_id = ?', [$stayId]);
            $days = self::days($s + ['discharged_at' => $now]);
            $lines = [['label' => ($s['kind'] === 'observation' ? 'Mise en observation' : 'Hospitalisation') . ' — ' . $s['bed'] . ' (' . $days . ' jour' . ($days > 1 ? 's' : '') . ')', 'quantity' => $days, 'unit_price' => (float) $s['daily_price'], 'system' => true]];
            foreach ($acts as $a) {
                if (!empty($a['act_id'])) {
                    $lines[] = $a;
                }
            }
            $billId = ClinicService::createBill((int) $s['patient_id'], $lines, null, $stayId);
            DB::update('clinic_stays', ['bill_id' => $billId], 'id = ?', [$stayId]);
            Audit::log('clinique.stay.discharge', 'clinic_stay', $stayId, ['mode' => $mode, 'days' => $days]);
            return $billId;
        });
    }

    /** Données de l'écran de supervision : tous les lits, patients, dernières constantes, soins dus. */
    public static function board(): array
    {
        $hours = max(1, (int) setting('clinique_constantes_heures', '6'));
        $beds = DB::all('SELECT b.id, b.name, r.name AS room, r.ward FROM clinic_beds b JOIN clinic_rooms r ON r.id = b.room_id WHERE b.active = 1 ORDER BY r.sort_order, r.name, b.name');
        $stays = array_column(DB::all("SELECT s.*, p.last_name, p.first_name, p.sex, p.birth_date, p.allergies, u.full_name AS doctor FROM clinic_stays s JOIN clinic_patients p ON p.id = s.patient_id LEFT JOIN users u ON u.id = s.doctor_id WHERE s.status = 'admitted'"), null, 'bed_id');
        $out = [];
        $totals = ['patients' => 0, 'alerts' => 0, 'late' => 0, 'due' => 0, 'vitals' => 0];
        foreach ($beds as $b) {
            $s = $stays[$b['id']] ?? null;
            $card = ['bed' => $b['name'], 'room' => $b['room'], 'ward' => $b['ward'], 'stay' => null];
            if ($s) {
                $v = DB::one('SELECT * FROM clinic_vitals WHERE stay_id = ? ORDER BY taken_at DESC, id DESC LIMIT 1', [$s['id']]);
                $alerts = ClinicService::alerts($v);
                $care = DB::all('SELECT id, kind, label, dose, route, next_due_at, TIMESTAMPDIFF(MINUTE, NOW(), next_due_at) AS in_min FROM clinic_care_plan WHERE stay_id = ? AND active = 1 ORDER BY next_due_at LIMIT 6', [$s['id']]);
                $late = count(array_filter($care, fn($c) => (int) $c['in_min'] < 0));
                $due = count(array_filter($care, fn($c) => (int) $c['in_min'] >= 0 && (int) $c['in_min'] <= 60));
                $vitalsOld = !$v || strtotime($v['taken_at']) < time() - $hours * 3600;
                $card['stay'] = [
                    'id' => (int) $s['id'], 'number' => $s['number'], 'kind' => $s['kind'], 'patient' => $s['last_name'] . ' ' . $s['first_name'], 'sex' => $s['sex'],
                    'age' => ClinicService::age($s['birth_date']), 'allergies' => $s['allergies'], 'reason' => $s['reason'], 'diagnosis' => $s['diagnosis'], 'doctor' => $s['doctor'],
                    'day' => self::days($s), 'since' => substr($s['admitted_at'], 0, 16),
                    'vitals' => $v ? ['t' => $v['temperature'], 'ta' => $v['systolic'] ? $v['systolic'] . '/' . $v['diastolic'] : null, 'p' => $v['pulse'], 'spo2' => $v['spo2'], 'gly' => $v['glycemia'], 'at' => substr($v['taken_at'], 11, 5), 'date' => substr($v['taken_at'], 0, 10)] : null,
                    'alerts' => array_values($alerts), 'flags' => array_keys($alerts), 'vitals_old' => $vitalsOld,
                    'care' => array_map(fn($c) => ['id' => (int) $c['id'], 'label' => $c['label'], 'dose' => $c['dose'], 'route' => $c['route'], 'at' => substr($c['next_due_at'], 11, 5), 'min' => (int) $c['in_min']], $care),
                    'late' => $late, 'due' => $due,
                ];
                $totals['patients']++;
                $totals['alerts'] += $alerts ? 1 : 0;
                $totals['late'] += $late;
                $totals['due'] += $due;
                $totals['vitals'] += $vitalsOld ? 1 : 0;
            }
            $out[] = $card;
        }
        // Lits en alerte d'abord, puis soins en retard
        $key = fn($c) => [$c['stay'] && $c['stay']['alerts'] ? 1 : 0, $c['stay']['late'] ?? 0, $c['stay'] ? 1 : 0];
        usort($out, fn($a, $b) => $key($b) <=> $key($a));
        return ['beds' => $out, 'totals' => $totals, 'now' => date('H:i'), 'free' => count(array_filter($out, fn($c) => $c['stay'] === null))];
    }
}
