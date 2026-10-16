<?php

declare(strict_types=1);

namespace App\Core;

/** Accès en notation pointée aux fichiers de config/*.php (ex : config('app.name')). */
final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(string $configDir)
    {
        foreach (glob(rtrim($configDir, '/') . '/*.php') ?: [] as $file) {
            $this->items[basename($file, '.php')] = require $file;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $ref = &$this->items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }
}
