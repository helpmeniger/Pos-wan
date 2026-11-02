<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\DB;

/**
 * Le cerveau de MARWA IA : comprend la demande (intention, période, client, article,
 * fournisseur, vendeur, mode de paiement, format), garde le fil de la conversation
 * (« et en PDF ? », « et le mois dernier ? »), exécute le bon rapport ou trouve la bonne
 * réponse dans la documentation. Tout est calculé sur le serveur de la boutique.
 */
final class Engine
{
    /** Intentions : expressions (sur texte normalisé, sans accents) et poids. */
    private const INTENTS = [
        'vente' => [['/\b(vente|ticket|facture)\s*(n|no|numero)?\s*[a-z]{0,3}\d{2,}/', 3]],
        'commande' => [['/\b(que|quoi|quels? articles?)\b.{0,25}\b(commander|acheter|reapprovisionner|racheter)\b/', 4], ['/\b(reapprovision\w*|reassort|bon de commande conseille|liste de courses|a commander)\b/', 4]],
        'prevision' => [['/\b(prevision\w*|projection|prevoir|vais je vendre|fin du mois|fin de mois|objectif du mois|estimation du mois)\b/', 4]],
        'bilan' => [['/\b(bilan|resume|synthese|comment (va|vont|marche|se porte)|situation|point (sur|de)|etat de (ma|la|mon) (boutique|entreprise|magasin|commerce|affaire)|conseils?|analyse\w*|quoi de neuf|des nouvelles)\b/', 3]],
        'meilleurs_clients' => [['/\b(meilleurs? clients?|bons clients|clients? fideles?|gros clients?|qui achete le plus|clients? qui achetent le plus)\b/', 4], ['/\b((rapport|liste|achats?|ventes?) (des|de mes|de nos|par) clients?|par client)\b/', 4]],
        'creances' => [['/\b(qui me doit|me doivent|me doit|creances?|impayes?|dettes?|dois|doit|devons|credits? en cours|recouvrement|relancer)\b/', 2]],
        'benefice' => [['/\b(benefices?|marges?|rentab\w*|gagne|gagner|perte|pertes|resultat|compte de resultat|profit|chiffre d affaires?|\bca\b)\b/', 2]],
        'depenses' => [['/\b(depenses?|charges?|frais|recettes? diverses?|sorties? d argent)\b/', 3]],
        'caisse' => [['/\b(ecarts?|clotures?|sessions? de caisse|manque en caisse|manquants?|fonds? de caisse|comptage)\b/', 3]],
        'top' => [['/\b(meilleures? ventes?|plus vendus?|plus vendues?|top|vend(ent)? le plus|se vend le mieux|populaires?|article vedette|best)\b/', 3]],
        'dormants' => [['/\b(ne se vend\w*|invendus?|dormants?|pas vendus?|ne bouge|ne bougent|stock mort|ne tourne|ne tournent|sans vente)\b/', 4]],
        'peremption' => [['/\b(perim\w*|peremption\w*|expir\w*|date limite|dlc|dluo)\b/', 4]],
        'rupture' => [['/\b(ruptures?|sous le seuil|seuil d alerte|alertes? (de )?stock|bientot epuises?|epuises?|presque fini|a recommander|en manque)\b/', 4]],
        'stock' => [['/\b(stocks?|en magasin|quantite restante|reste combien|combien (il )?reste|valeur du stock|inventaire)\b/', 2]],
        'vendeurs' => [['/\b(par vendeur|vendeurs?|par caissier|caissiers?|par employe|employes?|par utilisateur)\b/', 2]],
        'heures' => [['/\b(heures?|affluence|heure de pointe|moment de la journee|rush)\b/', 2]],
        'paiements' => [['/\b(modes? de paiement|par paiement|moyens? de paiement|par mode)\b/', 3]],
        'categories' => [['/\b(par categorie|categories?|par rayon|par famille|rayons?)\b/', 2]],
        'ventes' => [['/\b(ventes?|vendu|vendus|transactions?|tickets?|encaisse\w*|recettes?|chiffre|journal des ventes|operations)\b/', 1]],
    ];

    private const METHOD_RE = ['mobile_money' => '/\b(mobile money|momo|airtel|moov|zamani|nita|orange money|par telephone)\b/', 'cash' => '/\b(especes?|cash|liquide|argent liquide)\b/', 'card' => '/\b(carte bancaire|par carte|cb|carte)\b/', 'transfer' => '/\b(virements?)\b/', 'cheque' => '/\b(cheques?)\b/'];

