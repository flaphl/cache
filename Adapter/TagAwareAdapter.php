<?php

namespace Flaphl\Element\Cache\Adapter;

use Flaphl\Element\Cache\CacheItem;
use Flaphl\Element\Cache\Exception\InvalidArgumentException;
use Flaphl\Element\Cache\Exception\LogicException;
use Psr\Cache\CacheItemInterface;

/**
 * Tag-aware cache adapter decorator.
 * 
 * This decorator wraps any cache adapter to add tagging functionality.
 * It maintains a separate tag index to track tag-to-key mappings.
 */
class TagAwareAdapter implements TagAwareAdapterInterface
{
    private AdapterInterface $adapter;
    private AdapterInterface $tagIndex;
    private string $tagPrefix;
    private array $config;
    private array $tagStats = [];

    /**
     * Create a new tag-aware adapter.
     *
     * @param AdapterInterface $adapter The underlying cache adapter
     * @param AdapterInterface|null $tagIndex Optional separate adapter for tag index
     * @param array $config Configuration options
     */
    public function __construct(
        AdapterInterface $adapter,
        ?AdapterInterface $tagIndex = null,
        array $config = []
    ) {
        $this->adapter = $adapter;
        $this->tagIndex = $tagIndex ?? $adapter;
        $this->tagPrefix = $config['tag_prefix'] ?? '__tag__';
        
        $this->config = array_merge([
            'tag_prefix' => $this->tagPrefix,
            'max_tags_per_item' => 100,
            'enable_tag_stats' => true,
            'auto_prune_orphaned' => false,
            'tag_expiry' => null, // Tags don't expire by default
        ], $config);
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(string $key): CacheItemInterface
    {
        return $this->adapter->getItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(iterable $keys = []): iterable
    {
        return $this->adapter->getItems($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem(string $key): bool
    {
        return $this->adapter->hasItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function hasItems(iterable $keys): array
    {
        return $this->adapter->hasItems($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        $success = $this->adapter->clear();
        
        if ($success && $this->tagIndex !== $this->adapter) {
            $success = $this->tagIndex->clear();
        }
        
        $this->tagStats = [];
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(string $key): bool
    {
        // Get the item to check for tags before deletion
        $item = $this->adapter->getItem($key);
        
        $success = $this->adapter->deleteItem($key);
        
        if ($success && $item->isHit() && $item instanceof CacheItem) {
            $this->removeTagMappings($key, $item->getTags());
        }
        
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(iterable $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->deleteItem($key)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item): bool
    {
        // Get existing tags before saving
        $existingItem = $this->adapter->getItem($item->getKey());
        $existingTags = [];
        
        if ($existingItem->isHit() && $existingItem instanceof CacheItem) {
            $existingTags = $existingItem->getTags();
        }

        if ($item instanceof CacheItem) {
            $currentTags = $item->getTags();
            
            // Validate and limit tag count BEFORE saving
            if (count($currentTags) > $this->config['max_tags_per_item']) {
                $limitedTags = array_slice($currentTags, 0, $this->config['max_tags_per_item']);
                // Use reflection to directly set the tags property
                $reflection = new \ReflectionClass($item);
                $tagsProperty = $reflection->getProperty('tags');
                $tagsProperty->setAccessible(true);
                $tagsProperty->setValue($item, $limitedTags);
                $currentTags = $limitedTags;
            }
        }

        $success = $this->adapter->save($item);
        
        if ($success && $item instanceof CacheItem) {
            $currentTags = $item->getTags();
            
            // Update tag mappings
            $this->updateTagMappings($item->getKey(), $existingTags, $currentTags);
        }
        
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->adapter->saveDeferred($item);
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        return $this->adapter->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return $this->adapter->has($key);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, ?callable $callback = null, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->adapter->get($key, $callback, $beta, $metadata);
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->adapter->set($key, $value, $ttl);
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
    public function invalidateTags(string|array $tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $keysToDelete = [];
        
        foreach ($tags as $tag) {
            $this->validateTag($tag);
            $tagKeys = $this->getKeysForTag($tag);
            $keysToDelete = array_merge($keysToDelete, $tagKeys);
        }
        
        $keysToDelete = array_unique($keysToDelete);
        
        if (empty($keysToDelete)) {
            return true;
        }
        
        $success = $this->adapter->deleteItems($keysToDelete);
        
        if ($success) {
            // Remove tag mappings
            foreach ($tags as $tag) {
                $this->removeTagIndex($tag);
            }
        }
        
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function getItemsByTags(string|array $tags): iterable
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $keys = [];
        
        foreach ($tags as $tag) {
            $tagKeys = $this->getKeysForTag($tag);
            $keys = array_merge($keys, $tagKeys);
        }
        
        $keys = array_unique($keys);
        
        if (empty($keys)) {
            return [];
        }
        
        return $this->adapter->getItems($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function getKeysByTags(string|array $tags): array
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $keys = [];
        
        foreach ($tags as $tag) {
            $tagKeys = $this->getKeysForTag($tag);
            $keys = array_merge($keys, $tagKeys);
        }
        
        return array_unique($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllTags(): array
    {
        $masterTagListKey = $this->tagPrefix . '__master_tag_list__';
        $item = $this->tagIndex->getItem($masterTagListKey);
        
        if ($item->isHit()) {
            $tags = $item->get();
            return is_array($tags) ? $tags : [];
        }
        
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTagStats(): array
    {
        if (!$this->config['enable_tag_stats']) {
            return [];
        }
        
        $stats = [];
        $tags = $this->getAllTags();
        
        foreach ($tags as $tag) {
            $keys = $this->getKeysForTag($tag);
            $stats[$tag] = count($keys);
        }
        
        return $stats;
    }

    /**
     * {@inheritdoc}
     */
    public function hasTag(string|array $tags): bool
    {
        $tags = is_array($tags) ? $tags : [$tags];
        
        foreach ($tags as $tag) {
            $tagIndexKey = $this->getTagIndexKey($tag);
            if ($this->tagIndex->hasItem($tagIndexKey)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function pruneOrphanedTags(): int
    {
        $prunedCount = 0;
        $tags = $this->getAllTags();
        
        foreach ($tags as $tag) {
            $keys = $this->getKeysForTag($tag);
            $validKeys = [];
            
            foreach ($keys as $key) {
                if ($this->adapter->hasItem($key)) {
                    $validKeys[] = $key;
                }
            }
            
            if (empty($validKeys)) {
                // Tag has no valid items, remove it
                $this->removeTagIndex($tag);
                $prunedCount++;
            } elseif (count($validKeys) !== count($keys)) {
                // Some keys are invalid, update the tag index
                $this->updateTagIndex($tag, $validKeys);
            }
        }
        
        return $prunedCount;
    }

    /**
     * {@inheritdoc}
     */
    public function getTagStorageType(): string
    {
        return $this->tagIndex === $this->adapter ? 'embedded' : 'separate';
    }

    /**
     * {@inheritdoc}
     */
    public function rebuildTagIndex(): bool
    {
        if ($this->tagIndex === $this->adapter) {
            throw LogicException::forUnsupportedOperation(
                'rebuildTagIndex',
                'Cannot rebuild tag index when using embedded storage'
            );
        }
        
        // Clear existing tag index
        $this->tagIndex->clear();
        
        // Rebuild from scratch by scanning all items
        // This is expensive but necessary for consistency
        return true; // Implementation would depend on adapter's ability to scan all keys
    }

    /**
     * Delegate all other adapter methods to the underlying adapter.
     */
    public function getConfiguration(): array
    {
        $config = $this->adapter->getConfiguration();
        return array_merge($config, $this->config);
    }

    public function supports(string $feature): bool
    {
        if (in_array($feature, ['tagging', 'tag_invalidation', 'tag_stats'])) {
            return true;
        }
        return $this->adapter->supports($feature);
    }

    public function getStats(): array
    {
        $stats = $this->adapter->getStats();
        $tagStats = $this->getTagStats();
        
        // Calculate tag statistics from the simplified format
        $stats['tag_count'] = count($tagStats);
        $stats['tagged_items'] = array_sum($tagStats);
        $stats['tag_stats'] = $tagStats;
        
        return $stats;
    }

    public function prune(): int
    {
        $pruned = $this->adapter->prune();
        
        if ($this->config['auto_prune_orphaned']) {
            $pruned += $this->pruneOrphanedTags();
        }
        
        return $pruned;
    }

    public function reset(): bool
    {
        $success = $this->adapter->reset();
        
        if ($success && $this->tagIndex !== $this->adapter) {
            $success = $this->tagIndex->reset();
        }
        
        $this->tagStats = [];
        return $success;
    }

    public function isHealthy(): bool
    {
        $healthy = $this->adapter->isHealthy();
        
        if ($this->tagIndex !== $this->adapter) {
            $healthy = $healthy && $this->tagIndex->isHealthy();
        }
        
        return $healthy;
    }

    public function getNamespace(): string
    {
        return $this->adapter->getNamespace();
    }

    public function withNamespace(string $namespace): static
    {
        $newAdapter = $this->adapter->withNamespace($namespace);
        $newTagIndex = $this->tagIndex === $this->adapter 
            ? $newAdapter 
            : $this->tagIndex->withNamespace($namespace);
            
        return new static($newAdapter, $newTagIndex, $this->config);
    }

    public function optimize(): bool
    {
        $success = $this->adapter->optimize();
        
        if ($this->tagIndex !== $this->adapter) {
            $success = $success && $this->tagIndex->optimize();
        }
        
        return $success;
    }

    public function getMaxTtl(): ?int
    {
        return $this->adapter->getMaxTtl();
    }

    public function supportsBatch(): bool
    {
        return $this->adapter->supportsBatch();
    }

    public function getSizes(iterable $keys = []): array
    {
        return $this->adapter->getSizes($keys);
    }

    public function getTotalSize(): int
    {
        return $this->adapter->getTotalSize();
    }

    public function getItemCount(): int
    {
        return $this->adapter->getItemCount();
    }

    public function toggleFeature(string $feature, bool $enabled): bool
    {
        if (str_starts_with($feature, 'tag_')) {
            $configKey = substr($feature, 4);
            if (array_key_exists($configKey, $this->config)) {
                $this->config[$configKey] = $enabled;
                return true;
            }
        }
        
        return $this->adapter->toggleFeature($feature, $enabled);
    }

    /**
     * Get the tag index key for a tag.
     *
     * @param string $tag The tag
     * @return string The tag index key
     */
    private function getTagIndexKey(string $tag): string
    {
        return $this->tagPrefix . hash('xxh128', $tag);
    }

    /**
     * Get all keys for a specific tag.
     *
     * @param string $tag The tag
     * @return array Array of cache keys
     */
    private function getKeysForTag(string $tag): array
    {
        $tagIndexKey = $this->getTagIndexKey($tag);
        $item = $this->tagIndex->getItem($tagIndexKey);
        
        if (!$item->isHit()) {
            return [];
        }
        
        $keys = $item->get();
        return is_array($keys) ? $keys : [];
    }

    /**
     * Update tag mappings when an item is saved.
     *
     * @param string $key The cache key
     * @param array $oldTags Previous tags
     * @param array $newTags Current tags
     */
    private function updateTagMappings(string $key, array $oldTags, array $newTags): void
    {
        // Remove from old tags
        $removedTags = array_diff($oldTags, $newTags);
        foreach ($removedTags as $tag) {
            $this->removeKeyFromTag($tag, $key);
        }
        
        // Add to new tags
        $addedTags = array_diff($newTags, $oldTags);
        foreach ($addedTags as $tag) {
            $this->addKeyToTag($tag, $key);
        }
    }

    /**
     * Remove tag mappings when an item is deleted.
     *
     * @param string $key The cache key
     * @param array $tags The tags to remove
     */
    private function removeTagMappings(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $this->removeKeyFromTag($tag, $key);
        }
    }

    /**
     * Add a key to a tag index.
     *
     * @param string $tag The tag
     * @param string $key The cache key
     */
    private function addKeyToTag(string $tag, string $key): void
    {
        $this->validateTag($tag);
        $tagIndexKey = $this->getTagIndexKey($tag);
        $item = $this->tagIndex->getItem($tagIndexKey);
        
        $keys = $item->isHit() ? $item->get() : [];
        if (!is_array($keys)) {
            $keys = [];
        }
        
        if (!in_array($key, $keys)) {
            $keys[] = $key;
            $item->set($keys);
            
            if ($this->config['tag_expiry']) {
                $item->expiresAfter($this->config['tag_expiry']);
            }
            
            $this->tagIndex->save($item);
            
            // Update master tag list
            $this->addToMasterTagList($tag);
        }
    }

    /**
     * Remove a key from a tag index.
     *
     * @param string $tag The tag
     * @param string $key The cache key
     */
    private function removeKeyFromTag(string $tag, string $key): void
    {
        $tagIndexKey = $this->getTagIndexKey($tag);
        $item = $this->tagIndex->getItem($tagIndexKey);
        
        if (!$item->isHit()) {
            return;
        }
        
        $keys = $item->get();
        if (!is_array($keys)) {
            return;
        }
        
        $keyIndex = array_search($key, $keys);
        if (false !== $keyIndex) {
            unset($keys[$keyIndex]);
            $keys = array_values($keys); // Reindex array
            
            if (empty($keys)) {
                $this->tagIndex->deleteItem($tagIndexKey);
                // Remove from master tag list when tag has no more items
                $this->removeFromMasterTagList($tag);
            } else {
                $item->set($keys);
                $this->tagIndex->save($item);
            }
        }
    }

    /**
     * Update a tag index with new keys.
     *
     * @param string $tag The tag
     * @param array $keys The new keys
     */
    private function updateTagIndex(string $tag, array $keys): void
    {
        $tagIndexKey = $this->getTagIndexKey($tag);
        
        if (empty($keys)) {
            $this->tagIndex->deleteItem($tagIndexKey);
        } else {
            $item = new CacheItem($tagIndexKey, false);
            $item->set($keys);
            
            if ($this->config['tag_expiry']) {
                $item->expiresAfter($this->config['tag_expiry']);
            }
            
            $this->tagIndex->save($item);
        }
    }

    /**
     * Remove a tag index completely.
     *
     * @param string $tag The tag
     */
    private function removeTagIndex(string $tag): void
    {
        $tagIndexKey = $this->getTagIndexKey($tag);
        $this->tagIndex->deleteItem($tagIndexKey);
    }

    /**
     * Get all tag index keys.
     *
     * @return array Array of tag index keys
     */
    private function getTagIndexKeys(): array
    {
        $masterTagListKey = $this->tagPrefix . '__master_tag_list__';
        var_dump('Getting tag index keys, master key:', $masterTagListKey);
        $item = $this->tagIndex->getItem($masterTagListKey);
        
        var_dump('Master item hit?', $item->isHit());
        
        if ($item->isHit()) {
            $tags = $item->get();
            var_dump('Retrieved master tags:', $tags);
            return is_array($tags) ? array_map([$this, 'getTagIndexKey'], $tags) : [];
        }
        
        var_dump('No master tag list found');
        return [];
    }

    /**
     * Extract tag from tag index key.
     *
     * @param string $tagIndexKey The tag index key
     * @return string|null The tag or null if invalid
     */
    private function extractTagFromIndexKey(string $tagIndexKey): ?string
    {
        if (!str_starts_with($tagIndexKey, $this->tagPrefix)) {
            return null;
        }
        
        // Since we hash the tag, we can't reliably extract it
        // This would need to be stored separately or use a different approach
        return null;
    }

    /**
     * Validate a tag.
     *
     * @param string $tag The tag to validate
     * @throws InvalidArgumentException If the tag is invalid
     */
    private function validateTag(string $tag): void
    {
        if ('' === $tag) {
            throw InvalidArgumentException::forInvalidTag($tag);
        }

        if (strlen($tag) > 250) {
            throw InvalidArgumentException::forInvalidTag('Tag too long: ' . $tag);
        }

        if (preg_match('/[{}()\\/\\\\@]/', $tag)) {
            throw InvalidArgumentException::forInvalidTag('Tag contains reserved characters: ' . $tag);
        }
    }

    /**
     * Add a tag to the master tag list.
     *
     * @param string $tag The tag to add
     */
    private function addToMasterTagList(string $tag): void
    {
        $masterTagListKey = $this->tagPrefix . '__master_tag_list__';
        $item = $this->tagIndex->getItem($masterTagListKey);
        
        $tags = $item->isHit() ? $item->get() : [];
        if (!is_array($tags)) {
            $tags = [];
        }
        
        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            $item->set($tags);
            $this->tagIndex->save($item);
        }
    }

    /**
     * Remove a tag from the master tag list.
     *
     * @param string $tag The tag to remove
     */
    private function removeFromMasterTagList(string $tag): void
    {
        $masterTagListKey = $this->tagPrefix . '__master_tag_list__';
        $item = $this->tagIndex->getItem($masterTagListKey);
        
        if (!$item->isHit()) {
            return;
        }
        
        $tags = $item->get();
        if (!is_array($tags)) {
            return;
        }
        
        $tagIndex = array_search($tag, $tags);
        if (false !== $tagIndex) {
            unset($tags[$tagIndex]);
            $tags = array_values($tags); // Reindex array
            $item->set($tags);
            $this->tagIndex->save($item);
        }
    }
}
