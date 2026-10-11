<?php
declare(strict_types=1);

namespace Addons\Pressing;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\CashService;
use App\Services\Sequence;

/**
 * Règles métier du pressing. Les montants sont recalculés côté serveur à partir des
 * tarifs ; les paiements passent par la caisse ouverte (mouvements « pressing »), comme
 * les ventes, pour que la clôture de caisse reste juste.
 */
final class PressingService
{
    public const SERVICES = ['lavage' => 'Lavage + repassage', 'sec' => 'Nettoyage à sec', 'repassage' => 'Repassage seul', 'teinture' => 'Teinture'];
    public const STATUSES = ['received' => 'Reçu', 'processing' => 'En cours', 'ready' => 'Prêt', 'delivered' => 'Retiré', 'cancelled' => 'Annulé'];
    /** Étapes du traitement (le retrait et l'annulation ont leurs propres actions). */
    public const STEPS = ['received' => ['processing', 'ready'], 'processing' => ['ready', 'received'], 'ready' => ['processing']];

    /** Tarifs actifs : [article_id => [name, category, prices[service => prix]]]. */
    public static function tarifs(bool $activeOnly = true): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM pressing_articles' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name') as $a) {
            $out[(int) $a['id']] = ['id' => (int) $a['id'], 'name' => $a['name'], 'category' => $a['category'], 'active' => (int) $a['active'], 'prices' => []];
        }
        foreach (DB::all('SELECT * FROM pressing_prices') as $p) {
            if (isset($out[(int) $p['article_id']])) {
                $out[(int) $p['article_id']]['prices'][$p['service']] = (float) $p['price'];
            }
        }
        return $out;
    }

    private static function digits(string $phone): string
    {
        return (string) preg_replace('/\D/', '', $phone);
    }

    /** Session de caisse obligatoire pour encaisser (même règle que la caisse). */
    private static function session(): ?int
    {
        $s = CashService::currentSession(Auth::user());
        if (!$s && setting('require_cash_session', '1') === '1') {
            throw new BusinessException('La caisse est fermée : ouvrez votre caisse (Ma caisse) avant d\'encaisser.');
        }
        return $s ? (int) $s['id'] : null;
    }

    /** Enregistre un paiement (acompte, paiement, remboursement) dans la caisse. */
    private static function recordPayment(int $orderId, string $number, string $kind, array $pay): float
    {
        $methods = payment_methods();
        [$method, $provider] = array_pad(explode('|', (string) ($pay['method'] ?? 'cash'), 2), 2, null);
        if (!isset($methods[$method])) {
            throw new BusinessException('Mode de paiement non autorisé.');
        }
        $amount = round((float) $pay['amount'], 2);
        $sessionId = self::session();
        $signed = $kind === 'refund' ? -$amount : $amount;
        DB::insert('pressing_payments', [
            'order_id' => $orderId, 'kind' => $kind, 'method' => $method, 'provider' => $method === 'mobile_money' ? mobile_money_provider($provider) : null,
            'amount' => $signed, 'reference' => mb_substr(trim((string) ($pay['reference'] ?? '')), 0, 100) ?: null, 'cash_session_id' => $sessionId, 'user_id' => Auth::id(),
        ]);
        CashService::movement($sessionId, $kind === 'refund' ? 'pressing_refund' : 'pressing', $method, $signed, 'pressing', $orderId, 'Pressing ' . $number);
        DB::run('UPDATE pressing_orders SET paid = paid + ? WHERE id = ?', [$signed, $orderId]);
        return $amount;
    }

    private static function log(int $orderId, ?string $from, string $to, ?string $note = null): void
    {
        DB::insert('pressing_status_log', ['order_id' => $orderId, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'user_id' => Auth::id()]);
    }

    /**
     * Nouveau dépôt.
     * $d : customer_name, phone, express, due_date, discount, note, lines[{article_id, service, quantity, unit_price?, color, brand, defects}],
     *      deposit{method, amount, reference} (acompte facultatif)
     * @return array{id:int, number:string, change:float}
     */
    public static function create(array $d): array
    {
        $name = trim((string) ($d['customer_name'] ?? ''));
        $phone = trim((string) ($d['phone'] ?? ''));
        if ($name === '' || strlen(self::digits($phone)) < 8) {
            throw new BusinessException('Nom du client et téléphone (8 chiffres au moins) obligatoires.');
        }
        $tarifs = self::tarifs();
        $manage = Auth::can('pressing.manage');
        $lines = [];
        $subtotal = 0.0;
        $pieces = 0;
        foreach ((array) ($d['lines'] ?? []) as $l) {
            $aid = (int) ($l['article_id'] ?? 0);
            if ($aid <= 0) {
                continue;
            }
            $svc = (string) ($l['service'] ?? '');
            $q = (int) ($l['quantity'] ?? 0);
            if (!isset($tarifs[$aid])) {
                throw new BusinessException('Article inconnu ou désactivé.');
            }
            if (!isset(self::SERVICES[$svc], $tarifs[$aid]['prices'][$svc])) {
                throw new BusinessException('Service « ' . (self::SERVICES[$svc] ?? $svc) . ' » non proposé pour « ' . $tarifs[$aid]['name'] . ' ».');
            }
            if ($q < 1 || $q > 200) {
                throw new BusinessException('Quantité invalide pour « ' . $tarifs[$aid]['name'] . ' ».');
            }
            $list = $tarifs[$aid]['prices'][$svc];
            $price = $list;
            if (isset($l['unit_price']) && $l['unit_price'] !== '' && abs((float) $l['unit_price'] - $list) >= 0.01) {
                if (!$manage) {
                    throw new BusinessException('Modification de prix réservée au responsable (droit « Tarifs, remises… »).');
                }
                $price = max(0.0, round((float) $l['unit_price'], 2));
            }
            $lines[] = ['article_id' => $aid, 'article_name' => $tarifs[$aid]['name'], 'service' => $svc, 'quantity' => $q, 'unit_price' => $price, 'list_price' => $list, 'line_total' => round($price * $q, 2),
                'color' => mb_substr(trim((string) ($l['color'] ?? '')), 0, 60) ?: null, 'brand' => mb_substr(trim((string) ($l['brand'] ?? '')), 0, 80) ?: null,
                'defects' => mb_substr(trim((string) ($l['defects'] ?? '')), 0, 255) ?: null];
            $subtotal += $price * $q;
            $pieces += $q;
        }
        if (!$lines) {
            throw new BusinessException('Ajoutez au moins un article.');
        }
        if (count($lines) > 100) {
            throw new BusinessException('Trop de lignes dans le dépôt.');
        }
        $subtotal = round($subtotal, 2);
        $express = !empty($d['express']);
        $expressFee = $express ? round($subtotal * max(0.0, (float) setting('pressing_express_pourcent', '50')) / 100) : 0.0;
        $discount = round(max(0.0, (float) str_replace([' ', ','], ['', '.'], (string) ($d['discount'] ?? '0'))), 2);
        if ($discount > 0 && !$manage && !Auth::can('pos.discount')) {
            throw new BusinessException('Vous n\'êtes pas autorisé à accorder des remises.');
        }
        if ($discount > $subtotal + $expressFee) {
            throw new BusinessException('Remise supérieure au montant du dépôt.');
        }
        $total = round($subtotal + $expressFee - $discount, 2);
        $days = (int) setting($express ? 'pressing_express_delai_jours' : 'pressing_delai_jours', $express ? '1' : '3');
        $due = (string) ($d['due_date'] ?? '');
        if ($due === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            $due = date('Y-m-d', strtotime('+' . max(0, $days) . ' days'));
        } elseif ($due < date('Y-m-d')) {
            throw new BusinessException('La date de retrait prévue ne peut pas être passée.');
        }
        $dep = (array) ($d['deposit'] ?? []);
        $tendered = round((float) str_replace([' ', ','], ['', '.'], (string) ($dep['amount'] ?? '0')), 2);
        if ($tendered < 0) {
            throw new BusinessException('Acompte invalide.');
        }
        $method = explode('|', (string) ($dep['method'] ?? 'cash'))[0];
        if ($tendered > $total && $method !== 'cash') {
            throw new BusinessException('Un paiement électronique ne peut pas dépasser le total.');
        }
        $customerId = DB::value("SELECT id FROM customers WHERE REPLACE(REPLACE(phone, ' ', ''), '+', '') LIKE ? LIMIT 1", ['%' . substr(self::digits($phone), -8)]);

        return DB::transaction(function () use ($name, $phone, $express, $due, $subtotal, $expressFee, $discount, $total, $pieces, $lines, $d, $dep, $tendered, $customerId) {
            $number = Sequence::next('pressing');
            $id = DB::insert('pressing_orders', [
                'number' => $number, 'customer_id' => $customerId ?: null, 'customer_name' => mb_substr($name, 0, 150), 'phone' => mb_substr($phone, 0, 50),
                'express' => $express ? 1 : 0, 'due_date' => $due, 'subtotal' => $subtotal, 'express_fee' => $expressFee, 'discount' => $discount, 'total' => $total,
                'pieces' => $pieces, 'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id(),
            ]);
            $short = ltrim(substr($number, -6), '0') ?: '0';
            foreach ($lines as $i => $l) {
                DB::insert('pressing_items', $l + ['order_id' => $id, 'line_no' => $i + 1, 'tag' => $short . '-' . ($i + 1)]);
            }
            self::log($id, null, 'received');
            $change = 0.0;
            if ($tendered > 0) {
                $amount = min($tendered, $total);
                $change = round($tendered - $amount, 2);
                if ($amount > 0) {
                    self::recordPayment($id, $number, 'deposit', ['method' => $dep['method'] ?? 'cash', 'amount' => $amount, 'reference' => $dep['reference'] ?? null]);
                }
            }
            Audit::log('pressing.create', 'pressing_order', $id, ['number' => $number, 'total' => $total]);
            return ['id' => $id, 'number' => $number, 'change' => $change];
        });
    }

    private static function lock(int $id): array
    {
        $o = DB::one('SELECT * FROM pressing_orders WHERE id = ? FOR UPDATE', [$id]);
        if (!$o) {
            throw new BusinessException('Dépôt introuvable.');
        }
        return $o;
    }

    /** Étape du traitement : reçu ↔ en cours → prêt. */
    public static function setStatus(int $id, string $to): void
    {
        DB::transaction(function () use ($id, $to) {
            $o = self::lock($id);
            if (!in_array($to, self::STEPS[$o['status']] ?? [], true)) {
                throw new BusinessException('Changement de statut non autorisé.');
            }
            DB::update('pressing_orders', ['status' => $to, 'ready_at' => $to === 'ready' ? date('Y-m-d H:i:s') : null], 'id = ?', [$id]);
            self::log($id, $o['status'], $to);
            Audit::log('pressing.status', 'pressing_order', $id, ['from' => $o['status'], 'to' => $to]);
        });
    }

    /** Paiement d'une partie du solde ; retourne la monnaie à rendre (espèces). */
    public static function pay(int $id, array $pay): float
    {
        return DB::transaction(function () use ($id, $pay) {
            $o = self::lock($id);
            if ($o['status'] === 'cancelled') {
                throw new BusinessException('Dépôt annulé.');
            }
            $due = round((float) $o['total'] - (float) $o['paid'], 2);
            $tendered = round((float) str_replace([' ', ','], ['', '.'], (string) ($pay['amount'] ?? '0')), 2);
            if ($due <= 0) {
                throw new BusinessException('Ce dépôt est déjà entièrement payé.');
            }
            if ($tendered <= 0) {
                throw new BusinessException('Montant invalide.');
            }
            if ($tendered > $due && explode('|', (string) ($pay['method'] ?? 'cash'))[0] !== 'cash') {
                throw new BusinessException('Un paiement électronique ne peut pas dépasser le reste dû.');
            }
            $amount = min($tendered, $due);
            self::recordPayment($id, $o['number'], 'payment', ['amount' => $amount] + $pay);
            Audit::log('pressing.payment', 'pressing_order', $id, ['amount' => $amount]);
            return round($tendered - $amount, 2);
        });
    }

    /**
     * Retrait du linge : paiement du solde éventuel puis remise au client.
     * Un solde impayé n'est accepté qu'avec le droit pressing.manage et un motif.
     */
    public static function deliver(int $id, array $pay = [], ?string $unpaidReason = null): float
    {
        return DB::transaction(function () use ($id, $pay, $unpaidReason) {
            $o = self::lock($id);
            if (!in_array($o['status'], ['ready', 'processing', 'received'], true)) {
                throw new BusinessException('Ce dépôt ne peut pas être retiré (statut : ' . self::STATUSES[$o['status']] . ').');
            }
            if ($o['status'] !== 'ready' && !Auth::can('pressing.manage')) {
                throw new BusinessException('Le linge n\'est pas encore marqué « Prêt ».');
            }
            $change = 0.0;
            if ((float) ($pay['amount'] ?? 0) > 0) {
                $change = self::pay($id, $pay);
            }
            $due = round((float) DB::value('SELECT total - paid FROM pressing_orders WHERE id = ?', [$id]), 2);
            $note = null;
            if ($due > 0.009) {
                if (!Auth::can('pressing.manage') || trim((string) $unpaidReason) === '') {
                    throw new BusinessException('Reste à payer : ' . money($due) . '. Encaissez le solde avant de remettre le linge.');
                }
                $note = 'Remis avec un solde impayé de ' . money($due) . ' : ' . mb_substr(trim((string) $unpaidReason), 0, 180);
            }
            DB::update('pressing_orders', ['status' => 'delivered', 'delivered_at' => date('Y-m-d H:i:s'), 'delivered_by' => Auth::id()], 'id = ?', [$id]);
            self::log($id, $o['status'], 'delivered', $note);
            Audit::log('pressing.deliver', 'pressing_order', $id, ['unpaid' => $due > 0.009 ? $due : 0]);
            return $change;
        });
    }

    /** Annulation (motif obligatoire) ; les sommes déjà payées sont remboursées en espèces. */
    public static function cancel(int $id, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif d\'annulation est obligatoire.');
        }
        DB::transaction(function () use ($id, $reason) {
            $o = self::lock($id);
            if (in_array($o['status'], ['delivered', 'cancelled'], true)) {
                throw new BusinessException('Ce dépôt ne peut plus être annulé.');
            }
            if ((float) $o['paid'] > 0) {
                self::recordPayment($id, $o['number'], 'refund', ['method' => 'cash', 'amount' => (float) $o['paid'], 'reference' => 'Annulation']);
            }
            DB::update('pressing_orders', ['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 255)], 'id = ?', [$id]);
            self::log($id, $o['status'], 'cancelled', mb_substr(trim($reason), 0, 255));
            Audit::log('pressing.cancel', 'pressing_order', $id, ['reason' => $reason, 'refund' => (float) $o['paid']]);
        });
    }

    /** Enregistre la grille des tarifs (prix vide = service non proposé). */
    public static function saveTarifs(array $prices, array $names, array $active): void
    {
        DB::transaction(function () use ($prices, $names, $active) {
            foreach ($names as $aid => $name) {
                $aid = (int) $aid;
                $name = trim((string) $name);
                if ($name === '') {
                    throw new BusinessException('Le nom d\'un article ne peut pas être vide.');
                }
                DB::update('pressing_articles', ['name' => mb_substr($name, 0, 100), 'active' => !empty($active[$aid]) ? 1 : 0], 'id = ?', [$aid]);
                foreach (array_keys(self::SERVICES) as $svc) {
                    $raw = trim(str_replace([' ', ','], ['', '.'], (string) ($prices[$aid][$svc] ?? '')));
                    if ($raw === '') {
                        DB::run('DELETE FROM pressing_prices WHERE article_id = ? AND service = ?', [$aid, $svc]);
                        continue;
                    }
                    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 10000000) {
                        throw new BusinessException('Prix invalide pour « ' . $name . ' ».');
                    }
                    DB::run('INSERT INTO pressing_prices (article_id, service, price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)', [$aid, $svc, round((float) $raw, 2)]);
                }
            }
            Audit::log('pressing.tarifs', null, null, ['articles' => count($names)]);
        });
    }

    public static function addArticle(string $name, string $category): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new BusinessException('Nom de l\'article obligatoire.');
        }
        if (DB::value('SELECT id FROM pressing_articles WHERE name = ?', [$name])) {
            throw new BusinessException('Cet article existe déjà.');
        }
        return DB::insert('pressing_articles', ['name' => mb_substr($name, 0, 100), 'category' => mb_substr(trim($category) ?: 'Vêtements', 0, 50), 'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM pressing_articles')]);
    }
}
