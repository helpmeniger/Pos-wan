<?php
declare(strict_types=1);

namespace Addons\Ecole;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;
use App\Services\CashService;
use App\Services\Sequence;

/**
 * Inscriptions, frais de scolarité et comptabilité de l'établissement.
 * Toute somme encaissée passe par la session de caisse (même règle que la vente) ;
 * un reçu n'est jamais supprimé : il est annulé (écritures contre-passées).
 */
final class Finance
{
    public const TYPES_INSCRIPTION = ['nouvelle' => 'Nouvelle inscription', 'reinscription' => 'Réinscription', 'redoublement' => 'Redoublement', 'transfert' => 'Transfert (venant d\'un autre établissement)'];

    private static function montant(mixed $v): float
    {
        return round((float) str_replace([' ', ','], ['', '.'], (string) $v), 2);
    }

    // ------------------------------------------------------------------ inscriptions
    /**
     * Inscrit un élève dans une classe de l'année en cours et crée ses échéances :
     * frais d'inscription, scolarité en N tranches (remise déduite), frais annexes.
     */
    public static function inscrire(int $eleveId, int $classeId, array $in): int
    {
        $an = Ecole::anneeCouranteOuErreur();
        $el = Personnes::eleve($eleveId);
        if ($el['statut'] !== 'actif') {
            throw new BusinessException('Cette fiche n\'est pas active (statut : ' . $el['statut'] . ').');
        }
        return DB::transaction(function () use ($an, $el, $classeId, $in) {
            $c = DB::one('SELECT * FROM ecole_classes WHERE id = ? AND annee_id = ? AND active = 1 FOR UPDATE', [$classeId, $an['id']])
                ?? throw new BusinessException('Classe introuvable pour l\'année ' . $an['libelle'] . '.');
            if (DB::value('SELECT 1 FROM ecole_inscriptions WHERE eleve_id = ? AND annee_id = ?', [$el['id'], $an['id']])) {
                throw new BusinessException($el['prenom'] . ' ' . $el['nom'] . ' est déjà inscrit(e) pour ' . $an['libelle'] . '.');
            }
            $effectif = (int) DB::value("SELECT COUNT(*) FROM ecole_inscriptions WHERE classe_id = ? AND statut = 'active'", [$classeId]);
            if ($c['capacite'] && $effectif >= (int) $c['capacite']) {
                throw new BusinessException('Classe complète (' . $effectif . ' / ' . $c['capacite'] . ').');
            }
            $type = isset(self::TYPES_INSCRIPTION[$in['type'] ?? '']) ? $in['type'] : 'nouvelle';
            $remise = max(0, self::montant($in['remise'] ?? 0));
            if ($remise > (float) $c['frais_scolarite']) {
                throw new BusinessException('La remise dépasse les frais de scolarité.');
            }
            $date = Ecole::date($in['date'] ?? null) ? $in['date'] : date('Y-m-d');
            $id = DB::insert('ecole_inscriptions', ['eleve_id' => $el['id'], 'annee_id' => $an['id'], 'classe_id' => $classeId, 'date_inscription' => $date, 'type' => $type,
                'remise' => $remise, 'motif_remise' => $remise > 0 ? (trim(mb_substr((string) ($in['motif_remise'] ?? ''), 0, 150)) ?: null) : null, 'user_id' => Auth::id()]);
            if ((float) $c['frais_inscription'] > 0 && empty($in['sans_frais_inscription'])) {
                DB::insert('ecole_echeances', ['inscription_id' => $id, 'type' => 'inscription', 'libelle' => 'Frais d\'inscription', 'montant' => $c['frais_inscription'], 'date_limite' => $date]);
            }
            $total = round((float) $c['frais_scolarite'] - $remise, 2);
            $n = max(1, (int) $c['nb_tranches']);
            if ($total > 0) {
                $part = floor($total / $n); // montants ronds, la dernière tranche reçoit le reste
                $t0 = max(strtotime($an['debut']), strtotime($date)); $t1 = strtotime($an['fin']);
                for ($k = 1; $k <= $n; $k++) {
                    $m = $k < $n ? $part : round($total - $part * ($n - 1), 2);
                    $due = $k === 1 ? $t0 : (int) ($t0 + ($t1 - $t0) * ($k - 1) / $n);
                    DB::insert('ecole_echeances', ['inscription_id' => $id, 'type' => 'scolarite', 'libelle' => $n > 1 ? "Scolarité – tranche $k / $n" : 'Scolarité', 'montant' => $m, 'date_limite' => date('Y-m-d', $due)]);
                }
            }
            foreach ((array) ($in['frais'] ?? []) as $f) {
                $lib = trim(mb_substr((string) ($f['libelle'] ?? ''), 0, 100));
                $m = self::montant($f['montant'] ?? 0);
                if ($lib !== '' && $m > 0) {
                    DB::insert('ecole_echeances', ['inscription_id' => $id, 'type' => 'autre', 'libelle' => $lib, 'montant' => $m, 'date_limite' => $date]);
                }
            }
            Audit::log('ecole.inscription', null, $id, ['eleve' => $el['matricule'], 'classe' => $c['nom']]);
            return (int) $id;
        });
    }

