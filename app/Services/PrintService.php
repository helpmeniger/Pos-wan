<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Logger;

/**
 * Impression multi-imprimantes.
 *
 * Un navigateur imprime toujours sur l'imprimante par défaut de l'appareil. Pour
 * diriger chaque document vers la bonne imprimante (ticket de l'alimentation, bon
 * de la cuisine, bon du bar…), le serveur POS Marwa — installé sur le PC où sont
 * branchées les imprimantes — envoie lui-même les données ESC/POS :
 *  - network : imprimante réseau (Ethernet/Wi-Fi), port RAW 9100 ;
 *  - windows : imprimante USB partagée sous Windows (\\localhost\NomDuPartage) ;
 *  - browser : pas d'envoi serveur, impression par la fenêtre du navigateur.
 *
 * Usages : receipt (tickets de caisse), invoice (factures A4, navigateur), kitchen:<poste>
 * (bons cuisine, bar…). Une imprimante peut être limitée à une caisse.
 * Une panne d'imprimante n'annule jamais une vente : l'erreur est signalée et journalisée.
 */
final class PrintService
{
    public const DRIVERS = ['browser' => 'Navigateur (imprimante par défaut de l\'appareil)', 'network' => 'Imprimante réseau (IP, port 9100)', 'windows' => 'Imprimante partagée Windows (USB)'];

    /** Imprimantes serveur (network / windows) actives pour un usage. */
    public static function targets(string $usage, ?int $registerId = null): array
    {
        $rows = DB::all("SELECT * FROM printers WHERE active = 1 AND driver <> 'browser' AND (register_id IS NULL OR register_id <=> ?) ORDER BY register_id IS NULL, id", [$registerId]);
        return array_values(array_filter($rows, fn($p) => in_array($usage, array_map('trim', explode(',', $p['usages'])), true)));
    }

    public static function usages(): array
    {
        $u = ['receipt' => 'Tickets de caisse', 'invoice' => 'Factures / devis A4 (navigateur)'];
        if (feature('restaurant')) {
            foreach (RestaurantService::stations() as $s) {
                $u['kitchen:' . $s] = 'Bons ' . $s;
            }
            $u['bill'] = 'Additions restaurant';
        }
        return $u;
    }

    /** Valide l'adresse selon le pilote (empêche d'écrire n'importe où sur le disque). */
    public static function validateAddress(string $driver, string $address): string
    {
        $address = trim($address);
        if ($driver === 'network') {
            if (!preg_match('/^([a-z0-9.-]+)(?::(\d{1,5}))?$/i', $address, $m) || (int) ($m[2] ?? 9100) < 1 || (int) ($m[2] ?? 9100) > 65535) {
                throw new BusinessException('Adresse réseau invalide (ex. 192.168.1.50 ou 192.168.1.50:9100).');
            }
            if (!self::isLocalHost($m[1])) {
                throw new BusinessException('L\'imprimante doit être sur le réseau local (adresse 192.168.x.x, 10.x.x.x, 172.16-31.x.x ou nom local).');
            }
            return strtolower($m[1]) . ':' . (int) ($m[2] ?? 9100);
        }
        if ($driver === 'windows') {
            // Uniquement un partage d'imprimante : \\ordinateur\partage
            if (!preg_match('#^\\\\\\\\[A-Za-z0-9._-]+\\\\[A-Za-z0-9 ._-]+$#', $address)) {
                throw new BusinessException('Partage Windows invalide (ex. \\\\localhost\\XP-80). Partagez l\'imprimante dans Windows puis indiquez son nom de partage.');
            }
            return $address;
        }
        return '';
    }

