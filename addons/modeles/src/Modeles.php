<?php
declare(strict_types=1);

namespace Addons\Modeles;

use App\Core\Addons;
use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Modèles de documents : mises en page de tickets et de documents A4, modèles nommés et personnalisés,
 * affectation à chaque document (vente, vente à crédit, facture, devis, bon de livraison) et style des tickets
 * imprimés par les autres modules. Les données de la vente ne sont jamais modifiées : seule la présentation change.
 */
final class Modeles
{
    /** Mises en page disponibles. 'core' = modèle d'origine de POS Marwa (vue documents/…). */
    public const BASES = [
        'ticket' => [
            'encadre' => ['Encadré à colonnes', 'Numéro du ticket encadré, colonnes Qté / Produit / PU / Total, « NET À PAYER » en gros, solde de créance du client.'],
            'colonnes' => ['Tableau à colonnes', 'En-tête de colonnes souligné, montants alignés, total sur fond noir.'],
            'economique' => ['Économique (papier)', 'Une ligne par article, petits caractères, sans logo ni QR : consomme le moins de papier.'],
            'grand' => ['Grands caractères', 'Texte et total agrandis, articles en gras : lecture facile pour tous les clients.'],
            'core1' => ['POS Marwa — Classique', 'Modèle d\'origine n° 1.'],
            'core2' => ['POS Marwa — Compact', 'Modèle d\'origine n° 2.'],
            'core3' => ['POS Marwa — Moderne', 'Modèle d\'origine n° 3.'],
        ],
        'a4' => [
            'encadre' => ['Facture encadrée (grille)', 'Toutes les cases encadrées, cartouche du document à droite, total et montant en lettres encadrés, conditions de vente.'],
            'lateral' => ['Bandeau latéral', 'Bande de couleur à gauche avec les coordonnées de l\'entreprise, contenu aéré à droite.'],
            'a5' => ['Demi-page A5', 'Format A5 (148 × 210 mm) : deux documents par feuille A4 ou imprimante A5.'],
            'core1' => ['POS Marwa — Classique', 'Modèle d\'origine n° 1.'],
            'core2' => ['POS Marwa — Moderne (bandeau)', 'Modèle d\'origine n° 2.'],
            'core3' => ['POS Marwa — Épuré', 'Modèle d\'origine n° 3.'],
        ],
    ];

    /** Documents auxquels un modèle peut être affecté. */
    public const DOCUMENTS = [
        'sale' => ['ticket', 'Ticket de caisse', 'bi-receipt'],
        'credit' => ['ticket', 'Ticket de vente à crédit', 'bi-journal-text'],
        'invoice' => ['a4', 'Facture A4', 'bi-file-earmark-text'],
        'quote' => ['a4', 'Devis', 'bi-file-earmark-ruled'],
        'delivery' => ['a4', 'Bon de livraison', 'bi-truck'],
    ];

    /**
     * Tickets et reçus imprimés ailleurs dans le logiciel (cœur et modules) : leur présentation (police, taille,
     * séparateurs, titres) suit le modèle choisi. [préfixe d'adresse => [libellé, module requis ou null]].
     */
    public const AUTRES = [
        '/customer-payments' => ['Reçus de règlement des clients', null],
        '/returns' => ['Reçus de retour / remboursement', null],
        '/restaurant' => ['Additions du restaurant', null],
        '/pressing' => ['Tickets du pressing', 'pressing'],
        '/clinique' => ['Reçus et factures de la clinique', 'clinique'],
        '/ecole' => ['Reçus de scolarité', 'ecole'],
        '/hotel' => ['Documents de l\'hôtel', 'hotel'],
    ];

