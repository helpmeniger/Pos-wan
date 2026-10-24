<?php
declare(strict_types=1);

namespace Addons\Hotel;

use Addons\Hotel\HotelService as HS;
use App\Controllers\Controller;
use App\Core\DB;
use App\Core\Export;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;

final class HotelController extends Controller
{
    private function stay(int $id): array
    {
        $s = DB::one('SELECT s.*, g.last_name, g.first_name, g.phone, g.email, g.vip, g.blacklisted, g.id_type, g.id_number, g.nationality, r.number AS room_number, r.floor,
                t.name AS type_name, u.full_name AS user_name
            FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id LEFT JOIN hotel_rooms r ON r.id = s.room_id JOIN hotel_room_types t ON t.id = s.type_id JOIN users u ON u.id = s.user_id
            WHERE s.id = ?', [$id]);
        if (!$s) {
            throw new HttpException(404);
        }
        return $s;
    }

    private function payData(): array
    {
        return ['method' => Request::str('pay_method'), 'amount' => Request::str('pay_amount'), 'reference' => Request::str('pay_reference')];
    }

    private function back(int $id): string
    {
        return '/hotel/sejours/' . $id;
    }

    private static function changeMsg(float $change): string
    {
        return $change > 0 ? ' ' . t('Monnaie à rendre') . ' : ' . money($change) . '.' : '';
    }

    /** Tableau de bord : indicateurs du jour, arrivées, départs, clients présents. */
    public function index(): void
    {
        $today = date('Y-m-d');
        $board = HS::board();
        $q = "SELECT s.*, g.last_name, g.first_name, g.phone, g.vip, r.number AS room_number, t.name AS type_name FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id
              LEFT JOIN hotel_rooms r ON r.id = s.room_id JOIN hotel_room_types t ON t.id = s.type_id";
        view('hotel::index', [
            'title' => 'Hôtel', 'board' => $board, 'stats' => HS::stats($today), 'today' => $today,
            'arrivals' => DB::all("$q WHERE s.status IN ('reservee','confirmee') AND s.arrival <= ? AND s.departure > ? ORDER BY s.arrival, r.number", [$today, $today]),
            'departures' => DB::all("$q WHERE s.status = 'en_cours' AND s.departure <= ? ORDER BY s.departure, r.number", [$today]),
            'inHouse' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status = 'en_cours'"),
            'upcoming' => DB::all("$q WHERE s.status IN ('reservee','confirmee') AND s.arrival > ? ORDER BY s.arrival LIMIT 8", [$today]),
            'lastAudit' => (string) setting('hotel_dernier_audit', ''),
            'unposted' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status = 'en_cours' AND COALESCE(charged_until, arrival) < ?", [$today]),
        ]);
    }

    /** Plan des chambres plein écran (réception, second écran, tablette). */
    public function plan(): void
    {
        view('hotel::plan', ['u' => $this->user(), 'refresh' => 20, 'types' => DB::all('SELECT id, name FROM hotel_room_types WHERE active = 1 ORDER BY sort_order'),
            'can' => ['reception' => can('hotel.reception'), 'hk' => can('hotel.housekeeping'), 'manage' => can('hotel.manage')]], null);
    }

    public function planFeed(): void
    {
        header('Cache-Control: no-store');
        json_response(['ok' => true] + HS::board());
    }

    /** État du ménage (formulaire classique ou appel JSON depuis le plan / l'écran du ménage). */
    public function housekeeping(int $id): void
    {
        $to = (string) Request::input('state', '');
        if (Request::wantsJson()) {
            $this->jsonAttempt(function () use ($id, $to) {
                HS::setHousekeeping($id, $to, (string) Request::input('note', '') ?: null);
                return [];
            });
        }
        $this->attempt(fn() => HS::setHousekeeping($id, $to, Request::str('note') ?: null), '/hotel/menage');
        flash('success', t('État du ménage enregistré.'));
        redirect(Request::str('back') === 'room' ? '/hotel/chambres/' . $id : '/hotel/menage');
    }

    public function outOfOrder(int $id): void
    {
        $on = Request::input('on') === '1';
        $this->attempt(fn() => HS::setOutOfOrder($id, $on, Request::str('reason'), Request::str('until') ?: null), '/hotel/chambres/' . $id);
        flash('success', $on ? t('Chambre mise hors service.') : t('Chambre remise en service.'));
        redirect('/hotel/chambres/' . $id);
    }

    /** Fiche d'une chambre : état, séjour en cours, prochaines réservations, historique. */
    public function room(int $id): void
    {
        $r = DB::one('SELECT r.*, t.name AS type_name, t.base_price, t.weekend_price, t.capacity, t.amenities FROM hotel_rooms r JOIN hotel_room_types t ON t.id = r.type_id WHERE r.id = ?', [$id]);
        if (!$r) {
            throw new HttpException(404);
        }
        $q = 'SELECT s.*, g.last_name, g.first_name FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id WHERE s.room_id = ?';
        view('hotel::room', ['title' => 'Chambre ' . $r['number'], 'r' => $r,
            'current' => DB::one("$q AND s.status = 'en_cours'", [$id]),
            'next' => DB::all("$q AND s.status IN ('reservee','confirmee') AND s.departure > CURDATE() ORDER BY s.arrival LIMIT 10", [$id]),
            'past' => DB::all("$q AND s.status = 'terminee' ORDER BY s.checked_out_at DESC LIMIT 10", [$id]),
            'log' => DB::all('SELECT l.*, u.full_name FROM hotel_room_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.room_id = ? ORDER BY l.id DESC LIMIT 30', [$id])]);
    }

    /** Écran du ménage (téléphone de la gouvernante) : chambres à faire d'abord. */
    public function menage(): void
    {
        $board = HS::board();
        $order = ['sale' => 0, 'nettoyage' => 1, 'propre' => 2, 'inspectee' => 3];
        $rooms = $board['rooms'];
        usort($rooms, fn($a, $b) => [$order[$a['hk']], $a['state'] === 'arrivee' ? 0 : 1, $a['number']] <=> [$order[$b['hk']], $b['state'] === 'arrivee' ? 0 : 1, $b['number']]);
        view('hotel::menage', ['title' => 'Ménage des chambres', 'rooms' => $rooms, 'totals' => $board['totals'], 'scripts' => ['addons/hotel/hotel.js']]);
    }

    /** Planning : une ligne par chambre, une colonne par jour, les séjours en barres. */
    public function planning(): void
    {
        $start = (string) Request::query('d', '');
        if (!Validator::isDate($start)) {
            $start = date('Y-m-d', strtotime('-1 day'));
        }
        $days = max(7, min(31, (int) Request::query('n', 14)));
        $end = date('Y-m-d', strtotime($start . " +$days day"));
        $rooms = DB::all('SELECT r.*, t.name AS type_name, t.code AS type_code FROM hotel_rooms r JOIN hotel_room_types t ON t.id = r.type_id WHERE r.active = 1 ORDER BY r.sort_order, r.number');
        $stays = DB::all("SELECT s.id, s.number, s.room_id, s.arrival, s.status, s.adults, s.children,
                CASE WHEN s.status = 'en_cours' THEN GREATEST(s.departure, CURDATE() + INTERVAL 1 DAY) ELSE s.departure END AS departure, g.last_name, g.first_name, g.vip
            FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id
            WHERE s.status IN ('reservee','confirmee','en_cours','terminee') AND s.arrival < ? AND s.departure > ? ORDER BY s.arrival", [$end, $start]);
        $unassigned = DB::all("SELECT s.id, s.number, s.arrival, s.departure, s.status, g.last_name, g.first_name, t.name AS type_name FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id
            JOIN hotel_room_types t ON t.id = s.type_id WHERE s.status IN ('reservee','confirmee') AND s.room_id IS NULL AND s.departure > CURDATE() ORDER BY s.arrival LIMIT 30");
        view('hotel::planning', ['title' => 'Planning des chambres', 'rooms' => $rooms, 'stays' => $stays, 'start' => $start, 'days' => $days, 'unassigned' => $unassigned]);
    }

    /** Liste des réservations et séjours (filtres et recherche). */
    public function reservations(): void
    {
        $f = (string) Request::query('f', 'actifs');
        $q = trim((string) Request::query('q', ''));
        $today = date('Y-m-d');
        $where = match ($f) {
            'arrivees' => "s.status IN ('reservee','confirmee') AND s.arrival <= '$today' AND s.departure > '$today'",
            'avenir' => "s.status IN ('reservee','confirmee')",
            'en_cours' => "s.status = 'en_cours'",
            'departs' => "s.status = 'en_cours' AND s.departure <= '$today'",
            'terminee' => "s.status = 'terminee'",
            'soldes' => "s.status = 'terminee' AND s.total_charges - s.total_paid > 0.009",
            'annulees' => "s.status IN ('annulee','no_show')",
            'tous' => '1=1',
            default => "s.status IN ('reservee','confirmee','en_cours')",
        };
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where .= ' AND (s.number LIKE ? OR s.invoice_number LIKE ? OR g.last_name LIKE ? OR g.first_name LIKE ? OR g.phone LIKE ? OR g.id_number LIKE ? OR r.number = ? OR s.company LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $q, $like);
        }
        $p = paginate("FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id LEFT JOIN hotel_rooms r ON r.id = s.room_id JOIN hotel_room_types t ON t.id = s.type_id WHERE $where", $params,
            's.*, g.last_name, g.first_name, g.phone, g.vip, r.number AS room_number, t.name AS type_name',
            "FIELD(s.status, 'en_cours', 'confirmee', 'reservee', 'terminee', 'no_show', 'annulee'), " . (in_array($f, ['terminee', 'soldes', 'annulees', 'tous'], true) ? 's.id DESC' : 's.arrival, s.id'));
        $counts = [
            'arrivees' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status IN ('reservee','confirmee') AND arrival <= ? AND departure > ?", [$today, $today]),
            'en_cours' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status = 'en_cours'"),
            'departs' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status = 'en_cours' AND departure <= ?", [$today]),
            'avenir' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status IN ('reservee','confirmee')"),
            'soldes' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status = 'terminee' AND total_charges - total_paid > 0.009"),
        ];
        view('hotel::reservations', ['title' => 'Réservations et séjours', 'p' => $p, 'f' => $f, 'q' => $q, 'counts' => $counts, 'today' => $today]);
    }

    /** Formulaire de réservation ou d'arrivée directe (?walkin=1, ?room=, ?arrival=). */
    public function create(): void
    {
        $walkIn = Request::query('walkin') === '1';
        $a = (string) Request::query('arrival', '');
        $a = Validator::isDate($a) && $a >= date('Y-m-d') ? $a : date('Y-m-d');
        $room = (int) Request::query('room', 0);
        $guest = (int) Request::query('guest', 0);
        view('hotel::create', [
            'title' => $walkIn ? 'Arrivée directe (sans réservation)' : 'Nouvelle réservation', 'walkIn' => $walkIn,
            'arrival' => $walkIn ? date('Y-m-d') : $a, 'departure' => date('Y-m-d', strtotime(($walkIn ? date('Y-m-d') : $a) . ' +1 day')),
            'roomId' => $room, 'types' => DB::all('SELECT * FROM hotel_room_types WHERE active = 1 ORDER BY sort_order'),
            'guest' => $guest ? DB::one('SELECT * FROM hotel_guests WHERE id = ?', [$guest]) : null,
            'scripts' => ['addons/hotel/hotel.js'],
        ]);
    }

    private function guestFromForm(): array
    {
        $g = [];
        foreach (['last_name', 'first_name', 'sex', 'birth_date', 'birth_place', 'nationality', 'profession', 'address', 'phone', 'email', 'id_type', 'id_number', 'id_issued_on', 'id_issued_at', 'company', 'note'] as $k) {
            $g[$k] = Request::str('g_' . $k);
        }
        if (can('hotel.manage') && Request::input('g_form') === '1') {
            $g['vip'] = Request::input('g_vip') === '1';
            $g['blacklisted'] = Request::input('g_blacklisted') === '1';
        } elseif (Request::input('g_form') === '1') {
            $g['vip'] = Request::input('g_vip') === '1';
        }
        return $g;
    }

    public function store(): void
    {
        $walkIn = Request::input('walk_in') === '1';
        $guestId = Request::int('guest_id');
        $back = '/hotel/reservations/nouvelle' . ($walkIn ? '?walkin=1' : '');
        $r = $this->attempt(fn() => HS::createStay([
            'walk_in' => $walkIn, 'guest_id' => $guestId, 'guest' => $guestId && Request::str('g_last_name') === '' ? [] : $this->guestFromForm(),
            'type_id' => Request::int('type_id'), 'room_id' => Request::int('room_id'), 'arrival' => Request::str('arrival'), 'departure' => Request::str('departure'),
            'adults' => Request::int('adults', 1), 'children' => Request::int('children'), 'rate' => Request::str('rate'), 'source' => Request::str('source'),
            'purpose' => Request::str('purpose'), 'company' => Request::str('company'), 'coming_from' => Request::str('coming_from'), 'going_to' => Request::str('going_to'),
            'note' => Request::str('note'), 'confirmed' => Request::input('confirmed') === '1', 'deposit' => $this->payData(),
        ]), $back);
        flash('success', sprintf($walkIn ? t('Client installé : séjour %s.') : t('Réservation %s enregistrée.'), $r['number']) . self::changeMsg($r['change']));
        redirect($this->back($r['id']));
    }

    /** Chambres libres et devis pour des dates (formulaire de réservation, changement de chambre). */
    public function availability(): void
    {
        $a = (string) Request::query('arrival', '');
        $d = (string) Request::query('departure', '');
        if (!Validator::isDate($a) || !Validator::isDate($d) || $d <= $a) {
            json_response(['ok' => false, 'error' => t('Dates invalides.')], 422);
        }
        $except = Request::query('except') ? (int) Request::query('except') : null;
        $rooms = HS::availableRooms($a, $d, null, $except);
        $types = [];
        foreach (DB::all('SELECT id, name, capacity, base_price, weekend_price FROM hotel_room_types WHERE active = 1 ORDER BY sort_order') as $t) {
            $q = HS::quote((int) $t['id'], $a, $d);
            $types[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'capacity' => (int) $t['capacity'], 'rate' => (float) $t['base_price'], 'total' => $q['total'], 'nights' => $q['nights'],
                'free' => count(array_filter($rooms, fn($r) => (int) $r['type_id'] === (int) $t['id']))];
        }
        json_response(['ok' => true, 'nights' => HS::nights($a, $d), 'types' => $types,
            'rooms' => array_map(fn($r) => ['id' => (int) $r['id'], 'number' => $r['number'], 'floor' => $r['floor'], 'type_id' => (int) $r['type_id'], 'type' => $r['type_name'],
                'beds' => (string) $r['beds'], 'hk' => $r['housekeeping'], 'rate' => (float) $r['base_price']], $rooms)]);
    }

    public function guestSearch(): void
    {
        $q = trim((string) Request::query('q', ''));
        if (mb_strlen($q) < 2) {
            json_response(['ok' => true, 'guests' => []]);
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $digits = preg_replace('/\D/', '', $q);
        $rows = DB::all("SELECT g.*, (SELECT COUNT(*) FROM hotel_stays s WHERE s.guest_id = g.id AND s.status = 'terminee') AS stays FROM hotel_guests g
            WHERE g.last_name LIKE ? OR g.first_name LIKE ? OR CONCAT(g.last_name, ' ', COALESCE(g.first_name, '')) LIKE ? OR g.id_number LIKE ?" . (strlen((string) $digits) >= 4 ? " OR REPLACE(REPLACE(g.phone, ' ', ''), '+', '') LIKE ?" : '') . '
            ORDER BY g.last_name LIMIT 12', array_merge([$like, $like, $like, $like], strlen((string) $digits) >= 4 ? ['%' . $digits . '%'] : []));
        json_response(['ok' => true, 'guests' => array_map(fn($g) => array_intersect_key($g, array_flip(['id', 'last_name', 'first_name', 'sex', 'birth_date', 'birth_place', 'nationality', 'profession',
            'address', 'phone', 'email', 'id_type', 'id_number', 'id_issued_on', 'id_issued_at', 'company', 'vip', 'blacklisted', 'stays'])), $rows)]);
    }

    public function nightAudit(): void
    {
        $r = HS::nightAudit();
        flash('success', sprintf(t('Audit de nuit : %d nuitée(s) portée(s) au folio de %d séjour(s).'), $r['nights'], $r['stays']));
        redirect('/hotel');
    }

    /** Fiche du séjour : client, chambre, folio, paiements, actions. */
    public function show(int $id): void
    {
        $s = $this->stay($id);
        view('hotel::show', [
            'title' => 'Séjour ' . $s['number'], 's' => $s, 'bal' => HS::balance($s), 'guest' => DB::one('SELECT * FROM hotel_guests WHERE id = ?', [$s['guest_id']]),
            'charges' => DB::all('SELECT c.*, u.full_name FROM hotel_charges c JOIN users u ON u.id = c.user_id WHERE c.stay_id = ? ORDER BY c.service_date, c.id', [$id]),
            'payments' => DB::all('SELECT p.*, u.full_name FROM hotel_payments p JOIN users u ON u.id = p.user_id WHERE p.stay_id = ? ORDER BY p.id', [$id]),
            'log' => DB::all('SELECT l.*, u.full_name FROM hotel_stay_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.stay_id = ? ORDER BY l.id', [$id]),
            'extras' => DB::all('SELECT * FROM hotel_extras WHERE active = 1 ORDER BY sort_order, name'),
            'types' => DB::all('SELECT * FROM hotel_room_types WHERE active = 1 ORDER BY sort_order'),
            'allRooms' => DB::all('SELECT r.id, r.number, t.name AS type_name FROM hotel_rooms r JOIN hotel_room_types t ON t.id = r.type_id WHERE r.active = 1 ORDER BY r.sort_order, r.number'),
            'previous' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE guest_id = ? AND status = 'terminee' AND id <> ?", [$s['guest_id'], $id]),
            'scripts' => ['addons/hotel/hotel.js'],
        ]);
    }

    public function update(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::updateReservation($id, ['arrival' => Request::str('arrival'), 'departure' => Request::str('departure'), 'room_id' => Request::int('room_id'),
            'type_id' => Request::int('type_id'), 'adults' => Request::int('adults', 1), 'children' => Request::int('children'), 'rate' => Request::str('rate'), 'note' => Request::str('note')]), $this->back($id));
        flash('success', t('Réservation modifiée.'));
        redirect($this->back($id));
    }

    public function confirm(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::confirm($id), $this->back($id));
        flash('success', t('Réservation confirmée.'));
        redirect($this->back($id));
    }

    public function checkIn(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::checkIn($id, Request::int('room_id') ?: null, Request::input('force') === '1'), $this->back($id));
        flash('success', t('Arrivée enregistrée : le client est installé.'));
        redirect($this->back($id));
    }

    public function charge(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::addCharge($id, ['extra_id' => Request::int('extra_id'), 'kind' => Request::str('kind') ?: 'autre', 'label' => Request::str('label'),
            'quantity' => Request::str('quantity') ?: '1', 'unit_price' => Request::str('unit_price'), 'service_date' => Request::str('service_date')]), $this->back($id));
        flash('success', t('Prestation ajoutée au folio.'));
        redirect($this->back($id));
    }

