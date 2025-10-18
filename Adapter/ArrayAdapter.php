<?php

namespace Flaphl\Element\Cache\Adapter;

use Flaphl\Element\Cache\CacheItem;
use Flaphl\Element\Cache\Exception\InvalidArgumentException;

/**
 * In-memory array cache adapter.
 * 
 * This adapter stores cache items in memory using a PHP array.
 * Data is lost when the process ends. Useful for testing and
 * short-lived caching needs.
 */
class ArrayAdapter implements AdapterInterface
{
    private array $cache = [];
    private array $deferred = [];
    private string $namespace;
    private array $config;
    private int $hits = 0;
    private int $misses = 0;
    private int $maxItems;

    /**
     * Create a new array adapter.
     *
     * @param string $namespace The namespace prefix for cache keys
     * @param int $maxItems Maximum number of items to store (0 = unlimited)
     * @param array $config Additional configuration
     */
    public function __construct(string $namespace = '', int $maxItems = 0, array $config = [])
    {
        $this->namespace = $namespace;
        $this->maxItems = $maxItems;
        $this->config = array_merge([
            'max_items' => $maxItems,
            'enable_expiration' => true,
        ], $config);
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(string $key): \Psr\Cache\CacheItemInterface
    {
        $this->validateKey($key);
        $namespacedKey = $this->getNamespacedKey($key);

        if (isset($this->cache[$namespacedKey])) {
            $data = $this->cache[$namespacedKey];
            
            // Check expiration
            if ($this->config['enable_expiration'] && $this->isExpired($data)) {
                unset($this->cache[$namespacedKey]);
                $this->misses++;
                return new CacheItem($key, false);
            }

            $this->hits++;
            $item = new CacheItem($key, true);
            $item->set($data['value']);
            
            if (isset($data['expiry'])) {
                $item->expiresAt(new \DateTimeImmutable('@' . $data['expiry']));
            }
            
            if (isset($data['tags'])) {
                $item->tag($data['tags']);
            }

            if (isset($data['metadata'])) {
                foreach ($data['metadata'] as $metaKey => $metaValue) {
                    $item->setMetadata($metaKey, $metaValue);
                }
            }

            return $item;
        }

        $this->misses++;
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
        $namespacedKey = $this->getNamespacedKey($key);

        if (!isset($this->cache[$namespacedKey])) {
            return false;
        }

        if ($this->config['enable_expiration'] && $this->isExpired($this->cache[$namespacedKey])) {
            unset($this->cache[$namespacedKey]);
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function hasItems(iterable $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->hasItem($key);
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $this->cache = [];
        $this->deferred = [];
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        $namespacedKey = $this->getNamespacedKey($key);
        
        unset($this->cache[$namespacedKey], $this->deferred[$namespacedKey]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        $namespacedKey = $this->getNamespacedKey($item->getKey());
        
        // Enforce max items limit
        if ($this->maxItems > 0 && count($this->cache) >= $this->maxItems && !isset($this->cache[$namespacedKey])) {
            // Remove oldest item (FIFO)
            $oldestKey = array_key_first($this->cache);
            unset($this->cache[$oldestKey]);
        }

        $data = [
            'value' => $item->get(),
            'created' => time(),
        ];

        if ($item instanceof CacheItem) {
            if ($item->getExpiry()) {
                $data['expiry'] = $item->getExpiry()->getTimestamp();
            }

            $tags = $item->getTags();
            if (!empty($tags)) {
                $data['tags'] = $tags;
            }

            $metadata = $item->getMetadata();
            if (!empty($metadata)) {
                $data['metadata'] = $metadata;
            }
        }

        $this->cache[$namespacedKey] = $data;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
    {
        $namespacedKey = $this->getNamespacedKey($item->getKey());
        $this->deferred[$namespacedKey] = $item;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        foreach ($this->deferred as $item) {
            $this->save($item);
        }
        $this->deferred = [];
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
        $item = $this->getItem($key);
        
        if ($item->isHit()) {
            if (null !== $metadata && $item instanceof CacheItem) {
                $metadata = $item->getMetadata();
            }
            return $item->get();
        }

        if (null === $callback) {
            return null;
        }

        $value = $callback($item);
        $this->save($item);
        
        if (null !== $metadata && $item instanceof CacheItem) {
            $metadata = $item->getMetadata();
        }
        
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = new CacheItem($key, false);
        $item->set($value);
        
        if (null !== $ttl) {
            $item->expiresAfter($ttl);
        }
        
        return $this->save($item);
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
        return in_array($feature, ['expiration', 'tagging', 'metadata', 'stats']);
    }

    /**
     * {@inheritdoc}
     */
    public function getStats(): array
    {
        $total = $this->hits + $this->misses;
        
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_ratio' => $total > 0 ? $this->hits / $total : 0.0,
            'memory_usage' => $this->calculateMemoryUsage(),
            'items' => count($this->cache),
            'deferred_count' => count($this->deferred),
            'adapter' => static::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): int
    {
        if (!$this->config['enable_expiration']) {
            return 0;
        }

        $pruned = 0;
        $now = time();
        
        foreach ($this->cache as $key => $data) {
            if ($this->isExpired($data, $now)) {
                unset($this->cache[$key]);
                $pruned++;
            }
        }
        
        return $pruned;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): bool
    {
        $this->cache = [];
        $this->deferred = [];
        $this->hits = 0;
        $this->misses = 0;
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
        return new static($namespace, $this->maxItems, $this->config);
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(): bool
    {
        // Prune expired items
        $this->prune();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxTtl(): ?int
    {
        return null; // No limit
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
        
        if (empty($keys)) {
            foreach ($this->cache as $namespacedKey => $data) {
                $originalKey = $this->getOriginalKey($namespacedKey);
                $result[$originalKey] = $this->calculateItemSize($data);
            }
        } else {
            foreach ($keys as $key) {
                $namespacedKey = $this->getNamespacedKey($key);
                $result[$key] = isset($this->cache[$namespacedKey]) 
                    ? $this->calculateItemSize($this->cache[$namespacedKey])
                    : 0;
            }
        }
        
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getTotalSize(): int
    {
        return $this->calculateMemoryUsage();
    }

    /**
     * {@inheritdoc}
     */
    public function getItemCount(): int
    {
        return count($this->cache);
    }

    /**
     * {@inheritdoc}
     */
    public function toggleFeature(string $feature, bool $enabled): bool
    {
        switch ($feature) {
            case 'expiration':
                $this->config['enable_expiration'] = $enabled;
                return true;
            default:
                return false;
        }
    }

    /**
     * Get the namespaced cache key.
     *
     * @param string $key The original key
     * @return string The namespaced key
     */
    private function getNamespacedKey(string $key): string
    {
        return $this->namespace ? $this->namespace . ':' . $key : $key;
    }

    /**
     * Get the original key from a namespaced key.
     *
     * @param string $namespacedKey The namespaced key
     * @return string The original key
     */
    private function getOriginalKey(string $namespacedKey): string
    {
        if (!$this->namespace) {
            return $namespacedKey;
        }
        
        $prefix = $this->namespace . ':';
        return str_starts_with($namespacedKey, $prefix) 
            ? substr($namespacedKey, strlen($prefix))
            : $namespacedKey;
    }

    /**
     * Check if a cache item has expired.
     *
     * @param array $data The cache item data
     * @param int|null $now Current timestamp
     * @return bool True if expired
     */
    private function isExpired(array $data, ?int $now = null): bool
    {
        if (!isset($data['expiry'])) {
            return false;
        }
        
        $now ??= time();
        return $data['expiry'] <= $now;
    }

    /**
     * Calculate memory usage of all cache items.
     *
     * @return int Memory usage in bytes
     */
    private function calculateMemoryUsage(): int
    {
        return strlen(serialize($this->cache));
    }

    /**
     * Calculate the size of a single cache item.
     *
     * @param array $data The item data
     * @return int Size in bytes
     */
    private function calculateItemSize(array $data): int
    {
        return strlen(serialize($data));
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