    /**
     * Répond à une question.
     * @param array|null $ctx dernière demande comprise (suite de la conversation)
     * @return array réponse affichable + 'spec' (à garder pour la suite)
     */
    public static function answer(string $question, ?array $ctx = null): array
    {
        $q = trim($question);
        if ($q === '') {
            return self::reply('Posez-moi une question, par exemple « rapport du client Moussa en PDF ».');
        }
        $n = Text::norm($q);
        // Quiz de formation en cours : la réponse A / B / C est corrigée
        if (($ctx['kind'] ?? null) === 'quiz' && ($graded = Trainer::grade($q, $ctx))) {
            return $graded;
        }
        if ($ctx !== null && !isset(Reports::KINDS[$ctx['kind'] ?? ''])) {
            $ctx = null; // la suite de conversation ne vaut que pour un rapport
        }
        if ($small = self::smallTalk($n)) {
            return $small;
        }
        if ($r = Tools::calc($q)) {
            return $r;
        }
        if ($r = Tools::define($q)) {
            return $r;
        }
        if (Checkup::isCheckup($n)) {
            return Checkup::answer();
        }
        if (Trainer::isTraining($n)) {
            return Trainer::answer($q);
        }
        if (preg_match('/\b(proverbes?|citations?|sagesse|pensee du jour|motivation|motive moi|encourage moi|parole de sage|dicton)\b/', $n)) {
            return self::wisdom($n);
        }
        if (Advisor::isAdvice($n) && !preg_match('/\b(rapport|pdf|excel|csv|imprime\w*)\b/', $n)) {
            return Advisor::answer($q);
        }
        if ($m = Modules::detect($n)) {
            return Modules::answer($m);
        }
        $spec = self::understand($q, $ctx);
        if ($spec === null) {
            return self::help($q);
        }
        if ($spec['kind'] === 'introuvable') {
            return self::notFound($spec['who'], $spec['name']);
        }
        try {
            $r = self::run($spec);
        } catch (BusinessException $e) {
            return self::reply(e($e->getMessage()), ['spec' => $spec, 'type' => 'error']);
        }
        return self::present($r, $spec);
    }

    /** Analyse : intention + paramètres, ou null si c'est une question d'aide. */
    public static function understand(string $q, ?array $ctx = null): ?array
    {
        $n = Text::norm($q);
        $period = Text::period($q);
        $format = Text::format($q);
        $qw = Text::words($q);

        // Suite de conversation : « et en PDF ? », « et le mois dernier ? », « la même chose pour hier »
        $intent = self::intent($n);
        $ents = self::entities($q, $n, $qw, $intent);
        $followUp = $ctx && !$ents['any'] && ($intent === null || $intent === 'ventes' && preg_match('/^(et|meme|pareil|aussi|maintenant|alors)\b/', $n))
            && ($period || $format || preg_match('/^(et|meme|pareil|aussi|refais|encore|maintenant|alors)\b/', $n));
        if ($followUp) {
            $s = $ctx;
            if ($period) {
                $s['period'] = $period;
            }
            $s['format'] = $format;
            return $s;
        }

        // Numéro de vente
        if (preg_match('/\b([a-z]{1,3}\d{4}-\d{3,6})\b/i', $q, $m) || preg_match('/\b(?:vente|ticket|facture)\s*(?:n[°o]?|numero)?\s*(\d{1,6})\b/i', $q, $m)) {
            return ['kind' => 'vente', 'number' => strtoupper($m[1]), 'format' => $format];
        }

        $f = ['period' => $period, 'format' => $format];
        if ($ents['method']) {
            $f['filters']['method'] = $ents['method'];
        }
        // Une personne ou un article cité donne le sens de la demande
        if ($ents['customer'] && !in_array($intent, ['meilleurs_clients', 'top', 'commande', 'prevision'], true)) {
            if ($intent === 'ventes' && preg_match('/\b(ventes?|achats?|tickets?|transactions?)\b/', $n) && !preg_match('/\b(releve|historique|compte|doit|solde|rapport)\b/', $n)) {
                $f['period'] ??= ['from' => '2000-01-01', 'to' => date('Y-m-d'), 'label' => 'depuis le début'];
                return $f + ['kind' => 'ventes', 'filters' => ($f['filters'] ?? []) + ['customer_id' => $ents['customer']['id'], 'label' => 'du client ' . $ents['customer']['name']]];
            }
            return $f + ['kind' => 'client', 'id' => $ents['customer']['id'], 'name' => $ents['customer']['name']];
        }
        if ($ents['supplier'] && !in_array($intent, ['top', 'commande'], true)) {
            return $f + ['kind' => 'fournisseur', 'id' => $ents['supplier']['id'], 'name' => $ents['supplier']['name']];
        }
        if ($ents['product'] && in_array($intent, [null, 'ventes', 'stock', 'benefice', 'rupture', 'top', 'peremption'], true)) {
            return $f + ['kind' => 'article', 'id' => $ents['product']['id'], 'name' => $ents['product']['name']];
        }
        if ($ents['user'] && in_array($intent, [null, 'ventes', 'vendeurs'], true)) {
            return $f + ['kind' => 'ventes', 'filters' => ($f['filters'] ?? []) + ['user_id' => $ents['user']['id'], 'label' => 'de ' . $ents['user']['name']]];
        }
        if (preg_match('/\b(clients?|fournisseurs?)\s+([a-z0-9][a-z0-9 ]{1,40})/', $n, $m) && preg_match('/\b(rapport|releve|historique|compte|doit|solde|achats?|fiche)\b/', $n)
            && !preg_match('/^(qui|les|mes|des|du mois|de la|cette|ce|par)\b/', $m[2])) {
            return ['kind' => 'introuvable', 'who' => $m[1][0] === 'c' ? 'client' : 'fournisseur', 'name' => trim($m[2])];
        }
        if ($intent === null) {
            return null;
        }
        if ($intent === 'ventes') {
            if (preg_match('/\b(credit|a credit)\b/', $n)) {
                $f['filters']['credit'] = true;
                $f['filters']['label'] = 'à crédit';
            }
            if (!empty($f['filters']['method'])) {
                $f['filters']['label'] = 'payées en ' . strtolower(Reports::METHODS[$f['filters']['method']]);
            }
            if (!$period && preg_match('/\b(toutes|tous|tout|ensemble|historique)\b/', $n)) {
                $f['period'] = ['from' => '2000-01-01', 'to' => date('Y-m-d'), 'label' => 'depuis le début'];
            }
            if (preg_match('/\b(combien|chiffre|total|montant)\b/', $n) && !$format) {
                $f['brief'] = true;
            }
        }
        if ($intent === 'stock' && preg_match('/\bvaleur\b/', $n)) {
            $f['mode'] = 'value';
        }
        if ($intent === 'top' && preg_match('/\b(quantite|nombre|unites?|volume)\b/', $n)) {
            $f['order'] = 'qty';
        }
        return $f + ['kind' => $intent];
    }

