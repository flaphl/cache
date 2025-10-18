<?php

namespace Flaphl\Element\Cache\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Flaphl\Element\Cache\Adapter\ArrayAdapter;
use Flaphl\Element\Cache\Adapter\TagAwareAdapter;
use Flaphl\Element\Cache\CacheItem;
use Flaphl\Element\Cache\LockerRegistry;

/**
 * Integration tests for the complete cache system.
 *
 * @package Flaphl\Element\Cache\Tests\Integration
 */
class CacheSystemIntegrationTest extends TestCase
{
    private ArrayAdapter $adapter;
    private TagAwareAdapter $tagAwareAdapter;
    private LockerRegistry $locker;

    protected function setUp(): void
    {
        $this->adapter = new ArrayAdapter('integration_test', 1000);
        $tagAdapter = new ArrayAdapter('tags');
        $this->tagAwareAdapter = new TagAwareAdapter($this->adapter, $tagAdapter);
        $this->locker = new LockerRegistry();
    }

    protected function tearDown(): void
    {
        $this->adapter->clear();
        $this->locker->cleanup();
    }

    public function testBasicCacheWorkflow(): void
    {
        // Test basic cache operations
        $item = $this->adapter->getItem('basic.test');
        $this->assertFalse($item->isHit());

        $item->set('test value');
        $item->expiresAfter(3600);
        $this->assertTrue($this->adapter->save($item));

        // Retrieve and verify
        $retrievedItem = $this->adapter->getItem('basic.test');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('test value', $retrievedItem->get());
    }

    public function testTagAwareCaching(): void
    {
        // Save items with different tags
        $item1 = $this->tagAwareAdapter->getItem('product.1');
        $item1->set(['id' => 1, 'name' => 'Product 1']);
        $item1->tag(['products', 'category:electronics']);
        $this->tagAwareAdapter->save($item1);

        $item2 = $this->tagAwareAdapter->getItem('product.2');
        $item2->set(['id' => 2, 'name' => 'Product 2']);
        $item2->tag(['products', 'category:books']);
        $this->tagAwareAdapter->save($item2);

        $item3 = $this->tagAwareAdapter->getItem('user.1');
        $item3->set(['id' => 1, 'name' => 'User 1']);
        $item3->tag(['users']);
        $this->tagAwareAdapter->save($item3);

        // Verify all items exist
        $this->assertTrue($this->tagAwareAdapter->hasItem('product.1'));
        $this->assertTrue($this->tagAwareAdapter->hasItem('product.2'));
        $this->assertTrue($this->tagAwareAdapter->hasItem('user.1'));

        // Invalidate products tag
        $this->tagAwareAdapter->invalidateTags(['products']);

        // Product items should be gone
        $this->assertFalse($this->tagAwareAdapter->hasItem('product.1'));
        $this->assertFalse($this->tagAwareAdapter->hasItem('product.2'));

        // User item should remain
        $this->assertTrue($this->tagAwareAdapter->hasItem('user.1'));
    }

    public function testConcurrentAccess(): void
    {
        $key = 'concurrent.test';
        $lockKey = "cache.{$key}";

        // Test exclusive locking
        $this->assertTrue($this->locker->acquireLock($lockKey, 5, false));

        // Second lock attempt should fail (non-blocking)
        $this->assertFalse($this->locker->acquireLock($lockKey, 5, false));

        // Release lock
        $this->assertTrue($this->locker->releaseLock($lockKey));

        // Now second attempt should succeed
        $this->assertTrue($this->locker->acquireLock($lockKey, 5, false));
        $this->assertTrue($this->locker->releaseLock($lockKey));
    }

    public function testLockedCacheOperations(): void
    {
        $key = 'locked.operation';
        $lockKey = "cache.{$key}";

        // Perform cache operation with lock
        $result = $this->locker->withLock($lockKey, function() use ($key) {
            $item = $this->adapter->getItem($key);
            $item->set('locked value');
            $this->adapter->save($item);
            return 'operation completed';
        }, 5);

        $this->assertEquals('operation completed', $result);

        // Verify item was saved
        $retrievedItem = $this->adapter->getItem($key);
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('locked value', $retrievedItem->get());
    }

    public function testComplexDataStructures(): void
    {
        $complexData = [
            'user' => [
                'id' => 123,
                'profile' => [
                    'name' => 'John Doe',
                    'email' => 'john@example.com',
                    'preferences' => [
                        'theme' => 'dark',
                        'notifications' => true,
                        'languages' => ['en', 'es', 'fr']
                    ]
                ]
            ],
            'metadata' => [
                'created_at' => new \DateTimeImmutable(),
                'version' => '1.0.0'
            ]
        ];

        $item = $this->tagAwareAdapter->getItem('complex.data');
        $item->set($complexData);
        $item->tag(['users', 'profiles', 'version:1.0']);
        $this->tagAwareAdapter->save($item);

        // Retrieve and verify structure integrity
        $retrievedItem = $this->tagAwareAdapter->getItem('complex.data');
        $this->assertTrue($retrievedItem->isHit());
        
        $retrievedData = $retrievedItem->get();
        $this->assertEquals($complexData['user']['id'], $retrievedData['user']['id']);
        $this->assertEquals($complexData['user']['profile']['name'], $retrievedData['user']['profile']['name']);
        $this->assertEquals($complexData['user']['profile']['preferences'], $retrievedData['user']['profile']['preferences']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $retrievedData['metadata']['created_at']);
    }

