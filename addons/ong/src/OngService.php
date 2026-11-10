<?php
declare(strict_types=1);

namespace Addons\Ong;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Validator;
use App\Services\Sequence;

/**
 * Module ONG. Règles tenues ici (jamais dans les vues) :
 * - une dépense est imputée à une ligne budgétaire d'une convention ; le budget de la ligne est
 *   contrôlé (dépenses soumises, approuvées, payées + avances de mission non justifiées) ;
 * - circuit : soumise → approuvée → payée ; celui qui saisit n'approuve pas (réglage ong_separation) ;
 * - l'argent ne bouge que par un mouvement de trésorerie, dans la transaction qui met à jour le
 *   solde du compte (verrou FOR UPDATE) ; rien n'est effacé : annulation motivée ;
 * - mission : demande → approbation → avance → départ → rapport → clôture (justification de l'avance).
 */
final class OngService
{
    public const BAILLEUR_KINDS = ['institution' => 'Institution / agence', 'ong' => 'ONG internationale', 'gouvernement' => 'État / collectivité', 'prive' => 'Entreprise / fondation', 'particulier' => 'Particulier', 'autre' => 'Autre'];
    public const PROJET_STATUS = ['preparation' => 'En préparation', 'en_cours' => 'En cours', 'suspendu' => 'Suspendu', 'termine' => 'Terminé'];
    public const CONV_STATUS = ['negociation' => 'En négociation', 'active' => 'Active', 'cloturee' => 'Clôturée'];
    public const FREQUENCIES = ['mensuel' => 'Mensuel', 'trimestriel' => 'Trimestriel', 'semestriel' => 'Semestriel', 'annuel' => 'Annuel', 'final' => 'Rapport final seulement'];
    public const LINE_CATEGORIES = ['personnel' => 'Personnel', 'fonctionnement' => 'Fonctionnement', 'activites' => 'Activités', 'equipement' => 'Équipement', 'deplacements' => 'Déplacements et missions', 'frais_admin' => 'Frais administratifs', 'autre' => 'Autre'];
    public const COMPTE_KINDS = ['banque' => 'Compte bancaire', 'caisse' => 'Caisse', 'mobile' => 'Mobile money'];
    public const DEP_STATUS = ['soumise' => 'Soumise', 'approuvee' => 'Approuvée', 'payee' => 'Payée', 'rejetee' => 'Rejetée', 'annulee' => 'Annulée'];
    public const DEP_COLORS = ['soumise' => 'warning', 'approuvee' => 'info', 'payee' => 'success', 'rejetee' => 'danger', 'annulee' => 'secondary'];
    public const MV_TYPES = ['fonds_bailleur' => 'Fonds reçus du bailleur', 'autre_recette' => 'Autre recette', 'depense' => 'Paiement de dépense', 'avance_mission' => 'Avance de mission',
        'retour_avance' => 'Retour d\'avance de mission', 'virement_sortant' => 'Virement vers un autre compte', 'virement_entrant' => 'Virement reçu', 'ajustement' => 'Ajustement de solde'];
    public const MISSION_STATUS = ['demandee' => 'Demandée', 'approuvee' => 'Approuvée', 'en_cours' => 'En cours', 'terminee' => 'Terminée (à justifier)', 'cloturee' => 'Clôturée', 'annulee' => 'Annulée'];
    public const MISSION_COLORS = ['demandee' => 'warning', 'approuvee' => 'info', 'en_cours' => 'primary', 'terminee' => 'dark', 'cloturee' => 'success', 'annulee' => 'secondary'];
    public const PERSONNEL_KINDS = ['salarie' => 'Salarié', 'consultant' => 'Consultant', 'volontaire' => 'Volontaire', 'stagiaire' => 'Stagiaire', 'journalier' => 'Journalier'];
    public const VEHICULE_KINDS = ['voiture' => 'Voiture', '4x4' => '4x4', 'moto' => 'Moto', 'camion' => 'Camion', 'autre' => 'Autre'];
    public const VEHICULE_STATUS = ['disponible' => 'Disponible', 'en_mission' => 'En mission', 'panne' => 'En panne', 'reforme' => 'Réformé'];
    public const EQUIP_STATES = ['bon' => 'Bon état', 'moyen' => 'État moyen', 'mauvais' => 'Mauvais état', 'hors_service' => 'Hors service', 'cede' => 'Cédé / rétrocédé'];
    public const STOCK_TYPES = ['achat' => 'Achat', 'don' => 'Don reçu', 'distribution' => 'Distribution', 'perte' => 'Perte / casse', 'ajustement' => 'Ajustement'];

    /** Fiches simples : table => [champs, champs obligatoires, listes fermées, unique, libellé]. */
    private const SIMPLE = [
        'bailleur' => ['ong_bailleurs', ['name', 'kind', 'country', 'contact', 'phone', 'email', 'note', 'active'], ['name'], ['kind' => self::BAILLEUR_KINDS], 'name', 'Un bailleur porte déjà ce nom.'],
        'projet' => ['ong_projets', ['code', 'name', 'sector', 'zone', 'start_date', 'end_date', 'manager', 'status', 'description'], ['code', 'name'], ['status' => self::PROJET_STATUS], 'code', 'Un projet porte déjà ce code.'],
        'compte' => ['ong_comptes', ['name', 'kind', 'bank_name', 'account_number', 'convention_id', 'active'], ['name'], ['kind' => self::COMPTE_KINDS], 'name', 'Un compte porte déjà ce nom.'],
        'personnel' => ['ong_personnel', ['matricule', 'full_name', 'position', 'kind', 'projet_id', 'phone', 'start_date', 'end_date', 'monthly_cost', 'active'], ['matricule', 'full_name'], ['kind' => self::PERSONNEL_KINDS], 'matricule', 'Ce matricule est déjà utilisé.'],
        'vehicule' => ['ong_vehicules', ['plate', 'label', 'kind', 'projet_id', 'odometer', 'status', 'note'], ['plate', 'label'], ['kind' => self::VEHICULE_KINDS, 'status' => self::VEHICULE_STATUS], 'plate', 'Cette immatriculation existe déjà.'],
        'equipement' => ['ong_equipements', ['code', 'label', 'category', 'acquired_on', 'value', 'convention_id', 'location', 'holder', 'state', 'note'], ['code', 'label'], ['state' => self::EQUIP_STATES], 'code', 'Ce code d\'inventaire existe déjà.'],
        'article' => ['ong_articles', ['name', 'unit', 'alert'], ['name'], [], 'name', 'Cet article existe déjà.'],
    ];
    private const DATES = ['start_date', 'end_date', 'acquired_on'];
    private const NUMBERS = ['monthly_cost', 'value', 'alert', 'odometer'];
    private const REFS = ['projet_id', 'convention_id'];

