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

use Flaphl\Contracts\Cache\TagAwareCacheInterface;

/**
 * Interface for cache adapters that support tagging.
 * 
 * This interface extends the base adapter interface with tag-specific
 * operations for invalidating cache items by tags.
 */
interface TagAwareAdapterInterface extends AdapterInterface, TagAwareCacheInterface
{
    /**
     * Invalidate all cache items associated with any of the specified tags.
     *
     * @param string[]|string $tags An array of tags or a single tag
     * @return bool True if the operation was successful
     */
    public function invalidateTags(string|array $tags): bool;

    /**
     * Get all cache items associated with any of the specified tags.
     *
     * @param string[]|string $tags An array of tags or a single tag
     * @return iterable An iterable of cache items
     */
    public function getItemsByTags(string|array $tags): iterable;

    /**
     * Get all cache keys associated with any of the specified tags.
     *
     * @param string[]|string $tags An array of tags or a single tag
     * @return array An array of cache keys
     */
    public function getKeysByTags(string|array $tags): array;

    /**
     * Get all unique tags currently stored in the cache.
     *
     * @return array An array of all tags
     */
    public function getAllTags(): array;

    /**
     * Get statistics about tag usage.
     *
     * @return array Statistics including tag count, items per tag, etc.
     */
    public function getTagStats(): array;

    /**
     * Check if any items are associated with the specified tags.
     *
     * @param string[]|string $tags An array of tags or a single tag
     * @return bool True if any items have the tags
     */
    public function hasTag(string|array $tags): bool;

    /**
     * Prune cache items that have orphaned tags (tags with no items).
     *
     * @return int Number of orphaned tags removed
     */
    public function pruneOrphanedTags(): int;

    /**
     * Get the tag storage mechanism used by this adapter.
     *
     * @return string The tag storage type (e.g., 'embedded', 'separate', 'index')
     */
    public function getTagStorageType(): string;

    /**
     * Rebuild the tag index if the adapter uses separate tag storage.
     *
     * @return bool True if the rebuild was successful
     */
    public function rebuildTagIndex(): bool;
}