    public function testCacheExpiration(): void
    {
        // Test immediate expiration
        $item = $this->adapter->getItem('expiring.immediate');
        $item->set('expires immediately');
        $item->expiresAfter(0);
        $this->adapter->save($item);

        // Should be expired immediately
        $this->assertFalse($this->adapter->hasItem('expiring.immediate'));

        // Test future expiration
        $item = $this->adapter->getItem('expiring.future');
        $item->set('expires in future');
        $item->expiresAfter(1); // 1 second
        $this->adapter->save($item);

        // Should exist now
        $this->assertTrue($this->adapter->hasItem('expiring.future'));

        // Wait for expiration
        sleep(2);

        // Should be expired now
        $this->assertFalse($this->adapter->hasItem('expiring.future'));
    }

    public function testDeferredSaving(): void
    {
        $items = [];
        
        // Create multiple deferred items
        for ($i = 1; $i <= 5; $i++) {
            $item = $this->adapter->getItem("deferred.{$i}");
            $item->set("deferred value {$i}");
            $this->adapter->saveDeferred($item);
            $items[] = $item;
        }

        // Items should not be immediately available
        foreach ($items as $i => $item) {
            $this->assertFalse($this->adapter->hasItem("deferred." . ($i + 1)));
        }

        // Commit all deferred items
        $this->assertTrue($this->adapter->commit());

        // Now all items should be available
        foreach ($items as $i => $item) {
            $this->assertTrue($this->adapter->hasItem("deferred." . ($i + 1)));
            $retrievedItem = $this->adapter->getItem("deferred." . ($i + 1));
            $this->assertEquals("deferred value " . ($i + 1), $retrievedItem->get());
        }
    }

    public function testNamespacedCaching(): void
    {
        $mainAdapter = $this->adapter;
        $namespacedAdapter = $mainAdapter->withNamespace('test_namespace');

        // Save item in main adapter
        $mainItem = $mainAdapter->getItem('shared.key');
        $mainItem->set('main value');
        $mainAdapter->save($mainItem);

        // Save item with same key in namespaced adapter
        $namespacedItem = $namespacedAdapter->getItem('shared.key');
        $namespacedItem->set('namespaced value');
        $namespacedAdapter->save($namespacedItem);

        // Both should exist independently
        $this->assertTrue($mainAdapter->hasItem('shared.key'));
        $this->assertTrue($namespacedAdapter->hasItem('shared.key'));

        // Values should be different
        $mainValue = $mainAdapter->getItem('shared.key')->get();
        $namespacedValue = $namespacedAdapter->getItem('shared.key')->get();

        $this->assertEquals('main value', $mainValue);
        $this->assertEquals('namespaced value', $namespacedValue);
    }

    public function testCacheStatistics(): void
    {
        // Generate some cache activity
        $this->adapter->getItem('miss1'); // miss
        $this->adapter->getItem('miss2'); // miss

        $item = $this->adapter->getItem('hit.test');
        $item->set('hit value');
        $this->adapter->save($item);

        $this->adapter->getItem('hit.test'); // hit
        $this->adapter->getItem('hit.test'); // hit

        $stats = $this->adapter->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('items', $stats);
        $this->assertArrayHasKey('hit_ratio', $stats);

        $this->assertGreaterThanOrEqual(2, $stats['hits']);
        $this->assertGreaterThanOrEqual(2, $stats['misses']);
        $this->assertGreaterThanOrEqual(1, $stats['items']);
    }

    public function testMemoryManagement(): void
    {
        $adapter = new ArrayAdapter('memory_test', 10, ['max_items' => 10]);

        // Fill cache to capacity
        for ($i = 1; $i <= 15; $i++) {
            $item = $adapter->getItem("item.{$i}");
            $item->set("value {$i}");
            $adapter->save($item);
        }

        $stats = $adapter->getStats();
        
        // Should not exceed max items
        $this->assertLessThanOrEqual(10, $stats['items']);

        // Some items should have been evicted
        $existingItems = 0;
        for ($i = 1; $i <= 15; $i++) {
            if ($adapter->hasItem("item.{$i}")) {
                $existingItems++;
            }
        }

        $this->assertLessThanOrEqual(10, $existingItems);
    }

    public function testErrorHandling(): void
    {
        // Test invalid key
        $this->expectException(\Flaphl\Element\Cache\Exception\InvalidArgumentException::class);
        $this->adapter->getItem('invalid{}key');
    }
}