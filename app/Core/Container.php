<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Conteneur d'injection de dépendances minimal : singletons déclarés + autowiring
 * par réflexion des constructeurs (types de classes uniquement).
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string, Closure> */
    private array $factories = [];
    /** @var array<string, object> */
    private array $instances = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|object
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        if (!class_exists($id)) {
            throw new RuntimeException("Service inconnu : {$id}");
        }
        return $this->instances[$id] = $this->build($id);
    }

    private function build(string $class): object
    {
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Classe non instanciable : {$class}");
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new RuntimeException("Impossible de résoudre \${$param->getName()} pour {$class}");
            }
        }
        return $reflection->newInstanceArgs($args);
    }
}
