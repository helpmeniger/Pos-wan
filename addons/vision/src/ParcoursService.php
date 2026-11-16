<?php
declare(strict_types=1);

namespace Addons\Vision;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Contrôle « rayon → caisse » : suivi de chaque article pris en rayon jusqu'à son encaissement.
 *
 *   caméra d'étagère : « prise » sur un emplacement  → suivi ouvert (délai : vision_parcours_delai minutes)
 *   vente enregistrée du même article (même boutique) → suivi clos « encaissé » (une unité vendue ne clôt qu'un suivi)
 *   caméra d'étagère : « remis » sur le même emplacement → suivi clos « remis en rayon »
 *   caméra de caisse : article reconnu sur le comptoir  → « vu en caisse » (en attente de la vente)
 *   caméra de sortie : article reconnu qui sort          → alerte immédiate s'il n'est pas encaissé
 *   délai dépassé sans vente ni remise                   → alerte « vérification demandée »
 *
 * Une alerte n'est PAS une preuve de vol : la détection et la reconnaissance sont automatiques et imparfaites.
 * Elle demande une vérification (rayon, caisse, vignette) ; le résultat de la vérification est enregistré et sert à
 * mesurer la fiabilité de chaque caméra (part des fausses alertes).
 */
final class ParcoursService
{
    public const ROLES = [
        'passage' => 'Passage (comptage par ligne ou zone)',
        'etagere' => 'Étagère : articles pris ou remis',
        'caisse' => 'Caisse : articles vus sur le comptoir',
        'sortie' => 'Sortie : articles qui quittent la boutique',
    ];
    public const STATUSES = [
        'en_cours' => 'En cours', 'vu_caisse' => 'Vu en caisse, vente attendue', 'encaisse' => 'Encaissé', 'remis' => 'Remis en rayon',
        'alerte' => 'À vérifier', 'verifie_encaisse' => 'Vérifié : encaissé', 'verifie_remis' => 'Vérifié : remis en rayon',
        'fausse_alerte' => 'Fausse alerte', 'perte' => 'Perte confirmée',
    ];
    public const LEVELS = ['priorite' => 'Priorité', 'verifier' => 'À vérifier', 'faible' => 'Article non identifié'];
    public const OPEN = ['en_cours', 'vu_caisse'];
    public const REVIEW = ['encaisse' => 'verifie_encaisse', 'remis' => 'verifie_remis', 'fausse' => 'fausse_alerte', 'perte' => 'perte'];
    /** Tolérance d'horloge : une vente enregistrée jusqu'à 60 s avant la prise peut la justifier. */
    private const CLOCK = 60;

    public static function delay(): int
    {
        return max(1, min(120, (int) setting('vision_parcours_delai', '15')));
    }

    /** Boutique d'une caméra : la sienne, sinon celle de la caisse rattachée. */
    public static function storeOf(array $cam): ?int
    {
        if (!empty($cam['store_id'])) {
            return (int) $cam['store_id'];
        }
        if (!empty($cam['register_id'])) {
            return (int) DB::value('SELECT store_id FROM cash_registers WHERE id = ?', [$cam['register_id']]) ?: null;
        }
        return null;
    }

    private static function sure(array $ev): bool
    {
        return (int) $ev['product_id'] > 0 && in_array($ev['status'], ['identifie', 'confirme', 'corrige'], true);
    }

    /**
     * Appelé après l'enregistrement d'un événement de caméra. Retourne le suivi touché (ou null).
     * @return array{journey: int, status: string, level: ?string}|null
     */
    public static function onEvent(array $ev, array $cam): ?array
    {
        $role = (string) ($cam['role'] ?? 'passage');
        $store = self::storeOf($cam);
        $now = (string) $ev['seen_at'];
        if ($role === 'etagere' && $ev['direction'] === 'prise') {
            $pid = (int) $ev['product_id'] ?: null;
            $by = $pid ? (!empty($ev['_slot_product']) ? 'emplacement' : (self::sure($ev) ? 'reconnaissance' : 'aucun')) : 'aucun';
            if ($by === 'aucun') {
                $pid = null; // reconnaissance incertaine : on ne nomme pas l'article (pas de rapprochement sur une supposition)
            }
            // Plusieurs articles pris d'un seul geste : un suivi par article (chacun doit être encaissé)
            $r = null;
            for ($n = 0; $n < max(1, (int) ($ev['qty'] ?? 1)); $n++) {
                $id = DB::insert('vision_journeys', ['store_id' => $store, 'camera_id' => $cam['id'], 'slot_id' => $ev['slot_id'] ?: null, 'product_id' => $pid, 'identified_by' => $by,
                    'origin' => 'rayon', 'taken_event_id' => $ev['id'], 'taken_at' => $now, 'deadline_at' => date('Y-m-d H:i:s', strtotime($now) + self::delay() * 60), 'status' => 'en_cours']);
                $r = self::resolveOne((int) $id, $now);
                $r['count'] = $n + 1;
            }
            return $r;
        }
        if ($role === 'etagere' && $ev['direction'] === 'remis') {
            // Les derniers articles pris sur cet emplacement (ou de cet article sur cette caméra), encore suivis
            $rows = DB::all('SELECT * FROM vision_journeys WHERE camera_id = ? AND status IN (\'en_cours\',\'vu_caisse\',\'alerte\') AND reviewed_at IS NULL AND exit_event_id IS NULL
                          AND ' . ($ev['slot_id'] ? 'slot_id = ?' : 'product_id = ?') . ' AND taken_at >= ? - INTERVAL ? MINUTE ORDER BY taken_at DESC LIMIT ' . max(1, min(50, (int) ($ev['qty'] ?? 1))),
                [$cam['id'], $ev['slot_id'] ?: (int) $ev['product_id'], $now, 3 * self::delay()]);
            if (!$rows) {
                return null; // réapprovisionnement, ou remise d'un article pris avant la surveillance
            }
            foreach ($rows as $j) {
                DB::update('vision_journeys', ['status' => 'remis', 'resolved_at' => $now, 'reason' => $j['status'] === 'alerte' ? 'Remis en rayon après l\'alerte' : null], 'id = ?', [$j['id']]);
            }
            return ['journey' => (int) $rows[0]['id'], 'status' => 'remis', 'level' => null, 'count' => count($rows)];
        }
        if ($role === 'etagere' && $ev['direction'] === 'deplace') {
            // Parti d'un emplacement et posé sur un autre du même rayon dans le même geste : il ne quitte pas le rayon
            return ['journey' => null, 'status' => 'deplace', 'level' => null];
        }
        if ($role === 'etagere' && $ev['direction'] === 'depose') {
            // Objet posé hors de sa place : si la reconnaissance le nomme avec certitude, l'article pris plus tôt et suivi
            // (même boutique) n'a pas quitté la boutique : son suivi est clos « remis » (reposé ailleurs)
            if (!self::sure($ev)) {
                return ['journey' => null, 'status' => 'depose', 'level' => null];
            }
            $j = DB::one('SELECT * FROM vision_journeys WHERE product_id = ? AND status IN (\'en_cours\',\'vu_caisse\',\'alerte\') AND reviewed_at IS NULL AND exit_event_id IS NULL'
                . ($store ? ' AND (store_id = ? OR store_id IS NULL)' : '') . ' AND taken_at >= ? - INTERVAL ? MINUTE ORDER BY taken_at DESC LIMIT 1',
                array_merge([(int) $ev['product_id']], $store ? [$store] : [], [$now, 3 * self::delay()]));
            if (!$j) {
                return ['journey' => null, 'status' => 'depose', 'level' => null];
            }
            $where = (string) DB::value('SELECT label FROM vision_slots WHERE id = ?', [$ev['slot_id']]);
            DB::update('vision_journeys', ['status' => 'remis', 'resolved_at' => $now, 'reason' => mb_substr('Reposé ailleurs sur le rayon' . ($where !== '' ? ' (' . $where . ')' : '') . ($cam['place'] ? ' · ' . $cam['place'] : ''), 0, 255)], 'id = ?', [$j['id']]);
            return ['journey' => (int) $j['id'], 'status' => 'remis', 'level' => null];
        }
        if ($role === 'caisse' && in_array($ev['direction'], ['vu', 'sortie'], true) && self::sure($ev)) {
            $pid = (int) $ev['product_id'];
            $j = DB::one('SELECT * FROM vision_journeys WHERE product_id = ? AND status = \'en_cours\' AND checkout_event_id IS NULL' . ($store ? ' AND (store_id = ? OR store_id IS NULL)' : '') . '
                          AND taken_at >= ? - INTERVAL ? MINUTE ORDER BY taken_at LIMIT 1', array_merge([$pid], $store ? [$store] : [], [$now, 3 * self::delay()]));
            if ($j) {
                DB::update('vision_journeys', ['status' => 'vu_caisse', 'checkout_event_id' => $ev['id'], 'checkout_at' => $now], 'id = ?', [$j['id']]);
                return self::resolveOne((int) $j['id'], $now);
            }
            // Article venu d'un rayon non filmé : suivi ouvert depuis le comptoir (vente attendue)
            $id = DB::insert('vision_journeys', ['store_id' => $store, 'camera_id' => null, 'product_id' => $pid, 'identified_by' => 'reconnaissance', 'origin' => 'caisse',
                'taken_event_id' => $ev['id'], 'taken_at' => $now, 'deadline_at' => date('Y-m-d H:i:s', strtotime($now) + self::delay() * 60), 'status' => 'vu_caisse',
                'checkout_event_id' => $ev['id'], 'checkout_at' => $now]);
            return self::resolveOne((int) $id, $now);
        }
        if ($role === 'sortie' && in_array($ev['direction'], ['sortie', 'vu'], true) && self::sure($ev) && setting('vision_parcours_sortie', '1') === '1') {
            $pid = (int) $ev['product_id'];
            $j = DB::one('SELECT * FROM vision_journeys WHERE product_id = ? AND status IN (\'en_cours\',\'vu_caisse\',\'alerte\') AND reviewed_at IS NULL AND exit_event_id IS NULL' . ($store ? ' AND (store_id = ? OR store_id IS NULL)' : '') . '
                          AND taken_at >= ? - INTERVAL ? MINUTE ORDER BY taken_at LIMIT 1', array_merge([$pid], $store ? [$store] : [], [$now, 3 * self::delay()]));
            if ($j) {
                DB::update('vision_journeys', ['exit_event_id' => $ev['id'], 'exit_at' => $now], 'id = ?', [$j['id']]);
                $r = self::resolveOne((int) $j['id'], $now);
                if ($r && in_array($r['status'], ['encaisse', 'remis'], true)) {
                    return $r;
                }
                self::alert((int) $j['id'], $now, 'priorite', 'Vu franchir la sortie sans encaissement');
                return ['journey' => (int) $j['id'], 'status' => 'alerte', 'level' => 'priorite'];
            }
            // Pas de prise observée : une vente récente de cet article, non encore rapprochée, justifie la sortie
            $id = DB::insert('vision_journeys', ['store_id' => $store, 'product_id' => $pid, 'identified_by' => 'reconnaissance', 'origin' => 'sortie', 'taken_event_id' => $ev['id'],
                'taken_at' => date('Y-m-d H:i:s', strtotime($now) - self::delay() * 60), 'deadline_at' => $now, 'status' => 'en_cours', 'exit_event_id' => $ev['id'], 'exit_at' => $now]);
            if ($sale = self::matchSale(['id' => $id, 'product_id' => $pid, 'store_id' => $store, 'taken_at' => date('Y-m-d H:i:s', strtotime($now) - self::delay() * 60)], $now)) {
                DB::update('vision_journeys', ['status' => 'encaisse', 'sale_id' => $sale, 'resolved_at' => $now], 'id = ?', [$id]);
                return ['journey' => (int) $id, 'status' => 'encaisse', 'level' => null];
            }
            self::alert((int) $id, $now, 'verifier', 'Vu à la sortie sans vente correspondante (prise en rayon non observée)');
            return ['journey' => (int) $id, 'status' => 'alerte', 'level' => 'verifier'];
        }
        return null;
    }

    /**
     * Unité vendue du même article (même boutique), après la prise, pas encore rapprochée d'un autre suivi.
     * Une vente enregistrée après l'alerte (client lent, file d'attente) clôt aussi le suivi, dans la limite de 3 délais.
     */
    private static function matchSale(array $j, string $now): ?int
    {
        if (!$j['product_id']) {
            return null;
        }
        $from = date('Y-m-d H:i:s', strtotime((string) $j['taken_at']) - self::CLOCK);
        $to = min($now, date('Y-m-d H:i:s', strtotime((string) $j['taken_at']) + 3 * self::delay() * 60));
        $cands = DB::all('SELECT s.id, SUM(si.base_quantity) AS qty FROM sales s JOIN sale_items si ON si.sale_id = s.id
                          WHERE si.product_id = ? AND s.status IN (\'completed\',\'partially_returned\') AND s.created_at BETWEEN ? AND ?' . ($j['store_id'] ? ' AND s.store_id = ?' : '') . '
                          GROUP BY s.id, s.created_at ORDER BY s.created_at', array_merge([$j['product_id'], $from, $to], $j['store_id'] ? [$j['store_id']] : []));
        foreach ($cands as $c) {
            $used = (int) DB::value('SELECT COUNT(*) FROM vision_journeys WHERE sale_id = ? AND product_id = ? AND id <> ?', [$c['id'], $j['product_id'], $j['id']]);
            if ($used < (int) floor((float) $c['qty'] + 1e-9)) {
                return (int) $c['id'];
            }
        }
        return null;
    }

    private static function alert(int $id, string $now, string $level, string $reason): void
    {
        DB::update('vision_journeys', ['status' => 'alerte', 'level' => $level, 'reason' => mb_substr($reason, 0, 255), 'alerted_at' => $now], 'id = ?', [$id]);
    }

    /** Niveau d'une alerte selon ce qui a été observé. */
    private static function levelOf(array $j): array
    {
        if ($j['exit_event_id']) {
            return ['priorite', 'Vu franchir la sortie sans encaissement'];
        }
        if (!$j['product_id']) {
            return ['faible', 'Article non identifié pris en rayon, sans vente ni remise dans le délai'];
        }
        if ($j['checkout_event_id']) {
            return ['priorite', $j['origin'] === 'caisse' ? 'Vu sur le comptoir de la caisse, aucune vente correspondante' : 'Vu en caisse mais pas encaissé dans le délai'];
        }
        return ['verifier', 'Pris en rayon, ni encaissé ni remis dans le délai de ' . self::delay() . ' min'];
    }

    /** Fait avancer un suivi : vente trouvée → encaissé ; délai dépassé → alerte. */
    private static function resolveOne(int $id, string $now): ?array
    {
        $j = DB::one('SELECT * FROM vision_journeys WHERE id = ?', [$id]);
        if (!$j) {
            return null;
        }
        $open = in_array($j['status'], self::OPEN, true);
        $lateAlert = $j['status'] === 'alerte' && !$j['reviewed_at'];
        if (($open || $lateAlert) && ($sale = self::matchSale($j, $now))) {
            DB::update('vision_journeys', ['status' => 'encaisse', 'sale_id' => $sale, 'resolved_at' => $now, 'reason' => $lateAlert ? 'Encaissé après l\'alerte' : null], 'id = ?', [$id]);
            return ['journey' => $id, 'status' => 'encaisse', 'level' => null];
        }
        if ($open && $j['deadline_at'] <= $now) {
            if (!$j['product_id'] && setting('vision_parcours_inconnu', '1') !== '1') {
                DB::update('vision_journeys', ['status' => 'fausse_alerte', 'resolved_at' => $now, 'reason' => 'Article non identifié : alerte désactivée dans les réglages'], 'id = ?', [$id]);
                return ['journey' => $id, 'status' => 'fausse_alerte', 'level' => null];
            }
            [$level, $reason] = self::levelOf($j);
            self::alert($id, $now, $level, $reason);
            return ['journey' => $id, 'status' => 'alerte', 'level' => $level];
        }
        return ['journey' => $id, 'status' => $j['status'], 'level' => $j['level']];
    }

    /** Fait avancer tous les suivis ouverts (appelé par l'écran de surveillance, la page Contrôles et la cloche). */
    public static function resolve(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $n = ['encaisse' => 0, 'alerte' => 0, 'fausse_alerte' => 0];
        $ids = array_column(DB::all('SELECT id FROM vision_journeys WHERE status IN (\'en_cours\',\'vu_caisse\') OR (status = \'alerte\' AND reviewed_at IS NULL AND product_id IS NOT NULL
                                     AND taken_at >= ? - INTERVAL ? MINUTE) ORDER BY taken_at LIMIT 500', [$now, 3 * self::delay()]), 'id');
        foreach ($ids as $id) {
            $before = DB::value('SELECT status FROM vision_journeys WHERE id = ?', [$id]);
            $r = self::resolveOne((int) $id, $now);
            if ($r && $r['status'] !== $before && isset($n[$r['status']])) {
                $n[$r['status']]++;
            }
        }
        return $n;
    }

    /** Vérification d'une alerte par un responsable. */
    public static function review(int $id, string $action, ?string $note = null): array
    {
        $j = DB::one('SELECT * FROM vision_journeys WHERE id = ?', [$id]) ?? throw new BusinessException('Suivi introuvable.');
        $status = self::REVIEW[$action] ?? throw new BusinessException('Action inconnue.');
        if (!in_array($j['status'], ['alerte', 'en_cours', 'vu_caisse'], true) && !$j['reviewed_at']) {
            throw new BusinessException('Ce suivi est déjà clos (' . (self::STATUSES[$j['status']] ?? $j['status']) . ').');
        }
        DB::update('vision_journeys', ['status' => $status, 'reviewed_by' => Auth::id(), 'reviewed_at' => date('Y-m-d H:i:s'), 'resolved_at' => $j['resolved_at'] ?? date('Y-m-d H:i:s'),
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null], 'id = ?', [$id]);
        Audit::log('vision.parcours_review', 'product', $j['product_id'] ? (int) $j['product_id'] : null, ['journey' => $id, 'action' => $action, 'before' => $j['status']]);
        return ['status' => $status, 'label' => self::STATUSES[$status]];
    }

    /** Suivis avec article, emplacement, caméras et vignettes, pour l'affichage. */
    public static function rows(string $where, array $params, int $limit = 100): array
    {
        return DB::all("SELECT j.*, p.name AS product, p.reference, sl.label AS slot, c.name AS camera, c.place, sa.number AS sale_number,
                               et.thumb AS taken_thumb, ec.thumb AS checkout_thumb, cc.name AS checkout_camera, ex.thumb AS exit_thumb, xc.name AS exit_camera, u.full_name AS reviewer,
                               (SELECT COUNT(*) FROM vision_events d WHERE d.camera_id = j.camera_id AND d.direction = 'depose' AND d.seen_at >= j.taken_at) AS deposes
                        FROM vision_journeys j
                        LEFT JOIN products p ON p.id = j.product_id LEFT JOIN vision_slots sl ON sl.id = j.slot_id LEFT JOIN vision_cameras c ON c.id = j.camera_id
                        LEFT JOIN sales sa ON sa.id = j.sale_id LEFT JOIN vision_events et ON et.id = j.taken_event_id
                        LEFT JOIN vision_events ec ON ec.id = j.checkout_event_id LEFT JOIN vision_cameras cc ON cc.id = ec.camera_id
                        LEFT JOIN vision_events ex ON ex.id = j.exit_event_id LEFT JOIN vision_cameras xc ON xc.id = ex.camera_id
                        LEFT JOIN users u ON u.id = j.reviewed_by
                        WHERE $where ORDER BY j.taken_at DESC LIMIT " . max(1, min(500, $limit)), $params);
    }

    /** État en direct : alertes à vérifier, suivis en cours (pour l'écran de surveillance). */
    public static function live(int $sinceId = 0): array
    {
        self::resolve();
        $alerts = self::rows("j.status = 'alerte' AND j.reviewed_at IS NULL AND j.taken_at >= NOW() - INTERVAL 2 DAY", [], 40);
        $out = array_map(fn($r) => [
            'id' => (int) $r['id'], 'level' => $r['level'], 'level_label' => self::LEVELS[$r['level']] ?? '', 'product' => $r['product'] ?? 'Article non identifié',
            'where' => trim(($r['place'] ?: $r['camera'] ?: '') . ($r['slot'] ? ' · ' . $r['slot'] : ''), ' ·') ?: ($r['checkout_camera'] ?? $r['exit_camera'] ?? ''),
            'reason' => $r['reason'] . ((int) $r['deposes'] ? ' · ' . (int) $r['deposes'] . ' objet(s) posé(s) hors de leur place sur ce rayon depuis : à regarder d\'abord' : ''),
            'taken_at' => substr((string) $r['taken_at'], 11, 5), 'alerted_at' => substr((string) $r['alerted_at'], 11, 5),
            'thumb' => $r['taken_thumb'] ?? $r['checkout_thumb'] ?? $r['exit_thumb'], 'new' => (int) $r['id'] > $sinceId,
        ], $alerts);
        $open = DB::one("SELECT SUM(status = 'en_cours') AS en_cours, SUM(status = 'vu_caisse') AS vu_caisse FROM vision_journeys WHERE status IN ('en_cours','vu_caisse')");
        return ['alerts' => $out, 'en_cours' => (int) ($open['en_cours'] ?? 0), 'vu_caisse' => (int) ($open['vu_caisse'] ?? 0),
            'max_id' => (int) DB::value("SELECT COALESCE(MAX(id), 0) FROM vision_journeys WHERE status = 'alerte'")];
    }

    /**
     * Emplacements dont le nombre d'articles vus à leur place est descendu au seuil choisi (« alerte si ≤ »).
     * Un compte de plus de 24 h n'est pas utilisé (écran de surveillance fermé depuis).
     */
    public static function lowShelves(): array
    {
        return DB::all('SELECT s.id, s.label, s.units, s.min_count, s.seen_count, s.seen_at, p.name AS product, c.name AS camera, c.place
                        FROM vision_slots s JOIN vision_cameras c ON c.id = s.camera_id AND c.active = 1 LEFT JOIN products p ON p.id = s.product_id
                        WHERE s.active = 1 AND s.min_count IS NOT NULL AND s.seen_count IS NOT NULL AND s.seen_count <= s.min_count AND s.seen_at >= NOW() - INTERVAL 1 DAY
                        ORDER BY s.seen_count, s.label');
    }

    /** Comptes vus par l'écran de surveillance (articles à leur place, par emplacement). */
    public static function saveCounts(int $cameraId, array $list): int
    {
        $n = 0;
        foreach (array_slice($list, 0, 40) as $x) {
            $x = (array) $x;
            $n += DB::run('UPDATE vision_slots SET seen_count = LEAST(units, ?), seen_at = NOW() WHERE id = ? AND camera_id = ? AND active = 1',
                [max(0, min(255, (int) ($x['count'] ?? 0))), (int) ($x['id'] ?? 0), $cameraId])->rowCount();
        }
        return $n;
    }

    /** Nombre d'alertes à vérifier (cloche de la barre supérieure). */
    public static function openAlerts(): int
    {
        self::resolve();
        return (int) DB::value("SELECT COUNT(*) FROM vision_journeys WHERE status = 'alerte' AND reviewed_at IS NULL");
    }

    /**
     * Rappel discret au caissier : articles vus sur le comptoir de SA caisse (caméra rattachée au poste de sa session
     * de caisse avec une session ouverte) depuis plus de N secondes et pas encore encaissés. Désactivé par défaut.
     */
    public static function cashierReminders(int $userId): array
    {
        if (setting('vision_parcours_rappel', '0') !== '1') {
            return [];
        }
        // Poste de caisse du compte (comme la caisse elle-même), avec une session ouverte
        $reg = (int) DB::value("SELECT u.register_id FROM users u JOIN cash_sessions cs ON cs.register_id = u.register_id AND cs.status = 'open' WHERE u.id = ? LIMIT 1", [$userId]);
        if (!$reg) {
            return [];
        }
        self::resolve();
        $after = max(15, min(600, (int) setting('vision_parcours_rappel_s', '60')));
        return DB::all("SELECT j.id, p.name AS product, j.checkout_at FROM vision_journeys j JOIN vision_events e ON e.id = j.checkout_event_id JOIN vision_cameras c ON c.id = e.camera_id
                        JOIN products p ON p.id = j.product_id
                        WHERE j.status = 'vu_caisse' AND c.register_id = ? AND j.checkout_at <= NOW() - INTERVAL ? SECOND AND j.checkout_at >= NOW() - INTERVAL 30 MINUTE
                        ORDER BY j.checkout_at LIMIT 5", [$reg, $after]);
    }

    /** Fiabilité par caméra d'étagère et bilan de la période. */
    public static function stats(string $from, string $to): array
    {
        $p = [$from . ' 00:00:00', $to . ' 23:59:59'];
        $byCam = DB::all("SELECT COALESCE(c.name, CASE j.origin WHEN 'caisse' THEN 'Caisse (rayon non filmé)' ELSE 'Sortie (prise non observée)' END) AS camera, c.place, j.origin,
                                 COUNT(*) AS total, SUM(j.product_id IS NOT NULL) AS identified, SUM(j.identified_by = 'emplacement') AS by_slot,
                                 SUM(j.status IN ('encaisse','verifie_encaisse')) AS paid, SUM(j.status IN ('remis','verifie_remis')) AS back,
                                 SUM(j.alerted_at IS NOT NULL) AS alerts, SUM(j.status IN ('fausse_alerte','verifie_encaisse','verifie_remis') AND j.reviewed_at IS NOT NULL AND j.alerted_at IS NOT NULL) AS false_alerts,
                                 SUM(j.status = 'perte') AS losses, SUM(j.status = 'alerte' AND j.reviewed_at IS NULL) AS to_check,
                                 SUM(j.reviewed_at IS NOT NULL AND j.alerted_at IS NOT NULL) AS reviewed,
                                 ROUND(AVG(CASE WHEN j.status = 'encaisse' THEN TIMESTAMPDIFF(SECOND, j.taken_at, j.resolved_at) END)) AS avg_pay_s
                          FROM vision_journeys j LEFT JOIN vision_cameras c ON c.id = j.camera_id
                          WHERE j.taken_at BETWEEN ? AND ? GROUP BY j.camera_id, j.origin, c.name, c.place ORDER BY total DESC", $p);
        $t = DB::one("SELECT COUNT(*) AS total, SUM(status IN ('encaisse','verifie_encaisse')) AS paid, SUM(status IN ('remis','verifie_remis')) AS back,
                             SUM(status IN ('en_cours','vu_caisse')) AS open, SUM(alerted_at IS NOT NULL) AS alerts, SUM(status = 'alerte' AND reviewed_at IS NULL) AS to_check,
                             SUM(status IN ('fausse_alerte','verifie_encaisse','verifie_remis') AND reviewed_at IS NOT NULL AND alerted_at IS NOT NULL) AS false_alerts, SUM(status = 'perte') AS losses,
                             SUM(reviewed_at IS NOT NULL AND alerted_at IS NOT NULL) AS reviewed
                      FROM vision_journeys WHERE taken_at BETWEEN ? AND ?", $p) ?: [];
        $t = array_map('intval', $t);
        $t['false_rate'] = ($t['reviewed'] ?? 0) > 0 ? (int) round(100 * $t['false_alerts'] / $t['reviewed']) : null;
        return ['cameras' => $byCam, 'totals' => $t];
    }
}
