<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Services\Menu;

/**
 * Connaissance de POS Marwa : questions fréquentes rédigées, documentation du logiciel
 * (guide d'utilisation, notices des modules, installation) et menu de l'utilisateur.
 * Recherche locale par pertinence (BM25 sur les racines des mots), sans Internet.
 */
final class Knowledge
{
    /**
     * Questions fréquentes : [mots-clés, question, réponse (HTML simple), liens [libellé, chemin]].
     * Les réponses suivent le logiciel réel (menus et boutons de la version 2.14).
     */
    public const FAQ = [
        ['vendre carton paquet conditionnement pack lot casier unite', 'Comment vendre au carton ?',
            '<ol><li>Ouvrez la fiche de l\'article → <b>Modifier</b>.</li><li>En bas, partie <b>Conditionnements</b> : nom (« Carton de 24 »), nombre d\'unités (24), prix du carton, code-barres facultatif → <b>Ajouter</b>.</li><li>En caisse, le bouton « Carton de 24 » apparaît sous l\'article (ou scannez le code du carton).</li></ol>Le stock reste compté à l\'unité : un carton vendu fait sortir 24 unités.', [['Articles', '/products']]],
        ['ouvrir caisse fond matin debut journee', 'Comment ouvrir la caisse ?',
            'Touchez <b>Ouvrir la caisse</b> (ou <b>Ma caisse</b>), saisissez le <b>fond de caisse</b> compté dans le tiroir, puis validez. Chaque caissier ouvre sa propre caisse avec son compte ; l\'utilisateur doit être rattaché à une caisse (Configuration → Utilisateurs).', [['Ma caisse', '/cash']]],
        ['cloturer cloture fermer caisse soir fin journee ecart comptage', 'Comment clôturer la caisse ?',
            '<ol><li>Saisissez d\'abord les dépenses de la journée.</li><li><b>Ma caisse</b> : le résumé calcule les <b>espèces théoriques</b>.</li><li>Comptez par coupure ou saisissez le total compté ; commentez tout écart.</li><li><b>Clôturer et imprimer l\'état</b>.</li></ol>Ensuite, versez l\'argent : module Banques → Clôtures à verser.', [['Ma caisse', '/cash']]],
        ['vente credit client doit dette plafond', 'Comment vendre à crédit ?',
            'En caisse : <b>Client</b> (F4) → choisissez le client → ajoutez les articles → bouton <b>Crédit</b> → encaissez l\'éventuel acompte → validez. Le reste est inscrit sur son compte, dans la limite de son <b>plafond</b> (au-delà, un responsable doit autoriser). Pour le règlement : fiche du client → <b>Encaisser</b>.', [['Clients & crédits', '/customers']]],
        ['reglement paiement client rembourse dette encaisser recu', 'Comment enregistrer le paiement d\'un client qui doit ?',
            'Ouvrez <b>Clients & crédits</b> → le client → partie <b>Encaisser</b> : « Règlement de dette », montant, mode → <b>Encaisser et imprimer le reçu</b>. Le solde diminue aussitôt.', [['Clients & crédits', '/customers']]],
        ['remise reduction rabais promotion prix', 'Comment faire une remise ?',
            'Sélectionnez la ligne → <b>Remise</b> (F7), en montant ou en pourcentage, ou une remise sur tout le ticket. Au-delà du plafond de remise (Paramètres), un responsable saisit son identifiant et son mot de passe ou PIN. Pour des prix réduits durables : <b>prix promotionnel avec dates</b> ou <b>prix de gros</b> dans la fiche article.', []],
        ['retour rembourser remboursement avoir rendre article', 'Comment faire un retour ou un remboursement ?',
            'Historique des ventes → ouvrez la vente → <b>Retour / remboursement</b> : quantités rendues, mode de remboursement, motif. Le stock remonte et le remboursement sort de la caisse.', [['Historique des ventes', '/sales']]],
        ['annuler vente erreur supprimer ticket', 'Comment annuler une vente ?',
            'Ouvrez la vente → <b>Annuler la vente</b> (responsable, dans les 24 h) : tout est contre-passé automatiquement (stock, caisse, crédit). Une vente n\'est jamais effacée.', [['Historique des ventes', '/sales']]],
        ['reimprimer ticket duplicata facture a4 copie', 'Comment réimprimer un ticket ou faire une facture ?',
            'En caisse : <b>Tickets</b> → icône imprimante (copie marquée DUPLICATA). Ou Historique des ventes → la vente → <b>Ticket</b>, <b>Facture A4</b> ou <b>Bon de livraison</b>.', [['Historique des ventes', '/sales']]],
        ['article creer nouveau produit ajouter fiche', 'Comment créer un article ?',
            'Gestion des articles → <b>Articles</b> → <b>Nouveau</b> : nom, code-barres (ou <b>Générer</b>), catégorie, prix de vente TTC, prix d\'achat, seuil d\'alerte, stock de départ → <b>Enregistrer</b>. Pour beaucoup d\'articles : <b>Importer</b> un fichier Excel/CSV.', [['Articles', '/products'], ['Nouvel article', '/products/create']]],
        ['code barre generer etiquette imprimer douchette', 'Comment créer et imprimer des codes-barres ?',
            'Dans la fiche article, bouton <b>Générer</b> (code EAN-13 interne). Puis Gestion des articles → <b>Étiquettes code-barres</b> : ajoutez les articles, choisissez les informations et le format (planche A4 ou rouleau), vérifiez l\'aperçu et imprimez.', [['Étiquettes', '/labels']]],
        ['reception livraison fournisseur achat marchandise entree stock', 'Comment enregistrer une livraison de fournisseur ?',
            'Achats & fournisseurs → Réceptions → <b>Réception directe</b> (ou depuis une commande) : fournisseur, articles, quantités, prix d\'achat (lot et péremption si besoin), choix du coût (prix moyen conseillé) → <b>Valider la réception</b>. Stock, coût moyen et dette fournisseur sont mis à jour.', [['Réception directe', '/receptions/create']]],
        ['payer fournisseur dette regler', 'Comment payer un fournisseur ?',
            'Achats & fournisseurs → Fournisseurs → le fournisseur → <b>Enregistrer un paiement</b> : montant, mode, référence ; cochez « espèces prélevées dans ma caisse » si l\'argent sort du tiroir → <b>Payer</b>.', [['Fournisseurs', '/suppliers']]],
        ['inventaire compter stock ecart', 'Comment faire un inventaire ?',
            'Gestion des articles → Inventaires → <b>Démarrer</b> (entrepôt, catégorie facultative). Comptez par scan (+1 par lecture) ou par saisie, contrôlez les écarts, puis un responsable <b>valide</b> : les écarts deviennent des mouvements de stock.', [['Inventaires', '/inventories']]],
        ['perte casse perime ajustement stock corriger', 'Comment déclarer une perte ou corriger le stock ?',
            'Gestion des articles → <b>Ajustement / pertes</b> : article, quantité, <b>motif obligatoire</b> (casse, périmé, vol…). Pour une correction générale, faites plutôt un inventaire.', [['Ajustement de stock', '/stock/adjust']]],
        ['depense charge sortie argent transport electricite loyer', 'Comment enregistrer une dépense ?',
            'Trésorerie & banques → <b>Dépenses & recettes</b> : Dépense, catégorie, montant, description, mode ; cochez « Via ma caisse ouverte » si l\'argent sort du tiroir → <b>Enregistrer</b>. Une erreur se contrepasse (jamais d\'effacement).', [['Dépenses & recettes', '/expenses']]],
        ['devis proforma facture proforma offre prix', 'Comment faire un devis ?',
            'Gestion des ventes → <b>Devis</b> → Nouveau devis : client, articles, quantités, validité → <b>Enregistrer le devis</b> (ni stock ni caisse). Imprimez-le ; une fois accepté, <b>Convertir en vente</b>.', [['Devis', '/quotes'], ['Nouveau devis', '/quotes/create']]],
        ['utilisateur employe compte creer caissier ajouter', 'Comment créer un compte pour un employé ?',
            'Configuration → <b>Utilisateurs</b> → Nouveau : nom, identifiant, mot de passe, code PIN, <b>rôle</b>, boutique et <b>caisse</b>. Les droits se règlent dans <b>Rôles & permissions</b>.', [['Utilisateurs', '/users'], ['Rôles & permissions', '/roles']]],
        ['mot passe oublie pin bloque debloquer', 'J\'ai oublié mon mot de passe / mon compte est bloqué',
            'Après plusieurs erreurs, le compte est bloqué 15 minutes. Un administrateur peut réinitialiser le mot de passe ou le PIN dans Configuration → <b>Utilisateurs</b>. Chacun change le sien dans <b>Mon mot de passe / PIN</b>.', [['Mon mot de passe', '/account/password']]],
        ['imprimante ticket imprime pas thermique reseau usb', 'Le ticket ne s\'imprime pas / installer une imprimante',
            'Vérifiez la case <b>TICKET</b>, le papier et l\'alimentation. Configuration → <b>Imprimantes</b> : imprimante réseau (ex. 192.168.1.50:9100) ou partage Windows (ex. \\\\localhost\\XP-80), usages, caisse → <b>Tester</b>. Sans imprimante déclarée, l\'impression passe par le navigateur.', [['Imprimantes', '/printers']]],
        ['internet coupure hors connexion reseau panne', 'Que faire en cas de coupure d\'Internet ?',
            'Continuez à vendre : la caisse passe <b>hors connexion</b> (bandeau rouge), chaque vente reçoit un numéro provisoire et part automatiquement au retour de la connexion. Impossible hors connexion : créer un client, mettre en attente, autorisations d\'un responsable. Il faut avoir ouvert la caisse une fois avec la connexion.', []],
        ['sauvegarde backup restaurer donnees perte', 'Comment sauvegarder mes données ?',
            'Système & maintenance → <b>Sauvegardes</b> : sauvegardes automatiques et avant chaque mise à jour ; bouton pour en faire une tout de suite. Gardez chaque semaine une copie hors du PC (clé USB). La restauration remplace les données : faites-la avec l\'aide de High Tech Niger.', [['Sauvegardes', '/backups']]],
        ['mise a jour version nouvelle installer update', 'Comment mettre à jour POS Marwa ?',
            'Système & maintenance → <b>Mise à jour du logiciel</b> : choisissez le dossier décompressé « pos-marwa », mot de passe, <b>Envoyer et vérifier</b>, puis <b>Sauvegarder puis installer</b>. Retour possible à la version précédente.', [['Mise à jour', '/update']]],
        ['licence activation essai cle lecture seule', 'Licence et activation',
            'Communiquez l\'<b>identifiant d\'installation</b> (page Licence) à l\'auteur (+227 92 08 12 82 / 87 22 52 02), collez la clé reçue → <b>Activer</b>. Sans licence, après 30 jours d\'essai, POS Marwa passe en lecture seule (données conservées).', [['Licence', '/licence']]],
        ['tva taxe ht ttc taux', 'Comment régler la TVA ?',
            'Paramètres → <b>TVA</b> : pas de TVA, TVA générale (taux au choix) ou taux par article. Les prix restent saisis TTC ; la TVA est calculée à la vente. Rapport : Finances & rapports → <b>TVA</b>.', [['Paramètres', '/settings']]],
        ['devise monnaie fcfa symbole', 'Changer la devise',
            'Paramètres → <b>Devise</b> : FCFA par défaut ; symbole, position et décimales modifiables. Les montants déjà enregistrés ne sont pas convertis.', [['Paramètres', '/settings']]],
        ['mobile money airtel moov zamani operateur paiement telephone', 'Encaisser par mobile money',
            'Bouton <b>Espèces</b> (F10) → <b>Mobile money</b> → choisissez l\'opérateur, saisissez la <b>référence</b> de la transaction, validez après avoir vu la confirmation de l\'opérateur. La liste des opérateurs se règle dans Paramètres.', []],
        ['telephone smartphone tablette mobile qr acces', 'Utiliser POS Marwa sur un téléphone',
            'Configuration → <b>Accès mobile (QR code)</b> : scannez le QR code avec le téléphone (même Wi-Fi), puis connectez-vous. <b>Appareils connectés</b> permet de révoquer un appareil perdu.', [['Accès mobile', '/mobile']]],
        ['ecran client second ecran afficheur double ecran video image publicite', 'Écran client et afficheur client',
            'Configuration → <b>Écran et afficheur client</b> : activez l\'écran client, ajoutez images et vidéos, puis en caisse touchez l\'icône d\'écran. L\'afficheur 2 lignes se branche en USB/série sur le poste ou au serveur (port COM ou réseau).', [['Écran et afficheur client', '/settings/display']]],
        ['module installer ajouter restaurant pharmacie clinique hotel pressing ecole', 'Installer un module',
            'Système & maintenance → <b>Modules complémentaires</b> → <b>Installer</b> sur le module voulu (restaurant, clinique, hôtel, établissement, pressing, banques, comptabilité, reconnaissance, voix, assistance, MARWA IA). Désactiver masque le module sans effacer ses données.', [['Modules', '/addons']]],
        ['mode activite fonctionnement commerce pharmacie changer', 'Changer le mode de fonctionnement',
            'Paramètres → <b>Mode de fonctionnement de la caisse</b> : Commerce, Restaurant, Pharmacie, Clinique, Clinique + Pharmacie, Pressing, Hôtel, Établissement. La caisse et les menus s\'adaptent ; aucune donnée n\'est supprimée.', [['Paramètres', '/settings']]],
        ['mettre attente reprendre client oublie', 'Mettre une vente en attente',
            'En caisse : <b>Attente</b> (F8) garde la vente ; servez le client suivant ; <b>Reprendre</b> rappelle la vente.', []],
        ['raccourci clavier touche f9 f10 f2', 'Raccourcis clavier de la caisse',
            'F2 recherche · F4 client · F6 quantité · F7 remise · F8 attente · F9 valider (espèces) · F10 fenêtre d\'encaissement · F12 vider le panier · Entrée : nouvelle vente. Modifiables dans Paramètres.', []],
        ['peremption perime lot fefo pharmacie expiration', 'Suivre les péremptions',
            'Saisissez lot et date de péremption à la réception. POS Marwa vend d\'abord les lots qui périment le plus tôt (FEFO) et bloque les lots périmés. Liste : Gestion des articles → <b>Péremptions</b>. Demandez-moi aussi « produits qui périment ».', [['Péremptions', '/stock/expiry']]],
        ['assistance aide technicien distance support depannage', 'Obtenir de l\'aide de High Tech Niger',
            'Configuration → <b>Assistance à distance</b> (module MARWA REMOTE ASSIST) : le technicien voit l\'écran sans arrêter la caisse. Ou appelez / WhatsApp : <b>+227 92 08 12 82</b> ou <b>+227 87 22 52 02</b>.', []],
        ['role permission droit acces interdit refuse', 'Pourquoi une page ou une action m\'est refusée ?',
            'Votre <b>rôle</b> ne contient pas la permission nécessaire. Un administrateur peut l\'ajouter dans Configuration → <b>Rôles & permissions</b>, ou un responsable autorise ponctuellement (remise, prix, annulation).', [['Rôles & permissions', '/roles']]],
        ['marge benefice calcul cout moyen cmp', 'Comment la marge est-elle calculée ?',
            'Marge = prix de vente − <b>coût moyen pondéré</b> (CMP). À chaque réception, CMP = (ancien stock × ancien coût + quantité reçue × nouveau coût) ÷ stock total. Bénéfice = marge brute − dépenses + recettes diverses. Demandez-moi « mon bénéfice du mois ».', []],
        ['transfert entrepot magasin depot deplacer stock', 'Transférer du stock entre entrepôts',
            'Gestion des articles → <b>Transferts</b> → nouveau transfert : entrepôt de départ et d\'arrivée, articles, quantités.', [['Transferts', '/stock/transfers']]],
        ['prix gros grossiste quantite', 'Vendre au prix de gros',
            'Dans la fiche article : <b>prix de gros</b> et quantité minimale. En caisse, cochez « Vente en gros » (selon les droits) : le prix de gros s\'applique dès la quantité minimale.', []],
        ['scanner camera code barre telephone photo', 'Scanner avec la caméra',
            'Bouton <b>caméra</b> à côté du champ code-barres : un bip = un article. Sans HTTPS, utilisez « Photo du code ». La douchette USB ou Bluetooth fonctionne toujours, sans réglage.', []],
        ['langue francais anglais arabe haoussa', 'Changer de langue',
            'Chaque utilisateur choisit sa langue dans <b>Mon compte</b> ou sur l\'écran de connexion : français, anglais, arabe, haoussa, espagnol, portugais, turc.', []],
        ['marwa ia assistant que sais tu faire capacites aide', 'Que sait faire MARWA IA ?',
            'Je réponds à vos questions sur POS Marwa et je prépare vos rapports en un instant : relevé d\'un client ou d\'un fournisseur, ventes d\'une période, d\'un vendeur ou d\'un mode de paiement, meilleures ventes, articles dormants, stock, ruptures, péremptions, créances, bénéfice, dépenses, écarts de caisse, bilan avec conseils, réapprovisionnement, prévision du mois. Ajoutez « en PDF » ou « en Excel » pour télécharger.', []],
    ];

