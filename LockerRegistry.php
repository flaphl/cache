<?php

namespace Flaphl\Element\Cache;

use Flaphl\Element\Cache\Exception\LogicException;
use Flaphl\Element\Cache\Exception\CacheException;

/**
 * Registry for managing locks on cache operations.
 * 
 * This class provides a locking mechanism to prevent race conditions
 * when multiple processes access the same cache keys simultaneously.
 * It supports different locking strategies and timeout handling.
 */
class LockerRegistry
{
    private const LOCK_PREFIX = '__lock__';
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_RETRY_DELAY = 100000; // 100ms in microseconds

    private array $locks = [];
    private array $config;
    private string $lockDirectory;
    private int $processId;

    /**
     * Create a new locker registry.
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'lock_directory' => sys_get_temp_dir() . '/flaphl_cache_locks',
            'default_timeout' => self::DEFAULT_TIMEOUT,
            'retry_delay' => self::DEFAULT_RETRY_DELAY,
            'max_retries' => 100,
            'enable_file_locks' => true,
            'enable_memory_locks' => true,
            'cleanup_interval' => 300, // 5 minutes
            'auto_cleanup' => true,
        ], $config);

        $this->lockDirectory = rtrim($this->config['lock_directory'], DIRECTORY_SEPARATOR);
        $this->processId = getmypid();

        if ($this->config['enable_file_locks']) {
            $this->ensureLockDirectoryExists();
        }

        // Register shutdown function to release locks
        register_shutdown_function([$this, 'releaseAllLocks']);
    }

    /**
     * Acquire a lock for a cache key.
     *
     * @param string $key The cache key to lock
     * @param int|null $timeout Lock timeout in seconds
     * @param bool $blocking Whether to wait for the lock
     * @return bool True if lock was acquired, false otherwise
     * @throws CacheException If locking fails
     */
    public function acquireLock(string $key, ?int $timeout = null, bool $blocking = true): bool
    {
        $timeout ??= $this->config['default_timeout'];
        $lockKey = $this->getLockKey($key);

        // Check if we already hold this lock
        if (isset($this->locks[$lockKey])) {
            return true;
        }

        $startTime = microtime(true);
        $maxRetries = $blocking ? $this->config['max_retries'] : 1;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            // Try memory lock first
            if ($this->config['enable_memory_locks'] && $this->acquireMemoryLock($lockKey, $timeout)) {
                // Try file lock if enabled
                if ($this->config['enable_file_locks']) {
                    if ($this->acquireFileLock($lockKey, $timeout)) {
                        return true;
                    } else {
                        $this->releaseMemoryLock($lockKey);
                    }
                } else {
                    return true;
                }
            }

            // Check timeout
            if ((microtime(true) - $startTime) >= $timeout) {
                break;
            }

            if ($blocking && $retryCount < $maxRetries - 1) {
                usleep($this->config['retry_delay']);
            }

            $retryCount++;
        }

