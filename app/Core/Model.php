<?php

declare(strict_types=1);

/**
 * Lightweight ORM-style base model. It intentionally stays simple: persistence complexity belongs in repositories/services.
 */

namespace App\Core;

abstract class Model
{
    protected static string $table;
    protected static string $primaryKey = 'id';
    protected array $attributes = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function fill(array $attributes): void
    {
        $this->attributes = array_replace($this->attributes, $attributes);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public static function table(): string
    {
        return static::$table;
    }

    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }
}
