<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Addons;
use App\Core\Auth;
use App\Core\DB;

/**
 * Formation des utilisateurs : leçons pas à pas (selon le rôle et les modules actifs),
 * mini-quiz à trois choix corrigé et expliqué, et suivi de la progression de chacun.
 */
final class Trainer
{
    /**
     * code => [titre, permission (null = tous), mots-clés, étapes [texte, lien|null], quiz [[question, [A, B, C], bonne (0-2), explication]], module (null = cœur)]
     */
    public const LESSONS = [
        'demarrer' => ['Prise en main de POS Marwa', null, 'prise main demarrer debut commencer debutant nouveau menu connexion connecter base', [
            ['<b>Connexion</b> : choisissez votre nom, tapez votre code PIN puis <b>GO</b> (ou identifiant + mot de passe).', null],
            ['Le <b>menu de gauche</b> regroupe les modules par catégories ; la loupe en haut retrouve un écran par son nom.', null],
            ['Le bouton <b>Menu</b> affiche tous vos modules en grandes icônes. Vous ne voyez que ce que votre rôle permet.', null],
            ['La <b>caisse</b> s\'ouvre par le bouton « Caisse » : c\'est l\'écran de vente.', '/pos'],
            ['Une question ? Écrivez-la ici, à MARWA IA, avec vos mots : « comment faire une remise ? ».', '/ia'],
        ], [
            ['Où retrouver rapidement un écran dont vous connaissez le nom ?', ['Dans la recherche en haut du menu', 'Dans les paramètres', 'Il faut redémarrer le logiciel'], 0, 'La recherche du menu filtre les écrans au fur et à mesure de la frappe.'],
            ['Pourquoi un caissier ne voit-il pas le menu « Rapports » ?', ['Le logiciel est en panne', 'Son rôle ne le permet pas', 'Il faut une connexion Internet'], 1, 'Chaque rôle ne voit que les modules autorisés : c\'est voulu, pour la sécurité.'],
            ['Après plusieurs erreurs de mot de passe…', ['Le compte est supprimé', 'Rien ne se passe', 'Le compte est bloqué 15 minutes'], 2, 'Un administrateur peut aussi le débloquer avant.'],
        ], null],
        'caisse' => ['Vendre à la caisse', 'pos.sell', 'caisse vendre vente encaisser ticket client payer paiement monnaie rendre valider', [
            ['<b>Ouvrez la caisse</b> le matin en saisissant le fond de caisse compté dans le tiroir.', '/cash'],
            ['<b>Ajoutez les articles</b> : scannez le code-barres, touchez une tuile ou cherchez par nom (F2). Les touches 2 à 9 donnent la quantité du prochain article.', '/pos'],
            ['Pour un <b>carton</b> ou un pack, touchez le bouton « Carton de 24 » sous l\'article.', null],
            ['<b>Client</b> (F4) : recherche par nom ou téléphone, création rapide. Indispensable pour le crédit.', null],
            ['Tapez l\'<b>argent du client</b> en bas : la monnaie à rendre s\'affiche. <b>Valider</b> (F9) encaisse en espèces.', null],
            ['Pour le <b>mobile money</b>, la carte ou un paiement mixte : bouton <b>Espèces</b> (F10), choisissez le mode et l\'opérateur.', null],
            ['Client qui va chercher un article ? <b>F8</b> met la vente en attente ; <b>Reprendre</b> la rappelle.', null],
        ], [
            ['Le client donne 5 000 F pour un total de 3 750 F. Que faites-vous ?', ['Je tape 5 000 dans « Argent client » puis Valider', 'Je change le prix', 'J\'annule la vente'], 0, 'La monnaie à rendre (1 250 F) s\'affiche avant la validation.'],
            ['Quelle touche met une vente en attente ?', ['F2', 'F8', 'F12'], 1, 'F8 met en attente, F2 recherche, F12 vide le panier.'],
            ['Le client paie par Airtel Money. Où le choisir ?', ['Valider directement', 'Bouton Espèces (F10) puis Mobile money et l\'opérateur', 'Ce n\'est pas possible'], 1, 'La fenêtre d\'encaissement gère mobile money, carte, virement et paiement mixte.'],
        ], null],
        'articles' => ['Créer les articles, les cartons et les étiquettes', 'products.manage', 'article articles produit produits creer ajouter carton cartons conditionnement code barre barres etiquette etiquettes prix', [
            ['Articles → <b>Nouvel article</b> : nom, catégorie, <b>prix d\'achat</b>, <b>prix de vente</b>, stock minimum.', '/products/create'],
            ['Pas de code-barres ? Bouton <b>Générer</b> à côté du champ, puis imprimez l\'étiquette.', null],
            ['Pour vendre au <b>carton</b> : fiche de l\'article → Modifier → <b>Conditionnements</b> : « Carton de 24 », 24 unités, prix du carton → Ajouter.', '/products'],
            ['Beaucoup d\'articles ? <b>Importez</b> votre liste Excel (modèle téléchargeable).', '/products/import'],
            ['<b>Étiquettes</b> : choisissez les articles et le format, puis imprimez la planche.', '/products/labels'],
        ], [
            ['Un carton contient 24 bouteilles. Combien d\'unités sortent du stock quand on vend 1 carton ?', ['1', '24', 'Aucune'], 1, 'Le stock reste compté à l\'unité : un carton vendu fait sortir 24 unités.'],
            ['Pourquoi le prix d\'achat est-il important ?', ['Il calcule le bénéfice réel', 'Il s\'imprime sur le ticket', 'Il n\'a aucune utilité'], 0, 'Sans prix d\'achat, le bénéfice affiché est faux.'],
            ['Un article n\'a pas de code-barres. Que faire ?', ['Ne pas le vendre', 'Bouton Générer puis imprimer l\'étiquette', 'Écrire le prix au stylo'], 1, 'Le code interne commence par 21 et se scanne comme les autres.'],
        ], null],
        'achats' => ['Recevoir la marchandise (achats)', 'purchases.manage', 'achat achats reception receptionner fournisseur fournisseurs commande marchandise arrivage livraison', [
            ['Achats → <b>Réceptions</b> → <b>Réception directe</b> (ou depuis une commande fournisseur).', '/receptions/create'],
            ['Choisissez le <b>fournisseur</b>, scannez les articles, saisissez quantités et <b>prix d\'achat</b> (lot et péremption si besoin).', null],
            ['Prix qui change ? <b>Prix moyen</b> (par défaut), nouveau prix ou ancien prix pour chaque ligne.', null],
            ['La colonne <b>Nouveau prix de vente</b> met à jour le prix en caisse en même temps.', null],
            ['Validez : le stock, le coût moyen et la <b>dette fournisseur</b> sont mis à jour.', '/suppliers'],
        ], [
            ['Que met à jour une réception validée ?', ['Seulement le stock', 'Le stock, le coût moyen et la dette fournisseur', 'Rien tant que le fournisseur n\'est pas payé'], 1, 'Tout est mis à jour en une seule opération.'],
            ['Le prix d\'achat a augmenté. Quel choix garde un coût juste pour tout le stock ?', ['Prix moyen', 'Supprimer l\'article', 'Ne rien saisir'], 0, 'Le prix moyen pondère l\'ancien stock et le nouveau.'],
            ['Où changer le prix de vente au moment de la réception ?', ['Ce n\'est pas possible', 'Colonne « Nouveau prix de vente »', 'Dans les paramètres'], 1, 'Le changement est historisé.'],
        ], null],
        'stock' => ['Stock, inventaire et pertes', 'stock.view', 'stock stocks inventaire inventaires compter comptage perte pertes casse perime ajustement ecart transfert', [
            ['<b>Stock</b> : quantités par entrepôt, alertes de rupture et de stock minimum.', '/stock'],
            ['Casse, périmé, vol : <b>Ajustement de stock</b> avec un <b>motif obligatoire</b>, jamais une correction « à la main ».', '/stock/adjust'],
            ['<b>Inventaire</b> : Stock → Inventaires → <b>Démarrer</b>, choisissez l\'entrepôt (et une catégorie pour un inventaire partiel).', '/inventories'],
            ['Comptez par <b>scan</b> (chaque lecture ajoute 1) ou par <b>saisie</b> ; tout est enregistré : vous pouvez reprendre plus tard.', null],
            ['Un responsable <b>valide</b> : les écarts deviennent des mouvements de stock.', null],
        ], [
            ['Une bouteille est cassée. Que faire ?', ['Ajustement de stock avec le motif « casse »', 'La vendre à 0 F', 'Ne rien faire'], 0, 'Le motif permet de comprendre les pertes dans les rapports.'],
            ['Pendant l\'inventaire, vous scannez 15 fois le même article. Quantité comptée ?', ['1', '15', '0'], 1, 'Chaque lecture ajoute 1.'],
            ['Quand le stock est-il corrigé après un inventaire ?', ['Dès le premier scan', 'À la validation par un responsable', 'Le lendemain'], 1, 'L\'inventaire est ensuite verrouillé.'],
        ], null],
        'clients' => ['Clients, crédit et recouvrement', 'customers.view', 'client clients credit credits dette dettes doit plafond reglement recouvrement relance fidelite', [
            ['<b>Clients & crédits</b> → Nouveau client : nom, <b>téléphone</b>, et un <b>plafond de crédit</b> raisonnable.', '/customers'],
            ['Vente à crédit en caisse : <b>Client</b> (F4) → articles → bouton <b>Crédit</b> → acompte éventuel → valider.', '/pos'],
            ['Au-delà du plafond, un responsable doit autoriser avec son code.', null],
            ['Quand le client paie : sa fiche → <b>Encaisser</b> → « Règlement de dette » → reçu imprimé.', '/customers'],
            ['Demandez-moi « qui me doit de l\'argent ? » pour la liste à relancer.', null],
        ], [
            ['Que faut-il choisir avant de vendre à crédit ?', ['Le client', 'L\'imprimante', 'La langue'], 0, 'Le crédit est inscrit sur le compte du client.'],
            ['Un client dépasse son plafond. Que se passe-t-il ?', ['La vente passe sans contrôle', 'Un responsable doit autoriser', 'Le client est supprimé'], 1, 'Le plafond protège votre trésorerie.'],
            ['Où enregistrer le paiement d\'une dette ?', ['Fiche du client → Encaisser', 'Ajustement de stock', 'Dans les dépenses'], 0, 'Le solde diminue aussitôt et un reçu s\'imprime.'],
        ], null],
        'cloture' => ['Clôturer la caisse le soir', 'cash.open_close', 'cloture cloturer fermer fin journee soir comptage compter ecart billets', [
            ['Saisissez d\'abord les <b>dépenses</b> payées avec l\'argent de la caisse.', '/expenses'],
            ['<b>Ma caisse</b> : le résumé calcule les <b>espèces théoriques</b>.', '/cash'],
            ['Comptez les billets et pièces <b>par coupure</b> ; le logiciel fait le total.', null],
            ['<b>Commentez tout écart</b>, puis <b>Clôturer et imprimer l\'état</b>.', null],
            ['Versez ensuite l\'argent : module Banques → Clôtures à verser.', null],
        ], [
            ['Que saisir avant de clôturer ?', ['Les dépenses payées avec la caisse', 'Un nouvel article', 'Rien'], 0, 'Sinon la caisse paraît en manque.'],
            ['La caisse a 500 F de moins que prévu. Que faire ?', ['Ajouter 500 F de sa poche sans rien dire', 'Commenter l\'écart lors de la clôture', 'Ne pas clôturer'], 1, 'Un écart commenté se comprend ; un écart caché se répète.'],
            ['Faut-il clôturer chaque jour ?', ['Oui, chaque soir', 'Une fois par mois', 'Jamais'], 0, 'Une clôture quotidienne permet de contrôler l\'argent.'],
        ], null],
        'rapports' => ['Lire ses rapports et son bénéfice', 'reports.view', 'rapport rapports chiffre affaires benefice marge statistique analyse resultat tableau bord', [
            ['<b>Rapports</b> : ventes par jour, catégorie, vendeur, heure et mode de paiement.', '/reports'],
            ['<b>Bénéfices</b> : chiffre d\'affaires moins coût d\'achat des articles vendus, puis moins les dépenses.', '/reports/profit'],
            ['<b>Valeur du stock</b>, <b>créances</b> (ce qu\'on vous doit) et <b>dettes</b> (ce que vous devez).', '/reports/stock'],
            ['Chaque rapport s\'exporte en <b>PDF</b> ou en <b>Excel (CSV)</b>.', null],
            ['Plus rapide : demandez-moi « bilan de la semaine en PDF » ou « bénéfice du mois ».', null],
        ], [
            ['Ventes 100 000 F, coût des articles 70 000 F. Marge brute ?', ['30 000 F', '170 000 F', '70 000 F'], 0, 'Marge = ventes − coût d\'achat des articles vendus.'],
            ['Le chiffre d\'affaires est-il le bénéfice ?', ['Oui', 'Non : il faut retirer les coûts et les dépenses', 'Seulement le samedi'], 1, 'Beaucoup de ventes ne veulent pas dire beaucoup de bénéfice.'],
            ['Comment obtenir un rapport en PDF le plus vite ?', ['Le recopier à la main', 'Demander à MARWA IA « … en PDF »', 'Ce n\'est pas possible'], 1, 'Je prépare le PDF et je l\'ouvre directement.'],
        ], null],
        'depenses' => ['Enregistrer les dépenses', 'expenses.manage', 'depense depenses charge charges frais loyer electricite salaire facture sortie argent', [
            ['<b>Dépenses</b> : créez vos catégories (loyer, électricité, salaires, transport…).', '/expenses'],
            ['Chaque sortie d\'argent : montant, catégorie, moyen de paiement, description.', null],
            ['Payée avec la caisse ? Elle est déduite des espèces théoriques à la clôture.', null],
            ['Une erreur ? Une dépense ne s\'efface pas : on la <b>contre-passe</b> (traçabilité).', null],
        ], [
            ['Pourquoi enregistrer chaque dépense ?', ['Pour connaître le vrai bénéfice net', 'Pour remplir le disque', 'Ce n\'est pas utile'], 0, 'Bénéfice net = marge − dépenses.'],
            ['Une dépense a été saisie deux fois. Que faire ?', ['La contre-passer', 'Supprimer la base', 'Laisser tel quel'], 0, 'La contre-passation garde la trace de la correction.'],
            ['Le carburant du livreur payé en espèces de la caisse doit être…', ['Saisi en dépense avant la clôture', 'Oublié', 'Ajouté aux ventes'], 0, 'Sinon la caisse paraîtra en manque.'],
        ], null],
        'marketing' => ['Marketing : vendre plus et être visible', null, 'marketing publicite pub campagne reseaux sociaux facebook tiktok whatsapp visible visibilite promotion attirer clients', [
            ['<b>Qui, quoi, où</b> : votre client idéal, ce qui vous distingue, et là où il regarde (enseigne, Google Maps, WhatsApp, Facebook, TikTok).', null],
            ['<b>WhatsApp Business</b> : profil complet, catalogue des meilleurs articles, un statut par jour, listes de diffusion.', null],
            ['<b>Publier régulièrement</b> : vraies photos, prix lisible, et toujours un appel à l\'action (« Écrivez-nous sur WhatsApp »).', null],
            ['<b>Campagne payante</b> : un objectif, une cible (ville / rayon), un petit budget test de 5 à 7 jours, puis on mesure.', null],
            ['<b>Mesurer</b> : ventes avant / après, messages reçus, « comment nous avez-vous connu ? ». Demandez-moi « mon plan marketing en PDF ».', '/ia'],
        ], [
            ['Avant de payer une publicité, il faut d\'abord…', ['Fixer un objectif et une cible', 'Dépenser tout le budget le premier jour', 'Copier la publicité d\'un concurrent'], 0, 'Sans objectif ni cible, on ne peut pas savoir si la publicité a rapporté.'],
            ['Quelle publication fait le plus agir le client ?', ['Une photo floue sans prix', 'Une vraie photo, le prix et « Écrivez-nous sur WhatsApp »', 'Un long texte sans image'], 1, 'Photo réelle + prix + appel à l\'action : le client sait quoi faire.'],
            ['Comment savoir si une campagne a marché ?', ['Au nombre de « j\'aime » seulement', 'C\'est impossible', 'Comparer les ventes avant / après et demander comment le client nous a connus'], 2, 'Les « j\'aime » ne paient pas les factures : regardez les ventes et les nouveaux clients.'],
        ], null],
        'pressing' => ['Le module Pressing', 'pressing.view', 'pressing linge vetement vetements depot depots lavage repassage retrait', [
            ['<b>Caisse pressing</b> : client, articles (chemise, costume, m² de tapis…), service (lavage, repassage, lavage seul), date promise.', '/pressing/caisse'],
            ['Notez les <b>défauts</b> au dépôt et imprimez le ticket avec ses étiquettes.', null],
            ['Faites avancer les statuts : reçu → en traitement → <b>prêt</b> → livré.', '/pressing'],
            ['<b>Suivi des vêtements</b> : retrouvez un article par son code ; l\'écran des dépôts en cours s\'affiche en grand.', '/pressing/suivi'],
        ], [
            ['Pourquoi noter les défauts au dépôt ?', ['Pour éviter les litiges au retrait', 'Pour faire joli', 'Ce n\'est pas utile'], 0, 'Le client a signé ce qui était déjà abîmé.'],
            ['Quel statut avant la remise au client ?', ['Prêt', 'Annulé', 'Reçu'], 0, 'Le client peut alors être prévenu.'],
            ['Un tapis se facture…', ['Au m²', 'Au kilo de savon', 'Gratuitement'], 0, 'L\'unité m² est prévue pour les tapis et rideaux.'],
        ], 'pressing'],
        'hotel' => ['Le module Hôtel', 'hotel.view', 'hotel chambre chambres reservation arrivee depart sejour folio menage client', [
            ['<b>Nouvelle réservation</b> : client, chambre, dates, tarif.', '/hotel/reservations/nouvelle'],
            ['À l\'arrivée : <b>enregistrer l\'arrivée</b> et imprimer la fiche de police.', '/hotel/reservations'],
            ['Pendant le séjour, les consommations vont sur le <b>folio</b> de la chambre.', null],
            ['Au départ : encaisser le solde du folio, la chambre passe au <b>ménage</b>.', '/hotel/menage'],
        ], [
            ['Où s\'ajoutent les consommations du client pendant son séjour ?', ['Sur le folio', 'Dans les dépenses', 'Nulle part'], 0, 'Le folio est réglé au départ.'],
            ['Après le départ, la chambre passe…', ['Au ménage', 'Directement occupée', 'En panne'], 0, 'Elle redevient libre quand le ménage est fait.'],
            ['Quand imprimer la fiche de police ?', ['À l\'arrivée', 'Jamais', 'À la fin de l\'année'], 0, 'Elle est remplie avec les informations du client.'],
        ], 'hotel'],
        'ecole' => ['Le module Établissement', 'ecole.view', 'ecole etablissement eleve eleves inscription scolarite note notes bulletin bulletins paiement parents', [
            ['<b>Classes et matières</b>, puis <b>élèves</b> et inscriptions.', '/ecole/eleves'],
            ['<b>Caisse</b> : inscriptions et paiements, avec reçu à chaque versement.', '/ecole/caisse'],
            ['Saisissez les <b>notes</b> : moyennes et rangs sont calculés, les bulletins s\'impriment.', '/ecole/notes'],
            ['Suivez les impayés par échéance et relancez avant la date limite.', '/ecole'],
        ], [
            ['Que remettre aux parents à chaque versement ?', ['Un reçu', 'Rien', 'Un bulletin'], 0, 'Le reçu évite les contestations.'],
            ['Qui calcule les moyennes et rangs ?', ['Le logiciel, à partir des notes', 'Le parent', 'Personne'], 0, 'Les bulletins sont prêts à imprimer.'],
            ['Quand relancer un impayé ?', ['Avant la date limite, poliment', 'Jamais', 'Devant toute la classe'], 0, 'Avec le reste à payer exact.'],
        ], 'ecole'],
    ];

