<?php
declare(strict_types=1);

namespace Addons\MarwaIa;

/**
 * Citations et proverbes africains, rangés par thème pour accompagner les conseils de gestion.
 * Les attributions restent prudentes : « proverbe africain » quand l'origine exacte varie
 * selon les sources.
 */
final class Wisdom
{
    /** [texte, origine, thèmes] */
    public const QUOTES = [
        ['Si tu veux aller vite, marche seul ; si tu veux aller loin, marchons ensemble.', 'Proverbe africain', ['equipe', 'personnel', 'clients']],
        ['Quand les toiles d\'araignée s\'unissent, elles peuvent attacher un lion.', 'Proverbe éthiopien', ['equipe', 'personnel']],
        ['Sannu sannu, bā ta hana zuwa — Doucement, doucement n\'empêche pas d\'arriver.', 'Proverbe haoussa', ['patience', 'croissance', 'formation']],
        ['En Afrique, quand un vieillard meurt, c\'est une bibliothèque qui brûle.', 'Amadou Hampâté Bâ', ['formation', 'savoir']],
        ['Cela semble toujours impossible, jusqu\'à ce qu\'on le fasse.', 'Nelson Mandela', ['croissance', 'courage', 'ventes']],
        ['Celui qui pose des questions ne s\'égare pas.', 'Proverbe africain', ['formation', 'savoir']],
        ['Ne regarde pas où tu es tombé, mais où tu as glissé.', 'Proverbe africain', ['pertes', 'caisse', 'erreurs', 'stock']],
        ['Le bâton dans l\'eau ne se transforme pas en crocodile.', 'Proverbe africain', ['prudence', 'credit', 'stock']],
        ['Ce que le vieillard voit assis, le jeune ne le voit pas debout.', 'Proverbe africain', ['savoir', 'conseil']],
        ['Lorsque les racines sont profondes, il n\'y a pas de raison de craindre le vent.', 'Proverbe africain', ['tresorerie', 'epargne', 'croissance', 'ventes']],
        ['Qui veut du miel doit avoir le courage d\'affronter les abeilles.', 'Proverbe africain', ['courage', 'ventes', 'croissance']],
        ['C\'est au bout de l\'ancienne corde qu\'on tisse la nouvelle.', 'Proverbe africain', ['clients', 'fidelite', 'savoir']],
        ['Le savoir est comme un baobab : une seule personne ne peut pas l\'embrasser.', 'Proverbe africain', ['formation', 'equipe']],
        ['La main qui donne est au-dessus de celle qui reçoit.', 'Proverbe africain', ['clients', 'fidelite']],
        ['Le mensonge donne des fleurs, mais jamais de fruits.', 'Proverbe africain', ['honnetete', 'caisse', 'personnel']],
        ['L\'eau chaude n\'oublie pas qu\'elle a été froide.', 'Proverbe africain', ['humilite', 'croissance']],
        ['Quand tu ne sais pas où tu vas, regarde d\'où tu viens.', 'Proverbe africain', ['rapports', 'savoir', 'conseil']],
        ['Un seul doigt ne peut pas ramasser un caillou.', 'Proverbe africain', ['equipe', 'personnel']],
        ['Le fleuve fait des détours parce que personne ne lui montre le chemin.', 'Proverbe africain', ['formation', 'conseil']],
    ];

    /** Citation du jour (la même toute la journée, différente chaque jour). */
    public static function ofDay(): array
    {
        return self::QUOTES[(int) date('z') % count(self::QUOTES)];
    }

    /** Une citation adaptée à un thème (choix stable dans la journée). */
    public static function forTheme(string $theme): array
    {
        $list = array_values(array_filter(self::QUOTES, fn($q) => in_array($theme, $q[2], true)));
        if (!$list) {
            return self::ofDay();
        }
        return $list[((int) date('z') + strlen($theme)) % count($list)];
    }

    public static function html(array $q): string
    {
        return '<blockquote class="mia-proverbe"><span>« ' . e($q[0]) . ' »</span><cite>' . e($q[1]) . '</cite></blockquote>';
    }
}