    public function cancelCharge(int $id): void
    {
        $stay = (int) DB::value('SELECT stay_id FROM hotel_charges WHERE id = ?', [$id]);
        if (!$stay) {
            throw new HttpException(404);
        }
        $this->attempt(fn() => HS::cancelCharge($id, Request::str('reason')), $this->back($stay));
        flash('success', t('Ligne du folio annulée.'));
        redirect($this->back($stay));
    }

    public function pay(int $id): void
    {
        $s = $this->stay($id);
        $change = $this->attempt(fn() => $s['status'] === 'terminee' ? HS::settle($id, $this->payData()) : HS::pay($id, $this->payData()), $this->back($id));
        flash('success', t('Paiement enregistré.') . self::changeMsg($change));
        redirect($this->back($id));
    }

    public function refund(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::refund($id, Request::num('amount'), Request::str('reason')), $this->back($id));
        flash('success', t('Remboursement enregistré en caisse.'));
        redirect($this->back($id));
    }

    public function changeRoom(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::changeRoom($id, Request::int('room_id'), Request::input('keep_rate') === '1', Request::str('reason')), $this->back($id));
        flash('success', t('Chambre changée.'));
        redirect($this->back($id));
    }

    public function changeDeparture(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::changeDeparture($id, Request::str('departure')), $this->back($id));
        flash('success', t('Date de départ modifiée.'));
        redirect($this->back($id));
    }

    public function checkOut(int $id): void
    {
        $this->stay($id);
        $change = $this->attempt(fn() => HS::checkOut($id, $this->payData(), Request::input('late') === '1', Request::str('unpaid_reason') ?: null), $this->back($id));
        flash('success', t('Départ enregistré : la chambre passe « À nettoyer ».') . self::changeMsg($change));
        redirect($this->back($id) . '?facture=1');
    }

    public function cancel(int $id): void
    {
        $this->stay($id);
        $this->attempt(fn() => HS::cancel($id, Request::str('reason'), Request::input('keep_deposit') === '1', Request::input('no_show') === '1'), $this->back($id));
        flash('success', Request::input('no_show') === '1' ? t('Client noté « non présenté ».') : t('Réservation annulée.'));
        redirect($this->back($id));
    }

    public function guestUpdate(int $id): void
    {
        $s = $this->stay($id);
        $this->attempt(fn() => HS::saveGuest($this->guestFromForm(), (int) $s['guest_id']), $this->back($id));
        flash('success', t('Fiche du client enregistrée.'));
        redirect($this->back($id));
    }

    /** Facture A4 du séjour. */
    public function invoice(int $id): void
    {
        $s = $this->stay($id);
        view('hotel::invoice', ['title' => 'Facture ' . ($s['invoice_number'] ?: $s['number']), 's' => $s, 'bal' => HS::balance($s),
            'guest' => DB::one('SELECT * FROM hotel_guests WHERE id = ?', [$s['guest_id']]),
            'charges' => DB::all('SELECT * FROM hotel_charges WHERE stay_id = ? AND cancelled = 0 ORDER BY service_date, id', [$id]),
            'payments' => DB::all('SELECT * FROM hotel_payments WHERE stay_id = ? ORDER BY id', [$id]),
            'bodyClass' => 'a4', 'backUrl' => url($this->back($id)), 'autoPrint' => Request::query('auto') === '1'], 'layouts/print');
    }

    /** Fiche de police (fiche individuelle de voyageur) à faire signer à l'arrivée. */
    public function policeForm(int $id): void
    {
        $s = $this->stay($id);
        view('hotel::police', ['title' => 'Fiche de police ' . $s['number'], 's' => $s, 'g' => DB::one('SELECT * FROM hotel_guests WHERE id = ?', [$s['guest_id']]),
            'bodyClass' => 'a4', 'backUrl' => url($this->back($id))], 'layouts/print');
    }

    public function guests(): void
    {
        $q = trim((string) Request::query('q', ''));
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where = '(g.last_name LIKE ? OR g.first_name LIKE ? OR g.phone LIKE ? OR g.id_number LIKE ? OR g.company LIKE ?)';
            $params = [$like, $like, $like, $like, $like];
        }
        $p = paginate("FROM hotel_guests g WHERE $where", $params, "g.*, (SELECT COUNT(*) FROM hotel_stays s WHERE s.guest_id = g.id AND s.status IN ('en_cours','terminee')) AS stays,
            (SELECT COALESCE(SUM(DATEDIFF(s.departure, s.arrival)),0) FROM hotel_stays s WHERE s.guest_id = g.id AND s.status = 'terminee') AS nights,
            (SELECT COALESCE(SUM(s.total_charges),0) FROM hotel_stays s WHERE s.guest_id = g.id AND s.status IN ('en_cours','terminee')) AS spent,
            (SELECT MAX(s.arrival) FROM hotel_stays s WHERE s.guest_id = g.id AND s.status IN ('en_cours','terminee')) AS last_stay", 'g.last_name, g.first_name');
        view('hotel::guests', ['title' => 'Clients de l\'hôtel', 'p' => $p, 'q' => $q]);
    }

    public function guest(int $id): void
    {
        $g = DB::one('SELECT * FROM hotel_guests WHERE id = ?', [$id]);
        if (!$g) {
            throw new HttpException(404);
        }
        view('hotel::guest', ['title' => trim($g['last_name'] . ' ' . $g['first_name']), 'g' => $g,
            'stays' => DB::all('SELECT s.*, r.number AS room_number, t.name AS type_name FROM hotel_stays s LEFT JOIN hotel_rooms r ON r.id = s.room_id JOIN hotel_room_types t ON t.id = s.type_id WHERE s.guest_id = ? ORDER BY s.arrival DESC', [$id])]);
    }

    public function guestSave(int $id): void
    {
        $this->attempt(fn() => HS::saveGuest($this->guestFromForm(), $id), '/hotel/clients/' . $id);
        flash('success', t('Fiche du client enregistrée.'));
        redirect('/hotel/clients/' . $id);
    }

    /** Rapport : occupation, chiffre d'affaires, ADR, RevPAR, sources, encaissements ; export. */
    public function report(): void
    {
        [$from, $to] = date_range('first day of this month');
        $r = $this->attempt(fn() => HS::report($from, $to), '/hotel/rapport');
        if (Export::requested() && can('reports.export')) {
            $rows = [];
            foreach ($r['days'] as $d => $x) {
                $rows[] = [fdate($d, false), $x['occupied'], $r['rooms'] ? round($x['occupied'] * 100 / $r['rooms'], 1) . ' %' : '', $x['arrivals'], $x['departures'], $x['room'], $x['other'], $x['occupied'] ? round($x['room'] / $x['occupied']) : 0];
            }
            $t = $r['totals'];
            Export::download("hotel_{$from}_{$to}.csv", 'Rapport hôtel — occupation et chiffre d\'affaires', ['Date', 'Chambres occupées', 'Occupation', 'Arrivées', 'Départs', 'Nuitées (CA)', 'Autres prestations', 'Prix moyen'],
                $rows, ['subtitle' => Export::period($from, $to) . ' · ' . $r['rooms'] . ' chambre(s)', 'landscape' => true,
                    'footer' => ['TOTAL', $t['occupied'], $t['occupancy'] . ' %', $t['arrivals'], $t['departures'], $t['room'], $t['other'], $t['adr']]]);
        }
        view('hotel::report', ['title' => 'Rapport hôtel', 'from' => $from, 'to' => $to, 'r' => $r]);
    }

    /** Registre des voyageurs (main courante de police) ; export CSV / PDF. */
    public function register(): void
    {
        [$from, $to] = date_range('first day of this month');
        $rows = HS::policeRegister($from, $to);
        if (Export::requested() && can('reports.export')) {
            Export::download("registre_voyageurs_{$from}_{$to}.csv", 'Registre des voyageurs', ['Arrivée', 'Ch.', 'Nom', 'Prénom', 'Sexe', 'Né(e) le', 'à', 'Nationalité', 'Profession', 'Domicile', 'Pièce', 'N°', 'Délivrée le / à', 'Venant de', 'Allant à', 'Départ'],
                array_map(fn($x) => [fdate($x['checked_in_at']), $x['room'], $x['last_name'], $x['first_name'], $x['sex'], $x['birth_date'] ? fdate($x['birth_date'], false) : '', $x['birth_place'], $x['nationality'], $x['profession'], $x['address'],
                    HS::ID_TYPES[$x['id_type']] ?? '', $x['id_number'], trim(($x['id_issued_on'] ? fdate($x['id_issued_on'], false) : '') . ' ' . $x['id_issued_at']), $x['coming_from'], $x['going_to'],
                    $x['checked_out_at'] ? fdate($x['checked_out_at']) : fdate($x['departure'], false) . ' (prévu)'], $rows),
                ['subtitle' => Export::period($from, $to) . ' · ' . count($rows) . ' voyageur(s)', 'landscape' => true]);
        }
        view('hotel::register', ['title' => 'Registre des voyageurs', 'from' => $from, 'to' => $to, 'rows' => $rows]);
    }
}