    public static function isTraining(string $n): bool
    {
        return (bool) preg_match('/\b(formation|formations|forme moi|formez moi|me former|former (mon|mes|un|le)|apprends moi|apprenez moi|je veux apprendre|tutoriel|tuto|lecon|lecons|quiz|quizz|teste moi|interroge moi|je suis nouveau|je suis nouvelle|je debute|debutant|debutante|guide moi|entraine moi|ma progression|mes progres)\b/', $n)
            || (bool) preg_match('/(^|[^n] )\bcours\b/', $n) && !preg_match('/\ben cours\b/', $n);
    }

    /** Leçons visibles pour l'utilisateur (rôle + modules actifs). */
    public static function lessons(): array
    {
        $out = [];
        foreach (self::LESSONS as $code => $l) {
            if ($l[5] !== null && !Addons::isActive($l[5])) {
                continue;
            }
            if ($l[1] === null || Auth::can($l[1])) {
                $out[$code] = $l;
            }
        }
        return $out;
    }

    public static function find(string $q): ?string
    {
        $stems = Text::stems($q);
        $best = null;
        $bs = 0;
        foreach (self::lessons() as $code => $l) {
            $keys = Text::stems($l[2] . ' ' . $l[0]);
            $hit = count(array_intersect($stems, $keys));
            if ($hit > $bs) {
                $bs = $hit;
                $best = $code;
            }
        }
        return $best;
    }

