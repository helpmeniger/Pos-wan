<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

use App\Core\Auth;
use App\Core\DB;
use App\Services\BusinessMode;
use App\Services\ReportService;

/**
 * Coach marketing de MARWA IA : explique comment se faire connaître et vendre plus, adapté à
 * l'activité réelle du commerce (alimentation, pharmacie, restaurant, hôtel, pressing…), avec
 * les chiffres de la boutique, des textes prêts à publier et un plan marketing en PDF.
 * Les conseils restent dans le domaine de l'activité ; les règles particulières (santé,
 * établissements scolaires) sont rappelées.
 */
final class Marketing
{
    /**
     * activité => [libellé, clientèle, quoi mettre en avant (sujet), canaux prioritaires, idées de contenu, offres qui marchent, règle particulière|null, hashtags]
     */
    public const PROFILES = [
        'alimentation' => ['alimentation / supermarché', 'les familles et les ménagères du quartier, les petits commerçants qui achètent en gros', 'vos produits du quotidien', ['WhatsApp Business (statuts et liste de diffusion)', 'Facebook (page + groupes du quartier)', 'Google Maps', 'enseigne et vitrine'],
            ['les arrivages du jour en photo (riz, huile, sucre, lait…)', 'le prix du carton et du sac comparé au détail', 'le « panier de la semaine » pour une famille', 'les produits frais et leur date', 'les nouveautés en rayon'],
            ['pack famille (riz + huile + sucre)', 'prix de gros dès quelques cartons', 'livraison à domicile au-delà d\'un montant', 'carte de fidélité : la 10e course remisée'], null, ['#supermarche', '#alimentation', '#bonsprix']],
        'boutique' => ['boutique', 'les jeunes, les femmes et les familles qui suivent la mode et les nouveautés', 'vos articles et nouveautés', ['TikTok (vidéos courtes)', 'Instagram', 'WhatsApp Business (catalogue + statuts)', 'Facebook'],
            ['l\'arrivage de la semaine porté par une personne (essayage)', 'trois façons de porter un même article', 'les tailles et couleurs disponibles avec prix', 'les avis et photos des clientes (avec leur accord)', 'les coulisses : déballage des colis'],
            ['un accessoire offert dès un montant', 'prix spécial fête (Tabaski, fin d\'année, rentrée)', 'réservation par WhatsApp avec acompte', 'parrainage : une amie amenée = une remise'], null, ['#mode', '#boutique', '#nouvelarrivage']],
        'quincaillerie' => ['quincaillerie', 'les maçons, plombiers, électriciens, chefs de chantier et particuliers qui construisent', 'vos matériaux et outillages', ['WhatsApp (artisans et chefs de chantier)', 'Google Maps', 'Facebook (groupes de construction et d\'immobilier)', 'enseigne visible depuis la route'],
            ['les prix du ciment, du fer et de la tôle de la semaine', 'des devis types (une chambre, une douche)', 'conseils de pose en courte vidéo', 'les arrivages et les marques disponibles', 'les chantiers réalisés par vos clients artisans (avec accord)'],
            ['prix artisan / prix de gros', 'livraison sur chantier', 'devis gratuit par WhatsApp', 'carte de fidélité artisan'], null, ['#quincaillerie', '#construction', '#btp']],
        'boissons' => ['dépôt de boissons', 'les maquis, bars, restaurants, boutiques de quartier et les familles pour les fêtes', 'vos boissons et vos prix au casier', ['WhatsApp (commandes des revendeurs)', 'Facebook', 'Google Maps', 'tricycle et livreur bien identifiés'],
            ['les prix au casier et au carton', 'les boissons fraîches disponibles', 'les packs pour mariages, baptêmes et fêtes', 'les nouveautés et formats', 'la livraison en cours (photo du tricycle chargé)'],
            ['prix revendeur', 'pack fête livré', 'consigne des emballages claire', 'remise au volume'], 'La publicité pour l\'alcool est encadrée : ne visez jamais les mineurs, évitez d\'associer l\'alcool à la conduite ou au sport, et respectez la réglementation en vigueur.', ['#boissons', '#depot', '#livraison']],
        'cosmetique' => ['cosmétique', 'les femmes et les hommes soucieux de leur beauté, les coiffeuses et esthéticiennes', 'vos produits de beauté', ['TikTok et Instagram (avant / après, routines)', 'WhatsApp Business', 'Facebook', 'partenariat avec des coiffeuses et maquilleuses'],
            ['une routine de soin en 3 étapes', 'le produit du jour expliqué en 15 secondes', 'les nouveautés et leur prix', 'les conseils selon le type de peau ou de cheveux', 'les avis de clientes (avec accord)'],
            ['coffret cadeau', 'échantillon offert', 'remise pour les professionnelles', 'programme fidélité'], 'N\'annoncez pas d\'effet médical (« guérit », « soigne ») et n\'utilisez que des produits autorisés : la confiance se perd en une seule fois.', ['#beaute', '#cosmetique', '#soinsdelapeau']],
        'informatique' => ['matériel informatique et téléphonie', 'les étudiants, les entreprises, les administrations et les particuliers', 'votre matériel et vos services', ['Facebook', 'WhatsApp Business (catalogue)', 'Google Maps', 'TikTok (démonstrations)'],
            ['la comparaison de deux modèles', 'les prix et garanties', 'la démonstration d\'un produit en vidéo', 'les conseils pour bien choisir', 'les services : réparation, installation, maintenance'],
            ['garantie claire', 'accessoire offert', 'prix étudiant', 'contrat de maintenance pour les entreprises'], null, ['#informatique', '#telephone', '#technologie']],
        'papeterie' => ['papeterie / librairie', 'les parents, élèves, étudiants, écoles et bureaux', 'vos fournitures', ['WhatsApp (listes scolaires des parents)', 'Facebook', 'partenariat avec les écoles', 'Google Maps'],
            ['les kits de rentrée par classe', 'les prix des fournitures', 'les services : impression, photocopie, reliure', 'les nouveautés de bureau', 'les livres disponibles'],
            ['kit rentrée prêt', 'commande de la liste scolaire par WhatsApp', 'remise aux écoles et entreprises', 'livraison aux bureaux'], null, ['#rentreescolaire', '#papeterie', '#fournitures']],
        'pieces' => ['pièces détachées', 'les mécaniciens, chauffeurs, propriétaires de motos et voitures, transporteurs', 'vos pièces disponibles', ['WhatsApp (mécaniciens)', 'Facebook (groupes auto-moto)', 'Google Maps', 'partenariat avec les garages'],
            ['les pièces disponibles par modèle de moto ou voiture', 'les prix d\'origine et compatibles', 'conseils d\'entretien en vidéo', 'les arrivages', 'les garages partenaires'],
            ['prix mécanicien', 'recherche de pièce par photo WhatsApp', 'livraison au garage', 'garantie'], null, ['#piecesdetachees', '#moto', '#auto']],
        'restaurant' => ['restaurant / maquis', 'les travailleurs à midi, les familles, les jeunes et les groupes le soir et le week-end', 'vos plats', ['TikTok et Instagram (plats en vidéo)', 'WhatsApp Business (menu du jour)', 'Google Maps (avis)', 'Facebook'],
            ['le menu du jour en photo chaque matin', 'la préparation d\'un plat en 15 secondes', 'l\'ambiance de la salle le soir', 'la carte des prix', 'les avis des clients (avec accord)'],
            ['menu du midi à prix fixe', 'livraison et à emporter', 'offre groupe et anniversaire', 'happy hour aux heures creuses'], null, ['#restaurant', '#maquis', '#menudujour']],
        'pharmacie' => ['pharmacie', 'les familles, les personnes âgées, les mamans et les patients du quartier', 'vos services de pharmacie', ['Google Maps (horaires et garde)', 'WhatsApp Business (informations pratiques)', 'Facebook (prévention)', 'enseigne lumineuse et croix visibles'],
            ['les jours et horaires de garde', 'des conseils de prévention (paludisme, chaleur, hygiène)', 'les services : prise de tension, conseils, livraison si autorisée', 'la parapharmacie et l\'hygiène', 'les campagnes de santé publique'],
            ['service de livraison si autorisé', 'commande d\'ordonnance par WhatsApp à préparer', 'conseil personnalisé', 'parapharmacie : coffrets bébé et hygiène'],
            'La publicité en pharmacie est strictement encadrée (réglementation de la santé et déontologie de l\'Ordre des pharmaciens) : communiquez sur vos services, horaires, gardes et la prévention ; jamais de promotion ni d\'incitation à consommer des médicaments, en particulier ceux sur ordonnance. En cas de doute, renseignez-vous auprès de l\'Ordre.', ['#pharmacie', '#pharmaciedegarde', '#sante']],
        'clinique' => ['clinique / centre de santé', 'les familles, les entreprises (assurance santé) et les patients de la zone', 'vos services de santé', ['Google Maps (accès, horaires, urgences)', 'Facebook (prévention, campagnes)', 'WhatsApp (rendez-vous)', 'partenariats avec les entreprises et assureurs'],
            ['les spécialités et jours de consultation', 'les campagnes de dépistage', 'des conseils de prévention', 'les équipements et l\'accueil', 'les horaires et numéros d\'urgence'],
            ['bilan de santé pour les entreprises', 'prise de rendez-vous par WhatsApp', 'conventions avec les assureurs', 'journée de dépistage'],
            'La communication des établissements de santé est encadrée par la déontologie médicale : informez sur vos services et la prévention, sans promesse de guérison, sans témoignage de patient identifiable, et dans le respect absolu du secret médical.', ['#sante', '#clinique', '#prevention']],
        'pressing' => ['pressing', 'les fonctionnaires, cadres, familles, hôtels et entreprises', 'votre service de pressing', ['WhatsApp Business (dépôts et rappels)', 'Google Maps', 'Facebook', 'partenariat avec les bureaux et hôtels'],
            ['l\'avant / après d\'un vêtement ou d\'un tapis', 'les tarifs (chemise, costume, tapis au m²)', 'le service express et la livraison', 'les conseils d\'entretien des tissus', 'les délais tenus'],
            ['forfait mensuel pour les cadres', 'enlèvement et livraison à domicile', 'tarif entreprise', 'la 10e chemise offerte'], null, ['#pressing', '#blanchisserie', '#propre']],
        'hotel' => ['hôtel', 'les voyageurs d\'affaires, les ONG, les touristes, les familles et les organisateurs de séminaires', 'vos chambres et services', ['Google Maps (photos et avis)', 'plateformes de réservation en ligne', 'Facebook et Instagram', 'WhatsApp (réservations)', 'partenariats avec les entreprises, ONG et agences'],
            ['les chambres en photo, lumineuses et rangées', 'les services : restaurant, wifi, salle de réunion, navette', 'les avis des clients', 'les offres de séminaire', 'la ville et ses lieux à visiter'],
            ['tarif entreprise / ONG', 'forfait séminaire', 'nuit offerte au-delà d\'une durée', 'petit déjeuner inclus'], null, ['#hotel', '#voyage', '#seminaire']],
        'ecole' => ['établissement scolaire', 'les parents d\'élèves et les élèves / étudiants', 'votre établissement', ['Facebook (vie de l\'établissement)', 'WhatsApp (parents)', 'Google Maps', 'journées portes ouvertes', 'radio locale'],
            ['les résultats aux examens', 'les activités et sorties', 'le corps enseignant et l\'encadrement', 'les dates d\'inscription et les frais', 'les équipements (salles, bibliothèque, informatique)'],
            ['réduction pour les fratries', 'paiement échelonné', 'journée portes ouvertes', 'bourse d\'excellence'],
            'Ne publiez jamais la photo ou le nom d\'un élève sans l\'accord écrit de ses parents (loi n° 2017-28 sur la protection des données personnelles), et restez exact sur les résultats annoncés.', ['#ecole', '#education', '#rentree']],
    ];

