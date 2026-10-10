<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Enregistrement des ventes.
 *
 * Règles principales :
 * - Les prix sont TOUJOURS recalculés côté serveur depuis la base ; un prix
 *   différent du catalogue exige la permission pos.price_override.
 * - Les prix sont TTC ; la taxe incluse est calculée par ligne.
 * - Remise : pos.discount requise ; au-delà du seuil (paramètre
 *   max_discount_percent), pos.discount_high ou l'autorisation d'un superviseur.
 * - Vente à crédit : client obligatoire, pos.credit_sale, plafond de crédit.
 * - Idempotence : client_uuid unique ; une double soumission renvoie la vente existante.
 * - Vente, paiements, mouvements de caisse et de stock : une seule transaction.
 */
final class SaleService
{
    private const NON_CASH = ['card', 'mobile_money', 'transfer', 'cheque'];

    /** Prix applicable d'un article (hors dérogation manuelle). */
    public static function listPrice(array $p, float $qty, bool $wholesale, ?array $packaging = null): float
    {
        if ($packaging) {
            return (float) $packaging['sale_price'];
        }
        if ($wholesale && feature('wholesale') && $p['wholesale_price'] !== null && (float) $p['wholesale_price'] > 0
            && ($p['wholesale_min_qty'] === null || $qty >= (float) $p['wholesale_min_qty'])) {
            return (float) $p['wholesale_price'];
        }
        if (self::promoActive($p)) {
            return (float) $p['promo_price'];
        }
        return (float) $p['sale_price'];
    }

    public static function promoActive(array $p): bool
    {
        if ($p['promo_price'] === null || (float) $p['promo_price'] <= 0 || !feature('promotions')) {
            return false;
        }
        $today = date('Y-m-d');
        return (!$p['promo_start'] || $p['promo_start'] <= $today) && (!$p['promo_end'] || $p['promo_end'] >= $today);
    }