    /** Ajoute des frais à une inscription (cantine, tenue, transport, examen…). */
    public static function ajouterFrais(int $inscriptionId, string $libelle, mixed $montant, ?string $date): int
    {
        DB::value("SELECT 1 FROM ecole_inscriptions WHERE id = ? AND statut = 'active'", [$inscriptionId]) ?: throw new BusinessException('Inscription introuvable.');
        $libelle = trim(mb_substr($libelle, 0, 100));
        $m = self::montant($montant);
        if ($libelle === '' || $m <= 0) {
            throw new BusinessException('Libellé et montant positif obligatoires.');
        }
        $id = DB::insert('ecole_echeances', ['inscription_id' => $inscriptionId, 'type' => 'autre', 'libelle' => $libelle, 'montant' => $m, 'date_limite' => Ecole::date($date) ? $date : date('Y-m-d')]);
        Audit::log('ecole.frais', null, $inscriptionId, ['libelle' => $libelle, 'montant' => $m]);
        return $id;
    }

    public static function changerClasse(int $inscriptionId, int $classeId): void
    {
        $i = DB::one('SELECT * FROM ecole_inscriptions WHERE id = ?', [$inscriptionId]) ?? throw new BusinessException('Inscription introuvable.');
        DB::value('SELECT 1 FROM ecole_classes WHERE id = ? AND annee_id = ? AND active = 1', [$classeId, $i['annee_id']]) ?: throw new BusinessException('Classe introuvable pour cette année.');
        DB::update('ecole_inscriptions', ['classe_id' => $classeId], 'id = ?', [$inscriptionId]);
        Audit::log('ecole.changement_classe', null, $inscriptionId, ['de' => $i['classe_id'], 'vers' => $classeId]);
    }

    /** Abandon / départ : les montants non encore payés ne sont plus dus (rien n'est effacé). */
    public static function abandon(int $inscriptionId, string $statut): void
    {
        if (!in_array($statut, ['abandon', 'transferee'], true)) {
            throw new BusinessException('Statut inconnu.');
        }
        DB::transaction(function () use ($inscriptionId, $statut) {
            DB::one('SELECT id FROM ecole_inscriptions WHERE id = ? FOR UPDATE', [$inscriptionId]) ?? throw new BusinessException('Inscription introuvable.');
            DB::update('ecole_inscriptions', ['statut' => $statut], 'id = ?', [$inscriptionId]);
            DB::run('UPDATE ecole_echeances SET annule = 1 WHERE inscription_id = ? AND paye = 0', [$inscriptionId]);
            DB::run('UPDATE ecole_echeances SET montant = paye WHERE inscription_id = ? AND paye > 0 AND paye < montant', [$inscriptionId]);
        });
        Audit::log('ecole.inscription_' . $statut, null, $inscriptionId);
    }

    public static function echeances(int $inscriptionId): array
    {
        return DB::all('SELECT *, montant - paye AS reste FROM ecole_echeances WHERE inscription_id = ? AND annule = 0 ORDER BY (type = \'autre\'), date_limite, id', [$inscriptionId]);
    }