    /** sujet => [expression, titre, étapes] */
    public const TOPICS = [
        'bases' => ['/\b(comment marche le marketing|c est quoi le marketing|qu est ce que le marketing|le marketing|marketing|strategie commerciale|plan marketing|se faire connaitre|faire connaitre|etre visible|visibilite|notoriete)\b/', 'Comment marche le marketing', [
            '<b>Qui ?</b> Choisissez votre client idéal : où il habite, ce qu\'il achète, quand, et sur quel téléphone ou réseau il passe son temps.',
            '<b>Quoi ?</b> Une promesse simple qui vous distingue : le moins cher, le plus frais, le plus rapide, le plus proche, le meilleur conseil.',
            '<b>Où ?</b> Soyez visible là où vos clients regardent : enseigne, Google Maps, WhatsApp, Facebook, TikTok, bouche-à-oreille.',
            '<b>Comment ?</b> Publiez régulièrement (photos réelles, prix clairs, numéro WhatsApp) et donnez une raison d\'agir maintenant (offre limitée).',
            '<b>Combien ?</b> Mesurez : ventes avant / après, nombre de messages reçus, question « comment nous avez-vous connu ? ». Gardez ce qui marche, arrêtez le reste.']],
        'reseaux' => ['/\b(reseaux sociaux|reseau social|sur internet|en ligne|visible partout|partout|etre visible|visibilite|digital|numerique)\b/', 'Être visible sur Internet et les réseaux sociaux', [
            '<b>Commencez par 2 canaux</b> bien tenus plutôt que 5 abandonnés : en général WhatsApp Business + Facebook ou TikTok selon vos clients, et la fiche Google Maps.',
            '<b>Même identité partout</b> : même nom, même logo, même numéro WhatsApp, même adresse.',
            '<b>Régularité</b> : un statut WhatsApp par jour, 3 à 5 publications par semaine, une vidéo courte au moins une fois par semaine.',
            '<b>Vraies photos et vrais prix</b> : la confiance vend plus qu\'une belle image copiée sur Internet.',
            '<b>Répondez vite</b> aux messages et commentaires : un client qui attend achète ailleurs.',
            '<b>Faites le lien avec la boutique</b> : affichez vos réseaux sur l\'enseigne, les tickets et les sacs ; demandez aux clients de vous suivre.']],
        'tendances' => ['/\b(de nos jours|aujourd hui|methodes? (moderne|modernes|actuelles?|qui marche\w*)|tendances?|nouvelles methodes|ce qui marche|marketing moderne|marketing digital)\b/', 'Les méthodes qui marchent aujourd\'hui', [
            '<b>La vidéo courte</b> (TikTok, Reels, statuts) : c\'est ce que les gens regardent le plus sur leur téléphone. Authentique vaut mieux que parfait.',
            '<b>Le commerce par messagerie</b> : le client voit, demande et commande sur WhatsApp ; catalogue, réponses rapides et paiement mobile money rendent l\'achat facile.',
            '<b>Les avis en ligne</b> : avant de se déplacer, beaucoup regardent les avis Google ; chaque avis positif est une publicité gratuite et durable.',
            '<b>La livraison et le retrait</b> : commander à distance et se faire livrer est devenu une attente pour beaucoup de clients en ville.',
            '<b>Les micro-influenceurs locaux</b> : une personne suivie par quelques milliers de gens de votre ville vend souvent mieux qu\'une célébrité.',
            '<b>Les communautés</b> : groupes WhatsApp et Facebook du quartier, associations, écoles, mosquées et églises (dans le respect de leurs règles), tontines.',
            '<b>Les données de vos clients</b> : POS Marwa sait qui achète quoi et quand ; utilisez-le pour des messages ciblés plutôt que d\'envoyer la même chose à tous.',
            '<b>La publicité ciblée à petit budget</b> : quelques milliers de francs par jour, ciblés sur votre ville, testés puis augmentés seulement si ça rapporte.',
            '<b>Le local reste roi</b> : enseigne, accueil, propreté, prix affichés. Le digital amène le client ; la boutique le garde.']],
        'whatsapp' => ['/\b(whatsapp|whatsapp business|statut|statuts|liste de diffusion|catalogue whatsapp)\b/', 'WhatsApp Business : votre vitrine dans le téléphone', [
            'Installez <b>WhatsApp Business</b> (gratuit) avec le numéro de la boutique : nom, logo, adresse, horaires, description.',
            'Remplissez le <b>catalogue</b> avec vos 10 à 20 meilleurs articles : photo nette, nom, prix.',
            'Publiez un <b>statut</b> chaque jour (arrivage, offre, coulisses) : vos contacts le voient pendant 24 h.',
            'Créez des <b>listes de diffusion</b> par type de clients (particuliers, revendeurs) : seuls ceux qui ont enregistré votre numéro reçoivent le message, alors demandez-leur de l\'enregistrer.',
            'Activez le <b>message d\'accueil</b> et le <b>message d\'absence</b>, et utilisez les <b>étiquettes</b> (nouveau client, commande, payé).',
            'Ne harcelez pas : 2 à 3 messages de diffusion par semaine au maximum, et retirez tout de suite ceux qui le demandent.']],
        'facebook' => ['/\b(facebook|fb|page facebook|marketplace|groupes? facebook)\b/', 'Facebook : page, groupes et Marketplace', [
            'Créez une <b>page professionnelle</b> (pas un profil) : logo, photo de couverture, adresse, téléphone, bouton WhatsApp.',
            'Publiez 3 à 5 fois par semaine : photos réelles, prix, et une question pour faire réagir.',
            'Partagez vos offres dans les <b>groupes du quartier ou de la ville</b> en respectant leurs règles.',
            'Mettez vos articles sur <b>Marketplace</b> avec un prix clair.',
            'Répondez à chaque commentaire et message rapidement : la rapidité vend.']],
        'tiktok' => ['/\b(tiktok|tik tok|reels?|videos? courtes?|faire des videos?|video)\b/', 'TikTok et vidéos courtes', [
            'Des vidéos de <b>10 à 30 secondes</b>, tournées au téléphone, en lumière du jour, à la verticale.',
            'Les 2 premières secondes doivent accrocher : le produit en gros plan, le prix, ou une question.',
            'Idées : déballage d\'un arrivage, avant / après, « 3 choses à savoir sur… », coulisses, réaction d\'un client (avec accord).',
            'Parlez la langue de vos clients (français, haoussa, zarma…) et ajoutez du texte à l\'écran.',
            'Publiez régulièrement ; terminez toujours par l\'adresse ou le numéro WhatsApp.']],
        'instagram' => ['/\b(instagram|insta)\b/', 'Instagram', [
            'Un compte professionnel avec photo de profil = logo, bio claire (activité, quartier, numéro WhatsApp).',
            'Des photos nettes et cohérentes (même fond, mêmes couleurs).',
            'Les <b>stories</b> chaque jour, les <b>reels</b> pour être découvert.',
            'Les « stories à la une » : Prix, Nouveautés, Avis, Livraison.']],
        'google' => ['/\b(google|google maps|maps|carte|referencement|fiche google|etre trouve|me trouver|localisation)\b/', 'Être trouvé sur Google Maps', [
            'Créez gratuitement votre <b>fiche d\'établissement Google</b> (Google Business Profile) et placez le repère exact sur la carte.',
            'Renseignez : nom, catégorie, horaires, téléphone, photos de la façade, de l\'intérieur et des produits.',
            'Demandez aux clients satisfaits de laisser un <b>avis</b>, et répondez poliment à tous les avis, même négatifs.',
            'Mettez à jour les horaires (fêtes, gardes) : une fiche à jour inspire confiance.']],
        'publicite' => ['/\b(publicites?|pub|pubs|campagnes?|sponsoris\w*|booster|boost|ads|meta ads|annonces? payantes?|payer pour|lancer une campagne|ciblage)\b/', 'Lancer une campagne publicitaire', [
            '<b>Un objectif</b> unique et mesurable : recevoir des messages WhatsApp, faire venir en boutique, vendre un article précis.',
            '<b>Une cible</b> : la ville ou un rayon autour de la boutique, l\'âge, les centres d\'intérêt de vos clients.',
            '<b>Un message</b> : une photo ou vidéo réelle, le prix, une offre limitée dans le temps, le bouton « Envoyer un message WhatsApp ».',
            '<b>Un petit budget test</b> : 5 à 7 jours avec un petit montant par jour ; sponsorisez la publication qui a déjà le plus de réactions.',
            '<b>Mesurer</b> : coût par message reçu, ventes réalisées, question « comment nous avez-vous connu ? » ; augmentez le budget seulement si ça rapporte.',
            'Hors Internet aussi : radio locale, affiches, flyers, crieur, tricycle publicitaire, sponsoring d\'un événement de quartier.']],
        'contenu' => ['/\b(quoi publier|que publier|contenu|contenus|idees? de posts?|idees? de publications?|calendrier|publier quoi|photos?)\b/', 'Que publier, et quand', [
            '<b>Règle des 4 sortes de publications</b> : présenter un produit, donner un conseil utile, montrer les coulisses, faire une offre.',
            'Photos : lumière du jour, fond propre, le produit entier, le prix lisible.',
            'Toujours finir par un <b>appel à l\'action</b> : « Écrivez-nous sur WhatsApp au … », « Passez avant samedi ».',
            'Publiez aux heures où vos clients sont sur leur téléphone : tôt le matin, à la pause de midi, le soir.',
            'Préparez la semaine le lundi : un calendrier simple évite d\'oublier.']],
        'promotion' => ['/\b(promos?|promotions?|soldes?|offres?|reduction|reductions|black friday|happy hour|fete|tabaski|ramadan|fin d annee|rentree)\b/', 'Réussir une promotion', [
            'Un but précis : écouler un stock dormant, remplir une heure creuse, attirer de nouveaux clients.',
            'Une durée courte et une date de fin claire : l\'urgence fait agir.',
            'Préférez les <b>lots</b> et les <b>cadeaux</b> aux grosses remises : votre marge est protégée.',
            'Vérifiez la marge avant (demandez-moi « remise de 10 % sur 5 000 » ou « acheté 1 000 vendu 1 250 »).',
            'Préparez le stock et la caisse : une promotion sans stock déçoit.',
            'Calez-vous sur les temps forts : Ramadan, Tabaski, rentrée, fêtes de fin d\'année, jours de paie.']],
        'fidelite' => ['/\b(fidelis\w*|fidelite|bouche a oreille|parrainage|avis clients?|clients? reviennent|recommandation)\b/', 'Fidélité et bouche-à-oreille', [
            'Un client fidèle coûte moins cher qu\'un nouveau client : soignez l\'accueil, la rapidité et la monnaie.',
            'Enregistrez nom et téléphone des clients dans POS Marwa : vous saurez qui sont vos meilleurs clients.',
            '<b>Parrainage</b> : « amenez un ami, vous recevez tous les deux un petit cadeau ».',
            'Remerciez vos meilleurs clients (message, petit cadeau, prix de gros).',
            'Demandez un avis Google ou une recommandation après un achat réussi.']],
        'terrain' => ['/\b(flyers?|affiches?|enseigne|vitrine|panneau|radio|crieur|tricycle|banderole|au marche|jours? de marche|evenement|terrain|hors ligne|sans internet|quartier)\b/', 'Être visible dans le quartier', [
            'Une <b>enseigne</b> lisible de loin, éclairée le soir, avec le numéro WhatsApp.',
            'Une <b>vitrine</b> et une entrée propres, avec les offres affichées et les prix visibles.',
            'Des <b>flyers</b> simples : offre, adresse, plan, numéro ; distribuez-les près des écoles, marchés, bureaux.',
            'La <b>radio locale</b> et les crieurs pour les grandes occasions.',
            'Les <b>partenariats</b> avec les commerces voisins (non concurrents) : on se recommande mutuellement.']],
        'influenceur' => ['/\b(influenceu\w*|influenceurs?|tiktokeurs?|createurs? de contenu|celebrites?|artistes?)\b/', 'Travailler avec des influenceurs locaux', [
            'Choisissez quelqu\'un suivi par <b>vos</b> clients (même ville, même public), pas seulement le plus célèbre.',
            'Commencez petit : produit offert contre une vidéo, puis payez selon les résultats.',
            'Donnez-lui un <b>code promo</b> unique pour mesurer les ventes qu\'il apporte.',
            'Mettez par écrit ce qui est attendu (nombre de publications, dates, mention de la boutique).']],
        'mesure' => ['/\b(mesurer|resultats? (de|d une) (campagne|pub)|savoir si (ca|la pub|la campagne) marche|retour sur investissement|roi|indicateurs?|kpi)\b/', 'Mesurer si ça marche', [
            'Notez les ventes de la semaine <b>avant</b> l\'action (rapports POS Marwa ou demandez-moi « ventes de la semaine dernière »).',
            'Comptez les messages WhatsApp reçus et les nouveaux clients enregistrés.',
            'Demandez à chaque nouveau client : « Comment nous avez-vous connu ? ».',
            'Comparez après : chiffre d\'affaires, panier moyen, article mis en avant.',
            'Calculez : bénéfice supplémentaire − coût de l\'action. Positif : on continue ; négatif : on change.']],
    ];