    /**
     * Options de personnalisation : [libellé, type, défaut, portée (ticket|a4|tous), choix éventuels].
     * Types : bool, text:<longueur>, area:<longueur>, select, color.
     */
    public const OPTIONS = [
        'titre' => ['Titre du document (vide = titre habituel)', 'text:40', '', 'tous'],
        'slogan' => ['Phrase sous le nom de l\'entreprise (activité, slogan)', 'text:80', '', 'tous'],
        'logo' => ['Afficher le logo', 'bool', '1', 'tous'],
        'adresse' => ['Afficher l\'adresse et le téléphone', 'bool', '1', 'tous'],
        'nif' => ['Afficher le NIF et le RCCM', 'bool', '1', 'tous'],
        'police' => ['Police', 'select', 'mono', 'tous', ['mono' => 'Machine à écrire (Courier)', 'sans' => 'Arial', 'serif' => 'Times', 'etroite' => 'Étroite (Arial Narrow)']],
        'taille' => ['Taille du texte', 'select', '100', 'tous', ['85' => 'Petite (85 %)', '100' => 'Normale', '115' => 'Grande (115 %)', '130' => 'Très grande (130 %)']],
        'couleur' => ['Couleur principale (A4)', 'color', '#14213d', 'a4'],
        'separateur' => ['Séparateurs', 'select', 'tirets', 'ticket', ['tirets' => '- - - tirets', 'points' => '····· points', 'plein' => '──── trait plein', 'double' => '════ double trait']],
        'cadre_numero' => ['Numéro du document encadré', 'bool', '1', 'ticket'],
        'date' => ['Afficher la date et l\'heure', 'bool', '1', 'ticket'],
        'caisse' => ['Afficher la caisse', 'bool', '1', 'ticket'],
        'caissier' => ['Afficher le caissier', 'bool', '1', 'ticket'],
        'client' => ['Afficher le client', 'bool', '1', 'ticket'],
        'majuscules' => ['Noms des articles en MAJUSCULES', 'bool', '0', 'tous'],
        'reference' => ['Afficher la référence des articles', 'bool', '0', 'tous'],
        'libelle_total' => ['Libellé du total', 'text:30', 'NET À PAYER', 'ticket'],
        'solde_client' => ['Afficher le solde de créance du client (ventes avec client)', 'bool', '1', 'tous'],
        'montant_lettres' => ['Montant en lettres', 'bool', '1', 'tous'],
        'signature' => ['Zone de signature (client / vendeur)', 'bool', '0', 'tous'],
        'qr' => ['QR code de vérification', 'bool', '1', 'tous'],
        'nb_articles' => ['Nombre d\'articles', 'bool', '1', 'ticket'],
        'conditions' => ['Conditions de vente (ex. marchandises ni reprises ni échangées)', 'area:300', '', 'tous'],
        'pied' => ['Message de pied (vide = message des Paramètres)', 'area:300', '', 'tous'],
        'pied2' => ['Ligne de contact en bas (téléphone, réseaux sociaux)', 'text:120', '', 'tous'],
        'filigrane' => ['Filigrane (A4, ex. PAYÉ, COPIE)', 'text:20', '', 'a4'],
    ];

    /** Valeurs par défaut propres à une mise en page. */
    private const BASE_DEFAULTS = [
        'ticket' => [
            'encadre' => ['police' => 'serif', 'signature' => '1', 'montant_lettres' => '0'],
            'colonnes' => ['police' => 'sans', 'cadre_numero' => '0', 'montant_lettres' => '0'],
            'economique' => ['logo' => '0', 'qr' => '0', 'taille' => '85', 'cadre_numero' => '0', 'nif' => '0', 'montant_lettres' => '0', 'caisse' => '0'],
            'grand' => ['police' => 'sans', 'taille' => '130', 'montant_lettres' => '0'],
            'core1' => ['montant_lettres' => '0'], 'core2' => ['montant_lettres' => '0'], 'core3' => ['montant_lettres' => '0'],
        ],
        'a4' => [
            'encadre' => ['police' => 'sans', 'signature' => '1'],
            'lateral' => ['police' => 'sans', 'couleur' => '#0f5132', 'signature' => '1'],
            'a5' => ['police' => 'sans', 'signature' => '0', 'reference' => '0'],
            'core1' => ['police' => 'sans'], 'core2' => ['police' => 'sans'], 'core3' => ['police' => 'sans'],
        ],
    ];