    public static function answer(string $q): array
    {
        $n = Text::norm($q);
        $code = self::find($q);
        $lessons = self::lessons();
        if (!$lessons) {
            return ['ok' => true, 'type' => 'chat', 'html' => '<p>Aucune leçon n\'est disponible pour votre rôle.</p>', 'actions' => [], 'suggestions' => [], 'spec' => null];
        }
        if (preg_match('/\b(quiz|quizz|teste moi|interroge moi)\b/', $n)) {
            return self::startQuiz($code ?? array_key_first($lessons));
        }
        if ($code !== null && !preg_match('/\b(ma progression|mes progres)\b/', $n)) {
            return self::lesson($code);
        }
        return self::menu();
    }

    /** Programme de formation avec la progression de l'utilisateur. */
    public static function menu(): array
    {
        $done = self::progress();
        $lessons = self::lessons();
        $html = '<p class="h">Votre formation POS Marwa</p><p>Choisissez une leçon : je vous explique pas à pas, puis un petit quiz de 3 questions vérifie que tout est clair.</p><ol class="mia-lessons">';
        $next = null;
        foreach ($lessons as $code => $l) {
            $d = $done[$code] ?? null;
            $badge = $d ? ' <span class="ok">' . (int) $d['score'] . '/' . (int) $d['total'] . '</span>' : '';
            if (!$d && $next === null) {
                $next = $code;
            }
            $html .= '<li><b>' . e($l[0]) . '</b>' . $badge . '</li>';
        }
        $html .= '</ol>';
        $count = count(array_intersect_key($done, $lessons));
        $html .= '<p>Progression : <b>' . $count . ' / ' . count($lessons) . '</b> leçon(s) validée(s).</p>';
        $html .= Wisdom::html(Wisdom::forTheme('formation'));
        $sugg = [];
        if ($next) {
            $sugg[] = 'Leçon : ' . $lessons[$next][0];
        }
        foreach ($lessons as $code => $l) {
            if ($code !== $next && count($sugg) < 4) {
                $sugg[] = 'Leçon : ' . $l[0];
            }
        }
        return ['ok' => true, 'type' => 'training', 'html' => $html, 'actions' => [], 'suggestions' => $sugg, 'spec' => null];
    }

