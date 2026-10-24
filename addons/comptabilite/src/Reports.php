<?php
declare(strict_types=1);

namespace Addons\Comptabilite;

use App\Core\DB;

/**
 * États comptables : journal, journal centralisateur, grand livre, balance générale et des tiers,
 * bilan et compte de résultat (présentation SYSCOHADA révisé, ou simplifiée pour un autre plan).
 * Par défaut les états reprennent toutes les écritures (validées et brouillard, signalé à l'écran) ;
 * l'option « validées seulement » donne les états définitifs.
 */
final class Reports
{
    // ------------------------------------------------------------------ bases

    private static function statutSql(bool $validOnly): string
    {
        return $validOnly ? ' AND e.statut = \'valide\'' : '';
    }

    /** Écritures d'un journal (ou de tous) sur une période, lignes comprises. */
    public static function journal(int $exId, ?int $journalId, string $from, string $to, bool $validOnly): array
    {
        $p = [$exId, $from, $to];
        $w = '';
        if ($journalId) {
            $w = ' AND e.journal_id = ?';
            $p[] = $journalId;
        }
        $entries = DB::all('SELECT e.*, j.code AS journal_code, j.libelle AS journal_libelle FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id
            WHERE e.exercice_id = ? AND e.date_ecriture BETWEEN ? AND ?' . $w . self::statutSql($validOnly) . ' ORDER BY j.code, e.date_ecriture, e.numero IS NULL, e.numero, e.id', $p);
        if (!$entries) {
            return [];
        }
        $ids = array_column($entries, 'id');
        $lines = DB::all('SELECT l.*, c.numero AS compte, c.libelle AS compte_libelle, t.code AS tiers_code FROM cpt_lignes l JOIN cpt_comptes c ON c.id = l.compte_id
            LEFT JOIN cpt_tiers t ON t.id = l.tiers_id WHERE l.ecriture_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY l.ecriture_id, l.ordre, l.id');
        $by = [];
        foreach ($lines as $l) {
            $by[$l['ecriture_id']][] = $l;
        }
        foreach ($entries as &$e) {
            $e['lignes'] = $by[$e['id']] ?? [];
        }
        return $entries;
    }

    /** Journal centralisateur : totaux par journal et par mois. */
    public static function centralisateur(int $exId, bool $validOnly): array
    {
        return DB::all('SELECT j.code, j.libelle, DATE_FORMAT(e.date_ecriture, \'%Y-%m\') AS mois, COUNT(DISTINCT e.id) AS nb, SUM(l.debit) AS debit, SUM(l.credit) AS credit
            FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id JOIN cpt_lignes l ON l.ecriture_id = e.id
            WHERE e.exercice_id = ?' . self::statutSql($validOnly) . ' GROUP BY j.code, j.libelle, mois ORDER BY j.code, mois', [$exId]);
    }

    /**
     * Grand livre : pour chaque compte de la plage, report (avant la période, à-nouveaux compris),
     * lignes de la période avec solde progressif, totaux.
     */
    public static function grandLivre(int $exId, string $from, string $to, string $cFrom, string $cTo, bool $validOnly, ?int $tiersId = null): array
    {
        [$wc, $pc] = self::range($cFrom, $cTo);
        $tw = $tiersId ? ' AND l.tiers_id = ' . (int) $tiersId : '';
        $reports = [];
        // Report : écritures antérieures à la période et à-nouveaux (journal de type AN)
        foreach (DB::all('SELECT c.id, SUM(l.debit) AS d, SUM(l.credit) AS c FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_journaux j ON j.id = e.journal_id JOIN cpt_comptes c ON c.id = l.compte_id
            WHERE e.exercice_id = ? AND (e.date_ecriture < ? OR j.type = \'an\')' . $wc . $tw . self::statutSql($validOnly) . ' GROUP BY c.id', array_merge([$exId, $from], $pc)) as $r) {
            $reports[$r['id']] = [(float) $r['d'], (float) $r['c']];
        }
        $rows = DB::all('SELECT l.*, c.id AS cid, c.numero, c.libelle AS compte_libelle, e.date_ecriture, e.numero AS piece_no, e.piece, e.statut, e.id AS ecriture_id, j.code AS journal_code, t.code AS tiers_code
            FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_comptes c ON c.id = l.compte_id JOIN cpt_journaux j ON j.id = e.journal_id LEFT JOIN cpt_tiers t ON t.id = l.tiers_id
            WHERE e.exercice_id = ? AND e.date_ecriture BETWEEN ? AND ? AND j.type <> \'an\'' . $wc . $tw . self::statutSql($validOnly) . ' ORDER BY c.numero, e.date_ecriture, e.id, l.ordre', array_merge([$exId, $from, $to], $pc));
        $accounts = [];
        foreach ($rows as $r) {
            $accounts[$r['cid']] ??= ['numero' => $r['numero'], 'libelle' => $r['compte_libelle'], 'lignes' => []];
            $accounts[$r['cid']]['lignes'][] = $r;
        }
        foreach ($reports as $cid => $_) {
            if (!isset($accounts[$cid])) {
                $c = DB::one('SELECT numero, libelle FROM cpt_comptes WHERE id = ?', [$cid]);
                $accounts[$cid] = ['numero' => $c['numero'], 'libelle' => $c['libelle'], 'lignes' => []];
            }
        }
        uasort($accounts, fn($a, $b) => strcmp($a['numero'], $b['numero']));
        $grand = ['d' => 0.0, 'c' => 0.0];
        foreach ($accounts as $cid => &$a) {
            [$rd, $rc] = $reports[$cid] ?? [0.0, 0.0];
            $a['report'] = round($rd - $rc, 2);
            $solde = $a['report'];
            $td = $tc = 0.0;
            foreach ($a['lignes'] as &$l) {
                $solde = round($solde + (float) $l['debit'] - (float) $l['credit'], 2);
                $l['solde'] = $solde;
                $td += (float) $l['debit'];
                $tc += (float) $l['credit'];
            }
            unset($l);
            $a['debit'] = $td;
            $a['credit'] = $tc;
            $a['solde'] = $solde;
            $grand['d'] += $td + max(0, $a['report']);
            $grand['c'] += $tc + max(0, -$a['report']);
        }
        unset($a);
        return ['comptes' => $accounts, 'total' => $grand];
    }

    /**
     * Balance générale : par compte (ou regroupé à 2 chiffres / par classe) — soldes d'ouverture,
     * mouvements de la période, soldes de clôture, en colonnes débit / crédit.
     */
    public static function balance(int $exId, string $from, string $to, string $cFrom, string $cTo, bool $validOnly, string $niveau = 'compte'): array
    {
        [$wc, $pc] = self::range($cFrom, $cTo);
        $rows = DB::all('SELECT c.numero, c.libelle, c.classe,
              SUM(CASE WHEN e.date_ecriture < ? OR j.type = \'an\' THEN l.debit - l.credit ELSE 0 END) AS ouverture,
              SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND j.type <> \'an\' THEN l.debit ELSE 0 END) AS md,
              SUM(CASE WHEN e.date_ecriture BETWEEN ? AND ? AND j.type <> \'an\' THEN l.credit ELSE 0 END) AS mc
            FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_journaux j ON j.id = e.journal_id JOIN cpt_comptes c ON c.id = l.compte_id
            WHERE e.exercice_id = ? AND e.date_ecriture <= ?' . $wc . self::statutSql($validOnly) . ' GROUP BY c.numero, c.libelle, c.classe ORDER BY c.numero',
            array_merge([$from, $from, $to, $from, $to, $exId, $to], $pc));
        if ($niveau !== 'compte') {
            $len = $niveau === 'classe' ? 1 : 2;
            $labels = [];
            foreach (DB::all('SELECT numero, libelle FROM cpt_comptes WHERE CHAR_LENGTH(numero) = 2') as $c) {
                $labels[$c['numero']] = $c['libelle'];
            }
            $g = [];
            foreach ($rows as $r) {
                $k = substr($r['numero'], 0, $len);
                $g[$k] ??= ['numero' => $k, 'libelle' => $len === 1 ? (Compta::CLASSES[(int) $k] ?? 'Classe ' . $k) : ($labels[$k] ?? 'Comptes ' . $k), 'classe' => (int) $k[0], 'ouverture' => 0, 'md' => 0, 'mc' => 0];
                $g[$k]['ouverture'] += (float) $r['ouverture'];
                $g[$k]['md'] += (float) $r['md'];
                $g[$k]['mc'] += (float) $r['mc'];
            }
            $rows = array_values($g);
        }
        $out = [];
        $classes = [];
        $tot = array_fill_keys(['od', 'oc', 'md', 'mc', 'fd', 'fc'], 0.0);
        foreach ($rows as $r) {
            $o = round((float) $r['ouverture'], 2);
            $f = round($o + (float) $r['md'] - (float) $r['mc'], 2);
            if (abs($o) < 0.005 && abs((float) $r['md']) < 0.005 && abs((float) $r['mc']) < 0.005) {
                continue;
            }
            $line = ['numero' => $r['numero'], 'libelle' => $r['libelle'], 'classe' => (int) $r['classe'],
                'od' => max(0, $o), 'oc' => max(0, -$o), 'md' => (float) $r['md'], 'mc' => (float) $r['mc'], 'fd' => max(0, $f), 'fc' => max(0, -$f)];
            $out[] = $line;
            $classes[$line['classe']] ??= array_fill_keys(array_keys($tot), 0.0);
            foreach ($tot as $k => $_) {
                $classes[$line['classe']][$k] += $line[$k];
                $tot[$k] += $line[$k];
            }
        }
        return ['lignes' => $out, 'classes' => $classes, 'total' => $tot];
    }

    /** Balance des tiers (clients, fournisseurs…) à une date. */
    public static function balanceTiers(int $exId, string $type, string $to, bool $validOnly): array
    {
        $p = [$exId, $to];
        $w = '';
        if (isset(Compta::TIERS_TYPES[$type])) {
            $w = ' AND t.type = ?';
            $p[] = $type;
        }
        return DB::all('SELECT t.id, t.code, t.nom, t.type, t.telephone, c.numero AS compte, SUM(l.debit) AS debit, SUM(l.credit) AS credit,
              SUM(CASE WHEN l.lettrage IS NULL THEN l.debit - l.credit ELSE 0 END) AS non_lettre, MIN(CASE WHEN l.lettrage IS NULL AND l.debit > 0 THEN COALESCE(l.echeance, e.date_ecriture) END) AS plus_ancien
            FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_tiers t ON t.id = l.tiers_id JOIN cpt_comptes c ON c.id = l.compte_id
            WHERE e.exercice_id = ? AND e.date_ecriture <= ?' . $w . self::statutSql($validOnly) . ' GROUP BY t.id, t.code, t.nom, t.type, t.telephone, c.numero
            HAVING ABS(SUM(l.debit) - SUM(l.credit)) >= 0.01 OR SUM(l.debit) > 0 ORDER BY t.type, t.nom', $p);
    }

    /** Filtre sur une plage de comptes « de … à … » (préfixes compris). */
    private static function range(string $from, string $to): array
    {
        $w = '';
        $p = [];
        $from = Compta::leadToken($from);
        $to = Compta::leadToken($to);
        if ($from !== '') {
            $w .= ' AND c.numero >= ?';
            $p[] = $from;
        }
        if ($to !== '') {
            $w .= ' AND (c.numero <= ? OR c.numero LIKE ?)';
            $p[] = $to;
            $p[] = $to . '%';
        }
        return [$w, $p];
    }

    // ------------------------------------------------------------------ états de synthèse

    /** Soldes (débit − crédit) par compte et par tiers, jusqu'à une date, dans un exercice. */
    private static function soldes(int $exId, string $to, bool $validOnly): array
    {
        return DB::all('SELECT c.numero, l.tiers_id, SUM(l.debit - l.credit) AS solde FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id
            JOIN cpt_comptes c ON c.id = l.compte_id WHERE e.exercice_id = ? AND e.date_ecriture <= ?' . self::statutSql($validOnly) . '
            GROUP BY c.numero, l.tiers_id HAVING ABS(SUM(l.debit - l.credit)) >= 0.005', [$exId, $to]);
    }

    /** Postes du bilan SYSCOHADA révisé : [réf, libellé, type] (type : poste, total, titre). */
    public const BILAN_ACTIF = [
        ['AD', 'Immobilisations incorporelles'], ['AI', 'Immobilisations corporelles'], ['AP', 'Avances et acomptes versés sur immobilisations'],
        ['AQ', 'Immobilisations financières'], ['AZ', 'TOTAL ACTIF IMMOBILISÉ', ['AD', 'AI', 'AP', 'AQ']],
        ['BA', 'Actif circulant HAO'], ['BB', 'Stocks et encours'], ['BH', 'Fournisseurs, avances versées'], ['BI', 'Clients'], ['BJ', 'Autres créances'],
        ['BK', 'TOTAL ACTIF CIRCULANT', ['BA', 'BB', 'BH', 'BI', 'BJ']],
        ['BQ', 'Titres de placement'], ['BR', 'Valeurs à encaisser'], ['BS', 'Banques, chèques postaux, caisse et assimilés'],
        ['BT', 'TOTAL TRÉSORERIE-ACTIF', ['BQ', 'BR', 'BS']], ['BU', 'Écart de conversion-Actif'],
        ['BZ', 'TOTAL GÉNÉRAL', ['AZ', 'BK', 'BT', 'BU']],
    ];

    public const BILAN_PASSIF = [
        ['CA', 'Capital'], ['CB', 'Apporteurs capital non appelé (−)'], ['CD', 'Primes liées au capital social'], ['CE', 'Écarts de réévaluation'],
        ['CF', 'Réserves indisponibles'], ['CG', 'Réserves libres'], ['CH', 'Report à nouveau (+ ou −)'], ['CJ', 'Résultat net de l\'exercice (bénéfice + ou perte −)'],
        ['CL', 'Subventions d\'investissement'], ['CM', 'Provisions réglementées'],
        ['CP', 'TOTAL CAPITAUX PROPRES ET RESSOURCES ASSIMILÉES', ['CA', 'CB', 'CD', 'CE', 'CF', 'CG', 'CH', 'CJ', 'CL', 'CM']],
        ['DA', 'Emprunts et dettes financières diverses'], ['DB', 'Dettes de location-acquisition'], ['DC', 'Provisions pour risques et charges'],
        ['DD', 'TOTAL DETTES FINANCIÈRES ET RESSOURCES ASSIMILÉES', ['DA', 'DB', 'DC']],
        ['DF', 'TOTAL RESSOURCES STABLES', ['CP', 'DD']],
        ['DH', 'Dettes circulantes HAO'], ['DI', 'Clients, avances reçues'], ['DJ', 'Fournisseurs d\'exploitation'], ['DK', 'Dettes fiscales et sociales'],
        ['DM', 'Autres dettes'], ['DN', 'Provisions pour risques à court terme'],
        ['DP', 'TOTAL PASSIF CIRCULANT', ['DH', 'DI', 'DJ', 'DK', 'DM', 'DN']],
        ['DQ', 'Banques, crédits d\'escompte'], ['DR', 'Banques, établissements financiers et crédits de trésorerie'],
        ['DT', 'TOTAL TRÉSORERIE-PASSIF', ['DQ', 'DR']], ['DV', 'Écart de conversion-Passif'],
        ['DZ', 'TOTAL GÉNÉRAL', ['DF', 'DP', 'DT', 'DV']],
    ];

    /**
     * Affectation des soldes : [préfixe, côté (D : solde débiteur, C : créditeur, * : tout), poste, nature].
     * Le préfixe le plus long l'emporte (ex. 409 avant 40, 478 avant 47).
     * Nature : brut (actif), amort (amortissements / dépréciations, en déduction de l'actif), passif.
     */
    private const BILAN_RULES = [
        ['21', '*', 'AD', 'brut'], ['22', '*', 'AI', 'brut'], ['23', '*', 'AI', 'brut'], ['24', '*', 'AI', 'brut'], ['25', '*', 'AP', 'brut'], ['26', '*', 'AQ', 'brut'], ['27', '*', 'AQ', 'brut'],
        ['281', '*', 'AD', 'amort'], ['282', '*', 'AI', 'amort'], ['283', '*', 'AI', 'amort'], ['284', '*', 'AI', 'amort'], ['28', '*', 'AI', 'amort'],
        ['291', '*', 'AD', 'amort'], ['292', '*', 'AI', 'amort'], ['293', '*', 'AI', 'amort'], ['294', '*', 'AI', 'amort'], ['295', '*', 'AP', 'amort'], ['296', '*', 'AQ', 'amort'], ['297', '*', 'AQ', 'amort'], ['29', '*', 'AI', 'amort'],
        ['3', '*', 'BB', 'brut'], ['39', '*', 'BB', 'amort'],
        ['40', 'D', 'BH', 'brut'], ['41', 'D', 'BI', 'brut'], ['42', 'D', 'BJ', 'brut'], ['43', 'D', 'BJ', 'brut'], ['44', 'D', 'BJ', 'brut'], ['45', 'D', 'BJ', 'brut'], ['46', 'D', 'BJ', 'brut'], ['47', 'D', 'BJ', 'brut'],
        ['48', 'D', 'BA', 'brut'], ['478', '*', 'BU', 'brut'],
        ['490', '*', 'BH', 'amort'], ['491', '*', 'BI', 'amort'], ['492', '*', 'BJ', 'amort'], ['493', '*', 'BJ', 'amort'], ['494', '*', 'BJ', 'amort'], ['495', '*', 'BJ', 'amort'], ['496', '*', 'BJ', 'amort'], ['497', '*', 'BJ', 'amort'], ['498', '*', 'BA', 'amort'],
        ['50', '*', 'BQ', 'brut'], ['51', '*', 'BR', 'brut'], ['5', 'D', 'BS', 'brut'], ['590', '*', 'BQ', 'amort'], ['591', '*', 'BR', 'amort'], ['59', '*', 'BS', 'amort'],
        ['10', '*', 'CA', 'passif'], ['105', '*', 'CD', 'passif'], ['106', '*', 'CE', 'passif'], ['109', '*', 'CB', 'passif'],
        ['11', '*', 'CG', 'passif'], ['111', '*', 'CF', 'passif'], ['112', '*', 'CF', 'passif'], ['113', '*', 'CF', 'passif'],
        ['12', '*', 'CH', 'passif'], ['13', '*', 'CJ', 'passif'], ['14', '*', 'CL', 'passif'], ['15', '*', 'CM', 'passif'],
        ['16', '*', 'DA', 'passif'], ['18', '*', 'DA', 'passif'], ['17', '*', 'DB', 'passif'], ['19', '*', 'DC', 'passif'],
        ['48', 'C', 'DH', 'passif'], ['41', 'C', 'DI', 'passif'], ['40', 'C', 'DJ', 'passif'], ['42', 'C', 'DK', 'passif'], ['43', 'C', 'DK', 'passif'], ['44', 'C', 'DK', 'passif'],
        ['45', 'C', 'DM', 'passif'], ['46', 'C', 'DM', 'passif'], ['47', 'C', 'DM', 'passif'], ['479', '*', 'DV', 'passif'], ['499', '*', 'DN', 'passif'], ['599', '*', 'DN', 'passif'],
        ['5', 'C', 'DR', 'passif'], ['56', '*', 'DR', 'passif'], ['564', '*', 'DQ', 'passif'], ['565', '*', 'DQ', 'passif'],
    ];

    /** Compte de résultat SYSCOHADA révisé : [réf, libellé, préfixes] ou [réf, libellé, null, [réfs à additionner]]. */
    public const RESULTAT = [
        ['TA', 'Ventes de marchandises', ['701']], ['RA', 'Achats de marchandises', ['601']], ['RB', 'Variation de stocks de marchandises', ['6031', '603']],
        ['XA', 'MARGE COMMERCIALE', null, ['TA', 'RA', 'RB']],
        ['TB', 'Ventes de produits fabriqués', ['702', '703', '704']], ['TC', 'Travaux, services vendus', ['705', '706']], ['TD', 'Produits accessoires', ['707', '70']],
        ['XB', 'CHIFFRE D\'AFFAIRES', null, ['TA', 'TB', 'TC', 'TD']],
        ['TE', 'Production stockée (ou déstockage)', ['73']], ['TF', 'Production immobilisée', ['72']], ['TG', 'Subventions d\'exploitation', ['71']],
        ['TH', 'Autres produits', ['75', '7']], ['TI', 'Transferts de charges d\'exploitation', ['781', '78']],
        ['RC', 'Achats de matières premières et fournitures liées', ['602']], ['RD', 'Variation de stocks de matières premières et fournitures liées', ['6032']],
        ['RE', 'Autres achats', ['604', '605', '608', '60']], ['RF', 'Variation de stocks d\'autres approvisionnements', ['6033']],
        ['RG', 'Transports', ['61']], ['RH', 'Services extérieurs', ['62', '63']], ['RI', 'Impôts et taxes', ['64']], ['RJ', 'Autres charges', ['65', '6']],
        ['XC', 'VALEUR AJOUTÉE', null, ['XB', 'RA', 'RB', 'TE', 'TF', 'TG', 'TH', 'TI', 'RC', 'RD', 'RE', 'RF', 'RG', 'RH', 'RI', 'RJ']],
        ['RK', 'Charges de personnel', ['66']],
        ['XD', 'EXCÉDENT BRUT D\'EXPLOITATION', null, ['XC', 'RK']],
        ['TJ', 'Reprises d\'amortissements, provisions et dépréciations', ['791', '798', '799', '79']], ['RL', 'Dotations aux amortissements, aux provisions et dépréciations', ['681', '691', '68', '69']],
        ['XE', 'RÉSULTAT D\'EXPLOITATION', null, ['XD', 'TJ', 'RL']],
        ['TK', 'Revenus financiers et assimilés', ['77']], ['TL', 'Reprises de provisions et dépréciations financières', ['797']], ['TM', 'Transferts de charges financières', ['787']],
        ['RM', 'Frais financiers et charges assimilées', ['67']], ['RN', 'Dotations aux provisions et aux dépréciations financières', ['697']],
        ['XF', 'RÉSULTAT FINANCIER', null, ['TK', 'TL', 'TM', 'RM', 'RN']],
        ['XG', 'RÉSULTAT DES ACTIVITÉS ORDINAIRES', null, ['XE', 'XF']],
        ['TN', 'Produits des cessions d\'immobilisations', ['82']], ['TO', 'Autres produits HAO', ['84', '86', '88']],
        ['RO', 'Valeurs comptables des cessions d\'immobilisations', ['81']], ['RP', 'Autres charges HAO', ['83', '85', '8']],
        ['XH', 'RÉSULTAT HORS ACTIVITÉS ORDINAIRES', null, ['TN', 'TO', 'RO', 'RP']],
        ['RQ', 'Participation des travailleurs', ['87']], ['RS', 'Impôts sur le résultat', ['89']],
        ['XI', 'RÉSULTAT NET', null, ['XG', 'XH', 'RQ', 'RS']],
    ];

    private static function longest(string $numero, array $candidates): ?array
    {
        $best = null;
        foreach ($candidates as $c) {
            if (str_starts_with($numero, $c[0]) && (!$best || strlen($c[0]) > strlen($best[0]))) {
                $best = $c;
            }
        }
        return $best;
    }

    /** Compte de résultat (SYSCOHADA) : montants signés (produits +, charges −). */
    public static function resultat(int $exId, string $to, bool $validOnly): array
    {
        $rules = [];
        foreach (self::RESULTAT as $r) {
            foreach ((array) ($r[2] ?? []) as $prefix) {
                $rules[] = [$prefix, $r[0]];
            }
        }
        $v = array_fill_keys(array_column(self::RESULTAT, 0), 0.0);
        $detail = [];
        foreach (self::soldes($exId, $to, $validOnly) as $s) {
            $cls = (int) $s['numero'][0];
            if ($cls < 6 || $cls > 8) {
                continue;
            }
            $rule = self::longest($s['numero'], $rules);
            $ref = $rule[1] ?? 'RJ';
            $v[$ref] += -(float) $s['solde'];
            $detail[$ref][$s['numero']] = ($detail[$ref][$s['numero']] ?? 0) - (float) $s['solde'];
        }
        foreach (self::RESULTAT as $r) {
            if ($r[2] === null) {
                $v[$r[0]] = array_sum(array_map(fn($k) => $v[$k], $r[3]));
            }
        }
        return ['valeurs' => array_map(fn($x) => round($x, 2), $v), 'detail' => $detail];
    }

    /** Bilan SYSCOHADA : actif (brut, amortissements, net) et passif ; résultat de l'exercice inclus. */
    public static function bilan(int $exId, string $to, bool $validOnly): array
    {
        $actifRefs = array_column(self::BILAN_ACTIF, 0);
        $brut = array_fill_keys($actifRefs, 0.0);
        $amort = array_fill_keys($actifRefs, 0.0);
        $passif = array_fill_keys(array_column(self::BILAN_PASSIF, 0), 0.0);
        $nonClasses = [];
        $resultat = 0.0;
        foreach (self::soldes($exId, $to, $validOnly) as $s) {
            $n = $s['numero'];
            $v = (float) $s['solde'];
            $cls = (int) $n[0];
            if ($cls >= 6 && $cls <= 8) {
                $resultat -= $v;
                continue;
            }
            if ($cls === 9) {
                continue;
            }
            $side = $v > 0 ? 'D' : 'C';
            $rule = self::longest($n, array_values(array_filter(self::BILAN_RULES, fn($r) => $r[1] === '*' || $r[1] === $side)));
            if (!$rule) {
                $nonClasses[$n] = ($nonClasses[$n] ?? 0) + $v;
                continue;
            }
            match ($rule[3]) {
                'brut' => $brut[$rule[2]] += $v,
                'amort' => $amort[$rule[2]] += -$v,
                default => $passif[$rule[2]] += -$v,
            };
        }
        $passif['CJ'] += $resultat;
        $net = [];
        foreach (self::BILAN_ACTIF as $r) {
            if (isset($r[2])) {
                $brut[$r[0]] = array_sum(array_map(fn($k) => $brut[$k], $r[2]));
                $amort[$r[0]] = array_sum(array_map(fn($k) => $amort[$k], $r[2]));
            }
            $net[$r[0]] = round($brut[$r[0]] - $amort[$r[0]], 2);
        }
        foreach (self::BILAN_PASSIF as $r) {
            if (isset($r[2])) {
                $passif[$r[0]] = array_sum(array_map(fn($k) => $passif[$k], $r[2]));
            }
        }
        return ['brut' => array_map(fn($x) => round($x, 2), $brut), 'amort' => array_map(fn($x) => round($x, 2), $amort), 'net' => $net,
            'passif' => array_map(fn($x) => round($x, 2), $passif), 'resultat' => round($resultat, 2), 'non_classes' => $nonClasses,
            'ecart' => round($net['BZ'] - $passif['DZ'], 2)];
    }

    /** Présentation simplifiée (plan non SYSCOHADA) : soldes regroupés à 2 chiffres. */
    public static function syntheseSimple(int $exId, string $to, bool $validOnly): array
    {
        $labels = [];
        foreach (DB::all('SELECT numero, libelle FROM cpt_comptes WHERE CHAR_LENGTH(numero) = 2') as $c) {
            $labels[$c['numero']] = $c['libelle'];
        }
        $actif = $passif = $charges = $produits = [];
        foreach (self::soldes($exId, $to, $validOnly) as $s) {
            $g = substr($s['numero'], 0, 2);
            $v = (float) $s['solde'];
            $cls = (int) $s['numero'][0];
            if ($cls === 6) {
                $charges[$g] = ($charges[$g] ?? 0) + $v;
            } elseif ($cls === 7) {
                $produits[$g] = ($produits[$g] ?? 0) - $v;
            } elseif ($cls >= 1 && $cls <= 5) {
                if ($v > 0) {
                    $actif[$g] = ($actif[$g] ?? 0) + $v;
                } else {
                    $passif[$g] = ($passif[$g] ?? 0) - $v;
                }
            }
        }
        ksort($actif);
        ksort($passif);
        ksort($charges);
        ksort($produits);
        $res = array_sum($produits) - array_sum($charges);
        return compact('actif', 'passif', 'charges', 'produits', 'labels') + ['resultat' => round($res, 2)];
    }

    // ------------------------------------------------------------------ tableau de bord

    public static function dashboard(array $ex): array
    {
        $id = (int) $ex['id'];
        $sum = fn(string $like, string $sign = 'D') => (float) DB::value('SELECT COALESCE(SUM(' . ($sign === 'D' ? 'l.debit - l.credit' : 'l.credit - l.debit') . '), 0)
            FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_comptes c ON c.id = l.compte_id WHERE e.exercice_id = ? AND c.numero LIKE ?', [$id, $like]);
        $treso = DB::all('SELECT c.numero, c.libelle, SUM(l.debit - l.credit) AS solde FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id JOIN cpt_comptes c ON c.id = l.compte_id
            WHERE e.exercice_id = ? AND c.classe = 5 GROUP BY c.numero, c.libelle HAVING ABS(SUM(l.debit - l.credit)) >= 0.01 ORDER BY c.numero', [$id]);
        $totals = DB::one('SELECT COALESCE(SUM(l.debit), 0) AS d, COALESCE(SUM(l.credit), 0) AS c FROM cpt_lignes l JOIN cpt_ecritures e ON e.id = l.ecriture_id WHERE e.exercice_id = ?', [$id]);
        return [
            'ca' => $sum('70%', 'C'),
            'produits' => $sum('7%', 'C') + $sum('8%', 'C'),
            'charges' => $sum('6%'),
            'resultat' => $sum('7%', 'C') + $sum('6%', 'C') + $sum('8%', 'C'),
            'clients' => $sum('41%'),
            'fournisseurs' => $sum('40%', 'C'),
            'tresorerie' => $treso,
            'equilibre' => abs((float) $totals['d'] - (float) $totals['c']) < 0.01,
            'mouvements' => (float) $totals['d'],
            'brouillard' => (int) DB::value('SELECT COUNT(*) FROM cpt_ecritures WHERE exercice_id = ? AND statut = \'brouillard\'', [$id]),
            'valides' => (int) DB::value('SELECT COUNT(*) FROM cpt_ecritures WHERE exercice_id = ? AND statut = \'valide\'', [$id]),
            'recentes' => DB::all('SELECT e.*, j.code AS journal_code FROM cpt_ecritures e JOIN cpt_journaux j ON j.id = e.journal_id WHERE e.exercice_id = ? ORDER BY e.id DESC LIMIT 8', [$id]),
        ];
    }
}
