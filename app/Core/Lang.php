<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Traduction de l'interface.
 * Le français est la langue source : les dictionnaires lang/<code>.php associent
 * chaque texte français à sa traduction. Deux mécanismes :
 *  - t('Texte {x}', ['x' => …]) pour les textes construits dans le code ;
 *  - Lang::html() traduit, à l'affichage, les textes fixes des pages (nœuds texte et
 *    attributs placeholder/title/aria-label/data-confirm) qui correspondent exactement
 *    à une entrée du dictionnaire. Les données saisies (noms d'articles…) ne sont
 *    traduites que si elles sont identiques à un libellé de l'interface.
 * Un texte absent du dictionnaire reste en français.
 */
final class Lang
{
    public const LANGUAGES = [
        'fr' => ['Français', 'ltr'],
        'en' => ['English', 'ltr'],
        'ar' => ['العربية', 'rtl'],
        'ha' => ['Hausa', 'ltr'],
        'es' => ['Español', 'ltr'],
        'pt' => ['Português', 'ltr'],
        'tr' => ['Türkçe', 'ltr'],
    ];

    private static ?string $locale = null;
    private static array $dict = [];

    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }
        // Ordre : langue choisie pendant la session (écran de connexion ou « Mon compte »),
        // puis préférence enregistrée de l'utilisateur, puis langue par défaut du logiciel.
        $candidates = [$_SESSION['locale'] ?? null];
        try {
            $u = Auth::check() ? Auth::user() : null;
            $candidates[] = $u['locale'] ?? null;
        } catch (\Throwable) {
            // base indisponible (installation)
        }
        $candidates[] = setting('default_locale', 'fr');
        foreach ($candidates as $c) {
            if (is_string($c) && isset(self::LANGUAGES[$c])) {
                return self::$locale = $c;
            }
        }
        return self::$locale = 'fr';
    }

    public static function set(string $code): void
    {
        if (isset(self::LANGUAGES[$code])) {
            self::$locale = $code;
            self::$dict = [];
        }
    }

    public static function reset(): void
    {
        self::$locale = null;
        self::$dict = [];
    }

    public static function dir(): string
    {
        return self::LANGUAGES[self::locale()][1];
    }

    public static function dictionary(?string $code = null): array
    {
        $code ??= self::locale();
        if ($code === 'fr') {
            return [];
        }
        if (!isset(self::$dict[$code])) {
            $file = BASE_PATH . '/lang/' . $code . '.php';
            $d = is_file($file) ? require $file : [];
            self::$dict[$code] = (is_array($d) ? $d : []) + Addons::dictionary($code);
        }
        return self::$dict[$code];
    }

    public static function t(string $text, array $params = []): string
    {
        $d = self::dictionary();
        $out = $d[$text] ?? $text;
        foreach ($params as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }

    /** Traduit les textes fixes d'une page HTML déjà générée. */
    public static function html(string $html): string
    {
        $d = self::dictionary();
        if (!$d) {
            return $html;
        }
        // Les blocs script/style/textarea ne sont jamais modifiés
        $parts = preg_split('#(<(script|style|textarea)\b.*?</\2>)#si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        for ($i = 0; $i < count($parts); $i++) {
            $p = $parts[$i];
            if (preg_match('#^<(script|style|textarea)\b#i', $p)) {
                $out .= $p;
                $i++; // saute le groupe capturé (nom de balise)
                continue;
            }
            $p = preg_replace_callback('/>([^<>]+)</u', static function ($m) use ($d) {
                return '>' . self::translateNode($m[1], $d) . '<';
            }, $p);
            $p = preg_replace_callback('/\b(placeholder|title|aria-label|data-confirm|alt)="([^"]*)"/u', static function ($m) use ($d) {
                $raw = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $tr = $d[trim($raw)] ?? null;
                return $tr === null ? $m[0] : $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . '"';
            }, $p);
            $out .= $p;
        }
        return $out;
    }

    private static function translateNode(string $node, array $d): string
    {
        $raw = html_entity_decode($node, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $trim = trim($raw);
        if ($trim === '' || !preg_match('/\p{L}/u', $trim)) {
            return $node;
        }
        $lead = substr($raw, 0, strlen($raw) - strlen(ltrim($raw)));
        $tail = substr($raw, strlen(rtrim($raw)));
        if (isset($d[$trim])) {
            return htmlspecialchars($lead . $d[$trim] . $tail, ENT_NOQUOTES, 'UTF-8');
        }
        // « Libellé : » → traduit « Libellé » et garde la ponctuation finale
        if (preg_match('/^(.*?)(\s*[:：]|\s*…|\s*\*)$/u', $trim, $m) && isset($d[$m[1]])) {
            return htmlspecialchars($lead . $d[$m[1]] . $m[2] . $tail, ENT_NOQUOTES, 'UTF-8');
        }
        // « Libellé : valeur » dans un même nœud → traduit le libellé, garde la valeur
        if (preg_match('/^(.+?)(\s*:\s+)(.+)$/u', $trim, $m) && isset($d[$m[1]])) {
            return htmlspecialchars($lead . $d[$m[1]] . $m[2] . $m[3] . $tail, ENT_NOQUOTES, 'UTF-8');
        }
        return $node;
    }

    /** Dictionnaire pour le JavaScript (uniquement si la langue n'est pas le français). */
    public static function jsonForJs(): string
    {
        return json_encode((object) self::dictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
    }
}
