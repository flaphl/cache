<?php

namespace Flaphl\Element\Cache\Tests\Adapter;

use PHPUnit\Framework\TestCase;
use Flaphl\Element\Cache\Adapter\TagAwareAdapter;
use Flaphl\Element\Cache\Adapter\ArrayAdapter;
use Flaphl\Element\Cache\Adapter\TagAwareAdapterInterface;
use Flaphl\Element\Cache\CacheItem;

/**
 * Tests for TagAwareAdapter implementation.
 *
 * @package Flaphl\Element\Cache\Tests\Adapter
 */
class TagAwareAdapterTest extends TestCase
{
    private TagAwareAdapter $adapter;
    private ArrayAdapter $mainAdapter;
    private ArrayAdapter $tagAdapter;

    protected function setUp(): void
    {
        $this->mainAdapter = new ArrayAdapter('main');
        $this->tagAdapter = new ArrayAdapter('tags');
        
        $this->adapter = new TagAwareAdapter(
            $this->mainAdapter,
            $this->tagAdapter,
            [
                'tag_prefix' => '__tag__',
                'max_tags_per_item' => 100,
                'enable_tag_stats' => true,
            ]
        );
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(TagAwareAdapterInterface::class, $this->adapter);
    }

    public function testSaveItemWithTags(): void
    {
        $item = $this->adapter->getItem('tagged.item');
        $item->set('tagged value');
        $item->tag(['category', 'priority:high']);
        
        $this->assertTrue($this->adapter->save($item));
        
        // Verify item was saved
        $retrievedItem = $this->adapter->getItem('tagged.item');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals('tagged value', $retrievedItem->get());
        $this->assertEquals(['category', 'priority:high'], $retrievedItem->getTags());
    }

    public function testInvalidateTags(): void
    {
        // Save multiple items with different tags
        $item1 = $this->adapter->getItem('item1');
        $item1->set('value1');
        $item1->tag(['category', 'urgent']);
        $this->adapter->save($item1);
        
        $item2 = $this->adapter->getItem('item2');
        $item2->set('value2');
        $item2->tag(['category', 'normal']);
        $this->adapter->save($item2);
        
        $item3 = $this->adapter->getItem('item3');
        $item3->set('value3');
        $item3->tag(['other']);
        $this->adapter->save($item3);
        
        // Invalidate 'category' tag
        $this->assertTrue($this->adapter->invalidateTags(['category']));
        
        // Items with 'category' tag should be gone
        $this->assertFalse($this->adapter->hasItem('item1'));
        $this->assertFalse($this->adapter->hasItem('item2'));
        
        // Item with only 'other' tag should remain
        $this->assertTrue($this->adapter->hasItem('item3'));
    }

    public function testInvalidateTagsString(): void
    {
        $item = $this->adapter->getItem('tagged.item');
        $item->set('tagged value');
        $item->tag(['category']);
        $this->adapter->save($item);
        
        // Invalidate using string instead of array
        $this->assertTrue($this->adapter->invalidateTags('category'));
        
        $this->assertFalse($this->adapter->hasItem('tagged.item'));
    }

    public function testGetTaggedItems(): void
    {
        // Save items with tags
        $item1 = $this->adapter->getItem('item1');
        $item1->set('value1');
        $item1->tag(['category']);
        $this->adapter->save($item1);
        
        $item2 = $this->adapter->getItem('item2');
        $item2->set('value2');
        $item2->tag(['category', 'priority']);
        $this->adapter->save($item2);
        
        $item3 = $this->adapter->getItem('item3');
        $item3->set('value3');
        $item3->tag(['other']);
        $this->adapter->save($item3);
        
        $taggedItems = $this->adapter->getItemsByTags(['category']);
        
        $this->assertCount(2, $taggedItems);
        $this->assertArrayHasKey('item1', $taggedItems);
        $this->assertArrayHasKey('item2', $taggedItems);
        $this->assertArrayNotHasKey('item3', $taggedItems);
    }

    public function testGetTagStats(): void
    {
        // Save items with tags
        $item1 = $this->adapter->getItem('item1');
        $item1->set('value1');
        $item1->tag(['category', 'priority']);
        $this->adapter->save($item1);
        
        $item2 = $this->adapter->getItem('item2');
        $item2->set('value2');
        $item2->tag(['category']);
        $this->adapter->save($item2);
        
        $stats = $this->adapter->getTagStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('category', $stats);
        $this->assertArrayHasKey('priority', $stats);
        $this->assertEquals(2, $stats['category']); // Used by 2 items
        $this->assertEquals(1, $stats['priority']); // Used by 1 item
    }