    /** Adresse IP privée / locale, ou nom d'hôte local (sans domaine, .local, .lan, .home). */
    private static function isLocalHost(string $host): bool
    {
        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '127.') || str_starts_with($host, '169.254.')
                || !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
        }
        return $host === 'localhost' || !str_contains($host, '.') || (bool) preg_match('/\.(local|lan|home|internal)$/', $host);
    }

    /** Envoie des octets ESC/POS à une imprimante. */
    public static function send(array $printer, string $bytes): void
    {
        $copies = max(1, min(5, (int) $printer['copies']));
        $payload = str_repeat($bytes, $copies);
        if ($printer['driver'] === 'network') {
            [$host, $port] = explode(':', $printer['address']) + [1 => 9100];
            $fp = @fsockopen($host, (int) $port, $errno, $errstr, 3);
            if (!$fp) {
                throw new BusinessException("Imprimante « {$printer['name']} » injoignable ({$host}:{$port}) : $errstr");
            }
            stream_set_timeout($fp, 5);
            $ok = fwrite($fp, $payload);
            fclose($fp);
            if ($ok === false || $ok < strlen($payload)) {
                throw new BusinessException("Envoi incomplet vers « {$printer['name']} ».");
            }
            return;
        }
        if ($printer['driver'] === 'windows') {
            $fp = @fopen($printer['address'], 'wb');
            if (!$fp) {
                throw new BusinessException("Imprimante partagée « {$printer['name']} » inaccessible ({$printer['address']}).");
            }
            fwrite($fp, $payload);
            fclose($fp);
            return;
        }
        throw new BusinessException('Cette imprimante utilise le navigateur.');
    }

    /**
     * Imprime sur toutes les imprimantes serveur d'un usage.
     * @return array{printed:int, errors:string[]}
     */
    public static function dispatch(string $usage, ?int $registerId, callable $build): array
    {
        $printed = 0;
        $errors = [];
        foreach (self::targets($usage, $registerId) as $p) {
            try {
                self::send($p, $build($p));
                $printed++;
            } catch (\Throwable $e) {
                $errors[] = $e instanceof BusinessException ? $e->getMessage() : "Erreur d'impression sur « {$p['name']} ».";
                Logger::warning('Impression : ' . $e->getMessage(), ['printer' => $p['id']]);
            }
        }
        return ['printed' => $printed, 'errors' => $errors];
    }

    // ------------------------------------------------------------------ documents

    public static function header(EscPos $d): void
    {
        $d->align('center')->bold()->size(2, 2)->wrap(mb_strtoupper((string) setting('company_name')), (int) ($d->cols / 2))->size()->bold(false);
        foreach (['company_address', 'company_phone'] as $k) {
            if ($v = setting($k)) {
                $d->wrap(($k === 'company_phone' ? 'Tel : ' : '') . $v);
            }
        }
        if ($nif = setting('company_nif')) {
            $d->line('NIF : ' . $nif . (setting('company_rccm') ? ' - RCCM : ' . setting('company_rccm') : ''));
        }
        $d->align('left');
    }

    public static function receipt(int $saleId, array $printer, bool $duplicate = false): string
    {
        $s = DB::one('SELECT s.*, u.full_name AS cashier, c.name AS customer, r.name AS register FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN customers c ON c.id = s.customer_id LEFT JOIN cash_registers r ON r.id = s.register_id WHERE s.id = ?', [$saleId]);
        $items = DB::all('SELECT * FROM sale_items WHERE sale_id = ?', [$saleId]);
        $payments = DB::all('SELECT * FROM sale_payments WHERE sale_id = ?', [$saleId]);
        $d = new EscPos($printer['paper']);
        self::header($d);
        $d->sep();
        if ($duplicate) {
            $d->align('center')->bold()->line('*** DUPLICATA ***')->bold(false)->align('left');
        }
        $d->line(t('Ticket') . ' : ' . $s['number'])->line(fdate($s['created_at']) . ' - ' . ($s['register'] ?? ''))->line(t('Caissier') . ' : ' . $s['cashier']);
        if ($s['customer']) {
            $d->line(t('Client') . ' : ' . $s['customer']);
        }
        $d->sep();
        foreach ($items as $i) {
            $d->wrap($i['product_name']);
            $d->pair('  ' . qty($i['quantity']) . ' x ' . money($i['unit_price'], false) . ((float) $i['discount'] > 0 ? ' -' . money($i['discount'], false) : ''), money($i['line_total'], false));
        }
        $d->sep();
        if ((float) $s['discount_total'] > 0) {
            $d->pair(t('Sous-total'), money($s['subtotal']))->pair(t('Remise'), '-' . money($s['discount_total']));
        }
        $d->bold()->size(1, 2)->pair('TOTAL', money($s['total']))->size()->bold(false);
        foreach (Documents::vatBreakdown($items) as $v) {
            $d->pair(t('dont') . ' ' . Tax::rateLabel($v['rate']), money($v['tax']));
        }
        foreach ($payments as $p) {
            $d->pair(method_label($p['method']) . ($p['provider'] ? ' ' . $p['provider'] : ''), money($p['amount']));
        }
        $d->pair(t('Montant remis'), money($s['tendered']))->bold()->pair(t('Monnaie rendue'), money($s['change_due']))->bold(false);
        if ((float) $s['credit_amount'] > 0) {
            $d->pair(t('Reste dû (crédit)'), money($s['credit_amount']));
        }
        $d->sep()->align('center')->wrap((string) setting('ticket_footer', ''));
        if (setting('receipt_qr', '1') === '1') {
            $d->feed(1)->qr(DocumentRef::saleQr($s), $printer['paper'] === '58' ? 4 : 5)->line($s['number']);
        }
        $d->align('left')->cut();
        if ((int) $printer['open_drawer'] === 1 && !$duplicate) {
            $d->drawer();
        }
        return $d->bytes();
    }

    public static function kitchenTicket(int $ticketId, array $printer): string
    {
        $t = DB::one('SELECT kt.*, o.number AS order_number, o.type AS order_type, o.covers, o.customer_name, o.note AS order_note, tb.name AS table_name, u.full_name AS waiter
                FROM kitchen_tickets kt JOIN restaurant_orders o ON o.id = kt.order_id LEFT JOIN restaurant_tables tb ON tb.id = o.table_id JOIN users u ON u.id = kt.user_id WHERE kt.id = ?', [$ticketId]);
        $items = $t['type'] === 'cancel'
            ? DB::all('SELECT i.name, c.quantity, i.note, i.course, i.cancel_reason FROM kitchen_ticket_cancellations c JOIN restaurant_order_items i ON i.id = c.order_item_id WHERE c.kitchen_ticket_id = ?', [$ticketId])
            : DB::all('SELECT i.name, i.quantity, i.note, i.course FROM restaurant_order_items i WHERE i.kitchen_ticket_id = ? ORDER BY i.course, i.id', [$ticketId]);
        $d = new EscPos($printer['paper']);
        $d->align('center')->bold()->line($t['type'] === 'cancel' ? '*** ' . t('ANNULATION') . ' ***' : t('BON') . ' ' . mb_strtoupper($t['station']));
        $d->size(2, 2)->line($t['table_name'] ? t('TABLE') . ' ' . $t['table_name'] : ($t['order_type'] === 'delivery' ? t('LIVRAISON') : t('À EMPORTER')))->size()->bold(false)->align('left');
        $d->sep()->line($t['number'] . ' - ' . $t['order_number'] . ' - ' . date('H:i', strtotime($t['created_at'])))->line(t('Serveur') . ' : ' . $t['waiter'] . ' - ' . $t['covers'] . ' couv.')->sep();
        $last = null;
        foreach ($items as $i) {
            if ($t['type'] === 'order' && $i['course'] !== $last) {
                $last = $i['course'];
                $d->bold()->line('-- ' . mb_strtoupper(t(RestaurantService::COURSES[(int) $i['course']] ?? '')) . ' --')->bold(false);
            }
            $d->bold()->size(1, 2)->wrap(qty($i['quantity']) . ' x ' . $i['name'])->size()->bold(false);
            if (!empty($i['note'])) {
                $d->wrap('  >> ' . $i['note']);
            }
            if (!empty($i['cancel_reason'])) {
                $d->wrap('  ' . t('Motif') . ' : ' . $i['cancel_reason']);
            }
        }
        if ($t['order_note']) {
            $d->sep()->wrap(t('Note') . ' : ' . $t['order_note']);
        }
        return $d->cut()->bytes();
    }

    public static function testPage(array $printer): string
    {
        $d = new EscPos($printer['paper']);
        self::header($d);
        $d->sep()->align('center')->bold()->line('TEST D\'IMPRESSION')->bold(false)->line($printer['name'])->line(date('d/m/Y H:i'))
            ->line('Accents : é è à ç ô ù - ' . money(13500))->sep()->qr('POSWAN TEST ' . date('c'), 5)->align('left');
        return $d->cut()->bytes();
    }

    /** Ticket de caisse sur l'imprimante serveur de la caisse (si configurée). */
    public static function printReceipt(int $saleId, bool $duplicate = false): array
    {
        $reg = DB::value('SELECT register_id FROM sales WHERE id = ?', [$saleId]);
        $r = self::dispatch('receipt', $reg ? (int) $reg : null, fn($p) => self::receipt($saleId, $p, $duplicate));
        if ($r['printed'] > 0) {
            DB::run('UPDATE sales SET print_count = print_count + 1 WHERE id = ?', [$saleId]);
            if ($duplicate) {
                Audit::log('sale.reprint', 'sale', $saleId, ['server' => true]);
            }
        }
        return $r;
    }

    public static function printKitchenTicket(int $ticketId): array
    {
        $station = (string) DB::value('SELECT station FROM kitchen_tickets WHERE id = ?', [$ticketId]);
        $r = self::dispatch('kitchen:' . $station, null, fn($p) => self::kitchenTicket($ticketId, $p));
        if ($r['printed'] > 0) {
            DB::run('UPDATE kitchen_tickets SET print_count = print_count + 1 WHERE id = ?', [$ticketId]);
        }
        return $r;
    }
}
