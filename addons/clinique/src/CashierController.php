<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\CashService;

/**
 * Caisse clinique : la caisse principale en mode Clinique (ou l'onglet « Prestations » en mode
 * Clinique + Pharmacie). Prestations médicales uniquement ; les médicaments restent vendus par la
 * caisse pharmacie (opérations séparées et identifiables).
 */
final class CashierController extends Controller
{
    public function index(): void
    {
        $u = $this->user();
        $register = $u['register_id'] ? DB::one('SELECT id, name FROM cash_registers WHERE id = ?', [$u['register_id']]) : null;
        view('clinique::caisse', [
            'title' => 'Caisse clinique',
            'acts' => DB::all('SELECT id, name, category, price FROM clinic_acts WHERE active = 1 ORDER BY category, name'),
            'practitioners' => ClinicService::practitioners(),
            'openBills' => DB::all("SELECT b.id, b.number, b.patient_amount - b.paid AS due, b.created_at, p.last_name, p.first_name FROM clinic_bills b JOIN clinic_patients p ON p.id = b.patient_id WHERE b.status = 'open' ORDER BY b.id DESC LIMIT 15"),
            'today' => DB::all('SELECT b.id, b.number, b.subtotal, b.paid, b.status, p.last_name, p.first_name, b.created_at FROM clinic_bills b JOIN clinic_patients p ON p.id = b.patient_id WHERE DATE(b.created_at) = CURDATE() AND b.user_id = ? ORDER BY b.id DESC LIMIT 15', [$u['id']]),
            'register' => $register,
            'sessionOpen' => (bool) CashService::currentSession($u),
            'requireSession' => setting('require_cash_session', '1') === '1',
            'pharmacyLink' => \App\Services\BusinessMode::is('clinique_pharmacie') && can('pos.sell'),
            'scripts' => ['addons/clinique/caisse.js'],
        ]);
    }

    /** Recherche de patients (nom, n° de dossier, téléphone). */
    public function patients(): void
    {
        $q = trim((string) Request::query('q', ''));
        if (mb_strlen($q) < 2) {
            json_response(['ok' => true, 'items' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $rows = DB::all("SELECT id, number, last_name, first_name, sex, birth_date, phone, insurer, coverage_pct, allergies FROM clinic_patients
                WHERE active = 1 AND (number LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR phone LIKE ? OR CONCAT(last_name, ' ', first_name) LIKE ?) ORDER BY last_name, first_name LIMIT 15", [$like, $like, $like, $like, $like]);
        json_response(['ok' => true, 'items' => array_map(fn($r) => [
            'id' => (int) $r['id'], 'number' => $r['number'], 'name' => $r['last_name'] . ' ' . $r['first_name'], 'sex' => $r['sex'], 'age' => ClinicService::age($r['birth_date']),
            'phone' => $r['phone'], 'insurer' => $r['insurer'], 'coverage' => (float) $r['coverage_pct'], 'allergies' => $r['allergies'],
        ], $rows)]);
    }

    /** Création rapide d'un dossier patient depuis la caisse. */
    public function quickPatient(): void
    {
        $this->jsonAttempt(function () {
            $id = ClinicService::savePatient((array) Request::json());
            $p = ClinicService::patient($id);
            return ['patient' => ['id' => $id, 'number' => $p['number'], 'name' => $p['last_name'] . ' ' . $p['first_name'], 'sex' => $p['sex'], 'age' => ClinicService::age($p['birth_date']),
                'phone' => $p['phone'], 'insurer' => $p['insurer'], 'coverage' => (float) $p['coverage_pct'], 'allergies' => $p['allergies']]];
        });
    }

    /**
     * Encaissement : facture des prestations (prix du catalogue), paiement complet ou partiel,
     * et, si demandé, mise en file d'attente de consultation. Tout ou rien (transaction).
     */
    public function checkout(): void
    {
        $this->jsonAttempt(function () {
            $d = Request::json();
            $patientId = (int) ($d['patient_id'] ?? 0);
            $lines = array_map(fn($l) => ['act_id' => (int) ($l['act_id'] ?? 0), 'quantity' => (string) ($l['quantity'] ?? '1')], (array) ($d['lines'] ?? []));
            if (!$patientId) {
                throw new BusinessException('Choisissez ou créez le patient.');
            }
            $pay = (array) ($d['pay'] ?? []);
            $amount = round((float) str_replace([' ', ','], ['', '.'], (string) ($pay['amount'] ?? '0')), 2);
            return DB::transaction(function () use ($patientId, $lines, $d, $pay, $amount) {
                $visitId = null;
                if (!empty($d['queue'])) {
                    $visitId = ClinicService::openVisit(['patient_id' => $patientId, 'practitioner_id' => (int) ($d['practitioner_id'] ?? 0), 'priority' => ($d['priority'] ?? '') === 'urgent' ? 'urgent' : 'normal', 'reason' => (string) ($d['reason'] ?? '')]);
                }
                $billId = ClinicService::createBill($patientId, $lines, $visitId);
                $bill = DB::one('SELECT * FROM clinic_bills WHERE id = ?', [$billId]);
                $change = 0.0;
                if ($amount > 0 && $bill['status'] === 'open') {
                    if (setting('clinique_partial_payment', '1') !== '1' && $amount + 0.009 < (float) $bill['patient_amount']) {
                        throw new BusinessException('Le paiement partiel n\'est pas autorisé : encaissez ' . money($bill['patient_amount']) . '.');
                    }
                    $change = ClinicService::payBill($billId, ['method' => (string) ($pay['method'] ?? 'cash'), 'amount' => (string) $amount, 'reference' => (string) ($pay['reference'] ?? '')]);
                }
                $bill = DB::one('SELECT * FROM clinic_bills WHERE id = ?', [$billId]);
                Audit::log('clinique.caisse', 'clinic_bill', $billId, ['visit' => $visitId, 'paid' => (float) $bill['paid']]);
                return ['bill_id' => $billId, 'number' => $bill['number'], 'total' => (float) $bill['subtotal'], 'patient_amount' => (float) $bill['patient_amount'],
                    'paid' => (float) $bill['paid'], 'due' => round((float) $bill['patient_amount'] - (float) $bill['paid'], 2), 'change' => $change, 'visit_id' => $visitId,
                    'ticket_url' => url('/clinique/factures/' . $billId . '/ticket', ['auto' => 1]), 'bill_url' => url('/clinique/factures/' . $billId)];
            });
        });
    }

    /** Reçu de caisse 80 mm d'une facture clinique. */
    public function ticket(int $id): void
    {
        $b = DB::one('SELECT b.*, p.last_name, p.first_name, p.number AS pnum FROM clinic_bills b JOIN clinic_patients p ON p.id = b.patient_id WHERE b.id = ?', [$id]) ?? throw new HttpException(404);
        view('clinique::bill_ticket', ['title' => 'Reçu ' . $b['number'], 'b' => $b, 'items' => DB::all('SELECT * FROM clinic_bill_items WHERE bill_id = ?', [$id]),
            'payments' => DB::all('SELECT cp.*, u.full_name FROM clinic_payments cp JOIN users u ON u.id = cp.user_id WHERE cp.bill_id = ? ORDER BY cp.id', [$id]),
            'bodyClass' => setting('ticket_width', '80') === '58' ? 't58' : 't80', 'backUrl' => url('/clinique/caisse'), 'autoPrint' => Request::query('auto') === '1'], 'layouts/print');
    }
}
