<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validation stricte des entrées avec messages en français.
 * Renvoie uniquement les champs déclarés (liste blanche), convertis dans le bon type.
 *
 * Règles : required, nullable, string, int, numeric, bool, email, phone, password,
 * min:n, max:n, between:a,b, in:a,b,c, lat, lng, date, datetime, time, array, regex:/…/
 */
final class Validator
{
    public static function make(array $data, array $rules): array
    {
        $errors = [];
        $out = [];
        foreach ($rules as $field => $ruleString) {
            $rules_ = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if (in_array('required', $rules_, true)) {
                    $errors[$field] = 'Ce champ est obligatoire.';
                } elseif (in_array('nullable', $rules_, true) || array_key_exists($field, $data)) {
                    if (in_array('nullable', $rules_, true)) {
                        $out[$field] = null;
                    }
                }
                continue;
            }
            try {
                $out[$field] = self::apply($field, $value, $rules_);
            } catch (ValidationException $e) {
                $errors[$field] = $e->errors[$field] ?? $e->getMessage();
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    private static function fail(string $field, string $msg): never
    {
        throw new ValidationException([$field => $msg]);
    }

    private static function apply(string $f, mixed $v, array $rules): mixed
    {
        $isNumeric = false;
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            switch ($name) {
                case 'required':
                case 'nullable':
                    break;
                case 'string':
                    if (!is_string($v) && !is_int($v) && !is_float($v)) {
                        self::fail($f, 'Texte attendu.');
                    }
                    $v = (string) $v;
                    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) {
                        self::fail($f, 'Caractères non autorisés.');
                    }
                    break;
                case 'int':
                    if (is_string($v) && preg_match('/^-?\d+$/', $v)) {
                        $v = (int) $v;
                    }
                    if (!is_int($v)) {
                        self::fail($f, 'Nombre entier attendu.');
                    }
                    $isNumeric = true;
                    break;
                case 'numeric':
                    if (!is_numeric($v)) {
                        self::fail($f, 'Nombre attendu.');
                    }
                    $v = (float) $v;
                    $isNumeric = true;
                    break;
                case 'bool':
                    $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($b === null) {
                        self::fail($f, 'Valeur oui/non attendue.');
                    }
                    $v = $b;
                    break;
                case 'email':
                    $v = mb_strtolower((string) $v);
                    if (!filter_var($v, FILTER_VALIDATE_EMAIL) || strlen($v) > 190) {
                        self::fail($f, 'Adresse e-mail invalide.');
                    }
                    break;
                case 'phone':
                    $p = Str::normalizePhone((string) $v);
                    if ($p === null) {
                        self::fail($f, 'Numéro de téléphone invalide (ex. 90 12 34 56 ou +227 90 12 34 56).');
                    }
                    $v = $p;
                    break;
                case 'password':
                    $v = (string) $v;
                    if (mb_strlen($v) < 8 || !preg_match('/\pL/u', $v) || !preg_match('/\d/', $v)) {
                        self::fail($f, 'Le mot de passe doit contenir au moins 8 caractères, dont une lettre et un chiffre.');
                    }
                    if (strlen($v) > 200) {
                        self::fail($f, 'Mot de passe trop long.');
                    }
                    break;
                case 'min':
                    if ($isNumeric ? $v < (float) $arg : mb_strlen((string) $v) < (int) $arg) {
                        self::fail($f, $isNumeric ? "La valeur minimale est $arg." : "Au moins $arg caractères.");
                    }
                    break;
                case 'max':
                    if ($isNumeric ? $v > (float) $arg : (is_array($v) ? count($v) > (int) $arg : mb_strlen((string) $v) > (int) $arg)) {
                        self::fail($f, $isNumeric ? "La valeur maximale est $arg." : "Au plus $arg caractères ou éléments.");
                    }
                    break;
                case 'between':
                    [$a, $b] = array_map('floatval', explode(',', (string) $arg));
                    if (!is_numeric($v) || $v < $a || $v > $b) {
                        self::fail($f, "Valeur attendue entre $a et $b.");
                    }
                    break;
                case 'in':
                    $allowed = explode(',', (string) $arg);
                    if (!in_array((string) (is_bool($v) ? (int) $v : $v), $allowed, true)) {
                        self::fail($f, 'Valeur non autorisée.');
                    }
                    break;
                case 'lat':
                    if (!is_numeric($v) || abs((float) $v) > 90) {
                        self::fail($f, 'Latitude invalide.');
                    }
                    $v = round((float) $v, 6);
                    break;
                case 'lng':
                    if (!is_numeric($v) || abs((float) $v) > 180) {
                        self::fail($f, 'Longitude invalide.');
                    }
                    $v = round((float) $v, 6);
                    break;
                case 'date':
                    $v = self::parseDate((string) $v, false) ?? self::fail($f, 'Date invalide (JJ/MM/AAAA).');
                    break;
                case 'datetime':
                    $v = self::parseDate((string) $v, true) ?? self::fail($f, 'Date et heure invalides.');
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $v)) {
                        self::fail($f, 'Heure invalide (HH:MM).');
                    }
                    $v = strlen((string) $v) === 5 ? $v . ':00' : $v;
                    break;
                case 'array':
                    if (!is_array($v)) {
                        self::fail($f, 'Liste attendue.');
                    }
                    break;
                case 'regex':
                    if (!preg_match((string) $arg, (string) $v)) {
                        self::fail($f, 'Format invalide.');
                    }
                    break;
                default:
                    throw new \LogicException("Règle de validation inconnue : $name");
            }
        }
        return $v;
    }

    /** Accepte JJ/MM/AAAA, AAAA-MM-JJ et leurs variantes avec heure. Renvoie le format SQL. */
    public static function parseDate(string $v, bool $withTime): ?string
    {
        $formats = $withTime
            ? ['d/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s']
            : ['d/m/Y', 'Y-m-d'];
        foreach ($formats as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $v);
            if ($d && $d->format($fmt) === $v) {
                return $d->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
        }
        return null;
    }
}
