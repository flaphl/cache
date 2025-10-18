<?php

namespace Flaphl\Element\Cache\Adapter;

use Flaphl\Contracts\Cache\CacheInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Base adapter interface for Flaphl cache implementations.
 * 
 * This interface extends PSR-6 CacheItemPoolInterface and Flaphl CacheInterface
 * to provide a unified API for all cache adapters with enhanced functionality.
 */
interface AdapterInterface extends CacheItemPoolInterface, CacheInterface
{
    /**
     * Get adapter-specific configuration.
     *
     * @return array The adapter configuration
     */
    public function getConfiguration(): array;

    /**
     * Check if the adapter supports a specific feature.
     *
     * @param string $feature The feature name (e.g., 'tagging', 'expiration', 'locking')
     * @return bool True if the feature is supported
     */
    public function supports(string $feature): bool;

    /**
     * Get adapter statistics and information.
     *
     * @return array Statistics including hit rate, memory usage, etc.
     */
    public function getStats(): array;

    /**
     * Prune expired items from the cache.
     *
     * @return int The number of items pruned
     */
    public function prune(): int;

    /**
     * Reset the adapter to its initial state.
     *
     * This clears all items and resets internal state but keeps configuration.
     *
     * @return bool True on success, false on failure
     */
    public function reset(): bool;

    /**
     * Check if the adapter is currently available and operational.
     *
     * @return bool True if operational, false otherwise
     */
    public function isHealthy(): bool;

    /**
     * Get the adapter's namespace prefix.
     *
     * @return string The namespace prefix
     */
    public function getNamespace(): string;

    /**
     * Create a new adapter instance with a different namespace.
     *
     * @param string $namespace The new namespace
     * @return static A new adapter instance
     */
    public function withNamespace(string $namespace): static;

    /**
     * Optimize the cache storage.
     *
     * This might involve compacting data, rebuilding indexes, etc.
     *
     * @return bool True on success, false on failure
     */
    public function optimize(): bool;

    /**
     * Get the maximum time-to-live supported by this adapter.
     *
     * @return int|null Maximum TTL in seconds, null if unlimited
     */
    public function getMaxTtl(): ?int;

    /**
     * Check if the adapter supports batch operations efficiently.
     *
     * @return bool True if batch operations are optimized
     */
    public function supportsBatch(): bool;

    /**
     * Get multiple cache items efficiently.
     *
     * This should be more efficient than calling getItem() multiple times.
     *
     * @param iterable $keys The cache keys
     * @return iterable An iterable of cache items keyed by cache keys
     */
    public function getItems(iterable $keys = []): iterable;

    /**
     * Check if multiple cache items exist.
     *
     * @param iterable $keys The cache keys to check
     * @return array An array of key => bool indicating existence
     */
    public function hasItems(iterable $keys): array;

    /**
     * Get the size of stored data for given keys.
     *
     * @param iterable $keys The cache keys, empty for all items
     * @return array An array of key => size in bytes
     */
    public function getSizes(iterable $keys = []): array;

    /**
     * Get the total size of all cached data.
     *
     * @return int Total size in bytes
     */
    public function getTotalSize(): int;

    /**
     * Get the number of items in the cache.
     *
     * @return int The item count
     */
    public function getItemCount(): int;

    /**
     * Enable or disable adapter-specific features.
     *
     * @param string $feature The feature name
     * @param bool $enabled Whether to enable the feature
     * @return bool True if the feature was toggled successfully
     */
    public function toggleFeature(string $feature, bool $enabled): bool;
}
