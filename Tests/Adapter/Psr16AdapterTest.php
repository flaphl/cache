<?php

/**
 * This file is part of the Flaphl package.
 * 
 * (c) Jade Phyressi <jade@flaphl.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flaphl\Element\Cache\Tests\Adapter;

use DateInterval;
use Flaphl\Element\Cache\Adapter\ArrayAdapter;
use Flaphl\Element\Cache\Adapter\Psr16Adapter;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Test case for PSR-16 Simple Cache adapter.
 * 
 * Tests the PSR-16 adapter implementation to ensure compliance
 * with the Simple Cache interface specifications.
 */
class Psr16AdapterTest extends TestCase
{
    private Psr16Adapter $cache;
    
    protected function setUp(): void
    {
        $pool = new ArrayAdapter();
        $this->cache = new Psr16Adapter($pool);
    }
    
    protected function tearDown(): void
    {
        $this->cache->clear();
    }
    
    public function testBasicGetSet(): void
    {
        $this->assertTrue($this->cache->set('key1', 'value1'));
        $this->assertEquals('value1', $this->cache->get('key1'));
    }
    
    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', $this->cache->get('nonexistent', 'default'));
        $this->assertNull($this->cache->get('nonexistent'));
    }
    
    public function testSetWithTtl(): void
    {
        // Test with integer TTL
        $this->assertTrue($this->cache->set('key1', 'value1', 3600));
        $this->assertTrue($this->cache->has('key1'));
        
        // Test with DateInterval TTL
        $interval = new DateInterval('PT1H');
        $this->assertTrue($this->cache->set('key2', 'value2', $interval));
        $this->assertTrue($this->cache->has('key2'));
    }
    
    public function testDelete(): void
    {
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->has('key1'));
        
        $this->assertTrue($this->cache->delete('key1'));
        $this->assertFalse($this->cache->has('key1'));
    }
    
    public function testClear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $this->assertTrue($this->cache->clear());
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }
    
    public function testGetMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key3', 'value3');
        
        $result = $this->cache->getMultiple(['key1', 'key2', 'key3'], 'default');
        
        $expected = [
            'key1' => 'value1',
            'key2' => 'default',
            'key3' => 'value3'
        ];
        
        $this->assertEquals($expected, $result);
    }
    
    public function testSetMultiple(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3'
        ];
        
        $this->assertTrue($this->cache->setMultiple($values));
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->get('key2'));
        $this->assertEquals('value3', $this->cache->get('key3'));
    }
    
    public function testSetMultipleWithTtl(): void
    {
        $values = ['key1' => 'value1', 'key2' => 'value2'];
        
        $this->assertTrue($this->cache->setMultiple($values, 3600));
        $this->assertTrue($this->cache->has('key1'));
        $this->assertTrue($this->cache->has('key2'));
    }
    
    public function testDeleteMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');
        
        $this->assertTrue($this->cache->deleteMultiple(['key1', 'key3']));
        
        $this->assertFalse($this->cache->has('key1'));
        $this->assertTrue($this->cache->has('key2'));
        $this->assertFalse($this->cache->has('key3'));
    }
    
    public function testHas(): void
    {
        $this->assertFalse($this->cache->has('key1'));
        
        $this->cache->set('key1', 'value1');
        $this->assertTrue($this->cache->has('key1'));
        
        $this->cache->delete('key1');
        $this->assertFalse($this->cache->has('key1'));
    }
    
    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get('');
    }
    
    public function testKeyTooLongThrowsException(): void
    {
        $longKey = str_repeat('a', 65); // Max is 64
        
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get($longKey);
    }
    
    public function testKeyWithReservedCharactersThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get('key{with}brackets');
    }
    
    public function testNonStringKeyInMultipleOperationThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->getMultiple([123, 'valid_key']);
    }
    
    public function testNonStringKeyInSetMultipleThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->setMultiple([123 => 'value']);
    }
    
    public function testGetPool(): void
    {
        $pool = $this->cache->getPool();
        $this->assertInstanceOf(ArrayAdapter::class, $pool);
    }
    
    public function testVariousDataTypes(): void
    {
        $testData = [
            'string' => 'test string',
            'integer' => 42,
            'float' => 3.14,
            'boolean' => true,
            'array' => ['a', 'b', 'c'],
            'object' => (object) ['prop' => 'value'],
            'null' => null
        ];
        
        foreach ($testData as $key => $value) {
            $this->assertTrue($this->cache->set($key, $value));
            $this->assertEquals($value, $this->cache->get($key));
        }
    }
    
    public function testEmptyKeysArray(): void
    {
        $result = $this->cache->getMultiple([]);
        $this->assertEquals([], $result);
        
        $this->assertTrue($this->cache->setMultiple([]));
        $this->assertTrue($this->cache->deleteMultiple([]));
    }
}