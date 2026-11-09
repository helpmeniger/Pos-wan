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
            self::$patterns = [];
        }
    }

    public static function reset(): void
    {
        self::$locale = null;
        self::$dict = [];
        self::$patterns = [];
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
                $tr = self::lookup(trim($raw), $d);
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
        if (($tr = self::lookup($trim, $d)) !== null) {
            return htmlspecialchars($lead . $tr . $tail, ENT_NOQUOTES, 'UTF-8');
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

    /** Modèles du dictionnaire contenant des valeurs variables : « {n} vente(s) » → motif. */
    private static array $patterns = [];

    /**
     * Traduction d'un texte : exacte, puis espaces multiples ramenés à un seul, puis modèle
     * avec valeurs variables (« 16 vente(s) » trouve « {n} vente(s) » => « {n} sale(s) »).
     */
    private static function lookup(string $text, array $d): ?string
    {
        if (isset($d[$text])) {
            return $d[$text];
        }
        $norm = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if ($norm !== $text && isset($d[$norm])) {
            return $d[$norm];
        }
        $key = self::$locale ?? '';
        if (!isset(self::$patterns[$key])) {
            self::$patterns[$key] = [];
            foreach ($d as $src => $dst) {
                if (!str_contains((string) $src, '{')) {
                    continue;
                }
                $names = [];
                $parts = preg_split('/\{(\w+)\}/u', (string) $src, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                $rx = '';
                foreach ($parts as $i => $part) {
                    if ($i % 2) {
                        $names[] = $part;
                        // Valeurs typées : un nombre ne peut pas « avaler » un mot
                        $g = match (rtrim($part, '0123456789')) {
                            'n', 'm', 'c' => '(\d[\d\s.,\x{202F}\x{00A0}]*)',
                            'ref' => '([A-Z]{1,4}\d{4}-\d{3,})',
                            'date' => '(\d{2}/\d{2}/\d{4}(?: \d{2}:\d{2})?)',
                            'money' => '(-?\d[\d\s.,\x{202F}\x{00A0}]*\s?[^\s\d]{0,4})',
                            'txt' => '(.+?)',
                            default => null, // nom inconnu ({x}…) : clé réservée au JavaScript, pas de motif
                        };
                        if ($g === null) {
                            $rx = null;
                            break;
                        }
                        $rx .= $g;
                    } else {
                        $rx .= preg_quote($part, '#');
                    }
                }
                if ($names && $rx !== null) {
                    self::$patterns[$key][] = ['#^' . $rx . '$#u', $names, (string) $dst];
                }
            }
        }
        foreach (self::$patterns[$key] as [$rx, $names, $dst]) {
            if (preg_match($rx, $norm, $m)) {
                $out = $dst;
                foreach ($names as $i => $n) {
                    $out = str_replace('{' . $n . '}', $m[$i + 1], $out);
                }
                return $out;
            }
        }
        return null;
    }

    /** Dictionnaire pour le JavaScript (uniquement si la langue n'est pas le français). */
    public static function jsonForJs(): string
    {
        return json_encode((object) self::dictionary(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
    }
}
