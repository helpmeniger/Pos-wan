<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

/**
 * Garde-fou : MARWA IA reste dans son domaine (la gestion et le développement du commerce de
 * l'utilisateur, et l'utilisation de POS Marwa). Les sujets sans rapport reçoivent un refus
 * poli et un rappel de ce qu'il sait faire ; les questions de santé personnelle sont
 * renvoyées vers un professionnel.
 */
final class Scope
{
    private const OFF = [
        'politique' => '/\b(politique|elections?|president de la republique|gouvernement|parti politique|coup d etat|ministre|depute)\b/',
        'religion' => '/\b(religion|islam|chretien\w*|coran|bible|priere|imam|pasteur|dieu existe)\b/',
        'sport' => '/\b(football|foot|match|ligue des champions|can 20\d\d|coupe du monde|score|messi|ronaldo|basket)\b/',
        'divertissement' => '/\b(blague|devinette|chanson|film|musique|jeu video|horoscope|signe astrologique|amour|ma copine|mon copain|mariage avec|draguer)\b/',
        'scolaire' => '/\b(devoirs?|exercice de (math|physique|francais)|dissertation|resous|resoudre l equation|traduis|traduction en)\b/',
        'argent_risque' => '/\b(paris sportifs?|pari sportif|bitcoin|crypto\w*|forex|trading|loterie|pmu|devenir riche vite)\b/',
        'meteo' => '/\b(meteo|va t il pleuvoir|temperature demain|quel temps fait)\b/',
        'piratage' => '/\b(pirater|hacker|espionner|mot de passe (de|d) (quelqu un|mon voisin|ma femme|mon mari)|cracker)\b/',
    ];

    private const HEALTH = '/\b(j ai mal|je suis malade|mon enfant (est malade|a de la fievre)|symptomes?|diagnosti\w*|quel medicament (pour|contre|prendre)|que prendre (pour|contre)|posologie pour moi|dose pour (mon|ma|un) (enfant|bebe|fils|fille)|est ce grave|je saigne|enceinte que faire)\b/';

    /** Réponse de recadrage si la question sort du domaine, sinon null. */
    public static function guard(string $n): ?array
    {
        // Une question qui parle clairement du commerce ou du logiciel reste dans le domaine
        if (preg_match('/\b(vente|ventes|vendre|client|clients|stock|caisse|article|articles|boutique|magasin|commerce|marketing|publicite|pos marwa|marwa|rapport|facture|prix|fournisseur|benefice|promotion|pub|configuration|parametres?|formation|quiz|lecon|eleves?|ecole|classe|patients?|chambres?|depots?|commande|livraison|achat|achats)\b/', $n)
            && !preg_match(self::HEALTH, $n)) {
            return null;
        }
        $label = Marketing::profile()[0];
        if (preg_match(self::HEALTH, $n)) {
            return self::reply('<p>Je ne donne pas d\'avis médical : pour un symptôme, un traitement ou une dose, seul un <b>médecin</b> ou un <b>pharmacien</b> peut répondre, après avoir vu la personne. En cas d\'urgence, rendez-vous au centre de santé le plus proche.</p>'
                . '<p>Je peux en revanche vous aider à gérer votre ' . e($label) . ' : stock, péremptions, ventes, rapports, marketing.</p>');
        }
        foreach (self::OFF as $re) {
            if (preg_match($re, $n)) {
                return self::reply('<p>Ce sujet sort de mon domaine. Je suis <b>MARWA IA</b>, l\'assistant de gestion de votre <b>' . e($label) . '</b> : je reste concentré sur votre activité pour vous aider à 100 %.</p>'
                    . '<p>Je peux par exemple préparer vos rapports, vous donner des idées pour vendre plus, vous montrer comment être visible sur les réseaux sociaux, écrire vos publications, vous former au logiciel ou vérifier votre configuration.</p>');
            }
        }
        return null;
    }

    private static function reply(string $html): array
    {
        return ['ok' => true, 'type' => 'scope', 'html' => $html, 'actions' => [],
            'suggestions' => ['Comment être visible sur les réseaux sociaux ?', 'Comment augmenter mes ventes ?', 'Mon plan marketing en PDF', 'Que sais-tu faire ?'], 'spec' => null];
    }
}
