<?php

namespace Flaphl\Element\Cache\Tests;

use PHPUnit\Framework\TestCase;
use Flaphl\Element\Cache\CacheItem;
use Flaphl\Contracts\Cache\ItemInterface;
use Psr\Cache\CacheItemInterface;

/**
 * Tests for CacheItem implementation.
 *
 * @package Flaphl\Element\Cache\Tests
 */
class CacheItemTest extends TestCase
{
    private CacheItem $item;

    protected function setUp(): void
    {
        $this->item = new CacheItem('test.key', true);
        $this->item->set('test value');
    }

    public function testImplementsInterfaces(): void
    {
        $this->assertInstanceOf(CacheItemInterface::class, $this->item);
        $this->assertInstanceOf(ItemInterface::class, $this->item);
    }

    public function testGetKey(): void
    {
        $this->assertEquals('test.key', $this->item->getKey());
    }

    public function testGetValue(): void
    {
        $this->assertEquals('test value', $this->item->get());
    }

    public function testIsHit(): void
    {
        $this->assertTrue($this->item->isHit());
        
        $missItem = new CacheItem('miss.key', false);
        $this->assertFalse($missItem->isHit());
    }

    public function testSetValue(): void
    {
        $this->item->set('new value');
        $this->assertEquals('new value', $this->item->get());
    }

    public function testExpiresAt(): void
    {
        $expiry = new \DateTimeImmutable('+1 hour');
        $this->item->expiresAt($expiry);
        
        $this->assertEquals($expiry, $this->item->getExpiry());
    }

    public function testExpiresAfter(): void
    {
        $interval = new \DateInterval('PT1H');
        $this->item->expiresAfter($interval);
        
        $expiry = $this->item->getExpiry();
        $this->assertInstanceOf(\DateTimeInterface::class, $expiry);
        
        // Check that expiry is approximately 1 hour from now
        $expected = new \DateTimeImmutable('+1 hour');
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $expiry->getTimestamp(),
            5 // 5 second tolerance
        );
    }

    public function testExpiresAfterWithInteger(): void
    {
        $this->item->expiresAfter(3600); // 1 hour in seconds
        
        $expiry = $this->item->getExpiry();
        $this->assertInstanceOf(\DateTimeInterface::class, $expiry);
        
        // Check that expiry is approximately 1 hour from now
        $expected = new \DateTimeImmutable('+1 hour');
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $expiry->getTimestamp(),
            5 // 5 second tolerance
        );
    }

    public function testTagging(): void
    {
        $this->item->tag('category1');
        $this->assertEquals(['category1'], $this->item->getTags());
        
        $this->item->tag(['category2', 'category3']);
        $this->assertEquals(['category1', 'category2', 'category3'], $this->item->getTags());
    }

    public function testGetMetadata(): void
    {
        $metadata = $this->item->getMetadata();
        
        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('created_at', $metadata);
        $this->assertArrayHasKey('tags', $metadata);
        $this->assertArrayHasKey('expiry', $metadata);
    }

    public function testIsExpired(): void
    {
        // Item without expiry should not be expired
        $this->assertFalse($this->item->isExpired());
        
        // Item with future expiry should not be expired
        $this->item->expiresAt(new \DateTimeImmutable('+1 hour'));
        $this->assertFalse($this->item->isExpired());
        
        // Item with past expiry should be expired
        $this->item->expiresAt(new \DateTimeImmutable('-1 hour'));
        $this->assertTrue($this->item->isExpired());
    }

    public function testSetMetadata(): void
    {
        $this->item->setMetadata('custom', 'value');
        
        $this->assertTrue($this->item->hasMetadata('custom'));
        $this->assertEquals('value', $this->item->getMetadataValue('custom'));
    }

    public function testHasValue(): void
    {
        $this->assertTrue($this->item->hasValue());
        
        $emptyItem = new CacheItem('empty.key');
        $this->assertFalse($emptyItem->hasValue());
    }

    public function testGetTtl(): void
    {
        // Item without expiry should return null TTL
        $this->assertNull($this->item->getTtl());
        
        // Item with future expiry should return positive TTL
        $this->item->expiresAfter(3600);
        $ttl = $this->item->getTtl();
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
    }

    public function testWithKey(): void
    {
        $newItem = $this->item->withKey('new.key');
        
        $this->assertEquals('new.key', $newItem->getKey());
        $this->assertEquals('test value', $newItem->get()); // Value should be preserved
        
        // Original item should be unchanged
        $this->assertEquals('test.key', $this->item->getKey());
    }

    public function testInvalidKeyValidation(): void
    {
        $this->expectException(\Flaphl\Element\Cache\Exception\InvalidArgumentException::class);
        new CacheItem('invalid{}key');
    }

    public function testInvalidTagValidation(): void
    {
        $this->expectException(\Flaphl\Element\Cache\Exception\InvalidArgumentException::class);
        $this->item->tag('invalid{}tag');
    }

    public function testClone(): void
    {
        $this->item->tag('original');
        $cloned = clone $this->item;
        
        $cloned->tag('cloned');
        
        // Original should only have 'original' tag
        $this->assertEquals(['original'], $this->item->getTags());
        // Cloned should have both tags
        $this->assertEquals(['original', 'cloned'], $cloned->getTags());
    }

    public function testToArray(): void
    {
        $this->item->tag(['tag1', 'tag2']);
        $this->item->expiresAt(new \DateTimeImmutable('+1 hour'));
        
        $array = $this->item->toArray();
        
        $this->assertIsArray($array);
        $this->assertEquals('test.key', $array['key']);
        $this->assertEquals('test value', $array['value']);
        $this->assertTrue($array['hit']);
        $this->assertEquals(['tag1', 'tag2'], $array['tags']);
        $this->assertArrayHasKey('expiry', $array);
        $this->assertArrayHasKey('metadata', $array);
    }

    public function testExpiresAfterWithZero(): void
    {
        $this->item->expiresAfter(0);
        $this->assertTrue($this->item->isExpired());
    }

    public function testExpiresAfterWithNegative(): void
    {
        $this->item->expiresAfter(-1);
        $this->assertTrue($this->item->isExpired());
    }

    public function testExpiresAfterWithNull(): void
    {
        $this->item->expiresAfter(3600);
        $this->assertNotNull($this->item->getExpiry());
        
        $this->item->expiresAfter(null);
        $this->assertNull($this->item->getExpiry());
    }
}