    public static function solde(int $inscriptionId): array
    {
        $r = DB::one('SELECT COALESCE(SUM(montant),0) AS du, COALESCE(SUM(paye),0) AS paye, COALESCE(SUM(CASE WHEN date_limite <= CURDATE() THEN montant - paye ELSE 0 END),0) AS echu
                      FROM ecole_echeances WHERE inscription_id = ? AND annule = 0', [$inscriptionId]);
        return ['du' => (float) $r['du'], 'paye' => (float) $r['paye'], 'reste' => round((float) $r['du'] - (float) $r['paye'], 2), 'echu' => round((float) $r['echu'], 2)];
    }

    // ------------------------------------------------------------------ encaissements
    private static function session(string $mode): ?int
    {
        $s = CashService::currentSession(Auth::user());
        if (!$s && setting('require_cash_session', '1') === '1') {
            throw new BusinessException('La caisse est fermée : ouvrez votre caisse (Ma caisse) avant d\'encaisser.');
        }
        return $s ? (int) $s['id'] : null;
    }

    /**
     * Encaissement : réparti sur les échéances choisies (ou les plus anciennes d'abord).
     * Idempotent : un même identifiant de paiement (double clic, réseau coupé) ne crée qu'un reçu.
     */
    public static function encaisser(int $inscriptionId, array $in): array
    {
        $uuid = (string) ($in['uuid'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
            throw new BusinessException('Identifiant de paiement manquant : rechargez la page.');
        }
        if ($dej = DB::one('SELECT id, numero FROM ecole_paiements WHERE uuid = ?', [$uuid])) {
            return ['id' => (int) $dej['id'], 'numero' => $dej['numero'], 'deja' => true];
        }
        $montant = self::montant($in['montant'] ?? 0);
        if ($montant <= 0) {
            throw new BusinessException('Montant à encaisser obligatoire.');
        }
        $methods = payment_methods();
        [$mode, $op] = array_pad(explode('|', (string) ($in['mode'] ?? 'cash'), 2), 2, null);
        if (!isset($methods[$mode])) {
            throw new BusinessException('Mode de paiement non autorisé.');
        }
        $choix = array_values(array_filter(array_map('intval', (array) ($in['echeances'] ?? []))));
        return DB::transaction(function () use ($inscriptionId, $montant, $mode, $op, $choix, $uuid, $in) {
            $ins = DB::one('SELECT i.*, e.matricule, e.nom, e.prenom FROM ecole_inscriptions i JOIN ecole_eleves e ON e.id = i.eleve_id WHERE i.id = ? FOR UPDATE', [$inscriptionId])
                ?? throw new BusinessException('Inscription introuvable.');
            $sql = 'SELECT * FROM ecole_echeances WHERE inscription_id = ? AND annule = 0 AND paye < montant' . ($choix ? ' AND id IN (' . implode(',', $choix) . ')' : '') . ' ORDER BY (type = \'autre\'), date_limite, id FOR UPDATE';
            $ech = DB::all($sql, [$inscriptionId]);
            $reste = array_sum(array_map(fn($e) => (float) $e['montant'] - (float) $e['paye'], $ech));
            if ($montant > round($reste, 2) + 0.001) {
                throw new BusinessException('Le montant (' . money($montant) . ') dépasse le reste à payer' . ($choix ? ' des échéances choisies' : '') . ' (' . money($reste) . ').');
            }
            $session = self::session($mode);
            $numero = Sequence::next('ecole_recu');
            $pid = DB::insert('ecole_paiements', ['numero' => $numero, 'uuid' => $uuid, 'inscription_id' => $inscriptionId, 'eleve_id' => $ins['eleve_id'], 'montant' => $montant,
                'mode' => $mode, 'operateur' => $mode === 'mobile_money' ? mobile_money_provider($op) : null,
                'reference' => trim(mb_substr((string) ($in['reference'] ?? ''), 0, 100)) ?: null, 'cash_session_id' => $session, 'user_id' => Auth::id()]);
            $a = $montant;
            foreach ($ech as $e) {
                if ($a <= 0) {
                    break;
                }
                $p = min($a, round((float) $e['montant'] - (float) $e['paye'], 2));
                DB::insert('ecole_paiement_lignes', ['paiement_id' => $pid, 'echeance_id' => $e['id'], 'montant' => $p]);
                DB::run('UPDATE ecole_echeances SET paye = paye + ? WHERE id = ?', [$p, $e['id']]);
                $a = round($a - $p, 2);
            }
            CashService::movement($session, 'ecole', $mode, $montant, 'ecole_paiement', $pid, 'Scolarité ' . $numero . ' – ' . $ins['prenom'] . ' ' . $ins['nom']);
            Audit::log('ecole.paiement', null, $pid, ['numero' => $numero, 'montant' => $montant, 'eleve' => $ins['matricule']]);
            return ['id' => (int) $pid, 'numero' => $numero];
        });
    }

    /** Annulation d'un reçu (erreur de saisie, remboursement) : écritures contre-passées, traçées. */
    public static function annuler(int $paiementId, string $motif): void
    {
        $motif = trim(mb_substr($motif, 0, 255));
        if (mb_strlen($motif) < 3) {
            throw new BusinessException('Motif d\'annulation obligatoire.');
        }
        DB::transaction(function () use ($paiementId, $motif) {
            $p = DB::one('SELECT * FROM ecole_paiements WHERE id = ? FOR UPDATE', [$paiementId]) ?? throw new BusinessException('Reçu introuvable.');
            if ($p['annule']) {
                throw new BusinessException('Ce reçu est déjà annulé.');
            }
            foreach (DB::all('SELECT * FROM ecole_paiement_lignes WHERE paiement_id = ?', [$paiementId]) as $l) {
                DB::run('UPDATE ecole_echeances SET paye = GREATEST(0, paye - ?) WHERE id = ?', [$l['montant'], $l['echeance_id']]);
            }
            DB::update('ecole_paiements', ['annule' => 1, 'annule_par' => Auth::id(), 'annule_le' => date('Y-m-d H:i:s'), 'motif_annulation' => $motif], 'id = ?', [$paiementId]);
            $session = CashService::currentSession(Auth::user());
            CashService::movement($session ? (int) $session['id'] : null, 'ecole_annulation', $p['mode'], -(float) $p['montant'], 'ecole_paiement', $paiementId, 'Annulation ' . $p['numero'] . ' : ' . $motif);
            Audit::log('ecole.paiement_annule', null, $paiementId, ['numero' => $p['numero'], 'motif' => $motif]);
        });
    }

    // ------------------------------------------------------------------ salaires
    public static function payerSalaire(int $personnelId, string $periode, array $in): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
            throw new BusinessException('Mois invalide (format AAAA-MM).');
        }
        $p = Personnes::personnel($personnelId);
        $montant = self::montant($in['montant'] ?? ($p['salaire'] ?? 0));
        if ($montant <= 0) {
            throw new BusinessException('Montant du salaire obligatoire.');
        }
        if (DB::value('SELECT 1 FROM ecole_salaires WHERE personnel_id = ? AND periode = ? AND annule = 0', [$personnelId, $periode]) && empty($in['complement'])) {
            throw new BusinessException('Le salaire de ' . $periode . ' est déjà payé à ' . $p['prenom'] . ' ' . $p['nom'] . ' (cochez « complément » pour un second versement).');
        }
        $methods = payment_methods();
        $mode = isset($methods[$in['mode'] ?? '']) ? $in['mode'] : 'cash';
        return DB::transaction(function () use ($p, $periode, $montant, $mode, $in) {
            $session = $mode === 'cash' ? self::session($mode) : (($s = CashService::currentSession(Auth::user())) ? (int) $s['id'] : null);
            $numero = Sequence::next('ecole_salaire');
            $id = DB::insert('ecole_salaires', ['numero' => $numero, 'personnel_id' => $p['id'], 'periode' => $periode, 'montant' => $montant, 'mode' => $mode,
                'reference' => trim(mb_substr((string) ($in['reference'] ?? ''), 0, 100)) ?: null, 'note' => trim(mb_substr((string) ($in['note'] ?? ''), 0, 255)) ?: null,
                'cash_session_id' => $session, 'user_id' => Auth::id()]);
            CashService::movement($session, 'ecole_salaire', $mode, -$montant, 'ecole_salaire', $id, 'Salaire ' . $periode . ' – ' . $p['prenom'] . ' ' . $p['nom']);
            Audit::log('ecole.salaire', null, $id, ['personnel' => $p['matricule'], 'periode' => $periode, 'montant' => $montant]);
            return ['id' => (int) $id, 'numero' => $numero];
        });
    }

