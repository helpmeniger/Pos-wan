<?php
declare(strict_types=1);

namespace Addons\Fiscal\Secef;

use App\Core\BusinessException;
use App\Core\DB;

/**
 * MARWA SECEF CONNECT — règles métier (Niger, e-SECeF / e-SFE de la DGI).
 *
 * Tout ce qui est fixe ici a été OBSERVÉ sur l'e-SFE le 20/11/2026 (documentation/marwa-secef/) :
 * groupes de taxation, types de facture, modes de prix, modes de paiement, valeurs ISB.
 * Les choix propres au contribuable (régime, groupe de chaque article) sont faits par l'administrateur :
 * POS Marwa ne devine jamais un groupe de taxation.
 */
final class Secef
{
    public const DRIVER = 'ne_esfe_pont';

    /** Groupes de taxation (libellés exacts de la liste « Groupe de taxation » de l'e-SFE). */
    public const GROUPES = [
        'A' => 'EXONERE', 'B' => 'TAXABLE 19%', 'C' => 'TAXABLE 10%', 'D' => 'TAXABLE 5%', 'F' => 'EXPORTATION',
        'G' => 'TVA REGIME DEROGATOIRE', 'H' => 'REGIME IS', 'I' => "CONSIGNATION D'EMBALLAGE", 'J' => 'DEPOTS',
        'K' => 'DEBOURS', 'L' => 'TIMBRE',
    ];

    /** Taux de TVA affiché par l'e-SFE pour les groupes dont le libellé l'indique (« H - 0% » observé). */
    public const TAUX_GROUPE = ['A' => 0.0, 'B' => 19.0, 'C' => 10.0, 'D' => 5.0, 'H' => 0.0];

    /** Types de facture (écran « Enregistrement d'une nouvelle facture »). */
    public const TYPES = [
        'FV' => 'Facture de vente', 'FT' => 'Facture d\'acompte ou d\'avance', 'FA' => 'Facture d\'avoir',
        'EV' => 'Facture de vente à l\'exportation', 'ET' => 'Facture d\'acompte ou d\'avance à l\'exportation', 'EA' => 'Facture d\'avoir à l\'exportation',
    ];

    /** Modes de paiement de l'e-SFE (liste « Paiement »). */
    public const PAIEMENTS = ['ESPECES', 'MOBILE MONEY', 'VIREMENT', 'CARTE BANCAIRE', 'CHEQUES', 'AUTRE 1', 'AUTRE 2'];

    /** Précompte ISB (liste « ISB sélectionné »). */
    public const ISB = ['N/A', 'ISB 2%', 'ISB 5%'];

    /** Régimes proposés à l'administrateur : ils ne fixent que la règle PAR DÉFAUT (modifiable article par article). */
    public const REGIMES = [
        'IS' => 'Impôt synthétique (régime IS) : articles dans le groupe H par défaut',
        'TVA' => 'Assujetti à la TVA : groupe choisi d\'après le taux de TVA de l\'article dans POS Marwa',
        'MANUEL' => 'Aucune règle automatique : chaque article ou catégorie reçoit son groupe à la main',
    ];

    /** Modes de paiement POS Marwa → e-SFE (modifiable dans la configuration). */
    public const PAIEMENTS_DEFAUT = ['cash' => 'ESPECES', 'mobile_money' => 'MOBILE MONEY', 'card' => 'CARTE BANCAIRE',
        'transfer' => 'VIREMENT', 'cheque' => 'CHEQUES', 'credit' => 'AUTRE 1', 'voucher' => 'AUTRE 2'];

    public const DEFAUTS = [
        'actif' => false,
        'url_portail' => 'https://esecef.impots.gouv.ne',
        'url_pont' => 'http://127.0.0.1:8760',
        'regime' => 'IS',
        'groupe_defaut' => 'H',
        'taux' => ['19' => 'B', '10' => 'C', '5' => 'D', '0' => 'A'],
        'paiements' => self::PAIEMENTS_DEFAUT,
        'type_defaut' => 'FV',
        'mode_prix' => 'TTC',
        'case_cochee' => false,          // la case « Facture certifiée DGI » est-elle cochée d'office en caisse ?
        'creer_articles' => false,       // programmer automatiquement sur l'e-SFE un article absent (action sur le portail)
        'certification_autorisee' => false, // tant que false : la facture est préparée et vérifiée, jamais certifiée
        'fenetre_visible' => false,
        'reference_commentaire' => true, // n° de vente POS dans la ligne de commentaire (retrouver la facture)
        'client_avec_nif' => 'morale',   // type de client e-SFE quand le client POS a un NIF
        'client_sans_nif' => 'physique',
    ];

    public static function config(): array
    {
        $c = json_decode((string) setting('secef_config', ''), true);
        $c = is_array($c) ? $c + self::DEFAUTS : self::DEFAUTS;
        $c['paiements'] = (array) $c['paiements'] + self::PAIEMENTS_DEFAUT;
        return $c;
    }