    /** Fichiers de documentation indexés (relatifs à la racine). */
    private static function files(): array
    {
        $f = [BASE_PATH . '/docs/GUIDE_UTILISATEUR.md'];
        foreach (['INSTALLATION_WAMPSERVER.md', 'HEBERGEMENT_O2SWITCH.md', 'DEMARRAGE_WINDOWS.md', 'MODULES.md'] as $d) {
            if (is_file(BASE_PATH . '/docs/' . $d)) {
                $f[] = BASE_PATH . '/docs/' . $d;
            }
        }
        foreach (glob(BASE_PATH . '/addons/*/README.md') ?: [] as $r) {
            $f[] = $r;
        }
        return array_values(array_filter($f, 'is_file'));
    }

    /** Sections Markdown (titre, texte), avec cache sur disque tant que les fichiers ne changent pas. */
    public static function sections(): array
    {
        static $mem = null;
        if ($mem !== null) {
            return $mem;
        }
        $files = self::files();
        $sig = md5(implode('|', array_map(fn($f) => $f . filemtime($f), $files)));
        $cache = BASE_PATH . '/storage/cache/marwa_ia_index.json';
        if (is_file($cache) && ($c = json_decode((string) file_get_contents($cache), true)) && ($c['sig'] ?? '') === $sig) {
            return $mem = $c['sections'];
        }
        $out = [];
        foreach ($files as $f) {
            $src = basename(dirname($f)) === 'docs' ? 'Guide' : 'Module ' . basename(dirname($f));
            $title = $src;
            $buf = '';
            $flush = function () use (&$out, &$title, &$buf, $src) {
                $t = trim($buf);
                if (mb_strlen($t) > 40) {
                    $out[] = ['t' => $title, 's' => $src, 'x' => $t, 'k' => Text::stems($title . ' ' . $title . ' ' . $t)];
                }
                $buf = '';
            };
            foreach (preg_split('/\R/', (string) file_get_contents($f)) as $line) {
                if (preg_match('/^#{1,4}\s+(.+)$/', $line, $m)) {
                    $flush();
                    $title = trim($m[1]);
                    continue;
                }
                $buf .= $line . "\n";
            }
            $flush();
        }
        if (is_dir(dirname($cache)) || @mkdir(dirname($cache), 0775, true)) {
            @file_put_contents($cache, json_encode(['sig' => $sig, 'sections' => $out], JSON_UNESCAPED_UNICODE));
        }
        return $mem = $out;
    }