    /**
     * @param array $data {client_uuid, items[], payments[], customer_id?, discount_type?, discount_value?,
     *                     wholesale?, supervisor?{username,secret}, prescription?{...}, note?}
     * @param array $options Options internes (jamais issues du navigateur) :
     *                       trusted_prices (prix fixés par une commande restaurant), restaurant_order_id
     * @return array{sale_id:int, number:string, duplicate:bool, change:float, total:float}
     */
    public static function create(array $data, array $options = []): array
    {
        $user = Auth::user();
        if (!$user) {
            throw new BusinessException('Utilisateur non connecté.');
        }
        $uuid = (string) ($data['client_uuid'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw new BusinessException('Identifiant de transaction invalide. Rechargez la caisse.');
        }
        $existing = DB::one('SELECT id, number, change_due, total FROM sales WHERE client_uuid = ?', [$uuid]);
        if ($existing) {
            return ['sale_id' => (int) $existing['id'], 'number' => $existing['number'], 'duplicate' => true, 'change' => (float) $existing['change_due'], 'total' => (float) $existing['total']];
        }

        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new BusinessException('Le panier est vide.');
        }
        if (count($items) > 500) {
            throw new BusinessException('Trop de lignes dans le panier.');
        }

        // ------- Contexte caisse / entrepôt -------
        $registerId = $user['register_id'] ? (int) $user['register_id'] : null;
        $register = $registerId ? DB::one('SELECT * FROM cash_registers WHERE id = ? AND active = 1', [$registerId]) : null;
        if (!$register) {
            throw new BusinessException('Aucune caisse n\'est associée à votre compte. Contactez un administrateur.');
        }
        $session = CashService::openSessionForRegister((int) $register['id']);
        if (!$session && setting('require_cash_session', '1') === '1') {
            throw new BusinessException('La caisse est fermée. Ouvrez une session de caisse avant de vendre.');
        }
        $warehouseId = (int) $register['warehouse_id'];
        $storeId = (int) $register['store_id'];

        // ------- Autorisations (utilisateur ou superviseur) -------
        $supervisorId = null;
        $sup = $data['supervisor'] ?? null;
        $authorize = function (string $perm, string $why) use (&$supervisorId, $sup): void {
            if (Auth::can($perm)) {
                return;
            }
            if (is_array($sup) && !empty($sup['username']) && !empty($sup['secret'])) {
                $supervisorId = Auth::verifySupervisor((string) $sup['username'], (string) $sup['secret'], $perm);
                return;
            }
            throw new BusinessException('AUTH_REQUIRED:' . $why);
        };

        $wholesale = !empty($data['wholesale']);
        if ($wholesale) {
            $authorize('pos.wholesale', 'Vente au prix de gros');
        }

        // ------- Lignes : prix recalculés côté serveur -------
        $lines = [];
        $subtotal = 0.0;
        $lineDiscounts = 0.0;
        $needsPrescription = false;
        foreach ($items as $raw) {
            $pid = (int) ($raw['product_id'] ?? 0);
            $q = (float) ($raw['quantity'] ?? 0);
            if ($pid <= 0 || $q <= 0 || $q > 100000) {
                throw new BusinessException('Ligne de panier invalide.');
            }
            $p = DB::one("SELECT * FROM products WHERE id = ? AND status = 'active'", [$pid]);
            if (!$p) {
                throw new BusinessException('Un article du panier est introuvable ou archivé.');
            }
            if ((int) ($p['sellable'] ?? 1) === 0) {
                throw new BusinessException(sprintf('« %s » est un ingrédient : il ne peut pas être vendu.', $p['name']));
            }
            $allowDecimal = (int) $p['sold_by_weight'] === 1 || (bool) DB::value('SELECT allow_decimal FROM units WHERE id = ?', [$p['unit_id']]);
            if (!$allowDecimal && abs($q - round($q)) > 0.0001) {
                throw new BusinessException(sprintf('« %s » ne peut être vendu qu\'en quantité entière.', $p['name']));
            }
            $pack = null;
            if (!empty($raw['packaging_id'])) {
                $pack = DB::one('SELECT * FROM product_packagings WHERE id = ? AND product_id = ?', [(int) $raw['packaging_id'], $pid]);
                if (!$pack) {
                    throw new BusinessException('Conditionnement invalide.');
                }
            }
            $baseQty = $pack ? round($q * (float) $pack['factor'], 3) : $q;
            $list = self::listPrice($p, $baseQty, $wholesale, $pack);
            $price = $list;
            if (isset($raw['unit_price']) && $raw['unit_price'] !== null && $raw['unit_price'] !== '' && abs((float) $raw['unit_price'] - $list) >= 0.01) {
                $price = (float) $raw['unit_price'];
                if ($price < 0) {
                    throw new BusinessException('Prix négatif interdit.');
                }
                if (empty($options['trusted_prices'])) {
                    $authorize('pos.price_override', 'Modification du prix de « ' . $p['name'] . ' »');
                }
            }
            $gross = round($price * $q, 2);
            $disc = round(max(0.0, (float) ($raw['discount'] ?? 0)), 2);
            if ($disc > $gross) {
                throw new BusinessException('Remise supérieure au montant de la ligne.');
            }
            if ((int) $p['requires_prescription'] === 1 && feature('pharmacy')) {
                $needsPrescription = true;
            }
            $lines[] = ['p' => $p, 'pack' => $pack, 'qty' => $q, 'base_qty' => $baseQty, 'price' => $price, 'list' => $list, 'gross' => $gross, 'disc' => $disc];
            $subtotal += $gross;
            $lineDiscounts += $disc;
        }
        $subtotal = round($subtotal, 2);

        // ------- Remise globale, répartie au prorata sur les lignes -------
        $gType = (string) ($data['discount_type'] ?? 'amount');
        $gVal = max(0.0, (float) ($data['discount_value'] ?? 0));
        $afterLines = $subtotal - $lineDiscounts;
        $global = $gType === 'percent' ? round($afterLines * min($gVal, 100) / 100, 2) : round(min($gVal, $afterLines), 2);
        $discountTotal = round($lineDiscounts + $global, 2);
        if ($discountTotal > 0) {
            $authorize('pos.discount', 'Remise');
            $pct = $subtotal > 0 ? $discountTotal / $subtotal * 100 : 0;
            $max = (float) setting('max_discount_percent', '10');
            if ($pct > $max + 0.001) {
                $authorize('pos.discount_high', sprintf('Remise de %.1f %% (seuil %.0f %%)', $pct, $max));
            }
        }
        $allocated = 0.0;
        $n = count($lines);
        foreach ($lines as $i => &$l) {
            $base = $l['gross'] - $l['disc'];
            $share = $i === $n - 1 ? round($global - $allocated, 2) : ($afterLines > 0 ? round($global * $base / $afterLines, 2) : 0.0);
            $allocated += $share;
            $l['disc_total'] = round($l['disc'] + $share, 2);
            $l['total'] = round($l['gross'] - $l['disc_total'], 2);
            $rate = (float) $l['p']['tax_rate'];
            $l['tax'] = $rate > 0 ? round($l['total'] * $rate / (100 + $rate), 2) : 0.0;
        }
        unset($l);
        $total = round(array_sum(array_column($lines, 'total')), 2);
        $taxTotal = round(array_sum(array_column($lines, 'tax')), 2);

        // ------- Ordonnance (pharmacie) -------
        $prescription = null;
        if ($needsPrescription) {
            $authorize('pharmacy.dispense', 'Délivrance de médicament sur ordonnance');
            $pr = $data['prescription'] ?? [];
            if (empty($pr['prescriber']) || empty($pr['patient_name'])) {
                throw new BusinessException('PRESCRIPTION_REQUIRED:Ce panier contient un médicament soumis à ordonnance : renseignez le prescripteur et le patient.');
            }
            $prescription = [
                'prescriber' => mb_substr(trim((string) $pr['prescriber']), 0, 150),
                'patient_name' => mb_substr(trim((string) $pr['patient_name']), 0, 150),
                'prescription_ref' => mb_substr(trim((string) ($pr['prescription_ref'] ?? '')), 0, 100) ?: null,
                'prescription_date' => \App\Core\Validator::isDate((string) ($pr['prescription_date'] ?? '')) ? $pr['prescription_date'] : null,
            ];
        }

        // ------- Paiements -------
        $methods = payment_methods();
        $payments = [];
        $cashTendered = 0.0;
        $nonCash = 0.0;
        foreach (($data['payments'] ?? []) as $pay) {
            $m = (string) ($pay['method'] ?? '');
            $a = round((float) ($pay['amount'] ?? 0), 2);
            if ($a <= 0) {
                continue;
            }
            if (!isset($methods[$m])) {
                throw new BusinessException('Mode de paiement non autorisé.');
            }
            if ($m === 'cash') {
                $cashTendered += $a;
            } else {
                $nonCash += $a;
            }
            $payments[] = ['method' => $m, 'amount' => $a, 'reference' => mb_substr(trim((string) ($pay['reference'] ?? '')), 0, 100) ?: null,
                'provider' => $m === 'mobile_money' ? mobile_money_provider($pay['provider'] ?? null) : null];
        }
        if ($nonCash - $total > 0.009) {
            throw new BusinessException('Les paiements par carte / mobile / virement ne peuvent pas dépasser le total.');
        }
        $tendered = $cashTendered + $nonCash;
        $change = max(0.0, round($tendered - $total, 2));
        if ($change > $cashTendered + 0.009) {
            throw new BusinessException('Monnaie à rendre incohérente.');
        }
        $paid = round(min($tendered, $total), 2);
        $credit = round($total - $paid, 2);

        $customerId = !empty($data['customer_id']) ? (int) $data['customer_id'] : null;
        $customer = $customerId ? DB::one('SELECT * FROM customers WHERE id = ? AND active = 1', [$customerId]) : null;
        if ($customerId && !$customer) {
            throw new BusinessException('Client introuvable.');
        }
        if ($credit > 0.009) {
            if (!$customer) {
                throw new BusinessException('Paiement insuffisant : il manque ' . money($credit) . '. Pour une vente à crédit, sélectionnez un client.');
            }
            $authorize('pos.credit_sale', 'Vente à crédit de ' . money($credit));
            $limit = (float) $customer['credit_limit'];
            if ((float) $customer['balance'] + $credit > $limit + 0.009) {
                // Dépassement du plafond : autorisation explicite d'un superviseur obligatoire
                if (is_array($sup) && !empty($sup['username']) && !empty($sup['secret'])) {
                    $supervisorId = Auth::verifySupervisor((string) $sup['username'], (string) $sup['secret'], 'pos.credit_sale');
                } else {
                    throw new BusinessException('AUTH_REQUIRED:Plafond de crédit du client dépassé (plafond ' . money($limit) . ', encours ' . money($customer['balance']) . ')');
                }
            }
        }

        // ------- Écriture transactionnelle -------
        $result = DB::transaction(function () use ($uuid, $user, $storeId, $warehouseId, $register, $session, $customer, $wholesale, $subtotal, $discountTotal, $taxTotal, $total, $paid, $tendered, $change, $credit, $supervisorId, $lines, $payments, $prescription, $data, $options) {
            // Re-vérification sous verrou (protection contre deux soumissions simultanées)
            $dup = DB::one('SELECT id, number, change_due, total FROM sales WHERE client_uuid = ? FOR UPDATE', [$uuid]);
            if ($dup) {
                return ['sale_id' => (int) $dup['id'], 'number' => $dup['number'], 'duplicate' => true, 'change' => (float) $dup['change_due'], 'total' => (float) $dup['total']];
            }
            if ($session) {
                $s = DB::one('SELECT status FROM cash_sessions WHERE id = ? FOR UPDATE', [$session['id']]);
                if ($s['status'] !== 'open') {
                    throw new BusinessException('La session de caisse vient d\'être clôturée.');
                }
            }
            $number = Sequence::next('sale');
            $saleId = DB::insert('sales', [
                'number' => $number, 'client_uuid' => $uuid, 'store_id' => $storeId, 'warehouse_id' => $warehouseId,
                'register_id' => (int) $register['id'], 'cash_session_id' => $session['id'] ?? null,
                'customer_id' => $customer['id'] ?? null, 'restaurant_order_id' => $options['restaurant_order_id'] ?? null,
                'user_id' => (int) $user['id'], 'is_wholesale' => $wholesale ? 1 : 0,
                'subtotal' => $subtotal, 'discount_total' => $discountTotal, 'tax_total' => $taxTotal, 'total' => $total,
                'paid_total' => $paid, 'tendered' => $tendered, 'change_due' => $change, 'credit_amount' => $credit,
                'authorized_by' => $supervisorId, 'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 255) ?: null,
            ]);
            $costTotal = 0.0;
            foreach ($lines as $l) {
                $p = $l['p'];
                $name = $p['name'] . ($l['pack'] ? ' (' . $l['pack']['name'] . ')' : '');
                $itemId = DB::insert('sale_items', [
                    'sale_id' => $saleId, 'product_id' => (int) $p['id'], 'packaging_id' => $l['pack']['id'] ?? null,
                    'product_name' => mb_substr($name, 0, 200), 'quantity' => $l['qty'], 'base_quantity' => $l['base_qty'],
                    'unit_price' => $l['price'], 'list_price' => $l['list'], 'discount' => $l['disc_total'],
                    'tax_rate' => (float) $p['tax_rate'], 'tax_amount' => $l['tax'], 'line_total' => $l['total'],
                    'unit_cost' => (float) $p['cost_price'],
                ]);
                $lineCost = 0.0;
                $recipe = DB::all('SELECT ingredient_id, quantity FROM product_recipes WHERE product_id = ?', [$p['id']]);
                if ($recipe) {
                    // Fiche technique : ce sont les ingrédients qui sortent du stock
                    foreach ($recipe as $rc) {
                        $need = round((float) $rc['quantity'] * $l['base_qty'], 3);
                        if ($need <= 0) {
                            continue;
                        }
                        foreach (StockService::issue((int) $rc['ingredient_id'], $warehouseId, $need, 'sale', 'sale', $saleId, $number . ' (' . $p['name'] . ')') as $a) {
                            $lineCost += $a['qty'] * $a['unit_cost'];
                            DB::insert('sale_item_components', ['sale_item_id' => $itemId, 'ingredient_id' => (int) $rc['ingredient_id'], 'lot_id' => $a['lot_id'], 'quantity' => $a['qty'], 'unit_cost' => $a['unit_cost']]);
                        }
                    }
                    DB::run('UPDATE sale_items SET unit_cost = ? WHERE id = ?', [$l['base_qty'] > 0 ? round($lineCost / $l['base_qty'], 2) : 0, $itemId]);
                } elseif ((int) $p['track_stock'] === 1) {
                    $alloc = StockService::issue((int) $p['id'], $warehouseId, $l['base_qty'], 'sale', 'sale', $saleId, $number);
                    foreach ($alloc as $a) {
                        $lineCost += $a['qty'] * $a['unit_cost'];
                        if ($a['lot_id']) {
                            DB::insert('sale_item_lots', ['sale_item_id' => $itemId, 'lot_id' => $a['lot_id'], 'quantity' => $a['qty']]);
                        }
                    }
                    $unitCost = $l['base_qty'] > 0 ? round($lineCost / $l['base_qty'], 2) : 0;
                    DB::run('UPDATE sale_items SET unit_cost = ? WHERE id = ?', [$unitCost, $itemId]);
                } else {
                    $lineCost = $l['base_qty'] * (float) $p['cost_price'];
                }
                $costTotal += $lineCost;
            }
            DB::run('UPDATE sales SET cost_total = ? WHERE id = ?', [round($costTotal, 2), $saleId]);

            // Paiements : l'espèce enregistrée est nette de la monnaie rendue
            $changeLeft = $change;
            foreach ($payments as $pay) {
                $amount = $pay['amount'];
                if ($pay['method'] === 'cash' && $changeLeft > 0) {
                    $deduct = min($amount, $changeLeft);
                    $amount -= $deduct;
                    $changeLeft -= $deduct;
                }
                if ($amount <= 0) {
                    continue;
                }
                DB::insert('sale_payments', ['sale_id' => $saleId, 'method' => $pay['method'], 'provider' => $pay['provider'] ?? null, 'amount' => $amount, 'reference' => $pay['reference']]);
                CashService::movement($session['id'] ?? null, 'sale', $pay['method'], $amount, 'sale', $saleId, $number);
            }
            if ($credit > 0) {
                DB::insert('sale_payments', ['sale_id' => $saleId, 'method' => 'credit', 'amount' => $credit, 'reference' => null]);
                $due = date('Y-m-d', strtotime('+' . (int) setting('credit_default_days', '30') . ' days'));
                CustomerService::ledger((int) $customer['id'], 'credit_sale', $credit, null, 'sale', $saleId, 'Vente à crédit ' . $number, $due);
            }
            if ($prescription) {
                DB::insert('prescriptions', $prescription + ['sale_id' => $saleId, 'validated_by' => $supervisorId ?? (int) $user['id']]);
            }
            // Fidélité : X points par tranche de montant payé
            if ($customer && feature('loyalty')) {
                $step = max(1, (int) setting('loyalty_amount_per_point', '1000'));
                $pts = (int) floor($paid / $step);
                if ($pts > 0) {
                    DB::run('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?', [$pts, $customer['id']]);
                }
            }
            Audit::log('sale.create', 'sale', $saleId, ['number' => $number, 'total' => $total, 'credit' => $credit, 'authorized_by' => $supervisorId]);
            return ['sale_id' => $saleId, 'number' => $number, 'duplicate' => false, 'change' => $change, 'total' => $total];
        });
        return $result;
    }

