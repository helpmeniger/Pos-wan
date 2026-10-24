<?php
declare(strict_types=1);

namespace Addons\Comptabilite;

/**
 * Plans comptables proposés à la mise en place : SYSCOHADA révisé (complet ou simplifié pour
 * commerce / PME), PCG français abrégé, ou plan vide à compléter / importer.
 * Les comptes viennent de fichiers texte « numéro|libellé » (data/*.txt).
 */
final class Plans
{
    public const PLANS = [
        'syscohada' => ['SYSCOHADA révisé — plan complet', 'Plan comptable OHADA (Acte uniforme 2017), utilisé au Niger et dans les 17 États de l\'OHADA : environ 600 comptes, classes 1 à 9.', 'syscohada'],
        'syscohada_pme' => ['SYSCOHADA révisé — plan simplifié (commerce, services, PME)', 'Les comptes SYSCOHADA les plus utilisés par un commerce ou une petite entreprise (environ 250 comptes, regroupements compris) ; d\'autres comptes SYSCOHADA peuvent être ajoutés ensuite.', 'syscohada'],
        'pcg' => ['PCG français — version abrégée', 'Plan comptable général français (ANC 2014-03), pour une structure qui tient sa comptabilité selon les normes françaises. Les états de synthèse sont alors présentés de façon simplifiée.', 'pcg'],
        'vide' => ['Plan vide', 'Aucun compte : vous créez vos comptes un par un ou importez votre propre plan (fichier CSV « numéro;libellé »).', 'syscohada'],
    ];

    /** Comptes de liaison proposés selon le référentiel (paramètres compta_cpt_*). */
    public const LIAISONS = [
        'syscohada' => ['caisse' => '5711', 'banque' => '521', 'mobile' => '552', 'cheques' => '513', 'clients' => '4111', 'fournisseurs' => '4011',
            'ventes' => '7011', 'services' => '7061', 'tva_collectee' => '4431', 'achats' => '6011', 'frais_achats' => '6015', 'charges_diverses' => '6588',
            'produits_divers' => '7588', 'salaires' => '6611', 'frais_bancaires' => '6318', 'interets' => '771', 'coffre' => '5712', 'virements_internes' => '585'],
        'pcg' => ['caisse' => '5311', 'banque' => '512', 'mobile' => '517', 'cheques' => '511', 'clients' => '411', 'fournisseurs' => '401',
            'ventes' => '707', 'services' => '706', 'tva_collectee' => '44571', 'achats' => '6071', 'frais_achats' => '6078', 'charges_diverses' => '658',
            'produits_divers' => '758', 'salaires' => '641', 'frais_bancaires' => '627', 'interets' => '768', 'coffre' => '5312', 'virements_internes' => '580'],
    ];

    /** Comptes de résultat utilisés par la clôture : [bénéfice, perte]. */
    public const RESULTAT = ['syscohada' => ['131', '139'], 'pcg' => ['120', '129']];

    /** Comptes retenus dans le plan SYSCOHADA simplifié (les comptes de regroupement à 2 chiffres sont ajoutés automatiquement). */
    private const PME = ['101', '103', '104', '1041', '1042', '1048', '106', '109', '111', '118', '121', '129', '130', '131', '139', '141', '151', '162', '165', '168', '191', '198',
        '212', '213', '215', '216', '218', '222', '223', '231', '234', '235', '241', '244', '2441', '2442', '2444', '245', '248', '252', '275', '278',
        '281', '283', '284', '291', '294', '311', '318', '321', '331', '334', '335', '381', '391',
        '4011', '4017', '404', '408', '4091', '4111', '4117', '416', '418', '4191', '421', '422', '428', '431', '438', '441', '442', '4431', '4432', '4441', '4449',
        '4451', '4452', '4454', '447', '4471', '4472', '448', '462', '465', '471', '476', '477', '481', '485', '491', '499',
        '513', '515', '521', '525', '552', '561', '565', '5711', '5712', '5713', '585',
        '6011', '6015', '6019', '6021', '6031', '6032', '6033', '6041', '6043', '6047', '6051', '6052', '6053', '6055', '6056', '6081',
        '612', '614', '6181', '6222', '6223', '6228', '6241', '6242', '6243', '6251', '6252', '6265', '6271', '6272', '6276', '6281', '6288',
        '6318', '6315', '6317', '6324', '6327', '633', '6343', '6351', '6371', '6383', '6384', '6411', '6412', '6413', '6418', '6462', '6463', '6478', '648',
        '6511', '6582', '6588', '6611', '6612', '6613', '6614', '6617', '6641', '666', '671', '673', '674', '6813', '691',
        '7011', '7015', '7019', '7061', '7071', '7073', '7078', '718', '7582', '7588', '771', '773', '781', '791', '798',
        '812', '822', '831', '835', '841', '845', '891', '895'];

    /** @return array<string, string> numéro => libellé */
    public static function accounts(string $plan): array
    {
        if (!isset(self::PLANS[$plan]) || $plan === 'vide') {
            return [];
        }
        $all = self::readFile(dirname(__DIR__) . '/data/' . ($plan === 'pcg' ? 'pcg' : 'syscohada') . '.txt');
        if ($plan !== 'syscohada_pme') {
            return $all;
        }
        $keep = [];
        foreach (self::PME as $n) {
            if (isset($all[$n])) {
                $keep[$n] = $all[$n];
                $g = substr($n, 0, 2);
                if (isset($all[$g])) {
                    $keep[$g] = $all[$g];
                }
            }
        }
        uksort($keep, 'strcmp');
        return $keep;
    }

    public static function referentiel(string $plan): string
    {
        return self::PLANS[$plan][2] ?? 'syscohada';
    }

    /** @return array<string, string> */
    public static function readFile(string $file): array
    {
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] === '#' || !str_contains($line, '|')) {
                continue;
            }
            [$n, $l] = explode('|', $line, 2);
            $out[trim($n)] = trim($l);
        }
        return $out;
    }

    /** Type de tiers attaché par défaut aux comptes collectifs (clients, fournisseurs, personnel). */
    public static function tiersType(string $numero, string $referentiel): string
    {
        if ($referentiel === 'pcg') {
            return match (true) {
                str_starts_with($numero, '411'), str_starts_with($numero, '416'), str_starts_with($numero, '419') => 'client',
                str_starts_with($numero, '401'), str_starts_with($numero, '404'), str_starts_with($numero, '409') => 'fournisseur',
                str_starts_with($numero, '421'), str_starts_with($numero, '425') => 'salarie',
                default => '',
            };
        }
        return match (true) {
            str_starts_with($numero, '411'), str_starts_with($numero, '416'), str_starts_with($numero, '419') => 'client',
            str_starts_with($numero, '401'), str_starts_with($numero, '409'), str_starts_with($numero, '481') => 'fournisseur',
            str_starts_with($numero, '421'), str_starts_with($numero, '422') => 'salarie',
            default => '',
        };
    }

    /** Comptes lettrables par défaut : tiers, personnel, État, débiteurs/créditeurs divers, virements internes. */
    public static function lettrable(string $numero): bool
    {
        return (bool) preg_match('/^(40|41|42|46|47|48|58)/', $numero);
    }
}