    // ------------------------------------------------------------------ options

    public static function defaults(string $type, string $base): array
    {
        $d = [];
        foreach (self::OPTIONS as $k => $o) {
            if ($o[3] === 'tous' || $o[3] === $type) {
                $d[$k] = $o[2];
            }
        }
        return array_merge($d, self::BASE_DEFAULTS[$type][$base] ?? []);
    }

    /** Valeurs reçues (formulaire, import) → options sûres (listes fermées, longueurs bornées, couleur #rrggbb). */
    public static function normalize(string $type, string $base, array $in): array
    {
        $out = self::defaults($type, $base);
        foreach ($out as $k => $def) {
            [, $kind] = self::OPTIONS[$k];
            $v = $in[$k] ?? null;
            if ($v === null) {
                continue; // option absente (modèle prêt à l'emploi, fichier importé) : valeur par défaut
            }
            if ($kind === 'bool') {
                $out[$k] = ($v === '1' || $v === 1 || $v === true || $v === 'on') ? '1' : '0';
                continue;
            }
            $v = is_scalar($v) ? (string) $v : '';
            if ($kind === 'select') {
                $out[$k] = isset(self::OPTIONS[$k][4][$v]) ? $v : $def;
            } elseif ($kind === 'color') {
                $out[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $def;
            } else {
                $max = (int) explode(':', $kind)[1];
                $v = str_replace("\r", '', $v);
                $v = str_starts_with($kind, 'area') ? trim($v) : trim(preg_replace('/\s+/u', ' ', $v));
                $out[$k] = mb_substr($v, 0, $max);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ modèles

    public static function all(?string $type = null): array
    {
        $rows = $type ? DB::all('SELECT * FROM doc_modeles WHERE type = ? ORDER BY nom', [$type]) : DB::all('SELECT * FROM doc_modeles ORDER BY type DESC, nom');
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function find(int $id): ?array
    {
        $r = DB::one('SELECT * FROM doc_modeles WHERE id = ?', [$id]);
        return $r ? self::hydrate($r) : null;
    }

    private static function hydrate(array $r): array
    {
        $r['options'] = self::normalize($r['type'], $r['base'], (array) (json_decode((string) $r['options'], true) ?: []));
        return $r;
    }

    public static function get(int $id): array
    {
        return self::find($id) ?? throw new BusinessException('Modèle introuvable.');
    }

    private static function checkBase(string $type, string $base): void
    {
        if (!isset(self::BASES[$type][$base])) {
            throw new BusinessException('Mise en page inconnue.');
        }
    }

    public static function save(?int $id, string $nom, string $type, string $base, array $options): int
    {
        $nom = trim(preg_replace('/\s+/u', ' ', $nom));
        if ($nom === '' || mb_strlen($nom) > 80) {
            throw new BusinessException('Donnez un nom au modèle (80 caractères au plus).');
        }
        self::checkBase($type, $base);
        if (DB::value('SELECT id FROM doc_modeles WHERE nom = ? AND id <> ?', [$nom, (int) $id])) {
            throw new BusinessException('Un modèle porte déjà ce nom.');
        }
        $data = ['nom' => $nom, 'type' => $type, 'base' => $base, 'options' => json_encode(self::normalize($type, $base, $options), JSON_UNESCAPED_UNICODE)];
        if ($id) {
            $old = self::get($id);
            if ($old['type'] !== $type) {
                throw new BusinessException('Le type d\'un modèle (ticket ou A4) ne peut pas changer : dupliquez-le.');
            }
            DB::update('doc_modeles', $data + ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
            Audit::log('modeles.modifie', 'doc_modele', $id, ['nom' => $nom]);
            return $id;
        }
        $id = DB::insert('doc_modeles', $data);
        Audit::log('modeles.cree', 'doc_modele', $id, ['nom' => $nom, 'base' => $base]);
        return $id;
    }

    public static function duplicate(int $id): int
    {
        $m = self::get($id);
        $n = 2;
        do {
            $nom = mb_substr($m['nom'], 0, 70) . ' (' . $n++ . ')';
        } while (DB::value('SELECT id FROM doc_modeles WHERE nom = ?', [$nom]));
        return self::save(null, $nom, $m['type'], $m['base'], $m['options']);
    }

    /** Suppression : le modèle est retiré des documents qui l'utilisaient (ils reprennent le modèle des Paramètres). */
    public static function delete(int $id): array
    {
        $m = self::get($id);
        $freed = [];
        $aff = self::assignments();
        foreach ($aff as $k => $v) {
            if ($v === $id) {
                unset($aff[$k]);
                $freed[] = self::DOCUMENTS[$k][1];
            }
        }
        $aut = self::others();
        foreach ($aut as $k => $v) {
            if ($v === $id) {
                unset($aut[$k]);
                $freed[] = self::AUTRES[$k][0];
            }
        }
        setting_set('modeles_affectations', json_encode($aff));
        setting_set('modeles_autres', json_encode($aut));
        DB::run('DELETE FROM doc_modeles WHERE id = ?', [$id]);
        Audit::log('modeles.supprime', 'doc_modele', $id, ['nom' => $m['nom'], 'liberes' => $freed]);
        return $freed;
    }

    /** Fichier d'échange (partage d'un modèle entre boutiques) : aucune donnée de vente ni réglage de l'entreprise. */
    public static function export(int $id): array
    {
        $m = self::get($id);
        return ['format' => 'pos-marwa-modele', 'version' => 1, 'nom' => $m['nom'], 'type' => $m['type'], 'base' => $m['base'], 'options' => $m['options']];
    }

    public static function import(string $json): int
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['format'] ?? '') !== 'pos-marwa-modele' || !is_array($d['options'] ?? null)) {
            throw new BusinessException('Ce fichier n\'est pas un modèle de document POS Marwa.');
        }
        $type = (string) ($d['type'] ?? '');
        $base = (string) ($d['base'] ?? '');
        self::checkBase($type, $base);
        $nom = trim((string) ($d['nom'] ?? 'Modèle importé')) ?: 'Modèle importé';
        $try = mb_substr($nom, 0, 70);
        $n = 2;
        while (DB::value('SELECT id FROM doc_modeles WHERE nom = ?', [$try])) {
            $try = mb_substr($nom, 0, 70) . ' (' . $n++ . ')';
        }
        return self::save(null, $try, $type, $base, $d['options']);
    }

    // ------------------------------------------------------------------ affectations

    /** @return array<string,int> document => id du modèle */
    public static function assignments(): array
    {
        $a = json_decode((string) setting('modeles_affectations', '{}'), true);
        return array_filter(array_map('intval', is_array($a) ? array_intersect_key($a, self::DOCUMENTS) : []));
    }

    /** @return array<string,int> préfixe => id du modèle */
    public static function others(): array
    {
        $a = json_decode((string) setting('modeles_autres', '{}'), true);
        return array_filter(array_map('intval', is_array($a) ? array_intersect_key($a, self::AUTRES) : []));
    }

    public static function availableOthers(): array
    {
        return array_filter(self::AUTRES, fn($o) => $o[1] === null || Addons::isActive($o[1]));
    }

    public static function saveAssignments(array $docs, array $autres): void
    {
        $aff = [];
        foreach (self::DOCUMENTS as $k => [$type, $label]) {
            $id = (int) ($docs[$k] ?? 0);
            if ($id) {
                $m = self::get($id);
                if ($m['type'] !== $type) {
                    throw new BusinessException('« ' . $label . ' » demande un modèle ' . ($type === 'a4' ? 'A4' : 'de ticket') . '.');
                }
                $aff[$k] = $id;
            }
        }
        $aut = [];
        foreach (self::availableOthers() as $k => $o) {
            $id = (int) ($autres[$k] ?? 0);
            if ($id) {
                self::get($id);
                $aut[$k] = $id;
            }
        }
        setting_set('modeles_affectations', json_encode($aff));
        setting_set('modeles_autres', json_encode($aut));
        Audit::log('modeles.affectations', 'doc_modele', null, ['documents' => $aff, 'autres' => $aut]);
    }

    /** Modèle à utiliser pour un document ; la vente à crédit reprend celui du ticket de caisse si elle n'en a pas. */
    public static function forDocument(string $kind): ?array
    {
        $aff = self::assignments();
        $id = $aff[$kind] ?? ($kind === 'credit' ? ($aff['sale'] ?? null) : null);
        return $id ? self::find($id) : null;
    }

    // ------------------------------------------------------------------ rendu

    /** Vue de la mise en page (modèles d'origine : vues du cœur, sinon vues du module). */
    public static function view(array $m): string
    {
        if (str_starts_with($m['base'], 'core')) {
            return 'documents/' . ($m['type'] === 'a4' ? 'a4_' : 'ticket_') . substr($m['base'], 4);
        }
        return 'modeles::' . $m['type'] . '_' . $m['base'];
    }

    /**
     * Adapte le document préparé par le cœur aux options du modèle (titre, lignes d'information, QR, pied…).
     * Les montants ne sont jamais recalculés.
     */
    public static function apply(array $doc, array $m): array
    {
        $o = $m['options'];
        if ($o['titre'] !== '') {
            $doc['title'] = $o['titre'];
        }
        if ($o['qr'] !== '1') {
            $doc['qr'] = null;
        }
        if ($o['pied'] !== '') {
            $doc['footer'] = $o['pied'];
        }
        if ($o['majuscules'] === '1') {
            foreach (['items', 'lines'] as $k) {
                foreach ($doc[$k] ?? [] as $i => $l) {
                    $doc[$k][$i]['name'] = mb_strtoupper($l['name']);
                }
            }
        }
        if ($m['type'] === 'ticket' && isset($doc['meta'])) {
            $hide = [];
            foreach (['date' => 'Date', 'caisse' => 'Caisse', 'caissier' => 'Caissier', 'client' => 'Client'] as $k => $label) {
                if ($o[$k] !== '1') {
                    $hide[] = t($label);
                }
            }
            $doc['meta'] = array_values(array_filter($doc['meta'], fn($r) => !in_array($r[0], $hide, true)));
        }
        if (($doc['credit'] ?? 0) > 0 && isset($doc['payments'])) {
            // Vente à crédit : « Montant remis 0 » et « Monnaie rendue 0 » n'apportent rien au client.
            $zero = money(0);
            $doc['payments'] = array_values(array_filter($doc['payments'], fn($r) => !($r[1] === $zero && in_array($r[0], [t('Montant remis'), t('Monnaie rendue')], true))));
        }
        if ($o['montant_lettres'] !== '1') {
            $doc['words'] = null;
        }
        if ($m['type'] === 'a4' && $o['signature'] !== '1') {
            $doc['signatures'] = [];
        }
        $doc['mdl'] = $o + ['id' => (int) $m['id'], 'base' => $m['base']];
        return $doc;
    }

    /** Montant en lettres d'un total (pour les tickets qui l'affichent). */
    public static function words(float $total): string
    {
        return ucfirst(amount_words($total)) . ' ' . currency()['name'];
    }

    /** Feuille de style d'un modèle, limitée à ses documents ($scope = sélecteur parent). Valeurs issues de listes fermées. */
    public static function css(array $m, string $scope): string
    {
        $o = $m['options'];
        $font = ['mono' => '"Courier New",Courier,monospace', 'sans' => 'Arial,Helvetica,sans-serif', 'serif' => '"Times New Roman",Times,serif',
            'etroite' => '"Arial Narrow","Liberation Sans Narrow",Arial,sans-serif'][$o['police']] ?? 'inherit';
        $size = ((int) $o['taille']) / 100;
        $root = $m['type'] === 'a4' ? '.sheet' : '.ticket';
        $css = sprintf('%1$s %2$s{font-family:%3$s;font-size:%4$.2fem}', $scope, $root, $font, $size);
        if ($m['type'] === 'ticket') {
            // La taille de base du ticket vient des Paramètres (12 px) ; le modèle l'agrandit ou la réduit.
            $t58 = $scope === 'body' ? 'body.t58 .ticket' : 'body.t58 ' . $scope . ' .ticket';
            $css = sprintf('%1$s .ticket{font-family:%2$s;font-size:%3$.1fpx}%5$s{font-size:%4$.1fpx}', $scope, $font, 12 * $size, 10.5 * $size, $t58);
            $sep = ['tirets' => '1px dashed', 'points' => '1px dotted', 'plein' => '1px solid', 'double' => '3px double'][$o['separateur']];
            $css .= sprintf('%1$s .ticket hr,%1$s .ticket .m-sep{border:0;border-top:%2$s #000}', $scope, $sep);
        } else {
            $c = $o['couleur'];
            $css .= sprintf('%1$s .sheet table.lines th{background:%2$s}%1$s .sheet .head{border-bottom-color:%2$s}%1$s .sheet{--mdl:%2$s}', $scope, $c);
        }
        return $css;
    }

    /** Mise en forme commune des mises en page du module (fichier du module, inclus une fois par page). */
    public static function layoutCss(): string
    {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return (string) file_get_contents(dirname(__DIR__) . '/assets/modeles-impression.css');
    }

    /** Style des tickets et reçus des autres pages (préfixe d'adresse), ou chaîne vide. */
    public static function othersCss(string $path): string
    {
        foreach (self::others() as $prefix => $id) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                $m = self::find($id);
                return $m ? self::css($m, 'body') : '';
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ aperçu

    /** Document fictif (rien n'est enregistré) pour l'aperçu d'un modèle. */
    public static function sampleDoc(string $type, string $kind): array
    {
        [$s, $items, $payments] = \App\Services\Documents::sample();
        if ($kind === 'credit') {
            $s['tendered'] = 0;
            $s['change_due'] = 0;
            $s['credit_amount'] = $s['total'];
            $payments = [];
        }
        if ($type === 'ticket') {
            $doc = \App\Services\Documents::saleTicket($s, $items, $payments, false);
        } else {
            $doc = \App\Services\Documents::saleA4($s, $items, $payments, $kind === 'delivery' ? 'delivery' : 'invoice', $kind !== 'delivery');
            if ($kind === 'quote') {
                // Devis fictif : pas de paiement, mentions du devis
                $doc['title'] = t('DEVIS');
                $cut = array_search(true, array_map(fn($r) => !empty($r[2]), $doc['totals']), true);
                $doc['totals'] = $cut === false ? $doc['totals'] : array_slice($doc['totals'], 0, $cut + 1);
                $doc['words'] = t('Arrêté le présent devis à la somme de :') . ' ' . ucfirst(amount_words((float) $s['total'])) . ' ' . currency()['name'] . '.';
                $doc['signatures'] = [t('Bon pour accord (client)'), t('Pour') . ' ' . setting('company_name')];
            }
        }
        $doc['customer_balance'] = 25000.0;
        $doc['credit'] = (float) $s['credit_amount'];
        $doc['kind'] = $kind;
        return $doc;
    }
}
