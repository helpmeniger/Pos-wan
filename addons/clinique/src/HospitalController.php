<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;

/** Hospitalisation, mise en observation et écran de supervision des malades. */
final class HospitalController extends Controller
{
    private const ASSETS = ['addons/clinique/clinique.js'];

    private function stayOr404(int $id): array
    {
        $s = DB::one('SELECT s.*, b.name AS bed, b.daily_price, r.name AS room, u.full_name AS doctor FROM clinic_stays s JOIN clinic_beds b ON b.id = s.bed_id JOIN clinic_rooms r ON r.id = b.room_id LEFT JOIN users u ON u.id = s.doctor_id WHERE s.id = ?', [$id]);
        if (!$s) {
            throw new HttpException(404);
        }
        return $s;
    }

    public function index(): void
    {
        $st = Request::query('status') === 'discharged' ? 'discharged' : 'admitted';
        view('clinique::hospitalisation', [
            'title' => 'Hospitalisation et observation', 'status' => $st,
            'rows' => DB::all("SELECT s.*, p.last_name, p.first_name, p.sex, p.birth_date, p.number AS pnum, b.name AS bed, r.name AS room, u.full_name AS doctor
                FROM clinic_stays s JOIN clinic_patients p ON p.id = s.patient_id JOIN clinic_beds b ON b.id = s.bed_id JOIN clinic_rooms r ON r.id = b.room_id LEFT JOIN users u ON u.id = s.doctor_id
                WHERE s.status = ? ORDER BY " . ($st === 'admitted' ? 's.admitted_at' : 's.discharged_at DESC') . ' LIMIT 300', [$st]),
            'beds' => ClinicController::freeBeds(),
            'patients' => DB::all("SELECT p.id, p.number, p.last_name, p.first_name FROM clinic_patients p WHERE p.active = 1 AND NOT EXISTS (SELECT 1 FROM clinic_stays s WHERE s.patient_id = p.id AND s.status = 'admitted') ORDER BY p.last_name, p.first_name LIMIT 2000"),
            'patientId' => (int) Request::query('patient', 0), 'visitId' => (int) Request::query('visit', 0),
        ]);
    }

    public function admit(): void
    {
        $back = '/clinique/hospitalisation';
        $id = $this->attempt(fn() => HospitalService::admit($_POST), $back);
        flash('success', t('Patient admis.'));
        redirect('/clinique/sejours/' . $id);
    }

    public function show(int $id): void
    {
        $s = $this->stayOr404($id);
        $p = ClinicService::patient((int) $s['patient_id']);
        Audit::log('clinique.dossier.view', 'clinic_patient', (int) $p['id'], ['stay' => $id]);
        $vitals = DB::all('SELECT v.*, u.full_name FROM clinic_vitals v JOIN users u ON u.id = v.user_id WHERE v.stay_id = ? ORDER BY v.taken_at DESC, v.id DESC LIMIT 60', [$id]);
        view('clinique::stay', [
            'title' => ($s['kind'] === 'observation' ? 'Observation ' : 'Hospitalisation ') . $s['number'], 's' => $s, 'p' => $p, 'vitals' => $vitals,
            'care' => DB::all('SELECT c.*, TIMESTAMPDIFF(MINUTE, NOW(), c.next_due_at) AS in_min, u.full_name AS prescriber FROM clinic_care_plan c LEFT JOIN users u ON u.id = c.prescriber_id WHERE c.stay_id = ? ORDER BY c.active DESC, c.next_due_at', [$id]),
            'careLog' => DB::all('SELECT l.*, c.label, c.dose, u.full_name FROM clinic_care_log l JOIN clinic_care_plan c ON c.id = l.plan_id JOIN users u ON u.id = l.user_id WHERE l.stay_id = ? ORDER BY l.done_at DESC LIMIT 50', [$id]),
            'notes' => DB::all('SELECT n.*, u.full_name FROM clinic_notes n JOIN users u ON u.id = n.user_id WHERE n.stay_id = ? ORDER BY n.created_at DESC', [$id]),
            'prescriptions' => DB::all('SELECT * FROM clinic_prescriptions WHERE stay_id = ? ORDER BY id DESC', [$id]),
            'alerts' => ClinicService::alerts($vitals[0] ?? null), 'days' => HospitalService::days($s),
            'beds' => ClinicController::freeBeds(), 'acts' => DB::all('SELECT * FROM clinic_acts WHERE active = 1 ORDER BY category, name'),
            'drugs' => ClinicController::drugs(), 'scripts' => self::ASSETS,
            'chart' => array_reverse(array_slice($vitals, 0, 20)),
        ]);
    }

    public function vitals(int $id): void
    {
        $s = $this->stayOr404($id);
        if ($s['status'] !== 'admitted') {
            $this->fail('Ce patient est déjà sorti.', '/clinique/sejours/' . $id);
        }
        $this->attempt(fn() => ClinicService::addVitals(['patient_id' => $s['patient_id'], 'stay_id' => $id] + (array) Request::input('vitals', [])), '/clinique/sejours/' . $id);
        flash('success', t('Constantes enregistrées.'));
        redirect('/clinique/sejours/' . $id);
    }

    public function careAdd(int $id): void
    {
        $this->stayOr404($id);
        $this->attempt(fn() => HospitalService::addCare($id, $_POST), '/clinique/sejours/' . $id);
        flash('success', t('Soin ajouté au plan de soins.'));
        redirect('/clinique/sejours/' . $id);
    }

    /** Soin fait / refusé / non fait : depuis la fiche (formulaire) ou l'écran de supervision (JSON). */
    public function careLog(int $id): void
    {
        $stay = (int) DB::value('SELECT stay_id FROM clinic_care_plan WHERE id = ?', [$id]);
        if (!$stay) {
            throw new HttpException(404);
        }
        if (Request::wantsJson()) {
            $this->jsonAttempt(function () use ($id) {
                HospitalService::logCare($id, Request::str('status', 'done') ?: 'done', (Request::str('note') ?: Request::str('reason')) ?: null);
                return [];
            });
        }
        $this->attempt(fn() => HospitalService::logCare($id, Request::str('status', 'done') ?: 'done', (Request::str('note') ?: Request::str('reason')) ?: null), '/clinique/sejours/' . $stay);
        flash('success', t('Soin enregistré.'));
        redirect('/clinique/sejours/' . $stay);
    }

    public function careStop(int $id): void
    {
        $stay = (int) DB::value('SELECT stay_id FROM clinic_care_plan WHERE id = ?', [$id]);
        if (!$stay) {
            throw new HttpException(404);
        }
        HospitalService::stopCare($id);
        flash('success', t('Soin arrêté.'));
        redirect('/clinique/sejours/' . $stay);
    }

    public function note(int $id): void
    {
        $this->stayOr404($id);
        $kind = can('clinique.doctor') && Request::str('kind') === 'doctor' ? 'doctor' : 'nurse';
        $this->attempt(fn() => HospitalService::addNote($id, Request::str('note'), $kind), '/clinique/sejours/' . $id);
        flash('success', t('Note ajoutée.'));
        redirect('/clinique/sejours/' . $id);
    }

    public function transfer(int $id): void
    {
        $this->stayOr404($id);
        $this->attempt(fn() => HospitalService::transfer($id, Request::int('bed_id')), '/clinique/sejours/' . $id);
        flash('success', t('Patient transféré.'));
        redirect('/clinique/sejours/' . $id);
    }

    public function prescription(int $id): void
    {
        $s = $this->stayOr404($id);
        if ($s['status'] !== 'admitted') {
            $this->fail('Ce patient est déjà sorti.', '/clinique/sejours/' . $id);
        }
        $pid = $this->attempt(fn() => PharmacyLink::create((int) $s['patient_id'], $this->rowsFromForm('drugs'), null, $id, Request::str('presc_note')), '/clinique/sejours/' . $id);
        flash('success', t('Ordonnance enregistrée et transmise à la pharmacie.'));
        redirect('/clinique/sejours/' . $id, ['printp' => $pid]);
    }

    public function discharge(int $id): void
    {
        $this->stayOr404($id);
        $billId = $this->attempt(fn() => HospitalService::discharge($id, Request::str('mode'), Request::str('note'), $this->rowsFromForm('acts')), '/clinique/sejours/' . $id);
        flash('success', t('Sortie enregistrée : la facture du séjour est à encaisser à l\'accueil.'));
        redirect(can('clinique.reception') ? '/clinique/factures/' . $billId : '/clinique/sejours/' . $id);
    }

    // ------------------------------------------------------------------ supervision

    /** Écran plein écran (sans menu), prévu pour un téléviseur ou une tablette du poste infirmier. */
    public function supervision(): void
    {
        Audit::log('clinique.supervision.open', null, null);
        view('clinique::supervision', ['u' => $this->user(), 'refresh' => 20], null);
    }

    public function feed(): void
    {
        json_response(['ok' => true] + HospitalService::board());
    }
}