    public static function isMarketing(string $n): bool
    {
        if (preg_match('/\b(marketing|publicites?|pub|campagnes? (publicitaires?|pub|marketing|facebook|tiktok|whatsapp)|lancer une campagne|reseaux sociaux|facebook|tiktok|tik tok|instagram|whatsapp business|google maps|visibilite|etre visible|visible partout|se faire connaitre|faire connaitre|notoriete|sponsoris\w*|influenceu\w*|flyers?|slogan|plan marketing|guide marketing|communication commerciale|attirer (des|plus de|de nouveaux) clients|nouveaux clients|liste de diffusion|marketing (moderne|digital)|methodes? (moderne|modernes|qui marche\w*)|(faire|lancer|organiser|reussir|preparer|idees? de) (une |des |ma |mes )?(promo|promos|promotion|promotions|soldes|offre speciale))\b/', $n)) {
            return true;
        }
        return self::isGenerate($n);
    }

    public static function isGenerate(string $n): bool
    {
        return (bool) preg_match('/\b(ecris|ecrire|redige|rediger|genere|generer|cree|creer|propose|fais|prepare|donne)( moi| nous)?\b.{0,25}\b(post|posts|publication|publications|message|messages|statut|statuts|annonce|annonces|slogan|slogans|texte|textes|pub|publicite|sms|affiche|flyer|legende|script|accroche)\b/', $n)
            || (bool) preg_match('/^(un |une |des )?(post|publication|statut|slogan|annonce|sms|message) (pour|whatsapp|facebook|tiktok|sur)\b/', $n);
    }