    public static function num(mixed $v): float
    {
        if (is_string($v)) {
            $v = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], $v);
        }
        return is_numeric($v) ? round((float) $v, 2) : 0.0;
    }

    private static function date(mixed $v, string $label, bool $required = false): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            if ($required) {
                throw new BusinessException("$label : date obligatoire.");
            }
            return null;
        }
        if (!Validator::isDate($v)) {
            throw new BusinessException("$label : date invalide.");
        }
        return $v;
    }

    /** Création ou modification d'une fiche simple (bailleur, projet, compte, personnel, véhicule, équipement, article). */
    public static function saveSimple(string $entity, array $in, ?int $id = null): int
    {
        if (!isset(self::SIMPLE[$entity])) {
            throw new BusinessException('Fiche inconnue.');
        }
        [$table, $fields, $required, $enums, $unique, $dupMsg] = self::SIMPLE[$entity];
        $data = [];
        foreach ($fields as $f) {
            $v = $in[$f] ?? null;
            if ($f === 'active') {
                $data[$f] = !empty($v) ? 1 : 0;
            } elseif (isset($enums[$f])) {
                $v = (string) ($v ?? array_key_first($enums[$f]));
                if (!isset($enums[$f][$v])) {
                    throw new BusinessException('Valeur inconnue pour « ' . $f . ' ».');
                }
                $data[$f] = $v;
            } elseif (in_array($f, self::DATES, true)) {
                $data[$f] = self::date($v, 'Date');
            } elseif (in_array($f, self::NUMBERS, true)) {
                $n = self::num($v);
                if ($n < 0) {
                    throw new BusinessException('Un montant ne peut pas être négatif.');
                }
                $data[$f] = $f === 'odometer' ? (int) $n : $n;
            } elseif (in_array($f, self::REFS, true)) {
                $data[$f] = (int) $v > 0 ? (int) $v : null;
            } else {
                $v = trim((string) $v);
                $data[$f] = $v === '' ? null : mb_substr($v, 0, $f === 'description' ? 5000 : 250);
            }
        }
        foreach ($required as $f) {
            if (($data[$f] ?? null) === null) {
                throw new BusinessException('Renseignez les champs obligatoires.');
            }
        }
        if (isset($data['start_date'], $data['end_date']) && $data['end_date'] < $data['start_date']) {
            throw new BusinessException('La date de fin est avant la date de début.');
        }
        if (DB::value("SELECT id FROM `$table` WHERE `$unique` = ? AND id <> ?", [$data[$unique], (int) $id])) {
            throw new BusinessException($dupMsg);
        }
        if ($entity === 'vehicule' && $id) {
            unset($data['odometer']); // le compteur n'avance que par le carnet de bord
            if (($data['status'] ?? '') !== 'en_mission' && DB::value("SELECT id FROM ong_missions WHERE vehicule_id = ? AND status = 'en_cours'", [$id])) {
                throw new BusinessException('Ce véhicule est en mission : terminez la mission d\'abord.');
            }
        }
        if ($id) {
            if (!DB::value("SELECT id FROM `$table` WHERE id = ?", [$id])) {
                throw new BusinessException('Fiche introuvable.');
            }
            DB::update($table, $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert($table, $data);
        }
        Audit::log('ong.' . $entity . '.save', $table, $id, ['name' => $data[$unique]]);
        return $id;
    }

    // ------------------------------------------------------------------ conventions et budget

    public static function saveConvention(array $in, ?int $id = null): int
    {
        $number = trim((string) ($in['number'] ?? ''));
        $title = trim((string) ($in['title'] ?? ''));
        if ($number === '' || $title === '') {
            throw new BusinessException('Numéro et intitulé de la convention obligatoires.');
        }
        $b = (int) ($in['bailleur_id'] ?? 0);
        $p = (int) ($in['projet_id'] ?? 0);
        if (!DB::value('SELECT id FROM ong_bailleurs WHERE id = ?', [$b]) || !DB::value('SELECT id FROM ong_projets WHERE id = ?', [$p])) {
            throw new BusinessException('Choisissez le bailleur et le projet.');
        }
        $start = self::date($in['start_date'] ?? '', 'Début', true);
        $end = self::date($in['end_date'] ?? '', 'Fin', true);
        if ($end < $start) {
            throw new BusinessException('La date de fin est avant la date de début.');
        }
        $amount = self::num($in['amount'] ?? 0);
        $over = self::num($in['overhead_rate'] ?? 0);
        if ($amount <= 0) {
            throw new BusinessException('Montant de la convention obligatoire.');
        }
        if ($over < 0 || $over > 100) {
            throw new BusinessException('Taux de frais administratifs entre 0 et 100 %.');
        }
        $status = (string) ($in['status'] ?? 'active');
        $freq = (string) ($in['report_frequency'] ?? 'trimestriel');
        if (!isset(self::CONV_STATUS[$status]) || !isset(self::FREQUENCIES[$freq])) {
            throw new BusinessException('Statut ou périodicité inconnus.');
        }
        if (DB::value('SELECT id FROM ong_conventions WHERE number = ? AND id <> ?', [$number, (int) $id])) {
            throw new BusinessException('Une convention porte déjà ce numéro.');
        }
        $data = ['number' => mb_substr($number, 0, 60), 'bailleur_id' => $b, 'projet_id' => $p, 'title' => mb_substr($title, 0, 180), 'amount' => $amount, 'start_date' => $start, 'end_date' => $end,
            'report_frequency' => $freq, 'overhead_rate' => $over, 'status' => $status, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null];
        if ($id) {
            $budget = (float) DB::value('SELECT COALESCE(SUM(budget), 0) FROM ong_lignes WHERE convention_id = ?', [$id]);
            if ($amount < $budget) {
                throw new BusinessException('Le montant de la convention (' . money($amount) . ') est inférieur au total de ses lignes budgétaires (' . money($budget) . ').');
            }
            DB::update('ong_conventions', $data, 'id = ?', [$id]);
        } else {
            $id = DB::insert('ong_conventions', $data);
        }
        Audit::log('ong.convention.save', 'ong_conventions', $id, ['number' => $number, 'amount' => $amount]);
        return $id;
    }

    public static function convention(int $id): array
    {
        $c = DB::one('SELECT c.*, b.name AS bailleur, p.code AS projet_code, p.name AS projet FROM ong_conventions c JOIN ong_bailleurs b ON b.id = c.bailleur_id JOIN ong_projets p ON p.id = c.projet_id WHERE c.id = ?', [$id]);
        if (!$c) {
            throw new BusinessException('Convention introuvable.');
        }
        return $c;
    }

    /** Ligne budgétaire : le total des lignes ne dépasse jamais le montant de la convention. */
    public static function saveLigne(int $conventionId, array $in, ?int $id = null): int
    {
        $c = self::convention($conventionId);
        $code = trim((string) ($in['code'] ?? ''));
        $label = trim((string) ($in['label'] ?? ''));
        $cat = (string) ($in['category'] ?? 'activites');
        $budget = self::num($in['budget'] ?? 0);
        if ($code === '' || $label === '' || !isset(self::LINE_CATEGORIES[$cat])) {
            throw new BusinessException('Code, libellé et catégorie de la ligne obligatoires.');
        }
        if ($budget <= 0) {
            throw new BusinessException('Budget de la ligne obligatoire.');
        }
        if (DB::value('SELECT id FROM ong_lignes WHERE convention_id = ? AND code = ? AND id <> ?', [$conventionId, $code, (int) $id])) {
            throw new BusinessException('Une ligne porte déjà ce code dans cette convention.');
        }
        $others = (float) DB::value('SELECT COALESCE(SUM(budget), 0) FROM ong_lignes WHERE convention_id = ? AND id <> ?', [$conventionId, (int) $id]);
        if ($others + $budget > (float) $c['amount'] + 0.004) {
            throw new BusinessException('Le total des lignes (' . money($others + $budget) . ') dépasserait le montant de la convention (' . money($c['amount']) . ').');
        }
        $data = ['convention_id' => $conventionId, 'code' => mb_substr($code, 0, 20), 'label' => mb_substr($label, 0, 150), 'category' => $cat, 'budget' => $budget];
        if ($id) {
            $used = self::engaged($id);
            if ($budget < $used) {
                throw new BusinessException('Budget inférieur à ce qui est déjà engagé sur cette ligne (' . money($used) . ').');
            }
            DB::update('ong_lignes', $data, 'id = ? AND convention_id = ?', [$id, $conventionId]);
        } else {
            $id = DB::insert('ong_lignes', $data);
        }
        Audit::log('ong.ligne.save', 'ong_lignes', $id, ['code' => $code, 'budget' => $budget]);
        return $id;
    }

    /** Engagé sur une ligne : dépenses soumises, approuvées et payées + avances de mission non encore justifiées. */
    public static function engaged(int $ligneId, int $exceptDepense = 0, int $exceptMission = 0): float
    {
        $d = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM ong_depenses WHERE ligne_id = ? AND status IN ('soumise','approuvee','payee') AND id <> ?", [$ligneId, $exceptDepense]);
        $m = (float) DB::value("SELECT COALESCE(SUM(GREATEST(advance, estimate)), 0) FROM ong_missions WHERE ligne_id = ? AND status IN ('approuvee','en_cours','terminee') AND id <> ?", [$ligneId, $exceptMission]);
        return round($d + $m, 2);
    }

    /** Suivi budgétaire d'une convention, ligne par ligne. */
    public static function budget(int $conventionId): array
    {
        $rows = DB::all("SELECT l.*,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.ligne_id = l.id AND d.status = 'payee') AS paid,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.ligne_id = l.id AND d.status IN ('soumise','approuvee')) AS pending,
              (SELECT COALESCE(SUM(GREATEST(advance, estimate)), 0) FROM ong_missions m WHERE m.ligne_id = l.id AND m.status IN ('approuvee','en_cours','terminee')) AS missions
            FROM ong_lignes l WHERE l.convention_id = ? ORDER BY l.code", [$conventionId]);
        $tot = ['budget' => 0.0, 'paid' => 0.0, 'engaged' => 0.0, 'available' => 0.0];
        foreach ($rows as &$r) {
            $r['engaged'] = (float) $r['pending'] + (float) $r['missions'];
            $r['available'] = (float) $r['budget'] - (float) $r['paid'] - $r['engaged'];
            $r['rate'] = (float) $r['budget'] > 0 ? round(((float) $r['paid'] + $r['engaged']) * 100 / (float) $r['budget'], 1) : 0.0;
            foreach (['budget', 'paid', 'engaged', 'available'] as $k) {
                $tot[$k] += (float) $r[$k];
            }
        }
        unset($r);
        $tot['rate'] = $tot['budget'] > 0 ? round(($tot['paid'] + $tot['engaged']) * 100 / $tot['budget'], 1) : 0.0;
        $tot['received'] = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM ong_mouvements WHERE convention_id = ? AND type = 'fonds_bailleur' AND cancelled_at IS NULL", [$conventionId]);
        $tot['cash'] = $tot['received'] - $tot['paid'];
        return ['lines' => $rows, 'total' => $tot];
    }

    // ------------------------------------------------------------------ trésorerie

    private static function lockCompte(int $id): array
    {
        $c = DB::one('SELECT * FROM ong_comptes WHERE id = ? FOR UPDATE', [$id]);
        if (!$c) {
            throw new BusinessException('Compte introuvable.');
        }
        if (!(int) $c['active']) {
            throw new BusinessException('Ce compte est désactivé.');
        }
        return $c;
    }

    /** Écrit un mouvement et met à jour le solde (à appeler dans une transaction). */
    private static function move(int $compteId, int $direction, float $amount, string $type, string $date, array $extra = []): int
    {
        if ($amount <= 0) {
            throw new BusinessException('Montant obligatoire.');
        }
        $c = self::lockCompte($compteId);
        $new = round((float) $c['balance'] + $direction * $amount, 2);
        if ($new < 0) {
            throw new BusinessException('Solde insuffisant sur « ' . $c['name'] . ' » : ' . money($c['balance']) . ' disponibles, ' . money($amount) . ' demandés.');
        }
        DB::update('ong_comptes', ['balance' => $new], 'id = ?', [$compteId]);
        return DB::insert('ong_mouvements', array_merge(['number' => Sequence::next('ong_tresorerie'), 'compte_id' => $compteId, 'direction' => $direction, 'amount' => $amount,
            'op_date' => $date, 'type' => $type, 'user_id' => Auth::id()], $extra));
    }

    /** Fonds reçus d'un bailleur (tranche), autre recette ou ajustement. */
    public static function receive(array $in): int
    {
        $type = (string) ($in['type'] ?? 'fonds_bailleur');
        if (!in_array($type, ['fonds_bailleur', 'autre_recette'], true)) {
            throw new BusinessException('Type de recette inconnu.');
        }
        $conv = (int) ($in['convention_id'] ?? 0);
        if ($type === 'fonds_bailleur') {
            self::convention($conv);
        }
        $date = self::date($in['op_date'] ?? date('Y-m-d'), 'Date', true);
        return DB::transaction(function () use ($in, $type, $conv, $date) {
            $id = self::move((int) ($in['compte_id'] ?? 0), 1, self::num($in['amount'] ?? 0), $type, $date, ['convention_id' => $conv ?: null,
                'reference' => mb_substr(trim((string) ($in['reference'] ?? '')), 0, 80) ?: null, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255) ?: null]);
            Audit::log('ong.fonds.recu', 'ong_mouvements', $id, ['amount' => self::num($in['amount'] ?? 0), 'convention' => $conv]);
            return $id;
        });
    }

    public static function transfer(int $from, int $to, float $amount, string $date, string $note = ''): void
    {
        if ($from === $to) {
            throw new BusinessException('Choisissez deux comptes différents.');
        }
        $date = self::date($date, 'Date', true);
        DB::transaction(function () use ($from, $to, $amount, $date, $note) {
            $a = self::move($from, -1, $amount, 'virement_sortant', $date, ['description' => mb_substr($note, 0, 255) ?: null]);
            $b = self::move($to, 1, $amount, 'virement_entrant', $date, ['description' => mb_substr($note, 0, 255) ?: null, 'transfer_id' => $a]);
            DB::update('ong_mouvements', ['transfer_id' => $b], 'id = ?', [$a]);
            Audit::log('ong.virement', 'ong_mouvements', $a, ['from' => $from, 'to' => $to, 'amount' => $amount]);
        });
    }

    /** Annule un mouvement manuel (fonds reçus, recette, virement) : le solde est rétabli, la ligne reste. */
    public static function cancelMovement(int $id, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Motif de l\'annulation obligatoire.');
        }
        DB::transaction(function () use ($id, $reason) {
            $m = DB::one('SELECT * FROM ong_mouvements WHERE id = ? FOR UPDATE', [$id]);
            if (!$m || $m['cancelled_at']) {
                throw new BusinessException('Mouvement introuvable ou déjà annulé.');
            }
            if (!in_array($m['type'], ['fonds_bailleur', 'autre_recette', 'virement_sortant', 'virement_entrant'], true)) {
                throw new BusinessException('Ce mouvement vient d\'une dépense ou d\'une mission : annulez la dépense ou la mission.');
            }
            foreach (array_filter([$m, $m['transfer_id'] ? DB::one('SELECT * FROM ong_mouvements WHERE id = ? FOR UPDATE', [$m['transfer_id']]) : null]) as $x) {
                self::reverse($x, $reason);
            }
            Audit::log('ong.mouvement.annule', 'ong_mouvements', $id, ['reason' => $reason]);
        });
    }

    private static function reverse(array $m, string $reason): void
    {
        $c = DB::one('SELECT * FROM ong_comptes WHERE id = ? FOR UPDATE', [$m['compte_id']]);
        $new = round((float) $c['balance'] - (int) $m['direction'] * (float) $m['amount'], 2);
        if ($new < 0) {
            throw new BusinessException('Annulation impossible : le solde de « ' . $c['name'] . ' » deviendrait négatif (l\'argent a déjà été utilisé).');
        }
        DB::update('ong_comptes', ['balance' => $new], 'id = ?', [$c['id']]);
        DB::update('ong_mouvements', ['cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$m['id']]);
    }

    // ------------------------------------------------------------------ dépenses

    private static function ligneOf(int $ligneId, int $conventionId): array
    {
        $l = DB::one('SELECT l.*, c.projet_id, c.status AS conv_status, c.start_date, c.end_date, c.number AS conv_number FROM ong_lignes l JOIN ong_conventions c ON c.id = l.convention_id WHERE l.id = ? AND l.convention_id = ?', [$ligneId, $conventionId]);
        if (!$l) {
            throw new BusinessException('Choisissez la convention et sa ligne budgétaire.');
        }
        if ($l['conv_status'] !== 'active') {
            throw new BusinessException('La convention ' . $l['conv_number'] . ' n\'est pas active.');
        }
        return $l;
    }

    private static function checkBudget(array $ligne, float $amount, int $exceptDepense = 0, int $exceptMission = 0): void
    {
        $free = round((float) $ligne['budget'] - self::engaged((int) $ligne['id'], $exceptDepense, $exceptMission), 2);
        if ($amount > $free + 0.004 && setting('ong_depassement_ligne', '0') !== '1') {
            throw new BusinessException('Dépassement de budget : il reste ' . money(max(0, $free)) . ' sur la ligne ' . $ligne['code'] . ' « ' . $ligne['label'] . ' », pour ' . money($amount) . ' demandés.');
        }
    }

    public static function submitDepense(array $in): int
    {
        $conv = (int) ($in['convention_id'] ?? 0);
        $ligne = self::ligneOf((int) ($in['ligne_id'] ?? 0), $conv);
        $amount = self::num($in['amount'] ?? 0);
        $date = self::date($in['exp_date'] ?? date('Y-m-d'), 'Date de la dépense', true);
        $payee = trim((string) ($in['payee'] ?? ''));
        $desc = trim((string) ($in['description'] ?? ''));
        if ($amount <= 0 || $payee === '' || mb_strlen($desc) < 3) {
            throw new BusinessException('Montant, bénéficiaire du paiement et objet de la dépense obligatoires.');
        }
        if ($date < $ligne['start_date'] || $date > $ligne['end_date']) {
            throw new BusinessException('La date est hors de la période de la convention (' . fdate($ligne['start_date'], false) . ' → ' . fdate($ligne['end_date'], false) . ') : dépense non éligible.');
        }
        return DB::transaction(function () use ($ligne, $conv, $amount, $date, $payee, $desc, $in) {
            DB::one('SELECT id FROM ong_lignes WHERE id = ? FOR UPDATE', [$ligne['id']]);
            self::checkBudget($ligne, $amount);
            $id = DB::insert('ong_depenses', ['number' => Sequence::next('ong_depense'), 'exp_date' => $date, 'projet_id' => $ligne['projet_id'], 'convention_id' => $conv, 'ligne_id' => $ligne['id'],
                'amount' => $amount, 'payee' => mb_substr($payee, 0, 150), 'description' => mb_substr($desc, 0, 255), 'piece' => mb_substr(trim((string) ($in['piece'] ?? '')), 0, 80) ?: null,
                'created_by' => Auth::id()]);
            Audit::log('ong.depense.soumise', 'ong_depenses', $id, ['amount' => $amount, 'ligne' => $ligne['code']]);
            return $id;
        });
    }

    private static function lockDepense(int $id): array
    {
        $d = DB::one('SELECT * FROM ong_depenses WHERE id = ? FOR UPDATE', [$id]);
        if (!$d) {
            throw new BusinessException('Dépense introuvable.');
        }
        return $d;
    }

    private static function separation(int $author, string $what): void
    {
        if (setting('ong_separation', '1') === '1' && $author === (int) Auth::id()) {
            throw new BusinessException("Séparation des tâches : vous avez saisi $what, une autre personne doit l'approuver.");
        }
    }

    public static function approveDepense(int $id): void
    {
        DB::transaction(function () use ($id) {
            $d = self::lockDepense($id);
            if ($d['status'] !== 'soumise') {
                throw new BusinessException('Seule une dépense soumise peut être approuvée.');
            }
            self::separation((int) $d['created_by'], 'cette dépense');
            DB::update('ong_depenses', ['status' => 'approuvee', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            Audit::log('ong.depense.approuvee', 'ong_depenses', $id, ['amount' => $d['amount']]);
        });
    }

    public static function rejectDepense(int $id, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Motif du rejet obligatoire.');
        }
        DB::transaction(function () use ($id, $reason) {
            $d = self::lockDepense($id);
            if (!in_array($d['status'], ['soumise', 'approuvee'], true)) {
                throw new BusinessException('Cette dépense ne peut plus être rejetée.');
            }
            DB::update('ong_depenses', ['status' => 'rejetee', 'reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
            Audit::log('ong.depense.rejetee', 'ong_depenses', $id, ['reason' => $reason]);
        });
    }

    public static function payDepense(int $id, int $compteId, string $date = ''): void
    {
        $date = self::date($date ?: date('Y-m-d'), 'Date du paiement', true);
        DB::transaction(function () use ($id, $compteId, $date) {
            $d = self::lockDepense($id);
            if ($d['status'] !== 'approuvee') {
                throw new BusinessException('La dépense doit être approuvée avant d\'être payée.');
            }
            self::move($compteId, -1, (float) $d['amount'], 'depense', $date, ['convention_id' => $d['convention_id'], 'depense_id' => $id, 'reference' => $d['piece'], 'description' => mb_substr($d['payee'] . ' — ' . $d['description'], 0, 255)]);
            DB::update('ong_depenses', ['status' => 'payee', 'compte_id' => $compteId, 'paid_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            Audit::log('ong.depense.payee', 'ong_depenses', $id, ['amount' => $d['amount'], 'compte' => $compteId]);
        });
    }

    /** Annulation d'une dépense payée : le paiement est contre-passé, la dépense reste visible avec son motif. */
    public static function cancelDepense(int $id, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Motif de l\'annulation obligatoire.');
        }
        DB::transaction(function () use ($id, $reason) {
            $d = self::lockDepense($id);
            if ($d['status'] !== 'payee') {
                throw new BusinessException('Seule une dépense payée s\'annule ; une dépense non payée se rejette.');
            }
            if ($d['mission_id']) {
                throw new BusinessException('Cette dépense justifie une mission clôturée : elle ne s\'annule pas ici.');
            }
            foreach (DB::all('SELECT * FROM ong_mouvements WHERE depense_id = ? AND cancelled_at IS NULL FOR UPDATE', [$id]) as $m) {
                self::reverse($m, $reason);
            }
            DB::update('ong_depenses', ['status' => 'annulee', 'reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
            Audit::log('ong.depense.annulee', 'ong_depenses', $id, ['reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ missions terrain

    /** @param array<int, array{personnel_id:int, per_diem:float, days:float}> $membres */
    public static function requestMission(array $in, array $membres): int
    {
        $conv = (int) ($in['convention_id'] ?? 0);
        $ligne = self::ligneOf((int) ($in['ligne_id'] ?? 0), $conv);
        $title = trim((string) ($in['title'] ?? ''));
        $dest = trim((string) ($in['destination'] ?? ''));
        $start = self::date($in['start_date'] ?? '', 'Départ', true);
        $end = self::date($in['end_date'] ?? '', 'Retour', true);
        $chef = (int) ($in['chef_id'] ?? 0);
        if ($title === '' || $dest === '') {
            throw new BusinessException('Objet et destination de la mission obligatoires.');
        }
        if ($end < $start) {
            throw new BusinessException('La date de retour est avant la date de départ.');
        }
        if (!DB::value('SELECT id FROM ong_personnel WHERE id = ? AND active = 1', [$chef])) {
            throw new BusinessException('Choisissez le chef de mission.');
        }
        $veh = (int) ($in['vehicule_id'] ?? 0) ?: null;
        if ($veh && !DB::value("SELECT id FROM ong_vehicules WHERE id = ? AND status IN ('disponible','en_mission')", [$veh])) {
            throw new BusinessException('Ce véhicule n\'est pas disponible (panne ou réforme).');
        }
        $rows = []; $estimate = self::num($in['other_costs'] ?? 0);
        foreach ($membres as $m) {
            $pid = (int) ($m['personnel_id'] ?? 0);
            if (!$pid || isset($rows[$pid])) {
                continue;
            }
            if (!DB::value('SELECT id FROM ong_personnel WHERE id = ? AND active = 1', [$pid])) {
                throw new BusinessException('Membre de mission inconnu.');
            }
            $pd = self::num($m['per_diem'] ?? 0); $days = round((float) self::num($m['days'] ?? 0), 1);
            if ($pd < 0 || $days < 0) {
                throw new BusinessException('Per diem et nombre de jours : valeurs positives.');
            }
            $rows[$pid] = ['personnel_id' => $pid, 'per_diem' => $pd, 'days' => $days];
            $estimate += $pd * $days;
        }
        if (!isset($rows[$chef])) {
            $rows[$chef] = ['personnel_id' => $chef, 'per_diem' => 0, 'days' => 0];
        }
        return DB::transaction(function () use ($in, $ligne, $conv, $title, $dest, $start, $end, $chef, $veh, $rows, $estimate) {
            $id = DB::insert('ong_missions', ['number' => Sequence::next('ong_mission'), 'projet_id' => $ligne['projet_id'], 'convention_id' => $conv, 'ligne_id' => $ligne['id'],
                'title' => mb_substr($title, 0, 180), 'destination' => mb_substr($dest, 0, 180), 'objective' => trim((string) ($in['objective'] ?? '')) ?: null, 'start_date' => $start, 'end_date' => $end,
                'chef_id' => $chef, 'vehicule_id' => $veh, 'estimate' => round($estimate, 2), 'created_by' => Auth::id()]);
            foreach ($rows as $r) {
                DB::insert('ong_mission_membres', ['mission_id' => $id] + $r);
            }
            Audit::log('ong.mission.demandee', 'ong_missions', $id, ['estimate' => $estimate]);
            return $id;
        });
    }

    private static function lockMission(int $id): array
    {
        $m = DB::one('SELECT * FROM ong_missions WHERE id = ? FOR UPDATE', [$id]);
        if (!$m) {
            throw new BusinessException('Mission introuvable.');
        }
        return $m;
    }

    public static function approveMission(int $id): void
    {
        DB::transaction(function () use ($id) {
            $m = self::lockMission($id);
            if ($m['status'] !== 'demandee') {
                throw new BusinessException('Seule une mission demandée peut être approuvée.');
            }
            self::separation((int) $m['created_by'], 'cette demande de mission');
            $ligne = self::ligneOf((int) $m['ligne_id'], (int) $m['convention_id']);
            DB::one('SELECT id FROM ong_lignes WHERE id = ? FOR UPDATE', [$ligne['id']]);
            self::checkBudget($ligne, (float) $m['estimate'], 0, $id);
            DB::update('ong_missions', ['status' => 'approuvee', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            Audit::log('ong.mission.approuvee', 'ong_missions', $id, ['estimate' => $m['estimate']]);
        });
    }

    /** Avance de mission : sort du compte ; reste « à justifier » jusqu'à la clôture. */
    public static function advanceMission(int $id, int $compteId, float $amount, string $date = ''): void
    {
        $date = self::date($date ?: date('Y-m-d'), 'Date', true);
        DB::transaction(function () use ($id, $compteId, $amount, $date) {
            $m = self::lockMission($id);
            if (!in_array($m['status'], ['approuvee', 'en_cours'], true)) {
                throw new BusinessException('L\'avance se verse sur une mission approuvée ou en cours.');
            }
            $ligne = self::ligneOf((int) $m['ligne_id'], (int) $m['convention_id']);
            DB::one('SELECT id FROM ong_lignes WHERE id = ? FOR UPDATE', [$ligne['id']]);
            $total = round((float) $m['advance'] + $amount, 2);
            self::checkBudget($ligne, max($total, (float) $m['estimate']), 0, $id);
            self::move($compteId, -1, $amount, 'avance_mission', $date, ['convention_id' => $m['convention_id'], 'mission_id' => $id, 'description' => 'Avance ' . $m['number'] . ' — ' . mb_substr($m['title'], 0, 200)]);
            DB::update('ong_missions', ['advance' => $total], 'id = ?', [$id]);
            Audit::log('ong.mission.avance', 'ong_missions', $id, ['amount' => $amount]);
        });
    }

    public static function startMission(int $id): void
    {
        DB::transaction(function () use ($id) {
            $m = self::lockMission($id);
            if ($m['status'] !== 'approuvee') {
                throw new BusinessException('La mission doit être approuvée avant le départ.');
            }
            if ($m['vehicule_id']) {
                $v = DB::one('SELECT * FROM ong_vehicules WHERE id = ? FOR UPDATE', [$m['vehicule_id']]);
                if ($v['status'] !== 'disponible') {
                    throw new BusinessException('Le véhicule ' . $v['plate'] . ' n\'est pas disponible (' . self::VEHICULE_STATUS[$v['status']] . ').');
                }
                DB::update('ong_vehicules', ['status' => 'en_mission'], 'id = ?', [$v['id']]);
            }
            DB::update('ong_missions', ['status' => 'en_cours'], 'id = ?', [$id]);
            Audit::log('ong.mission.depart', 'ong_missions', $id);
        });
    }

    /** Retour de mission : rapport obligatoire ; le véhicule redevient disponible. */
    public static function finishMission(int $id, string $report): void
    {
        if (mb_strlen(trim($report)) < 20) {
            throw new BusinessException('Rapport de mission obligatoire (au moins quelques lignes : activités réalisées, résultats, difficultés).');
        }
        DB::transaction(function () use ($id, $report) {
            $m = self::lockMission($id);
            if ($m['status'] !== 'en_cours') {
                throw new BusinessException('Seule une mission en cours peut être terminée.');
            }
            if ($m['vehicule_id']) {
                DB::run("UPDATE ong_vehicules SET status = 'disponible' WHERE id = ? AND status = 'en_mission'", [$m['vehicule_id']]);
            }
            DB::update('ong_missions', ['status' => 'terminee', 'report' => trim($report)], 'id = ?', [$id]);
            Audit::log('ong.mission.retour', 'ong_missions', $id);
        });
    }

    /**
     * Clôture : le montant justifié (pièces) devient une dépense payée sur la ligne de la mission.
     * Justifié < avance : le reliquat est reversé sur le compte. Justifié > avance : complément payé.
     */
    public static function closeMission(int $id, float $justified, int $compteId, string $piece = ''): void
    {
        if ($justified < 0) {
            throw new BusinessException('Montant justifié invalide.');
        }
        DB::transaction(function () use ($id, $justified, $compteId, $piece) {
            $m = self::lockMission($id);
            if ($m['status'] !== 'terminee') {
                throw new BusinessException('La mission doit être terminée (rapport remis) avant la clôture.');
            }
            $diff = round((float) $m['advance'] - $justified, 2);
            $today = date('Y-m-d');
            $depId = null;
            if ($justified > 0) {
                $chef = (string) DB::value('SELECT full_name FROM ong_personnel WHERE id = ?', [$m['chef_id']]);
                $depId = DB::insert('ong_depenses', ['number' => Sequence::next('ong_depense'), 'exp_date' => min($today, (string) DB::value('SELECT end_date FROM ong_conventions WHERE id = ?', [$m['convention_id']])),
                    'projet_id' => $m['projet_id'], 'convention_id' => $m['convention_id'], 'ligne_id' => $m['ligne_id'], 'amount' => $justified, 'payee' => $chef,
                    'description' => mb_substr('Frais de mission ' . $m['number'] . ' — ' . $m['title'], 0, 255), 'piece' => mb_substr($piece, 0, 80) ?: null, 'mission_id' => $id, 'status' => 'payee',
                    'compte_id' => $compteId ?: null, 'created_by' => $m['created_by'], 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s'), 'paid_at' => date('Y-m-d H:i:s')]);
            }
            if (abs($diff) >= 0.005) {
                if (!$compteId) {
                    throw new BusinessException($diff > 0 ? 'Choisissez le compte qui reçoit le reliquat de ' . money($diff) . '.' : 'Choisissez le compte qui paie le complément de ' . money(-$diff) . '.');
                }
                self::move($compteId, $diff > 0 ? 1 : -1, abs($diff), $diff > 0 ? 'retour_avance' : 'depense', $today, ['convention_id' => $m['convention_id'], 'mission_id' => $id, 'depense_id' => $diff > 0 ? null : $depId,
                    'description' => ($diff > 0 ? 'Reliquat d\'avance ' : 'Complément de frais ') . $m['number']]);
            }
            DB::update('ong_missions', ['status' => 'cloturee', 'justified' => $justified, 'closed_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            Audit::log('ong.mission.cloturee', 'ong_missions', $id, ['advance' => $m['advance'], 'justified' => $justified]);
        });
    }

    public static function cancelMission(int $id, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new BusinessException('Motif de l\'annulation obligatoire.');
        }
        DB::transaction(function () use ($id, $reason) {
            $m = self::lockMission($id);
            if (!in_array($m['status'], ['demandee', 'approuvee'], true)) {
                throw new BusinessException('Une mission partie ne s\'annule plus : terminez-la puis clôturez-la.');
            }
            if ((float) $m['advance'] > 0) {
                throw new BusinessException('Une avance de ' . money($m['advance']) . ' a été versée : faites partir la mission puis clôturez-la avec 0 justifié pour reverser l\'avance.');
            }
            DB::update('ong_missions', ['status' => 'annulee', 'cancel_reason' => mb_substr($reason, 0, 255)], 'id = ?', [$id]);
            Audit::log('ong.mission.annulee', 'ong_missions', $id, ['reason' => $reason]);
        });
    }

    // ------------------------------------------------------------------ logistique

    /** Carnet de bord : le compteur ne recule jamais. */
    public static function logTrip(array $in): int
    {
        $date = self::date($in['log_date'] ?? date('Y-m-d'), 'Date', true);
        $driver = trim((string) ($in['driver'] ?? ''));
        $s = (int) self::num($in['km_start'] ?? 0); $e = (int) self::num($in['km_end'] ?? 0);
        if ($driver === '') {
            throw new BusinessException('Nom du conducteur obligatoire.');
        }
        return DB::transaction(function () use ($in, $date, $driver, $s, $e) {
            $v = DB::one('SELECT * FROM ong_vehicules WHERE id = ? FOR UPDATE', [(int) ($in['vehicule_id'] ?? 0)]);
            if (!$v) {
                throw new BusinessException('Véhicule introuvable.');
            }
            if ($s < (int) $v['odometer']) {
                throw new BusinessException('Kilométrage de départ (' . $s . ') inférieur au compteur enregistré (' . $v['odometer'] . ' km).');
            }
            if ($e <= $s) {
                throw new BusinessException('Le kilométrage d\'arrivée doit dépasser celui de départ.');
            }
            $id = DB::insert('ong_carnet', ['vehicule_id' => $v['id'], 'log_date' => $date, 'mission_id' => (int) ($in['mission_id'] ?? 0) ?: null, 'driver' => mb_substr($driver, 0, 120),
                'route' => mb_substr(trim((string) ($in['route'] ?? '')), 0, 180) ?: null, 'km_start' => $s, 'km_end' => $e, 'fuel_liters' => max(0, self::num($in['fuel_liters'] ?? 0)),
                'fuel_amount' => max(0, self::num($in['fuel_amount'] ?? 0)), 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id()]);
            DB::update('ong_vehicules', ['odometer' => $e], 'id = ?', [$v['id']]);
            return $id;
        });
    }

    /** Entrée ou sortie de stock (achat, don, distribution aux bénéficiaires, perte). Jamais de stock négatif. */
    public static function stockMove(array $in): int
    {
        $type = (string) ($in['type'] ?? '');
        if (!isset(self::STOCK_TYPES[$type])) {
            throw new BusinessException('Type de mouvement inconnu.');
        }
        $qty = round((float) self::num($in['quantity'] ?? 0), 3);
        if ($qty <= 0) {
            throw new BusinessException('Quantité obligatoire.');
        }
        $dir = in_array($type, ['achat', 'don'], true) ? 1 : ($type === 'ajustement' ? ((string) ($in['sens'] ?? 'plus') === 'moins' ? -1 : 1) : -1);
        $date = self::date($in['mv_date'] ?? date('Y-m-d'), 'Date', true);
        $site = trim((string) ($in['site'] ?? ''));
        if ($type === 'distribution' && $site === '') {
            throw new BusinessException('Distribution : indiquez le site (village, centre, école…).');
        }
        return DB::transaction(function () use ($in, $type, $qty, $dir, $date, $site) {
            $a = DB::one('SELECT * FROM ong_articles WHERE id = ? FOR UPDATE', [(int) ($in['article_id'] ?? 0)]);
            if (!$a) {
                throw new BusinessException('Article introuvable.');
            }
            $new = round((float) $a['stock'] + $dir * $qty, 3);
            if ($new < 0) {
                throw new BusinessException('Stock insuffisant pour « ' . $a['name'] . ' » : ' . qty($a['stock']) . ' ' . $a['unit'] . ' disponibles.');
            }
            DB::update('ong_articles', ['stock' => $new], 'id = ?', [$a['id']]);
            $id = DB::insert('ong_stock_mouvements', ['article_id' => $a['id'], 'direction' => $dir, 'quantity' => $qty, 'balance_after' => $new, 'mv_date' => $date, 'type' => $type,
                'projet_id' => (int) ($in['projet_id'] ?? 0) ?: null, 'site' => mb_substr($site, 0, 150) ?: null, 'beneficiaries' => (int) ($in['beneficiaries'] ?? 0) ?: null,
                'reference' => mb_substr(trim((string) ($in['reference'] ?? '')), 0, 80) ?: null, 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null, 'user_id' => Auth::id()]);
            Audit::log('ong.stock.' . $type, 'ong_stock_mouvements', $id, ['article' => $a['name'], 'qty' => $dir * $qty]);
            return $id;
        });
    }

    public static function saveIndicateur(int $projetId, array $in, ?int $id = null): int
    {
        if (!DB::value('SELECT id FROM ong_projets WHERE id = ?', [$projetId])) {
            throw new BusinessException('Projet introuvable.');
        }
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '') {
            throw new BusinessException('Libellé de l\'indicateur obligatoire.');
        }
        $data = ['projet_id' => $projetId, 'label' => mb_substr($label, 0, 200), 'unit' => mb_substr(trim((string) ($in['unit'] ?? '')), 0, 40) ?: null,
            'target' => max(0, self::num($in['target'] ?? 0)), 'achieved' => max(0, self::num($in['achieved'] ?? 0)), 'updated_at' => date('Y-m-d H:i:s')];
        if ($id) {
            DB::update('ong_indicateurs', $data, 'id = ? AND projet_id = ?', [$id, $projetId]);
            return $id;
        }
        return DB::insert('ong_indicateurs', $data);
    }

    // ------------------------------------------------------------------ tableau de bord

    public static function dashboard(): array
    {
        $days = max(1, (int) setting('ong_alerte_fin_jours', '60'));
        $convs = DB::all("SELECT c.id, c.number, c.title, c.amount, c.end_date, b.name AS bailleur, p.code AS projet_code,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_mouvements m WHERE m.convention_id = c.id AND m.type = 'fonds_bailleur' AND m.cancelled_at IS NULL) AS received,
              (SELECT COALESCE(SUM(amount), 0) FROM ong_depenses d WHERE d.convention_id = c.id AND d.status = 'payee') AS spent
            FROM ong_conventions c JOIN ong_bailleurs b ON b.id = c.bailleur_id JOIN ong_projets p ON p.id = c.projet_id WHERE c.status = 'active' ORDER BY c.end_date");
        return [
            'projets' => (int) DB::value("SELECT COUNT(*) FROM ong_projets WHERE status = 'en_cours'"),
            'conventions' => $convs,
            'budget' => array_sum(array_column($convs, 'amount')),
            'received' => array_sum(array_column($convs, 'received')),
            'spent' => array_sum(array_column($convs, 'spent')),
            'ending' => array_values(array_filter($convs, fn ($c) => $c['end_date'] <= date('Y-m-d', strtotime("+$days days")))),
            'alertDays' => $days,
            'toApprove' => (int) DB::value("SELECT COUNT(*) FROM ong_depenses WHERE status = 'soumise'"),
            'toPay' => DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM ong_depenses WHERE status = 'approuvee'"),
            'missionsToApprove' => (int) DB::value("SELECT COUNT(*) FROM ong_missions WHERE status = 'demandee'"),
            'missions' => DB::all("SELECT m.*, p.full_name AS chef FROM ong_missions m JOIN ong_personnel p ON p.id = m.chef_id WHERE m.status IN ('approuvee','en_cours','terminee') ORDER BY m.start_date"),
            'advances' => (float) DB::value("SELECT COALESCE(SUM(advance), 0) FROM ong_missions WHERE status IN ('approuvee','en_cours','terminee')"),
            'comptes' => DB::all('SELECT * FROM ong_comptes WHERE active = 1 ORDER BY kind, name'),
            'stockAlerts' => DB::all('SELECT * FROM ong_articles WHERE alert > 0 AND stock <= alert ORDER BY name'),
            'staff' => (int) DB::value('SELECT COUNT(*) FROM ong_personnel WHERE active = 1'),
        ];
    }
}
