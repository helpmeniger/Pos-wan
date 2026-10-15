<?php
/**
 * Extraction des messages français des fichiers PHP (sans les commentaires).
 * Les concaténations « 'Texte ' . $x . ' suite' » deviennent « Texte {1} suite ».
 * Sortie JSON sur la sortie standard. Utilisé par tools/i18n/extract.py.
 */
$root = dirname(__DIR__, 2);
$dirs = ['app/Services', 'app/Controllers/Api', 'app/Controllers/PageController.php', 'app/Core', 'app/Middleware', 'app/Support'];
$out = [];
$files = [];
foreach ($dirs as $d) {
    $p = $root . '/' . $d;
    if (is_file($p)) { $files[] = $p; continue; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p)) as $f) {
        if (str_ends_with((string) $f, '.php')) { $files[] = (string) $f; }
    }
}
$str = static function (array $t): ?string {
    if ($t[0] !== T_CONSTANT_ENCAPSED_STRING) { return null; }
    $q = $t[1][0];
    $s = substr($t[1], 1, -1);
    return $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $s) : stripcslashes($s);
};
foreach ($files as $file) {
    $tokens = array_values(array_filter(token_get_all(file_get_contents($file)), fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_ENCAPSED_AND_WHITESPACE) { continue; }
        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
            // chaîne avec variables "Texte $x"
            if ($t === '"') {
                $parts = []; $j = $i + 1; $k = 0;
                while ($j < $n && $tokens[$j] !== '"') {
                    $tj = $tokens[$j];
                    if (is_array($tj) && $tj[0] === T_ENCAPSED_AND_WHITESPACE) { $parts[] = $tj[1]; }
                    elseif (is_array($tj) && $tj[0] === T_VARIABLE || $tj === '}') { if (!end($parts) || !str_ends_with((string) end($parts), '}')) { $parts[] = '{' . (++$k) . '}'; } }
                    $j++;
                }
                $out[] = implode('', $parts);
                $i = $j;
            }
            continue;
        }
        // chaîne de concaténations autour de la chaîne courante
        $start = $i;
        while ($start >= 2 && $tokens[$start - 1] === '.') { $start -= 2; }
        if ($start !== $i) { continue; } // déjà traité depuis le début de la chaîne
        $parts = []; $k = 0; $j = $i; $hasLiteral = false;
        while (true) {
            $tj = $tokens[$j];
            $s = is_array($tj) ? $str($tj) : null;
            if ($s !== null) { $parts[] = $s; $hasLiteral = true; }
            else { $parts[] = '{' . (++$k) . '}'; }
            // avance jusqu'au prochain '.' de même niveau
            $depth = 0; $j++;
            while ($j < $n) {
                $x = $tokens[$j];
                if (in_array($x, ['(', '['], true)) { $depth++; }
                elseif (in_array($x, [')', ']'], true)) { if ($depth === 0) { break; } $depth--; }
                elseif ($depth === 0 && in_array($x, [',', ';', '?', ':'], true)) { break; }
                elseif ($depth === 0 && $x === '.') { break; }
                elseif ($depth === 0 && is_array($x) && in_array($x[0], [T_DOUBLE_ARROW], true)) { break; }
                if ($depth === 0 && $s !== null) { break; }
                $j++;
            }
            if ($j < $n && $tokens[$j] === '.') { $j++; continue; }
            break;
        }
        if ($hasLiteral) { $out[] = implode('', $parts); }
        if (count($parts) > 1) { $out[] = $str($t); }
    }
}
$out = array_values(array_unique(array_filter($out, fn ($s) => is_string($s) && preg_match('/\p{L}/u', $s) && str_contains($s, ' ')
    && !preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|JOIN|CREATE|ALTER)\b|[<>]|\\\\|^\s*\{|%[sd]|::|->|\$/', $s))));
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
