<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

use Addons\MarwaIa\Text;

/**
 * MARWA GENERAL INTELLIGENCE ENGINE — conversation naturelle et culture de gestion.
 *
 * Salutations, remerciements, plaisanteries, questions sur l'assistant et sur le logiciel, et notions de gestion
 * (« différence entre chiffre d'affaires et bénéfice », « pourquoi faire un inventaire », « comment fonctionne un
 * code-barres »…). Les explications sont rédigées et relues : elles ne dépendent d'aucun modèle externe et ne citent
 * aucun chiffre de l'entreprise (ceux-là viennent des outils de données). Ce que l'assistant ne sait pas, il le dit.
 */
final class General
{
    /** Exemples d'entraînement du classifieur. */
    public const EXAMPLES = [
        'greeting' => ['bonjour', 'salut', 'bonsoir', 'coucou', 'hello', 'salam', 'salam aleykoum', 'bonjour marwa', 'salut ca va', 'bonne journee', 'yo', 'bjr', 'bonjour a toi', 'fofo', 'ina kwana', 'sannu'],
        'thanks' => ['merci', 'merci beaucoup', 'super merci', 'c est gentil', 'merci pour ton aide', 'parfait merci', 'top merci', 'ok merci', 'bien recu merci', 'je te remercie', 'nagode', 'barka'],
        'wellbeing' => ['comment vas tu', 'ca va', 'tu vas bien', 'comment tu te sens', 'la forme', 'quoi de neuf', 'ca roule'],
        'goodbye' => ['au revoir', 'a demain', 'bonne nuit', 'a plus tard', 'bye', 'a bientot', 'je m en vais', 'bonne soiree', 'j ai fini pour aujourd hui'],
        'joke' => ['raconte moi une blague', 'fais moi rire', 'une blague', 'dis moi quelque chose de drole', 'tu connais une histoire drole', 'amuse moi un peu'],
        'praise' => ['tu es fort', 'bravo', 'bien joue', 'tu es intelligent', 'excellent travail', 'tu geres', 'c est exactement ca', 'tu m aides beaucoup', 'tu es genial'],
        'frustration' => ['tu ne comprends rien', 'tu es nul', 'ca ne sert a rien', 'tu racontes n importe quoi', 'ce n est pas ce que j ai demande', 'tu te trompes', 'mauvaise reponse', 'tu m enerves', 'c est faux'],
        'app_purpose' => ['a quoi sert cette application', 'c est quoi pos marwa', 'a quoi sert ce logiciel', 'que fait pos marwa', 'presente moi le logiciel', 'explique moi comment fonctionne cette application',
            'pourquoi utiliser pos marwa', 'ce logiciel fait quoi exactement', 'a quoi ca sert tout ca'],
        'app_name' => ['pourquoi pos marwa porte ce nom', 'd ou vient le nom marwa', 'que veut dire marwa', 'que signifie pos', 'pourquoi ca s appelle pos marwa', 'qui a cree pos marwa', 'qui a fait ce logiciel'],
        'concept' => ['quelle est la difference entre chiffre d affaires et benefice', 'comment fonctionne un code barres', 'pourquoi faut il faire un inventaire', 'qu est ce qu une marge commerciale',
            'comment mieux gerer une boutique', 'comment devenir un bon gestionnaire', 'quels sont les avantages de la digitalisation', 'pourquoi mon stock peut il etre negatif', 'comment fonctionne une imprimante thermique',
            'quelle est la difference entre un client debiteur et crediteur', 'comment fonctionne un logiciel de caisse', 'c est quoi le cout moyen pondere', 'a quoi sert le fond de caisse', 'c est quoi un ecart de caisse',
            'pourquoi faire des sauvegardes', 'c est quoi la rotation du stock', 'a quoi sert un seuil d alerte', 'difference entre ticket et facture', 'c est quoi un avoir', 'c est quoi le panier moyen',
            'qu est ce que la tresorerie', 'c est quoi le seuil de rentabilite', 'a quoi sert la tva', 'pourquoi separer les roles des utilisateurs', 'c est quoi la demarque', 'c est quoi le fefo', 'pourquoi vendre a credit est risque',
            'c est quoi le prix de gros', 'a quoi sert un bon de commande', 'c est quoi le mobile money'],
    ];

