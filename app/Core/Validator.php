<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\Phone;

/**
 * Validation côté serveur. Règles séparées par « | » :
 *   required, nullable, string, integer, numeric, email, phone, min:n, max:n, between:a,b,
 *   in:a,b,c, confirmed, accepted, date, password, slug, exists:table,col, unique:table,col[,ignoreId]
 * min/max s'appliquent à la longueur pour les chaînes, à la valeur pour integer/numeric.
 */
final class Validator
{
    /** Tables autorisées pour exists/unique (pas d'identifiant SQL venant de l'extérieur). */
    private const SQL_WHITELIST = [
        'users' => ['phone', 'email', 'id', 'uuid'],
        'cities' => ['id', 'slug'],
        'neighborhoods' => ['id'],
        'job_categories' => ['id', 'slug'],
        'job_subcategories' => ['id', 'slug'],
        'businesses' => ['id', 'name'],
    ];

    /** @var array<string, list<string>> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels libellés lisibles des champs
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = [],
        private readonly ?Database $db = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $db = Container::getInstance()->has(Database::class) ? Container::getInstance()->get(Database::class) : null;
        $v = new self($data, $rules, $labels, $db);
        if (!$v->passes()) {
            throw new ValidationException($v->errors(), $data);
        }
        return $v->validated();
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->validated = [];
        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $isEmpty = $value === null || $value === '' || $value === [];
            if ($isEmpty) {
                if (in_array('required', $rules, true)) {
                    $this->addError($field, 'Le champ :field est obligatoire.');
                } elseif (in_array('accepted', $rules, true)) {
                    $this->addError($field, 'Vous devez accepter :field.');
                } else {
                    $this->validated[$field] = null;
                }
                continue;
            }
            $numeric = in_array('integer', $rules, true) || in_array('numeric', $rules, true);
            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $param, $field, $value, $numeric);
                if ($error !== null) {
                    $this->addError($field, $error);
                    break;
                }
            }
            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $this->normalize($rules, $value);
            }
        }
        return $this->errors === [];
    }

    private function check(string $name, ?string $param, string $field, mixed $value, bool $numeric): ?string
    {
        $str = is_scalar($value) ? (string) $value : '';
        switch ($name) {
            case 'required':
            case 'nullable':
                return null;
            case 'string':
                return is_string($value) ? null : 'Le champ :field doit être un texte.';
            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'Le champ :field doit être un nombre entier.';
            case 'numeric':
                return is_numeric($value) ? null : 'Le champ :field doit être un nombre.';
            case 'email':
                return filter_var($str, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($str) <= 190
                    ? null : 'L\'adresse e-mail n\'est pas valide.';
            case 'phone':
                return Phone::normalize($str) !== null ? null : 'Le numéro de téléphone n\'est pas valide (ex : 90 12 34 56).';
            case 'min':
                if ($numeric) {
                    return (float) $value >= (float) $param ? null : "Le champ :field doit être au moins {$param}.";
                }
                return mb_strlen($str) >= (int) $param ? null : "Le champ :field doit contenir au moins {$param} caractères.";
            case 'max':
                if ($numeric) {
                    return (float) $value <= (float) $param ? null : "Le champ :field ne doit pas dépasser {$param}.";
                }
                return mb_strlen($str) <= (int) $param ? null : "Le champ :field ne doit pas dépasser {$param} caractères.";
            case 'between':
                [$a, $b] = explode(',', (string) $param);
                return (float) $value >= (float) $a && (float) $value <= (float) $b ? null : "Le champ :field doit être compris entre {$a} et {$b}.";
            case 'in':
                return in_array($str, explode(',', (string) $param), true) ? null : 'La valeur choisie pour :field n\'est pas valide.';
            case 'confirmed':
                return ($this->data[$field . '_confirmation'] ?? null) === $value ? null : 'La confirmation de :field ne correspond pas.';
            case 'accepted':
                return in_array($str, ['1', 'on', 'yes', 'true', 'oui'], true) ? null : 'Vous devez accepter :field.';
            case 'date':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $str);
                return $d && $d->format('Y-m-d') === $str ? null : 'Le champ :field doit être une date valide.';
            case 'password':
                if (mb_strlen($str) < 8) {
                    return 'Le mot de passe doit contenir au moins 8 caractères.';
                }
                if (!preg_match('/\pL/u', $str) || !preg_match('/\d/', $str)) {
                    return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
                }
                return mb_strlen($str) <= 128 ? null : 'Le mot de passe est trop long.';
            case 'slug':
                return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $str) ? null : 'Le champ :field contient des caractères non autorisés.';
            case 'exists':
            case 'unique':
                return $this->checkDatabase($name, (string) $param, $field, $value);
            default:
                throw new \InvalidArgumentException("Règle de validation inconnue : {$name}");
        }
    }

    private function checkDatabase(string $rule, string $param, string $field, mixed $value): ?string
    {
        $parts = explode(',', $param);
        [$table, $column] = [$parts[0], $parts[1] ?? $field];
        $ignoreId = isset($parts[2]) ? (int) $parts[2] : null;
        if (!isset(self::SQL_WHITELIST[$table]) || !in_array($column, self::SQL_WHITELIST[$table], true)) {
            throw new \InvalidArgumentException("Table/colonne non autorisée pour {$rule} : {$table}.{$column}");
        }
        if ($this->db === null) {
            return null;
        }
        if ($column === 'phone') {
            $value = Phone::normalize((string) $value) ?? $value;
        }
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?";
        $params = [$value];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        $count = (int) $this->db->fetchValue($sql, $params);
        if ($rule === 'exists') {
            return $count > 0 ? null : 'La valeur choisie pour :field n\'existe pas.';
        }
        return $count === 0 ? null : 'Cette valeur de :field est déjà utilisée.';
    }

    /** @param list<string> $rules */
    private function normalize(array $rules, mixed $value): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('phone', $rules, true)) {
            return Phone::normalize((string) $value);
        }
        if (in_array('email', $rules, true)) {
            return mb_strtolower((string) $value);
        }
        if (in_array('accepted', $rules, true)) {
            return true;
        }
        return $value;
    }

    private function addError(string $field, string $message): void
    {
        $label = $this->labels[$field] ?? str_replace('_', ' ', $field);
        $this->errors[$field][] = str_replace(':field', '« ' . $label . ' »', $message);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        return $this->validated;
    }
}