    /**
     * Meilleures réponses pour une question d'aide.
     * @return array{faq: ?array, docs: array, menu: array, score: float}
     */
    public static function search(string $q): array
    {
        $qs = array_values(array_unique(Text::stems($q)));
        // Questions fréquentes : recouvrement des mots-clés
        $faq = null;
        $best = 0.0;
        foreach (self::FAQ as $f) {
            $keys = array_unique(array_merge(Text::stems($f[0]), Text::stems($f[1])));
            $hit = 0.0;
            foreach ($qs as $w) {
                foreach ($keys as $k) {
                    if ($w === $k || (strlen($w) >= 4 && strlen($k) >= 4 && (str_starts_with($k, $w) || str_starts_with($w, $k))) || Text::close($w, $k)) {
                        $hit += 1;
                        break;
                    }
                }
            }
            $score = $qs ? $hit / max(2, count($qs)) * min(1, $hit / 2) : 0;
            if ($score > $best) {
                $best = $score;
                $faq = $f;
            }
        }
        // Documentation : BM25
        $secs = self::sections();
        $N = max(1, count($secs));
        $df = [];
        $avg = 0;
        foreach ($secs as $s) {
            $avg += count($s['k']);
            foreach (array_unique($s['k']) as $k) {
                $df[$k] = ($df[$k] ?? 0) + 1;
            }
        }
        $avg = $avg / $N;
        $scored = [];
        foreach ($secs as $i => $s) {
            $tf = array_count_values($s['k']);
            $len = count($s['k']);
            $sc = 0.0;
            foreach ($qs as $w) {
                if (!isset($tf[$w])) {
                    continue;
                }
                $idf = log(1 + ($N - $df[$w] + 0.5) / ($df[$w] + 0.5));
                $sc += $idf * ($tf[$w] * 2.2) / ($tf[$w] + 1.2 * (0.25 + 0.75 * $len / max(1, $avg)));
            }
            if ($sc > 0) {
                $scored[$i] = $sc;
            }
        }
        arsort($scored);
        $docs = [];
        foreach (array_slice($scored, 0, 3, true) as $i => $sc) {
            $docs[] = $secs[$i] + ['score' => $sc];
        }
        return ['faq' => $best >= 0.45 ? $faq : null, 'faqScore' => $best, 'docs' => $docs, 'menu' => self::menu($q)];
    }

