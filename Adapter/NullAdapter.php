<?php

/**
 * This file is part of the Flaphl package.
 * 
 * (c) Jade Phyressi <jade@flaphl.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flaphl\Element\Cache\Adapter;

use Flaphl\Element\Cache\CacheItem;
use Flaphl\Element\Cache\Exception\InvalidArgumentException;

/**
 * Null cache adapter that never stores anything.
 * 
 * This adapter is useful for testing, development, or when you want
 * to disable caching without changing your code.
 */
class NullAdapter implements AdapterInterface
{
    private string $namespace;
    private array $config;

    /**
     * Create a new null adapter.
     *
     * @param string $namespace The namespace prefix for cache keys
     * @param array $config Additional configuration
     */
    public function __construct(string $namespace = '', array $config = [])
    {
        $this->namespace = $namespace;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(string $key): \Psr\Cache\CacheItemInterface
    {
        $this->validateKey($key);
        return new CacheItem($key, false);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(iterable $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }
        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function hasItems(iterable $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = false;
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->validateKey($key);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, ?callable $callback = null, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (null === $callback) {
            return null;
        }

        $item = new CacheItem($key, false);
        $value = $callback($item);
        
        if (null !== $metadata) {
            $metadata = $item->getMetadata();
        }
        
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->validateKey($key);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(string $feature): bool
    {
        return false; // Null adapter supports no special features
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        return [
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'memory_usage' => 0,
            'item_count' => 0,
            'adapter' => static::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): int
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * {@inheritdoc}
     */
    public function withNamespace(string $namespace): static
    {
        return new static($namespace, $this->config);
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxTtl(): ?int
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsBatch(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getSizes(iterable $keys = []): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = 0;
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getTotalSize(): int
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getItemCount(): int
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function toggleFeature(string $feature, bool $enabled): bool
    {
        return false; // No features to toggle
    }

    /**
     * Validate a cache key.
     *
     * @param string $key The key to validate
     * @throws InvalidArgumentException If the key is invalid
     */
    private function validateKey(string $key): void
    {
        if ('' === $key) {
            throw InvalidArgumentException::forInvalidKey($key, 'Key cannot be empty');
        }

        if (strlen($key) > 250) {
            throw InvalidArgumentException::forInvalidKey($key, 'Key cannot be longer than 250 characters');
        }

        if (preg_match('/[{}()\\/\\\\@:]/', $key)) {
            throw InvalidArgumentException::forInvalidKey($key, 'Key contains reserved characters');
        }
    }
}
