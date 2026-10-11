<?php
declare(strict_types=1);

namespace Addons\Clinique;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\SaleService;
use App\Services\Sequence;

/**
 * Lien avec la pharmacie : le médecin rédige l'ordonnance (médicaments du stock ou hors
 * stock) ; elle apparaît dans la file « Ordonnances → pharmacie » où le pharmacien la
 * délivre. La délivrance crée une vente normale (stock, lots FEFO, caisse) avec les
 * informations d'ordonnance remplies automatiquement (registre des délivrances).
 */
final class PharmacyLink
{
    public const STATUSES = ['new' => 'À délivrer', 'partial' => 'Délivrée en partie', 'dispensed' => 'Délivrée', 'cancelled' => 'Annulée'];

    /** $lines : [{product_id?, drug_name?, posology, duration, quantity}] */
    public static function create(int $patientId, array $lines, ?int $visitId = null, ?int $stayId = null, ?string $note = null): int
    {
        ClinicService::patient($patientId);
        $items = [];
        foreach ($lines as $l) {
            $pid = (int) ($l['product_id'] ?? 0);
            $name = trim((string) ($l['drug_name'] ?? ''));
            if ($pid > 0) {
                $p = DB::one("SELECT id, name FROM products WHERE id = ? AND status = 'active' AND sellable = 1 AND sales_channel <> 'restaurant'", [$pid]);
                if (!$p) {
                    throw new BusinessException('Médicament introuvable dans le stock.');
                }
                $name = $p['name'];
            } elseif ($name === '') {
                continue;
            }
            $q = (int) ($l['quantity'] ?? 1);
            if ($q < 1 || $q > 1000) {
                throw new BusinessException('Quantité invalide pour « ' . $name . ' ».');
            }
            $items[] = ['product_id' => $pid ?: null, 'drug_name' => mb_substr($name, 0, 150), 'posology' => mb_substr(trim((string) ($l['posology'] ?? '')), 0, 255) ?: null,
                'duration' => mb_substr(trim((string) ($l['duration'] ?? '')), 0, 50) ?: null, 'quantity' => $q];
        }
        if (!$items) {
            throw new BusinessException('Ajoutez au moins un médicament à l\'ordonnance.');
        }
        return DB::transaction(function () use ($patientId, $items, $visitId, $stayId, $note) {
            $id = DB::insert('clinic_prescriptions', ['number' => Sequence::next('clinic_prescription'), 'patient_id' => $patientId, 'visit_id' => $visitId, 'stay_id' => $stayId,
                'prescriber_id' => Auth::id(), 'note' => $note !== null ? mb_substr(trim($note), 0, 255) ?: null : null,
                'status' => array_filter($items, fn($i) => $i['product_id']) ? 'new' : 'dispensed']);
            foreach ($items as $it) {
                DB::insert('clinic_prescription_items', $it + ['prescription_id' => $id]);
            }
            Audit::log('clinique.prescription.create', 'clinic_prescription', $id);
            return $id;
        });
    }

    /**
     * Délivrance (pharmacien) : $quantities [item_id => qté délivrée], paiement au format SaleService.
     * @return array résultat de SaleService::create (sale_id, number, change…)
     */
    public static function dispense(int $id, array $quantities, array $payments, string $uuid): array
    {
        if (!Auth::can('clinique.pharmacy')) {
            throw new BusinessException('Délivrance réservée à la pharmacie.');
        }
        return DB::transaction(function () use ($id, $quantities, $payments, $uuid) {
            $pr = DB::one('SELECT p.*, pa.last_name, pa.first_name, u.full_name AS prescriber FROM clinic_prescriptions p JOIN clinic_patients pa ON pa.id = p.patient_id JOIN users u ON u.id = p.prescriber_id WHERE p.id = ? FOR UPDATE', [$id]);
            if (!$pr || !in_array($pr['status'], ['new', 'partial'], true)) {
                throw new BusinessException('Ordonnance introuvable, déjà délivrée ou annulée.');
            }
            $items = DB::all('SELECT * FROM clinic_prescription_items WHERE prescription_id = ? AND product_id IS NOT NULL FOR UPDATE', [$id]);
            $saleItems = [];
            $given = [];
            foreach ($items as $it) {
                $q = (int) ($quantities[$it['id']] ?? 0);
                $left = (int) $it['quantity'] - (int) $it['dispensed_qty'];
                if ($q <= 0) {
                    continue;
                }
                if ($q > $left) {
                    throw new BusinessException('Quantité supérieure au reste à délivrer pour « ' . $it['drug_name'] . ' ».');
                }
                $saleItems[] = ['product_id' => (int) $it['product_id'], 'quantity' => $q];
                $given[(int) $it['id']] = $q;
            }
            if (!$saleItems) {
                throw new BusinessException('Indiquez les quantités délivrées.');
            }
            $r = SaleService::create([
                'client_uuid' => $uuid, 'items' => $saleItems, 'payments' => $payments, 'note' => 'Ordonnance clinique ' . $pr['number'],
                'prescription' => ['prescriber' => 'Dr ' . $pr['prescriber'] . ' (clinique)', 'patient_name' => $pr['last_name'] . ' ' . $pr['first_name'],
                    'prescription_ref' => $pr['number'], 'prescription_date' => substr($pr['created_at'], 0, 10)],
            ]);
            if (empty($r['duplicate'])) {
                foreach ($given as $itemId => $q) {
                    DB::run('UPDATE clinic_prescription_items SET dispensed_qty = dispensed_qty + ? WHERE id = ?', [$q, $itemId]);
                }
                DB::insert('clinic_dispensings', ['prescription_id' => $id, 'sale_id' => $r['sale_id'], 'user_id' => Auth::id()]);
                $left = (int) DB::value('SELECT COALESCE(SUM(quantity - dispensed_qty),0) FROM clinic_prescription_items WHERE prescription_id = ? AND product_id IS NOT NULL', [$id]);
                DB::update('clinic_prescriptions', ['status' => $left > 0 ? 'partial' : 'dispensed', 'dispensed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
                Audit::log('clinique.prescription.dispense', 'clinic_prescription', $id, ['sale' => $r['number']]);
            }
            return $r;
        });
    }

    public static function cancel(int $id): void
    {
        $st = DB::value('SELECT status FROM clinic_prescriptions WHERE id = ?', [$id]);
        if ($st !== 'new') {
            throw new BusinessException('Seule une ordonnance non encore délivrée peut être annulée.');
        }
        DB::update('clinic_prescriptions', ['status' => 'cancelled'], 'id = ?', [$id]);
        Audit::log('clinique.prescription.cancel', 'clinic_prescription', $id);
    }
}
