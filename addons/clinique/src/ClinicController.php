<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;

/** Accueil, patients, rendez-vous, consultations, factures, paramètres et rapport. */
final class ClinicController extends Controller
{
    private const ASSETS = ['addons/clinique/clinique.js'];

    /** Seuils d'alerte de l'écran de supervision : clé (sans « clinique_ ») => [libellé, défaut]. */
    public const THRESHOLDS = [
        'alerte_temp_haute' => ['Fièvre : T° ≥ (°C)', '38.5'], 'alerte_temp_basse' => ['Hypothermie : T° ≤ (°C)', '35.5'],
        'alerte_tas_haute' => ['TA systolique ≥ (mmHg)', '160'], 'alerte_tas_basse' => ['TA systolique ≤ (mmHg)', '90'],
        'alerte_pouls_haut' => ['Pouls ≥ (/min)', '120'], 'alerte_pouls_bas' => ['Pouls ≤ (/min)', '50'],
        'alerte_spo2' => ['SpO₂ < (%)', '92'], 'alerte_glycemie_haute' => ['Glycémie ≥ (g/L)', '2.5'], 'alerte_glycemie_basse' => ['Glycémie ≤ (g/L)', '0.6'],
        'constantes_heures' => ['Reprendre les constantes toutes les (h)', '6'],
    ];