    /** Intention la plus probable (null = question d'aide sur le logiciel). */
    public static function intent(string $n): ?string
    {
        // Les questions « comment faire… » relèvent de l'aide, sauf demande explicite de rapport
        $howTo = preg_match('/\b(comment (faire|faut|on|je|creer|ajouter|enregistrer|imprimer|vendre|ouvrir|cloturer|fermer|installer|regler|parametrer|changer|supprimer|annuler|modifier|mettre|utiliser|activer|scanner|payer|rembourser|transferer|sauvegarder)|ou (se trouve|trouver|est|sont)|a quoi sert|c est quoi|qu est ce que|explique|aide moi a|pourquoi|est il possible|puis je|peut on)\b/', $n)
            && !preg_match('/\bcomment (va|vont|marche|se porte|se portent|vont les)\b/', $n);
        $report = preg_match('/\b(rapport|releve|liste|donne|montre|affiche|combien|quel(le)?s? (est|sont)|qui|pdf|csv|excel|imprime|exporte|telecharge|total|etat)\b/', $n);
        if ($howTo && !$report) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach (self::INTENTS as $intent => $rules) {
            $s = 0;
            foreach ($rules as [$re, $w]) {
                if (preg_match($re, $n)) {
                    $s += $w;
                }
            }
            if ($s > $bestScore) {
                $best = $intent;
                $bestScore = $s;
            }
        }
        // « meilleures ventes » → top, pas ventes ; « valeur du stock » → stock
        return $best;
    }

