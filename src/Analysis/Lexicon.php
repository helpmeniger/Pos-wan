<?php
declare(strict_types=1);

namespace Verifia\Analysis;

/**
 * Lexiques (français / anglais) utilisés par l'analyse heuristique.
 * Les termes sont comparés sur un texte sans accents et en minuscules.
 * Ces listes sont volontairement simples, explicables et modifiables.
 */
final class Lexicon
{
    public const CATEGORIES = [
        'politique' => ['gouvernement', 'president', 'ministre', 'election', 'elections', 'parlement', 'depute', 'senat', 'parti', 'opposition', 'vote', 'scrutin', 'campagne electorale', 'decret', 'assemblee nationale', 'junte', 'coup d etat', 'government', 'president', 'minister', 'parliament'],
        'economie' => ['economie', 'inflation', 'prix', 'banque', 'franc cfa', 'fcfa', 'budget', 'dette', 'croissance', 'impot', 'impots', 'salaire', 'salaires', 'marche', 'bourse', 'emploi', 'chomage', 'petrole', 'carburant', 'economy', 'price', 'prices', 'bank', 'tax'],
        'sante' => ['sante', 'hopital', 'vaccin', 'vaccins', 'vaccination', 'virus', 'epidemie', 'pandemie', 'maladie', 'medecin', 'medicament', 'covid', 'cholera', 'paludisme', 'malaria', 'guerison', 'traitement', 'cancer', 'oms', 'health', 'vaccine', 'hospital', 'disease', 'cure'],
        'education' => ['ecole', 'ecoles', 'universite', 'etudiant', 'etudiants', 'eleves', 'enseignant', 'enseignants', 'examen', 'examens', 'baccalaureat', 'bac', 'bepc', 'rentree scolaire', 'school', 'university', 'students', 'exam'],
        'securite' => ['attaque', 'attentat', 'terroriste', 'terroristes', 'armee', 'militaire', 'militaires', 'soldats', 'police', 'gendarmerie', 'enlevement', 'otages', 'explosion', 'tues', 'blesses', 'jihadiste', 'jihadistes', 'insecurite', 'attack', 'army', 'soldiers', 'killed', 'terrorist'],
        'science' => ['science', 'scientifique', 'scientifiques', 'chercheurs', 'etude', 'recherche', 'nasa', 'climat', 'technologie', 'intelligence artificielle', 'decouverte', 'researchers', 'study', 'scientists', 'climate'],
        'societe' => ['societe', 'famille', 'mariage', 'religion', 'culture', 'jeunesse', 'femmes', 'communaute', 'tradition', 'village', 'quartier', 'manifestation', 'greve', 'society', 'protest', 'strike'],
        'actualite' => ['aujourd hui', 'ce matin', 'hier', 'cette nuit', 'breaking', 'flash', 'derniere minute', 'annonce', 'communique', 'officiel', 'today', 'announced', 'reported'],
    ];

    public const OPINION = ['je pense', 'a mon avis', 'selon moi', 'je crois', 'pour moi', 'il me semble', 'mon opinion', 'je trouve que', 'edito', 'editorial', 'tribune', 'i think', 'in my opinion', 'i believe', 'imo'];
    public const SATIRE = ['satire', 'satirique', 'parodie', 'humour', 'gorafi', 'canular assume', 'parody', 'satirical', 'the onion', '#satire', 'fiction'];
    public const ADVERTISING = ['sponsorise', 'sponsorisee', 'publicite', 'promo', 'promotion', 'achetez', 'commandez', 'livraison gratuite', 'reduction', 'offre speciale', 'contactez nous au', 'prix imbattable', 'sponsored', 'buy now', 'discount', 'order now'];
    public const RUMOR = ['on dit que', 'il parait que', 'selon des rumeurs', 'des sources non confirmees', 'information non confirmee', 'circule sur les reseaux', 'rumeur', 'rumeurs', 'aurait ete', 'serait mort', 'serait decede', 'rumor', 'unconfirmed'];

    public const SENSATIONAL = ['urgent', 'choquant', 'incroyable', 'scandale', 'scandaleux', 'exclusif', 'revelation', 'revelations', 'alerte', 'bombe', 'terrifiant', 'hallucinant', 'vous n allez pas croire', 'a voir absolument', 'personne n en parle', 'breaking', 'shocking', 'unbelievable', 'must see'];
    public const SHARE_CALL = ['partagez', 'partager avant', 'faites tourner', 'avant que ce soit supprime', 'avant qu il soit supprime', 'diffusez', 'faites circuler', 'partagez massivement', 'share before', 'share this', 'spread the word'];
    public const CONSPIRACY = ['les medias ne vous diront pas', 'ce qu on vous cache', 'on nous cache', 'la verite cachee', 'ils ne veulent pas que vous sachiez', 'complot', 'reveillez vous', 'les medias se taisent', 'censure', 'what they don t want you to know', 'wake up', 'mainstream media won t'];
    public const MIRACLE = ['guerit en', 'remede miracle', 'traitement miracle', 'guerison miracle', '100 efficace', 'les medecins detestent', 'sans effet secondaire', 'miracle cure'];

    public const ATTRIBUTION = ['selon', 'd apres', 'a declare', 'a annonce', 'a affirme', 'a indique', 'source', 'sources', 'communique', 'rapport', 'according to', 'said', 'reported by', 'source'];
    public const FACT_VERBS = ['a ete', 'ont ete', 'est mort', 'est decede', 'sont morts', 'a annonce', 'a declare', 'a signe', 'a interdit', 'a arrete', 'ont arrete', 'a ete arrete', 'augmente', 'diminue', 'a tue', 'ont tue', 'confirme', 'interdit', 'va fermer', 'sera ferme', 'a demissionne', 'was', 'were', 'has been', 'announced', 'killed', 'banned', 'arrested', 'died'];
    public const RECENCY = ['aujourd hui', 'ce matin', 'ce soir', 'cette nuit', 'hier', 'en ce moment', 'actuellement', 'il y a quelques heures', 'en direct', 'maintenant', 'today', 'this morning', 'right now', 'live', 'yesterday'];

    public const MONTHS = ['janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];
}