    /** Activité du commerce (mode de fonctionnement, puis type d'activité). */
    public static function activity(): string
    {
        $mode = BusinessMode::current();
        if ($mode === 'clinique_pharmacie') {
            return 'clinique';
        }
        if ($mode !== 'commerce' && isset(self::PROFILES[$mode])) {
            return $mode;
        }
        $t = (string) setting('business_type', '');
        return isset(self::PROFILES[$t]) ? $t : 'boutique';
    }

    public static function profile(): array
    {
        return self::PROFILES[self::activity()];
    }

    public static function shop(): array
    {
        $name = trim((string) setting('company_name', '')) ?: 'Votre boutique';
        $addr = trim((string) setting('company_address', ''));
        $city = '';
        foreach (['Niamey', 'Zinder', 'Maradi', 'Tahoua', 'Agadez', 'Dosso', 'Tillabéri', 'Diffa', 'Arlit', 'Konni', 'Gaya', 'Tessaoua', 'Dogondoutchi', 'Abidjan', 'Dakar', 'Bamako', 'Ouagadougou', 'Lomé', 'Cotonou', 'Kano'] as $c) {
            if (stripos(Text::norm($addr), Text::norm($c)) !== false) {
                $city = $c;
                break;
            }
        }
        return ['name' => $name, 'phone' => trim((string) setting('company_phone', '')), 'address' => $addr, 'city' => $city];
    }

    // ------------------------------------------------------------------ données

    /** Constats marketing tirés des chiffres (seulement ce que le rôle peut voir). */
    public static function facts(): array
    {
        $f = ['tops' => [], 'dormants' => [], 'quiet' => null, 'busy' => null, 'phones' => null, 'customers' => null, 'net' => null, 'tickets' => null, 'basket' => null];
        $from = date('Y-m-d', strtotime('-29 days'));
        $to = date('Y-m-d');
        try {
            if (Auth::can('reports.view')) {
                $s = ReportService::summary($from, $to);
                $f['net'] = $s['net'];
                $f['tickets'] = $s['tickets'];
                $f['basket'] = $s['avg_basket'];
                $f['tops'] = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], ReportService::topProducts($from, $to, null, 5));
                $h = ReportService::byHour($from, $to);
                if (count($h) >= 3) {
                    usort($h, fn($a, $b) => $a['total'] <=> $b['total']);
                    $f['quiet'] = (int) $h[0]['h'];
                    $f['busy'] = (int) end($h)['h'];
                }
            }
            if (Auth::can('stock.view')) {
                $f['dormants'] = array_column(DB::all("SELECT p.name FROM products p JOIN (SELECT product_id, SUM(quantity) q FROM stock_levels GROUP BY product_id) st ON st.product_id = p.id
                    WHERE p.status = 'active' AND p.track_stock = 1 AND st.q > 0 AND NOT EXISTS (SELECT 1 FROM sale_items y JOIN sales x ON x.id = y.sale_id WHERE y.product_id = p.id AND x.status <> 'cancelled' AND x.created_at >= NOW() - INTERVAL 30 DAY)
                    ORDER BY st.q * p.cost_price DESC LIMIT 5"), 'name');
            }
            if (Auth::can('customers.view')) {
                $c = DB::one("SELECT COUNT(*) n, SUM(phone IS NOT NULL AND phone <> '') p FROM customers WHERE active = 1");
                $f['customers'] = (int) $c['n'];
                $f['phones'] = (int) $c['p'];
            }
        } catch (\Throwable) {
            // les constats sont un plus
        }
        return $f;
    }