    /** Client, fournisseur, article, vendeur et mode de paiement cités. */
    public static function entities(string $q, string $n, array $qw, ?string $intent): array
    {
        $out = ['customer' => null, 'supplier' => null, 'product' => null, 'user' => null, 'method' => null, 'any' => false];
        foreach (self::METHOD_RE as $m => $re) {
            if (preg_match($re, $n)) {
                $out['method'] = $m;
                break;
            }
        }
        // Les mots des intentions ne servent pas à reconnaître un nom
        $skip = ['client', 'clients', 'fournisseur', 'fournisseurs', 'article', 'articles', 'produit', 'produits', 'vente', 'ventes', 'rapport', 'releve', 'stock', 'historique',
            'transaction', 'transactions', 'pdf', 'csv', 'excel', 'mois', 'semaine', 'jour', 'annee', 'hier', 'aujourd', 'hui', 'dernier', 'derniere', 'combien', 'vendu', 'vendus',
            'achat', 'achats', 'compte', 'solde', 'doit', 'marge', 'prix', 'quantite', 'reste', 'caisse', 'vendeur', 'caissier', 'mobile', 'money', 'especes', 'carte', 'credit',
            'boutique', 'magasin', 'situation', 'donne', 'montre', 'affiche', 'liste', 'total', 'meilleur', 'meilleurs', 'meilleures', 'detail', 'details', 'imprime', 'ses', 'son', 'leur'];
        $w = array_values(array_filter($qw, fn($x) => !in_array($x, $skip, true) && !isset(Text::MONTHS[$x]) && !preg_match('/^\d+$/', $x)));
        $digits = preg_match('/(\d[\d ]{7,})/', $n, $dm) && strlen(preg_replace('/\D/', '', $dm[1])) >= 8 ? substr(preg_replace('/\D/', '', $dm[1]), -8) : null;
        if (!$w && !$digits) {
            return $out;
        }
        $wantCustomer = (bool) preg_match('/\bclients?\b/', $n);
        $wantSupplier = (bool) preg_match('/\bfournisseurs?\b/', $n);
        $wantProduct = (bool) preg_match('/\b(articles?|produits?|stocks?|vendus?|vendues?|vend|vendent|prix|marges?|reste|combien de)\b/', $n);
        $wantUser = (bool) preg_match('/\b(vendeur|caissier|employe|utilisateur|par)\b/', $n);

        $pick = function (array $rows, string $col, float $min, bool $item = false) use ($w): ?array {
            $best = null;
            $bs = 0.0;
            foreach ($rows as $r) {
                $s = $item ? Text::itemScore($w, (string) $r[$col]) : Text::nameScore($w, (string) $r[$col]);
                if ($s > $bs) {
                    $bs = $s;
                    $best = $r;
                }
            }
            return $bs >= $min ? $best + ['_score' => $bs] : null;
        };
        if (Auth::can('customers.view')) {
            $c = null;
            if ($digits) {
                $c = DB::one("SELECT id, name FROM customers WHERE REPLACE(REPLACE(phone, ' ', ''), '+', '') LIKE ? LIMIT 1", ['%' . $digits]);
            }
            $c ??= $w ? $pick(DB::all('SELECT id, name FROM customers WHERE active = 1 LIMIT 5000'), 'name', $wantCustomer ? 0.55 : 0.75, $wantCustomer) : null;
            $out['customer'] = $c;
        }
        if (Auth::can('purchases.view') && $w) {
            $out['supplier'] = $pick(DB::all('SELECT id, name FROM suppliers WHERE active = 1 LIMIT 2000'), 'name', $wantSupplier ? 0.55 : 0.8, $wantSupplier);
        }
        if ($w) {
            // Recherche des articles candidats (classement insensible aux accents de MySQL)
            $cond = [];
            $prm = [];
            foreach (array_slice(array_filter($w, fn($x) => strlen($x) >= 3), 0, 6) as $x) {
                $cond[] = 'name LIKE ?';
                $prm[] = '%' . (strlen($x) > 4 ? Text::stem($x) : $x) . '%'; // « biscuits » trouve « Biscuit »
            }
            if ($cond) {
                $out['product'] = $pick(DB::all("SELECT id, name FROM products WHERE status = 'active' AND (" . implode(' OR ', $cond) . ') LIMIT 300', $prm), 'name', $wantProduct ? 0.45 : 0.7, true);
            }
            $out['user'] = $pick(DB::all('SELECT id, full_name AS name FROM users WHERE active = 1'), 'name', $wantUser ? 0.55 : 0.85, $wantUser);
        }
        // Plusieurs candidats : le mot-clé de la question (client / fournisseur / article) tranche, sinon le meilleur score
        $cands = array_filter(['customer' => $out['customer'], 'supplier' => $out['supplier'], 'product' => $out['product'], 'user' => $out['user']]);
        if (count($cands) > 1) {
            $prefer = $wantCustomer ? 'customer' : ($wantSupplier ? 'supplier' : ($wantProduct ? 'product' : ($wantUser ? 'user' : null)));
            if ($prefer && isset($cands[$prefer])) {
                $keep = $prefer;
            } else {
                uasort($cands, fn($a, $b) => $b['_score'] <=> $a['_score']);
                $keep = array_key_first($cands);
            }
            foreach (array_keys($cands) as $k) {
                if ($k !== $keep) {
                    $out[$k] = null;
                }
            }
        }
        $out['any'] = (bool) array_filter([$out['customer'], $out['supplier'], $out['product'], $out['user']]);
        return $out;
    }