    private const JOKES = [
        'Un commerçant dit à son comptable : « Fais en sorte que deux et deux fassent cinq. » Le comptable répond : « D\'accord, mais c\'est l\'inventaire qui va pleurer. »',
        'Pourquoi le stock n\'aime-t-il pas les surprises ? Parce qu\'à chaque fois, on appelle ça un écart d\'inventaire.',
        'Le client : « Vous faites crédit ? » Le boutiquier : « Oui, mais seulement aux personnes de plus de 90 ans… accompagnées de leurs parents. »',
        'Deux tickets de caisse discutent. Le premier : « Je suis déprimé, on m\'a annulé. » Le second : « Moi ça va, je suis une copie conforme. »',
    ];

    /** Notions : mots-clés (racines) → [titre, explication]. */
    public const CONCEPTS = [
        'ca_benefice' => [['chiffre', 'affaire', 'benefic', 'differ', 'ca'], 'Chiffre d\'affaires et bénéfice',
            'Le chiffre d\'affaires, c\'est tout ce que vous avez vendu sur une période. Le bénéfice, c\'est ce qui vous reste une fois retirés le coût des marchandises vendues et les dépenses (loyer, salaires, électricité…). On peut donc vendre beaucoup et gagner peu si les marges sont faibles ou les charges élevées. Dans POS Marwa : « chiffre d\'affaires du mois » et « bénéfice du mois ».'],
        'marge' => [['marge', 'commercial', 'brut'], 'Marge commerciale',
            'La marge est la différence entre le prix de vente et le prix d\'achat d\'un article. Acheté 1 000 F et vendu 1 250 F : la marge est de 250 F, soit 20 % du prix de vente (taux de marge) ou 25 % du prix d\'achat (taux de marque inversé, souvent appelé « coefficient »). C\'est la marge de tous vos articles qui paie vos charges ; ce qui reste ensuite est le bénéfice.'],
        'code_barres' => [['code', 'barre', 'scann', 'douchette'], 'Code-barres',
            'Un code-barres est un numéro (souvent 13 chiffres, norme EAN) dessiné en barres pour qu\'un lecteur le lise sans erreur. Le lecteur se comporte comme un clavier : il « tape » le numéro, et POS Marwa retrouve l\'article qui porte ce numéro. Un article sans code-barres peut en recevoir un généré par le logiciel, à imprimer sur une étiquette.'],
        'inventaire' => [['inventair', 'compt', 'pourquoi'], 'Pourquoi faire un inventaire',
            'L\'inventaire compare ce que le logiciel croit avoir avec ce qui est réellement en rayon. Les écarts révèlent la casse, les vols, les erreurs de saisie ou de livraison. Sans inventaire, le stock théorique s\'éloigne peu à peu de la réalité, et les décisions (commandes, valeur du stock, bénéfice) deviennent fausses. Un inventaire tournant, rayon par rayon, évite de fermer la boutique.'],
        'stock_negatif' => [['stock', 'negatif', 'moins', 'zero'], 'Stock négatif',
            'Un stock devient négatif quand on vend un article que le logiciel ne croit pas avoir : marchandise reçue mais pas encore saisie, vente faite dans le mauvais magasin, ou inventaire jamais fait. Ce n\'est pas une panne, c\'est un signal : il faut enregistrer la réception manquante ou corriger par un inventaire. Le réglage « autoriser le stock négatif » décide si la caisse laisse passer ces ventes.'],
        'imprimante' => [['imprimant', 'thermi', 'ticket', 'papier'], 'Imprimante thermique',
            'Une imprimante thermique n\'utilise pas d\'encre : sa tête chauffe un papier spécial qui noircit à la chaleur. Elle est rapide, silencieuse et peu coûteuse à l\'usage, mais le papier doit être du bon côté et de la bonne largeur (58 ou 80 mm), et les tickets pâlissent avec le temps et la chaleur. Si rien ne s\'imprime, on vérifie d\'abord le sens du rouleau.'],
        'debiteur_crediteur' => [['debiteur', 'crediteur', 'client', 'solde'], 'Client débiteur ou créditeur',
            'Un client débiteur vous doit de l\'argent : il a acheté à crédit et n\'a pas tout réglé. Un client créditeur, c\'est l\'inverse : vous lui devez quelque chose, par exemple un avoir après un retour ou une avance versée. Dans POS Marwa, la dette d\'un client est son « encours » ; « qui me doit de l\'argent ? » liste les clients débiteurs.'],
        'logiciel_caisse' => [['logiciel', 'caisse', 'fonctionn', 'pos', 'point'], 'Logiciel de caisse',
            'Un logiciel de caisse enregistre chaque vente : articles, quantités, prix, moyen de paiement, vendeur. À chaque vente il diminue le stock, augmente la caisse et garde une trace qu\'on ne peut pas effacer discrètement. C\'est ce qui permet ensuite de connaître son chiffre, son bénéfice, ses meilleurs articles et ses écarts, au lieu de les estimer.'],
        'digitalisation' => [['digital', 'numeri', 'avantag', 'informatis'], 'Avantages de la digitalisation',
            'Passer du cahier au logiciel apporte trois choses : la rapidité (prix, monnaie et stock calculés tout seuls), la fiabilité (moins d\'erreurs, chaque opération signée par son auteur) et la visibilité (chiffres du jour, ruptures, dettes, disponibles tout de suite). La contrepartie : il faut saisir sérieusement, faire des sauvegardes et former l\'équipe.'],
        'gestionnaire' => [['bon', 'gestionnair', 'devenir', 'gerant', 'qualit'], 'Devenir un bon gestionnaire',
            'Un bon gestionnaire regarde ses chiffres régulièrement plutôt que de se fier à l\'impression du jour, sépare l\'argent de la boutique de l\'argent personnel, contrôle la caisse chaque soir, suit ses dettes et ses créances, et fait des inventaires. Il délègue avec des droits limités et vérifie. Demandez-moi « mon point du jour » : c\'est une bonne habitude quotidienne.'],
        'gerer_boutique' => [['mieux', 'gerer', 'boutique', 'magasin', 'tenir'], 'Mieux gérer une boutique',
            'Quatre leviers : ne pas manquer des articles qui se vendent (suivre les ruptures et les seuils), ne pas immobiliser d\'argent dans ceux qui ne se vendent pas (stock dormant), protéger la marge (prix d\'achat, remises maîtrisées) et tenir la caisse (clôture comptée chaque jour). Si vous voulez des conseils calculés sur vos propres données, demandez « conseille-moi pour améliorer ma boutique ».'],
        'cmp' => [['cout', 'moyen', 'ponder', 'cmp'], 'Coût moyen pondéré (CMP)',
            'Le CMP est le prix d\'achat moyen d\'un article, recalculé à chaque réception en tenant compte des quantités : 10 unités à 1 000 F puis 10 à 1 200 F donnent un CMP de 1 100 F. POS Marwa l\'utilise pour valoriser le stock et calculer la marge, ce qui évite qu\'un seul achat cher ou bon marché fausse le bénéfice.'],
        'fond_caisse' => [['fond', 'caisse', 'ouvertur', 'monnai'], 'Fond de caisse',
            'Le fond de caisse est la monnaie laissée dans le tiroir à l\'ouverture pour pouvoir rendre la monnaie aux premiers clients. Il ne fait pas partie des ventes : à la clôture, on le retire du compté pour trouver la recette du jour.'],
        'ecart_caisse' => [['ecart', 'caisse', 'manqu', 'excedent', 'theoriq'], 'Écart de caisse',
            'L\'écart de caisse est la différence entre l\'argent que le logiciel attend (fond + ventes en espèces + entrées − sorties) et l\'argent réellement compté à la clôture. Un manque peut venir d\'une erreur de monnaie, d\'une sortie non saisie ou d\'un vol ; un excédent, d\'une vente non enregistrée. Un petit écart arrive ; un écart répété chez le même caissier mérite un contrôle.'],
        'sauvegarde' => [['sauvegard', 'backup', 'copie', 'perdre'], 'Pourquoi sauvegarder',
            'Un disque dur peut tomber en panne, un ordinateur peut être volé ou brûler. La sauvegarde est une copie de toutes vos données ; rangée ailleurs que sur l\'ordinateur (clé USB, autre lieu), elle permet de repartir en quelques minutes. Sans elle, des années de ventes, de stock et de dettes clients peuvent disparaître.'],
        'rotation' => [['rotation', 'stock', 'tourn', 'ecoul'], 'Rotation du stock',
            'La rotation mesure la vitesse à laquelle un stock se vend et se renouvelle. Un article qui se vend en une semaine « tourne » vite : peu d\'argent immobilisé, peu de risque de péremption. Un article qui reste six mois tourne lentement : il occupe de la place et de la trésorerie. On cherche à commander souvent et peu ce qui tourne vite, et à ne pas réapprovisionner ce qui dort.'],
        'seuil' => [['seuil', 'alert', 'minimum', 'reapprovision'], 'Seuil d\'alerte',
            'Le seuil d\'alerte est la quantité en dessous de laquelle il faut recommander un article. On le fixe selon le rythme de vente et le délai du fournisseur : si vous vendez 5 unités par jour et que la livraison prend 4 jours, un seuil d\'au moins 20 évite la rupture. POS Marwa signale les articles passés sous leur seuil.'],
        'ticket_facture' => [['ticket', 'factur', 'differ', 'recu'], 'Ticket et facture',
            'Le ticket de caisse est le reçu remis à un client de passage : il prouve la vente. La facture porte en plus le nom du client et les mentions légales de l\'entreprise ; elle sert à un client professionnel pour sa comptabilité. Dans POS Marwa, une même vente peut être imprimée en ticket ou en facture A4.'],
        'avoir' => [['avoir', 'retour', 'rembours'], 'Avoir',
            'Un avoir est un document qui annule tout ou partie d\'une vente déjà faite, par exemple après un retour de marchandise. Au lieu d\'effacer la vente, on garde la trace des deux opérations. Le client est remboursé, ou l\'avoir vient réduire sa dette.'],
        'panier' => [['panier', 'moyen'], 'Panier moyen',
            'Le panier moyen est le montant moyen dépensé par client : chiffre d\'affaires divisé par le nombre de ventes. Pour vendre plus sans avoir plus de clients, on cherche à l\'augmenter : articles complémentaires près de la caisse, lots, conseils du vendeur.'],
        'tresorerie' => [['tresorer', 'liquidit', 'cash'], 'Trésorerie',
            'La trésorerie est l\'argent réellement disponible (caisse, banque, mobile money) à un moment donné. Une boutique peut être bénéficiaire et manquer de trésorerie si son argent est bloqué dans le stock ou dans les crédits accordés aux clients. C\'est pourquoi on suit à la fois le bénéfice et les encaissements.'],
        'rentabilite' => [['seuil', 'rentabilit', 'point', 'mort'], 'Seuil de rentabilité',
            'Le seuil de rentabilité est le chiffre d\'affaires à partir duquel vous commencez à gagner de l\'argent : charges fixes divisées par le taux de marge. Avec 300 000 F de charges par mois et 25 % de marge, il faut vendre 1 200 000 F pour ne rien perdre. Je peux faire ce calcul : « seuil de rentabilité avec 300 000 F de charges ».'],
        'tva' => [['tva', 'taxe', 'impot'], 'TVA',
            'La TVA est une taxe comprise dans le prix payé par le client, que l\'entreprise assujettie collecte puis reverse à l\'État, après déduction de la TVA payée sur ses achats. POS Marwa calcule la TVA incluse dans vos prix TTC selon le taux saisi sur chaque article. Les règles (assujettissement, taux, déclarations) relèvent de l\'administration fiscale : je ne donne pas d\'avis fiscal.'],
        'roles' => [['role', 'droit', 'permission', 'separ', 'utilisateur'], 'Rôles et droits',
            'Donner à chacun un compte et seulement les droits de son poste protège tout le monde : le caissier vend mais ne modifie pas les prix, le magasinier gère le stock mais ne touche pas à la caisse, et chaque opération est signée. En cas d\'erreur ou de fraude, on sait qui a fait quoi, et personne n\'est soupçonné à tort.'],
        'demarque' => [['demarqu', 'perte', 'casse', 'vol'], 'Démarque',
            'La démarque est la marchandise perdue sans être vendue : casse, péremption, vol, erreurs. On la mesure à l\'inventaire (écart entre stock théorique et réel) et par les ajustements de pertes saisis. La réduire passe par des inventaires réguliers, le rangement, la surveillance des dates et des droits bien répartis.'],
        'fefo' => [['fefo', 'fifo', 'perempt', 'lot', 'premier'], 'FIFO et FEFO',
            'FIFO : premier entré, premier sorti — on vend d\'abord le stock le plus ancien. FEFO : premier périmé, premier sorti — on vend d\'abord le lot dont la date de péremption est la plus proche. POS Marwa applique le FEFO pour les articles gérés par lots.'],
        'credit' => [['credit', 'risqu', 'dette', 'impay'], 'Vendre à crédit',
            'Vendre à crédit fidélise, mais c\'est prêter votre trésorerie. Les règles prudentes : un plafond par client, une échéance claire, pas de nouveau crédit tant que l\'ancien n\'est pas réglé, et un suivi régulier des retards. POS Marwa enregistre l\'encours et le plafond de chaque client.'],
        'gros' => [['prix', 'gros', 'demi', 'detail'], 'Prix de gros',
            'Le prix de gros est un prix réduit accordé à partir d\'une certaine quantité. La marge par unité est plus faible, mais le volume et la rapidité de vente compensent. Dans POS Marwa, un article peut avoir un prix de gros et une quantité minimale ; la caisse l\'applique en mode « vente en gros ».'],
        'bon_commande' => [['bon', 'command', 'fournisseur', 'achat'], 'Bon de commande',
            'Le bon de commande écrit ce que vous demandez au fournisseur : articles, quantités, prix convenus. À la livraison, on compare ce qui arrive avec ce bon, ce qui évite de payer ce qui n\'a pas été livré. Dans POS Marwa, la réception de la commande met le stock et la dette fournisseur à jour.'],
        'mobile_money' => [['mobile', 'money', 'paiement', 'electron'], 'Mobile money',
            'Le mobile money permet au client de payer depuis son téléphone, sans espèces. Pour le commerçant : moins d\'argent liquide à garder et à compter, mais une vérification indispensable — le paiement n\'est acquis que lorsque la confirmation de l\'opérateur est reçue, pas sur simple présentation d\'un écran.'],
    ];