    private static function factLines(array $f): array
    {
        $out = [];
        if ($f['tops']) {
            $out[] = 'Vos articles vedettes, à mettre en avant en premier : <b>' . e(implode(', ', array_column(array_slice($f['tops'], 0, 3), 'name'))) . '</b>.';
        }
        if ($f['quiet'] !== null) {
            $out[] = sprintf('Heure la plus calme : <b>%02d h – %02d h</b> (idéale pour une offre ciblée) ; heure de pointe : %02d h.', $f['quiet'], $f['quiet'] + 1, $f['busy']);
        }
        if ($f['dormants']) {
            $out[] = 'Articles qui dorment depuis 30 jours, à transformer en lot ou en promotion : ' . e(implode(', ', array_slice($f['dormants'], 0, 3))) . '.';
        }
        if ($f['customers'] !== null) {
            $out[] = $f['phones'] > 0 ? '<b>' . $f['phones'] . '</b> client(s) enregistré(s) avec un téléphone : c\'est le début de votre liste de diffusion WhatsApp.'
                : 'Aucun client enregistré avec son téléphone : commencez à les saisir en caisse, c\'est votre future liste WhatsApp.';
        }
        return $out;
    }

    // ------------------------------------------------------------------ réponses

    public static function answer(string $q): array
    {
        $n = Text::norm($q);
        if (preg_match('/\b(pdf|imprim\w*|telecharg\w*|document|guide|plan marketing|plan de communication)\b/', $n)) {
            return self::planAnswer();
        }
        if (self::isGenerate($n)) {
            return self::generate($q);
        }
        $topic = 'bases';
        if (!preg_match('/\b(comment (marche|fonctionne) le marketing|c est quoi le marketing|qu est ce que le marketing|bases du marketing|explique moi le marketing)\b/', $n)) {
            foreach (['mesure', 'publicite', 'influenceur', 'promotion', 'tendances', 'whatsapp', 'facebook', 'tiktok', 'instagram', 'google', 'contenu', 'fidelite', 'terrain', 'reseaux'] as $k) {
                if (preg_match(self::TOPICS[$k][0], $n)) {
                    $topic = $k;
                    break;
                }
            }
        }
        return self::topic($topic);
    }

    public static function topic(string $k): array
    {
        $t = self::TOPICS[$k];
        $p = self::profile();
        $act = self::activity();
        $html = '<p class="h">' . e($t[1]) . ' <span class="src">— pour votre ' . e($p[0]) . '</span></p>';
        if ($k === 'bases') {
            $html .= '<p>Le marketing, c\'est faire en sorte que <b>les bonnes personnes</b> connaissent votre commerce, aient <b>envie</b> d\'y venir, et <b>reviennent</b>. Pour vous, la clientèle à viser : ' . e($p[1]) . '.</p>';
        }
        $regulated = $p[6] && in_array($act, ['pharmacie', 'clinique', 'ecole'], true);
        if ($regulated) {
            $html .= '<p class="mia-bad"><b>D\'abord, à respecter dans votre activité :</b> ' . e($p[6]) . '</p>';
        }
        $html .= '<ol class="mia-steps">' . implode('', array_map(fn($s) => '<li>' . $s . '</li>', $t[2])) . '</ol>';
        $html .= '<div class="mia-tip"><b>Dans votre activité</b><ul>'
            . '<li>Canaux prioritaires : ' . e(implode(', ', array_slice($p[3], 0, 4))) . '.</li>'
            . '<li>À publier : ' . e(implode(' ; ', array_slice($p[4], 0, 3))) . '.</li>'
            . '<li>Offres qui marchent : ' . e(implode(' ; ', array_slice($p[5], 0, 3))) . '.</li></ul></div>';
        if ($p[6] && !$regulated) {
            $html .= '<p class="mia-bad"><b>À respecter :</b> ' . e($p[6]) . '</p>';
        }
        $facts = self::factLines(self::facts());
        if ($facts) {
            $html .= '<p><b>Ce que je vois dans vos chiffres :</b></p><ul class="mia-facts">' . implode('', array_map(fn($x) => '<li>' . $x . '</li>', $facts)) . '</ul>';
        }
        $html .= Wisdom::html(Wisdom::forTheme($k === 'fidelite' ? 'clients' : 'croissance'));
        $pdf = url('/ia/marketing', ['export' => 'pdf', 'view' => 1]);
        $next = ['bases' => 'Comment utiliser WhatsApp Business ?', 'whatsapp' => 'Écris un statut WhatsApp pour mon article vedette', 'facebook' => 'Comment lancer une campagne publicitaire ?',
            'tiktok' => 'Écris un script TikTok pour mon article vedette', 'instagram' => 'Que publier cette semaine ?', 'google' => 'Comment avoir plus d\'avis clients ?',
            'publicite' => 'Comment mesurer si ma pub marche ?', 'contenu' => 'Écris une publication Facebook', 'promotion' => 'Écris une annonce de promotion', 'fidelite' => 'Comment être visible dans mon quartier ?',
            'terrain' => 'Propose-moi un slogan', 'tendances' => 'Comment lancer une campagne publicitaire ?', 'reseaux' => 'Comment utiliser WhatsApp Business ?', 'influenceur' => 'Comment mesurer si ma pub marche ?', 'mesure' => 'Comment lancer une campagne publicitaire ?'][$k];
        return ['ok' => true, 'type' => 'marketing', 'html' => $html,
            'actions' => [['label' => 'Mon plan marketing (PDF)', 'url' => $pdf, 'kind' => 'view'], ['label' => 'Télécharger le PDF', 'url' => url('/ia/marketing', ['export' => 'pdf']), 'kind' => 'pdf']],
            'suggestions' => array_values(array_unique([$next, 'Mon plan marketing en PDF', $act === 'ecole' || $act === 'clinique' ? 'Comment être trouvé sur Google Maps ?' : 'Écris un post pour mon article vedette', 'Quiz marketing'])), 'spec' => null];
    }

    public static function planAnswer(): array
    {
        $p = self::profile();
        $view = url('/ia/marketing', ['export' => 'pdf', 'view' => 1]);
        $html = '<p class="h">Votre plan marketing personnalisé</p><p>Je l\'ai préparé pour votre <b>' . e($p[0]) . '</b> avec vos propres chiffres : comment marche le marketing, comment vendre plus, WhatsApp, Facebook, TikTok, Google Maps, campagnes publicitaires, promotions, visibilité dans le quartier, <b>plan d\'action sur 30 jours</b>, calendrier de publication, <b>textes prêts à publier</b> et tableau de suivi.</p>';
        return ['ok' => true, 'type' => 'marketing', 'title' => 'Plan marketing — ' . self::shop()['name'], 'html' => $html,
            'actions' => [['label' => 'Voir le PDF', 'url' => $view, 'kind' => 'view'], ['label' => 'Télécharger le PDF', 'url' => url('/ia/marketing', ['export' => 'pdf']), 'kind' => 'pdf']],
            'open' => $view, 'suggestions' => ['Écris un post pour mon article vedette', 'Comment lancer une campagne publicitaire ?', 'Quiz marketing'], 'spec' => null];
    }

    // ------------------------------------------------------------------ textes prêts à publier

