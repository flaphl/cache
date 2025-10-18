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
 * PHP files cache adapter that stores each item as a separate PHP file.
 * 
 * This adapter provides excellent performance with opcache by storing each
 * cache item as a PHP file that returns the cached data. Combines benefits
 * of filesystem persistence with PHP opcache optimization.
 */
class PhpFilesAdapter implements AdapterInterface
{
    private string $directory;
    private string $namespace;
    private array $config;
    private int $hits = 0;
    private int $misses = 0;
    private array $deferred = [];
    private int $defaultFileMode;
    private int $defaultDirMode;

    /**
     * Create a new PHP files adapter.
     *
     * @param string $directory The cache directory
     * @param string $namespace The namespace prefix for cache keys
     * @param int $defaultTtl Default TTL in seconds
     * @param array $config Additional configuration
     */
    public function __construct(
        string $directory,
        string $namespace = '',
        int $defaultTtl = 0,
        array $config = []
    ) {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->namespace = $namespace;
        $this->defaultFileMode = $config['file_mode'] ?? 0666;
        $this->defaultDirMode = $config['dir_mode'] ?? 0777;
        
        $this->config = array_merge([
            'default_ttl' => $defaultTtl,
            'file_mode' => $this->defaultFileMode,
            'dir_mode' => $this->defaultDirMode,
            'hash_algo' => 'xxh128',
            'enable_gc' => true,
            'gc_probability' => 0.01,
            'enable_opcache' => true,
            'pretty_print' => false,
        ], $config);

        $this->ensureDirectoryExists();
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(string $key): \Psr\Cache\CacheItemInterface
    {
        $this->validateKey($key);
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            $this->misses++;
            return new CacheItem($key, false);
        }

        try {
            $data = $this->loadFile($filePath);
            
            if ($this->isExpired($data)) {
                @unlink($filePath);
                $this->invalidateOpcache($filePath);
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
        } catch (\Throwable $e) {
            $this->misses++;
            return new CacheItem($key, false);
        }
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
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return false;
        }

        try {
            $data = $this->loadFile($filePath);
            
            if ($this->isExpired($data)) {
                @unlink($filePath);
                $this->invalidateOpcache($filePath);
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
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
        $this->deferred = [];
        
        try {
            $this->clearDirectory($this->getNamespacePath());
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        $filePath = $this->getFilePath($key);
        
        unset($this->deferred[$key]);
        
        if (!file_exists($filePath)) {
            return true;
        }

        $success = @unlink($filePath);
        if ($success) {
            $this->invalidateOpcache($filePath);
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
    public function save(\Psr\Cache\CacheItemInterface $item): bool
    {
        $filePath = $this->getFilePath($item->getKey());
        
        try {
            $this->ensureDirectoryExists(dirname($filePath));
            
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

            $success = $this->saveFile($filePath, $data);
            
            if ($success) {
                $this->invalidateOpcache($filePath);
            }
            
            return $success;
        } catch (\Throwable $e) {
            throw CacheException::forOperation('save', $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        $success = true;
        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $success = false;
            }
        }
        $this->deferred = [];
        return $success;
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
        } elseif ($this->config['default_ttl'] > 0) {
            $item->expiresAfter($this->config['default_ttl']);
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
        return in_array($feature, ['expiration', 'tagging', 'metadata', 'stats', 'persistence', 'opcache']);
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
            'memory_usage' => 0, // Not applicable for files
            'disk_usage' => $this->calculateDiskUsage(),
            'item_count' => $this->getItemCount(),
            'deferred_count' => count($this->deferred),
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(),
            'adapter' => static::class,
            'directory' => $this->directory,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): int
    {
        return $this->garbageCollect();
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): bool
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->deferred = [];
        return $this->clear();
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        return is_dir($this->directory) && is_writable($this->directory);
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
        return new static($this->directory, $namespace, $this->config['default_ttl'], $this->config);
    }

    /**
     * {@inheritdoc}
     */
    public function optimize(): bool
    {
        // Perform garbage collection
        $this->garbageCollect();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMaxTtl(): ?int
    {
        return null; // No inherent limit for filesystem
    }

    /**
     * {@inheritdoc}
     */
    public function supportsBatch(): bool
    {
        return false; // Filesystem operations are inherently sequential
    }

    /**
     * {@inheritdoc}
     */
    public function getSizes(iterable $keys = []): array
    {
        $result = [];
        
        if (empty($keys)) {
            // Get all keys in namespace
            $files = $this->scanDirectory($this->getNamespacePath());
            foreach ($files as $file) {
                $key = $this->getKeyFromFilePath($file);
                $result[$key] = filesize($file) ?: 0;
            }
        } else {
            foreach ($keys as $key) {
                $filePath = $this->getFilePath($key);
                $result[$key] = file_exists($filePath) ? filesize($filePath) : 0;
            }
        }
        
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getTotalSize(): int
    {
        return $this->calculateDiskUsage();
    }

    /**
     * {@inheritdoc}
     */
    public function getItemCount(): int
    {
        return count($this->scanDirectory($this->getNamespacePath()));
    }

    /**
     * {@inheritdoc}
     */
    public function toggleFeature(string $feature, bool $enabled): bool
    {
        switch ($feature) {
            case 'gc':
                $this->config['enable_gc'] = $enabled;
                return true;
            case 'opcache':
                $this->config['enable_opcache'] = $enabled;
                return true;
            case 'pretty_print':
                $this->config['pretty_print'] = $enabled;
                return true;
            default:
                return false;
        }
    }

    /**
     * Get the file path for a cache key.
     *
     * @param string $key The cache key
     * @return string The file path
     */
    private function getFilePath(string $key): string
    {
        $hash = hash($this->config['hash_algo'], $key);
        $subdir = substr($hash, 0, 2);
        
        return $this->getNamespacePath() . DIRECTORY_SEPARATOR . 
               $subdir . DIRECTORY_SEPARATOR . 
               $hash . '.php';
    }

    /**
     * Get the namespace path.
     *
     * @return string The namespace directory path
     */
    private function getNamespacePath(): string
    {
        if (!$this->namespace) {
            return $this->directory;
        }
        
        return $this->directory . DIRECTORY_SEPARATOR . 
               hash($this->config['hash_algo'], $this->namespace);
    }

    /**
     * Get the original key from a file path.
     *
     * @param string $filePath The file path
     * @return string The original key (best effort)
     */
    private function getKeyFromFilePath(string $filePath): string
    {
        // This is a best-effort reconstruction since we hash the keys
        return basename($filePath, '.php');
    }

    /**
     * Load data from a cache file.
     *
     * @param string $filePath The file path
     * @return array The cache data
     * @throws \RuntimeException If loading fails
     */
    private function loadFile(string $filePath): array
    {
        try {
            $data = include $filePath;
            
            if (!is_array($data)) {
                throw new \RuntimeException('Cache file did not return an array: ' . $filePath);
            }
            
            return $data;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to load cache file: ' . $filePath, 0, $e);
        }
    }

    /**
     * Save data to a cache file.
     *
     * @param string $filePath The file path
     * @param array $data The cache data
     * @return bool True on success
     */
    private function saveFile(string $filePath, array $data): bool
    {
        $content = $this->generatePhpContent($data);
        $tempFile = $filePath . '.tmp.' . uniqid();
        
        if (false === file_put_contents($tempFile, $content, LOCK_EX)) {
            return false;
        }

        chmod($tempFile, $this->defaultFileMode);
        
        if (!rename($tempFile, $filePath)) {
            @unlink($tempFile);
            return false;
        }

        // Trigger garbage collection occasionally
        if ($this->config['enable_gc'] && mt_rand() / mt_getrandmax() < $this->config['gc_probability']) {
            $this->garbageCollect();
        }

        return true;
    }

    /**
     * Generate PHP file content.
     *
     * @param array $data The cache data
     * @return string The PHP file content
     */
    private function generatePhpContent(array $data): string
    {
        $export = var_export($data, true);
        
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
     * Check if cache data has expired.
     *
     * @param array $data The cache data
     * @return bool True if expired
     */
    private function isExpired(array $data): bool
    {
        if (!isset($data['expiry'])) {
            return false;
        }
        
        return $data['expiry'] <= time();
    }

    /**
     * Invalidate opcache for a file.
     *
     * @param string $filePath The file path
     */
    private function invalidateOpcache(string $filePath): void
    {
        if ($this->config['enable_opcache'] && function_exists('opcache_invalidate')) {
            opcache_invalidate($filePath, true);
        }
    }

    /**
     * Ensure a directory exists with proper permissions.
     *
     * @param string|null $directory The directory path, null for default
     * @throws CacheException If directory creation fails
     */
    private function ensureDirectoryExists(?string $directory = null): void
    {
        $directory ??= $this->getNamespacePath();
        
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, $this->defaultDirMode, true) && !is_dir($directory)) {
            throw CacheException::forOperation(
                'mkdir', 
                'Failed to create cache directory: ' . $directory
            );
        }
    }

    /**
     * Clear all files in a directory recursively.
     *
     * @param string $directory The directory to clear
     */
    private function clearDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                $filePath = $file->getPathname();
                unlink($filePath);
                $this->invalidateOpcache($filePath);
            }
        }
    }

    /**
     * Scan directory for cache files.
     *
     * @param string $directory The directory to scan
     * @return array Array of file paths
     */
    private function scanDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Perform garbage collection of expired items.
     *
     * @return int Number of items removed
     */
    private function garbageCollect(): int
    {
        $removed = 0;
        $files = $this->scanDirectory($this->getNamespacePath());
        
        foreach ($files as $file) {
            try {
                $data = $this->loadFile($file);
                if ($this->isExpired($data)) {
                    unlink($file);
                    $this->invalidateOpcache($file);
                    $removed++;
                }
            } catch (\Throwable) {
                // Invalid file, remove it
                unlink($file);
                $this->invalidateOpcache($file);
                $removed++;
            }
        }
        
        return $removed;
    }

    /**
     * Calculate total disk usage.
     *
     * @return int Disk usage in bytes
     */
    private function calculateDiskUsage(): int
    {
        $size = 0;
        $files = $this->scanDirectory($this->getNamespacePath());
        
        foreach ($files as $file) {
            $size += filesize($file) ?: 0;
        }
        
        return $size;
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
