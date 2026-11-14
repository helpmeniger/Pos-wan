<?php
declare(strict_types=1);

namespace Addons\MarwaIa\Brain;

/**
 * Garde-fou contre les tentatives de manipulation de l'assistant.
 *
 * MARWA AI n'exécute jamais de SQL venu d'une question, n'a pas de privilèges propres et ne lit aucun secret : ces
 * demandes ne pourraient donc pas aboutir. Le garde-fou sert à y répondre clairement (et à les journaliser) au lieu de
 * les traiter comme une question ordinaire. Il ne remplace aucun contrôle de droits : ceux-ci restent faits côté serveur.
 */
final class Guard
{
    /** Motif (sur le texte normalisé) → raison donnée à l'utilisateur. */
    private const RULES = [
        '/\b(ignore|oublie|contourne|desactive|outrepasse)\w*\b.{0,30}\b(instructions?|regles?|consignes?|restrictions?|limites?|securite|permissions?|droits|controles?)\b/' => 'Je ne peux pas mettre de côté mes règles ni les contrôles de droits : ils protègent vos données.',
        '/\b(mode (developpeur|admin|root|debug|sans limite)|jailbreak|tu es maintenant|fais comme si (j etais|tu etais)|agis comme (si|un)|prompt systeme|tes instructions (systeme|secretes))\b/' => 'Je garde le même fonctionnement pour tout le monde : je réponds avec les droits du compte connecté, sans « mode » particulier.',
        '/\b(mots? de passe|password|code pin|hash|cle (de licence|d api|secrete)|fichier env|\.env|app key|token)\b.{0,40}\b(de |du |des |d )?(l )?(admin\w*|gerant|patron|autres?|utilisateurs?|tout le monde|caissier\w*|systeme|base|serveur|licence|api)?\b/' => null,
        '/\b(drop|truncate|delete from|update \w+ set|insert into|alter table|grant all|union select|select \* from)\b/' => 'Je n\'exécute aucune requête SQL transmise dans une question. Je consulte les données par des outils en lecture seule, et les modifications passent par les écrans du logiciel.',
        '/\b(supprime|efface|vide|purge)\w*\b.{0,25}\b(toutes? les? (ventes?|donnees|tickets?|factures?|clients?|stocks?)|la base|l historique|le journal|les traces?|l audit)\b/' => 'Je ne supprime pas de données : les ventes, le journal d\'audit et l\'historique ne s\'effacent pas, c\'est ce qui rend vos chiffres fiables.',
        '/\b(sans (laisse\w* (de )?)?traces?|discretement|en cachette|cache\w*\b.{0,20}\b(au|du|a) (patron|gerant|proprietaire|chef)|que personne ne (voie|sache)|maquille\w*|falsifie\w*|trafique\w*)\b/' => 'Je ne peux pas faire une opération cachée : chaque opération est enregistrée avec son auteur, pour protéger tout le monde.',
        '/^(stp |svp |tu peux |peux tu |il faut |merci d )?(efface|supprime|annule|enleve|retire|remets? a zero|oublie)\w*\b.{0,20}\b(la |sa |les |cette |une )?(dettes?|credits?|encours|ardoise)\b/' => 'Je n\'efface pas une dette : elle se solde par un règlement enregistré (Clients & crédits → fiche du client → Encaisser), qui reste dans l\'historique.',
        '/\b(cache\w*|dissimule\w*|masque\w*)\b.{0,40}\b(fisc|impots?|patron|gerant|proprietaire|chef|controleur|comptable|inspecteur)\b|\b(cache\w*|dissimule\w*)\b.{0,12}\b(une partie|des ventes|du benefice|des recettes|de l argent)\b/' => 'Je ne peux pas aider à cacher des ventes, des recettes ou un bénéfice : chaque opération reste enregistrée et visible des personnes autorisées.',
        '/\b(donne|accorde|mets|ajoute)\w*\b.{0,15}\b(moi )?(tous les droits|les droits (d )?admin\w*|le role (admin\w*|proprietaire)|acces (total|complet|admin\w*))\b/' => 'Je ne peux pas modifier les droits d\'un compte. Un administrateur le fait dans Configuration → Utilisateurs.',
        '/\b(connecte|logue)\w*\b.{0,15}\b(moi )?(en|comme|en tant qu)\s*(admin\w*|proprietaire|gerant|un autre)\b/' => 'Je ne peux pas vous connecter sous un autre compte : chacun travaille avec son identifiant.',
        '/\b(change|modifie|corrige|baisse|augmente)\w*\b.{0,20}\b(le )?(total|montant)\b.{0,20}\b(de la |d une |des )?(vente|ticket|facture)\b/' => 'Une vente enregistrée ne se modifie pas. En cas d\'erreur, on l\'annule ou on fait un retour : les deux opérations restent visibles.',
    ];

    /** Message de refus si la question est une tentative de manipulation, sinon null. */
    public static function check(string $n): ?string
    {
        foreach (self::RULES as $re => $msg) {
            if (!preg_match($re, $n, $m)) {
                continue;
            }
            if ($msg !== null) {
                return $msg;
            }
            // Secrets : « changer MON mot de passe » est une question d'aide, pas une tentative
            if (preg_match('/\b(mon|ma|mes)\s+(mot de passe|code pin|password)\b|\b(changer|modifier|oublie|reinitialis\w*|perdu)\b.{0,25}\b(mot de passe|code pin)\b|\bmot de passe\b.{0,15}\b(oublie|perdu)\b/', $n)
                && !preg_match('/\b(donne|montre|affiche|dis|revele|envoie)\w*\b.{0,25}\b(mots? de passe|code pin|password)\b/', $n)) {
                continue;
            }
            if (preg_match('/\b(donne|montre|affiche|dis|revele|envoie|quel est|c est quoi|recupere|lis)\w*\b/', $n)) {
                return 'Je n\'ai accès à aucun mot de passe, code PIN ni clé : ils sont chiffrés et ne sont jamais affichés, même à un administrateur. Pour un mot de passe oublié, un administrateur peut le réinitialiser dans Configuration → Utilisateurs.';
            }
        }
        return null;
    }
}