    /** Sujet du texte : article cité, article vedette, ou service de l'activité. */
    public static function subject(string $q): array
    {
        $act = self::activity();
        $services = ['pressing' => 'notre service de pressing', 'hotel' => 'nos chambres', 'ecole' => 'les inscriptions', 'clinique' => 'nos consultations', 'pharmacie' => 'notre pharmacie'];
        $w = array_values(array_diff(Text::words($q), ['post', 'publication', 'statut', 'whatsapp', 'facebook', 'tiktok', 'slogan', 'annonce', 'promotion', 'promo', 'ecris', 'redige', 'genere', 'message', 'sms', 'script', 'article', 'vedette', 'pour', 'mon', 'ma', 'mes', 'nos', 'notre', 'une', 'un']));
        if ($w && Auth::can('pos.sell') || $w && Auth::can('products.view')) {
            $best = null;
            $bs = 0.0;
            foreach (DB::all("SELECT id, name, sale_price, promo_price, promo_start, promo_end FROM products WHERE status = 'active' AND sale_price > 0 LIMIT 5000") as $r) {
                $s = Text::itemScore($w, (string) $r['name']);
                if ($s > $bs) {
                    $bs = $s;
                    $best = $r;
                }
            }
            if ($best && $bs >= 0.5) {
                return self::productSubject($best);
            }
        }
        if (in_array($act, ['pressing', 'hotel', 'ecole', 'clinique'], true) || ($act === 'pharmacie' && !preg_match('/\b(parapharmacie|hygiene|bebe|cosmetique)\b/', Text::norm($q)))) {
            return ['name' => $services[$act], 'price' => null, 'promo' => null, 'product' => false];
        }
        $top = self::facts()['tops'][0]['id'] ?? (int) DB::value("SELECT id FROM products WHERE status = 'active' AND sale_price > 0 ORDER BY id LIMIT 1");
        $r = $top ? DB::one('SELECT id, name, sale_price, promo_price, promo_start, promo_end FROM products WHERE id = ?', [$top]) : null;
        return $r ? self::productSubject($r) : ['name' => 'nos articles', 'price' => null, 'promo' => null, 'product' => false];
    }

    private static function productSubject(array $r): array
    {
        $today = date('Y-m-d');
        $promo = $r['promo_price'] !== null && (float) $r['promo_price'] > 0 && (float) $r['promo_price'] < (float) $r['sale_price']
            && ($r['promo_start'] === null || $r['promo_start'] <= $today) && ($r['promo_end'] === null || $r['promo_end'] >= $today) ? (float) $r['promo_price'] : null;
        return ['name' => (string) $r['name'], 'price' => (float) $r['sale_price'], 'promo' => $promo, 'product' => true];
    }

    /** @return array<string, string> canal => texte prêt à copier */
    public static function texts(array $s, int $seed = 0): array
    {
        $shop = self::shop();
        $p = self::profile();
        $act = self::activity();
        $where = $shop['address'] !== '' ? $shop['address'] : ($shop['city'] ?: 'notre boutique');
        $wa = $shop['phone'] !== '' ? $shop['phone'] : '[votre numéro WhatsApp]';
        $price = $s['price'] !== null ? ($s['promo'] !== null ? money($s['promo']) . ' au lieu de ' . money($s['price']) : money($s['price'])) : '';
        $tags = $p[7];
        if ($shop['city'] !== '') {
            array_unshift($tags, '#' . str_replace([' ', '\'', 'é'], ['', '', 'e'], $shop['city']));
        }
        $hooks = ['Arrivage du jour', 'Bonne nouvelle', 'Disponible maintenant', 'Vous l\'attendiez', 'Ne ratez pas ça', 'Nouveau chez nous'];
        $hook = $hooks[$seed % count($hooks)];
        $ctas = ['Commandez ou réservez sur WhatsApp : ', 'Écrivez-nous sur WhatsApp : ', 'Un message suffit, on vous le prépare : '];
        $cta = $ctas[$seed % count($ctas)];
        $name = $s['name'];
        $isService = !$s['product'];
        $line = $isService ? ucfirst($name) . ' : ' . $p[5][$seed % count($p[5])] . '.' : $name . ($price !== '' ? ' — ' . $price : '') . '.';
        $why = match ($act) {
            'alimentation', 'boissons' => 'Qualité, bons prix et service rapide.',
            'restaurant' => 'Préparé frais, servi chaud, avec le sourire.',
            'pharmacie' => 'Accueil, conseil et écoute pour toute la famille.',
            'clinique' => 'Une équipe à votre écoute, sur rendez-vous ou sans.',
            'pressing' => 'Vos vêtements propres, repassés et prêts à l\'heure.',
            'hotel' => 'Chambres propres, wifi, accueil chaleureux.',
            'ecole' => 'Un encadrement sérieux pour la réussite de vos enfants.',
            default => 'Qualité, prix justes et conseil.',
        };
        $urg = $s['promo'] !== null ? 'Offre limitée, jusqu\'à épuisement du stock.' : ($isService ? '' : 'Quantités limitées.');
        $out = [];
        $out['Statut / message WhatsApp'] = trim(($hook === 'Nouveau chez nous' ? 'Nouveau chez ' . $shop['name'] : "$hook chez {$shop['name']}") . " !\n$line\n$why $urg\n$cta$wa\n$where");
        $out['Publication Facebook'] = trim("$hook ! " . ($isService ? '' : 'Vous cherchiez ' . $name . ' ? ') . "\n\n$line\n$why\n" . ($urg !== '' ? "$urg\n" : '')
            . "\nOù ? $where\nComment ? Envoyez-nous un message WhatsApp au $wa ou passez nous voir.\n\n" . ($isService ? 'Dites-nous en commentaire ce qui compte le plus pour vous !' : 'Question : vous le prenez à l\'unité ou en lot ? Répondez en commentaire.')
            . "\n\n" . implode(' ', $tags));
        $out['Script de vidéo TikTok / Reels (15 s)'] = "0-2 s : gros plan sur " . ($isService ? 'votre équipe au travail' : $name) . ", texte à l'écran « $hook ».\n"
            . "3-9 s : montrez " . ($isService ? 'le service (avant / après, l\'accueil, les lieux)' : 'l\'article sous tous les angles et le prix' . ($price !== '' ? " ($price)" : '')) . ".\n"
            . "10-15 s : la façade de {$shop['name']} + « Écrivez-nous sur WhatsApp : $wa ».\nTexte : $line " . implode(' ', array_slice($tags, 0, 3));
        $sms = self::ascii("{$shop['name']}: " . ($isService ? ucfirst($name) : $name . ($price !== '' ? ' ' . str_replace(' au lieu de ', ' (au lieu de ', $price) . ($s['promo'] !== null ? ')' : '') : '')) . ". Infos/commandes WhatsApp $wa");
        $out['SMS (' . mb_strlen($sms) . ' caractères)'] = mb_substr($sms, 0, 160);
        return $out;
    }

