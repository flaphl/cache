<?php

/**
 * This file is part of the Flaphl package.
 * 
 * (c) Jade Phyressi <jade@flaphl.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flaphl\Element\Cache;

use Flaphl\Contracts\Cache\ItemInterface;
use Flaphl\Element\Cache\Exception\InvalidArgumentException;
use Psr\Cache\CacheItemInterface;

/**
 * PSR-6 compliant cache item implementation with Flaphl enhancements.
 * 
 * This implementation provides standard PSR-6 functionality plus additional
 * features like tagging, metadata, and enhanced expiration handling.
 */
class CacheItem implements CacheItemInterface, ItemInterface
{
    private string $key;
    private mixed $value = null;
    private bool $isHit = false;
    private ?\DateTimeInterface $expiry = null;
    private array $tags = [];
    private array $metadata = [];
    private bool $hasValue = false;

    /**
     * Create a new cache item.
     *
     * @param string $key The cache key
     * @param bool $isHit Whether this item was found in cache
     */
    public function __construct(string $key, bool $isHit = false)
    {
        $this->validateKey($key);
        $this->key = $key;
        $this->isHit = $isHit;
    }

    /**
     * {@inheritdoc}
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): mixed
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function isHit(): bool
    {
        return $this->isHit;
    }

    /**
     * {@inheritdoc}
     */
    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hasValue = true;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->expiry = $expiration;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAfter(\DateInterval|int|null $time): static
    {
        if (null === $time) {
            $this->expiry = null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiry = (new \DateTimeImmutable())->add($time);
        } elseif (is_int($time)) {
            if ($time <= 0) {
                $this->expiry = new \DateTimeImmutable('@1'); // Expired in the past
            } else {
                $this->expiry = new \DateTimeImmutable('@' . (time() + $time));
            }
        } else {
            throw InvalidArgumentException::forInvalidTtl($time);
        }

        return $this;
    }

    /**
     * Check if the item has expired.
     *
     * @return bool True if expired, false otherwise
     */
    public function isExpired(): bool
    {
        if (null === $this->expiry) {
            return false;
        }

        return $this->expiry <= new \DateTimeImmutable();
    }

    /**
     * Get the expiration time.
     *
     * @return \DateTimeInterface|null The expiration time or null if never expires
     */
    public function getExpiry(): ?\DateTimeInterface
    {
        return $this->expiry;
    }

    /**
     * {@inheritdoc}
     */
    public function tag(string|iterable $tags): static
    {
        if (is_string($tags)) {
            $tags = [$tags];
        }
        
        foreach ($tags as $tag) {
            if (!is_string($tag) || '' === $tag) {
                throw InvalidArgumentException::forInvalidTag($tag);
            }
            
            // Validate tag format - allow alphanumeric, underscore, dash, dot, colon
            if (strlen($tag) > 250) {
                throw InvalidArgumentException::forInvalidTag($tag);
            }
            
            if (preg_match('/[{}()\\/\\\\@]/', $tag)) {
                throw InvalidArgumentException::forInvalidTag($tag);
            }
            
            $this->tags[] = $tag;
        }
        
        $this->tags = array_unique($this->tags);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata(string $key, mixed $value): static
    {
        if ('' === $key) {
            throw InvalidArgumentException::forEmptyParameter('metadata key');
        }
        
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(): array
    {
        return array_merge($this->metadata, [
            'created_at' => $this->metadata[ItemInterface::METADATA_CTIME] ?? time(),
            'expiry' => $this->expiry?->getTimestamp(),
            'tags' => $this->tags,
            'owner' => $this->metadata[ItemInterface::METADATA_OWNER] ?? null,
        ]);
    }

    /**
     * Get a specific metadata value.
     *
     * @param string $key The metadata key
     * @return mixed The metadata value or null if not found
     */
    public function getMetadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * Check if the item has a value set (even if null).
     *
     * @return bool True if a value has been set
     */
    public function hasValue(): bool
    {
        return $this->hasValue;
    }

    /**
     * Get the time remaining until expiration.
     *
     * @return int|null Seconds until expiration, null if never expires, 0 if expired
     */
    public function getTtl(): ?int
    {
        if (null === $this->expiry) {
            return null;
        }

        $ttl = $this->expiry->getTimestamp() - time();
        return max(0, $ttl);
    }

    /**
     * Create a copy of this item with a new key.
     *
     * @param string $key The new key
     * @return static A new item with the same data but different key
     */
    public function withKey(string $key): static
    {
        $new = new static($key, $this->isHit);
        $new->value = $this->value;
        $new->expiry = $this->expiry;
        $new->tags = $this->tags;
        $new->metadata = $this->metadata;
        $new->hasValue = $this->hasValue;
        
        return $new;
    }

    /**
     * Convert the item to an array representation.
     *
     * @return array The item data as array
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'hit' => $this->isHit,
            'expiry' => $this->expiry?->format(\DateTimeInterface::ATOM),
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'hasValue' => $this->hasValue,
        ];
    }

    /**
     * Create an item from array data.
     *
     * @param array $data The item data
     * @return static A new cache item
     */
    public static function fromArray(array $data): static
    {
        $item = new static($data['key'], $data['isHit'] ?? false);
        
        if (array_key_exists('value', $data)) {
            $item->set($data['value']);
        }
        
        if (!empty($data['expiry'])) {
            $item->expiresAt(new \DateTimeImmutable($data['expiry']));
        }
        
        if (!empty($data['tags'])) {
            $item->tag($data['tags']);
        }
        
        if (!empty($data['metadata'])) {
            foreach ($data['metadata'] as $key => $value) {
                $item->setMetadata($key, $value);
            }
        }
        
        return $item;
    }

    /**
     * Validate a cache key according to PSR-6 requirements.
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

        // Check for reserved characters: {}()/\@:
        if (preg_match('/[{}()\\/\\\\@:]/', $key)) {
            throw InvalidArgumentException::forInvalidKey($key, 'Key contains reserved characters');
        }
    }
}
