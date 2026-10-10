<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validation côté serveur. Règles : required, string, max:N, min:N, numeric,
 * int, email, date, in:a,b, phone, regex:/.../, nullable.
 */
final class Validator
{
    public array $errors = [];
    public array $data = [];

    public function __construct(private array $input, private array $rules, private array $labels = [])
    {
    }

    public static function make(array $input, array $rules, array $labels = []): self
    {
        $v = new self($input, $rules, $labels);
        $v->run();
        return $v;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleStr) {
            $rules = is_array($ruleStr) ? $ruleStr : explode('|', $ruleStr);
            $raw = $this->input[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $label = $this->labels[$field] ?? $field;
            $nullable = in_array('nullable', $rules, true);
            if ($value === null || $value === '') {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = "Le champ « $label » est obligatoire.";
                } else {
                    $this->data[$field] = in_array('bool', $rules, true) ? 0 : null;
                }
                continue;
            }
            if (is_array($value)) {
                $this->errors[$field] = "Le champ « $label » est invalide.";
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = match ($name) {
                    'numeric' => is_numeric(str_replace([' ', ','], ['', '.'], (string) $value)) ? null : "« $label » doit être un nombre.",
                    'int' => preg_match('/^-?\d+$/', (string) $value) ? null : "« $label » doit être un nombre entier.",
                    'min' => (is_numeric(str_replace(',', '.', (string) $value)) ? (float) str_replace(',', '.', (string) $value) < (float) $arg : mb_strlen((string) $value) < (int) $arg) ? "« $label » doit être au minimum $arg." : null,
                    'max' => (in_array('numeric', $rules, true) ? (float) str_replace(',', '.', (string) $value) > (float) $arg : mb_strlen((string) $value) > (int) $arg) ? "« $label » dépasse la limite autorisée ($arg)." : null,
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "« $label » n'est pas une adresse e-mail valide.",
                    'date' => self::isDate((string) $value) ? null : "« $label » n'est pas une date valide.",
                    'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : "« $label » contient une valeur non autorisée.",
                    'phone' => preg_match('/^[+0-9 ()\-.]{6,30}$/', (string) $value) ? null : "« $label » n'est pas un numéro valide.",
                    'regex' => preg_match((string) $arg, (string) $value) ? null : "« $label » a un format invalide.",
                    'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) ? null : "« $label » doit être une couleur (#RRGGBB).",
                    default => null,
                };
                if ($err) {
                    $this->errors[$field] = $err;
                    break;
                }
            }
            if (!isset($this->errors[$field])) {
                if (in_array('numeric', $rules, true)) {
                    $value = (float) str_replace([' ', ','], ['', '.'], (string) $value);
                } elseif (in_array('int', $rules, true) || in_array('bool', $rules, true)) {
                    $value = (int) $value;
                }
                $this->data[$field] = $value;
            }
        }
        unset($nullable);
    }

    public static function isDate(string $v): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
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