    public static function slogans(): array
    {
        $shop = self::shop()['name'];
        $act = self::activity();
        $base = [
            'alimentation' => ['Tout pour la maison, au juste prix.', 'Le bon produit, le bon prix, tout près de chez vous.', 'Vos courses, vite et bien.'],
            'boutique' => ['Votre style, notre passion.', 'Le nouveau arrive ici en premier.', 'Élégance à petit prix.'],
            'quincaillerie' => ['Pour construire solide.', 'Du fondement à la toiture.', 'Les bons matériaux, au bon prix.'],
            'boissons' => ['La fraîcheur livrée chez vous.', 'Vos fêtes commencent ici.', 'Toujours frais, toujours disponible.'],
            'cosmetique' => ['Révélez votre beauté naturelle.', 'Prenez soin de vous, on prend soin de vous conseiller.', 'La beauté en confiance.'],
            'informatique' => ['La technologie à votre portée.', 'Le bon matériel, le bon conseil.', 'Connectés, garantis, servis.'],
            'papeterie' => ['Tout pour réussir.', 'De la rentrée au bureau, on a tout.', 'Le savoir commence ici.'],
            'pieces' => ['La bonne pièce, du premier coup.', 'On roule avec vous.', 'Pièces fiables, route sereine.'],
            'restaurant' => ['Le goût de chez nous.', 'Ici, on mange bien et on revient.', 'Frais, chaud, généreux.'],
            'pharmacie' => ['Votre santé, notre engagement.', 'À l\'écoute, au service de votre famille.', 'Conseil et confiance, tous les jours.'],
            'clinique' => ['Soigner avec compétence et humanité.', 'Votre santé entre de bonnes mains.', 'Proches de vous, au service de la vie.'],
            'pressing' => ['Propre, net, à l\'heure.', 'Vos vêtements, notre fierté.', 'Le soin de vos tenues, notre métier.'],
            'hotel' => ['Comme à la maison, en mieux.', 'Votre repos, notre priorité.', 'L\'accueil qui fait revenir.'],
            'ecole' => ['Former aujourd\'hui les talents de demain.', 'Ensemble pour la réussite.', 'L\'excellence, une habitude.'],
        ][$act];
        $base[] = $shop . ', ' . lcfirst(rtrim($base[0], '.')) . ' !';
        return $base;
    }

    private static function ascii(string $s): string
    {
        $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ç' => 'c', 'ù' => 'u', 'û' => 'u', '’' => "'", "\u{202F}" => ' ', "\u{00A0}" => ' ', 'É' => 'E']);
        return (string) preg_replace('/\s+/', ' ', $s);
    }

    public static function generate(string $q, ?int $seed = null): array
    {
        $n = Text::norm($q);
        $seed = $seed ?? ((int) date('z')) % 6;
        $html = '';
        if (preg_match('/\bslogans?\b/', $n)) {
            $html .= '<p class="h">Idées de slogans</p><ul class="mia-facts">' . implode('', array_map(fn($s) => '<li>' . e($s) . '</li>', self::slogans())) . '</ul><p class="small">Un bon slogan est court, facile à retenir et dit ce qui vous distingue. Testez-le auprès de vos clients.</p>';
            return ['ok' => true, 'type' => 'marketing', 'html' => $html, 'actions' => [], 'suggestions' => ['Écris un post pour mon article vedette', 'Mon plan marketing en PDF', 'Comment être visible dans mon quartier ?'], 'spec' => null];
        }
        $s = self::subject($q);
        $all = self::texts($s, $seed);
        $want = match (true) {
            (bool) preg_match('/\bwhatsapp|statut/', $n) => ['Statut / message WhatsApp'],
            (bool) preg_match('/\bfacebook|fb\b/', $n) => ['Publication Facebook'],
            (bool) preg_match('/\btiktok|reel|video|script/', $n) => ['Script de vidéo TikTok / Reels (15 s)'],
            (bool) preg_match('/\bsms\b/', $n) => [array_key_last($all)],
            default => array_keys($all),
        };
        $html .= '<p class="h">Textes prêts à publier — ' . e($s['name']) . '</p>';
        foreach ($want as $k) {
            $html .= '<div class="mia-copy"><div class="mia-copy-h"><b>' . e($k) . '</b><button type="button" data-mia-copy>Copier</button></div><pre>' . e($all[$k]) . '</pre></div>';
        }
        $p = self::profile();
        if ($p[6]) {
            $html .= '<p class="small"><b>Rappel :</b> ' . e($p[6]) . '</p>';
        }
        $html .= '<p class="small">Ajoutez une vraie photo ou vidéo prise dans votre commerce, et vérifiez le prix et le stock avant de publier.' . (self::shop()['phone'] === '' ? ' Renseignez le téléphone de l\'entreprise dans les Paramètres pour qu\'il apparaisse automatiquement.' : '') . '</p>';
        return ['ok' => true, 'type' => 'marketing', 'html' => $html, 'actions' => [],
            'suggestions' => ['Un autre texte', 'Propose-moi un slogan', 'Comment lancer une campagne publicitaire ?', 'Mon plan marketing en PDF'], 'spec' => ['kind' => 'mk_gen', 'q' => mb_substr($q, 0, 200), 'seed' => $seed]];
    }

    // ------------------------------------------------------------------ plan marketing PDF