    public static function annulerSalaire(int $id, string $motif): void
    {
        if (mb_strlen(trim($motif)) < 3) {
            throw new BusinessException('Motif d\'annulation obligatoire.');
        }
        DB::transaction(function () use ($id, $motif) {
            $s = DB::one('SELECT * FROM ecole_salaires WHERE id = ? FOR UPDATE', [$id]) ?? throw new BusinessException('Versement introuvable.');
            if ($s['annule']) {
                throw new BusinessException('Déjà annulé.');
            }
            DB::update('ecole_salaires', ['annule' => 1, 'note' => mb_substr(trim(($s['note'] ? $s['note'] . ' · ' : '') . 'Annulé : ' . $motif), 0, 255)], 'id = ?', [$id]);
            $session = CashService::currentSession(Auth::user());
            CashService::movement($session ? (int) $session['id'] : null, 'ecole_salaire_annule', $s['mode'], (float) $s['montant'], 'ecole_salaire', $id, 'Annulation ' . $s['numero']);
            Audit::log('ecole.salaire_annule', null, $id, ['motif' => $motif]);
        });
    }

    // ------------------------------------------------------------------ rapports
    /** Comptabilité d'une période : recettes (frais) par mode / type / classe, dépenses (salaires + dépenses générales), solde. */
    public static function rapport(string $du, string $au): array
    {
        $a = $du . ' 00:00:00'; $b = $au . ' 23:59:59';
        $recettes = (float) DB::value('SELECT COALESCE(SUM(montant),0) FROM ecole_paiements WHERE annule = 0 AND created_at BETWEEN ? AND ?', [$a, $b]);
        $parMode = DB::all('SELECT mode, COUNT(*) AS n, SUM(montant) AS total FROM ecole_paiements WHERE annule = 0 AND created_at BETWEEN ? AND ? GROUP BY mode ORDER BY total DESC', [$a, $b]);
        $parType = DB::all("SELECT e.type, SUM(l.montant) AS total FROM ecole_paiement_lignes l JOIN ecole_paiements p ON p.id = l.paiement_id JOIN ecole_echeances e ON e.id = l.echeance_id
                            WHERE p.annule = 0 AND p.created_at BETWEEN ? AND ? GROUP BY e.type", [$a, $b]);
        $parClasse = DB::all('SELECT c.nom AS classe, COUNT(DISTINCT p.eleve_id) AS eleves, SUM(p.montant) AS total FROM ecole_paiements p JOIN ecole_inscriptions i ON i.id = p.inscription_id
                              JOIN ecole_classes c ON c.id = i.classe_id WHERE p.annule = 0 AND p.created_at BETWEEN ? AND ? GROUP BY c.id, c.nom ORDER BY total DESC', [$a, $b]);
        $salaires = (float) DB::value('SELECT COALESCE(SUM(montant),0) FROM ecole_salaires WHERE annule = 0 AND created_at BETWEEN ? AND ?', [$a, $b]);
        $depenses = 0.0;
        try {
            $depenses = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE type = 'expense' AND status = 'valid' AND expense_date BETWEEN ? AND ?", [$du, $au]);
        } catch (\Throwable) {
            $depenses = 0.0; // structure différente : dépenses générales non comptées
        }
        $an = Ecole::anneeCourante();
        $du_ = $an ? DB::one("SELECT COALESCE(SUM(e.montant),0) AS du, COALESCE(SUM(e.paye),0) AS paye, COALESCE(SUM(CASE WHEN e.date_limite <= CURDATE() THEN e.montant - e.paye ELSE 0 END),0) AS echu
                             FROM ecole_echeances e JOIN ecole_inscriptions i ON i.id = e.inscription_id WHERE i.annee_id = ? AND e.annule = 0", [$an['id']]) : ['du' => 0, 'paye' => 0, 'echu' => 0];
        return ['recettes' => $recettes, 'par_mode' => $parMode, 'par_type' => $parType, 'par_classe' => $parClasse, 'salaires' => $salaires, 'depenses' => $depenses,
            'solde' => round($recettes - $salaires - $depenses, 2), 'annee' => ['du' => (float) $du_['du'], 'paye' => (float) $du_['paye'], 'echu' => round((float) $du_['echu'], 2)]];
    }

    /** Échéances échues non payées (relances), par élève. */
    public static function impayes(?int $classeId = null): array
    {
        $an = Ecole::anneeCourante();
        if (!$an) {
            return [];
        }
        return DB::all("SELECT el.id AS eleve_id, el.matricule, el.nom, el.prenom, el.tuteur_nom, el.tuteur_tel, c.nom AS classe, i.id AS inscription_id,
                        SUM(e.montant - e.paye) AS echu, MIN(e.date_limite) AS plus_ancienne, COUNT(*) AS echeances
                        FROM ecole_echeances e JOIN ecole_inscriptions i ON i.id = e.inscription_id JOIN ecole_eleves el ON el.id = i.eleve_id JOIN ecole_classes c ON c.id = i.classe_id
                        WHERE i.annee_id = ? AND i.statut = 'active' AND e.annule = 0 AND e.paye < e.montant AND e.date_limite <= CURDATE()" . ($classeId ? ' AND i.classe_id = ' . (int) $classeId : '') . '
                        GROUP BY el.id, el.matricule, el.nom, el.prenom, el.tuteur_nom, el.tuteur_tel, c.nom, i.id ORDER BY echu DESC', [$an['id']]);
    }
}