    /**
     * Annulation d'une vente : contre-passation complète (stock, caisse, crédit).
     * Aucune donnée n'est effacée : la vente passe au statut « annulée ».
     */
    public static function cancel(int $saleId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif d\'annulation est obligatoire.');
        }
        DB::transaction(function () use ($saleId, $reason) {
            $sale = DB::one('SELECT * FROM sales WHERE id = ? FOR UPDATE', [$saleId]);
            if (!$sale) {
                throw new BusinessException('Vente introuvable.');
            }
            if ($sale['status'] !== 'completed') {
                throw new BusinessException('Seule une vente sans retour peut être annulée (utilisez un retour sinon).');
            }
            $maxHours = (int) setting('cancel_max_hours', '24');
            if (time() - strtotime($sale['created_at']) > $maxHours * 3600) {
                throw new BusinessException("Annulation impossible après {$maxHours} h : enregistrez un retour.");
            }
            $session = CashService::currentSession();
            $origSession = $sale['cash_session_id'] ? DB::one('SELECT * FROM cash_sessions WHERE id = ?', [$sale['cash_session_id']]) : null;
            $targetSession = ($origSession && $origSession['status'] === 'open') ? (int) $origSession['id'] : ($session['id'] ?? null);
            $cashPaid = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM sale_payments WHERE sale_id = ? AND method = 'cash'", [$saleId]);
            if ($cashPaid > 0 && !$targetSession) {
                throw new BusinessException('Ouvrez une session de caisse pour rembourser les espèces.');
            }
            // Stock : réintégration dans les lots d'origine
            foreach (DB::all('SELECT si.*, p.track_stock FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?', [$saleId]) as $it) {
                $components = DB::all('SELECT * FROM sale_item_components WHERE sale_item_id = ?', [$it['id']]);
                foreach ($components as $c) {
                    StockService::move((int) $c['ingredient_id'], (int) $sale['warehouse_id'], (float) $c['quantity'], 'sale_cancel', 'sale', $saleId, 'Annulation ' . $sale['number'], (float) $c['unit_cost'], $c['lot_id'] ? (int) $c['lot_id'] : null);
                }
                if ($components || (int) $it['track_stock'] !== 1) {
                    continue;
                }
                $lots = DB::all('SELECT lot_id, quantity FROM sale_item_lots WHERE sale_item_id = ?', [$it['id']]);
                if ($lots) {
                    foreach ($lots as $lt) {
                        StockService::move((int) $it['product_id'], (int) $sale['warehouse_id'], (float) $lt['quantity'], 'sale_cancel', 'sale', $saleId, 'Annulation ' . $sale['number'], (float) $it['unit_cost'], (int) $lt['lot_id']);
                    }
                } else {
                    StockService::move((int) $it['product_id'], (int) $sale['warehouse_id'], (float) $it['base_quantity'], 'sale_cancel', 'sale', $saleId, 'Annulation ' . $sale['number'], (float) $it['unit_cost']);
                }
            }
            // Caisse : contre-passation de chaque encaissement
            foreach (DB::all("SELECT method, SUM(amount) AS amount FROM sale_payments WHERE sale_id = ? AND method <> 'credit' GROUP BY method", [$saleId]) as $p) {
                CashService::movement($targetSession, 'sale_cancel', $p['method'], -(float) $p['amount'], 'sale', $saleId, 'Annulation ' . $sale['number']);
            }
            if ((float) $sale['credit_amount'] > 0 && $sale['customer_id']) {
                CustomerService::ledger((int) $sale['customer_id'], 'adjustment', -(float) $sale['credit_amount'], null, 'sale', $saleId, 'Annulation vente ' . $sale['number']);
            }
            DB::update('sales', ['status' => 'cancelled', 'cancelled_by' => Auth::id(), 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$saleId]);
            Audit::log('sale.cancel', 'sale', $saleId, ['number' => $sale['number'], 'reason' => $reason, 'total' => $sale['total']]);
        });
    }

    /**
     * Retour client (total ou partiel) avec remboursement.
     * @param array $items [sale_item_id => quantité retournée]
     */
    public static function createReturn(int $saleId, array $items, string $refundMethod, string $reason, bool $restock): int
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif du retour est obligatoire.');
        }
        if (!in_array($refundMethod, ['cash', 'credit_note', 'card', 'mobile_money', 'transfer'], true)) {
            throw new BusinessException('Mode de remboursement invalide.');
        }
        return DB::transaction(function () use ($saleId, $items, $refundMethod, $reason, $restock) {
            $sale = DB::one('SELECT * FROM sales WHERE id = ? FOR UPDATE', [$saleId]);
            if (!$sale || $sale['status'] === 'cancelled' || $sale['status'] === 'returned') {
                throw new BusinessException('Cette vente ne peut pas faire l\'objet d\'un retour.');
            }
            $maxDays = (int) setting('return_max_days', '30');
            if (time() - strtotime($sale['created_at']) > $maxDays * 86400) {
                throw new BusinessException("Délai de retour dépassé ({$maxDays} jours).");
            }
            $session = CashService::currentSession();
            if ($refundMethod === 'cash' && !$session) {
                throw new BusinessException('Ouvrez une session de caisse pour rembourser en espèces.');
            }
            if ($refundMethod === 'credit_note' && !$sale['customer_id']) {
                throw new BusinessException('Un avoir nécessite une vente rattachée à un client.');
            }
            $number = Sequence::next('return');
            $retId = DB::insert('sale_returns', [
                'number' => $number, 'sale_id' => $saleId, 'total' => 0, 'refund_method' => $refundMethod,
                'reason' => mb_substr($reason, 0, 255), 'restock' => $restock ? 1 : 0, 'cash_session_id' => $session['id'] ?? null,
                'user_id' => Auth::id(),
            ]);
            $total = 0.0;
            $any = false;
            foreach ($items as $itemId => $q) {
                $q = (float) $q;
                if ($q <= 0) {
                    continue;
                }
                $it = DB::one('SELECT * FROM sale_items WHERE id = ? AND sale_id = ? FOR UPDATE', [(int) $itemId, $saleId]);
                if (!$it) {
                    throw new BusinessException('Ligne de vente invalide.');
                }
                $available = (float) $it['quantity'] - (float) $it['returned_qty'];
                if ($q > $available + 0.0005) {
                    throw new BusinessException(sprintf('Quantité retournée supérieure à la quantité vendue restante pour « %s » (%s).', $it['product_name'], qty($available)));
                }
                $any = true;
                $amount = round((float) $it['line_total'] / (float) $it['quantity'] * $q, 2);
                $total += $amount;
                DB::insert('sale_return_items', ['return_id' => $retId, 'sale_item_id' => (int) $it['id'], 'product_id' => (int) $it['product_id'], 'quantity' => $q, 'amount' => $amount]);
                DB::run('UPDATE sale_items SET returned_qty = returned_qty + ? WHERE id = ?', [$q, $it['id']]);
                $trackStock = (int) DB::value('SELECT track_stock FROM products WHERE id = ?', [$it['product_id']]);
                $components = DB::all('SELECT * FROM sale_item_components WHERE sale_item_id = ?', [$it['id']]);
                if ($restock && $components) {
                    // Plat non consommé et réutilisable : ingrédients réintégrés au prorata
                    $ratio = $q / (float) $it['quantity'];
                    foreach ($components as $c) {
                        StockService::move((int) $c['ingredient_id'], (int) $sale['warehouse_id'], round((float) $c['quantity'] * $ratio, 4), 'return', 'return', $retId, $number, (float) $c['unit_cost'], $c['lot_id'] ? (int) $c['lot_id'] : null);
                    }
                } elseif ($restock && $trackStock === 1) {
                    $baseQ = round($q * (float) $it['base_quantity'] / (float) $it['quantity'], 3);
                    $lots = DB::all('SELECT lot_id, quantity FROM sale_item_lots WHERE sale_item_id = ? ORDER BY id', [$it['id']]);
                    if ($lots) {
                        // Réintégration dans les lots d'origine (proportionnelle)
                        $left = $baseQ;
                        foreach ($lots as $lt) {
                            if ($left <= 0.0005) {
                                break;
                            }
                            $put = min($left, (float) $lt['quantity']);
                            StockService::move((int) $it['product_id'], (int) $sale['warehouse_id'], $put, 'return', 'return', $retId, $number, (float) $it['unit_cost'], (int) $lt['lot_id']);
                            $left = round($left - $put, 3);
                        }
                    } else {
                        StockService::move((int) $it['product_id'], (int) $sale['warehouse_id'], $baseQ, 'return', 'return', $retId, $number, (float) $it['unit_cost']);
                    }
                }
            }
            if (!$any) {
                throw new BusinessException('Sélectionnez au moins un article à retourner.');
            }
            $total = round($total, 2);
            DB::run('UPDATE sale_returns SET total = ? WHERE id = ?', [$total, $retId]);
            if ($refundMethod === 'credit_note') {
                CustomerService::ledger((int) $sale['customer_id'], 'refund', -$total, null, 'return', $retId, 'Avoir ' . $number);
            } else {
                if ($refundMethod === 'cash' && CashService::expectedCash((int) $session['id']) < $total - 0.009) {
                    throw new BusinessException('Espèces insuffisantes dans la caisse pour ce remboursement.');
                }
                CashService::movement($session['id'] ?? null, 'refund', $refundMethod, -$total, 'return', $retId, 'Remboursement ' . $number);
            }
            $remaining = (float) DB::value('SELECT COALESCE(SUM(quantity - returned_qty),0) FROM sale_items WHERE sale_id = ?', [$saleId]);
            DB::run('UPDATE sales SET status = ? WHERE id = ?', [$remaining <= 0.0005 ? 'returned' : 'partially_returned', $saleId]);
            Audit::log('sale.return', 'sale_return', $retId, ['sale' => $sale['number'], 'total' => $total, 'method' => $refundMethod, 'restock' => $restock]);
            return $retId;
        });
    }
}
