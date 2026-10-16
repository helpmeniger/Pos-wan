<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use App\Core\Settings;

/**
 * Traductions de l'interface (français = langue source).
 *
 * Dictionnaires : public/assets/i18n/<langue>.json — clé = texte français exact, valeur = traduction.
 * Les clés peuvent contenir des emplacements {1}, {2}… pour les textes variables
 * (ex. « Commande {1} acceptée »). Les corrections faites dans l'administration
 * (table translations) priment sur les fichiers. Le même moteur existe en JavaScript (i18n.js).
 */
final class I18n
{
    public const NAMES = [
        'fr' => 'Français', 'ha' => 'Hausa', 'dje' => 'Zarma', 'en' => 'English',
        'ff' => 'Fulfulde', 'kr' => 'Kanuri', 'tmh' => 'Tamajaq', 'ar' => 'العربية',
    ];

    private static array $dicts = [];
    private static string $current = 'fr';

    /** Langue de la requête : choix fait sur cet appareil (cookie « mw_lang »), sinon celle du compte, sinon français. */
    public static function detect(?array $user, array $cookies): string
    {
        $candidates = [$cookies['mw_lang'] ?? null, $user['locale'] ?? null];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && self::valid($c)) {
                return self::$current = $c;
            }
        }
        return self::$current = 'fr';
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function setCurrent(string $code): void
    {
        self::$current = self::valid($code) ? $code : 'fr';
    }

    /** Version du dictionnaire (fichier + corrections) pour le cache du navigateur. */
    public static function version(string $locale): string
    {
        $file = MARWA_ROOT . '/public/assets/i18n/' . $locale . '.json';
        $db = '';
        try {
            $db = (string) Database::value('SELECT CONCAT(COUNT(*), MAX(updated_at)) FROM translations WHERE locale = :l', ['l' => $locale]);
        } catch (\Throwable $e) {
            // base indisponible
        }
        return substr(md5((is_file($file) ? (string) filemtime($file) : '0') . $db), 0, 10);
    }

    /** Langues proposées aux utilisateurs (paramètre i18n.languages). */
    public static function languages(): array
    {
        $list = Settings::get('i18n.languages', ['fr', 'ha', 'dje', 'en']);
        $list = is_string($list) ? json_decode($list, true) : $list;
        $list = is_array($list) ? array_values(array_filter($list, fn ($c) => isset(self::NAMES[$c]))) : ['fr'];
        return in_array('fr', $list, true) ? $list : array_merge(['fr'], $list);
    }

    public static function valid(string $code): bool
    {
        return in_array($code, self::languages(), true);
    }

    /** Dictionnaire fusionné (fichier + corrections de l'administration). */
    public static function dictionary(string $locale): array
    {
        if ($locale === 'fr' || !isset(self::NAMES[$locale])) {
            return [];
        }
        if (isset(self::$dicts[$locale])) {
            return self::$dicts[$locale];
        }
        $file = MARWA_ROOT . '/public/assets/i18n/' . $locale . '.json';
        $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $d = is_array($d) ? $d : [];
        unset($d['_meta']);
        try {
            foreach (Database::select('SELECT source, target FROM translations WHERE locale = :l', ['l' => $locale]) as $row) {
                $d[$row['source']] = $row['target'];
            }
        } catch (\Throwable $e) {
            // base indisponible (installation) : fichier seul
        }
        return self::$dicts[$locale] = $d;
    }

    public static function forget(): void
    {
        self::$dicts = [];
        self::$compiled = [];
    }

    /** Traduit un texte français ; renvoie le texte d'origine si aucune traduction n'existe. */
    public static function translate(string $text, string $locale): string
    {
        if ($locale === 'fr' || $text === '') {
            return $text;
        }
        $dict = self::dictionary($locale);
        if (!$dict) {
            return $text;
        }
        $trim = trim($text);
        if (isset($dict[$trim]) && $dict[$trim] !== '') {
            return str_replace($trim, $dict[$trim], $text);
        }
        // Textes variables : les nombres sont remplacés par {1}, {2}…
        $nums = [];
        $shape = preg_replace_callback('/\d[\d\s\x{202F}.,:]*\d|\d/u', function ($m) use (&$nums) {
            $nums[] = $m[0];
            return '{' . count($nums) . '}';
        }, $trim);
        if ($nums && isset($dict[$shape]) && $dict[$shape] !== '') {
            return self::fill($dict[$shape], $nums);
        }
        // Emplacements libres (noms, adresses…)
        foreach (self::patterns($locale) as [$re, $target]) {
            if (preg_match($re, $trim, $m)) {
                return self::fill($target, array_slice($m, 1));
            }
        }
        return $text;
    }

    private static array $compiled = [];

    private static function patterns(string $locale): array
    {
        if (isset(self::$compiled[$locale])) {
            return self::$compiled[$locale];
        }
        $out = [];
        foreach (self::dictionary($locale) as $src => $dst) {
            if ($dst === '' || !str_contains((string) $src, '{1}')) {
                continue;
            }
            $re = preg_quote((string) $src, '/');
            $re = preg_replace('/\\\\\{(\d)\\\\\}/', '(.+?)', $re);
            $out[] = ['/^' . $re . '$/u', (string) $dst];
        }
        return self::$compiled[$locale] = $out;
    }

    private static function fill(string $target, array $values): string
    {
        return preg_replace_callback('/\{(\d)\}/', fn ($m) => $values[(int) $m[1] - 1] ?? $m[0], $target);
    }
}
