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
use Flaphl\Element\Cache\Exception\CacheException;

/**
 * PHP array cache adapter optimized for production caching.
 * 
 * This adapter stores cache as PHP arrays that are included/required,
 * providing extremely fast access times. Best used with opcache enabled.
 */
class PhpArrayAdapter implements AdapterInterface
{
    private string $file;
    private string $namespace;
    private array $config;
    private array $cache = [];
    private array $deferred = [];
    private bool $loaded = false;
    private int $hits = 0;
    private int $misses = 0;
    private bool $modified = false;

    /**
     * Create a new PHP array adapter.
     *
     * @param string $file The cache file path
     * @param string $namespace The namespace prefix for cache keys
     * @param array $config Additional configuration
     */
    public function __construct(string $file, string $namespace = '', array $config = [])
    {
        $this->file = $file;
        $this->namespace = $namespace;
        $this->config = array_merge([
            'auto_save' => true,
            'pretty_print' => false,
            'enable_expiration' => true,
            'backup_copies' => 1,
        ], $config);

        $this->loadCache();
    }

    /**
     * Save cache on destruction if auto_save is enabled.
     */
    public function __destruct()
    {
        if ($this->config['auto_save'] && $this->modified) {
            $this->saveCache();
        }
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
                $this->modified = true;
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
            $this->modified = true;
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
        $this->modified = true;
        
        if ($this->config['auto_save']) {
            return $this->saveCache();
        }
        
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        $namespacedKey = $this->getNamespacedKey($key);
        
        if (isset($this->cache[$namespacedKey])) {
            unset($this->cache[$namespacedKey]);
            $this->modified = true;
        }
        
        unset($this->deferred[$namespacedKey]);
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
        $this->modified = true;
        
        if ($this->config['auto_save']) {
            return $this->saveCache();
        }
        
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
        return in_array($feature, ['expiration', 'tagging', 'metadata', 'stats', 'persistence']);
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
            'file_size' => file_exists($this->file) ? filesize($this->file) : 0,
            'item_count' => count($this->cache),
            'deferred_count' => count($this->deferred),
            'modified' => $this->modified,
            'adapter' => static::class,
            'file' => $this->file,
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
                $this->modified = true;
            }
        }
        
        if ($pruned > 0 && $this->config['auto_save']) {
            $this->saveCache();
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
        $this->modified = true;
        
        if ($this->config['auto_save']) {
            return $this->saveCache();
        }
        
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        $dir = dirname($this->file);
        return is_dir($dir) && is_writable($dir);
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
        return new static($this->file, $namespace, $this->config);
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(): bool
    {
        // Prune expired items and save
        $this->prune();
        return $this->saveCache();
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxTtl(): ?int
    {
        return null; // No inherent limit
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
            case 'auto_save':
                $this->config['auto_save'] = $enabled;
                return true;
            case 'expiration':
                $this->config['enable_expiration'] = $enabled;
                return true;
            case 'pretty_print':
                $this->config['pretty_print'] = $enabled;
                return true;
            default:
                return false;
        }
    }

    /**
     * Manually save the cache to the file.
     *
     * @return bool True on success
     */
    public function saveCache(): bool
    {
        try {
            $this->ensureDirectoryExists();
            
            // Create backup if enabled
            if ($this->config['backup_copies'] > 0 && file_exists($this->file)) {
                $this->createBackup();
            }

            $content = $this->generatePhpFile();
            $tempFile = $this->file . '.tmp.' . uniqid();
            
            if (false === file_put_contents($tempFile, $content, LOCK_EX)) {
                return false;
            }

            if (!rename($tempFile, $this->file)) {
                @unlink($tempFile);
                return false;
            }

            $this->modified = false;
            return true;
        } catch (\Throwable $e) {
            throw CacheException::forOperation('save', $e->getMessage(), $e);
        }
    }

    /**
     * Manually load the cache from the file.
     *
     * @return bool True on success
     */
    public function loadCache(): bool
    {
        if (!file_exists($this->file)) {
            $this->cache = [];
            $this->loaded = true;
            return true;
        }

        try {
            $this->cache = include $this->file;
            if (!is_array($this->cache)) {
                $this->cache = [];
            }
            $this->loaded = true;
            $this->modified = false;
            return true;
        } catch (\Throwable) {
            $this->cache = [];
            $this->loaded = true;
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
     * Generate the PHP file content.
     *
     * @return string The PHP file content
     */
    private function generatePhpFile(): string
    {
        $export = var_export($this->cache, true);
        
        if ($this->config['pretty_print']) {
            $export = $this->formatArrayExport($export);
        }

        $timestamp = date('Y-m-d H:i:s');
        $comment = "// Generated by " . static::class . " on {$timestamp}\n";
        
        return "<?php\n{$comment}// Namespace: {$this->namespace}\n\nreturn {$export};\n";
    }

    /**
     * Format the array export for better readability.
     *
     * @param string $export The var_export output
     * @return string Formatted output
     */
    private function formatArrayExport(string $export): string
    {
        // Basic formatting improvements
        $export = str_replace(
            ['array (', '),', ' => ', '  '],
            ['[', '],', ' => ', '    '],
            $export
        );
        
        // Replace array( with [
        $export = preg_replace('/array\s*\(/', '[', $export);
        
        // Replace final ) with ]
        $export = preg_replace('/\)$/', ']', $export);
        
        return $export;
    }

    /**
     * Create a backup of the current cache file.
     */
    private function createBackup(): void
    {
        $backupFile = $this->file . '.backup';
        
        // Rotate existing backups
        for ($i = $this->config['backup_copies']; $i > 1; $i--) {
            $current = $backupFile . ($i - 1);
            $next = $backupFile . $i;
            
            if (file_exists($current)) {
                rename($current, $next);
            }
        }
        
        // Create new backup
        copy($this->file, $backupFile . '1');
    }

    /**
     * Ensure the cache directory exists.
     */
    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->file);
        
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw CacheException::forOperation(
                    'mkdir', 
                    'Failed to create cache directory: ' . $directory
                );
            }
        }
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