    /** Exécute la demande comprise. */
    public static function run(array $s): array
    {
        $p = $s['period'] ?? null;
        return match ($s['kind']) {
            'client' => Reports::client((int) $s['id'], $p),
            'fournisseur' => Reports::supplier((int) $s['id'], $p),
            'article' => Reports::product((int) $s['id'], $p),
            'ventes' => Reports::sales($p, $s['filters'] ?? []),
            'vente' => Reports::sale((string) $s['number']),
            'top' => Reports::top($p, $s['order'] ?? 'revenue'),
            'dormants' => Reports::slow(30),
            'stock' => Reports::stock('all'),
            'rupture' => Reports::stock('low'),
            'peremption' => Reports::expiring(90),
            'creances' => Reports::debts(),
            'meilleurs_clients' => Reports::topCustomers($p),
            'benefice' => Reports::profit($p),
            'depenses' => Reports::expenses($p),
            'caisse' => Reports::cash($p),
            'vendeurs', 'heures', 'paiements', 'categories' => Reports::breakdown($s['kind'], $p),
            'bilan' => Reports::bilan($p),
            'commande' => Reports::reorder(14),
            'prevision' => Reports::forecast(),
            default => throw new BusinessException('Je ne sais pas encore préparer ce rapport.'),
        };
    }

    /** Paramètres d'adresse du rapport (PDF / CSV / affichage). */
    public static function query(array $s): array
    {
        $q = ['k' => $s['kind']];
        foreach (['id', 'number', 'order'] as $k) {
            if (isset($s[$k])) {
                $q[$k] = $s[$k];
            }
        }
        if (!empty($s['period'])) {
            $q['from'] = $s['period']['from'];
            $q['to'] = $s['period']['to'];
            $q['pl'] = $s['period']['label'];
        }
        foreach (['user_id', 'customer_id', 'method', 'credit', 'label'] as $k) {
            if (!empty($s['filters'][$k])) {
                $q['f'][$k] = $s['filters'][$k];
            }
        }
        return $q;
    }

    /** Demande reconstruite depuis l'adresse d'un rapport. */
    public static function fromQuery(array $g): array
    {
        $kind = (string) ($g['k'] ?? '');
        if (!isset(Reports::KINDS[$kind])) {
            throw new BusinessException('Rapport inconnu.');
        }
        $s = ['kind' => $kind];
        foreach (['id' => 'int', 'number' => 'str', 'order' => 'str'] as $k => $t) {
            if (isset($g[$k]) && $g[$k] !== '') {
                $s[$k] = $t === 'int' ? (int) $g[$k] : mb_substr((string) $g[$k], 0, 40);
            }
        }
        $d = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        if (($from = $d($g['from'] ?? null)) && ($to = $d($g['to'] ?? null))) {
            $s['period'] = ['from' => $from, 'to' => $to, 'label' => mb_substr((string) ($g['pl'] ?? ''), 0, 60)];
        }
        $f = (array) ($g['f'] ?? []);
        $s['filters'] = array_filter(['user_id' => (int) ($f['user_id'] ?? 0), 'customer_id' => (int) ($f['customer_id'] ?? 0),
            'method' => isset(Reports::METHODS[$f['method'] ?? '']) ? $f['method'] : null, 'credit' => !empty($f['credit']), 'label' => mb_substr((string) ($f['label'] ?? ''), 0, 80)]);
        return $s;
    }

