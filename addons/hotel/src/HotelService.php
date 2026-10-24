<?php
declare(strict_types=1);

namespace Addons\Hotel;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;
use App\Services\CashService;
use App\Services\Sequence;

/**
 * Règles métier de l'hôtel.
 *
 * Un « séjour » couvre tout le cycle : réservation → arrivée (séjour en cours) → départ.
 * Les nuitées sont portées au folio nuit par nuit (audit de nuit, changement de chambre, départ) :
 * le folio reste juste même si le tarif ou la chambre change en cours de séjour. Les montants
 * sont toujours recalculés côté serveur ; les paiements passent par la caisse ouverte
 * (mouvements « hotel »), comme les ventes, pour que la clôture de caisse reste juste.
 */
final class HotelService
{
    public const STATUSES = ['reservee' => 'Réservée', 'confirmee' => 'Confirmée', 'en_cours' => 'En séjour', 'terminee' => 'Partie', 'annulee' => 'Annulée', 'no_show' => 'Non présentée'];
    public const COLORS = ['reservee' => 'warning text-dark', 'confirmee' => 'info text-dark', 'en_cours' => 'primary', 'terminee' => 'secondary', 'annulee' => 'danger', 'no_show' => 'dark'];
    /** Statuts qui bloquent une chambre sur leurs dates. */
    public const HOLDING = ['reservee', 'confirmee', 'en_cours'];
    public const SOURCES = ['direct' => 'Comptoir (sans réservation)', 'telephone' => 'Téléphone', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'agence' => 'Agence de voyage', 'entreprise' => 'Entreprise / ONG', 'site' => 'Site Internet / plateforme', 'autre' => 'Autre'];
    public const PURPOSES = ['affaires' => 'Affaires / mission', 'tourisme' => 'Tourisme', 'famille' => 'Visite familiale', 'evenement' => 'Événement / séminaire', 'sante' => 'Santé', 'autre' => 'Autre'];
    public const ID_TYPES = ['cni' => 'Carte d\'identité', 'passeport' => 'Passeport', 'consulaire' => 'Carte consulaire', 'permis' => 'Permis de conduire', 'sejour' => 'Titre de séjour', 'autre' => 'Autre pièce'];
    public const HK = ['propre' => 'Propre', 'sale' => 'À nettoyer', 'nettoyage' => 'Nettoyage en cours', 'inspectee' => 'Inspectée (prête)'];
    public const KINDS = ['nuitee' => 'Nuitée', 'taxe' => 'Taxe de séjour', 'restauration' => 'Restauration', 'minibar' => 'Minibar', 'blanchisserie' => 'Blanchisserie', 'telephone' => 'Téléphone',
        'transport' => 'Transport', 'service' => 'Service', 'depart_tardif' => 'Départ tardif', 'annulation' => 'Frais d\'annulation', 'remise' => 'Remise', 'autre' => 'Autre'];

    private static function num(mixed $v): float
    {
        return round((float) str_replace([' ', ' ', ','], ['', '', '.'], (string) $v), 2);
    }

    private static function digits(string $s): string
    {
        return (string) preg_replace('/\D/', '', $s);
    }

    private static function today(): string
    {
        return date('Y-m-d');
    }

    private static function addDays(string $d, int $n): string
    {
        return date('Y-m-d', strtotime($d . ' ' . ($n >= 0 ? '+' : '') . $n . ' day'));
    }

    public static function nights(string $a, string $d): int
    {
        return max(0, (int) round((strtotime($d) - strtotime($a)) / 86400));
    }

    // ------------------------------------------------------------------ tarifs et disponibilité

    public static function type(int $id): array
    {
        $t = DB::one('SELECT * FROM hotel_room_types WHERE id = ?', [$id]);
        if (!$t) {
            throw new BusinessException('Type de chambre introuvable.');
        }
        return $t;
    }

    /** Prix de la nuit commençant le $date (tarif week-end les nuits du vendredi et du samedi). */
    public static function nightPrice(array $type, string $date): float
    {
        $dow = (int) date('N', strtotime($date));
        if (in_array($dow, [5, 6], true) && $type['weekend_price'] !== null && (float) $type['weekend_price'] > 0) {
            return (float) $type['weekend_price'];
        }
        return (float) $type['base_price'];
    }

    /** Prix de la nuit pour un séjour : tarif négocié fixe, sinon grille du type de chambre. */
    private static function stayNightPrice(array $s, array $type, string $date): float
    {
        return abs((float) $s['rate'] - (float) $s['list_rate']) >= 0.01 ? (float) $s['rate'] : self::nightPrice($type, $date);
    }

