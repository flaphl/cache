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

use DateInterval;
use Flaphl\Element\Cache\Exception\InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgumentException;

/**
 * PSR-16 Simple Cache adapter that bridges PSR-6 cache pools.
 * 
 * This adapter provides PSR-16 Simple Cache interface implementation
 * while leveraging any PSR-6 cache pool as the underlying storage.
 * It handles the conversion between PSR-16's simple key-value API
 * and PSR-6's more complex cache item interface.
 * 
 * Features:
 * - Full PSR-16 compliance
 * - Bridges any PSR-6 cache pool
 * - TTL handling with multiple formats
 * - Batch operations with atomic behavior
 * - Key validation and normalization
 * 
 * @author Jade Phyressi <jade@flaphl.com>
 */
class Psr16Adapter implements SimpleCacheInterface
{
    private CacheItemPoolInterface $pool;
    
    /**
     * Characters that are reserved and cannot be used in cache keys.
     */
    private const RESERVED_CHARACTERS = '{}()/\@:';
    
    /**
     * Maximum length for cache keys as per PSR-16.
     */
    private const MAX_KEY_LENGTH = 64;
    
    public function __construct(CacheItemPoolInterface $pool)
    {
        $this->pool = $pool;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        
        $item = $this->pool->getItem($key);
        
        if (!$item->isHit()) {
            return $default;
        }
        
        return $item->get();
    }
    
    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        
        $item = $this->pool->getItem($key);
        $item->set($value);
        
        if ($ttl !== null) {
            $item->expiresAfter($ttl);
        }
        
        return $this->pool->save($item);
    }
    
    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        
        return $this->pool->deleteItem($key);
    }
    
    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return $this->pool->clear();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = $this->validateKeys($keys);
        
        if (empty($keys)) {
            return [];
        }
        
        $items = $this->pool->getItems($keys);
        $result = [];
        
        foreach ($keys as $key) {
            if (isset($items[$key]) && $items[$key]->isHit()) {
                $result[$key] = $items[$key]->get();
            } else {
                $result[$key] = $default;
            }
        }
        
        return $result;
    }
    
    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $items = [];
        $success = true;
        
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new class('Cache key must be a string.') extends InvalidArgumentException implements SimpleCacheInvalidArgumentException {};
            }
            
            $this->validateKey($key);
            
            $item = $this->pool->getItem($key);
            $item->set($value);
            
            if ($ttl !== null) {
                $item->expiresAfter($ttl);
            }
            
            $items[] = $item;
        }
        
        // Save all items atomically
        foreach ($items as $item) {
            if (!$this->pool->save($item)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = $this->validateKeys($keys);
        
        if (empty($keys)) {
            return true;
        }
        
        return $this->pool->deleteItems($keys);
    }
    
    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);
        
        return $this->pool->hasItem($key);
    }
    
    /**
     * Validate a single cache key according to PSR-16 rules.
     * 
     * @param string $key The key to validate
     * @throws SimpleCacheInvalidArgumentException If the key is invalid
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new class('Cache key cannot be empty.') extends InvalidArgumentException implements SimpleCacheInvalidArgumentException {};
        }
        
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new class(
                sprintf('Cache key length cannot exceed %d characters. Given: %d', 
                    self::MAX_KEY_LENGTH, 
                    strlen($key)
                )
            ) extends InvalidArgumentException implements SimpleCacheInvalidArgumentException {};
        }
        
        if (strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            throw new class(
                sprintf('Cache key "%s" contains reserved characters. Reserved: %s', 
                    $key, 
                    self::RESERVED_CHARACTERS
                )
            ) extends InvalidArgumentException implements SimpleCacheInvalidArgumentException {};
        }
    }
    
    /**
     * Validate and normalize an iterable of cache keys.
     * 
     * @param iterable $keys The keys to validate
     * @return array Normalized array of valid keys
     * @throws SimpleCacheInvalidArgumentException If any key is invalid
     */
    private function validateKeys(iterable $keys): array
    {
        $normalizedKeys = [];
        
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new class('All cache keys must be strings.') extends InvalidArgumentException implements SimpleCacheInvalidArgumentException {};
            }
            
            $this->validateKey($key);
            $normalizedKeys[] = $key;
        }
        
        return $normalizedKeys;
    }
    
    /**
     * Get the underlying PSR-6 cache pool.
     * 
     * This method provides access to the underlying pool for advanced
     * operations that aren't available through the PSR-16 interface.
     * 
     * @return CacheItemPoolInterface The underlying cache pool
     */
    public function getPool(): CacheItemPoolInterface
    {
        return $this->pool;
    }
}