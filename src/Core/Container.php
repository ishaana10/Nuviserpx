<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use Exception;
use ReflectionClass;
use ReflectionNamedType;

class Container
{
    private static ?self $instance = null;
    /** @var array<string, Closure|string> */
    private array $bindings = [];
    /** @var array<string, object> */
    private array $instances = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, object $concrete): void
    {
        $this->instances[$abstract] = $concrete;
    }

    public function make(string $abstract): object
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];
            $object = ($concrete instanceof Closure) ? $concrete($this) : $this->make((string)$concrete);
            $this->instances[$abstract] = $object;
            return $object;
        }

        if (class_exists($abstract)) {
            $reflector = new ReflectionClass($abstract);
            $constructor = $reflector->getConstructor();

            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                $object = new $abstract();
            } else {
                $dependencies = [];
                foreach ($constructor->getParameters() as $param) {
                    $type = $param->getType();
                    if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                        $dependencies[] = $this->make($type->getName());
                    } elseif ($param->isDefaultValueAvailable()) {
                        $dependencies[] = $param->getDefaultValue();
                    } else {
                        throw new Exception("Cannot resolve parameter {$param->getName()} for {$abstract}");
                    }
                }
                $object = $reflector->newInstanceArgs($dependencies);
            }

            $this->instances[$abstract] = $object;
            return $object;
        }

        throw new Exception("Class or binding [{$abstract}] not found in container.");
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || class_exists($abstract);
    }
}