    /** Mise en forme de la réponse (texte, chiffres, aperçu du tableau, boutons PDF / Excel). */
    private static function present(array $r, array $s): array
    {
        $qs = self::query($s);
        $pdfView = url('/ia/rapport', $qs + ['export' => 'pdf', 'view' => 1]);
        $pdf = url('/ia/rapport', $qs + ['export' => 'pdf']);
        $csv = url('/ia/rapport', $qs + ['export' => 'csv']);
        $rows = $r['rows'];
        $preview = array_map(fn($row) => array_map([self::class, 'cell'], array_values($row)), array_slice($rows, 0, 12));
        $html = $r['html'] ?? '<p>' . ($r['text'] ?? '') . '</p>';
        if (in_array($s['kind'], ['bilan', 'benefice', 'prevision'], true)) {
            $html .= Wisdom::html(Wisdom::forTheme($s['kind'] === 'benefice' ? 'tresorerie' : 'croissance'));
        }
        $actions = [['label' => 'Voir le PDF', 'url' => $pdfView, 'kind' => 'view'], ['label' => 'Télécharger le PDF', 'url' => $pdf, 'kind' => 'pdf'], ['label' => 'Excel (CSV)', 'url' => $csv, 'kind' => 'csv']];
        foreach ($r['links'] ?? [] as [$label, $u]) {
            $actions[] = ['label' => $label, 'url' => $u, 'kind' => 'link'];
        }
        return [
            'ok' => true, 'type' => 'report', 'title' => $r['title'], 'subtitle' => $r['subtitle'], 'html' => $html,
            'kpis' => array_map(fn($k) => ['label' => $k[0], 'value' => $k[1]], $r['kpis'] ?? []),
            'table' => !empty($s['brief']) || !$rows ? null : ['headers' => $r['headers'], 'rows' => $preview, 'more' => max(0, count($rows) - count($preview))],
            'actions' => $actions, 'open' => ($s['format'] ?? null) === 'pdf' ? $pdfView : (($s['format'] ?? null) === 'csv' ? $csv : null),
            'suggestions' => self::suggest($s), 'spec' => $s,
        ];
    }

    private static function cell(mixed $v): string
    {
        if (is_float($v) || is_int($v)) {
            $dec = abs($v - round($v)) > 0.0001 ? 2 : 0;
            return number_format((float) $v, $dec, ',', ' ');
        }
        return (string) $v;
    }

    /** Questions suivantes proposées selon le contexte. */
    private static function suggest(array $s): array
    {
        $k = $s['kind'];
        $base = match ($k) {
            'client' => ['Et le mois dernier ?', 'Qui me doit de l\'argent ?', 'Meilleurs clients de l\'année'],
            'ventes' => ['Et en PDF ?', 'Ventes par mode de paiement', 'Meilleures ventes du mois'],
            'article' => ['Que dois-je commander ?', 'Articles qui ne se vendent pas', 'Et cette année ?'],
            'rupture', 'stock' => ['Que dois-je commander ?', 'Produits qui périment bientôt', 'Valeur du stock'],
            'bilan' => ['Bénéfice du mois', 'Prévision du mois', 'Que dois-je commander ?'],
            'benefice' => ['Dépenses du mois', 'Et le mois dernier ?', 'Meilleures ventes du mois'],
            default => ['Bilan de la journée', 'Ventes d\'aujourd\'hui', 'Qui me doit de l\'argent ?'],
        };
        return array_values(array_filter($base, function ($x) {
            $kind = self::understand($x)['kind'] ?? null;
            return $kind === null || !isset(Reports::KINDS[$kind]) || Auth::can(Reports::KINDS[$kind][0]);
        }));
    }

    /** Aide sur le logiciel : réponse rédigée, documentation et pages du menu. */
    public static function help(string $q): array
    {
        $k = Knowledge::search($q);
        $html = '';
        $actions = [];
        if ($k['faq']) {
            $html .= '<p class="h">' . e($k['faq'][1]) . '</p>' . $k['faq'][2];
            foreach ($k['faq'][3] as [$label, $path]) {
                $actions[] = ['label' => $label, 'url' => url($path), 'kind' => 'link'];
            }
        }
        $docs = array_values(array_filter($k['docs'], fn($d) => $d['score'] >= 2.5));
        if (!$k['faq'] && $docs) {
            $d = $docs[0];
            $html .= '<p class="h">' . e($d['t']) . ' <span class="src">(' . e($d['s']) . ')</span></p>' . Knowledge::html($d['x']);
        }
        if ($k['faq'] && $docs && $docs[0]['score'] >= 6) {
            $html .= '<details><summary>Plus de détails : ' . e($docs[0]['t']) . '</summary>' . Knowledge::html($docs[0]['x'], 900) . '</details>';
        }
        foreach ($k['menu'] as $m) {
            if (!in_array($m['url'], array_column($actions, 'url'), true)) {
                $actions[] = ['label' => $m['label'], 'url' => $m['url'], 'kind' => 'link'];
            }
        }
        $llm = Llm::enabled() ? Llm::answer($q, $html !== '' ? strip_tags($html) : implode("\n\n", array_map(fn($d) => $d['t'] . "\n" . $d['x'], array_slice($k['docs'], 0, 3)))) : null;
        if ($llm) {
            $html = '<div class="llm">' . nl2br(e($llm)) . '</div>' . ($html !== '' ? '<details><summary>Source dans la documentation</summary>' . $html . '</details>' : '');
        }
        if ($html === '' && preg_match('/\b(comment|pourquoi|que faire|aide)\b/', Text::norm($q)) && preg_match('/\b(vente|ventes|client|clients|stock|prix|argent|benefice|marge|perte|pertes|vol|depenses?|caisse|tresorerie|employes?|personnel)\b/', Text::norm($q))) {
            return Advisor::answer($q);
        }
        if ($html === '') {
            $html = '<p>Je n\'ai pas trouvé de réponse sûre à cette question dans la documentation de POS Marwa.</p><p>Essayez avec d\'autres mots, par exemple « comment vendre au carton », « rapport du client Moussa », « ventes d\'hier en PDF », « que dois-je commander ». '
                . 'Pour un problème technique : Configuration → Assistance à distance, ou le <b>+227 92 08 12 82</b>.</p>';
        }
        return ['ok' => true, 'type' => 'help', 'html' => $html, 'actions' => array_slice($actions, 0, 5), 'suggestions' => ['Que sais-tu faire ?', 'Bilan de la journée', 'Comment vendre au carton ?'], 'spec' => null];
    }

