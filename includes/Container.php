<?php

declare(strict_types=1);

namespace OpenScene\Engine;

use RuntimeException;

final class Container
{
    /** @var array<string, callable(self):mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (! array_key_exists($id, $this->factories)) {
            throw new RuntimeException("Service not found: {$id}");
        }

        $this->instances[$id] = ($this->factories[$id])($this);

        return $this->instances[$id];
    }
}