    /** Mots-clés trop courants pour désigner à eux seuls une notion, même dans une question « c'est quoi… ». */
    private const GENERIC = ['fonctionn', 'differ', 'client', 'stock', 'caisse', 'prix', 'pourquoi', 'bon', 'point', 'moyen', 'compt', 'achat', 'paiement', 'lot', 'premier', 'droit', 'code', 'solde', 'ticket', 'retour',
        'utilisateur', 'magasin', 'boutique', 'mieux', 'gerer', 'devenir', 'perte', 'role', 'affaire', 'chiffre', 'mobile', 'money', 'zero', 'moins', 'papier', 'logiciel', 'pos', 'command', 'fournisseur', 'vol',
        'manqu', 'copie', 'perdre', 'tourn', 'mort', 'dette', 'detail', 'casse', 'recu', 'qualit', 'gerant', 'tenir', 'separ', 'ca', 'factur', 'brut', 'commercial'];
    /** Mots-clés propres à une notion : un seul suffit, quelle que soit la tournure. */
    private const RARE = ['rotation', 'demarqu', 'fefo', 'fifo', 'cmp', 'ponder', 'tresorer', 'rentabilit', 'digital', 'numeri', 'thermi', 'debiteur', 'crediteur', 'tva', 'liquidit', 'backup', 'sauvegard'];