    /** Client ou fournisseur demandé mais introuvable : noms proches proposés. */
    private static function notFound(string $who, string $name): array
    {
        $table = $who === 'client' ? 'customers' : 'suppliers';
        $w = Text::words($name);
        $rows = DB::all("SELECT name FROM $table WHERE active = 1 LIMIT 5000");
        $sc = [];
        foreach ($rows as $r) {
            $sc[$r['name']] = Text::itemScore($w, (string) $r['name']);
        }
        arsort($sc);
        $near = array_keys(array_filter(array_slice($sc, 0, 3, true), fn($v) => $v > 0.2));
        return self::reply('<p>Je n\'ai trouvé aucun ' . $who . ' correspondant à « <b>' . e($name) . '</b> ».' . ($near ? ' Vouliez-vous dire :' : ' Vérifiez l\'orthographe ou le numéro de téléphone.') . '</p>',
            ['suggestions' => array_map(fn($x) => 'Rapport du ' . $who . ' ' . $x, $near)]);
    }

    /** Salutations, remerciements, présentation. */
    private static function smallTalk(string $n): ?array
    {
        $name = explode(' ', (string) (Auth::user()['full_name'] ?? ''))[0];
        if (preg_match('/^(bonjour|bonsoir|salut|salam|salamalekoum|assalamou alaykoum|hello|coucou|bjr|slt)\b/', $n) && str_word_count($n) <= 4) {
            return self::briefing($name);
        }
        if (preg_match('/\b(briefing|point du jour|mon point|resume du jour|quoi de neuf aujourd hui)\b/', $n)) {
            return self::briefing($name);
        }
        if (preg_match('/^(merci|merci beaucoup|thanks|ok merci|super|parfait|top|genial|bravo)\b/', $n) && str_word_count($n) <= 4) {
            return self::reply('Avec plaisir' . ($name ? ', ' . e($name) : '') . ' ! Je reste à votre disposition.');
        }
        if (preg_match('/\b(qui es tu|tu es qui|ton nom|presente toi|que sais tu faire|que peux tu faire|tu sais faire quoi|^aide( moi)?$|capacites|fonctionnalites de marwa ia)\b/', $n)) {
            $can = [];
            foreach (Reports::KINDS as $k => [$perm, $label]) {
                if (Auth::can($perm)) {
                    $can[] = $label;
                }
            }
            return self::reply('<p>Je suis <b>MARWA IA</b>, l\'assistant intégré à POS Marwa. Je fonctionne <b>sur le serveur de votre boutique</b>, sans Internet : vos données ne sortent pas.</p>'
                . '<p><b>Je prépare vos rapports</b> (aperçu, PDF ou Excel) : ' . e(implode(', ', $can)) . '.</p>'
                . '<p><b>Je comprends</b> les périodes (« hier », « la semaine dernière », « en mars », « du 1er au 15 octobre »), les noms de vos clients, articles, fournisseurs et vendeurs, même avec une faute de frappe, et la suite de la conversation (« et en PDF ? », « et le mois dernier ? »).</p>'
                . '<p><b>Je réponds</b> à vos questions sur l\'utilisation du logiciel et de tous ses modules (« comment vendre au carton ? »).</p>'
                . '<p><b>Je vous forme</b> pas à pas, leçon par leçon, avec un petit quiz (« forme-moi à la caisse »).</p>'
                . '<p><b>Je vérifie</b> ce qui a été oublié dans la configuration (« vérifie ma configuration »).</p>'
                . '<p><b>Je vous conseille</b> avec des idées concrètes basées sur vos chiffres (« comment augmenter mes ventes ? », « idées pour réduire les vols »), je fais vos <b>calculs</b> (prix de vente, marge, TVA, remise, seuil de rentabilité) et j\'explique les mots de la gestion (« c\'est quoi le CMP ? »).</p>'
                . '<p>Et chaque jour, une <b>sagesse africaine</b> pour bien travailler.</p>' . Wisdom::html(Wisdom::ofDay()), ['suggestions' => ['Bilan de la semaine', 'Comment augmenter mes ventes ?', 'Ma formation', 'Vérifie ma configuration']]);
        }
        return null;
    }