    public static function lesson(string $code): array
    {
        $l = self::LESSONS[$code];
        $html = '<p class="h">Leçon : ' . e($l[0]) . '</p><ol class="mia-steps">';
        $actions = [];
        foreach ($l[3] as [$txt, $path]) {
            $html .= '<li>' . $txt . ($path ? ' <a href="' . e(url($path)) . '">Ouvrir</a>' : '') . '</li>';
            if ($path && count($actions) < 3 && !in_array(url($path), array_column($actions, 'url'), true)) {
                $actions[] = ['label' => 'Ouvrir : ' . self::label($path), 'url' => url($path), 'kind' => 'link'];
            }
        }
        $html .= '</ol><p>Quand vous êtes prêt, faites le <b>quiz</b> : 3 questions, je corrige et j\'explique.</p>';
        return ['ok' => true, 'type' => 'training', 'html' => $html, 'actions' => $actions, 'suggestions' => ['Quiz : ' . $l[0], 'Ma formation', 'Vérifie ma configuration'], 'spec' => null];
    }

    private static function label(string $path): string
    {
        foreach (Knowledge::menu('') as $m) {
            if (rtrim($m['url'], '/') === rtrim(url($path), '/')) {
                return $m['label'];
            }
        }
        return ucfirst(trim(explode('/', trim($path, '/'))[0] ?? '', '/')) ?: 'Écran';
    }