    private function patientOr404(int $id): array
    {
        $p = DB::one('SELECT * FROM clinic_patients WHERE id = ?', [$id]);
        if (!$p) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function visitOr404(int $id): array
    {
        $v = DB::one('SELECT v.*, u.full_name AS practitioner FROM clinic_visits v LEFT JOIN users u ON u.id = v.practitioner_id WHERE v.id = ?', [$id]);
        if (!$v) {
            throw new HttpException(404);
        }
        return $v;
    }

    // ------------------------------------------------------------------ accueil

    public function dashboard(): void
    {
        $today = date('Y-m-d');
        view('clinique::dashboard', [
            'title' => 'Clinique — accueil',
            'appointments' => DB::all("SELECT a.*, p.last_name, p.first_name, p.number AS pnum, u.full_name AS doctor FROM clinic_appointments a JOIN clinic_patients p ON p.id = a.patient_id LEFT JOIN users u ON u.id = a.practitioner_id WHERE DATE(a.start_at) = ? ORDER BY a.start_at", [$today]),
            'queue' => DB::all("SELECT v.*, p.last_name, p.first_name, p.sex, p.birth_date, u.full_name AS doctor, TIMESTAMPDIFF(MINUTE, v.created_at, NOW()) AS wait_min FROM clinic_visits v JOIN clinic_patients p ON p.id = v.patient_id LEFT JOIN users u ON u.id = v.practitioner_id WHERE v.status IN ('waiting','in_progress') ORDER BY v.priority = 'urgent' DESC, v.id"),
            'stats' => [
                'hospital' => (int) DB::value("SELECT COUNT(*) FROM clinic_stays WHERE status = 'admitted'"),
                'beds' => (int) DB::value('SELECT COUNT(*) FROM clinic_beds WHERE active = 1'),
                'visits' => (int) DB::value('SELECT COUNT(*) FROM clinic_visits WHERE DATE(created_at) = ?', [$today]),
                'presc' => (int) DB::value("SELECT COUNT(*) FROM clinic_prescriptions WHERE status IN ('new','partial')"),
                'unpaid' => (float) DB::value("SELECT COALESCE(SUM(patient_amount - paid),0) FROM clinic_bills WHERE status = 'open'"),
                'cash' => (float) DB::value('SELECT COALESCE(SUM(amount),0) FROM clinic_payments WHERE DATE(created_at) = ?', [$today]),
            ],
            'practitioners' => ClinicService::practitioners(),
        ]);
    }

    // ------------------------------------------------------------------ patients

    public function patients(): void
    {
        $q = trim((string) Request::query('q', ''));
        $where = 'active = 1';
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $where .= ' AND (number LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR phone LIKE ? OR CONCAT(last_name, \' \', first_name) LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        view('clinique::patients', ['title' => 'Patients', 'p' => paginate("FROM clinic_patients WHERE $where", $params, '*', 'last_name, first_name'), 'q' => $q]);
    }

    public function patientForm(int $id = 0): void
    {
        view('clinique::patient_form', ['title' => $id ? 'Modifier le dossier' : 'Nouveau patient', 'p' => $id ? $this->patientOr404($id) : null]);
    }

    public function patientEdit(int $id): void
    {
        $this->patientForm($id);
    }

    public function patientStore(): void
    {
        $id = $this->attempt(fn() => ClinicService::savePatient($_POST), '/clinique/patients/create');
        flash('success', t('Dossier patient enregistré.'));
        redirect('/clinique/patients/' . $id);
    }

    public function patientUpdate(int $id): void
    {
        $this->patientOr404($id);
        $this->attempt(fn() => ClinicService::savePatient($_POST, $id), '/clinique/patients/' . $id . '/edit');
        flash('success', t('Dossier patient mis à jour.'));
        redirect('/clinique/patients/' . $id);
    }

    /** Dossier : partie administrative pour tous ; partie médicale réservée (accès journalisé). */
    public function patientShow(int $id): void
    {
        $p = $this->patientOr404($id);
        $medical = can('clinique.medical');
        $data = ['title' => $p['last_name'] . ' ' . $p['first_name'], 'p' => $p, 'medical' => $medical, 'practitioners' => ClinicService::practitioners(),
            'appointments' => DB::all('SELECT a.*, u.full_name AS doctor FROM clinic_appointments a LEFT JOIN users u ON u.id = a.practitioner_id WHERE a.patient_id = ? ORDER BY a.start_at DESC LIMIT 20', [$id]),
            'bills' => DB::all('SELECT * FROM clinic_bills WHERE patient_id = ? ORDER BY id DESC LIMIT 30', [$id]),
            'openVisit' => DB::one("SELECT id, status FROM clinic_visits WHERE patient_id = ? AND status IN ('waiting','in_progress')", [$id])];
        if ($medical) {
            Audit::log('clinique.dossier.view', 'clinic_patient', $id);
            $data += [
                'visits' => DB::all('SELECT v.*, u.full_name AS practitioner FROM clinic_visits v LEFT JOIN users u ON u.id = v.practitioner_id WHERE v.patient_id = ? ORDER BY v.id DESC', [$id]),
                'vitals' => DB::all('SELECT vt.*, u.full_name FROM clinic_vitals vt JOIN users u ON u.id = vt.user_id WHERE vt.patient_id = ? ORDER BY vt.taken_at DESC LIMIT 15', [$id]),
                'stays' => DB::all('SELECT s.*, b.name AS bed FROM clinic_stays s JOIN clinic_beds b ON b.id = s.bed_id WHERE s.patient_id = ? ORDER BY s.id DESC', [$id]),
                'prescriptions' => DB::all('SELECT pr.*, u.full_name AS prescriber FROM clinic_prescriptions pr JOIN users u ON u.id = pr.prescriber_id WHERE pr.patient_id = ? ORDER BY pr.id DESC', [$id]),
            ];
        }
        view('clinique::patient_show', $data);
    }

    /** Accueil : mise en file d'attente (avec constantes de triage). */
    public function admitQueue(int $id): void
    {
        $this->patientOr404($id);
        $vid = $this->attempt(fn() => ClinicService::openVisit(['patient_id' => $id, 'practitioner_id' => Request::int('practitioner_id'), 'priority' => Request::str('priority'),
            'reason' => Request::str('reason'), 'vitals' => can('clinique.nurse') ? (array) Request::input('vitals', []) : []]), '/clinique/patients/' . $id);
        flash('success', t('Patient placé dans la file d\'attente.'));
        redirect(can('clinique.medical') ? '/clinique/consultations/' . $vid : '/clinique');
    }

    // ------------------------------------------------------------------ rendez-vous

    public function agenda(): void
    {
        $day = (string) Request::query('d', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = date('Y-m-d');
        }
        view('clinique::agenda', ['title' => 'Rendez-vous', 'day' => $day,
            'rows' => DB::all('SELECT a.*, p.last_name, p.first_name, p.phone, p.number AS pnum, u.full_name AS doctor FROM clinic_appointments a JOIN clinic_patients p ON p.id = a.patient_id LEFT JOIN users u ON u.id = a.practitioner_id WHERE DATE(a.start_at) = ? ORDER BY a.start_at', [$day]),
            'practitioners' => ClinicService::practitioners(),
            'patients' => DB::all('SELECT id, number, last_name, first_name, phone FROM clinic_patients WHERE active = 1 ORDER BY last_name, first_name LIMIT 2000')]);
    }

    public function appointmentStore(): void
    {
        $back = '/clinique/rdv?d=' . substr(str_replace('T', ' ', Request::str('start_at')), 0, 10);
        $this->attempt(fn() => ClinicService::addAppointment($_POST), $back);
        flash('success', t('Rendez-vous enregistré.'));
        redirect('/clinique/rdv', ['d' => substr(Request::str('start_at'), 0, 10)]);
    }

    public function appointmentStatus(int $id): void
    {
        $a = DB::one('SELECT * FROM clinic_appointments WHERE id = ?', [$id]) ?? throw new HttpException(404);
        $this->attempt(fn() => ClinicService::setAppointmentStatus($id, Request::str('status')), '/clinique/rdv?d=' . substr($a['start_at'], 0, 10));
        flash('success', t('Rendez-vous mis à jour.'));
        redirect('/clinique/rdv', ['d' => substr($a['start_at'], 0, 10)]);
    }

    // ------------------------------------------------------------------ consultations

    public function visit(int $id): void
    {
        $v = $this->visitOr404($id);
        $p = $this->patientOr404((int) $v['patient_id']);
        Audit::log('clinique.dossier.view', 'clinic_patient', (int) $p['id'], ['visit' => $id]);
        view('clinique::visit', [
            'title' => 'Consultation ' . $v['number'], 'v' => $v, 'p' => $p,
            'vitals' => DB::all('SELECT vt.*, u.full_name FROM clinic_vitals vt JOIN users u ON u.id = vt.user_id WHERE vt.visit_id = ? ORDER BY vt.taken_at DESC', [$id]),
            'prescriptions' => DB::all('SELECT * FROM clinic_prescriptions WHERE visit_id = ? ORDER BY id', [$id]),
            'previous' => DB::all("SELECT id, number, created_at, diagnosis FROM clinic_visits WHERE patient_id = ? AND id <> ? AND status = 'done' ORDER BY id DESC LIMIT 5", [$p['id'], $id]),
            'acts' => DB::all('SELECT * FROM clinic_acts WHERE active = 1 ORDER BY category, name'),
            'bill' => $v['bill_id'] ? DB::one('SELECT * FROM clinic_bills WHERE id = ?', [$v['bill_id']]) : null,
            'drugs' => self::drugs(), 'beds' => self::freeBeds(), 'scripts' => self::ASSETS,
        ]);
    }

    /** Médicaments proposés à la prescription (articles en stock, médicaments d'abord). */
    public static function drugs(): array
    {
        return DB::all("SELECT p.id, p.name, p.dosage, COALESCE((SELECT SUM(quantity) FROM stock_levels sl WHERE sl.product_id = p.id), 0) AS stock FROM products p
                WHERE p.status = 'active' AND p.sellable = 1 AND p.sales_channel <> 'restaurant' ORDER BY p.is_medicine DESC, p.name LIMIT 3000");
    }

    public static function freeBeds(): array
    {
        return DB::all("SELECT b.id, b.name, r.name AS room, b.daily_price FROM clinic_beds b JOIN clinic_rooms r ON r.id = b.room_id
                WHERE b.active = 1 AND NOT EXISTS (SELECT 1 FROM clinic_stays s WHERE s.bed_id = b.id AND s.status = 'admitted') ORDER BY r.sort_order, b.name");
    }

    public function visitStart(int $id): void
    {
        $this->visitOr404($id);
        $this->attempt(fn() => ClinicService::startVisit($id), '/clinique/consultations/' . $id);
        redirect('/clinique/consultations/' . $id);
    }

    public function visitSave(int $id): void
    {
        $this->visitOr404($id);
        $close = Request::input('close') === '1';
        $this->attempt(fn() => ClinicService::saveVisit($id, $_POST, $close), '/clinique/consultations/' . $id);
        flash('success', $close ? t('Consultation terminée.') : t('Consultation enregistrée.'));
        redirect('/clinique/consultations/' . $id);
    }

    public function visitVitals(int $id): void
    {
        $v = $this->visitOr404($id);
        $this->attempt(fn() => ClinicService::addVitals(['patient_id' => $v['patient_id'], 'visit_id' => $id] + (array) Request::input('vitals', [])), '/clinique/consultations/' . $id);
        flash('success', t('Constantes enregistrées.'));
        redirect('/clinique/consultations/' . $id);
    }

    public function visitPrescription(int $id): void
    {
        $v = $this->visitOr404($id);
        $pid = $this->attempt(fn() => PharmacyLink::create((int) $v['patient_id'], $this->rowsFromForm('drugs'), $id, null, Request::str('presc_note')), '/clinique/consultations/' . $id);
        flash('success', t('Ordonnance enregistrée et transmise à la pharmacie.'));
        redirect('/clinique/consultations/' . $id, ['printp' => $pid]);
    }

    public function visitBill(int $id): void
    {
        $v = $this->visitOr404($id);
        if (!can('clinique.doctor') && !can('clinique.reception')) {
            throw new HttpException(403);
        }
        if ($v['bill_id']) {
            $this->fail('Cette consultation est déjà facturée.', '/clinique/consultations/' . $id);
        }
        $bid = $this->attempt(fn() => ClinicService::createBill((int) $v['patient_id'], $this->rowsFromForm('acts'), $id), '/clinique/consultations/' . $id);
        flash('success', t('Facture créée : à encaisser à l\'accueil.'));
        redirect(can('clinique.reception') ? '/clinique/factures/' . $bid : '/clinique/consultations/' . $id);
    }

    public function prescriptionPrint(int $id): void
    {
        if (!can('clinique.medical') && !can('clinique.pharmacy')) {
            throw new HttpException(403);
        }
        $pr = DB::one('SELECT pr.*, u.full_name AS prescriber, p.last_name, p.first_name, p.sex, p.birth_date, p.number AS pnum, p.allergies FROM clinic_prescriptions pr JOIN users u ON u.id = pr.prescriber_id JOIN clinic_patients p ON p.id = pr.patient_id WHERE pr.id = ?', [$id]) ?? throw new HttpException(404);
        view('clinique::prescription_print', ['title' => 'Ordonnance ' . $pr['number'], 'pr' => $pr, 'items' => DB::all('SELECT * FROM clinic_prescription_items WHERE prescription_id = ?', [$id]),
            'bodyClass' => 'a4', 'backUrl' => url('/clinique')], 'layouts/print');
    }

    // ------------------------------------------------------------------ factures

    public function bills(): void
    {
        $st = (string) Request::query('status', 'open');
        $where = in_array($st, ['open', 'paid', 'cancelled'], true) ? 'WHERE b.status = ?' : '';
        view('clinique::bills', ['title' => 'Factures clinique', 'status' => $st,
            'p' => paginate("FROM clinic_bills b JOIN clinic_patients p ON p.id = b.patient_id $where", $where ? [$st] : [], 'b.*, p.last_name, p.first_name, p.number AS pnum', 'b.id DESC')]);
    }

    private function billOr404(int $id): array
    {
        return DB::one('SELECT b.*, p.last_name, p.first_name, p.number AS pnum, p.phone FROM clinic_bills b JOIN clinic_patients p ON p.id = b.patient_id WHERE b.id = ?', [$id]) ?? throw new HttpException(404);
    }

    public function bill(int $id): void
    {
        $b = $this->billOr404($id);
        view('clinique::bill', ['title' => 'Facture ' . $b['number'], 'b' => $b, 'items' => DB::all('SELECT * FROM clinic_bill_items WHERE bill_id = ?', [$id]),
            'payments' => DB::all('SELECT cp.*, u.full_name FROM clinic_payments cp JOIN users u ON u.id = cp.user_id WHERE cp.bill_id = ? ORDER BY cp.id', [$id])]);
    }

    public function billPay(int $id): void
    {
        $this->billOr404($id);
        $change = $this->attempt(fn() => ClinicService::payBill($id, ['method' => Request::str('pay_method'), 'amount' => Request::str('pay_amount'), 'reference' => Request::str('pay_reference')]), '/clinique/factures/' . $id);
        flash('success', t('Paiement enregistré.') . ($change > 0 ? ' ' . t('Monnaie à rendre') . ' : ' . money($change) . '.' : ''));
        redirect('/clinique/factures/' . $id);
    }

    public function billCancel(int $id): void
    {
        $this->billOr404($id);
        $this->attempt(fn() => ClinicService::cancelBill($id, Request::str('reason')), '/clinique/factures/' . $id);
        flash('success', t('Facture annulée.'));
        redirect('/clinique/factures/' . $id);
    }

    public function billPrint(int $id): void
    {
        $b = $this->billOr404($id);
        view('clinique::bill_print', ['title' => 'Facture ' . $b['number'], 'b' => $b, 'items' => DB::all('SELECT * FROM clinic_bill_items WHERE bill_id = ?', [$id]),
            'payments' => DB::all('SELECT * FROM clinic_payments WHERE bill_id = ?', [$id]), 'bodyClass' => 'a4', 'backUrl' => url('/clinique/factures/' . $id)], 'layouts/print');
    }

    // ------------------------------------------------------------------ paramètres et rapport

    public function settings(): void
    {
        [$from, $to] = date_range('first day of this month');
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $stats = [
            'visits' => (int) DB::value("SELECT COUNT(*) FROM clinic_visits WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?", $range),
            'patients' => (int) DB::value('SELECT COUNT(*) FROM clinic_patients WHERE created_at BETWEEN ? AND ?', $range),
            'stays' => (int) DB::value('SELECT COUNT(*) FROM clinic_stays WHERE admitted_at BETWEEN ? AND ?', $range),
            'billed' => (float) DB::value("SELECT COALESCE(SUM(subtotal),0) FROM clinic_bills WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?", $range),
            'coverage' => (float) DB::value("SELECT COALESCE(SUM(coverage_amount),0) FROM clinic_bills WHERE status <> 'cancelled' AND created_at BETWEEN ? AND ?", $range),
            'cash' => (float) DB::value('SELECT COALESCE(SUM(amount),0) FROM clinic_payments WHERE created_at BETWEEN ? AND ?', $range),
        ];
        $byAct = DB::all("SELECT i.label, SUM(i.quantity) AS qty, SUM(i.total) AS total FROM clinic_bill_items i JOIN clinic_bills b ON b.id = i.bill_id WHERE b.status <> 'cancelled' AND b.created_at BETWEEN ? AND ? GROUP BY i.label ORDER BY total DESC", $range);
        $byDoctor = DB::all("SELECT COALESCE(u.full_name, '—') AS name, COUNT(*) AS n FROM clinic_visits v LEFT JOIN users u ON u.id = v.practitioner_id WHERE v.status = 'done' AND v.created_at BETWEEN ? AND ? GROUP BY u.full_name ORDER BY n DESC", $range);
        $byInsurer = DB::all("SELECT insurer, COUNT(*) AS n, SUM(coverage_amount) AS total FROM clinic_bills WHERE status <> 'cancelled' AND coverage_amount > 0 AND created_at BETWEEN ? AND ? GROUP BY insurer ORDER BY total DESC", $range);
        $diag = DB::all("SELECT diagnosis, COUNT(*) AS n FROM clinic_visits WHERE status = 'done' AND diagnosis IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY diagnosis ORDER BY n DESC LIMIT 10", $range);
        if (Export::requested() && can('reports.export')) {
            Export::download("clinique_actes_{$from}_{$to}.csv", 'Clinique — actes facturés', ['Acte', 'Quantité', 'Montant'], array_map(fn($r) => [$r['label'], (float) $r['qty'], $r['total']], $byAct),
                ['subtitle' => Export::period($from, $to), 'footer' => ['TOTAL', array_sum(array_column($byAct, 'qty')), array_sum(array_column($byAct, 'total'))]]);
        }
        view('clinique::settings', ['title' => 'Clinique — actes, lits, rapport', 'from' => $from, 'to' => $to, 'stats' => $stats, 'byAct' => $byAct, 'byDoctor' => $byDoctor, 'byInsurer' => $byInsurer, 'diag' => $diag,
            'acts' => DB::all('SELECT * FROM clinic_acts ORDER BY category, name'), 'rooms' => DB::all('SELECT * FROM clinic_rooms ORDER BY sort_order, name'),
            'beds' => DB::all('SELECT b.*, r.name AS room FROM clinic_beds b JOIN clinic_rooms r ON r.id = b.room_id ORDER BY r.sort_order, b.name')]);
    }

    public function actsSave(): void
    {
        $this->attempt(function () {
            foreach ((array) Request::input('acts', []) as $id => $a) {
                $price = (float) str_replace([' ', ','], ['', '.'], (string) ($a['price'] ?? '0'));
                if ($price < 0 || trim((string) ($a['name'] ?? '')) === '') {
                    throw new \App\Core\BusinessException('Acte invalide.');
                }
                DB::update('clinic_acts', ['name' => mb_substr(trim((string) $a['name']), 0, 120), 'price' => round($price, 2), 'active' => !empty($a['active']) ? 1 : 0], 'id = ?', [(int) $id]);
            }
            if (($n = trim(Request::str('new_name'))) !== '') {
                if (DB::value('SELECT 1 FROM clinic_acts WHERE name = ?', [$n])) {
                    throw new \App\Core\BusinessException('Cet acte existe déjà.');
                }
                DB::insert('clinic_acts', ['name' => mb_substr($n, 0, 120), 'category' => mb_substr(Request::str('new_category') ?: 'Soins', 0, 60), 'price' => max(0.0, Request::num('new_price'))]);
            }
            Audit::log('clinique.acts', null, null);
        }, '/clinique/parametres');
        flash('success', t('Actes enregistrés.'));
        redirect('/clinique/parametres');
    }

    public function bedsSave(): void
    {
        $this->attempt(function () {
            if (($room = trim(Request::str('room_name'))) !== '') {
                DB::insert('clinic_rooms', ['name' => mb_substr($room, 0, 80), 'ward' => mb_substr(Request::str('ward') ?: 'Hospitalisation', 0, 80)]);
            }
            if (($bed = trim(Request::str('bed_name'))) !== '') {
                if (!DB::value('SELECT 1 FROM clinic_rooms WHERE id = ?', [Request::int('room_id')])) {
                    throw new \App\Core\BusinessException('Choisissez la salle du lit.');
                }
                DB::insert('clinic_beds', ['room_id' => Request::int('room_id'), 'name' => mb_substr($bed, 0, 40), 'daily_price' => max(0.0, Request::num('daily_price'))]);
            }
            foreach ((array) Request::input('beds', []) as $id => $b) {
                DB::update('clinic_beds', ['daily_price' => max(0.0, (float) str_replace([' ', ','], ['', '.'], (string) ($b['daily_price'] ?? '0'))), 'active' => !empty($b['active']) ? 1 : 0], 'id = ?', [(int) $id]);
            }
            Audit::log('clinique.beds', null, null);
        }, '/clinique/parametres');
        flash('success', t('Chambres et lits enregistrés.'));
        redirect('/clinique/parametres');
    }

    public function thresholdsSave(): void
    {
        $this->attempt(function () {
            $in = (array) Request::input('th', []);
            foreach (self::THRESHOLDS as $k => [$label]) {
                $v = str_replace(',', '.', trim((string) ($in[$k] ?? '')));
                if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 400) {
                    throw new \App\Core\BusinessException('Valeur invalide : ' . $label);
                }
                setting_set('clinique_' . $k, (string) ($v + 0));
            }
            Audit::log('clinique.thresholds', null, null, $in);
        }, '/clinique/parametres');
        flash('success', t('Seuils d\'alerte enregistrés.'));
        redirect('/clinique/parametres');
    }
}