    /** Citation ou proverbe africain, choisi selon le thème demandé. */
    private static function wisdom(string $n): array
    {
        $themes = ['equipe' => 'equipe|personnel|employe|ensemble', 'patience' => 'patience|courage|difficile|dur|fatigue|decourage', 'formation' => 'apprendre|savoir|formation|etude',
            'clients' => 'client|clients|fidelite', 'tresorerie' => 'argent|epargne|tresorerie', 'croissance' => 'reussite|reussir|succes|grandir|croissance|motivation|motive'];
        $q = Wisdom::ofDay();
        foreach ($themes as $t => $re) {
            if (preg_match('/\b(' . $re . ')/', $n)) {
                $q = Wisdom::forTheme($t);
                break;
            }
        }
        if (preg_match('/\b(autre|encore|un autre|une autre)\b/', $n)) {
            $q = Wisdom::QUOTES[random_int(0, count(Wisdom::QUOTES) - 1)];
        }
        return self::reply(Wisdom::html($q) . '<p class="small">Une sagesse d\'Afrique pour bien travailler aujourd\'hui.</p>', ['type' => 'wisdom', 'suggestions' => ['Une autre citation', 'Un proverbe sur le travail en équipe', 'Comment augmenter mes ventes ?']]);
    }

    /** Briefing du jour : citation, chiffres clés, points de configuration, modules. */
    public static function briefing(string $name): array
    {
        $h = (int) date('G');
        $html = '<p class="h">' . ($h >= 18 ? 'Bonsoir' : 'Bonjour') . ($name ? ' ' . e($name) : '') . ' ! Voici votre point du jour.</p>';
        $html .= Wisdom::html(Wisdom::ofDay());
        $items = [];
        try {
            if (Auth::can('reports.view')) {
                $s = \App\Services\ReportService::summary(date('Y-m-d'), date('Y-m-d'));
                $items[] = $s['tickets'] > 0 ? 'Aujourd\'hui : <b>' . money($s['net']) . '</b> pour ' . (int) $s['tickets'] . ' vente(s).' : 'Pas encore de vente aujourd\'hui : la journée commence !';
            }
            if (Auth::can('stock.view')) {
                $out = count(array_filter(\App\Services\ReportService::lowStock(200), fn($r) => (float) $r['qty'] <= 0));
                if ($out) {
                    $items[] = '<b>' . $out . ' article(s) en rupture</b> : demandez-moi « que dois-je commander ? ».';
                }
            }
            $items = array_merge($items, Modules::briefing());
            $todo = Checkup::count();
            if ($todo) {
                $items[] = '<b>' . $todo . ' point(s) de configuration</b> à revoir : demandez-moi « vérifie ma configuration ».';
            }
        } catch (\Throwable) {
            // le briefing ne doit jamais bloquer la salutation
        }
        if ($items) {
            $html .= '<ul class="mia-facts">' . implode('', array_map(fn($i) => '<li>' . $i . '</li>', $items)) . '</ul>';
        }
        $html .= '<p>Je peux préparer vos rapports, vous <b>former</b> pas à pas, vérifier ce qui a été <b>oublié</b> dans la configuration et vous donner des <b>idées</b> pour mieux gérer.</p>';
        $sugg = Auth::can('reports.view') ? ['Bilan de la journée', 'Comment augmenter mes ventes ?', 'Vérifie ma configuration', 'Ma formation'] : ['Ma formation', 'Comment vendre au carton ?', 'Comment clôturer la caisse ?'];
        return self::reply($html, ['type' => 'briefing', 'suggestions' => $sugg]);
    }

    private static function reply(string $html, array $extra = []): array
    {
        return $extra + ['ok' => true, 'type' => 'chat', 'html' => str_starts_with($html, '<') ? $html : '<p>' . $html . '</p>', 'actions' => [], 'suggestions' => [], 'spec' => null];
    }
}
