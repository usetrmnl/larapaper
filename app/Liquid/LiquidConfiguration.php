<?php

declare(strict_types=1);

namespace App\Liquid;

use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Liquid view of plugin configuration.
 *
 * TRMNL hashes iterate as [key, value] pairs, and boolean settings are the
 * strings "true" and "false". Property and bracket access still return each setting.
 *
 * @implements IteratorAggregate<string, array{0: string, 1: mixed}>
 */
final class LiquidConfiguration implements IteratorAggregate, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values) {}

    public function __get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function getIterator(): Traversable
    {
        foreach ($this->values as $key => $value) {
            yield $key => [$key, $value];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
