<?php
declare(strict_types=1);

namespace App\Support;

/** Normalisation des noms pour la recherche et la détection de doublons. */
final class Normalizer
{
    public static function name(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            $value = $t ? (string) $t->transliterate($value) : mb_strtolower($value);
        } else {
            $value = mb_strtolower($value);
            $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            $value = $conv !== false ? $conv : $value;
        }
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Clé phonétique simplifiée, adaptée aux graphies francophones et aux
     * transcriptions courantes (ou/u, ph/f, doublement de consonnes…).
     * Exemples : Moussa / Musa → « musa », Oumarou / Umaru → « umaru ».
     */
    public static function phonetic(?string $value): string
    {
        $s = str_replace(' ', '', self::name($value));
        if ($s === '') {
            return '';
        }
        $rules = [
            '/ph/' => 'f', '/qu/' => 'k', '/q/' => 'k', '/ck/' => 'k',
            '/c(?=[eiy])/' => 's', '/c/' => 'k', '/gu(?=[ei])/' => 'g', '/g(?=[eiy])/' => 'j',
            '/x/' => 'ks', '/w/' => 'v', '/y/' => 'i', '/z/' => 's', '/h/' => '',
            '/eau/' => 'o', '/au/' => 'o', '/ou/' => 'u', '/oo/' => 'u', '/ee/' => 'i',
            '/[ae]i/' => 'e', '/dj/' => 'j',
        ];
        $s = preg_replace(array_keys($rules), array_values($rules), $s) ?? $s;
        $s = preg_replace('/(.)\1+/', '$1', $s) ?? $s;
        if (strlen($s) > 3) {
            $s = preg_replace('/e$/', '', $s) ?? $s;
        }
        return substr($s, 0, 40);
    }

    public static function adminId(?string $value): ?string
    {
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');
        return $v === '' ? null : $v;
    }
}
