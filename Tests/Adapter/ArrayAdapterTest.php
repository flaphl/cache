<?php

namespace Flaphl\Element\Cache\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Flaphl\Element\Cache\Adapter\ArrayAdapter;
use Flaphl\Element\Cache\Adapter\AdapterInterface;
use Flaphl\Element\Cache\CacheItem;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Tests for ArrayAdapter implementation.
 *
 * @package Flaphl\Element\Cache\Tests\Adapter
 */
class ArrayAdapterTest extends TestCase
{
    private ArrayAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ArrayAdapter('test', 100, [
            'max_items' => 100,
            'enable_expiration' => true,
        ]);
    }

    public function testImplementsInterfaces(): void
    {
        $this->assertInstanceOf(CacheItemPoolInterface::class, $this->adapter);
        $this->assertInstanceOf(AdapterInterface::class, $this->adapter);
    }

    public function testGetItem(): void
    {
        $item = $this->adapter->getItem('test.key');
        
        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertEquals('test.key', $item->getKey());
        $this->assertFalse($item->isHit());
    }

    public function testGetItems(): void
    {
        $items = $this->adapter->getItems(['key1', 'key2', 'key3']);
        
        $this->assertCount(3, $items);
        $this->assertArrayHasKey('key1', $items);
        $this->assertArrayHasKey('key2', $items);
        $this->assertArrayHasKey('key3', $items);
        
        foreach ($items as $item) {
            $this->assertInstanceOf(CacheItem::class, $item);
            $this->assertFalse($item->isHit());
        }
    }

    public function testHasItem(): void
    {
        $this->assertFalse($this->adapter->hasItem('nonexistent'));
        
        // Save an item and test it exists
        $item = $this->adapter->getItem('test.key');
        $item->set('test value');
        $this->adapter->save($item);
        
        $this->assertTrue($this->adapter->hasItem('test.key'));
    }

    public function testSaveAndRetrieve(): void
    {
        $item = $this->adapter->getItem('test.key');
        $item->set('test value');
        
        $this->assertTrue($this->adapter->save($item));
        
        // Retrieve the item
        $retrievedItem = $this->adapter->getItem('test.key');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('test value', $retrievedItem->get());
    }

    public function testSaveMultiple(): void
    {
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $item = $this->adapter->getItem("key{$i}");
            $item->set("value{$i}");
            $items[] = $item;
        }
        
        // Save items individually since saveMultiple doesn't exist
        foreach ($items as $item) {
            $this->assertTrue($this->adapter->save($item));
        }
        
        // Verify all items were saved
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($this->adapter->hasItem("key{$i}"));
        }
    }

    public function testDeleteItem(): void
    {
        // Save an item first
        $item = $this->adapter->getItem('test.key');
        $item->set('test value');
        $this->adapter->save($item);
        
        $this->assertTrue($this->adapter->hasItem('test.key'));
        $this->assertTrue($this->adapter->deleteItem('test.key'));
        $this->assertFalse($this->adapter->hasItem('test.key'));
    }

    public function testDeleteItems(): void
    {
        // Save multiple items
        for ($i = 1; $i <= 3; $i++) {
            $item = $this->adapter->getItem("key{$i}");
            $item->set("value{$i}");
            $this->adapter->save($item);
        }
        
        $this->assertTrue($this->adapter->deleteItems(['key1', 'key3']));
        
        $this->assertFalse($this->adapter->hasItem('key1'));
        $this->assertTrue($this->adapter->hasItem('key2')); // Should still exist
        $this->assertFalse($this->adapter->hasItem('key3'));
    }

    public function testClear(): void
    {
        // Save multiple items
        for ($i = 1; $i <= 3; $i++) {
            $item = $this->adapter->getItem("key{$i}");
            $item->set("value{$i}");
            $this->adapter->save($item);
        }
        
        $this->assertTrue($this->adapter->clear());
        
        // All items should be gone
        for ($i = 1; $i <= 3; $i++) {
            $this->assertFalse($this->adapter->hasItem("key{$i}"));
        }
    }

    public function testExpiration(): void
    {
        $item = $this->adapter->getItem('expiring.key');
        $item->set('expiring value');
        $item->expiresAfter(1); // 1 second
        
        $this->adapter->save($item);
        $this->assertTrue($this->adapter->hasItem('expiring.key'));
        
        // Wait for expiration
        sleep(2);
        
        $this->assertFalse($this->adapter->hasItem('expiring.key'));
    }

    public function testMaxItemsLimit(): void
    {
        $adapter = new ArrayAdapter('test', 3, ['max_items' => 3]);
        
        // Add 5 items to a cache with max 3 items
        for ($i = 1; $i <= 5; $i++) {
            $item = $adapter->getItem("key{$i}");
            $item->set("value{$i}");
            $adapter->save($item);
        }
        
        $stats = $adapter->getStats();
        $this->assertLessThanOrEqual(3, $stats['items']);
    }

    public function testGetStats(): void
    {
        $stats = $this->adapter->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('items', $stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('memory_usage', $stats);
        $this->assertArrayHasKey('hit_ratio', $stats);
    }

    public function testOptimize(): void
    {
        // Add some expired items
        $expiredItem = $this->adapter->getItem('expired.key');
        $expiredItem->set('expired value');
        $expiredItem->expiresAt(new \DateTimeImmutable('-1 hour'));
        $this->adapter->save($expiredItem);
        
        // Add a valid item
        $validItem = $this->adapter->getItem('valid.key');
        $validItem->set('valid value');
        $this->adapter->save($validItem);
        
        $this->adapter->optimize();
        
        // Only valid item should remain
        $this->assertFalse($this->adapter->hasItem('expired.key'));
        $this->assertTrue($this->adapter->hasItem('valid.key'));
    }

    public function testWithNamespace(): void
    {
        $namespacedAdapter = $this->adapter->withNamespace('sub');
        
        $item = $namespacedAdapter->getItem('test.key');
        $item->set('namespaced value');
        $namespacedAdapter->save($item);
        
        // Original adapter should not see the namespaced item
        $this->assertFalse($this->adapter->hasItem('test.key'));
        
        // Namespaced adapter should see it
        $this->assertTrue($namespacedAdapter->hasItem('test.key'));
    }

    public function testInvalidKeyException(): void
    {
        $this->expectException(\Flaphl\Element\Cache\Exception\InvalidArgumentException::class);
        $this->adapter->getItem('invalid{}key');
    }

    public function testSaveDeferred(): void
    {
        $item = $this->adapter->getItem('deferred.key');
        $item->set('deferred value');
        
        $this->assertTrue($this->adapter->saveDeferred($item));
        
        // Item should not be immediately available
        $this->assertFalse($this->adapter->hasItem('deferred.key'));
        
        // Commit deferred items
        $this->assertTrue($this->adapter->commit());
        
        // Now item should be available
        $this->assertTrue($this->adapter->hasItem('deferred.key'));
    }

    public function testResetStats(): void
    {
        // Generate some stats
        $this->adapter->getItem('test1');
        $this->adapter->getItem('test2');
        
        $stats = $this->adapter->getStats();
        $this->assertGreaterThan(0, $stats['misses']);
        
        $this->adapter->reset();
        
        $newStats = $this->adapter->getStats();
        $this->assertEquals(0, $newStats['hits']);
        $this->assertEquals(0, $newStats['misses']);
    }

    public function testPrune(): void
    {
        // Add some expired items
        $expiredItem = $this->adapter->getItem('expired.key');
        $expiredItem->set('expired value');
        $expiredItem->expiresAt(new \DateTimeImmutable('-1 hour'));
        $this->adapter->save($expiredItem);
        
        $validItem = $this->adapter->getItem('valid.key');
        $validItem->set('valid value');
        $this->adapter->save($validItem);
        
        $pruned = $this->adapter->prune();
        $this->assertGreaterThan(0, $pruned);
        
        // Expired item should be gone
        $this->assertFalse($this->adapter->hasItem('expired.key'));
        $this->assertTrue($this->adapter->hasItem('valid.key'));
    }
}