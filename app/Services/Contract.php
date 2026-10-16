<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Contrat de licence d'utilisation de POS Marwa (texte unique : page « À propos → Contrat »
 * et fichier LICENCE.md régénéré par bin/release.php).
 * Modèle à faire relire par un conseil juridique avant usage commercial.
 */
final class Contract
{
    public const VERSION = '1.0 — octobre 2026';

    public static function clauses(): array
    {
        $a = config('licence.author');
        $tel = implode(' / ', $a['phones']);
        return [
            ['Article 1 — Parties et objet', [
                'Le présent contrat est conclu entre ' . $a['name'] . ', ' . strtolower($a['title']) . ', ' . $a['country'] . ', auteur et titulaire de tous les droits sur le logiciel POS Marwa (ci-après « l\'Auteur »), et la personne physique ou morale qui installe ou utilise le logiciel (ci-après « le Client »).',
                'Il définit les conditions dans lesquelles le Client est autorisé à utiliser POS Marwa (caisse, gestion commerciale, stocks, modules complémentaires) et ses mises à jour.',
            ]],
            ['Article 2 — Propriété intellectuelle', [
                'POS Marwa, son code source, sa base de données, ses écrans, ses modules, sa documentation et son nom sont la propriété exclusive de l\'Auteur et sont protégés par le droit d\'auteur (Accord de Bangui révisé, Organisation africaine de la propriété intellectuelle — OAPI, et lois de la République du Niger).',
                'Le contrat ne transfère aucun droit de propriété au Client : il lui accorde seulement un droit d\'utilisation.',
            ]],
            ['Article 3 — Licence accordée', [
                'L\'Auteur accorde au Client une licence non exclusive, non cessible et non transférable, pour une seule installation identifiée par son « identifiant d\'installation » et activée par une clé de licence délivrée par l\'Auteur.',
                'La licence couvre le nombre de postes, boutiques et modules convenus lors de l\'achat. Toute installation supplémentaire nécessite une licence supplémentaire.',
                'Une période d\'essai de ' . (int) config('licence.trial_days', 30) . ' jours est accordée à compter de la première utilisation. À son terme, sans clé valide, le logiciel passe en lecture seule : les données restent consultables et sauvegardables, aucune donnée n\'est effacée.',
            ]],
            ['Article 4 — Interdictions', [
                'Sauf accord écrit de l\'Auteur, il est interdit : de copier, installer ou utiliser le logiciel au-delà de la licence acquise ; de le revendre, louer, prêter, céder ou distribuer, même gratuitement ; de le modifier, traduire, adapter, décompiler ou d\'en extraire tout ou partie du code ; de supprimer ou modifier les mentions de l\'Auteur, de copyright ou de licence ; de contourner, désactiver ou falsifier la clé de licence ou tout dispositif de protection ; de générer ou diffuser de fausses clés.',
                'Toute utilisation non autorisée constitue une contrefaçon pouvant donner lieu à des poursuites civiles et pénales, sans préjudice de dommages et intérêts.',
            ]],
            ['Article 5 — Activation et protections', [
                'Le logiciel contient un dispositif d\'activation (clé signée liée à l\'installation), la mention de l\'Auteur et un journal d\'audit. Le Client s\'engage à ne pas les altérer.',
                'Le changement de serveur ou d\'ordinateur est accepté sur demande à l\'Auteur, qui délivre une nouvelle clé pour la nouvelle installation, l\'ancienne cessant d\'être utilisée.',
            ]],
            ['Article 6 — Mises à jour et assistance', [
                'Les mises à jour correctives sont fournies pendant la durée convenue lors de l\'achat. Les nouvelles fonctionnalités et modules peuvent faire l\'objet d\'une tarification distincte.',
                'Assistance : ' . $tel . '. Les interventions sur place, formations et développements spécifiques sont facturés selon devis.',
            ]],
            ['Article 7 — Données du Client', [
                'Les données saisies (ventes, clients, stocks, patients…) appartiennent au Client. Elles sont stockées sur son propre serveur ; l\'Auteur n\'y a pas accès sauf intervention demandée par le Client, et s\'engage alors à la confidentialité.',
                'Le Client est responsable de ses sauvegardes, de la sécurité de son matériel, de ses mots de passe, ainsi que du respect des lois applicables à son activité (fiscalité, données personnelles, données de santé).',
            ]],
            ['Article 8 — Garantie et responsabilité', [
                'Le logiciel est fourni conforme à sa documentation. L\'Auteur corrige les anomalies reproductibles qui lui sont signalées. Aucun logiciel n\'étant exempt d\'erreur ni inviolable, l\'Auteur ne garantit pas un fonctionnement ininterrompu.',
                'La responsabilité de l\'Auteur est limitée au montant payé pour la licence au cours des douze derniers mois. Elle ne couvre pas les pertes indirectes (manque à gagner, perte de données faute de sauvegarde, mauvaise utilisation, matériel défectueux, modification du logiciel par un tiers).',
            ]],
            ['Article 9 — Durée et résiliation', [
                'La licence prend effet à l\'activation, pour la durée indiquée sur la clé (ou sans limite de durée si aucune date de fin n\'y figure).',
                'En cas de manquement grave du Client (notamment aux articles 4 et 5), l\'Auteur peut résilier la licence après mise en demeure restée sans effet pendant quinze jours. Le Client conserve alors l\'accès en lecture à ses données pour les exporter.',
            ]],
            ['Article 10 — Droit applicable et litiges', [
                'Le présent contrat est régi par le droit de la République du Niger. Les parties recherchent d\'abord une solution amiable ; à défaut, les tribunaux compétents de Niamey sont seuls compétents.',
                'L\'installation, l\'activation ou l\'utilisation du logiciel vaut acceptation du présent contrat.',
            ]],
        ];
    }

    /** Version texte (Markdown) pour LICENCE.md. */
    public static function markdown(): string
    {
        $a = config('licence.author');
        $md = "# Contrat de licence d'utilisation — POS Marwa\n\nVersion du contrat : " . self::VERSION . "\n\n"
            . "© " . date('Y') . ' ' . $a['name'] . ' — ' . $a['title'] . ' — ' . $a['country'] . ' — ' . implode(' / ', $a['phones']) . "\n\n"
            . "> Logiciel propriétaire. Toute copie, revente ou utilisation sans licence est interdite.\n\n";
        foreach (self::clauses() as [$title, $paras]) {
            $md .= '## ' . $title . "\n\n" . implode("\n\n", $paras) . "\n\n";
        }
        return $md;
    }
}
