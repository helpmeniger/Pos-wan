<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Controllers\Controller;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;

/** File des ordonnances de la clinique à délivrer par la pharmacie. */
final class PharmacyController extends Controller
{
    private function prescriptionOr404(int $id): array
    {
        return DB::one('SELECT pr.*, p.last_name, p.first_name, p.number AS pnum, p.sex, p.birth_date, p.allergies, u.full_name AS prescriber
                FROM clinic_prescriptions pr JOIN clinic_patients p ON p.id = pr.patient_id JOIN users u ON u.id = pr.prescriber_id WHERE pr.id = ?', [$id]) ?? throw new HttpException(404);
    }

    public function index(): void
    {
        $st = (string) Request::query('status', 'todo');
        $where = match ($st) {
            'dispensed' => "pr.status = 'dispensed'",
            'cancelled' => "pr.status = 'cancelled'",
            default => "pr.status IN ('new','partial')",
        };
        view('clinique::pharmacy_queue', ['title' => 'Ordonnances de la clinique → pharmacie', 'status' => $st,
            'rows' => DB::all("SELECT pr.*, p.last_name, p.first_name, p.number AS pnum, u.full_name AS prescriber,
                (SELECT COUNT(*) FROM clinic_prescription_items i WHERE i.prescription_id = pr.id) AS n,
                (SELECT COALESCE(SUM(i.quantity - i.dispensed_qty),0) FROM clinic_prescription_items i WHERE i.prescription_id = pr.id AND i.product_id IS NOT NULL) AS left_qty
                FROM clinic_prescriptions pr JOIN clinic_patients p ON p.id = pr.patient_id JOIN users u ON u.id = pr.prescriber_id
                WHERE $where ORDER BY " . ($st === 'todo' ? 'pr.id' : 'pr.id DESC') . ' LIMIT 300')]);
    }

    public function show(int $id): void
    {
        $pr = $this->prescriptionOr404($id);
        $items = DB::all('SELECT i.*, pd.sale_price, pd.requires_prescription, pd.barcode, pd.reference, COALESCE((SELECT SUM(quantity) FROM stock_levels sl WHERE sl.product_id = i.product_id), 0) AS stock
                FROM clinic_prescription_items i LEFT JOIN products pd ON pd.id = i.product_id WHERE i.prescription_id = ? ORDER BY i.id', [$id]);
        view('clinique::pharmacy_dispense', ['title' => 'Délivrance ' . $pr['number'], 'pr' => $pr, 'items' => $items, 'uuid' => $this->uuid(),
            'sales' => DB::all('SELECT d.*, s.number, s.total, u.full_name FROM clinic_dispensings d JOIN sales s ON s.id = d.sale_id JOIN users u ON u.id = d.user_id WHERE d.prescription_id = ? ORDER BY d.id', [$id]),
            'scripts' => ['addons/clinique/clinique.js']]);
    }

    public function dispense(int $id): void
    {
        $this->prescriptionOr404($id);
        [$method, $provider] = array_pad(explode('|', Request::str('pay_method', 'cash'), 2), 2, null);
        $payments = [['method' => $method, 'provider' => $provider, 'amount' => Request::num('pay_amount'), 'reference' => Request::str('pay_reference')]];
        $r = $this->attempt(fn() => PharmacyLink::dispense($id, (array) Request::input('qty', []), $payments, Request::str('client_uuid')), '/clinique/pharmacie/' . $id);
        flash('success', t('Ordonnance délivrée : vente') . ' ' . $r['number'] . ($r['change'] > 0 ? ' · ' . t('Monnaie à rendre') . ' : ' . money($r['change']) : ''));
        redirect('/sales/' . $r['sale_id']);
    }

    public function cancel(int $id): void
    {
        $pr = $this->prescriptionOr404($id);
        if (!can('clinique.pharmacy') && !(can('clinique.doctor') && (int) $pr['prescriber_id'] === (int) $this->user()['id']) && !can('clinique.manage')) {
            throw new HttpException(403);
        }
        $this->attempt(fn() => PharmacyLink::cancel($id), '/clinique/pharmacie/' . $id);
        flash('success', t('Ordonnance annulée.'));
        redirect(can('clinique.pharmacy') ? '/clinique/pharmacie' : '/clinique');
    }
}
