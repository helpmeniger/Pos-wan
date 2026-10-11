<?php
declare(strict_types=1);

namespace App\Support;

use App\Services\ValidationException;

/** Validation de formulaires avec messages en français. */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    public function __construct(private readonly array $input)
    {
    }

    public static function make(array $input): self
    {
        return new self($input);
    }

    private function raw(string $field): string
    {
        $v = $this->input[$field] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    public function text(string $field, string $label, bool $required = false, int $max = 255, int $min = 0): self
    {
        $v = $this->raw($field);
        // Suppression des caractères de contrôle (hors retours à la ligne).
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « $label » est obligatoire.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (mb_strlen($v) > $max) {
            $this->errors[$field] = "Le champ « $label » ne doit pas dépasser $max caractères.";
        } elseif (mb_strlen($v) < $min) {
            $this->errors[$field] = "Le champ « $label » doit comporter au moins $min caractères.";
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function name(string $field, string $label, bool $required = true): self
    {
        $this->text($field, $label, $required, 100);
        $v = $this->clean[$field];
        if ($v !== null && !preg_match("/^[\p{L}][\p{L}\p{M} '’.-]*$/u", $v)) {
            $this->errors[$field] = "Le champ « $label » contient des caractères non autorisés.";
        }
        return $this;
    }

    public function date(string $field, string $label, bool $required = false, bool $notFuture = false): self
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « $label » est obligatoire.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        $d = \DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v || (int) $d->format('Y') < 1900) {
            $this->errors[$field] = "Le champ « $label » doit être une date valide.";
            $this->clean[$field] = null;
            return $this;
        }
        if ($notFuture && $d > new \DateTime('today')) {
            $this->errors[$field] = "Le champ « $label » ne peut pas être une date future.";
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function datetime(string $field, string $label, bool $required = false): self
    {
        $v = str_replace('T', ' ', $this->raw($field));
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « $label » est obligatoire.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (strlen($v) === 16) {
            $v .= ':00';
        }
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $v);
        if (!$d || $d->format('Y-m-d H:i:s') !== $v) {
            $this->errors[$field] = "Le champ « $label » doit être une date et heure valides.";
            $this->clean[$field] = null;
            return $this;
        }
        if ($d > new \DateTime('+1 day')) {
            $this->errors[$field] = "Le champ « $label » ne peut pas être une date future.";
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function int(string $field, string $label, bool $required = false, int $min = 0, int $max = PHP_INT_MAX): self
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = "Le champ « $label » est obligatoire.";
            }
            $this->clean[$field] = null;
            return $this;
        }
        if (!preg_match('/^\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
            $this->errors[$field] = "Le champ « $label » doit être un nombre entre $min et $max.";
            $this->clean[$field] = null;
            return $this;
        }
        $this->clean[$field] = (int) $v;
        return $this;
    }

    public function in(string $field, string $label, array $allowed, bool $required = true, ?string $default = null): self
    {
        $v = $this->raw($field);
        if ($v === '') {
            if ($required && $default === null) {
                $this->errors[$field] = "Le champ « $label » est obligatoire.";
            }
            $this->clean[$field] = $default;
            return $this;
        }
        if (!in_array($v, $allowed, true)) {
            $this->errors[$field] = "Valeur non autorisée pour « $label ».";
            $this->clean[$field] = $default;
            return $this;
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function bool(string $field): self
    {
        $this->clean[$field] = !empty($this->input[$field]) ? 1 : 0;
        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] = $message;
        return $this;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function data(): array
    {
        return $this->clean;
    }

    /** @throws ValidationException */
    public function validated(): array
    {
        if ($this->errors) {
            throw new ValidationException('Le formulaire contient des erreurs.', $this->errors);
        }
        return $this->clean;
    }
}