    public function testPruneOrphanedTags(): void
    {
        // Save item with tags
        $item = $this->adapter->getItem('item1');
        $item->set('value1');
        $item->tag(['category']);
        $this->adapter->save($item);
        
        // Delete the item but leave the tag index
        $this->adapter->deleteItem('item1');
        
        // Prune orphaned tags
        $pruned = $this->adapter->pruneOrphanedTags();
        
        $this->assertGreaterThan(0, $pruned);
        
        // Tag stats should be clean
        $stats = $this->adapter->getTagStats();
        $this->assertEmpty($stats);
    }

    public function testClearTagsRemovesTagIndex(): void
    {
        // Save item with tags
        $item = $this->adapter->getItem('item1');
        $item->set('value1');
        $item->tag(['category']);
        $this->adapter->save($item);
        
        $this->adapter->clear();
        
        // Tag stats should be empty after clear
        $stats = $this->adapter->getTagStats();
        $this->assertEmpty($stats);
    }

    public function testMaxTagsPerItemLimit(): void
    {
        $adapter = new TagAwareAdapter(
            $this->mainAdapter,
            $this->tagAdapter,
            ['max_tags_per_item' => 2]
        );
        
        $item = $adapter->getItem('limited.item');
        $item->set('value');
        $item->tag(['tag1', 'tag2', 'tag3', 'tag4']); // 4 tags, but limit is 2
        
        $adapter->save($item);
        
        $retrievedItem = $adapter->getItem('limited.item');
        $tags = $retrievedItem->getTags();
        
        // Should only have 2 tags (first 2)
        $this->assertCount(2, $tags);
        $this->assertEquals(['tag1', 'tag2'], $tags);
    }

    public function testGetConfiguration(): void
    {
        $config = $this->adapter->getConfiguration();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('tag_prefix', $config);
        $this->assertArrayHasKey('max_tags_per_item', $config);
        $this->assertArrayHasKey('enable_tag_stats', $config);
        $this->assertEquals('__tag__', $config['tag_prefix']);
        $this->assertEquals(100, $config['max_tags_per_item']);
        $this->assertTrue($config['enable_tag_stats']);
    }

    public function testSupportsTagAwareFeatures(): void
    {
        $this->assertTrue($this->adapter->supports('tagging'));
        $this->assertTrue($this->adapter->supports('tag_invalidation'));
        $this->assertTrue($this->adapter->supports('tag_stats'));
    }

    public function testTagAwareGetStats(): void
    {
        // Save item with tags
        $item = $this->adapter->getItem('item1');
        $item->set('value1');
        $item->tag(['category']);
        $this->adapter->save($item);
        
        $stats = $this->adapter->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('tag_count', $stats);
        $this->assertArrayHasKey('tagged_items', $stats);
        $this->assertGreaterThan(0, $stats['tag_count']);
        $this->assertGreaterThan(0, $stats['tagged_items']);
    }

    public function testWithNamespacePreservesTagging(): void
    {
        $namespacedAdapter = $this->adapter->withNamespace('sub');
        
        $item = $namespacedAdapter->getItem('item1');
        $item->set('value1');
        $item->tag(['category']);
        $namespacedAdapter->save($item);
        
        // Should be able to invalidate tags in namespaced adapter
        $this->assertTrue($namespacedAdapter->invalidateTags(['category']));
        $this->assertFalse($namespacedAdapter->hasItem('item1'));
    }

    public function testDeferredItemsWithTags(): void
    {
        $item = $this->adapter->getItem('deferred.item');
        $item->set('deferred value');
        $item->tag(['deferred']);
        
        $this->adapter->saveDeferred($item);
        
        // Item should not be immediately available
        $this->assertFalse($this->adapter->hasItem('deferred.item'));
        
        // Commit deferred items
        $this->adapter->commit();
        
        // Now item should be available with tags
        $retrievedItem = $this->adapter->getItem('deferred.item');
        $this->assertTrue($retrievedItem->isHit());
        $this->assertEquals(['deferred'], $retrievedItem->getTags());
    }
}