    public static function startQuiz(string $code): array
    {
        return self::question($code, 0, 0, '<p class="h">Quiz : ' . e(self::LESSONS[$code][0]) . '</p>');
    }

    private static function question(string $code, int $i, int $score, string $prefix = ''): array
    {
        [$q, $choices] = self::LESSONS[$code][4][$i];
        $html = $prefix . '<p><b>Question ' . ($i + 1) . ' / ' . count(self::LESSONS[$code][4]) . '</b> : ' . e($q) . '</p><ul class="mia-quiz">';
        $sugg = [];
        foreach ($choices as $k => $c) {
            $html .= '<li><b>' . 'ABC'[$k] . '.</b> ' . e($c) . '</li>';
            $sugg[] = 'ABC'[$k] . '. ' . $c;
        }
        $html .= '</ul><p class="small">Répondez A, B ou C (ou touchez une réponse).</p>';
        return ['ok' => true, 'type' => 'quiz', 'html' => $html, 'actions' => [], 'suggestions' => $sugg, 'quiz' => true, 'spec' => ['kind' => 'quiz', 'lesson' => $code, 'i' => $i, 'score' => $score]];
    }

    /** Réponse à une question du quiz en cours, ou null si le message n'en est pas une. */
    public static function grade(string $q, array $ctx): ?array
    {
        $code = (string) ($ctx['lesson'] ?? '');
        $i = (int) ($ctx['i'] ?? 0);
        if (!isset(self::LESSONS[$code][4][$i])) {
            return null;
        }
        $n = Text::norm($q);
        if (preg_match('/^(stop|arrete|arreter|annule|annuler|fin|quitter|j arrete)\b/', $n)) {
            return ['ok' => true, 'type' => 'chat', 'html' => '<p>Quiz arrêté. Vous pourrez le reprendre quand vous voulez : « quiz ' . e(mb_strtolower(self::LESSONS[$code][0])) . ' ».</p>', 'actions' => [], 'suggestions' => ['Ma formation'], 'spec' => ['kind' => 'fin']];
        }
        [, $choices, $good, $why] = self::LESSONS[$code][4][$i];
        $pick = null;
        if (preg_match('/^\s*([abc])\s*(?:[.)\-:]|$|\s)/i', $q, $m)) {
            $pick = strpos('abc', strtolower($m[1]));
        } else {
            // Réponse écrite avec ses mots : le choix qui partage le plus de mots l'emporte
            $mine = Text::stems($q);
            $scores = [];
            foreach ($choices as $k => $c) {
                $cs = Text::stems($c);
                $scores[$k] = $cs ? count(array_intersect($cs, $mine)) / count($cs) : 0;
                if (Text::norm($c) !== '' && str_contains($n, Text::norm($c))) {
                    $scores[$k] = 2;
                }
            }
            arsort($scores);
            $top = array_values($scores);
            if ($top[0] >= 0.5 && $top[0] > ($top[1] ?? 0)) {
                $pick = array_key_first($scores);
            }
        }
        if ($pick === null) {
            if (preg_match('/^([a-z0-9]{1,2}|je (ne )?sais pas|sais pas|aucune idee|je ne sais pas|euh+|hum+|hmm+|ok|oui|non|\?+)$/', $n) || $n === '') {
                return ['ok' => true, 'type' => 'quiz', 'html' => '<p>Je n\'ai pas compris votre choix. Répondez <b>A</b>, <b>B</b> ou <b>C</b>, ou « stop » pour arrêter le quiz.</p>', 'actions' => [], 'suggestions' => array_map(fn($k, $c) => 'ABC'[$k] . '. ' . $c, array_keys($choices), $choices), 'quiz' => true, 'spec' => $ctx];
            }
            return null; // autre question : le quiz attend
        }
        $ok = $pick === $good;
        $score = (int) ($ctx['score'] ?? 0) + ($ok ? 1 : 0);
        $fb = $ok ? '<p class="mia-good"><b>Bonne réponse !</b> ' . e($why) . '</p>'
            : '<p class="mia-bad"><b>Pas tout à fait.</b> La bonne réponse était <b>' . 'ABC'[$good] . '. ' . e($choices[$good]) . '</b>. ' . e($why) . '</p>';
        $total = count(self::LESSONS[$code][4]);
        if ($i + 1 < $total) {
            return self::question($code, $i + 1, $score, $fb);
        }
        self::save($code, $score, $total);
        $msg = $score === $total ? 'Parfait : <b>' . $score . ' / ' . $total . '</b>. Leçon validée, bravo !'
            : ($score >= 2 ? 'Résultat : <b>' . $score . ' / ' . $total . '</b>. Leçon validée ; relisez le point manqué.' : 'Résultat : <b>' . $score . ' / ' . $total . '</b>. Relisez la leçon et refaites le quiz : on apprend en se trompant.');
        $html = $fb . '<p class="h">' . $msg . '</p>' . Wisdom::html(Wisdom::forTheme($score === $total ? 'croissance' : 'patience'));
        return ['ok' => true, 'type' => 'training', 'html' => $html, 'actions' => [], 'suggestions' => ['Ma formation', $score < 2 ? 'Leçon : ' . self::LESSONS[$code][0] : 'Que sais-tu faire ?'], 'spec' => ['kind' => 'fin']];
    }

    public static function save(string $code, int $score, int $total): void
    {
        try {
            DB::run('INSERT INTO ia_training (user_id, lesson, score, total, done_at) VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE score = GREATEST(score, VALUES(score)), total = VALUES(total), attempts = attempts + 1, done_at = NOW()', [Auth::id(), $code, $score, $total]);
        } catch (\Throwable) {
            // la progression est un plus ; le quiz reste utilisable
        }
    }

    /** @return array<string, array{score:int,total:int}> leçons validées (score ≥ 2) */
    public static function progress(?int $userId = null): array
    {
        try {
            $rows = DB::all('SELECT lesson, score, total FROM ia_training WHERE user_id = ? AND score >= 2', [$userId ?? Auth::id()]);
        } catch (\Throwable) {
            return [];
        }
        return array_column($rows, null, 'lesson');
    }
}