    public static function pdf(): string
    {
        $shop = self::shop();
        $p = self::profile();
        $act = self::activity();
        $f = self::facts();
        $doc = new PdfDoc('Plan marketing — ' . $shop['name'], 'Préparé par MARWA IA (POS Marwa) le ' . date('d/m/Y') . ' — conseils indicatifs, à adapter à votre situation.');
        $doc->cover('Plan marketing personnalisé', 'Vendre plus et être visible partout', 'Guide pratique et plan d\'action sur 30 jours pour ' . $shop['name'], [
            ['Commerce', $shop['name']], ['Activité', ucfirst($p[0])], ['Clientèle visée', $p[1]], ['Préparé le', date('d/m/Y')],
        ], Wisdom::forTheme('croissance'), ['Votre point de départ', 'Comment marche le marketing', 'Comment vendre plus (et les méthodes d\'aujourd\'hui)', 'Être visible sur Internet et les réseaux sociaux',
            'Lancer une campagne publicitaire', 'Être visible partout : quartier et bouche-à-oreille', 'Votre plan d\'action sur 30 jours', 'Textes prêts à publier', 'Mesurer vos résultats']);

        // 1. Situation
        $doc->h1('Votre point de départ', '1');
        $k = [];
        if ($f['net'] !== null) {
            $k[] = ['Ventes 30 jours', money($f['net'])];
            $k[] = ['Nombre de ventes', (string) $f['tickets']];
            $k[] = ['Panier moyen', money(round((float) $f['basket']))];
        }
        if ($f['customers'] !== null) {
            $k[] = ['Clients avec téléphone', (string) $f['phones']];
        }
        $doc->kpis($k);
        $lines = array_map(fn($x) => strip_tags($x), self::factLines($f));
        $doc->bullets($lines ?: ['Vos chiffres ne sont pas visibles avec votre rôle : demandez au gérant de générer ce plan pour l\'avoir complet.']);
        $doc->box('Votre objectif pour les 30 prochains jours', 'Augmenter le nombre de clients et le panier moyen, en mettant en avant ' . ($f['tops'] ? implode(', ', array_column(array_slice($f['tops'], 0, 3), 'name')) : $p[2])
            . ($f['dormants'] ? ', et en écoulant les articles qui dorment' : '') . '. Notez aujourd\'hui vos ventes de la semaine : ce sera votre point de comparaison.');

        // 2. Bases
        $doc->h1('Comment marche le marketing', '2');
        $doc->p('Le marketing, c\'est faire en sorte que les bonnes personnes connaissent votre commerce, aient envie d\'y venir, et reviennent. Il se résume en cinq questions :');
        $doc->bullets(self::TOPICS['bases'][2]);
        $doc->box('Pour votre ' . $p[0], 'Clientèle : ' . $p[1] . '. Canaux prioritaires : ' . implode(', ', $p[3]) . '.');
        if ($p[6]) {
            $doc->box('À respecter dans votre activité', $p[6], PdfDoc::SAND, PdfDoc::ORANGE);
        }

        // 3. Vendre plus
        $doc->h1('Comment vendre plus', '3');
        $doc->bullets([
            'Plus de clients : être visible (enseigne, Google Maps, réseaux sociaux) et donner une raison de venir (offre, nouveauté, service).',
            'Plus souvent : fidéliser (accueil, rapidité, parrainage, messages aux bons clients).',
            'Plus par visite : proposer l\'article complémentaire, les lots et conditionnements, mettre les meilleures ventes près de la caisse.',
            'Jamais de rupture sur les articles vedettes : demandez à MARWA IA « que dois-je commander ? ».',
            'Accepter tous les paiements : espèces, mobile money, carte, crédit encadré.',
        ]);
        $doc->h2('Offres qui marchent pour votre activité');
        $doc->bullets($p[5]);

        // 3 bis. Méthodes d'aujourd'hui
        $doc->h2('Les méthodes qui marchent aujourd\'hui');
        $doc->bullets(self::TOPICS['tendances'][2]);

        // 4. Réseaux
        foreach (['whatsapp', 'facebook', 'tiktok', 'google'] as $i => $t) {
            if ($i === 0) {
                $doc->newPage();
                $doc->h1('Être visible sur Internet et les réseaux sociaux', '4');
                $doc->bullets(self::TOPICS['reseaux'][2]);
            }
            $doc->h2(self::TOPICS[$t][1]);
            $doc->bullets(self::TOPICS[$t][2]);
        }
        $doc->h2('Que publier, et quand');
        $doc->bullets(self::TOPICS['contenu'][2]);
        $doc->h2('Idées de publications pour votre ' . $p[0]);
        $doc->bullets($p[4]);

        // 5. Publicité
        $doc->h1('Lancer une campagne publicitaire', '5');
        $doc->bullets(self::TOPICS['publicite'][2], true);
        $budget = $f['net'] ? max(5000, (int) round($f['net'] * 0.02, -3)) : 15000;
        $doc->box('Budget indicatif', 'Commencez petit : environ ' . money($budget) . ' pour le premier mois (de l\'ordre de 1 à 3 % de vos ventes), réparti sur 2 campagnes tests de 5 à 7 jours. Augmentez seulement ce qui rapporte. Les prix des plateformes publicitaires changent : vérifiez-les au moment de payer.');
        $doc->h2('Réussir une promotion');
        $doc->bullets(self::TOPICS['promotion'][2]);

        // 6. Terrain et fidélité
        $doc->h1('Être visible partout : quartier et bouche-à-oreille', '6');
        $doc->bullets(self::TOPICS['terrain'][2]);
        $doc->h2('Fidélité et recommandation');
        $doc->bullets(self::TOPICS['fidelite'][2]);
        $doc->h2('Influenceurs locaux');
        $doc->bullets(self::TOPICS['influenceur'][2]);

        // 7. Plan 30 jours
        $doc->newPage();
        $doc->h1('Votre plan d\'action sur 30 jours', '7');
        $tops = $f['tops'] ? implode(', ', array_column(array_slice($f['tops'], 0, 3), 'name')) : $p[2];
        $quiet = $f['quiet'] !== null ? sprintf('%02d h – %02d h', $f['quiet'], $f['quiet'] + 1) : 'les heures creuses';
        $doc->table(['Semaine', 'Actions', 'Résultat attendu'], [
            ['Semaine 1 — Fondations', 'Installer WhatsApp Business (profil, catalogue avec ' . $tops . '). Créer la fiche Google Maps et la page Facebook. Photos de la façade et des produits. Enseigne et vitrine propres.', 'Votre commerce est trouvable et présentable.'],
            ['Semaine 2 — Contenu', 'Publier 4 à 5 fois (voir calendrier). Un statut WhatsApp par jour. Demander aux clients d\'enregistrer votre numéro. Offre spéciale sur ' . $quiet . '.', 'Vos contacts vous voient chaque jour.'],
            ['Semaine 3 — Campagne', 'Sponsoriser la meilleure publication 5 à 7 jours (ville ou rayon de la boutique). ' . ($f['dormants'] ? 'Lot ou promotion sur : ' . implode(', ', array_slice($f['dormants'], 0, 2)) . '.' : 'Promotion courte sur un article vedette.'), 'Nouveaux clients et messages WhatsApp.'],
            ['Semaine 4 — Fidéliser et mesurer', 'Message de remerciement aux meilleurs clients. Lancer le parrainage. Demander des avis Google. Comparer les ventes avec la semaine 0.', 'Clients qui reviennent ; décision sur la suite.'],
        ], [1.3, 3.2, 1.6]);

        $doc->h2('Calendrier de publication type');
        $doc->table(['Jour', 'Publication', 'Canal'], [
            ['Lundi', 'Arrivage / nouveauté de la semaine (photo + prix)', 'WhatsApp, Facebook'],
            ['Mardi', 'Conseil utile pour vos clients (' . $p[2] . ')', 'Facebook, TikTok'],
            ['Mercredi', 'Coulisses : l\'équipe, la préparation, la livraison', 'TikTok, statut'],
            ['Jeudi', 'Article vedette en vidéo courte', 'TikTok / Reels'],
            ['Vendredi', 'Offre du week-end (date de fin claire)', 'WhatsApp diffusion, Facebook'],
            ['Samedi', 'Ambiance et clients satisfaits (avec accord)', 'Statut, Instagram'],
            ['Dimanche', 'Remerciement + rappel des horaires et du numéro', 'WhatsApp, Google'],
        ], [1, 3.4, 1.8]);

        // 8. Textes
        $doc->newPage();
        $doc->h1('Textes prêts à publier', '8');
        $doc->p('Copiez, ajoutez une vraie photo, vérifiez le prix et le stock, publiez.', 9.5, PdfDoc::GREY);
        foreach (self::texts(self::subject(''), 0) as $title => $txt) {
            $doc->box($title, str_replace("\n", ' / ', $txt));
        }
        $doc->h2('Idées de slogans');
        $doc->bullets(self::slogans());

        // 9. Mesure
        $doc->h1('Mesurer vos résultats', '9');
        $doc->bullets(self::TOPICS['mesure'][2], true);
        $doc->table(['Indicateur', 'Semaine 0', 'Semaine 1', 'Semaine 2', 'Semaine 3', 'Semaine 4'], [
            ['Chiffre d\'affaires', '', '', '', '', ''], ['Nombre de ventes', '', '', '', '', ''], ['Panier moyen', '', '', '', '', ''],
            ['Messages WhatsApp reçus', '', '', '', '', ''], ['Nouveaux clients enregistrés', '', '', '', '', ''], ['Dépense publicitaire', '', '', '', '', ''],
        ], [2.2, 1, 1, 1, 1, 1]);
        $doc->p('Dans POS Marwa : Rapports → Ventes, ou demandez à MARWA IA « bilan de la semaine », « meilleurs clients », « ventes par heure ».', 9.5, PdfDoc::GREY);
        $doc->h2('Petit lexique');
        $doc->table(['Mot', 'Ce que ça veut dire'], [
            ['Appel à l\'action', 'La phrase qui dit au client quoi faire maintenant : « Écrivez-nous sur WhatsApp », « Passez avant samedi ».'],
            ['Ciblage', 'Choisir qui verra votre publicité : ville, rayon, âge, centres d\'intérêt.'],
            ['Portée', 'Le nombre de personnes qui ont vu votre publication.'],
            ['Taux de conversion', 'La part des personnes intéressées qui achètent vraiment.'],
            ['Coût par message', 'Budget publicitaire ÷ nombre de messages reçus.'],
        ], [1.4, 4]);
        $q = Wisdom::forTheme('clients');
        $doc->space(10);
        $doc->box('Pour finir', '« ' . $q[0] . ' » — ' . $q[1] . '. Commencez petit, soyez régulier, mesurez : le marketing est un travail de chaque semaine.', PdfDoc::SAND, PdfDoc::ORANGE);
        return $doc->output();
    }
}