    public static function isGeneral(string $intent): bool
    {
        return isset(self::EXAMPLES[$intent]);
    }

    /** Notion la plus proche de la question (ou null). */
    public static function concept(string $q): ?array
    {
        $raw = array_filter(explode(' ', Text::norm($q)), fn($w) => strlen($w) >= 2);
        $stems = array_values(array_unique(array_merge(array_map(fn($w) => Text::stem($w), $raw), $raw)));
        $best = null;
        $bs = [0, 0, 0, 0];
        foreach (self::CONCEPTS as $key => [$keys, $title, $body]) {
            $hit = 0;
            $matched = [];
            foreach ($keys as $k) {
                foreach ($stems as $s) {
                    if ($s === $k || $s === Text::stem($k) || (strlen($k) >= 4 && str_starts_with($s, $k)) || (strlen($s) >= 4 && str_starts_with($k, $s))) {
                        $hit++;
                        $matched[] = $k;
                        break;
                    }
                }
            }
            if ($hit < 1) {
                continue;
            }
            // Classement : mot-clé propre à la notion d'abord, puis mots non banals, puis nombre de mots trouvés
            $rank = [(int) (bool) array_intersect($matched, self::RARE), count(array_diff($matched, self::GENERIC)), $hit, $hit / count($keys)];
            if ($best === null || $rank > $bs) {
                $bs = $rank;
                $best = ['key' => $key, 'title' => $title, 'body' => $body, 'hits' => $hit, 'distinct' => (bool) array_diff($matched, self::GENERIC), 'rare' => (bool) array_intersect($matched, self::RARE)];
            }
        }
        if (!$best) {
            return null;
        }
        // Un seul mot-clé suffit s'il est propre à la notion (« rotation », « FEFO »), ou s'il n'est pas banal et que la
        // question demande bien une explication (« c'est quoi un avoir ? », « à quoi sert le fond de caisse ? »).
        $asks = (bool) preg_match('/^(c est quoi|c quoi|qu est ce qu|que veu[tx] dire|que signifie|a quoi ser[tv]|pourquoi|comment fonctionne|quelle est la difference|difference entre|explique|definition)/', Text::norm($q));
        $short = count(explode(' ', Text::norm($q))) <= 4;
        return $best['hits'] >= 2 || ($best['rare'] && ($asks || $short)) || ($asks && $best['distinct']) ? $best : null;
    }