    /** Devis : total des nuitées d'un type entre deux dates. */
    public static function quote(int $typeId, string $arrival, string $departure): array
    {
        $t = self::type($typeId);
        $n = self::nights($arrival, $departure);
        $total = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $total += self::nightPrice($t, self::addDays($arrival, $i));
        }
        return ['nights' => $n, 'total' => round($total, 2), 'rate' => (float) $t['base_price'], 'weekend' => $t['weekend_price'] !== null ? (float) $t['weekend_price'] : null];
    }

    /**
     * Séjours qui occupent une chambre sur [$a ; $d[ (un séjour en cours dont la date de départ
     * est dépassée occupe toujours la chambre jusqu'à son départ effectif).
     */
    public static function conflicts(int $roomId, string $a, string $d, ?int $exceptStay = null): array
    {
        return DB::all("SELECT s.id, s.number, s.arrival, s.departure, s.status, g.last_name, g.first_name FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id
            WHERE s.room_id = ? AND s.status IN ('reservee','confirmee','en_cours') AND s.id <> ?
              AND s.arrival < ? AND (CASE WHEN s.status = 'en_cours' THEN GREATEST(s.departure, CURDATE() + INTERVAL 1 DAY) ELSE s.departure END) > ?",
            [$roomId, $exceptStay ?? 0, $d, $a]);
    }

    private static function outOfOrderOn(array $room, string $a): bool
    {
        return (int) $room['out_of_order'] === 1 && ($room['ooo_until'] === null || $room['ooo_until'] >= $a);
    }

    /** Chambres libres sur la période (hors service exclues), avec leur type et leur prix. */
    public static function availableRooms(string $a, string $d, ?int $typeId = null, ?int $exceptStay = null): array
    {
        $out = [];
        $sql = 'SELECT r.*, t.name AS type_name, t.base_price, t.weekend_price, t.capacity FROM hotel_rooms r JOIN hotel_room_types t ON t.id = r.type_id WHERE r.active = 1'
            . ($typeId ? ' AND r.type_id = ' . (int) $typeId : '') . ' ORDER BY r.sort_order, r.number';
        foreach (DB::all($sql) as $r) {
            if (!self::outOfOrderOn($r, $a) && !self::conflicts((int) $r['id'], $a, $d, $exceptStay)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function checkDates(string $a, string $d): void
    {
        if (!Validator::isDate($a) || !Validator::isDate($d)) {
            throw new BusinessException('Dates d\'arrivée et de départ obligatoires.');
        }
        if ($d <= $a) {
            throw new BusinessException('La date de départ doit être postérieure à la date d\'arrivée (au moins une nuit).');
        }
        if (self::nights($a, $d) > 365) {
            throw new BusinessException('Séjour trop long (365 nuits au plus) : faites plusieurs séjours.');
        }
    }

    private static function checkRoom(int $roomId, string $a, string $d, ?int $exceptStay = null): array
    {
        $r = DB::one('SELECT r.*, t.name AS type_name, t.capacity FROM hotel_rooms r JOIN hotel_room_types t ON t.id = r.type_id WHERE r.id = ?', [$roomId]);
        if (!$r || !(int) $r['active']) {
            throw new BusinessException('Chambre introuvable ou désactivée.');
        }
        if (self::outOfOrderOn($r, $a)) {
            throw new BusinessException('La chambre ' . $r['number'] . ' est hors service' . ($r['ooo_reason'] ? ' (' . $r['ooo_reason'] . ')' : '') . '.');
        }
        if ($c = self::conflicts($roomId, $a, $d, $exceptStay)) {
            $c = $c[0];
            throw new BusinessException('La chambre ' . $r['number'] . ' est déjà prise du ' . fdate($c['arrival'], false) . ' au ' . fdate($c['departure'], false) . ' (' . $c['number'] . ' — ' . trim($c['last_name'] . ' ' . $c['first_name']) . ').');
        }
        return $r;
    }

    // ------------------------------------------------------------------ clients

    /** Crée ou met à jour une fiche client ; retourne son identifiant. */
    public static function saveGuest(array $g, ?int $id = null): int
    {
        $last = mb_strtoupper(trim((string) ($g['last_name'] ?? '')));
        if ($last === '') {
            throw new BusinessException('Le nom du client est obligatoire.');
        }
        $phone = trim((string) ($g['phone'] ?? ''));
        if ($phone !== '' && strlen(self::digits($phone)) < 8) {
            throw new BusinessException('Numéro de téléphone invalide (8 chiffres au moins).');
        }
        $email = trim((string) ($g['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BusinessException('Adresse e-mail invalide.');
        }
        foreach (['birth_date', 'id_issued_on'] as $f) {
            if (($g[$f] ?? '') !== '' && !Validator::isDate((string) $g[$f])) {
                throw new BusinessException('Date invalide.');
            }
        }
        $idType = (string) ($g['id_type'] ?? '');
        $s = fn(string $k, int $n) => mb_substr(trim((string) ($g[$k] ?? '')), 0, $n) ?: null;
        $data = [
            'last_name' => mb_substr($last, 0, 80), 'first_name' => $s('first_name', 80), 'sex' => in_array($g['sex'] ?? '', ['M', 'F'], true) ? $g['sex'] : null,
            'birth_date' => ($g['birth_date'] ?? '') ?: null, 'birth_place' => $s('birth_place', 100), 'nationality' => $s('nationality', 60), 'profession' => $s('profession', 100),
            'address' => $s('address', 255), 'phone' => $phone !== '' ? mb_substr($phone, 0, 50) : null, 'email' => $email ?: null,
            'id_type' => isset(self::ID_TYPES[$idType]) ? $idType : null, 'id_number' => $s('id_number', 60), 'id_issued_on' => ($g['id_issued_on'] ?? '') ?: null,
            'id_issued_at' => $s('id_issued_at', 100), 'company' => $s('company', 150), 'note' => $s('note', 255),
        ];
        if (array_key_exists('vip', $g)) {
            $data['vip'] = !empty($g['vip']) ? 1 : 0;
        }
        if (array_key_exists('blacklisted', $g)) {
            if (!Auth::can('hotel.manage')) {
                unset($g['blacklisted']);
            } else {
                $data['blacklisted'] = !empty($g['blacklisted']) ? 1 : 0;
            }
        }
        if ($id) {
            if (!DB::value('SELECT id FROM hotel_guests WHERE id = ?', [$id])) {
                throw new BusinessException('Client introuvable.');
            }
            DB::update('hotel_guests', $data, 'id = ?', [$id]);
            Audit::log('hotel.guest_update', 'hotel_guest', $id);
            return $id;
        }
        // Évite les doublons : même pièce d'identité, ou même nom et même téléphone
        if ($data['id_number'] && ($dup = DB::value('SELECT id FROM hotel_guests WHERE id_number = ? LIMIT 1', [$data['id_number']]))) {
            DB::update('hotel_guests', array_filter($data, fn($v) => $v !== null), 'id = ?', [$dup]);
            return (int) $dup;
        }
        if ($phone !== '' && ($dup = DB::value("SELECT id FROM hotel_guests WHERE last_name = ? AND REPLACE(REPLACE(phone, ' ', ''), '+', '') LIKE ? LIMIT 1", [$data['last_name'], '%' . substr(self::digits($phone), -8)]))) {
            DB::update('hotel_guests', array_filter($data, fn($v) => $v !== null), 'id = ?', [$dup]);
            return (int) $dup;
        }
        $id = DB::insert('hotel_guests', $data);
        Audit::log('hotel.guest_create', 'hotel_guest', $id);
        return $id;
    }

    private static function guestReadyForCheckIn(int $guestId): void
    {
        if (setting('hotel_piece_obligatoire', '1') !== '1') {
            return;
        }
        $g = DB::one('SELECT id_type, id_number, nationality FROM hotel_guests WHERE id = ?', [$guestId]);
        if (!$g || !$g['id_type'] || !$g['id_number']) {
            throw new BusinessException('Pièce d\'identité du client obligatoire à l\'arrivée (type et numéro) : complétez la fiche du client.');
        }
    }

    // ------------------------------------------------------------------ réservations et séjours

    private static function log(int $stayId, string $event, ?string $note = null): void
    {
        DB::insert('hotel_stay_log', ['stay_id' => $stayId, 'event' => $event, 'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'user_id' => Auth::id()]);
    }

    private static function roomLog(int $roomId, string $event, ?string $from, ?string $to, ?string $note = null): void
    {
        DB::insert('hotel_room_log', ['room_id' => $roomId, 'event' => $event, 'from_state' => $from, 'to_state' => $to, 'note' => $note !== null ? mb_substr($note, 0, 255) : null, 'user_id' => Auth::id()]);
    }

    private static function lock(int $id): array
    {
        $s = DB::one('SELECT * FROM hotel_stays WHERE id = ? FOR UPDATE', [$id]);
        if (!$s) {
            throw new BusinessException('Séjour introuvable.');
        }
        return $s;
    }

    /** Tarif négocié : réservé au responsable (ou à la remise autorisée en caisse). */
    private static function rateFrom(array $d, float $list): float
    {
        if (!isset($d['rate']) || trim((string) $d['rate']) === '') {
            return $list;
        }
        $rate = self::num($d['rate']);
        if ($rate < 0) {
            throw new BusinessException('Tarif invalide.');
        }
        if (abs($rate - $list) >= 0.01 && !Auth::can('hotel.manage')) {
            throw new BusinessException('Modification du tarif réservée au responsable (droit « Hôtel : tarifs, remises, annulations »).');
        }
        return $rate;
    }

    /**
     * Nouvelle réservation, ou arrivée directe au comptoir ($d['walk_in']).
     * $d : guest_id | guest[...], type_id, room_id?, arrival, departure, adults, children, rate?, source, purpose, company,
     *      coming_from, going_to, note, deposit{method, amount, reference}
     * @return array{id:int, number:string, change:float}
     */
    public static function createStay(array $d): array
    {
        $walkIn = !empty($d['walk_in']);
        $a = (string) ($d['arrival'] ?? '');
        $dep = (string) ($d['departure'] ?? '');
        if ($walkIn) {
            $a = self::today();
        }
        self::checkDates($a, $dep);
        if (!$walkIn && $a < self::today()) {
            throw new BusinessException('La date d\'arrivée ne peut pas être passée.');
        }
        $roomId = (int) ($d['room_id'] ?? 0);
        $typeId = (int) ($d['type_id'] ?? 0);
        if ($roomId) {
            $typeId = (int) DB::value('SELECT type_id FROM hotel_rooms WHERE id = ?', [$roomId]);
        }
        if ($walkIn && !$roomId) {
            throw new BusinessException('Choisissez la chambre du client.');
        }
        $type = self::type($typeId);
        if (!(int) $type['active']) {
            throw new BusinessException('Ce type de chambre n\'est plus proposé.');
        }
        $adults = max(1, (int) ($d['adults'] ?? 1));
        $children = max(0, (int) ($d['children'] ?? 0));
        if ($adults > (int) $type['capacity'] && !Auth::can('hotel.manage')) {
            throw new BusinessException('Capacité de la chambre « ' . $type['name'] . ' » : ' . (int) $type['capacity'] . ' adulte(s).');
        }
        $source = isset(self::SOURCES[$d['source'] ?? '']) ? (string) $d['source'] : ($walkIn ? 'direct' : 'telephone');
        $purpose = isset(self::PURPOSES[$d['purpose'] ?? '']) ? (string) $d['purpose'] : null;
        $list = (float) $type['base_price'];
        $rate = self::rateFrom($d, $list);
        $pay = (array) ($d['deposit'] ?? []);

        return DB::transaction(function () use ($d, $walkIn, $a, $dep, $roomId, $typeId, $adults, $children, $source, $purpose, $rate, $list, $pay) {
            $guestId = (int) ($d['guest_id'] ?? 0);
            if ($guestId) {
                if (!DB::value('SELECT id FROM hotel_guests WHERE id = ?', [$guestId])) {
                    throw new BusinessException('Client introuvable.');
                }
                if (!empty($d['guest'])) {
                    self::saveGuest((array) $d['guest'], $guestId);
                }
            } else {
                $guestId = self::saveGuest((array) ($d['guest'] ?? []));
            }
            if ((int) DB::value('SELECT blacklisted FROM hotel_guests WHERE id = ?', [$guestId]) === 1 && !Auth::can('hotel.manage')) {
                throw new BusinessException('Ce client est sur la liste des clients refusés : décision du responsable nécessaire.');
            }
            if ($roomId) {
                self::checkRoom($roomId, $a, $dep);
            }
            if ($walkIn) {
                self::guestReadyForCheckIn($guestId);
                self::checkClean($roomId);
            }
            $number = Sequence::next('hotel_stay');
            $s = fn(string $k, int $n) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $n) ?: null;
            $id = DB::insert('hotel_stays', [
                'number' => $number, 'guest_id' => $guestId, 'room_id' => $roomId ?: null, 'type_id' => $typeId, 'status' => $walkIn ? 'en_cours' : (!empty($d['confirmed']) ? 'confirmee' : 'reservee'),
                'source' => $source, 'arrival' => $a, 'departure' => $dep, 'adults' => $adults, 'children' => $children, 'rate' => $rate, 'list_rate' => $list,
                'company' => $s('company', 150), 'purpose' => $purpose, 'coming_from' => $s('coming_from', 100), 'going_to' => $s('going_to', 100), 'note' => $s('note', 255),
                'charged_until' => $walkIn ? $a : null, 'checked_in_at' => $walkIn ? date('Y-m-d H:i:s') : null, 'user_id' => Auth::id(),
            ]);
            self::log($id, $walkIn ? 'arrivee' : 'reservation', $walkIn ? 'Arrivée directe, chambre ' . DB::value('SELECT number FROM hotel_rooms WHERE id = ?', [$roomId]) : self::nights($a, $dep) . ' nuit(s)');
            if ($walkIn) {
                self::roomLog($roomId, 'arrivee', null, null, $number);
            }
            $change = 0.0;
            if (self::num($pay['amount'] ?? 0) > 0) {
                $change = self::pay($id, $pay, $walkIn ? 'paiement' : 'acompte');
            }
            Audit::log($walkIn ? 'hotel.walk_in' : 'hotel.reservation', 'hotel_stay', $id, ['number' => $number, 'arrival' => $a, 'departure' => $dep, 'rate' => $rate]);
            return ['id' => $id, 'number' => $number, 'change' => $change];
        });
    }

    /** Modifie une réservation (dates, chambre, type, personnes, tarif). */
    public static function updateReservation(int $id, array $d): void
    {
        DB::transaction(function () use ($id, $d) {
            $s = self::lock($id);
            if (!in_array($s['status'], ['reservee', 'confirmee'], true)) {
                throw new BusinessException('Seule une réservation (avant l\'arrivée) peut être modifiée ainsi.');
            }
            $a = (string) ($d['arrival'] ?? $s['arrival']);
            $dep = (string) ($d['departure'] ?? $s['departure']);
            self::checkDates($a, $dep);
            if ($a < self::today() && $a !== $s['arrival']) {
                throw new BusinessException('La date d\'arrivée ne peut pas être passée.');
            }
            $roomId = (int) ($d['room_id'] ?? 0);
            $typeId = $roomId ? (int) DB::value('SELECT type_id FROM hotel_rooms WHERE id = ?', [$roomId]) : (int) ($d['type_id'] ?? $s['type_id']);
            $type = self::type($typeId);
            if ($roomId) {
                self::checkRoom($roomId, $a, $dep, $id);
            }
            $list = (float) $type['base_price'];
            $negotiated = abs((float) $s['rate'] - (float) $s['list_rate']) >= 0.01;
            if (trim((string) ($d['rate'] ?? '')) !== '' && !($negotiated && abs(self::num($d['rate']) - (float) $s['rate']) < 0.01)) {
                $rate = self::rateFrom($d, $list);
            } else {
                $rate = $negotiated ? (float) $s['rate'] : $list; // tarif négocié conservé, sinon grille du type
            }
            DB::update('hotel_stays', ['arrival' => $a, 'departure' => $dep, 'room_id' => $roomId ?: null, 'type_id' => $typeId, 'rate' => $rate, 'list_rate' => $list,
                'adults' => max(1, (int) ($d['adults'] ?? $s['adults'])), 'children' => max(0, (int) ($d['children'] ?? $s['children'])),
                'note' => array_key_exists('note', $d) ? (mb_substr(trim((string) $d['note']), 0, 255) ?: null) : $s['note']], 'id = ?', [$id]);
            self::log($id, 'modification', fdate($a, false) . ' → ' . fdate($dep, false) . ($roomId ? ', chambre ' . DB::value('SELECT number FROM hotel_rooms WHERE id = ?', [$roomId]) : ', chambre à attribuer'));
            Audit::log('hotel.reservation_update', 'hotel_stay', $id, ['arrival' => $a, 'departure' => $dep, 'room' => $roomId]);
        });
    }

    public static function confirm(int $id): void
    {
        DB::transaction(function () use ($id) {
            $s = self::lock($id);
            if ($s['status'] !== 'reservee') {
                throw new BusinessException('Seule une réservation non confirmée peut être confirmée.');
            }
            DB::update('hotel_stays', ['status' => 'confirmee'], 'id = ?', [$id]);
            self::log($id, 'confirmation');
        });
    }

    /** Une chambre à nettoyer ne peut accueillir un client que sur décision du responsable. */
    private static function checkClean(int $roomId, bool $force = false): void
    {
        $hk = (string) DB::value('SELECT housekeeping FROM hotel_rooms WHERE id = ?', [$roomId]);
        if (in_array($hk, ['sale', 'nettoyage'], true) && setting('hotel_exiger_propre', '1') === '1' && !($force && Auth::can('hotel.manage'))) {
            throw new BusinessException('La chambre ' . DB::value('SELECT number FROM hotel_rooms WHERE id = ?', [$roomId]) . ' n\'est pas encore propre (' . self::HK[$hk] . ').'
                . (Auth::can('hotel.manage') ? ' Cochez « Installer quand même » pour passer outre.' : ' Attendez le ménage ou choisissez une autre chambre.'));
        }
    }

    /**
     * Arrivée du client : la chambre est attribuée (ou changée) et doit être libre et propre.
     * Une arrivée anticipée ou tardive ramène la date d'arrivée à aujourd'hui.
     */
    public static function checkIn(int $id, ?int $roomId = null, bool $force = false): void
    {
        DB::transaction(function () use ($id, $roomId, $force) {
            $s = self::lock($id);
            if (!in_array($s['status'], ['reservee', 'confirmee'], true)) {
                throw new BusinessException('Arrivée impossible (statut : ' . self::STATUSES[$s['status']] . ').');
            }
            $today = self::today();
            if ($s['departure'] <= $today) {
                throw new BusinessException('La date de départ prévue est déjà passée : modifiez d\'abord les dates de la réservation.');
            }
            $roomId = $roomId ?: (int) $s['room_id'];
            if (!$roomId) {
                throw new BusinessException('Attribuez une chambre au client.');
            }
            self::guestReadyForCheckIn((int) $s['guest_id']);
            $r = self::checkRoom($roomId, $today, $s['departure'], $id);
            self::checkClean($roomId, $force);
            $upd = ['status' => 'en_cours', 'room_id' => $roomId, 'arrival' => $today, 'charged_until' => $today, 'checked_in_at' => date('Y-m-d H:i:s')];
            if ((int) $r['type_id'] !== (int) $s['type_id']) {
                // Surclassement : le tarif de la réservation est conservé
                $upd['type_id'] = (int) $r['type_id'];
            }
            DB::update('hotel_stays', $upd, 'id = ?', [$id]);
            self::log($id, 'arrivee', 'Chambre ' . $r['number'] . ($s['arrival'] !== $today ? ' (arrivée prévue le ' . fdate($s['arrival'], false) . ')' : ''));
            self::roomLog($roomId, 'arrivee', null, null, $s['number']);
            Audit::log('hotel.check_in', 'hotel_stay', $id, ['room' => $r['number']]);
        });
    }

    /**
     * Porte au folio les nuitées (et la taxe de séjour) non encore facturées jusqu'à $upTo (exclu).
     * Appelé par l'audit de nuit, le changement de chambre et le départ.
     */
    public static function postNights(int $id, string $upTo): int
    {
        return DB::transaction(function () use ($id, $upTo) {
            $s = self::lock($id);
            if ($s['status'] !== 'en_cours') {
                return 0;
            }
            $from = $s['charged_until'] ?: $s['arrival'];
            $type = self::type((int) $s['type_id']);
            $room = (string) DB::value('SELECT number FROM hotel_rooms WHERE id = ?', [$s['room_id']]);
            $tax = max(0.0, (float) setting('hotel_taxe_sejour', '0'));
            $n = 0;
            for ($day = $from; $day < $upTo; $day = self::addDays($day, 1)) {
                $price = self::stayNightPrice($s, $type, $day);
                DB::insert('hotel_charges', ['stay_id' => $id, 'kind' => 'nuitee', 'label' => 'Nuitée du ' . fdate($day, false) . ' — chambre ' . $room . ' (' . $type['name'] . ')',
                    'service_date' => $day, 'quantity' => 1, 'unit_price' => $price, 'amount' => $price, 'room_id' => $s['room_id'], 'user_id' => Auth::id()]);
                if ($tax > 0) {
                    DB::insert('hotel_charges', ['stay_id' => $id, 'kind' => 'taxe', 'label' => 'Taxe de séjour du ' . fdate($day, false) . ' (' . (int) $s['adults'] . ' adulte(s))',
                        'service_date' => $day, 'quantity' => (int) $s['adults'], 'unit_price' => $tax, 'amount' => round($tax * (int) $s['adults'], 2), 'room_id' => $s['room_id'], 'user_id' => Auth::id()]);
                }
                $n++;
            }
            if ($n > 0) {
                DB::update('hotel_stays', ['charged_until' => $upTo], 'id = ?', [$id]);
                self::refreshTotals($id);
            }
            return $n;
        });
    }

    /** Audit de nuit : nuitées portées au folio de tous les séjours en cours jusqu'à aujourd'hui. */
    public static function nightAudit(): array
    {
        $stays = 0;
        $nights = 0;
        foreach (DB::all("SELECT id FROM hotel_stays WHERE status = 'en_cours' ORDER BY id") as $s) {
            $n = self::postNights((int) $s['id'], self::today());
            if ($n > 0) {
                $stays++;
                $nights += $n;
            }
        }
        setting_set('hotel_dernier_audit', date('Y-m-d H:i'));
        Audit::log('hotel.night_audit', null, null, ['stays' => $stays, 'nights' => $nights]);
        return ['stays' => $stays, 'nights' => $nights];
    }

    private static function refreshTotals(int $id): void
    {
        DB::run('UPDATE hotel_stays SET total_charges = (SELECT COALESCE(SUM(amount),0) FROM hotel_charges WHERE stay_id = ? AND cancelled = 0),
                 total_paid = (SELECT COALESCE(SUM(amount),0) FROM hotel_payments WHERE stay_id = ?) WHERE id = ?', [$id, $id, $id]);
    }

    /** Nuitées pas encore portées au folio jusqu'au départ prévu (ou aujourd'hui si dépassé). */
    public static function pendingNights(array $s): array
    {
        if (!in_array($s['status'], self::HOLDING, true)) {
            return ['nights' => 0, 'amount' => 0.0];
        }
        $from = $s['status'] === 'en_cours' ? ($s['charged_until'] ?: $s['arrival']) : $s['arrival'];
        $to = $s['status'] === 'en_cours' ? max($s['departure'], self::today()) : $s['departure'];
        $type = self::type((int) $s['type_id']);
        $tax = max(0.0, (float) setting('hotel_taxe_sejour', '0')) * (int) $s['adults'];
        $n = 0;
        $amount = 0.0;
        for ($day = $from; $day < $to; $day = self::addDays($day, 1)) {
            $amount += self::stayNightPrice($s, $type, $day) + $tax;
            $n++;
        }
        return ['nights' => $n, 'amount' => round($amount, 2)];
    }

    /** Solde : portés au folio − payés ; estimé : avec les nuitées encore à porter. */
    public static function balance(array $s): array
    {
        $pending = self::pendingNights($s);
        $posted = round((float) $s['total_charges'], 2);
        $paid = round((float) $s['total_paid'], 2);
        return ['posted' => $posted, 'paid' => $paid, 'due' => round($posted - $paid, 2), 'pending' => $pending, 'estimated' => round($posted + $pending['amount'], 2), 'estimated_due' => round($posted + $pending['amount'] - $paid, 2)];
    }

    /**
     * Prestation au folio (catalogue ou libellé libre). Une remise est négative et réservée
     * au responsable ou au droit de remise de la caisse.
     */
    public static function addCharge(int $id, array $c): int
    {
        return DB::transaction(function () use ($id, $c) {
            $s = self::lock($id);
            if ($s['status'] !== 'en_cours' && !($s['status'] === 'terminee' && Auth::can('hotel.manage'))) {
                throw new BusinessException('Les prestations se portent sur un séjour en cours.');
            }
            $qty = self::num($c['quantity'] ?? 1);
            if ($qty <= 0 || $qty > 1000) {
                throw new BusinessException('Quantité invalide.');
            }
            $extraId = (int) ($c['extra_id'] ?? 0);
            $kind = (string) ($c['kind'] ?? 'autre');
            $label = trim((string) ($c['label'] ?? ''));
            $price = trim((string) ($c['unit_price'] ?? '')) === '' ? null : self::num($c['unit_price']);
            if ($extraId) {
                $x = DB::one('SELECT * FROM hotel_extras WHERE id = ? AND active = 1', [$extraId]);
                if (!$x) {
                    throw new BusinessException('Prestation introuvable.');
                }
                $kind = $x['kind'];
                $label = $label ?: $x['name'];
                if ($price !== null && abs($price - (float) $x['price']) >= 0.01 && !Auth::can('hotel.manage')) {
                    throw new BusinessException('Modification du prix réservée au responsable.');
                }
                $price ??= (float) $x['price'];
            }
            if (!isset(self::KINDS[$kind]) || in_array($kind, ['nuitee', 'taxe'], true)) {
                throw new BusinessException('Type de prestation invalide (les nuitées sont portées automatiquement).');
            }
            if ($label === '' || $price === null) {
                throw new BusinessException('Libellé et prix obligatoires.');
            }
            if ($kind === 'remise') {
                if (!Auth::can('hotel.manage') && !Auth::can('pos.discount')) {
                    throw new BusinessException('Vous n\'êtes pas autorisé à accorder des remises.');
                }
                $price = -abs($price);
                if (abs($price * $qty) > (float) $s['total_charges'] + self::pendingNights($s)['amount'] + 0.001) {
                    throw new BusinessException('Remise supérieure au montant du séjour.');
                }
            } elseif ($price < 0) {
                throw new BusinessException('Prix invalide.');
            }
            $date = (string) ($c['service_date'] ?? '') ?: self::today();
            if (!Validator::isDate($date) || $date > self::today()) {
                throw new BusinessException('Date de la prestation invalide.');
            }
            $cid = DB::insert('hotel_charges', ['stay_id' => $id, 'kind' => $kind, 'label' => mb_substr($label, 0, 150), 'service_date' => $date, 'quantity' => $qty, 'unit_price' => $price,
                'amount' => round($price * $qty, 2), 'room_id' => $s['room_id'], 'extra_id' => $extraId ?: null, 'user_id' => Auth::id()]);
            self::refreshTotals($id);
            Audit::log('hotel.charge', 'hotel_stay', $id, ['label' => $label, 'amount' => round($price * $qty, 2)]);
            return $cid;
        });
    }

    public static function cancelCharge(int $chargeId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        DB::transaction(function () use ($chargeId, $reason) {
            $c = DB::one('SELECT * FROM hotel_charges WHERE id = ? FOR UPDATE', [$chargeId]);
            if (!$c || (int) $c['cancelled']) {
                throw new BusinessException('Ligne introuvable ou déjà annulée.');
            }
            $s = self::lock((int) $c['stay_id']);
            if (!in_array($s['status'], ['en_cours', 'terminee'], true)) {
                throw new BusinessException('Folio clôturé.');
            }
            if ($c['kind'] === 'nuitee' && $s['status'] === 'en_cours') {
                throw new BusinessException('Une nuitée ne s\'annule pas : accordez une remise, ou corrigez le tarif.');
            }
            DB::update('hotel_charges', ['cancelled' => 1, 'cancel_reason' => mb_substr(trim($reason), 0, 255)], 'id = ?', [$chargeId]);
            self::refreshTotals((int) $c['stay_id']);
            self::log((int) $c['stay_id'], 'annulation_ligne', $c['label'] . ' (' . money($c['amount']) . ') : ' . trim($reason));
            Audit::log('hotel.charge_cancel', 'hotel_stay', (int) $c['stay_id'], ['charge' => $chargeId, 'reason' => $reason]);
        });
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

    private static function recordPayment(array $s, string $kind, string $methodRaw, float $amount, ?string $reference): void
    {
        [$method, $provider] = array_pad(explode('|', $methodRaw ?: 'cash', 2), 2, null);
        if (!isset(payment_methods()[$method])) {
            throw new BusinessException('Mode de paiement non autorisé.');
        }
        $sessionId = self::session();
        $signed = $kind === 'remboursement' ? -$amount : $amount;
        DB::insert('hotel_payments', ['stay_id' => $s['id'], 'kind' => $kind, 'method' => $method, 'provider' => $method === 'mobile_money' ? mobile_money_provider($provider) : null,
            'amount' => $signed, 'reference' => mb_substr(trim((string) $reference), 0, 100) ?: null, 'cash_session_id' => $sessionId, 'user_id' => Auth::id()]);
        CashService::movement($sessionId, $kind === 'remboursement' ? 'hotel_refund' : 'hotel', $method, $signed, 'hotel_stay', (int) $s['id'], 'Hôtel ' . $s['number']);
        self::refreshTotals((int) $s['id']);
    }

    /**
     * Encaissement (acompte d'une réservation, paiement en cours de séjour ou au départ).
     * Le montant est limité au reste estimé du séjour ; en espèces, l'excédent est rendu.
     * @return float monnaie à rendre
     */
    public static function pay(int $id, array $pay, string $kind = 'paiement'): float
    {
        return DB::transaction(function () use ($id, $pay, $kind) {
            $s = self::lock($id);
            if (in_array($s['status'], ['annulee', 'no_show'], true)) {
                throw new BusinessException('Séjour annulé.');
            }
            $tendered = self::num($pay['amount'] ?? 0);
            if ($tendered <= 0) {
                throw new BusinessException('Montant invalide.');
            }
            $due = self::balance($s)['estimated_due'];
            if ($due <= 0.009) {
                throw new BusinessException('Rien à encaisser : le séjour est entièrement payé.');
            }
            $method = (string) ($pay['method'] ?? 'cash');
            if ($tendered > $due && explode('|', $method)[0] !== 'cash') {
                throw new BusinessException('Un paiement électronique ne peut pas dépasser le reste à payer (' . money($due) . ').');
            }
            $amount = min($tendered, $due);
            self::recordPayment($s, in_array($s['status'], ['reservee', 'confirmee'], true) ? 'acompte' : $kind, $method, $amount, $pay['reference'] ?? null);
            self::log($id, 'paiement', money($amount) . ' — ' . method_label(explode('|', $method)[0]));
            Audit::log('hotel.payment', 'hotel_stay', $id, ['amount' => $amount]);
            return round($tendered - $amount, 2);
        });
    }

    /** Remboursement d'un trop-perçu (espèces, responsable). */
    public static function refund(int $id, float $amount, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        DB::transaction(function () use ($id, $amount, $reason) {
            $s = self::lock($id);
            $amount = round($amount, 2);
            $credit = round((float) $s['total_paid'] - (float) $s['total_charges'] - (in_array($s['status'], self::HOLDING, true) ? self::pendingNights($s)['amount'] : 0), 2);
            if ($amount <= 0 || $amount > $credit + 0.001) {
                throw new BusinessException('Montant à rembourser invalide (trop-perçu : ' . money(max(0, $credit)) . ').');
            }
            self::recordPayment($s, 'remboursement', 'cash', $amount, mb_substr(trim($reason), 0, 100));
            self::log($id, 'remboursement', money($amount) . ' : ' . trim($reason));
            Audit::log('hotel.refund', 'hotel_stay', $id, ['amount' => $amount, 'reason' => $reason]);
        });
    }

    /** Changement de chambre (séjour en cours : nuitées passées facturées à l'ancienne chambre). */
    public static function changeRoom(int $id, int $roomId, bool $keepRate, string $reason = ''): void
    {
        DB::transaction(function () use ($id, $roomId, $keepRate, $reason) {
            $s = self::lock($id);
            if (!in_array($s['status'], self::HOLDING, true)) {
                throw new BusinessException('Changement impossible (statut : ' . self::STATUSES[$s['status']] . ').');
            }
            if ($roomId === (int) $s['room_id']) {
                throw new BusinessException('Le client est déjà dans cette chambre.');
            }
            $live = $s['status'] === 'en_cours';
            $from = $live ? self::today() : $s['arrival'];
            $end = $live ? max($s['departure'], self::addDays(self::today(), 1)) : $s['departure'];
            $r = self::checkRoom($roomId, $from, $end, $id);
            if ($live) {
                self::checkClean($roomId);
                self::postNights($id, self::today());
            }
            $type = self::type((int) $r['type_id']);
            $upd = ['room_id' => $roomId, 'type_id' => (int) $r['type_id']];
            if (!$keepRate) {
                $upd += ['rate' => (float) $type['base_price'], 'list_rate' => (float) $type['base_price']];
            } elseif ((int) $r['type_id'] !== (int) $s['type_id']) {
                $upd['list_rate'] = (float) $type['base_price']; // tarif d'origine conservé comme tarif fixe
            }
            DB::update('hotel_stays', $upd, 'id = ?', [$id]);
            $old = $s['room_id'] ? (string) DB::value('SELECT number FROM hotel_rooms WHERE id = ?', [$s['room_id']]) : '—';
            if ($live && $s['room_id']) {
                self::setHousekeeping((int) $s['room_id'], 'sale', 'Changement de chambre (' . $s['number'] . ')');
                self::roomLog((int) $s['room_id'], 'depart', null, null, $s['number'] . ' → chambre ' . $r['number']);
                self::roomLog($roomId, 'arrivee', null, null, $s['number'] . ' (venant de ' . $old . ')');
            }
            self::log($id, 'changement_chambre', $old . ' → ' . $r['number'] . ($keepRate ? ' (tarif conservé)' : ' (tarif ' . money($type['base_price']) . ')') . ($reason !== '' ? ' : ' . $reason : ''));
            Audit::log('hotel.room_change', 'hotel_stay', $id, ['from' => $old, 'to' => $r['number']]);
        });
    }

    /** Prolongation ou départ anticipé (séjour en cours). */
    public static function changeDeparture(int $id, string $departure): void
    {
        DB::transaction(function () use ($id, $departure) {
            $s = self::lock($id);
            if ($s['status'] !== 'en_cours') {
                throw new BusinessException('Utilisez « Modifier la réservation » avant l\'arrivée.');
            }
            if (!Validator::isDate($departure) || $departure <= self::today() || $departure <= $s['arrival']) {
                throw new BusinessException('Nouvelle date de départ invalide (au plus tôt demain).');
            }
            if ($departure > $s['departure']) {
                self::checkRoom((int) $s['room_id'], $s['departure'], $departure, $id);
            }
            DB::update('hotel_stays', ['departure' => $departure], 'id = ?', [$id]);
            self::log($id, $departure > $s['departure'] ? 'prolongation' : 'raccourcissement', 'Départ prévu le ' . fdate($departure, false) . ' (au lieu du ' . fdate($s['departure'], false) . ')');
            Audit::log('hotel.departure_change', 'hotel_stay', $id, ['from' => $s['departure'], 'to' => $departure]);
        });
    }

    /**
     * Départ : nuitées portées jusqu'à aujourd'hui (une nuit au moins), départ tardif facultatif,
     * paiement du solde. Un solde restant n'est accepté qu'avec le droit hotel.manage et un motif
     * (le séjour reste alors dans « Soldes à recouvrer »). La chambre passe « À nettoyer ».
     * @return float monnaie à rendre
     */
    public static function checkOut(int $id, array $pay = [], bool $lateCheckout = false, ?string $unpaidReason = null): float
    {
        return DB::transaction(function () use ($id, $pay, $lateCheckout, $unpaidReason) {
            $s = self::lock($id);
            if ($s['status'] !== 'en_cours') {
                throw new BusinessException('Départ impossible (statut : ' . self::STATUSES[$s['status']] . ').');
            }
            $upTo = max(self::today(), self::addDays($s['arrival'], 1));
            self::postNights($id, $upTo);
            if ($lateCheckout) {
                $pct = max(0.0, (float) setting('hotel_depart_tardif_pourcent', '50'));
                $amount = round((float) $s['rate'] * $pct / 100);
                if ($amount > 0) {
                    DB::insert('hotel_charges', ['stay_id' => $id, 'kind' => 'depart_tardif', 'label' => 'Départ tardif (' . rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',') . ' % de la nuitée)',
                        'service_date' => self::today(), 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount, 'room_id' => $s['room_id'], 'user_id' => Auth::id()]);
                    self::refreshTotals($id);
                }
            }
            // Le départ effectif remplace la date prévue avant l'encaissement : le reste dû ne compte
            // plus les nuits qui ne seront pas passées (annulé avec le reste en cas d'erreur)
            DB::update('hotel_stays', ['departure' => $upTo], 'id = ?', [$id]);
            $change = 0.0;
            if (self::num($pay['amount'] ?? 0) > 0) {
                $change = self::pay($id, $pay);
            }
            $s = self::lock($id);
            $due = round((float) $s['total_charges'] - (float) $s['total_paid'], 2);
            $note = null;
            if ($due > 0.009) {
                if (!Auth::can('hotel.manage') || trim((string) $unpaidReason) === '') {
                    throw new BusinessException('Reste à payer : ' . money($due) . '. Encaissez le solde avant le départ.');
                }
                $note = 'Départ avec un solde de ' . money($due) . ' : ' . mb_substr(trim((string) $unpaidReason), 0, 180);
            } elseif ($due < -0.009) {
                throw new BusinessException('Trop-perçu de ' . money(-$due) . ' : remboursez le client (ou ajoutez la prestation oubliée) avant le départ.');
            }
            DB::update('hotel_stays', ['status' => 'terminee', 'departure' => $upTo, 'checked_out_at' => date('Y-m-d H:i:s'), 'invoice_number' => $s['invoice_number'] ?: Sequence::next('hotel_invoice')], 'id = ?', [$id]);
            self::setHousekeeping((int) $s['room_id'], 'sale', 'Départ ' . $s['number']);
            self::roomLog((int) $s['room_id'], 'depart', null, null, $s['number']);
            self::log($id, 'depart', $note ?? 'Solde réglé');
            Audit::log('hotel.check_out', 'hotel_stay', $id, ['unpaid' => max(0, $due)]);
            return $change;
        });
    }

    /** Règlement d'un solde après le départ (client débiteur). */
    public static function settle(int $id, array $pay): float
    {
        $s = DB::one('SELECT status FROM hotel_stays WHERE id = ?', [$id]);
        if (!$s || $s['status'] !== 'terminee') {
            throw new BusinessException('Séjour introuvable ou pas encore terminé.');
        }
        return self::pay($id, $pay);
    }

    /**
     * Annulation d'une réservation (ou client non présenté). L'acompte est soit remboursé en espèces,
     * soit conservé comme frais d'annulation.
     */
    public static function cancel(int $id, string $reason, bool $keepDeposit, bool $noShow = false): void
    {
        if (trim($reason) === '') {
            throw new BusinessException('Le motif est obligatoire.');
        }
        DB::transaction(function () use ($id, $reason, $keepDeposit, $noShow) {
            $s = self::lock($id);
            if (!in_array($s['status'], ['reservee', 'confirmee'], true)) {
                throw new BusinessException('Seule une réservation (avant l\'arrivée) peut être annulée.');
            }
            if ($noShow && $s['arrival'] > self::today()) {
                throw new BusinessException('« Non présenté » seulement à partir du jour d\'arrivée prévu.');
            }
            $paid = round((float) $s['total_paid'], 2);
            if ($paid > 0) {
                if ($keepDeposit) {
                    DB::insert('hotel_charges', ['stay_id' => $id, 'kind' => 'annulation', 'label' => $noShow ? 'Non-présentation : acompte conservé' : 'Frais d\'annulation : acompte conservé',
                        'service_date' => self::today(), 'quantity' => 1, 'unit_price' => $paid, 'amount' => $paid, 'room_id' => $s['room_id'], 'user_id' => Auth::id()]);
                    self::refreshTotals($id);
                } else {
                    self::recordPayment($s, 'remboursement', 'cash', $paid, 'Annulation');
                }
            }
            DB::update('hotel_stays', ['status' => $noShow ? 'no_show' : 'annulee', 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr(trim($reason), 0, 255)], 'id = ?', [$id]);
            self::log($id, $noShow ? 'no_show' : 'annulation', trim($reason) . ($paid > 0 ? ($keepDeposit ? ' — acompte conservé (' . money($paid) . ')' : ' — acompte remboursé (' . money($paid) . ')') : ''));
            Audit::log($noShow ? 'hotel.no_show' : 'hotel.cancel', 'hotel_stay', $id, ['reason' => $reason, 'deposit' => $paid, 'kept' => $keepDeposit]);
        });
    }

    // ------------------------------------------------------------------ chambres, ménage, maintenance

    public static function setHousekeeping(int $roomId, string $to, ?string $note = null): void
    {
        if (!isset(self::HK[$to])) {
            throw new BusinessException('État de ménage inconnu.');
        }
        $r = DB::one('SELECT id, housekeeping FROM hotel_rooms WHERE id = ?', [$roomId]);
        if (!$r) {
            throw new BusinessException('Chambre introuvable.');
        }
        if ($to === 'inspectee' && !Auth::can('hotel.manage') && !Auth::can('hotel.reception')) {
            throw new BusinessException('L\'inspection est faite par la réception ou le responsable.');
        }
        if ($r['housekeeping'] === $to) {
            return;
        }
        DB::update('hotel_rooms', ['housekeeping' => $to], 'id = ?', [$roomId]);
        self::roomLog($roomId, 'menage', $r['housekeeping'], $to, $note);
    }

    public static function setOutOfOrder(int $roomId, bool $on, string $reason = '', ?string $until = null): void
    {
        DB::transaction(function () use ($roomId, $on, $reason, $until) {
            $r = DB::one('SELECT * FROM hotel_rooms WHERE id = ? FOR UPDATE', [$roomId]);
            if (!$r) {
                throw new BusinessException('Chambre introuvable.');
            }
            if ($on) {
                if (trim($reason) === '') {
                    throw new BusinessException('Indiquez la raison (panne, travaux…).');
                }
                if ($until !== null && $until !== '' && (!Validator::isDate($until) || $until < self::today())) {
                    throw new BusinessException('Date de remise en service invalide.');
                }
                if (DB::value("SELECT id FROM hotel_stays WHERE room_id = ? AND status = 'en_cours'", [$roomId])) {
                    throw new BusinessException('La chambre est occupée : changez d\'abord le client de chambre.');
                }
                $c = DB::all("SELECT number FROM hotel_stays WHERE room_id = ? AND status IN ('reservee','confirmee') AND departure > CURDATE()" . ($until ? ' AND arrival <= ?' : ''), $until ? [$roomId, $until] : [$roomId]);
                DB::update('hotel_rooms', ['out_of_order' => 1, 'ooo_reason' => mb_substr(trim($reason), 0, 255), 'ooo_until' => $until ?: null], 'id = ?', [$roomId]);
                self::roomLog($roomId, 'hors_service', null, null, trim($reason) . ($until ? ' (jusqu\'au ' . fdate($until, false) . ')' : '') . ($c ? ' — réservations à déplacer : ' . implode(', ', array_column($c, 'number')) : ''));
            } else {
                DB::update('hotel_rooms', ['out_of_order' => 0, 'ooo_reason' => null, 'ooo_until' => null], 'id = ?', [$roomId]);
                self::roomLog($roomId, 'en_service', null, null, trim($reason) ?: null);
            }
            Audit::log($on ? 'hotel.room_ooo' : 'hotel.room_back', 'hotel_room', $roomId, ['reason' => $reason]);
        });
    }

    /** Remise en service automatique des chambres dont la date de fin de travaux est passée. */
    public static function releaseOutOfOrder(): void
    {
        foreach (DB::all('SELECT id FROM hotel_rooms WHERE out_of_order = 1 AND ooo_until IS NOT NULL AND ooo_until < CURDATE()') as $r) {
            DB::update('hotel_rooms', ['out_of_order' => 0, 'ooo_reason' => null, 'ooo_until' => null], 'id = ?', [$r['id']]);
            self::roomLog((int) $r['id'], 'en_service', null, null, 'Fin de la période hors service');
        }
    }

    public static function saveRoom(array $d, ?int $id = null): int
    {
        $number = trim((string) ($d['number'] ?? ''));
        if ($number === '' || mb_strlen($number) > 20) {
            throw new BusinessException('Numéro de chambre obligatoire (20 caractères au plus).');
        }
        $typeId = (int) ($d['type_id'] ?? 0);
        self::type($typeId);
        if (DB::value('SELECT id FROM hotel_rooms WHERE number = ? AND id <> ?', [$number, $id ?? 0])) {
            throw new BusinessException('La chambre ' . $number . ' existe déjà.');
        }
        $data = ['number' => $number, 'floor' => mb_substr(trim((string) ($d['floor'] ?? '')) ?: 'Rez-de-chaussée', 0, 30), 'type_id' => $typeId,
            'beds' => mb_substr(trim((string) ($d['beds'] ?? '')), 0, 80) ?: null, 'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 255) ?: null,
            'sort_order' => (int) ($d['sort_order'] ?? (ctype_digit($number) ? $number : 0)), 'active' => !isset($d['active']) || !empty($d['active']) ? 1 : 0];
        if ($id) {
            if (!$data['active'] && DB::value("SELECT id FROM hotel_stays WHERE room_id = ? AND status IN ('reservee','confirmee','en_cours') LIMIT 1", [$id])) {
                throw new BusinessException('Chambre occupée ou réservée : impossible de la désactiver.');
            }
            DB::update('hotel_rooms', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('hotel_rooms', $data);
        }
        Audit::log('hotel.room_save', 'hotel_room', $id, ['number' => $number]);
        return $id;
    }

    public static function saveType(array $d, ?int $id = null): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new BusinessException('Nom du type de chambre obligatoire.');
        }
        $price = self::num($d['base_price'] ?? 0);
        $we = trim((string) ($d['weekend_price'] ?? '')) === '' ? null : self::num($d['weekend_price']);
        if ($price < 0 || ($we !== null && $we < 0)) {
            throw new BusinessException('Prix invalide.');
        }
        if (DB::value('SELECT id FROM hotel_room_types WHERE name = ? AND id <> ?', [$name, $id ?? 0])) {
            throw new BusinessException('Ce type de chambre existe déjà.');
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($d['color'] ?? '')) ? (string) $d['color'] : '#0d6efd';
        $data = ['name' => mb_substr($name, 0, 80), 'code' => mb_strtoupper(mb_substr(trim((string) ($d['code'] ?? '')) ?: mb_substr($name, 0, 3), 0, 10)),
            'capacity' => max(1, min(20, (int) ($d['capacity'] ?? 2))), 'base_price' => $price, 'weekend_price' => $we, 'color' => $color,
            'amenities' => mb_substr(trim((string) ($d['amenities'] ?? '')), 0, 255) ?: null, 'active' => !isset($d['active']) || !empty($d['active']) ? 1 : 0];
        if ($id) {
            DB::update('hotel_room_types', $data, 'id = ?', [$id]);
        } else {
            $data['sort_order'] = (int) DB::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM hotel_room_types');
            $id = DB::insert('hotel_room_types', $data);
        }
        Audit::log('hotel.type_save', 'hotel_room_type', $id, ['name' => $name, 'price' => $price]);
        return $id;
    }

    public static function saveExtra(array $d, ?int $id = null): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        $kind = (string) ($d['kind'] ?? 'service');
        if ($name === '' || !isset(self::KINDS[$kind]) || in_array($kind, ['nuitee', 'taxe', 'remise', 'annulation', 'depart_tardif'], true)) {
            throw new BusinessException('Nom et type de la prestation obligatoires.');
        }
        $price = self::num($d['price'] ?? 0);
        if ($price < 0) {
            throw new BusinessException('Prix invalide.');
        }
        if (DB::value('SELECT id FROM hotel_extras WHERE name = ? AND id <> ?', [$name, $id ?? 0])) {
            throw new BusinessException('Cette prestation existe déjà.');
        }
        $data = ['name' => mb_substr($name, 0, 100), 'kind' => $kind, 'price' => $price, 'active' => !isset($d['active']) || !empty($d['active']) ? 1 : 0,
            'icon' => preg_match('/^bi-[a-z0-9-]+$/', (string) ($d['icon'] ?? '')) ? (string) $d['icon'] : 'bi-bag'];
        if ($id) {
            DB::update('hotel_extras', $data, 'id = ?', [$id]);
        } else {
            $data['sort_order'] = (int) DB::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM hotel_extras');
            $id = DB::insert('hotel_extras', $data);
        }
        Audit::log('hotel.extra_save', null, $id, ['name' => $name, 'price' => $price]);
        return $id;
    }

    // ------------------------------------------------------------------ plan des chambres et indicateurs

    /**
     * État de chaque chambre aujourd'hui : occupée, départ du jour, arrivée attendue, libre,
     * à nettoyer, hors service ; avec le client, les dates, le solde estimé.
     */
    public static function board(): array
    {
        self::releaseOutOfOrder();
        $today = self::today();
        $rooms = DB::all('SELECT r.*, t.name AS type_name, t.code AS type_code, t.color, t.capacity FROM hotel_rooms r JOIN hotel_room_types t ON t.id = r.type_id WHERE r.active = 1 ORDER BY r.sort_order, r.number');
        $inHouse = [];
        foreach (DB::all("SELECT s.*, g.last_name, g.first_name, g.phone, g.vip FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id WHERE s.status = 'en_cours'") as $s) {
            $inHouse[(int) $s['room_id']] = $s;
        }
        $arriving = [];
        foreach (DB::all("SELECT s.*, g.last_name, g.first_name, g.phone, g.vip FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id
                          WHERE s.status IN ('reservee','confirmee') AND s.arrival <= ? AND s.departure > ? AND s.room_id IS NOT NULL ORDER BY s.arrival", [$today, $today]) as $s) {
            $arriving[(int) $s['room_id']] ??= $s;
        }
        $t = ['rooms' => 0, 'occupied' => 0, 'free' => 0, 'dirty' => 0, 'ooo' => 0, 'arrivals' => 0, 'departures' => 0, 'overdue' => 0, 'guests' => 0, 'occupancy' => 0];
        $out = [];
        foreach ($rooms as $r) {
            $id = (int) $r['id'];
            $s = $inHouse[$id] ?? null;
            $arr = $arriving[$id] ?? null;
            $ooo = self::outOfOrderOn($r, $today);
            $state = $ooo ? 'hors_service' : ($s ? ($s['departure'] < $today ? 'depart_retard' : ($s['departure'] === $today ? 'depart' : 'occupee')) : ($arr ? 'arrivee' : 'libre'));
            $t['rooms']++;
            if ($s) {
                $t['occupied']++;
                $t['guests'] += (int) $s['adults'] + (int) $s['children'];
                $t['departures'] += $s['departure'] <= $today ? 1 : 0;
                $t['overdue'] += $s['departure'] < $today ? 1 : 0;
            } elseif ($state === 'libre') {
                $t['free']++;
            }
            $t['ooo'] += $ooo ? 1 : 0;
            $t['dirty'] += in_array($r['housekeeping'], ['sale', 'nettoyage'], true) ? 1 : 0;
            $guest = $s ?? $arr;
            $bal = $s ? self::balance($s) : null;
            $out[] = [
                'id' => $id, 'number' => $r['number'], 'floor' => $r['floor'], 'type' => $r['type_name'], 'type_code' => $r['type_code'], 'type_id' => (int) $r['type_id'], 'color' => $r['color'],
                'capacity' => (int) $r['capacity'], 'beds' => (string) $r['beds'], 'hk' => $r['housekeeping'], 'state' => $state, 'ooo_reason' => (string) $r['ooo_reason'], 'ooo_until' => $r['ooo_until'],
                'stay' => $guest ? ['id' => (int) $guest['id'], 'number' => $guest['number'], 'status' => $guest['status'], 'name' => trim($guest['last_name'] . ' ' . $guest['first_name']), 'phone' => (string) $guest['phone'],
                    'vip' => (bool) $guest['vip'], 'arrival' => $guest['arrival'], 'departure' => $guest['departure'], 'adults' => (int) $guest['adults'], 'children' => (int) $guest['children'],
                    'nights' => self::nights($guest['arrival'], $guest['departure']), 'night_no' => $s ? self::nights($s['arrival'], $today) + 1 : 0,
                    'due' => $bal ? $bal['estimated_due'] : null, 'note' => (string) $guest['note']] : null,
            ];
        }
        $t['arrivals'] = (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status IN ('reservee','confirmee') AND arrival = ?", [$today]);
        $t['unassigned'] = (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status IN ('reservee','confirmee') AND arrival <= ? AND departure > ? AND room_id IS NULL", [$today, $today]);
        $sellable = $t['rooms'] - $t['ooo'];
        $t['occupancy'] = $sellable > 0 ? (int) round($t['occupied'] * 100 / $sellable) : 0;
        return ['now' => date('H:i'), 'date' => $today, 'rooms' => $out, 'totals' => $t, 'hk' => self::HK, 'floors' => array_values(array_unique(array_column($rooms, 'floor')))];
    }

    /**
     * Indicateurs du tableau de bord : encaissements du jour ; nuitées, prix moyen et RevPAR de la
     * nuit précédente (la nuit du jour n'est portée au folio que le lendemain, par l'audit de nuit).
     */
    public static function stats(string $day): array
    {
        $rooms = (int) DB::value('SELECT COUNT(*) FROM hotel_rooms WHERE active = 1');
        $night = self::addDays($day, -1);
        $room = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM hotel_charges WHERE cancelled = 0 AND kind = 'nuitee' AND service_date = ?", [$night]);
        $nights = (int) DB::value("SELECT COUNT(*) FROM hotel_charges WHERE cancelled = 0 AND kind = 'nuitee' AND service_date = ?", [$night]);
        return [
            'cash' => (float) DB::value('SELECT COALESCE(SUM(amount),0) FROM hotel_payments WHERE created_at BETWEEN ? AND ?', [$day, $day . ' 23:59:59']),
            'other' => (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM hotel_charges WHERE cancelled = 0 AND kind NOT IN ('nuitee') AND service_date = ?", [$day]),
            'room' => $room, 'nights' => $nights, 'adr' => $nights ? round($room / $nights) : 0, 'revpar' => $rooms ? round($room / $rooms) : 0,
            'receivable' => (float) DB::value("SELECT COALESCE(SUM(total_charges - total_paid),0) FROM hotel_stays WHERE status = 'terminee' AND total_charges - total_paid > 0.009"),
        ];
    }

    /**
     * Rapport de période : occupation nuit par nuit (séjours en cours ou terminés), chiffre
     * d'affaires des nuitées et des prestations, prix moyen (ADR) et revenu par chambre (RevPAR).
     */
    public static function report(string $from, string $to): array
    {
        $rooms = (int) DB::value('SELECT COUNT(*) FROM hotel_rooms WHERE active = 1');
        $days = [];
        for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) {
            $days[$d] = ['occupied' => 0, 'room' => 0.0, 'other' => 0.0, 'arrivals' => 0, 'departures' => 0];
            if (count($days) > 400) {
                throw new BusinessException('Période trop longue (400 jours au plus).');
            }
        }
        foreach (DB::all("SELECT arrival, departure, status, checked_out_at FROM hotel_stays WHERE status IN ('en_cours','terminee') AND arrival <= ? AND departure > ?", [$to, $from]) as $s) {
            $end = $s['status'] === 'en_cours' ? max($s['departure'], self::addDays(self::today(), 1)) : $s['departure'];
            for ($d = max($s['arrival'], $from); $d < $end && $d <= $to; $d = self::addDays($d, 1)) {
                if ($s['status'] === 'en_cours' && $d >= self::today()) {
                    break; // nuits futures : pas encore réalisées
                }
                $days[$d]['occupied']++;
            }
            if (isset($days[$s['arrival']])) {
                $days[$s['arrival']]['arrivals']++;
            }
            if ($s['status'] === 'terminee' && isset($days[$s['departure']])) {
                $days[$s['departure']]['departures']++;
            }
        }
        foreach (DB::all("SELECT service_date d, SUM(CASE WHEN kind = 'nuitee' THEN amount ELSE 0 END) room, SUM(CASE WHEN kind <> 'nuitee' THEN amount ELSE 0 END) other
                          FROM hotel_charges WHERE cancelled = 0 AND service_date BETWEEN ? AND ? GROUP BY service_date", [$from, $to]) as $c) {
            if (isset($days[$c['d']])) {
                $days[$c['d']]['room'] = (float) $c['room'];
                $days[$c['d']]['other'] = (float) $c['other'];
            }
        }
        $occ = array_sum(array_column($days, 'occupied'));
        $room = array_sum(array_column($days, 'room'));
        $avail = $rooms * count($days);
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        return [
            'days' => $days, 'rooms' => $rooms,
            'totals' => ['occupied' => $occ, 'available' => $avail, 'occupancy' => $avail ? round($occ * 100 / $avail, 1) : 0, 'room' => $room,
                'other' => array_sum(array_column($days, 'other')), 'adr' => $occ ? round($room / $occ) : 0, 'revpar' => $avail ? round($room / $avail) : 0,
                'arrivals' => array_sum(array_column($days, 'arrivals')), 'departures' => array_sum(array_column($days, 'departures'))],
            'kinds' => DB::all("SELECT kind, SUM(amount) total, COUNT(*) n FROM hotel_charges WHERE cancelled = 0 AND service_date BETWEEN ? AND ? GROUP BY kind ORDER BY total DESC", [$from, $to]),
            'types' => DB::all("SELECT t.name, COUNT(*) nights, SUM(c.amount) total FROM hotel_charges c JOIN hotel_stays s ON s.id = c.stay_id JOIN hotel_rooms r ON r.id = c.room_id JOIN hotel_room_types t ON t.id = r.type_id
                                WHERE c.cancelled = 0 AND c.kind = 'nuitee' AND c.service_date BETWEEN ? AND ? GROUP BY t.id ORDER BY total DESC", [$from, $to]),
            'sources' => DB::all("SELECT source, COUNT(*) n, SUM(DATEDIFF(departure, arrival)) nights FROM hotel_stays WHERE status NOT IN ('annulee') AND created_at BETWEEN ? AND ? GROUP BY source ORDER BY n DESC", $range),
            'payments' => DB::all('SELECT method, kind, COUNT(*) n, SUM(amount) total FROM hotel_payments WHERE created_at BETWEEN ? AND ? GROUP BY method, kind ORDER BY method', $range),
            'cancelled' => (int) DB::value("SELECT COUNT(*) FROM hotel_stays WHERE status IN ('annulee','no_show') AND cancelled_at BETWEEN ? AND ?", $range),
        ];
    }

    /** Registre des voyageurs (fiche de police) : arrivées de la période avec l'identité complète. */
    public static function policeRegister(string $from, string $to): array
    {
        return DB::all("SELECT s.number, s.checked_in_at, s.arrival, s.departure, s.checked_out_at, s.adults, s.children, s.coming_from, s.going_to, s.purpose, r.number AS room,
                g.last_name, g.first_name, g.sex, g.birth_date, g.birth_place, g.nationality, g.profession, g.address, g.phone, g.id_type, g.id_number, g.id_issued_on, g.id_issued_at
            FROM hotel_stays s JOIN hotel_guests g ON g.id = s.guest_id LEFT JOIN hotel_rooms r ON r.id = s.room_id
            WHERE s.checked_in_at BETWEEN ? AND ? ORDER BY s.checked_in_at", [$from . ' 00:00:00', $to . ' 23:59:59']);
    }
}