        return false;
    }

    /**
     * Release a lock for a cache key.
     *
     * @param string $key The cache key to unlock
     * @return bool True if lock was released, false otherwise
     */
    public function releaseLock(string $key): bool
    {
        $lockKey = $this->getLockKey($key);

        if (!isset($this->locks[$lockKey])) {
            return true; // Not locked by us
        }

        $success = true;

        // Release file lock
        if ($this->config['enable_file_locks']) {
            $success = $this->releaseFileLock($lockKey) && $success;
        }

        // Release memory lock
        if ($this->config['enable_memory_locks']) {
            $success = $this->releaseMemoryLock($lockKey) && $success;
        }

        return $success;
    }

    /**
     * Check if a cache key is currently locked.
     *
     * @param string $key The cache key to check
     * @return bool True if locked, false otherwise
     */
    public function isLocked(string $key): bool
    {
        $lockKey = $this->getLockKey($key);

        // Check memory lock
        if ($this->config['enable_memory_locks'] && isset($this->locks[$lockKey])) {
            return !$this->isLockExpired($this->locks[$lockKey]);
        }

        // Check file lock
        if ($this->config['enable_file_locks']) {
            return $this->isFileLocked($lockKey);
        }

        return false;
    }

    /**
     * Get information about a lock.
     *
     * @param string $key The cache key
     * @return array|null Lock information or null if not locked
     */
    public function getLockInfo(string $key): ?array
    {
        $lockKey = $this->getLockKey($key);

        if (!$this->isLocked($key)) {
            return null;
        }

        $info = [
            'key' => $key,
            'lock_key' => $lockKey,
            'locked_by_us' => isset($this->locks[$lockKey]),
            'process_id' => null,
            'acquired_at' => null,
            'expires_at' => null,
        ];

        if (isset($this->locks[$lockKey])) {
            $lock = $this->locks[$lockKey];
            $info['process_id'] = $this->processId;
            $info['acquired_at'] = $lock['acquired_at'];
            $info['expires_at'] = $lock['expires_at'];
        }

        return $info;
    }

    /**
     * Execute a callback with a lock on the given key.
     *
     * @param string $key The cache key to lock
     * @param callable $callback The callback to execute
     * @param int|null $timeout Lock timeout in seconds
     * @return mixed The callback result
     * @throws CacheException If lock cannot be acquired
     */
    public function withLock(string $key, callable $callback, ?int $timeout = null): mixed
    {
        if (!$this->acquireLock($key, $timeout)) {
            throw CacheException::forOperation(
                'lock',
                'Could not acquire lock for key: ' . $key
            );
        }

        try {
            return $callback();
        } finally {
            $this->releaseLock($key);
        }
    }

    /**
     * Release all locks held by this process.
     *
     * @return bool True if all locks were released successfully
     */
    public function releaseAllLocks(): bool
    {
        $success = true;

        foreach (array_keys($this->locks) as $lockKey) {
            $key = $this->getKeyFromLockKey($lockKey);
            if (!$this->releaseLock($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Clean up expired locks.
     *
     * @return int Number of locks cleaned up
     */
    public function cleanup(): int
    {
        $cleaned = 0;

        // Clean up memory locks
        foreach ($this->locks as $lockKey => $lock) {
            if ($this->isLockExpired($lock)) {
                unset($this->locks[$lockKey]);
                $cleaned++;
            }
        }

        // Clean up file locks
        if ($this->config['enable_file_locks']) {
            $cleaned += $this->cleanupFileLocks();
        }

        return $cleaned;
    }

    /**
     * Get statistics about the locker registry.
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        return [
            'active_locks' => count($this->locks),
            'process_id' => $this->processId,
            'config' => $this->config,
            'lock_directory' => $this->lockDirectory,
            'memory_locks_enabled' => $this->config['enable_memory_locks'],
            'file_locks_enabled' => $this->config['enable_file_locks'],
        ];
    }

    /**
     * Get the lock key for a cache key.
     *
     * @param string $key The cache key
     * @return string The lock key
     */
    private function getLockKey(string $key): string
    {
        return self::LOCK_PREFIX . hash('xxh128', $key);
    }

    /**
     * Get the original key from a lock key.
     *
     * @param string $lockKey The lock key
     * @return string The original key
     */
    private function getKeyFromLockKey(string $lockKey): string
    {
        // Since we hash the key, we need to maintain a reverse mapping
        // For now, we'll use the lock key as the key (implementation detail)
        return str_replace(self::LOCK_PREFIX, '', $lockKey);
    }

    /**
     * Acquire a memory-based lock.
     *
     * @param string $lockKey The lock key
     * @param int $timeout Lock timeout in seconds
     * @return bool True if acquired
     */
    private function acquireMemoryLock(string $lockKey, int $timeout): bool
    {
        $now = time();
        $expiresAt = $now + $timeout;

        // Check if lock exists and is still valid
        if (isset($this->locks[$lockKey])) {
            if ($this->isLockExpired($this->locks[$lockKey])) {
                unset($this->locks[$lockKey]);
            } else {
                return false; // Lock still valid
            }
        }

        $this->locks[$lockKey] = [
            'acquired_at' => $now,
            'expires_at' => $expiresAt,
            'process_id' => $this->processId,
        ];

        return true;
    }

    /**
     * Release a memory-based lock.
     *
     * @param string $lockKey The lock key
     * @return bool True if released
     */
    private function releaseMemoryLock(string $lockKey): bool
    {
        unset($this->locks[$lockKey]);
        return true;
    }

    /**
     * Acquire a file-based lock.
     *
     * @param string $lockKey The lock key
     * @param int $timeout Lock timeout in seconds
     * @return bool True if acquired
     */
    private function acquireFileLock(string $lockKey, int $timeout): bool
    {
        $lockFile = $this->getLockFilePath($lockKey);
        $now = time();
        $expiresAt = $now + $timeout;

        // Create lock file
        $handle = fopen($lockFile, 'c+');
        if (!$handle) {
            return false;
        }

        // Try to acquire exclusive lock
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        // Write lock information
        $lockInfo = [
            'process_id' => $this->processId,
            'acquired_at' => $now,
            'expires_at' => $expiresAt,
        ];

        ftruncate($handle, 0);
        fwrite($handle, json_encode($lockInfo));
        fflush($handle);

        // Store the handle for later release
        $this->locks[$lockKey]['file_handle'] = $handle;

        return true;
    }

    /**
     * Release a file-based lock.
     *
     * @param string $lockKey The lock key
     * @return bool True if released
     */
    private function releaseFileLock(string $lockKey): bool
    {
        if (!isset($this->locks[$lockKey]['file_handle'])) {
            return true;
        }

        $handle = $this->locks[$lockKey]['file_handle'];
        $lockFile = $this->getLockFilePath($lockKey);

        // Release the lock and close the file
        flock($handle, LOCK_UN);
        fclose($handle);

        // Remove the lock file
        @unlink($lockFile);

        unset($this->locks[$lockKey]['file_handle']);

        return true;
    }

    /**
     * Check if a file lock exists.
     *
     * @param string $lockKey The lock key
     * @return bool True if file is locked
     */
    private function isFileLocked(string $lockKey): bool
    {
        $lockFile = $this->getLockFilePath($lockKey);

        if (!file_exists($lockFile)) {
            return false;
        }

        // Try to read lock info
        $content = @file_get_contents($lockFile);
        if (!$content) {
            return false;
        }

        $lockInfo = json_decode($content, true);
        if (!$lockInfo) {
            return false;
        }

        // Check if lock has expired
        if (isset($lockInfo['expires_at']) && $lockInfo['expires_at'] <= time()) {
            @unlink($lockFile);
            return false;
        }

        return true;
    }

    /**
     * Get the file path for a lock.
     *
     * @param string $lockKey The lock key
     * @return string The file path
     */
    private function getLockFilePath(string $lockKey): string
    {
        return $this->lockDirectory . DIRECTORY_SEPARATOR . $lockKey . '.lock';
    }

    /**
     * Check if a lock has expired.
     *
     * @param array $lock The lock data
     * @return bool True if expired
     */
    private function isLockExpired(array $lock): bool
    {
        return isset($lock['expires_at']) && $lock['expires_at'] <= time();
    }

    /**
     * Clean up expired file locks.
     *
     * @return int Number of locks cleaned up
     */
    private function cleanupFileLocks(): int
    {
        if (!is_dir($this->lockDirectory)) {
            return 0;
        }

        $cleaned = 0;
        $files = glob($this->lockDirectory . '/*.lock');

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (!$content) {
                @unlink($file);
                $cleaned++;
                continue;
            }

            $lockInfo = json_decode($content, true);
            if (!$lockInfo) {
                @unlink($file);
                $cleaned++;
                continue;
            }

            // Check if lock has expired
            if (isset($lockInfo['expires_at']) && $lockInfo['expires_at'] <= time()) {
                @unlink($file);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Ensure the lock directory exists.
     *
     * @throws CacheException If directory creation fails
     */
    private function ensureLockDirectoryExists(): void
    {
        if (is_dir($this->lockDirectory)) {
            return;
        }

        if (!mkdir($this->lockDirectory, 0755, true) && !is_dir($this->lockDirectory)) {
            throw CacheException::forOperation(
                'mkdir',
                'Failed to create lock directory: ' . $this->lockDirectory
            );
        }
    }
}
