<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validation stricte des entrées. Règles : required, string, int, numeric, min:n, max:n,
 * in:a,b,c, email, regex:/.../, bool, date, datetime, ip.
 */
final class Validator
{
    public array $errors = [];
    public array $data = [];

    public static function make(array $input, array $rules): self
    {
        $v = new self();
        foreach ($rules as $field => $ruleStr) {
            $rules = is_array($ruleStr) ? $ruleStr : explode('|', $ruleStr);
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $isEmpty = $value === null || $value === '' || $value === [];
            if ($isEmpty) {
                if (in_array('required', $rules, true)) {
                    $v->errors[$field] = 'Ce champ est obligatoire.';
                } elseif (in_array('bool', $rules, true)) {
                    $v->data[$field] = 0;
                } else {
                    $v->data[$field] = null;
                }
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = $v->check($name, $arg, $value, $rules);
                if ($err !== null) {
                    $v->errors[$field] = $err;
                    continue 2;
                }
            }
            $v->data[$field] = match (true) {
                in_array('int', $rules, true) => (int) $value,
                in_array('numeric', $rules, true) => (float) $value,
                in_array('bool', $rules, true) => in_array($value, ['1', 1, true, 'on', 'true'], true) ? 1 : 0,
                default => $value,
            };
        }
        return $v;
    }

    private function check(string $name, ?string $arg, mixed $value, array $rules): ?string
    {
        $numeric = in_array('int', $rules, true) || in_array('numeric', $rules, true);
        return match ($name) {
            'required', 'bool' => null,
            'string' => is_string($value) ? null : 'Texte attendu.',
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'Nombre entier attendu.',
            'numeric' => is_numeric(str_replace(',', '.', (string) $value)) ? null : 'Nombre attendu.',
            'min' => $numeric ? ((float) $value >= (float) $arg ? null : "Valeur minimale : {$arg}.")
                              : (mb_strlen((string) $value) >= (int) $arg ? null : "Au moins {$arg} caractères."),
            'max' => $numeric ? ((float) $value <= (float) $arg ? null : "Valeur maximale : {$arg}.")
                              : (mb_strlen((string) $value) <= (int) $arg ? null : "Au plus {$arg} caractères."),
            'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : 'Valeur non autorisée.',
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'Adresse e-mail invalide.',
            'regex' => preg_match((string) $arg, (string) $value) ? null : 'Format invalide.',
            'date' => \DateTime::createFromFormat('Y-m-d', (string) $value) ? null : 'Date invalide (AAAA-MM-JJ).',
            'datetime' => strtotime((string) $value) !== false ? null : 'Date/heure invalide.',
            'ip' => filter_var($value, FILTER_VALIDATE_IP) ? null : 'Adresse IP invalide.',
            default => null,
        };
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function firstError(): string
    {
        return (string) reset($this->errors);
    }
}