    /**
     * Réponse de conversation. $seed fait varier la formulation d'un message à l'autre.
     * @return array{text: string, suggestions?: string[]}|null
     */
    public static function reply(string $intent, string $q, UserContext $u, int $seed): ?array
    {
        $name = $u->firstName();
        $h = (int) date('G');
        $moment = $h < 12 ? 'Bonjour' : ($h < 18 ? 'Bon après-midi' : 'Bonsoir');
        $pick = fn(array $v) => $v[$seed % count($v)];
        switch ($intent) {
            case 'greeting':
                return ['text' => $pick(["$moment $name ! Que puis-je regarder pour vous ?", "$moment $name. Par quoi commence-t-on : les ventes, le stock, la caisse ?", "$moment ! Je suis prêt. Dites-moi ce que vous voulez savoir."]),
                    'suggestions' => ['Fais-moi le point', 'Quels produits sont en rupture ?', 'Combien de ventes aujourd\'hui ?']];
            case 'thanks':
                return ['text' => $pick(['Avec plaisir.', 'Je vous en prie. Autre chose à vérifier ?', 'De rien, je reste là si besoin.'])];
            case 'wellbeing':
                return ['text' => $pick(['Tout va bien de mon côté, merci. Et la boutique, on regarde les chiffres du jour ?', 'Ça va, merci ! Je suis un programme, mais je suis en forme pour vos chiffres. Que voulez-vous voir ?']),
                    'suggestions' => ['Fais-moi le point']];
            case 'goodbye':
                return ['text' => $pick(["Au revoir $name, bonne continuation.", 'À bientôt. Pensez à clôturer la caisse si la journée est finie.', "Bonne fin de journée $name."])];
            case 'joke':
                return ['text' => $pick(self::JOKES)];
            case 'praise':
                return ['text' => $pick(['Merci, ça fait plaisir. On continue ?', 'Merci ! Les chiffres sont les vôtres, je ne fais que les lire.'])];
            case 'frustration':
                return ['text' => 'Désolé, ma réponse ne vous a pas aidé. Dites-moi en quelques mots ce que vous cherchez (par exemple « ventes d\'hier », « stock du riz », « pourquoi la caisse a un écart »), ou cliquez sur le pouce vers le bas : la question sera relue par votre administrateur pour m\'améliorer.',
                    'suggestions' => ['Que sais-tu faire ?']];
            case 'app_purpose':
                return ['text' => 'POS Marwa est un logiciel de gestion de commerce : la caisse enregistre les ventes, le stock se met à jour tout seul, et vous suivez les achats, les fournisseurs, les clients et leurs crédits, les dépenses, la caisse et les bénéfices. '
                    . 'Des modules s\'ajoutent selon l\'activité (restaurant, pharmacie, hôtel, école, comptabilité, paiements électroniques…). Moi, MARWA AI, je lis ces données pour répondre à vos questions, vous expliquer les écrans et préparer certaines opérations que vous confirmez.',
                    'suggestions' => ['Que sais-tu faire ?', 'Ma formation', 'Comment faire une vente ?']];
            case 'app_name':
                return ['text' => 'POS signifie « Point Of Sale », point de vente en français : c\'est le nom courant des logiciels de caisse. POS Marwa a été conçu par Mahamadou Hamidou (Mohamed), ingénieur informaticien nigérien. '
                    . 'Pour l\'origine exacte du nom « Marwa », je n\'ai pas d\'information vérifiée dans le logiciel : je préfère ne pas l\'inventer.'];
            case 'concept':
                $c = self::concept($q);
                return $c ? ['text' => $c['body'], 'title' => $c['title'], 'concept' => $c['key']] : null;
        }
        return null;
    }
}