    /** Pages du menu (autorisées pour l'utilisateur) qui correspondent à la question. */
    public static function menu(string $q): array
    {
        $qw = Text::words($q);
        $out = [];
        foreach (Menu::visible() as $group => $items) {
            foreach ($items as $it) {
                $sc = Text::nameScore($qw, $it[0]);
                if ($sc >= 0.5) {
                    $out[] = ['label' => $it[0], 'url' => url($it[2]), 'group' => $group, 'score' => $sc];
                }
            }
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, 4);
    }

    /** Markdown de la documentation → HTML court et sûr. */
    public static function html(string $md, int $max = 1400): string
    {
        $md = (string) preg_replace('/```.*?```/s', '', $md);
        $lines = preg_split('/\R/', trim($md));
        $html = '';
        $inList = false;
        $len = 0;
        foreach ($lines as $l) {
            if ($len > $max) {
                $html .= ($inList ? '</ul>' : '') . '<p class="more">…</p>';
                $inList = false;
                break;
            }
            $t = trim($l);
            if ($t === '' || str_starts_with($t, '|') || str_starts_with($t, '<')) {
                if ($inList) {
                    $html .= '</ul>';
                    $inList = false;
                }
                continue;
            }
            $isItem = (bool) preg_match('/^([-*]|\d+\.)\s+(.*)$/', $t, $m);
            $txt = self::inline($isItem ? $m[2] : $t);
            $len += mb_strlen(strip_tags($txt));
            if ($isItem) {
                $html .= ($inList ? '' : '<ul>') . '<li>' . $txt . '</li>';
                $inList = true;
            } else {
                $html .= ($inList ? '</ul>' : '') . '<p>' . $txt . '</p>';
                $inList = false;
            }
        }
        return $html . ($inList ? '</ul>' : '');
    }

    private static function inline(string $s): string
    {
        $s = e($s);
        $s = (string) preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $s);
        $s = (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<i>$1</i>', $s);
        return (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    }
}
