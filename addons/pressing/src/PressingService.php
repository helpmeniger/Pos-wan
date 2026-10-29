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
    public const STATUSES = ['received' => 'Déposé — en attente de traitement', 'processing' => 'En cours de traitement', 'ready' => 'Prêt à être retiré', 'partial' => 'Partiellement retiré', 'delivered' => 'Retiré', 'cancelled' => 'Annulé'];
    /** Unités de facturation d'un article : à la pièce, ou au poids (quantité décimale, ex. 4,5 kg). */
    public const UNITS = ['piece' => 'À la pièce', 'kg' => 'Au kilo (kg)'];
    /** Liste « 3 Chemise, 4,5 kg Linge au kilo » d'un dépôt (requêtes SQL ; alias de ligne : i). */
    public const CLOTHES_SQL = "GROUP_CONCAT(CONCAT(REPLACE(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM i.quantity)), '.', ','), IF(i.unit = 'kg', ' kg', ''), ' ', i.article_name) ORDER BY i.line_no SEPARATOR ', ')";
    /** Libellés courts (badges, listes). */
    public const SHORT = ['received' => 'Déposé', 'processing' => 'En traitement', 'ready' => 'Prêt', 'partial' => 'Retrait partiel', 'delivered' => 'Retiré', 'cancelled' => 'Annulé'];

    /** Pourcentages express proposés au comptoir (réglage « 25,50,100 »), toujours avec le taux par défaut. */
    public static function expressChoices(): array
    {
        $list = array_map(fn($v) => round((float) trim($v), 2), self::splitChoices((string) setting('pressing_express_choix', '25,50,100')));
        $list[] = self::expressDefault();
        $list = array_values(array_unique(array_filter($list, fn($v) => $v > 0 && $v <= 300)));
        sort($list);
        return $list;
    }

    /** « 25 ; 50 ; 100 » ou « 25, 50, 100 » → ['25', '50', '100'] (décimales avec un point : 12.5). */
    private static function splitChoices(string $raw): array
    {
        $parts = preg_split(str_contains($raw, ';') ? '/;/' : '/[,\s]+/', $raw) ?: [];
        return array_values(array_filter(array_map(fn($v) => str_replace([' ', '%'], '', trim($v)), $parts), fn($v) => $v !== ''));
    }

    public static function expressDefault(): float
    {
        return round(max(0.0, min(300.0, (float) str_replace(',', '.', (string) setting('pressing_express_pourcent', '50')))), 2);
    }

    /**
     * Pourcentage express d'un dépôt : un des choix proposés, ou une autre valeur réservée au
     * responsable (droit « Tarifs, remises… »). Vide : taux par défaut.
     */
    public static function expressPct(mixed $raw, bool $manage): float
    {
        $raw = trim(str_replace([' ', '%', ','], ['', '', '.'], (string) $raw));
        if ($raw === '') {
            return self::expressDefault();
        }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 300) {
            throw new BusinessException('Pourcentage express invalide (0 à 300 %).');
        }
        $pct = round((float) $raw, 2);
        if (!$manage && !in_array($pct, self::expressChoices(), true)) {
            throw new BusinessException('Pourcentage express de ' . self::qty($pct) . ' % : seuls les choix proposés (' . implode(', ', array_map(fn($v) => self::qty($v) . ' %', self::expressChoices())) . ') sont permis sans le droit « Tarifs, remises… ».');
        }
        return $pct;
    }

    /** Réglages du pressing (page Tarifs, responsable). */
    public static function saveSettings(array $d): void
    {
        $num = fn($k, $min, $max, $label) => (function () use ($d, $k, $min, $max, $label) {
            $v = trim(str_replace([' ', '%', ','], ['', '', '.'], (string) ($d[$k] ?? '')));
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                throw new BusinessException($label . ' : valeur entre ' . $min . ' et ' . $max . '.');
            }
            return (float) $v;
        })();
        $pct = $num('express_pourcent', 0, 300, 'Supplément express par défaut (%)');
        $choices = [];
        foreach (self::splitChoices((string) ($d['express_choix'] ?? '')) as $c) {
            if ($c === '') {
                continue;
            }
            if (!is_numeric($c) || (float) $c <= 0 || (float) $c > 300) {
                throw new BusinessException('Choix express : pourcentages entre 1 et 300, séparés par des points-virgules (ex. 25 ; 50 ; 100).');
            }
            $choices[] = (string) round((float) $c, 2);
        }
        $values = [
            'pressing_express_pourcent' => (string) round($pct, 2),
            'pressing_express_choix' => implode(';', array_unique($choices)),
            'pressing_express_delai_jours' => (string) (int) $num('express_delai', 0, 30, 'Délai express (jours)'),
            'pressing_delai_jours' => (string) (int) $num('delai', 0, 60, 'Délai normal (jours)'),
            'pressing_livraison_frais' => (string) round($num('livraison_frais', 0, 1000000, 'Frais de livraison'), 2),
        ];
        foreach ($values as $k => $v) {
            setting_set($k, $v);
        }
        Audit::log('pressing.settings', null, null, $values);
    }

    /** Quantité saisie (virgule ou point décimal acceptés) ; null si illisible. */
    public static function parseQty(mixed $v): ?float
    {
        $raw = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], trim((string) $v));
        return $raw !== '' && is_numeric($raw) ? round((float) $raw, 3) : null;
    }

    /** Affichage d'une quantité : « 3 », « 4,5 kg », « 0,75 kg ». */
    public static function qty(mixed $q, string $unit = 'piece'): string
    {
        $q = round((float) $q, 3);
        $txt = rtrim(rtrim(number_format($q, 3, ',', ' '), '0'), ',');
        return $unit === 'kg' ? $txt . ' kg' : $txt;
    }

    /** Jours de retard : dépôt non prêt après la date prévue, ou prêt mais non retiré après cette date. */
    public static function lateDays(array $o): int
    {
        if (!in_array($o['status'], ['received', 'processing', 'ready', 'partial'], true)) {
            return 0;
        }
        return max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime((string) $o['due_date'])) / 86400));
    }
    /** Étapes du traitement (le retrait et l'annulation ont leurs propres actions). */
    public const STEPS = ['received' => ['processing', 'ready'], 'processing' => ['ready', 'received'], 'ready' => ['processing']];

    /** Tarifs actifs : [article_id => [name, category, prices[service => prix]]]. */
    public static function tarifs(bool $activeOnly = true): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM pressing_articles' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name') as $a) {
            $out[(int) $a['id']] = ['id' => (int) $a['id'], 'name' => $a['name'], 'category' => $a['category'], 'unit' => ($a['unit'] ?? 'piece') === 'kg' ? 'kg' : 'piece', 'active' => (int) $a['active'], 'prices' => []];
        }
        foreach (DB::all('SELECT * FROM pressing_prices') as $p) {
            if (isset($out[(int) $p['article_id']])) {
                $out[(int) $p['article_id']]['prices'][$p['service']] = (float) $p['price'];
            }
        }
        return $out;
    }

    /**
     * Clients déjà venus proposés à la saisie (nom → téléphone), le plus récent d'abord.
     * Un numéro invalide (plus de 15 chiffres, ex. saisi deux fois) n'est jamais reproposé.
     */
    public static function recentClients(): array
    {
        $rows = DB::all('SELECT customer_name, phone FROM pressing_orders GROUP BY customer_name, phone ORDER BY MAX(id) DESC LIMIT 400');
        return array_slice(array_values(array_filter($rows, fn($r) => strlen(self::digits((string) $r['phone'])) <= 15)), 0, 300);
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
        if (strlen(self::digits($phone)) > 15) {
            throw new BusinessException('Téléphone invalide (' . strlen(self::digits($phone)) . ' chiffres, 15 au plus) : vérifiez qu\'il n\'a pas été saisi deux fois.');
        }
        $tarifs = self::tarifs();
        $manage = Auth::can('pressing.manage');
        $lines = [];
        $subtotal = 0.0;
        $pieces = 0;
        $weight = 0.0;
        $decimals = currency()['decimals'];
        foreach ((array) ($d['lines'] ?? []) as $l) {
            $aid = (int) ($l['article_id'] ?? 0);
            if ($aid <= 0) {
                continue;
            }
            $svc = (string) ($l['service'] ?? '');
            if (!isset($tarifs[$aid])) {
                throw new BusinessException('Article inconnu ou désactivé.');
            }
            $unit = $tarifs[$aid]['unit'];
            $q = self::parseQty($l['quantity'] ?? '');
            if (!isset(self::SERVICES[$svc], $tarifs[$aid]['prices'][$svc])) {
                throw new BusinessException('Service « ' . (self::SERVICES[$svc] ?? $svc) . ' » non proposé pour « ' . $tarifs[$aid]['name'] . ' ».');
            }
            if ($unit === 'kg') {
                if ($q === null || $q < 0.001 || $q > 1000) {
                    throw new BusinessException('Poids invalide pour « ' . $tarifs[$aid]['name'] . ' » : indiquez les kilos, par exemple 4,5.');
                }
            } elseif ($q === null || $q < 1 || $q > 200 || abs($q - round($q)) > 0.0001) {
                throw new BusinessException('Quantité invalide pour « ' . $tarifs[$aid]['name'] . ' » : nombre entier de pièces (seuls les articles au kilo acceptent une virgule).');
            }
            $list = $tarifs[$aid]['prices'][$svc];
            $price = $list;
            if (isset($l['unit_price']) && $l['unit_price'] !== '' && abs((float) $l['unit_price'] - $list) >= 0.01) {
                if (!$manage) {
                    throw new BusinessException('Modification de prix réservée au responsable (droit « Tarifs, remises… »).');
                }
                $price = max(0.0, round((float) $l['unit_price'], 2));
            }
            $lines[] = ['article_id' => $aid, 'article_name' => $tarifs[$aid]['name'], 'service' => $svc, 'quantity' => $q, 'unit' => $unit, 'unit_price' => $price, 'list_price' => $list, 'line_total' => round($price * $q, $decimals),
                'color' => mb_substr(trim((string) ($l['color'] ?? '')), 0, 60) ?: null, 'brand' => mb_substr(trim((string) ($l['brand'] ?? '')), 0, 80) ?: null,
                'defects' => mb_substr(trim((string) ($l['defects'] ?? '')), 0, 255) ?: null];
            $subtotal += round($price * $q, $decimals);
            // Un sac de linge pesé compte pour un colis (une étiquette)
            $pieces += $unit === 'kg' ? 1 : (int) round($q);
            $weight += $unit === 'kg' ? $q : 0.0;
        }
        if (!$lines) {
            throw new BusinessException('Ajoutez au moins un article.');
        }
        if (count($lines) > 100) {
            throw new BusinessException('Trop de lignes dans le dépôt.');
        }
        $subtotal = round($subtotal, 2);
        $express = !empty($d['express']);
        $expressPct = $express ? self::expressPct($d['express_pct'] ?? null, $manage) : null;
        $expressFee = $express ? round($subtotal * $expressPct / 100, currency()['decimals']) : 0.0;
        // Livraison à domicile : adresse obligatoire, frais modifiables (par défaut : réglage du pressing)
        $delivery = !empty($d['delivery']);
        $address = $delivery ? mb_substr(trim((string) ($d['delivery_address'] ?? '')), 0, 255) : '';
        if ($delivery && mb_strlen($address) < 3) {
            throw new BusinessException('Livraison à domicile : indiquez l\'adresse (quartier, rue, repère).');
        }
        $rawFee = trim(str_replace([' ', ','], ['', '.'], (string) ($d['delivery_fee'] ?? '')));
        $deliveryFee = !$delivery ? 0.0 : ($rawFee === '' ? (float) setting('pressing_livraison_frais', '0') : (is_numeric($rawFee) ? round((float) $rawFee, 2) : -1.0));
        if ($deliveryFee < 0 || $deliveryFee > 1000000) {
            throw new BusinessException('Frais de livraison invalides.');
        }
        $discount = round(max(0.0, (float) str_replace([' ', ','], ['', '.'], (string) ($d['discount'] ?? '0'))), 2);
        if ($discount > 0 && !$manage && !Auth::can('pos.discount')) {
            throw new BusinessException('Vous n\'êtes pas autorisé à accorder des remises.');
        }
        if ($discount > $subtotal + $expressFee + $deliveryFee) {
            throw new BusinessException('Remise supérieure au montant du dépôt.');
        }
        $total = round($subtotal + $expressFee + $deliveryFee - $discount, 2);
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

        return DB::transaction(function () use ($name, $phone, $express, $expressPct, $delivery, $address, $deliveryFee, $due, $subtotal, $expressFee, $discount, $total, $pieces, $weight, $lines, $d, $dep, $tendered, $customerId) {
            $number = Sequence::next('pressing');
            $id = DB::insert('pressing_orders', [
                'number' => $number, 'customer_id' => $customerId ?: null, 'customer_name' => mb_substr($name, 0, 150), 'phone' => mb_substr($phone, 0, 50),
                'express' => $express ? 1 : 0, 'due_date' => $due, 'subtotal' => $subtotal, 'express_fee' => $expressFee, 'express_pct' => $expressPct,
                'delivery' => $delivery ? 1 : 0, 'delivery_address' => $address ?: null, 'delivery_fee' => $deliveryFee, 'discount' => $discount, 'total' => $total,
                'pieces' => $pieces, 'weight' => round($weight, 3), 'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id(),
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
            if (!in_array($o['status'], ['ready', 'processing', 'received', 'partial'], true)) {
                throw new BusinessException('Ce dépôt ne peut pas être retiré (statut : ' . self::STATUSES[$o['status']] . ').');
            }
            if (!in_array($o['status'], ['ready', 'partial'], true) && !Auth::can('pressing.manage')) {
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
            DB::run('UPDATE pressing_items SET picked_at = COALESCE(IF(picked_qty < quantity, NOW(), picked_at), NOW()), picked_qty = quantity WHERE order_id = ?', [$id]);
            self::log($id, $o['status'], 'delivered', $note);
            Audit::log('pressing.deliver', 'pressing_order', $id, ['unpaid' => $due > 0.009 ? $due : 0]);
            return $change;
        });
    }

    /**
     * Retrait partiel : le client emporte une partie des pièces ($qtys [item_id => quantité]).
     * Paiement facultatif à ce moment ; le solde reste exigé au dernier retrait.
     * Quand toutes les pièces sont retirées, le dépôt passe à « Retiré ».
     */
    public static function deliverPartial(int $id, array $qtys, array $pay = []): float
    {
        if (setting('pressing_retrait_partiel', '1') !== '1') {
            throw new BusinessException('Les retraits partiels ne sont pas autorisés (paramètre du pressing).');
        }
        return DB::transaction(function () use ($id, $qtys, $pay) {
            $o = self::lock($id);
            if (!in_array($o['status'], ['ready', 'partial', 'processing'], true)) {
                throw new BusinessException('Retrait impossible (statut : ' . self::STATUSES[$o['status']] . ').');
            }
            $items = DB::all('SELECT * FROM pressing_items WHERE order_id = ? FOR UPDATE', [$id]);
            $taken = [];
            foreach ($items as $it) {
                $q = self::parseQty($qtys[$it['id']] ?? '') ?? 0.0;
                $unit = ($it['unit'] ?? 'piece') === 'kg' ? 'kg' : 'piece';
                $left = round((float) $it['quantity'] - (float) $it['picked_qty'], 3);
                if ($q <= 0) {
                    continue;
                }
                if ($unit === 'piece' && abs($q - round($q)) > 0.0001) {
                    throw new BusinessException('« ' . $it['article_name'] . ' » : nombre entier de pièces.');
                }
                if ($q > $left + 0.0005) {
                    throw new BusinessException('« ' . $it['article_name'] . ' » : seulement ' . ($unit === 'kg' ? self::qty($left, 'kg') : self::qty($left) . ' pièce(s)') . ' restante(s).');
                }
                DB::run('UPDATE pressing_items SET picked_qty = picked_qty + ?, picked_at = NOW() WHERE id = ?', [$q, $it['id']]);
                $taken[] = ($unit === 'kg' ? self::qty($q, 'kg') : self::qty($q) . ' ×') . ' ' . $it['article_name'] . ' [' . $it['tag'] . ']';
            }
            if (!$taken) {
                throw new BusinessException('Indiquez les pièces remises au client.');
            }
            $remaining = (int) DB::value("SELECT COALESCE(SUM(IF(unit = 'kg', IF(quantity - picked_qty > 0.0005, 1, 0), quantity - picked_qty)),0) FROM pressing_items WHERE order_id = ?", [$id]);
            if ($remaining <= 0) {
                return self::deliver($id, $pay);
            }
            $change = (float) ($pay['amount'] ?? 0) > 0 ? self::pay($id, $pay) : 0.0;
            DB::update('pressing_orders', ['status' => 'partial'], 'id = ?', [$id]);
            self::log($id, $o['status'], 'partial', 'Remis : ' . implode(', ', $taken) . ' — reste ' . $remaining . ' pièce(s)');
            Audit::log('pressing.partial', 'pressing_order', $id, ['taken' => $taken, 'remaining' => $remaining]);
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
            if (in_array($o['status'], ['delivered', 'cancelled', 'partial'], true)) {
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
    public static function saveTarifs(array $prices, array $names, array $active, array $units = []): void
    {
        DB::transaction(function () use ($prices, $names, $active, $units) {
            foreach ($names as $aid => $name) {
                $aid = (int) $aid;
                $name = trim((string) $name);
                if ($name === '') {
                    throw new BusinessException('Le nom d\'un article ne peut pas être vide.');
                }
                $row = ['name' => mb_substr($name, 0, 100), 'active' => !empty($active[$aid]) ? 1 : 0];
                if (isset($units[$aid], self::UNITS[$units[$aid]])) {
                    $row['unit'] = $units[$aid]; // les dépôts déjà enregistrés gardent leur unité
                }
                DB::update('pressing_articles', $row, 'id = ?', [$aid]);
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

    public static function addArticle(string $name, string $category, string $unit = 'piece'): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new BusinessException('Nom de l\'article obligatoire.');
        }
        if (DB::value('SELECT id FROM pressing_articles WHERE name = ?', [$name])) {
            throw new BusinessException('Cet article existe déjà.');
        }
        return DB::insert('pressing_articles', ['name' => mb_substr($name, 0, 100), 'category' => mb_substr(trim($category) ?: 'Vêtements', 0, 50), 'unit' => isset(self::UNITS[$unit]) ? $unit : 'piece', 'sort_order' => (int) DB::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM pressing_articles')]);
    }

    /**
     * Tableau des dépôts en cours pour l'écran de l'atelier : colonnes déposés / en traitement / prêts.
     * $public : affichage côté clients (numéro et prénom seulement, aucun téléphone ni montant).
     */
    public static function board(bool $public = false): array
    {
        $rows = DB::all("SELECT o.id, o.number, o.customer_name, o.phone, o.status, o.express, o.delivery, o.delivery_address, o.due_date, o.pieces, o.weight, o.total, o.paid, o.created_at, o.ready_at, o.note,
                (SELECT " . self::CLOTHES_SQL . " FROM pressing_items i WHERE i.order_id = o.id) AS clothes
                FROM pressing_orders o WHERE o.status IN ('received','processing','ready','partial')
                ORDER BY o.express DESC, o.due_date, o.id LIMIT 600");
        $today = date('Y-m-d');
        $cols = ['received' => [], 'processing' => [], 'ready' => [], 'delivery' => []];
        $t = ['open' => 0, 'late' => 0, 'today' => 0, 'ready' => 0, 'delivery' => 0, 'express' => 0, 'pieces' => 0, 'due' => 0.0];
        foreach ($rows as $o) {
            $late = self::lateDays($o);
            $col = $o['status'] === 'partial' ? 'ready' : $o['status'];
            if ($col === 'ready' && (int) $o['delivery'] === 1) {
                $col = 'delivery'; // prêt, à porter chez le client
            }
            $rest = max(0, round((float) $o['total'] - (float) $o['paid'], 2));
            $t['open']++;
            $t['late'] += $late > 0 ? 1 : 0;
            $t['today'] += $o['due_date'] === $today && !in_array($col, ['ready', 'delivery'], true) ? 1 : 0;
            $t['ready'] += $col === 'ready' ? 1 : 0;
            $t['delivery'] += $col === 'delivery' ? 1 : 0;
            $t['express'] += (int) $o['express'];
            $t['pieces'] += (int) $o['pieces'];
            $t['due'] += $rest;
            $first = trim(explode(' ', trim((string) $o['customer_name']))[0] ?? '');
            $cols[$col][] = $public ? ['number' => $o['number'], 'name' => $first, 'status' => $o['status'], 'express' => (bool) $o['express']] : [
                'id' => (int) $o['id'], 'number' => $o['number'], 'name' => $o['customer_name'], 'phone' => $o['phone'], 'status' => $o['status'],
                'express' => (bool) $o['express'], 'due' => $o['due_date'], 'late' => $late, 'pieces' => (int) $o['pieces'], 'weight' => (float) $o['weight'],
                'clothes' => mb_substr((string) $o['clothes'], 0, 160), 'rest' => $rest, 'note' => (string) $o['note'],
                'since' => $o['ready_at'] ?: $o['created_at'], 'due_today' => $o['due_date'] === $today,
                'delivery' => (int) $o['delivery'] === 1, 'address' => (string) ($o['delivery_address'] ?? ''),
            ];
        }
        if ($public) {
            // Côté clients : seulement les dépôts prêts, sans aucune donnée personnelle hormis le prénom
            return ['now' => date('H:i'), 'ready' => $cols['ready'], 'totals' => ['ready' => $t['ready']]];
        }
        $t['due'] = round($t['due'], 2);
        return ['now' => date('H:i'), 'columns' => $cols, 'totals' => $t, 'labels' => self::SHORT];
    }
}