    public static function saveConfig(array $in): array
    {
        $c = self::config();
        foreach (['actif', 'case_cochee', 'creer_articles', 'certification_autorisee', 'fenetre_visible', 'reference_commentaire'] as $k) {
            if (array_key_exists($k, $in)) {
                $c[$k] = (bool) $in[$k];
            }
        }
        foreach (['url_portail', 'url_pont'] as $k) {
            if (isset($in[$k])) {
                $u = rtrim(trim((string) $in[$k]), '/');
                if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{2,5})?(/[A-Za-z0-9._~/\-]*)?$#', $u)) {
                    throw new BusinessException("Adresse invalide : $u");
                }
                $c[$k] = $u;
            }
        }
        if ($k = parse_url($c['url_pont'], PHP_URL_HOST)) {
            if (!in_array($k, ['127.0.0.1', 'localhost', '::1'], true)) {
                throw new BusinessException('Le pont MARWA SECEF doit tourner sur cet ordinateur (127.0.0.1) : il manipule votre session e-SECeF.');
            }
        }
        if (isset($in['regime'])) {
            $c['regime'] = array_key_exists($in['regime'], self::REGIMES) ? $in['regime'] : throw new BusinessException('Régime inconnu.');
        }
        if (isset($in['groupe_defaut'])) {
            $c['groupe_defaut'] = self::groupe((string) $in['groupe_defaut']);
        }
        if (isset($in['taux']) && is_array($in['taux'])) {
            $t = [];
            foreach ($in['taux'] as $rate => $g) {
                if ($g !== '' && $g !== null) {
                    $t[(string) (float) $rate] = self::groupe((string) $g);
                }
            }
            $c['taux'] = $t;
        }
        if (isset($in['paiements']) && is_array($in['paiements'])) {
            foreach ($in['paiements'] as $m => $v) {
                if (preg_match('/^[a-z_]{2,30}$/', (string) $m) && in_array($v, self::PAIEMENTS, true)) {
                    $c['paiements'][$m] = $v;
                }
            }
        }
        if (isset($in['type_defaut'])) {
            $c['type_defaut'] = array_key_exists($in['type_defaut'], self::TYPES) && in_array($in['type_defaut'], ['FV', 'EV'], true) ? $in['type_defaut'] : 'FV';
        }
        foreach (['client_avec_nif', 'client_sans_nif'] as $k) {
            if (isset($in[$k])) {
                $c[$k] = $in[$k] === 'morale' ? 'morale' : 'physique';
            }
        }
        if (isset($in['mode_prix'])) {
            $c['mode_prix'] = $in['mode_prix'] === 'HT' ? 'HT' : 'TTC';
        }
        setting_set('secef_config', json_encode($c, JSON_UNESCAPED_UNICODE));
        return $c;
    }

    public static function groupe(string $g): string
    {
        $g = strtoupper(trim($g));
        if (!isset(self::GROUPES[$g])) {
            throw new BusinessException("Groupe de taxation inconnu : « $g » (A, B, C, D, F, G, H, I, J, K ou L).");
        }
        return $g;
    }

    /**
     * Groupe de taxation d'un article : correspondance de l'article > catégorie > règle du régime.
     * @return array{groupe: ?string, source: string}
     */
    public static function groupFor(int $productId, float $taxRate, ?array $config = null): array
    {
        $c = $config ?? self::config();
        $g = DB::value('SELECT groupe FROM secef_articles WHERE product_id = ? AND groupe IS NOT NULL', [$productId]);
        if ($g) {
            return ['groupe' => (string) $g, 'source' => 'article'];
        }
        $g = DB::value('SELECT sc.groupe FROM products p JOIN secef_categories sc ON sc.category_id = p.category_id WHERE p.id = ?', [$productId]);
        if (!$g) { // catégorie parente
            $g = DB::value('SELECT sc.groupe FROM products p JOIN categories c ON c.id = p.category_id JOIN secef_categories sc ON sc.category_id = c.parent_id WHERE p.id = ?', [$productId]);
        }
        if ($g) {
            return ['groupe' => (string) $g, 'source' => 'catégorie'];
        }
        if ($c['regime'] === 'IS') {
            return ['groupe' => $c['groupe_defaut'], 'source' => 'régime IS'];
        }
        if ($c['regime'] === 'TVA') {
            $g = $c['taux'][(string) (float) $taxRate] ?? null;
            return ['groupe' => $g, 'source' => $g ? 'taux ' . rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') . ' %' : 'aucune'];
        }
        return ['groupe' => null, 'source' => 'aucune'];
    }

    /** Écart entre le taux de TVA de POS Marwa et celui du groupe (informatif : les montants officiels sont ceux de l'e-SFE). */
    public static function rateWarning(string $groupe, float $taxRate): ?string
    {
        if (!array_key_exists($groupe, self::TAUX_GROUPE)) {
            return null;
        }
        $g = self::TAUX_GROUPE[$groupe];
        return abs($g - $taxRate) > 0.001 ? sprintf('TVA de POS Marwa %s %% ≠ groupe %s (%s %%)', rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.'), $groupe, rtrim(rtrim(number_format($g, 2, '.', ''), '0'), '.')) : null;
    }

    /** Désignation de l'article sur l'e-SFE (correspondance enregistrée, sinon nom POS Marwa). */
    public static function designationFor(int $productId, string $posName): string
    {
        $d = DB::value('SELECT designation FROM secef_articles WHERE product_id = ?', [$productId]);
        return $d ? (string) $d : self::clean($posName);
    }

    public static function clean(string $s): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, 200);
    }

    /**
     * Facture à transmettre au pont, construite depuis la facture normalisée de Fiscal Connect.
     * Refuse (exception) tout ce que le pont ne sait pas reproduire fidèlement sur l'e-SFE : jamais de facture
     * certifiée différente de la vente.
     */
    public static function buildInvoice(array $invoice, ?array $config = null): array
    {
        $c = $config ?? self::config();
        $isCredit = $invoice['type'] === 'credit_note';
        $sale = $isCredit ? null : DB::one('SELECT id, discount_total FROM sales WHERE number = ?', [$invoice['local_number']]);
        $lines = [];
        $problems = [];
        $items = DB::all('SELECT si.product_id, si.product_name, si.quantity, si.unit_price, si.discount, si.tax_rate, si.line_total
            FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.number = ? ORDER BY si.id', [$invoice['local_number']]);
        $byIndex = $isCredit ? [] : $items;
        foreach ($invoice['lines'] as $i => $l) {
            $pid = (int) ($byIndex[$i]['product_id'] ?? DB::value('SELECT id FROM products WHERE reference = ? LIMIT 1', [$l['ref']]) ?? 0);
            $grp = self::groupFor($pid, (float) $l['tax_rate'], $c);
            if (!$grp['groupe']) {
                $problems[] = '« ' . $l['name'] . ' » : aucun groupe de taxation (configurez l\'article, sa catégorie ou la règle du régime).';
                continue;
            }
            if ((float) $l['discount'] > 0.0) {
                $problems[] = '« ' . $l['name'] . ' » : remise de ligne non encore reproduite sur l\'e-SFE.';
            }
            $qty = round((float) $l['qty'], 3);
            $unit = round($qty > 0 ? (float) $l['total_ttc'] / $qty : (float) $l['unit_price_ttc'], 2);
            $lines[] = ['product_id' => $pid, 'code' => (string) $l['ref'], 'designation' => self::designationFor($pid, (string) $l['name']), 'groupe' => $grp['groupe'],
                'quantite' => $qty, 'prix_unitaire' => $unit, 'montant' => round((float) $l['total_ttc'], 2)];
        }
        if (!$isCredit && $sale && (float) $sale['discount_total'] > 0.0) {
            $problems[] = 'Remise globale sur la vente : non encore reproduite sur l\'e-SFE.';
        }
        $pay = [];
        foreach ($invoice['payments'] as $p) {
            $m = $c['paiements'][$p['method']] ?? null;
            if (!$m) {
                $problems[] = 'Mode de paiement « ' . $p['method'] . ' » sans correspondance e-SFE.';
                continue;
            }
            $pay[$m] = round(($pay[$m] ?? 0) + (float) $p['amount'], 2);
        }
        if (count($pay) > 1) {
            $problems[] = 'Paiement en plusieurs modes : la répartition sur l\'e-SFE n\'est pas encore prise en charge.';
        }
        if ($problems) {
            throw new BusinessException('Facture non transmise à l\'e-SFE : ' . implode(' ', $problems));
        }
        $buyer = $invoice['buyer'] ?? null;
        return [
            'cle' => $invoice['idempotency_key'],
            'reference_pos' => $invoice['local_number'],
            'type' => $isCredit ? 'FA' : $c['type_defaut'],
            'mode' => $c['mode_prix'],
            // Type de client : réglage de l'administrateur (jamais déduit du nom)
            'client' => $buyer ? ['type' => trim((string) ($buyer['nif'] ?? '')) !== '' ? $c['client_avec_nif'] : $c['client_sans_nif'], 'nif' => (string) ($buyer['nif'] ?? ''),
                'nom' => (string) $buyer['name'], 'adresse' => (string) ($buyer['address'] ?? ''), 'contact' => (string) ($buyer['phone'] ?? '')] : null,
            'lignes' => $lines,
            'paiement' => (string) (array_key_first($pay) ?? 'ESPECES'),
            'total' => round((float) $invoice['totals']['ttc'], 2),
            'commentaire' => $c['reference_commentaire'] ? 'Réf. POS Marwa ' . $invoice['local_number'] : null,
            'reference_origine' => $isCredit ? (string) ($invoice['original']['fiscal_number'] ?? '') : null,
        ];
    }

    /** Articles de la vente sans correspondance e-SFE programmée (à programmer avant certification). */
    public static function unprogrammed(int $saleId): array
    {
        return DB::all("SELECT si.product_id, si.product_name, sa.statut FROM sale_items si
            LEFT JOIN secef_articles sa ON sa.product_id = si.product_id
            WHERE si.sale_id = ? AND (sa.id IS NULL OR sa.statut <> 'programme') GROUP BY si.product_id, si.product_name, sa.statut", [$saleId]);
    }
}
