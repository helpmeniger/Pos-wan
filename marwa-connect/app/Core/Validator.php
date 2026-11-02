<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validation des saisies. Règles : required, email, min:n, max:n, numeric, integer,
 * in:a,b, confirmed, unique:table,colonne[,ignoreId], ip_or_host, port, alpha_dash, regex:/x/.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data, private array $rules, private array $labels = [])
    {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self($data, $rules, $labels);
        $v->run();
        return $v;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $label = $this->labels[$field] ?? str_replace('_', ' ', $field);
            $empty = $value === null || $value === '' || $value === [];

            if (!in_array('required', $rules, true) && $empty) {
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $param, $value, $field, $label, $empty);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
        }
    }

    private function check(string $name, ?string $param, mixed $value, string $field, string $label, bool $empty): ?string
    {
        $str = is_scalar($value) ? (string) $value : '';
        return match ($name) {
            'required' => $empty ? "Le champ « {$label} » est obligatoire." : null,
            'email' => filter_var($str, FILTER_VALIDATE_EMAIL) ? null : "« {$label} » doit être une adresse e-mail valide.",
            'min' => mb_strlen($str) < (int) $param ? "« {$label} » doit contenir au moins {$param} caractères." : null,
            'max' => mb_strlen($str) > (int) $param ? "« {$label} » ne doit pas dépasser {$param} caractères." : null,
            'numeric' => is_numeric($str) ? null : "« {$label} » doit être un nombre.",
            'integer' => filter_var($str, FILTER_VALIDATE_INT) !== false ? null : "« {$label} » doit être un nombre entier.",
            'in' => in_array($str, explode(',', (string) $param), true) ? null : "La valeur de « {$label} » est invalide.",
            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value ? null : "La confirmation de « {$label} » ne correspond pas.",
            'alpha_dash' => preg_match('/^[A-Za-z0-9_.-]+$/', $str) ? null : "« {$label} » ne peut contenir que lettres, chiffres, tirets, points et soulignés.",
            'regex' => preg_match((string) $param, $str) ? null : "Le format de « {$label} » est invalide.",
            'port' => (filter_var($str, FILTER_VALIDATE_INT) !== false && (int) $str >= 1 && (int) $str <= 65535) ? null : "« {$label} » doit être un port valide (1-65535).",
            'ip_or_host' => (filter_var($str, FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)([A-Za-z0-9-]{1,63}\.)*[A-Za-z0-9-]{1,63}$/', $str)) ? null : "« {$label} » doit être une adresse IP ou un nom d'hôte valide.",
            'unique' => $this->checkUnique($param, $str, $label),
            'exists' => $this->checkExists($param, $str, $label),
            default => throw new \InvalidArgumentException("Règle de validation inconnue : {$name}"),
        };
    }

    private function checkUnique(?string $param, string $value, string $label): ?string
    {
        [$table, $column, $ignore] = array_pad(explode(',', (string) $param), 3, null);
        $sql = 'SELECT COUNT(*) FROM ' . Database::ident($table) . ' WHERE ' . Database::ident($column) . ' = :v';
        $params = ['v' => $value];
        if ($ignore !== null && $ignore !== '') {
            $sql .= ' AND id <> :ignore';
            $params['ignore'] = (int) $ignore;
        }
        return (int) Database::value($sql, $params) > 0 ? "Cette valeur de « {$label} » est déjà utilisée." : null;
    }

    private function checkExists(?string $param, string $value, string $label): ?string
    {
        [$table, $column] = array_pad(explode(',', (string) $param), 2, 'id');
        $sql = 'SELECT COUNT(*) FROM ' . Database::ident($table) . ' WHERE ' . Database::ident($column) . ' = :v';
        return (int) Database::value($sql, ['v' => $value]) > 0 ? null : "La valeur de « {$label} » est introuvable.";
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        $out = [];
        // Chaque champ déclaré est présent (null s'il n'a pas été envoyé).
        foreach (array_keys($this->rules) as $field) {
            $value = $this->data[$field] ?? null;
            $out[$field] = is_string($value) ? trim($value) : $value;
        }
        return $out;
    }
}
